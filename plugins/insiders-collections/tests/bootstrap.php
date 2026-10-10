<?php
/**
 * Acceptance-test harness. Run inside a disposable WordPress:
 *   wp eval-file wp-content/plugins/insiders-collections/tests/run.php
 * It TRUNCATES every icol_* table. Never run it against production.
 */
use Insiders\Collections\Domain\Policy;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Settings;
use Insiders\Collections\Schema;

if ( ! defined( 'ICOL_TESTING' ) ) {
	define( 'ICOL_TESTING', true );
}
if ( false === strpos( (string) DB_NAME, 'wp' ) || ( defined( 'WP_ENVIRONMENT_TYPE' ) && 'production' === WP_ENVIRONMENT_TYPE ) ) {
	fwrite( STDERR, "refusing to run outside a test database\n" );
	exit( 1 );
}

final class T {
	public static array $results = array();
	public static string $current = '';
	public static array $http_log = array();
	public static array $tranzila_tx = array();  // index => report row
	public static array $tranzila_stos = array(); // sto_id => sto
	public static array $fail_next = array();    // url-substring => 'timeout'|'500'|'401'
	public static int $wati_seq = 0;
	public static ?string $ai_reply = null;
	public static array $pd_persons = array(); // person_id => v2 person
	public static array $pd_deals = array();   // deal_id => v2 deal
	public static array $pd_labels = array( array( 'id' => 55, 'label' => 'ללא דמי רישום' ), array( 'id' => 56, 'label' => 'VIP' ) );

	public static function reset(): void {
		global $wpdb;
		foreach ( array_keys( Schema::tables() ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Db::t( $t ) );
		}
		foreach ( array( 'icol_heartbeats', 'icol_suspended', 'icol_runner_lock', 'icol_templates', 'icol_gate_owner', 'icol_pd_label' ) as $o ) {
			delete_option( $o );
		}
		delete_option( 'icol_settings' );
		Settings::flush();
		self::$http_log = array();
		self::$tranzila_tx = array();
		self::$tranzila_stos = array();
		self::$fail_next = array();
		self::$pd_persons = array();
		self::$pd_deals   = array();
		\Insiders\Collections\Integrations\RevenueDashboard\FinanceDashboard::flush();
		Clock::freeze( Clock::local_to_ts( '2026-10-11', '10:00' ) ); // Sunday, business day
		Settings::set(
			array(
				'kill_switch'        => 0,
				'display_only'       => 0,
				'cap_customer_sends' => 1,
				'cap_payment_links'  => 1,
				'wati_api_base'      => 'https://wati.test/123',
				'tranzila_cycle_strategy' => 'schedule',
			)
		);
		Settings::set_secret( 'wati_token', 'wati-test-token' );
		Settings::set_secret( 'tranzila_app_key', 'app' );
		Settings::set_secret( 'tranzila_secret', 'sec' );
		Settings::set_secret( 'tranzila_webhook_secret', 'tz_secret_aaaaaaaaaaaaaaaaaaaa' );
		Settings::set_secret( 'wati_webhook_secret', 'wati_secret_aaaaaaaaaaaaaaaaaa' );
		Settings::set_secret( 'cron_key', 'cron_key_aaaaaaaaaaaaaaaaaaaa' );
		Policy::seed();
		$cfg = Policy::defaults();
		$cfg['first_contact_jitter_minutes'] = 0;
		$v   = Policy::save_draft( $cfg, 'test' );
		self::as_admin();
		Policy::approve( $v, Clock::utc( Clock::now() - 60 ) );
		// Tranzila reconcile counts as fresh unless a test says otherwise.
		\Insiders\Collections\Engine\Runner::beat( 'reconcile', 'success' );
		foreach ( \Insiders\Collections\Domain\Templates::defaults() as $k => $tpl ) {
			\Insiders\Collections\Domain\Templates::save( $k, array( 'provider_name' => 'tpl_' . $k, 'approval_state' => 'approved' ) );
			if ( 'internal' !== $tpl['channel'] ) {
				\Insiders\Collections\Domain\Templates::save( $k, array( 'approval_state' => 'approved' ) );
			}
		}
	}

	public static function user( string $role ): int {
		$login = 'icol_' . $role;
		$u     = get_user_by( 'login', $login );
		$id    = $u ? $u->ID : wp_insert_user( array( 'user_login' => $login, 'user_pass' => 'pass-' . $role, 'user_email' => $login . '@example.test', 'display_name' => 'בדיקה ' . $role, 'role' => 'subscriber' ) );
		update_user_meta( $id, 'icol_role', $role );
		return (int) $id;
	}

	public static function as_admin(): void {
		$admin = get_user_by( 'login', 'admin' );
		wp_set_current_user( $admin->ID );
	}

	public static function as( string $role ): int {
		$id = self::user( $role );
		wp_set_current_user( $id );
		return $id;
	}

	public static function at( string $date, string $time = '10:00' ): void {
		Clock::freeze( Clock::local_to_ts( $date, $time ) );
		\Insiders\Collections\Engine\Runner::beat( 'reconcile', 'success' );
	}

	public static function check( bool $cond, string $what ): void {
		self::$results[ self::$current ][] = array( $cond, $what );
		if ( ! $cond ) {
			echo "    ✗ {$what}\n";
		}
	}

	public static function eq( $expected, $actual, string $what ): void {
		self::check( $expected == $actual, $what . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' );
	}

	public static function test( string $id, string $title, callable $fn ): void {
		self::$current = $id . ' ' . $title;
		echo "• {$id} {$title}\n";
		self::reset();
		try {
			$fn();
		} catch ( \Throwable $e ) {
			self::check( false, 'exception: ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
		}
	}

	public static function count( string $table, string $where = '1=1', ...$args ): int {
		return (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( $table ) . ' WHERE ' . $where, ...$args );
	}

	public static function customer( array $o = array() ): int {
		self::as_admin();
		$id = \Insiders\Collections\Domain\Customers::create(
			array_merge(
				array(
					'full_name'              => 'דנה כהן',
					'first_name'             => 'דנה',
					'first_name_reliable'    => true,
					'phone'                  => '050-1234567',
					'email'                  => 'dana@example.test',
					'phone_verified'         => true,
					'contact_permission_ref' => 'הסכם הצטרפות סעיף 9',
				),
				$o
			)
		);
		return $id;
	}

	public static function order( int $customer_id, array $o = array() ): int {
		return \Insiders\Collections\Domain\Matching::map_sto(
			array_merge(
				array(
					'customer_id'         => $customer_id,
					'terminal'            => 'insiderstok',
					'sto_id'              => '5001',
					'charge_dom'          => 10,
					'future_installments' => 6,
					'next_due_at'         => '2026-11-10',
					'charge_amount'       => '490',
				),
				$o
			)
		);
	}

	/** Deliver a Tranzila notification through the real REST route. */
	public static function tranzila( array $payload, string $kind = 'sto', bool $register_in_report = true ): \WP_REST_Response {
		$payload = array_merge( array( 'supplier' => 'insiderstok', 'currency' => 'ILS', 'tranmode' => 'A1705' ), $payload );
		if ( $register_in_report ) {
			self::$tranzila_tx[ (string) $payload['index'] ] = array(
				'terminal'                => $payload['supplier'],
				'index'                   => $payload['index'],
				'amount'                  => $payload['sum'],
				'currency'                => '1',
				'processor_response_code' => $payload['Response'],
				'tranmode'                => $payload['tranmode'],
				'transaction_date'        => Clock::local()->format( 'Y-m-d' ),
				'transaction_time'        => Clock::local()->format( 'H:i:s' ),
			);
		}
		$req = new \WP_REST_Request( 'POST', '/icol/v1/webhooks/tranzila/' . $kind . '/tz_secret_aaaaaaaaaaaaaaaaaaaa' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( $payload ) );
		return rest_do_request( $req );
	}

	public static function wati( array $payload ): \WP_REST_Response {
		$req = new \WP_REST_Request( 'POST', '/icol/v1/webhooks/wati/wati_secret_aaaaaaaaaaaaaaaaaa' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( $payload ) );
		return rest_do_request( $req );
	}

	public static function inbound( string $text, string $wa = '972501234567', array $extra = array() ): \WP_REST_Response {
		static $n = 0;
		++$n;
		return self::wati( array_merge( array( 'eventType' => 'message', 'id' => 'in-' . $n . '-' . md5( $text . microtime() ), 'whatsappMessageId' => 'wamid.' . $n . md5( $text . microtime() ), 'waId' => $wa, 'text' => $text, 'type' => 'text', 'owner' => false, 'timestamp' => (string) Clock::now() ), $extra ) );
	}

	public static function tick(): array {
		self::as_admin();
		delete_option( 'icol_runner_lock' );
		$r = \Insiders\Collections\Engine\Runner::tick( 'test' );
		return $r;
	}

	/** Run ticks until no due work remains or a limit. */
	public static function settle( int $max = 6 ): void {
		for ( $i = 0; $i < $max; $i++ ) {
			self::tick();
		}
	}

	public static function api( string $method, string $route, array $body = array(), ?string $idem = null ): \WP_REST_Response {
		$req = new \WP_REST_Request( $method, '/icol/v1' . $route );
		if ( 'GET' === $method ) {
			$req->set_query_params( $body );
		} else {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_header( 'Idempotency-Key', $idem ?? wp_generate_uuid4() );
			$req->set_body( wp_json_encode( $body ) );
		}
		return rest_do_request( $req );
	}

	public static function messages_out( int $customer_id, ?string $state = null ): array {
		return Db::rows( 'SELECT * FROM ' . Db::t( 'messages' ) . " WHERE customer_id = %d AND direction = 'out' AND channel = 'whatsapp'" . ( $state ? ' AND delivery_state = %s' : '' ) . ' ORDER BY id', ...array_filter( array( $customer_id, $state ) ) );
	}

	public static function summary(): int {
		$total = 0;
		$fail  = 0;
		echo "\n================ RESULTS ================\n";
		foreach ( self::$results as $test => $checks ) {
			$bad = array_filter( $checks, static fn( $c ) => ! $c[0] );
			$total++;
			if ( $bad ) {
				$fail++;
			}
			printf( "%s %s (%d checks)\n", $bad ? 'FAIL' : 'PASS', $test, count( $checks ) );
		}
		printf( "\n%d tests, %d passed, %d failed\n", $total, $total - $fail, $fail );
		return $fail;
	}
}

/** HTTP mock: Tranzila reports/stos/pr, WATI sends. Every call is logged. */
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		T::$http_log[] = array( 'url' => $url, 'method' => $args['method'] ?? 'GET', 'body' => $args['body'] ?? '' );
		foreach ( T::$fail_next as $needle => $mode ) {
			if ( false !== strpos( $url, $needle ) ) {
				unset( T::$fail_next[ $needle ] );
				if ( 'timeout' === $mode ) {
					return new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
				}
				return array( 'response' => array( 'code' => (int) $mode, 'message' => 'x' ), 'body' => '{"error":"mock"}', 'headers' => array() );
			}
		}
		$json = static fn( $data, int $code = 200 ) => array( 'response' => array( 'code' => $code, 'message' => 'OK' ), 'body' => wp_json_encode( $data ), 'headers' => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array( 'content-type' => 'application/json' ) ) );
		$body = json_decode( (string) ( $args['body'] ?? '' ), true ) ?: array();
		if ( false !== strpos( $url, 'report.tranzila.com/v1/transaction' ) ) {
			if ( isset( $body['transaction_index'] ) ) {
				$row = T::$tranzila_tx[ (string) $body['transaction_index'] ] ?? null;
				if ( $row && ( $row['terminal'] ?? 'insiderstok' ) !== ( $body['terminal_name'] ?? '' ) ) {
					$row = null;
				}
				return $json( array( 'transactions' => $row ? array( $row ) : array(), 'total' => $row ? 1 : 0 ) );
			}
			$rows = array_values( array_filter( T::$tranzila_tx, static fn( $r ) => ( $r['terminal'] ?? 'insiderstok' ) === ( $body['terminal_name'] ?? '' ) ) );
			$size = (int) ( $body['page_results'] ?? 500 );
			$page = (int) ( $body['page'] ?? 1 );
			return $json( array( 'transactions' => array_slice( $rows, ( $page - 1 ) * $size, $size ), 'total' => count( $rows ) ) );
		}
		if ( false !== strpos( $url, '/v1/stos/get' ) ) {
			$sto = T::$tranzila_stos[ (string) ( $body['sto_id'] ?? '' ) ] ?? null;
			return $json( array( 'stos' => $sto ? array( $sto ) : array() ) );
		}
		if ( false !== strpos( $url, '/v1/pr/create' ) ) {
			return $json( array( 'error_code' => 0, 'message' => 'ok', 'pr_id' => 'PR' . wp_rand( 1000, 9999 ), 'pr_link' => 'https://direct.tranzila.com/pr/' . wp_generate_password( 12, false ) ) );
		}
		if ( false !== strpos( $url, 'wati.test' ) ) {
			++T::$wati_seq;
			return $json( array( 'result' => true, 'receivers' => array( array( 'localMessageId' => 'wati-' . T::$wati_seq, 'waId' => '972501234567', 'isValidWhatsAppNumber' => true ) ) ) );
		}
		if ( false !== strpos( $url, 'pipedrive' ) && preg_match( '#/api/v2/persons/(\d+)#', $url, $pm ) ) {
			$person = T::$pd_persons[ (int) $pm[1] ] ?? null;
			return $person ? $json( array( 'success' => true, 'data' => $person ) ) : $json( array( 'success' => false, 'error' => 'not found' ), 404 );
		}
		if ( false !== strpos( $url, 'pipedrive' ) && preg_match( '#/api/v2/deals\?#', $url ) ) {
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $qq );
			$ids = array_map( 'intval', explode( ',', (string) ( $qq['ids'] ?? '' ) ) );
			return $json( array( 'success' => true, 'data' => array_values( array_filter( array_map( static fn( $i ) => T::$pd_deals[ $i ] ?? null, $ids ) ) ) ) );
		}
		if ( false !== strpos( $url, 'pipedrive' ) && false !== strpos( $url, '/api/v2/dealFields/label' ) ) {
			return $json( array( 'success' => true, 'data' => array( 'field_code' => 'label', 'options' => T::$pd_labels ) ) );
		}
		if ( false !== strpos( $url, 'pipedrive' ) ) {
			return $json( array( 'success' => true, 'data' => array( 'id' => 777 ) ) );
		}
		if ( false !== strpos( $url, 'api.anthropic.com' ) ) {
			return $json( array( 'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'text', 'text' => T::$ai_reply ?? '{}' ) ), 'usage' => array( 'input_tokens' => 10, 'output_tokens' => 10 ) ) );
		}
		return new \WP_Error( 'blocked', 'unexpected outbound call in tests: ' . $url );
	},
	10,
	3
);

/**
 * Software passkey for the gate tests: a P-256 key, CBOR by hand, the same bytes a
 * phone sends. Lets the full ceremony (create + get) run without a browser.
 */
final class SoftKey {
	public $priv;
	public string $cred_id;
	public int $counter = 0;
	public string $origin;

	public function __construct( ?string $origin = null ) {
		$this->priv    = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ) );
		$this->cred_id = random_bytes( 32 );
		$p             = wp_parse_url( home_url() );
		$this->origin  = $origin ?? ( $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) );
	}

	public static function cbor( $v ): string {
		if ( $v instanceof CborBytes ) {
			return self::head( 2, strlen( $v->b ) ) . $v->b;
		}
		if ( is_int( $v ) ) {
			return $v >= 0 ? self::head( 0, $v ) : self::head( 1, -1 - $v );
		}
		if ( is_string( $v ) ) {
			return self::head( 3, strlen( $v ) ) . $v;
		}
		if ( is_array( $v ) ) {
			$out = self::head( 5, count( $v ) );
			foreach ( $v as $k => $x ) {
				$out .= self::cbor( $k ) . self::cbor( $x );
			}
			return $out;
		}
		throw new \RuntimeException( 'cbor test encoder' );
	}

	private static function head( int $major, int $n ): string {
		if ( $n < 24 ) {
			return chr( ( $major << 5 ) | $n );
		}
		if ( $n < 256 ) {
			return chr( ( $major << 5 ) | 24 ) . chr( $n );
		}
		return chr( ( $major << 5 ) | 25 ) . pack( 'n', $n );
	}

	private function rp_hash(): string {
		return hash( 'sha256', \Insiders\Collections\Security\WebAuthn::rp_id(), true );
	}

	private static function b64u( string $b ): string {
		return \Insiders\Collections\Security\WebAuthn::b64u_encode( $b );
	}

	public function create( array $options, int $flags = 0x45 ): array {
		$d    = openssl_pkey_get_details( $this->priv )['ec'];
		$cose = self::cbor( array( 1 => 2, 3 => -7, -1 => 1, -2 => new CborBytes( str_pad( $d['x'], 32, "\0", STR_PAD_LEFT ) ), -3 => new CborBytes( str_pad( $d['y'], 32, "\0", STR_PAD_LEFT ) ) ) );
		$auth = $this->rp_hash() . chr( $flags ) . pack( 'N', 0 ) . str_repeat( "\0", 16 ) . pack( 'n', strlen( $this->cred_id ) ) . $this->cred_id . $cose;
		$cd   = wp_json_encode( array( 'type' => 'webauthn.create', 'challenge' => $options['publicKey']['challenge'], 'origin' => $this->origin, 'crossOrigin' => false ) );
		$att  = self::cbor( array( 'fmt' => 'none', 'attStmt' => array(), 'authData' => new CborBytes( $auth ) ) );
		return array( 'id' => self::b64u( $this->cred_id ), 'rawId' => self::b64u( $this->cred_id ), 'type' => 'public-key', 'response' => array( 'clientDataJSON' => self::b64u( $cd ), 'attestationObject' => self::b64u( $att ) ) );
	}

	public function get( array $options, int $flags = 0x05, ?int $counter = null, bool $tamper = false ): array {
		$this->counter = $counter ?? $this->counter;
		$auth = $this->rp_hash() . chr( $flags ) . pack( 'N', $this->counter );
		$cd   = wp_json_encode( array( 'type' => 'webauthn.get', 'challenge' => $options['publicKey']['challenge'], 'origin' => $this->origin ) );
		openssl_sign( $auth . hash( 'sha256', $cd, true ), $sig, $this->priv, OPENSSL_ALGO_SHA256 );
		if ( $tamper ) {
			$cd = wp_json_encode( array( 'type' => 'webauthn.get', 'challenge' => $options['publicKey']['challenge'], 'origin' => $this->origin, 'x' => 1 ) );
		}
		return array( 'id' => self::b64u( $this->cred_id ), 'rawId' => self::b64u( $this->cred_id ), 'type' => 'public-key', 'response' => array( 'clientDataJSON' => self::b64u( $cd ), 'authenticatorData' => self::b64u( $auth ), 'signature' => self::b64u( $sig ) ) );
	}
}

final class CborBytes {
	public function __construct( public string $b ) {}
}

/** Gives the current CLI user a real WordPress session (auth cookie + token), as a browser has. */
function gate_login( int $user_id ): void {
	wp_set_current_user( $user_id );
	$exp   = time() + DAY_IN_SECONDS;
	$token = WP_Session_Tokens::get_instance( $user_id )->create( $exp );
	$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, $exp, 'logged_in', $token );
	unset( $_COOKIE[ \Insiders\Collections\Security\Gate::cookie_name() ] );
	\Insiders\Collections\Security\Gate::flush();
}

<?php
namespace Insiders\Collections\Security;

use Insiders\Collections\Domain\DomainError;
use Insiders\Collections\Support\Audit;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The scan gate. A WordPress login alone shows nothing: every screen, API call and
 * data tool of this plugin needs a gate session, and a gate session exists only after
 * a paired phone scanned the QR on that screen and the person confirmed with Face ID,
 * fingerprint or the phone's PIN (a WebAuthn passkey with user verification).
 *
 * Enforcement is central, not per screen: while locked, Capabilities strips every
 * icol_* capability except icol_enter (which only opens the lock screen). Any code
 * path that checks a capability is therefore covered, including ones added later.
 *
 * Root of trust: the owner. Set once, either by ICOL_GATE_OWNER in wp-config.php or
 * by a WordPress administrator claiming it on the lock screen with their password while
 * no owner exists (add_option is the one atomic "first one wins" WordPress offers).
 * The wp-config route is not stronger against administrators: an administrator can
 * install code and read the database anyway. What the gate stops is a stolen password
 * of anyone, and every staff member who is not an administrator.
 * The owner's first phone is paired with the WordPress password + a code typed on the
 * computer; every other phone needs the owner's approval from an unlocked session.
 * Lost phone without a spare: ICOL_GATE_OFF in wp-config.php (or the host's support),
 * revoke, re-pair, remove the line.
 *
 * Not covered, by design: webhooks, the cron endpoint, the customer pay page (machines
 * and customers), WP-CLI (server access), and icol_get_collection_summary() (totals only,
 * read by the finance dashboard inside PHP).
 */
final class Gate {

	public const UNLOCK_TTL = 120;
	public const PAIR_TTL   = 300;

	/** Tests only: null = normal behaviour. */
	public static ?bool $force = null;
	public static bool $enforce_in_cli = false;
	public static ?int $owner_override = null;

	private static array $cache = array();

	public static function cookie_name(): string {
		return 'icol_gate_' . ( defined( 'COOKIEHASH' ) ? COOKIEHASH : md5( site_url() ) );
	}

	public static function off(): bool {
		return defined( 'ICOL_GATE_OFF' ) && ICOL_GATE_OFF;
	}

	public static function enforced(): bool {
		if ( self::off() ) {
			return false;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI && ! self::$enforce_in_cli ) {
			return false; // server access already sees the database
		}
		return true;
	}

	public static function owner_id(): int {
		if ( null !== self::$owner_override ) {
			return self::$owner_override;
		}
		if ( defined( 'ICOL_GATE_OWNER' ) && '' !== (string) ICOL_GATE_OWNER ) {
			$v = (string) ICOL_GATE_OWNER;
			$u = ctype_digit( $v ) ? get_userdata( (int) $v ) : ( is_email( $v ) ? get_user_by( 'email', $v ) : get_user_by( 'login', $v ) );
			return $u ? (int) $u->ID : 0;
		}
		$id = (int) get_option( 'icol_gate_owner', 0 );
		return $id && get_userdata( $id ) ? $id : 0;
	}

	/**
	 * First-time setup from the lock screen: an administrator becomes the owner after
	 * typing their WordPress password. Only while nobody owns the system; never changes
	 * an existing owner. The site's admin email hears about it.
	 */
	public static function claim_owner( string $password ): array {
		$user = wp_get_current_user();
		if ( self::owner_id() ) {
			throw new DomainError( 'gate_owner_exists', 'כבר נקבע בעל מערכת', 409 );
		}
		if ( defined( 'ICOL_GATE_OWNER' ) && '' !== (string) ICOL_GATE_OWNER ) {
			// The file decides when it says something; a name there that matches no user is fixed there.
			throw new DomainError( 'gate_owner_config', 'ב-wp-config.php מוגדר בעל מערכת שלא קיים באתר (ICOL_GATE_OWNER). יש לתקן או למחוק את השורה.', 409 );
		}
		if ( ! user_can( $user, 'manage_options' ) ) {
			throw new DomainError( 'forbidden', 'רק מנהל של האתר יכול לקבוע את בעל המערכת', 403 );
		}
		if ( ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			Audit::log( 'gate.claim_password_failed', 'user', $user->ID, null, null, '' );
			throw new DomainError( 'reauth_failed', 'הסיסמה שגויה', 401, array( 'password' => 'שגויה' ) );
		}
		if ( ! add_option( 'icol_gate_owner', (int) $user->ID, '', false ) ) {
			throw new DomainError( 'gate_owner_exists', 'כבר נקבע בעל מערכת', 409 );
		}
		self::flush();
		Audit::log( 'gate.owner_claimed', 'user', $user->ID, null, array( 'login' => $user->user_login ), '' );
		$body = $user->display_name . ' (' . $user->user_login . ") נקבע כבעל מערכת התשלומים.\nרק הטלפון של בעל המערכת מאשר טלפונים של אחרים.\nאם זה לא אמור היה לקרות, יש לפנות למפתח מיד.";
		foreach ( array_unique( array_filter( array( (string) get_option( 'admin_email' ), $user->user_email ) ) ) as $to ) {
			wp_mail( $to, '[INSIDERS תשלומים] נקבע בעל מערכת', $body );
		}
		return self::state();
	}

	public static function is_owner( ?int $user_id = null ): bool {
		$owner = self::owner_id();
		return $owner > 0 && $owner === ( $user_id ?? get_current_user_id() );
	}

	public static function flush(): void {
		self::$cache = array();
	}

	/** True when the current request may see data. Cached per request and user. */
	public static function unlocked(): bool {
		if ( null !== self::$force ) {
			return self::$force;
		}
		if ( ! self::enforced() ) {
			return true;
		}
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return false;
		}
		if ( ! array_key_exists( $uid, self::$cache ) ) {
			self::$cache[ $uid ] = null !== self::current_session();
		}
		return self::$cache[ $uid ];
	}

	/** WordPress session this request belongs to; a gate session never outlives it. */
	public static function wp_session_hash(): string {
		$t = function_exists( 'wp_get_session_token' ) ? (string) wp_get_session_token() : '';
		return '' === $t ? '' : hash( 'sha256', 'icol|' . $t );
	}

	public static function current_session(): ?array {
		$token = (string) ( $_COOKIE[ self::cookie_name() ] ?? '' );
		$wps   = self::wp_session_hash();
		if ( '' === $token || '' === $wps ) {
			return null;
		}
		$s = Db::row( 'SELECT * FROM ' . Db::t( 'gate_sessions' ) . ' WHERE token_hash = %s AND revoked_at IS NULL', hash( 'sha256', $token ) );
		if ( ! $s || (int) $s['user_id'] !== get_current_user_id() || ! hash_equals( (string) $s['wp_session_hash'], $wps ) ) {
			return null;
		}
		$now  = Clock::now();
		$idle = min( 240, max( 5, (int) Settings::get( 'gate_idle_minutes' ) ) ) * MINUTE_IN_SECONDS;
		if ( $now >= (int) Clock::ts( $s['expires_at'] ) ) {
			self::end_session( (int) $s['id'], 'expired' );
			return null;
		}
		if ( $now - (int) Clock::ts( $s['last_seen_at'] ) > $idle ) {
			self::end_session( (int) $s['id'], 'idle' );
			return null;
		}
		if ( $now - (int) Clock::ts( $s['last_seen_at'] ) > 60 ) {
			Db::update( 'gate_sessions', array( 'last_seen_at' => Clock::utc() ), array( 'id' => (int) $s['id'] ) );
		}
		if ( $s['device_id'] && 'active' !== Db::value( 'SELECT status FROM ' . Db::t( 'gate_devices' ) . ' WHERE id = %d', (int) $s['device_id'] ) ) {
			self::end_session( (int) $s['id'], 'device_revoked' );
			return null;
		}
		return $s;
	}

	public static function end_session( int $id, string $reason ): void {
		Db::exec( 'UPDATE ' . Db::t( 'gate_sessions' ) . ' SET revoked_at = %s, revoke_reason = %s WHERE id = %d AND revoked_at IS NULL', Clock::utc(), $reason, $id );
		self::flush();
	}

	/* ------------------------------------------------------------------ state */

	public static function devices( int $user_id, array $statuses = array( 'active' ) ): array {
		return Db::rows( 'SELECT * FROM ' . Db::t( 'gate_devices' ) . ' WHERE user_id = %d AND status IN (' . Db::in( $statuses, '%s' ) . ') ORDER BY id', $user_id, ...$statuses );
	}

	/** What the lock screen needs to know. No customer data. */
	public static function state(): array {
		$uid     = get_current_user_id();
		$active  = count( self::devices( $uid ) );
		$pending = count( self::devices( $uid, array( 'pending' ) ) );
		$owner   = self::owner_id();
		$is_own  = self::is_owner( $uid );
		return array(
			'enforced'         => self::enforced(),
			'off'              => self::off(),
			'unlocked'         => self::unlocked(),
			'secure'           => WebAuthn::secure_context(),
			'owner_configured' => $owner > 0,
			'can_claim'        => 0 === $owner && current_user_can( 'manage_options' ),
			'is_owner'         => $is_own,
			'owner_name'       => $owner ? (string) get_userdata( $owner )->display_name : '',
			'active_devices'   => $active,
			'pending_devices'  => $pending,
			// Pairing from the lock screen only with no phone at all; otherwise from an unlocked session.
			'can_pair'         => $owner > 0 && ( self::unlocked() || ( 0 === $active && 0 === $pending ) ),
			'needs_password'   => true,
			'needs_approval'   => ! $is_own,
			'can_admin_wp'     => current_user_can( 'manage_options' ),
			'login'            => (string) wp_get_current_user()->user_login,
			'idle_minutes'     => (int) Settings::get( 'gate_idle_minutes' ),
			'session_hours'    => (int) Settings::get( 'gate_session_hours' ),
		);
	}

	/* ---------------------------------------------------------------- desktop */

	private static function client(): array {
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		// Behind Cloudways' proxy REMOTE_ADDR can be private; then the first forwarded hop is the client.
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$first = trim( explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] );
			if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
				$ip = $first;
			}
		}
		return array( 'ip' => substr( $ip, 0, 45 ), 'ua' => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 255 ) );
	}

	private static function require_wp_session(): string {
		$wps = self::wp_session_hash();
		if ( '' === $wps ) {
			throw new DomainError( 'gate_no_session', 'יש להתחבר לוורדפרס מחדש', 401 );
		}
		return $wps;
	}

	private static function phone_url( string $id ): string {
		return add_query_arg( 'icol_gate', $id, home_url( '/' ) );
	}

	private static function new_challenge( string $kind, int $ttl, array $extra = array() ): array {
		$wps    = self::require_wp_session();
		$client = self::client();
		$id     = bin2hex( random_bytes( 16 ) );
		$row    = array_merge(
			array(
				'id'              => $id,
				'kind'            => $kind,
				'user_id'         => get_current_user_id(),
				'wp_session_hash' => $wps,
				'challenge'       => WebAuthn::b64u_encode( random_bytes( 32 ) ),
				'status'          => 'pending',
				'ip'              => $client['ip'],
				'user_agent'      => $client['ua'],
				'created_at'      => Clock::utc(),
				'expires_at'      => Clock::utc( Clock::now() + $ttl ),
			),
			$extra
		);
		// One live challenge of a kind per browser session: an old QR on another tab stops working.
		Db::exec( 'UPDATE ' . Db::t( 'gate_challenges' ) . " SET status = 'replaced' WHERE user_id = %d AND kind = %s AND wp_session_hash = %s AND status IN ('pending','registered')", get_current_user_id(), $kind, $wps );
		Db::insert( 'gate_challenges', $row );
		return array(
			'id'         => $id,
			'url'        => self::phone_url( $id ),
			'qr'         => Qr::svg( self::phone_url( $id ) ),
			'expires_in' => $ttl,
		);
	}

	public static function start_unlock(): array {
		if ( ! self::devices( get_current_user_id() ) ) {
			throw new DomainError( 'gate_no_device', 'עוד לא חובר טלפון למשתמש הזה', 409 );
		}
		$match = random_int( 10, 99 );
		$c     = self::new_challenge( 'unlock', self::UNLOCK_TTL, array( 'match_code' => $match ) );
		return $c + array( 'match' => $match );
	}

	/** Desktop polls its own challenge. Approval turns into a session cookie on this browser only. */
	public static function poll( string $id ): array {
		$c = self::own_challenge( $id );
		if ( 'approved' === $c['status'] ) {
			$n = Db::exec( 'UPDATE ' . Db::t( 'gate_challenges' ) . " SET status = 'consumed' WHERE id = %s AND status = 'approved'", $id );
			if ( 1 !== $n ) {
				return array( 'status' => 'consumed' );
			}
			self::open_session( (int) $c['device_id'] );
			return array( 'status' => 'approved' );
		}
		// A registered pairing stays open for the code (confirm_pair adds two minutes of grace).
		if ( 'pending' === $c['status'] && Clock::now() >= (int) Clock::ts( $c['expires_at'] ) ) {
			Db::update( 'gate_challenges', array( 'status' => 'expired' ), array( 'id' => $id ) );
			return array( 'status' => 'expired' );
		}
		return array( 'status' => $c['status'] );
	}

	private static function own_challenge( string $id ): array {
		$c = Db::row( 'SELECT * FROM ' . Db::t( 'gate_challenges' ) . ' WHERE id = %s', $id );
		if ( ! $c || (int) $c['user_id'] !== get_current_user_id() || ! hash_equals( (string) $c['wp_session_hash'], self::require_wp_session() ) ) {
			throw new DomainError( 'not_found', 'הקוד לא נמצא', 404 );
		}
		return $c;
	}

	private static function open_session( int $device_id ): void {
		$token  = WebAuthn::b64u_encode( random_bytes( 32 ) );
		$hours  = min( 24, max( 1, (int) Settings::get( 'gate_session_hours' ) ) );
		$client = self::client();
		$id     = Db::insert(
			'gate_sessions',
			array(
				'token_hash'      => hash( 'sha256', $token ),
				'user_id'         => get_current_user_id(),
				'wp_session_hash' => self::require_wp_session(),
				'device_id'       => $device_id ?: null,
				'ip'              => $client['ip'],
				'user_agent'      => $client['ua'],
				'created_at'      => Clock::utc(),
				'last_seen_at'    => Clock::utc(),
				'expires_at'      => Clock::utc( Clock::now() + $hours * HOUR_IN_SECONDS ),
			)
		);
		if ( ! headers_sent() ) {
			setcookie(
				self::cookie_name(),
				$token,
				array(
					'expires'  => 0, // a browser session cookie; the server-side expiry is the real limit
					'path'     => '/',
					'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax', // Strict would hide the cookie on links from email / Pipedrive
				)
			);
		}
		$_COOKIE[ self::cookie_name() ] = $token;
		self::flush();
		Audit::log( 'gate.session_open', 'gate_session', $id, null, array( 'device' => $device_id, 'ip' => $client['ip'] ), '' );
	}

	public static function lock(): void {
		$s = self::current_session();
		if ( $s ) {
			self::end_session( (int) $s['id'], 'locked' );
		}
	}

	/** wp_logout: every gate session of this WordPress session ends with it. */
	public static function on_logout(): void {
		$wps = self::wp_session_hash();
		if ( '' !== $wps ) {
			Db::exec( 'UPDATE ' . Db::t( 'gate_sessions' ) . " SET revoked_at = %s, revoke_reason = 'logout' WHERE wp_session_hash = %s AND revoked_at IS NULL", Clock::utc(), $wps );
		}
	}

	public static function start_pair( string $password ): array {
		$state = self::state();
		if ( ! $state['owner_configured'] ) {
			throw new DomainError( 'gate_no_owner', 'עוד לא הוגדר בעל המערכת (ICOL_GATE_OWNER ב-wp-config.php)', 409 );
		}
		if ( ! $state['can_pair'] ) {
			throw new DomainError( 'gate_pair_locked', 'כבר מחובר או ממתין טלפון. טלפון נוסף מחברים מתוך המערכת, אחרי פתיחה בסריקה.', 403 );
		}
		// Always, even from an unlocked session: a new phone is lasting access, and a script running
		// inside an open session must not be able to add one without the person typing the password.
		$user = wp_get_current_user();
		if ( ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			Audit::log( 'gate.pair_password_failed', 'user', $user->ID, null, null, '' );
			throw new DomainError( 'reauth_failed', 'הסיסמה שגויה', 401, array( 'password' => 'שגויה' ) );
		}
		self::cleanup();
		return self::new_challenge( 'pair', self::PAIR_TTL );
	}

	/** The code the phone showed, typed on this computer: proves phone and screen are the same person. */
	public static function confirm_pair( string $id, string $code ): array {
		$c = self::own_challenge( $id );
		if ( 'pair' !== $c['kind'] || 'registered' !== $c['status'] || Clock::now() >= (int) Clock::ts( $c['expires_at'] ) + 120 ) {
			throw new DomainError( 'gate_pair_state', 'הקוד כבר לא בתוקף. יש להתחיל מחדש.', 409 );
		}
		if ( ! hash_equals( (string) $c['pair_code_hash'], hash( 'sha256', $id . '|' . preg_replace( '/\D/', '', $code ) ) ) ) {
			$attempts = (int) $c['attempts'] + 1;
			Db::update( 'gate_challenges', array( 'attempts' => $attempts, 'status' => $attempts >= 5 ? 'burned' : 'registered' ), array( 'id' => $id ) );
			if ( $attempts >= 5 ) {
				Db::exec( 'DELETE FROM ' . Db::t( 'gate_devices' ) . " WHERE id = %d AND status = 'unconfirmed'", (int) $c['device_id'] );
			}
			throw new DomainError( 'gate_wrong_code', $attempts >= 5 ? 'יותר מדי ניסיונות. יש להתחיל מחדש.' : 'הקוד לא תואם', 422, array( 'code' => 'לא תואם' ) );
		}
		$owner  = self::is_owner();
		$status = $owner ? 'active' : 'pending';
		if ( $owner ) {
			Db::exec( 'UPDATE ' . Db::t( 'gate_devices' ) . " SET status = 'active', confirmed_at = %s, approved_at = %s, approved_by = %d WHERE id = %d AND status = 'unconfirmed'", Clock::utc(), Clock::utc(), get_current_user_id(), (int) $c['device_id'] );
		} else {
			Db::exec( 'UPDATE ' . Db::t( 'gate_devices' ) . " SET status = 'pending', confirmed_at = %s WHERE id = %d AND status = 'unconfirmed'", Clock::utc(), (int) $c['device_id'] );
		}
		Db::update( 'gate_challenges', array( 'status' => 'consumed' ), array( 'id' => $id ) );
		$dev = Db::row( 'SELECT * FROM ' . Db::t( 'gate_devices' ) . ' WHERE id = %d', (int) $c['device_id'] );
		Audit::log( 'gate.device_paired', 'gate_device', (int) $c['device_id'], null, array( 'status' => $status, 'label' => $dev['label'] ?? '' ), '' );
		self::notify_owner(
			$owner ? 'טלפון חובר למערכת התשלומים' : 'בקשה לחיבור טלפון למערכת התשלומים',
			sprintf(
				$owner ? "הטלפון \"%s\" חובר לחשבון שלך (%s).\nאם החיבור לא נעשה על ידך, יש לנתק את הטלפון מיד במסך האבטחה ולהחליף את סיסמת וורדפרס." : "התקבלה בקשה מ%2\$s לחבר את הטלפון \"%1\$s\".\nהטלפון לא יוכל לפתוח את המערכת עד לאישור במסך האבטחה.",
				$dev['label'] ?? '',
				wp_get_current_user()->display_name
			)
		);
		return array( 'status' => $status, 'device_id' => (int) $c['device_id'] );
	}

	/* ------------------------------------------------------------------ phone */

	/** Public endpoint data: what the phone needs to show and the WebAuthn options. */
	public static function phone_options( string $id ): array {
		$c = self::live_challenge( $id );
		$u = get_userdata( (int) $c['user_id'] );
		$base = array(
			'kind'    => $c['kind'],
			'who'     => $u ? ( $u->first_name ?: $u->display_name ) : '',
			'desktop' => array( 'ip' => (string) $c['ip'], 'browser' => self::describe_ua( (string) $c['user_agent'] ), 'at' => Clock::display( $c['created_at'], 'H:i' ) ),
			'expires_in' => max( 0, (int) Clock::ts( $c['expires_at'] ) - Clock::now() ),
		);
		if ( 'unlock' === $c['kind'] ) {
			$creds = array_map( static fn( $d ) => array( 'type' => 'public-key', 'id' => $d['credential_id'] ), self::devices( (int) $c['user_id'] ) );
			$nums  = array( (int) $c['match_code'] );
			while ( count( $nums ) < 3 ) {
				$n = random_int( 10, 99 );
				if ( ! in_array( $n, $nums, true ) ) {
					$nums[] = $n;
				}
			}
			shuffle( $nums );
			return $base + array(
				'numbers'   => $nums,
				'publicKey' => array(
					'challenge'        => $c['challenge'],
					'rpId'             => WebAuthn::rp_id(),
					'allowCredentials' => $creds,
					'userVerification' => 'required',
					'timeout'          => 60000,
				),
			);
		}
		$exclude = array_map( static fn( $d ) => array( 'type' => 'public-key', 'id' => $d['credential_id'] ), self::devices( (int) $c['user_id'], array( 'active', 'pending' ) ) );
		return $base + array(
			'publicKey' => array(
				'challenge'              => $c['challenge'],
				'rp'                     => array( 'id' => WebAuthn::rp_id(), 'name' => 'INSIDERS תשלומים' ),
				'user'                   => array( 'id' => self::user_handle( (int) $c['user_id'] ), 'name' => $u ? $u->user_login : 'user', 'displayName' => $u ? $u->display_name : 'user' ),
				'pubKeyCredParams'       => array( array( 'type' => 'public-key', 'alg' => WebAuthn::ALG_ES256 ), array( 'type' => 'public-key', 'alg' => WebAuthn::ALG_EDDSA ), array( 'type' => 'public-key', 'alg' => WebAuthn::ALG_RS256 ) ),
				'authenticatorSelection' => array( 'authenticatorAttachment' => 'platform', 'residentKey' => 'preferred', 'userVerification' => 'required' ),
				'attestation'            => 'none',
				'excludeCredentials'     => $exclude,
				'timeout'                => 120000,
			),
		);
	}

	public static function phone_approve( string $id, int $number, array $cred ): array {
		$c = self::live_challenge( $id, 'unlock' );
		if ( $number !== (int) $c['match_code'] ) {
			$attempts = (int) $c['attempts'] + 1;
			Db::update( 'gate_challenges', array( 'attempts' => $attempts, 'status' => $attempts >= 3 ? 'burned' : 'pending' ), array( 'id' => $id ) );
			Audit::log( 'gate.wrong_number', 'user', (int) $c['user_id'], null, null, (string) $c['ip'] );
			throw new DomainError( 'gate_wrong_number', $attempts >= 3 ? 'יותר מדי ניסיונות. יש לרענן את המסך במחשב.' : 'המספר לא תואם את המספר שבמחשב', 422 );
		}
		$raw_id = (string) ( $cred['rawId'] ?? $cred['id'] ?? '' );
		$dev    = Db::row( 'SELECT * FROM ' . Db::t( 'gate_devices' ) . " WHERE credential_id = %s AND user_id = %d AND status = 'active'", $raw_id, (int) $c['user_id'] );
		if ( ! $dev ) {
			throw new DomainError( 'gate_unknown_device', 'הטלפון הזה לא מחובר למשתמש שמנסה להיכנס', 403 );
		}
		try {
			$count = WebAuthn::verify_assertion( $cred, WebAuthn::b64u_decode( (string) $c['challenge'] ), $dev );
		} catch ( \Throwable $e ) {
			Audit::log( 'gate.assertion_failed', 'gate_device', (int) $dev['id'], null, null, $e->getMessage() );
			if ( 'sign_count' === $e->getMessage() ) {
				self::notify_owner( 'אזהרה: ייתכן שהעתק של מפתח טלפון בשימוש', 'מונה החתימות של "' . $dev['label'] . '" חזר אחורה. הכניסה נחסמה. מומלץ לנתק את הטלפון ולחבר אותו מחדש.' );
			}
			throw new DomainError( 'gate_assertion', 'האימות בטלפון לא עבר', 403 );
		}
		$n = Db::exec( 'UPDATE ' . Db::t( 'gate_challenges' ) . " SET status = 'approved', device_id = %d, approved_at = %s WHERE id = %s AND status = 'pending'", (int) $dev['id'], Clock::utc(), $id );
		if ( 1 !== $n ) {
			throw new DomainError( 'gate_challenge_used', 'הקוד כבר נוצל', 409 );
		}
		Db::update( 'gate_devices', array( 'sign_count' => $count, 'last_used_at' => Clock::utc() ), array( 'id' => (int) $dev['id'] ) );
		Audit::log( 'gate.approved', 'gate_device', (int) $dev['id'], null, array( 'desktop_ip' => $c['ip'] ), '' );
		return array( 'ok' => true );
	}

	public static function phone_register( string $id, string $label, array $cred ): array {
		$c = self::live_challenge( $id, 'pair' );
		try {
			$k = WebAuthn::verify_registration( $cred, WebAuthn::b64u_decode( (string) $c['challenge'] ) );
		} catch ( \Throwable $e ) {
			Audit::log( 'gate.registration_failed', 'user', (int) $c['user_id'], null, null, $e->getMessage() );
			throw new DomainError( 'gate_registration', 'יצירת המפתח בטלפון לא עברה. יש לוודא שמוגדרים בטלפון Face ID, טביעת אצבע או קוד נעילה.', 422 );
		}
		if ( Db::value( 'SELECT id FROM ' . Db::t( 'gate_devices' ) . ' WHERE credential_id = %s', $k['credential_id'] ) ) {
			throw new DomainError( 'gate_duplicate', 'הטלפון הזה כבר מחובר', 409 );
		}
		$label = trim( wp_strip_all_tags( $label ) );
		$dev   = Db::insert(
			'gate_devices',
			array(
				'user_id'         => (int) $c['user_id'],
				'credential_id'   => $k['credential_id'],
				'public_key'      => $k['public_key'],
				'alg'             => $k['alg'],
				'sign_count'      => $k['sign_count'],
				'aaguid'          => $k['aaguid'],
				'backup_eligible' => $k['backup_eligible'] ? 1 : 0,
				'label'           => mb_substr( '' !== $label ? $label : 'טלפון', 0, 100 ),
				'status'          => 'unconfirmed',
				'created_at'      => Clock::utc(),
			)
		);
		$code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$n    = Db::exec( 'UPDATE ' . Db::t( 'gate_challenges' ) . " SET status = 'registered', device_id = %d, pair_code_hash = %s WHERE id = %s AND status = 'pending'", $dev, hash( 'sha256', $id . '|' . $code ), $id );
		if ( 1 !== $n ) {
			Db::exec( 'DELETE FROM ' . Db::t( 'gate_devices' ) . ' WHERE id = %d', $dev );
			throw new DomainError( 'gate_challenge_used', 'הקוד כבר נוצל', 409 );
		}
		return array( 'code' => $code );
	}

	private static function live_challenge( string $id, ?string $kind = null ): array {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $id ) ) {
			throw new DomainError( 'not_found', 'הקוד לא נמצא', 404 );
		}
		$c = Db::row( 'SELECT * FROM ' . Db::t( 'gate_challenges' ) . ' WHERE id = %s', $id );
		if ( ! $c || ( $kind && $c['kind'] !== $kind ) ) {
			throw new DomainError( 'not_found', 'הקוד לא נמצא', 404 );
		}
		if ( 'pending' !== $c['status'] ) {
			throw new DomainError( 'gate_challenge_used', 'הקוד הזה כבר לא פעיל. יש לרענן את המסך במחשב.', 410 );
		}
		if ( Clock::now() >= (int) Clock::ts( $c['expires_at'] ) ) {
			throw new DomainError( 'gate_expired', 'תוקף הקוד פג. יש לרענן את המסך במחשב.', 410 );
		}
		return $c;
	}

	private static function user_handle( int $user_id ): string {
		$h = (string) get_user_meta( $user_id, 'icol_gate_handle', true );
		if ( '' === $h ) {
			$h = WebAuthn::b64u_encode( random_bytes( 16 ) );
			update_user_meta( $user_id, 'icol_gate_handle', $h );
		}
		return $h;
	}

	public static function describe_ua( string $ua ): string {
		$browser = 'דפדפן';
		foreach ( array( 'Edg/' => 'Edge', 'OPR/' => 'Opera', 'Firefox/' => 'Firefox', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari' ) as $needle => $name ) {
			if ( str_contains( $ua, $needle ) ) {
				$browser = $name;
				break;
			}
		}
		$os = '';
		foreach ( array( 'Windows' => 'Windows', 'Mac OS X' => 'Mac', 'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Linux' => 'Linux' ) as $needle => $name ) {
			if ( str_contains( $ua, $needle ) ) {
				$os = $name;
				break;
			}
		}
		return trim( $browser . ( $os ? ' ב-' . $os : '' ) );
	}

	/* ------------------------------------------------------------- management */

	public static function list_devices(): array {
		$owner = self::is_owner();
		$rows  = $owner
			? Db::rows( 'SELECT d.*, u.display_name FROM ' . Db::t( 'gate_devices' ) . ' d LEFT JOIN ' . $GLOBALS['wpdb']->users . " u ON u.ID = d.user_id WHERE d.status <> 'unconfirmed' ORDER BY FIELD(d.status,'pending','active','revoked'), d.id DESC LIMIT 200" )
			: Db::rows( 'SELECT d.*, u.display_name FROM ' . Db::t( 'gate_devices' ) . ' d LEFT JOIN ' . $GLOBALS['wpdb']->users . " u ON u.ID = d.user_id WHERE d.user_id = %d AND d.status <> 'unconfirmed' ORDER BY d.id DESC", get_current_user_id() );
		return array_map(
			static fn( $d ) => array(
				'id'          => (int) $d['id'],
				'user'        => (string) $d['display_name'],
				'is_mine'     => (int) $d['user_id'] === get_current_user_id(),
				'label'       => (string) $d['label'],
				'status'      => (string) $d['status'],
				'synced'      => (bool) (int) $d['backup_eligible'],
				'created'     => Clock::display( $d['created_at'] ),
				'last_used'   => $d['last_used_at'] ? Clock::display( $d['last_used_at'] ) : '',
				'can_approve' => $owner && 'pending' === $d['status'],
				'can_revoke'  => 'revoked' !== $d['status'] && ( $owner || (int) $d['user_id'] === get_current_user_id() ),
			),
			$rows
		);
	}

	public static function approve_device( int $id ): void {
		if ( ! self::is_owner() ) {
			throw new DomainError( 'forbidden', 'רק בעל המערכת מאשר טלפונים', 403 );
		}
		$n = Db::exec( 'UPDATE ' . Db::t( 'gate_devices' ) . " SET status = 'active', approved_at = %s, approved_by = %d WHERE id = %d AND status = 'pending'", Clock::utc(), get_current_user_id(), $id );
		if ( 1 !== $n ) {
			throw new DomainError( 'conflict', 'הטלפון כבר לא ממתין לאישור', 409 );
		}
		Audit::log( 'gate.device_approved', 'gate_device', $id, null, null, '' );
	}

	public static function revoke_device( int $id ): void {
		$d = Db::row( 'SELECT * FROM ' . Db::t( 'gate_devices' ) . ' WHERE id = %d', $id );
		if ( ! $d || ( ! self::is_owner() && (int) $d['user_id'] !== get_current_user_id() ) ) {
			throw new DomainError( 'not_found', 'הטלפון לא נמצא', 404 );
		}
		Db::update( 'gate_devices', array( 'status' => 'revoked', 'revoked_at' => Clock::utc(), 'revoked_by' => get_current_user_id() ), array( 'id' => $id ) );
		Db::exec( 'UPDATE ' . Db::t( 'gate_sessions' ) . " SET revoked_at = %s, revoke_reason = 'device_revoked' WHERE device_id = %d AND revoked_at IS NULL", Clock::utc(), $id );
		self::flush();
		Audit::log( 'gate.device_revoked', 'gate_device', $id, null, null, '' );
		self::notify_owner( 'טלפון נותק ממערכת התשלומים', 'הטלפון "' . $d['label'] . '" נותק על ידי ' . wp_get_current_user()->display_name . '.' );
	}

	public static function list_sessions(): array {
		$owner = self::is_owner();
		$where = $owner ? '' : ' AND s.user_id = ' . (int) get_current_user_id();
		$cur   = self::current_session();
		$rows  = Db::rows( 'SELECT s.*, u.display_name, d.label FROM ' . Db::t( 'gate_sessions' ) . ' s LEFT JOIN ' . $GLOBALS['wpdb']->users . ' u ON u.ID = s.user_id LEFT JOIN ' . Db::t( 'gate_devices' ) . " d ON d.id = s.device_id WHERE s.revoked_at IS NULL AND s.expires_at > %s{$where} ORDER BY s.last_seen_at DESC LIMIT 100", Clock::utc() );
		return array_map(
			static fn( $s ) => array(
				'id'        => (int) $s['id'],
				'user'      => (string) $s['display_name'],
				'device'    => (string) $s['label'],
				'ip'        => (string) $s['ip'],
				'browser'   => self::describe_ua( (string) $s['user_agent'] ),
				'started'   => Clock::display( $s['created_at'] ),
				'last_seen' => Clock::display( $s['last_seen_at'] ),
				'current'   => $cur && (int) $cur['id'] === (int) $s['id'],
			),
			$rows
		);
	}

	public static function revoke_session( int $id ): void {
		$s = Db::row( 'SELECT * FROM ' . Db::t( 'gate_sessions' ) . ' WHERE id = %d', $id );
		if ( ! $s || ( ! self::is_owner() && (int) $s['user_id'] !== get_current_user_id() ) ) {
			throw new DomainError( 'not_found', 'החיבור לא נמצא', 404 );
		}
		self::end_session( $id, 'revoked' );
		Audit::log( 'gate.session_revoked', 'gate_session', $id, null, null, '' );
	}

	public static function save_settings( array $d ): array {
		if ( ! self::is_owner() ) {
			throw new DomainError( 'forbidden', 'רק בעל המערכת משנה את הגדרות האבטחה', 403 );
		}
		$idle  = (int) ( $d['idle_minutes'] ?? Settings::get( 'gate_idle_minutes' ) );
		$hours = (int) ( $d['session_hours'] ?? Settings::get( 'gate_session_hours' ) );
		if ( $idle < 5 || $idle > 240 || $hours < 1 || $hours > 24 ) {
			throw new DomainError( 'validation_failed', 'ערכים מחוץ לטווח', 400, array( 'idle_minutes' => '5 עד 240', 'session_hours' => '1 עד 24' ) );
		}
		Settings::set( array( 'gate_idle_minutes' => $idle, 'gate_session_hours' => $hours ) );
		Audit::log( 'gate.settings', 'user', get_current_user_id(), null, array( 'idle' => $idle, 'hours' => $hours ), '' );
		return array( 'idle_minutes' => $idle, 'session_hours' => $hours );
	}

	/* ------------------------------------------------------------------ misc */

	public static function notify_owner( string $subject, string $body ): void {
		$owner = self::owner_id() ? get_userdata( self::owner_id() ) : null;
		$to    = $owner ? $owner->user_email : (string) get_option( 'admin_email' );
		if ( $to ) {
			wp_mail( $to, '[INSIDERS תשלומים] ' . $subject, $body . "\n\n" . admin_url( 'admin.php?page=icol#/security' ) );
		}
	}

	/** With the gate off, every use is visible: banner on screen, and the owner hears about it (at most every 6 hours). */
	public static function note_bypass(): void {
		if ( ! self::off() || ! is_user_logged_in() || get_transient( 'icol_gate_off_mail' ) ) {
			return;
		}
		set_transient( 'icol_gate_off_mail', 1, 6 * HOUR_IN_SECONDS );
		Audit::log( 'gate.off_in_use', 'user', get_current_user_id(), null, null, '' );
		self::notify_owner( 'שער הסריקה כבוי', 'ICOL_GATE_OFF מוגדר ב-wp-config.php, ולכן כניסה למערכת התשלומים לא דורשת סריקה. המשתמש האחרון: ' . wp_get_current_user()->display_name . '. אחרי שחזור הגישה יש למחוק את השורה.' );
	}

	public static function cleanup(): void {
		Db::exec( 'DELETE FROM ' . Db::t( 'gate_challenges' ) . ' WHERE created_at < %s', Clock::utc( Clock::now() - DAY_IN_SECONDS ) );
		Db::exec( 'DELETE FROM ' . Db::t( 'gate_devices' ) . " WHERE status = 'unconfirmed' AND created_at < %s", Clock::utc( Clock::now() - 15 * MINUTE_IN_SECONDS ) );
		Db::exec( 'UPDATE ' . Db::t( 'gate_sessions' ) . " SET revoked_at = %s, revoke_reason = 'expired' WHERE revoked_at IS NULL AND expires_at < %s", Clock::utc(), Clock::utc() );
	}

	/** Diagnostics: counts and configuration only. */
	public static function report(): array {
		return array(
			'enforced'        => self::enforced(),
			'off'             => self::off(),
			'owner'           => self::owner_id() ? get_userdata( self::owner_id() )->user_login . ( defined( 'ICOL_GATE_OWNER' ) ? ' (wp-config)' : ' (נקבע מהמסך)' ) : 'עוד לא נקבע. נקבע במסך התשלומים, בכפתור ״להגדיר אותי כבעלים של המערכת״',
			'rp_id'           => WebAuthn::rp_id(),
			'origins'         => WebAuthn::origins(),
			'secure_context'  => WebAuthn::secure_context(),
			'qr_library'      => Qr::available(),
			'openssl'         => function_exists( 'openssl_verify' ),
			'devices'         => Db::rows( 'SELECT status, COUNT(*) n FROM ' . Db::t( 'gate_devices' ) . ' GROUP BY status' ),
			'open_sessions'   => (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'gate_sessions' ) . ' WHERE revoked_at IS NULL AND expires_at > %s', Clock::utc() ),
			'idle_minutes'    => (int) Settings::get( 'gate_idle_minutes' ),
			'session_hours'   => (int) Settings::get( 'gate_session_hours' ),
		);
	}
}

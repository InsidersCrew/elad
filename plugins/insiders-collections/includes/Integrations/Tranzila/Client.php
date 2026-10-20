<?php
namespace Insiders\Collections\Integrations\Tranzila;

use Insiders\Collections\Support\Http;
use Insiders\Collections\Support\Money;
use Insiders\Collections\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Tranzila API client. Keys stay server-side; the browser never sees them.
 *
 * Auth (docs "authentication", confirmed by two independent libraries 2026-10):
 *   X-tranzila-api-app-key, X-tranzila-api-request-time (unix seconds),
 *   X-tranzila-api-nonce (40 chars), X-tranzila-api-access-token =
 *   hash_hmac('sha256', app_key, secret . time . nonce) — lowercase hex.
 * A docs excerpt suggested the opposite concatenation once; the diagnostic
 * ?icol_diag=tranzila_auth tests both against a harmless read before anything else.
 *
 * Field names for pr/create, stos/get and reports come from third-party code
 * mirroring the docs and are marked UNVERIFIED until a sandbox call confirms them.
 */
final class Client {

	public static function configured(): bool {
		return Settings::has_secret( 'tranzila_app_key' ) && Settings::has_secret( 'tranzila_secret' );
	}

	public static function auth_headers( ?int $time = null, ?string $nonce = null, string $order = 'secret_time_nonce' ): array {
		$app    = Settings::secret( 'tranzila_app_key' );
		$secret = Settings::secret( 'tranzila_secret' );
		$time   = $time ?? time();
		$nonce  = $nonce ?? bin2hex( random_bytes( 40 ) ); // 80 hex characters, as in Tranzila's samples
		$key    = 'secret_time_nonce' === $order ? $secret . $time . $nonce : $time . $nonce . $secret;
		return array(
			'X-tranzila-api-app-key'      => $app,
			'X-tranzila-api-request-time' => (string) $time,
			'X-tranzila-api-nonce'        => $nonce,
			'X-tranzila-api-access-token' => hash_hmac( 'sha256', $app, $key ),
			'Content-Type'                => 'application/json',
			'Accept'                      => 'application/json',
		);
	}

	private static function post( string $base_key, string $path, array $body, bool $is_write ): array {
		if ( ! self::configured() ) {
			return array( 'outcome' => Http::PERMANENT, 'status' => 0, 'json' => null, 'error' => 'not configured', 'body' => '' );
		}
		$base = rtrim( (string) Settings::get( $base_key ), '/' );
		return Http::request(
			'POST',
			$base . $path,
			array(
				'headers' => self::auth_headers( null, null, (string) Settings::get( 'tranzila_hmac_order', 'secret_time_nonce' ) ),
				'body'    => wp_json_encode( $body ),
				'timeout' => 10,
			),
			$is_write
		);
	}

	/** UNVERIFIED shape: request {terminal_name, sto_id}; response {stos:[{sto_id, next_charge_date, card:{token, expire_month, expire_year}, ...}]}. */
	public static function get_sto( string $terminal, string $sto_id ): array {
		$r = self::post( 'tranzila_api_base', '/v1/stos/get', array( 'terminal_name' => $terminal, 'sto_id' => is_numeric( $sto_id ) ? (int) $sto_id : $sto_id ), false );
		if ( Http::OK !== $r['outcome'] ) {
			return array( 'ok' => false, 'error' => $r['error'] ?: ( 'HTTP ' . $r['status'] ), 'outcome' => $r['outcome'] );
		}
		$sto = $r['json']['stos'][0] ?? null;
		return $sto ? array( 'ok' => true, 'sto' => $sto ) : array( 'ok' => false, 'error' => 'sto not found', 'outcome' => Http::PERMANENT );
	}

	/**
	 * Every standing order of a terminal in one status (docs: sto-api-for-my-billing/getstos; the live
	 * answer of 2026-10-17 returned 103 active orders in one list, no paging). A 200 can still carry an
	 * application error (20302).
	 */
	public static function stos( string $terminal, string $status ): array {
		$r = self::post( 'tranzila_api_base', '/v1/stos/get', array( 'terminal_name' => $terminal, 'sto_status' => $status ), false );
		if ( Http::OK !== $r['outcome'] ) {
			return array( 'ok' => false, 'error' => $r['error'] ?: ( 'HTTP ' . $r['status'] ), 'stos' => array() );
		}
		if ( (int) ( $r['json']['error_code'] ?? 0 ) !== 0 ) {
			return array( 'ok' => false, 'error' => 'error_code ' . (int) $r['json']['error_code'] . ': ' . mb_substr( (string) ( $r['json']['message'] ?? '' ), 0, 120 ), 'stos' => array() );
		}
		$stos = $r['json']['stos'] ?? array();
		return array( 'ok' => true, 'stos' => is_array( $stos ) ? $stos : array() );
	}

	/**
	 * UNVERIFIED shape: {terminal_name, transaction_start_date, transaction_end_date | transaction_index, page, page_results}.
	 * Response: {transactions:[...], total, rows}. Field names differ from the notification
	 * (amount / processor_response_code / credit_card_token vs sum / Response / TranzilaTK).
	 */
	public static function transactions( string $terminal, array $filter, int $page, int $page_size ): array {
		$body = array_merge( array( 'terminal_name' => $terminal, 'page' => $page, 'page_results' => $page_size ), $filter );
		$r    = self::post( 'tranzila_report_base', '/v1/transaction', $body, false );
		if ( Http::OK !== $r['outcome'] ) {
			return array( 'ok' => false, 'error' => $r['error'] ?: ( 'HTTP ' . $r['status'] ), 'outcome' => $r['outcome'], 'rows' => array() );
		}
		$rows = $r['json']['transactions'] ?? ( $r['json']['data'] ?? array() );
		return array( 'ok' => true, 'rows' => is_array( $rows ) ? $rows : array(), 'total' => (int) ( $r['json']['total'] ?? 0 ) );
	}

	/**
	 * Successful card payments by a phone number, from the transaction report (date range + the
	 * terminal's phone field, docs: reports/.../gettransactions). Used to find the card a student
	 * paid the registration fee with. Rows come back normalized, newest first.
	 */
	public static function card_payments_by_phone( string $terminal, string $phone_e164, string $from, string $to ): array {
		$digits = preg_replace( '/\D/', '', $phone_e164 );
		if ( strlen( $digits ) < 9 || $from > $to ) {
			return array( 'ok' => false, 'error' => 'no phone or no dates', 'rows' => array() );
		}
		$filter = array(
			'transaction_start_date' => $from,
			'transaction_end_date'   => $to,
			'order_direction'        => 'desc',
			// The terminal keeps the phone as typed ("0501234567"); the last nine digits match any prefix.
			'ufields'                => array( array( 'name' => (string) Settings::get( 'tranzila_phone_field', 'phone' ), 'operator' => 'ends_with', 'value' => substr( $digits, -9 ) ) ),
		);
		$r = self::transactions( $terminal, $filter, 1, 50 );
		if ( ! $r['ok'] ) {
			return $r;
		}
		$out = array();
		foreach ( $r['rows'] as $row ) {
			$n = self::normalize_report_row( (array) $row );
			if ( ResponseCodes::is_success( $n['response'] ) && '' !== $n['token'] && 'DEBIT' === strtoupper( (string) ( $row['txn_type'] ?? 'DEBIT' ) ) ) {
				$n['broker'] = strtoupper( (string) ( $row['broker'] ?? '' ) );
				$out[]       = $n;
			}
		}
		return array( 'ok' => true, 'rows' => $out );
	}

	/**
	 * One-time charge of a saved token (docs: tranzila-transactions-api-1/create-a-credit-card-transaction).
	 * Tranzila (2026-10-18): tokens are shared between the terminals and are charged on the token terminal,
	 * which needs no ID number or CVV. Outcome: approved | declined | unknown (no answer: never retried).
	 */
	public static function charge_token( string $terminal, string $token, int $month, int $year, int $amount_minor, string $description, string $dedupe ): array {
		$body = array(
			'terminal_name'     => $terminal,
			'txn_type'          => 'debit',
			'txn_currency_code' => 'ILS',
			'card_number'       => $token,
			'expire_month'      => $month,
			'expire_year'       => $year < 100 ? 2000 + $year : $year,
			'payment_plan'      => 1,
			'items'             => array( array( 'name' => mb_substr( $description, 0, 100 ), 'type' => 'I', 'unit_price' => round( $amount_minor / 100, 2 ), 'units_number' => 1, 'price_type' => 'G' ) ),
			'created_by_system' => 'insiders-collections',
			'remarks'           => $dedupe,
		);
		if ( Settings::on( 'tranzila_dcdisable' ) ) {
			// Field 20 defined as DCdisable on the terminal: Tranzila refuses a second success with the same value for 24 hours.
			$body['user_defined_fields'] = array( array( 'name' => 'DCdisable', 'value' => $dedupe ) );
		}
		$r = self::post( 'tranzila_api_base', '/v1/transaction/credit_card/create', $body, true );
		if ( Http::OK !== $r['outcome'] ) {
			// A timeout or a 5xx may have charged the card: the result is unknown, never retried blindly.
			$known_refusal = in_array( (int) $r['status'], array( 400, 401, 403, 404 ), true );
			return array( 'outcome' => $known_refusal ? 'declined' : 'unknown', 'response' => '', 'error' => $r['error'] ?: ( 'HTTP ' . $r['status'] ) );
		}
		$j   = (array) $r['json'];
		$res = (array) ( $j['transaction_result'] ?? array() );
		if ( 0 !== (int) ( $j['error_code'] ?? 0 ) ) {
			return array( 'outcome' => 'declined', 'response' => (string) ( $j['error_code'] ?? '' ), 'error' => (string) ( $j['message'] ?? 'error' ) );
		}
		$code = ResponseCodes::normalize( $res['processor_response_code'] ?? '' );
		if ( ResponseCodes::is_success( $code ) && ! empty( $res['transaction_id'] ) ) {
			return array( 'outcome' => 'approved', 'response' => $code, 'transaction_id' => (string) $res['transaction_id'], 'auth' => (string) ( $res['auth_number'] ?? '' ), 'last4' => (string) ( $res['last_4'] ?? '' ), 'error' => '' );
		}
		return array( 'outcome' => '' === $code ? 'unknown' : 'declined', 'response' => $code, 'error' => (string) ( $j['message'] ?? '' ) );
	}

	/** The terminal's user-defined fields: field_id => {name, caption}. Configuration only, no customer data. */
	public static function terminal_fields( string $terminal ): array {
		$r = self::post( 'tranzila_report_base', '/v1/terminals/settings/fields/list', array( 'terminal_name' => $terminal ), false );
		if ( Http::OK !== $r['outcome'] || ! is_array( $r['json'] ) ) {
			return array();
		}
		$out = array();
		foreach ( $r['json'] as $f ) {
			if ( is_array( $f ) && isset( $f['field_id'] ) ) {
				$out[ (int) $f['field_id'] ] = array( 'name' => (string) ( $f['api_parameter_name'] ?? '' ), 'caption' => (string) ( $f['caption'] ?? '' ) );
			}
		}
		ksort( $out );
		return $out;
	}

	public static function transaction( string $terminal, string $index ): array {
		$r = self::transactions( $terminal, array( 'transaction_index' => is_numeric( $index ) ? (int) $index : $index ), 1, 10 );
		if ( ! $r['ok'] ) {
			return $r;
		}
		foreach ( $r['rows'] as $row ) {
			if ( (string) ( $row['index'] ?? $row['transaction_index'] ?? '' ) === (string) $index ) {
				return array( 'ok' => true, 'row' => self::normalize_report_row( $row ) );
			}
		}
		return array( 'ok' => false, 'error' => 'transaction not found', 'outcome' => Http::PERMANENT );
	}

	/** Report row -> the notification vocabulary used by the processor. Raw values kept beside normalized ones. */
	public static function normalize_report_row( array $row ): array {
		// The report returns agorot: a live 100 ₪ transaction came back as amount 10000 (2026-10-17).
		$unit   = (string) Settings::get( 'tranzila_report_amount_unit', 'minor' );
		$amount = $row['amount'] ?? $row['sum'] ?? null;
		$minor  = null === $amount ? null : ( 'minor' === $unit ? (int) $amount : Money::from_provider( $amount ) );
		return array(
			'index'         => (string) ( $row['index'] ?? $row['transaction_index'] ?? '' ),
			'amount_minor'  => $minor,
			'currency'      => Money::normalize_currency( $row['currency'] ?? $row['currency_code'] ?? '' ),
			'response'      => ResponseCodes::normalize( $row['processor_response_code'] ?? $row['Response'] ?? $row['response_code'] ?? '' ),
			'tranmode'      => (string) ( $row['tranmode'] ?? $row['txn_type'] ?? '' ),
			'token'         => (string) ( $row['credit_card_token'] ?? $row['TranzilaTK'] ?? '' ),
			'expiry'        => isset( $row['expiration_month'], $row['expiration_year'] ) ? sprintf( '%02d%02d', (int) $row['expiration_month'], (int) $row['expiration_year'] % 100 ) : (string) ( $row['expdate'] ?? '' ),
			'sto_id'        => (string) ( $row['sto_id'] ?? $row['sto_external_id'] ?? '' ),
			'date'          => trim( (string) ( $row['transaction_date'] ?? '' ) . ' ' . (string) ( $row['transaction_time'] ?? '' ) ),
			'raw'           => $row,
		);
	}

	/**
	 * POST /v1/pr/create, per docs.tranzila.com (payment-request/get-v1-pr-create, read 2026-10-17).
	 * Required: terminal_name, created_by_user, created_by_system, created_via, request_date (null),
	 * request_language, response_language, client (name, contact_person, email), items (name,
	 * unit_price, units_number), payment_plans, payment_methods, payments_number.
	 * send_email is required in practice (20404 without it); it goes to the business address, not the student.
	 * No request_vat: the terminal's accounting module decides. Response: pr_id and pr_link;
	 * the Notify of the payment carries pr_id. 'tranzila_pr_extra' can still add fields.
	 */
	public static function create_payment_request( array $req, array $items, array $customer ): array {
		$terminal = (string) Settings::get( 'tranzila_pr_terminal', 'insiders' );
		// The schema requires name, contact_person and a valid email on the client (a live request without
		// contact_person came back "Json does not match validation schema", 2026-10-19), and Tranzila refuses a
		// request that it does not send by email or SMS (error 20404, 2026-10-20). The student gets the link in
		// WhatsApp, so the email goes to the business address, never to the student.
		$name  = trim( (string) ( $customer['full_name'] ?? '' ) ) ?: 'לקוח';
		$email = (string) Settings::get( 'tranzila_pr_fallback_email' ) ?: (string) get_option( 'admin_email' );
		$client = array_merge(
			array(
				'external_id'    => 'icol-' . (int) $req['id'],
				'name'           => $name,
				'contact_person' => $name,
				'email'          => $email,
			),
			self::phone_parts( (string) ( $customer['phone_e164'] ?? '' ) )
		);
		$currency = (string) ( $req['currency'] ?: 'ILS' );
		$body     = array(
			'terminal_name'     => $terminal,
			'created_by_user'   => 'insiders-collections',
			'created_by_system' => 'insiders-collections',
			'created_via'       => 'TRAPI',
			'action_type'       => 1,
			'request_date'      => null,
			'request_language'  => 'hebrew',
			'response_language' => 'hebrew',
			'request_currency'  => $currency,
			'payments_number'   => 1,
			'payment_plans'     => array( 1 ),
			'payment_methods'   => array( 1 ),
			'client'            => $client,
			'send_email'        => array( 'sender_name' => 'INSIDERS', 'sender_email' => 'donotreply@tranzila.com' ),
			'items'             => array_map(
				static fn( $i ) => array(
					'name'          => mb_substr( (string) $i['description'], 0, 100 ),
					'type'          => 'I',
					'unit_price'    => (float) number_format( $i['amount_minor'] / 100, 2, '.', '' ),
					'units_number'  => 1,
					'unit_type'     => 1,
					'price_type'    => 'G',
					'currency_code' => $currency,
				),
				$items
			),
		);
		$extra = json_decode( (string) Settings::get( 'tranzila_pr_extra', '' ), true );
		if ( is_array( $extra ) ) {
			$body = array_replace_recursive( $body, $extra );
		}
		$r = self::post( 'tranzila_api_base', '/v1/pr/create', $body, true );
		if ( Http::OK !== $r['outcome'] ) {
			return array( 'outcome' => $r['outcome'], 'error' => $r['error'] ?: ( 'HTTP ' . $r['status'] ) );
		}
		$pr_id = (string) ( $r['json']['pr_id'] ?? '' );
		$link  = (string) ( $r['json']['pr_link'] ?? $r['json']['url'] ?? '' );
		if ( '' === $pr_id || '' === $link || ( isset( $r['json']['error_code'] ) && 0 !== (int) $r['json']['error_code'] ) ) {
			// The whole answer, not only the message: a schema error may name the field after it.
			$why = is_array( $r['json'] ) ? wp_json_encode( $r['json'], JSON_UNESCAPED_UNICODE ) : 'missing pr_id/pr_link';
			return array( 'outcome' => Http::PERMANENT, 'error' => mb_substr( (string) $why, 0, 400 ) );
		}
		return array( 'outcome' => Http::OK, 'pr_id' => $pr_id, 'url' => $link );
	}

	/** "+972501234567" -> 972 / 050 / 1234567, the three client fields Tranzila asks for. Other countries: none. */
	public static function phone_parts( string $e164 ): array {
		$d = preg_replace( '/\D/', '', $e164 );
		if ( ! str_starts_with( $d, '972' ) || strlen( $d ) < 11 ) {
			return array();
		}
		$local = '0' . substr( $d, 3 );
		$cut   = in_array( substr( $local, 0, 2 ), array( '05', '07' ), true ) ? 3 : 2;
		return array(
			'phone_country_code' => '972',
			'phone_area_code'    => substr( $local, 0, $cut ),
			'phone_number'       => substr( $local, $cut ),
		);
	}
}

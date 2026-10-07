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
		$nonce  = $nonce ?? bin2hex( random_bytes( 20 ) );
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
		$unit   = (string) Settings::get( 'tranzila_report_amount_unit', 'major' );
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
	 * UNVERIFIED shape (docs show inconsistent examples, §16): POST /v1/pr/create.
	 * Response carries pr_id and pr_link (not "url"). Extra fields can be merged from the
	 * 'tranzila_pr_extra' setting once the sandbox confirms the account's schema —
	 * no VAT rate, payment method or field is invented here.
	 */
	public static function create_payment_request( array $req, array $items, array $customer ): array {
		$terminal = (string) Settings::get( 'tranzila_pr_terminal', 'insiders' );
		$body     = array(
			'terminal_name'     => $terminal,
			'created_by_user'   => 'insiders-collections',
			'created_via'       => 'API',
			'action_type'       => 1,
			'request_currency'  => 'ILS' === $req['currency'] ? 1 : $req['currency'],
			'payments_number'   => 1,
			'payment_plans'     => array( 1 ),
			'response_language' => 'hebrew',
			'request_language'  => 'hebrew',
			'client'            => array(
				'name'         => (string) $customer['full_name'],
				'email'        => (string) ( $customer['email'] ?? '' ),
				'phone_number' => (string) ( $customer['phone_e164'] ?? '' ),
				'internal_id'  => 'icol-' . (int) $req['id'],
			),
			'items'             => array_map(
				static fn( $i ) => array(
					'name'         => mb_substr( (string) $i['description'], 0, 100 ),
					'unit_price'   => (float) number_format( $i['amount_minor'] / 100, 2, '.', '' ),
					'units_number' => 1,
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
			return array( 'outcome' => Http::PERMANENT, 'error' => (string) ( $r['json']['message'] ?? 'missing pr_id/pr_link' ) );
		}
		return array( 'outcome' => Http::OK, 'pr_id' => $pr_id, 'url' => $link );
	}
}

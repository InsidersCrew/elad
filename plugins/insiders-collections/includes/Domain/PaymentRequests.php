<?php
namespace Insiders\Collections\Domain;

use Insiders\Collections\Integrations\Tranzila\Client as TranzilaClient;
use Insiders\Collections\Support\Audit;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Crypto;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Ids;
use Insiders\Collections\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Payment links (§8, §19). The customer gets OUR link (/pay/<random>), never the
 * provider's. Opening it (GET) only shows a page: WhatsApp's link-preview bot
 * opens every link it sees. The provider request is created on an explicit
 * click, after a fresh balance read, and reused while the balance is unchanged.
 */
final class PaymentRequests {

	public static function link_allowed( array $case ): array {
		if ( ! Settings::on( 'cap_payment_links' ) ) {
			return array( 'allowed' => false, 'reason' => 'יכולת קישורי התשלום כבויה' );
		}
		if ( ! TranzilaClient::configured() ) {
			return array( 'allowed' => false, 'reason' => 'חיבור טרנזילה לא הוגדר' );
		}
		$recurring = (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'debt_items' ) . " WHERE case_id = %d AND collection_owner = 'my_billing' AND finance_state IN ('open','partially_paid')", (int) $case['id'] );
		if ( $recurring > 0 && ! Settings::on( 'cap_separate_payment_recurring' ) ) {
			// §8 / AT26: My Billing may retry the same item; a separate link could charge twice.
			return array( 'allowed' => false, 'reason' => 'פריט מחזורי בבעלות My Billing, תשלום נפרד חסום עד תיאום מאומת עם טרנזילה' );
		}
		return array( 'allowed' => true, 'reason' => '' );
	}

	public static function url( string $token ): string {
		return home_url( '/' . trim( (string) Settings::get( 'pay_base_path' ), '/' ) . '/' . $token );
	}

	/** One active request per case and balance signature (AT27). */
	public static function local_link( int $case_id ): ?string {
		return Db::transaction(
			function () use ( $case_id ) {
				$case    = Workflow::get( $case_id, true );
				$summary = Ledger::case_summary( $case_id );
				if ( $summary['due_balance_minor'] <= 0 ) {
					return null;
				}
				$active = Db::row( 'SELECT * FROM ' . Db::t( 'payment_requests' ) . " WHERE case_id = %d AND status IN ('draft','active') ORDER BY id DESC LIMIT 1", $case_id );
				if ( $active && $active['balance_version'] === $summary['signature'] && (int) $active['amount_minor'] === $summary['due_balance_minor'] && $active['token_enc'] ) {
					$token = Crypto::decrypt( $active['token_enc'] );
					if ( $token ) {
						return self::url( $token );
					}
				}
				if ( $active ) {
					Db::update( 'payment_requests', array( 'status' => 'superseded', 'updated_at' => Clock::utc() ), array( 'id' => (int) $active['id'] ) );
				}
				$token = Ids::token();
				$id    = Db::insert(
					'payment_requests',
					array(
						'case_id'         => $case_id,
						'token_hash'      => Ids::hash( $token ),
						'token_enc'       => Crypto::available() ? Crypto::encrypt( $token ) : null,
						'amount_minor'    => $summary['due_balance_minor'],
						'currency'        => $summary['currency'],
						'balance_version' => $summary['signature'],
						'status'          => 'active',
						'expires_at'      => Clock::utc( Clock::now() + 14 * DAY_IN_SECONDS ),
						'created_by'      => get_current_user_id() ?: null,
						'created_at'      => Clock::utc(),
						'updated_at'      => Clock::utc(),
					)
				);
				foreach ( $summary['items'] as $it ) {
					if ( in_array( $it['finance_state'], array( 'open', 'partially_paid' ), true ) ) {
						Db::insert( 'request_items', array( 'payment_request_id' => $id, 'debt_item_id' => (int) $it['id'], 'requested_amount_minor' => (int) $it['cached_balance_minor'] ) );
					}
				}
				return self::url( $token );
			}
		);
	}

	/** Pay page state for a token. Never creates anything. */
	public static function page_state( string $token ): array {
		$req = Db::row( 'SELECT * FROM ' . Db::t( 'payment_requests' ) . ' WHERE token_hash = %s', Ids::hash( $token ) );
		if ( ! $req ) {
			return array( 'state' => 'not_found' );
		}
		if ( Settings::on( 'kill_switch' ) ) {
			return array( 'state' => 'paused', 'request' => $req );
		}
		$case    = Workflow::get( (int) $req['case_id'] );
		$summary = Ledger::case_summary( (int) $req['case_id'] );
		if ( null === $req['opened_at'] ) {
			Db::update( 'payment_requests', array( 'opened_at' => Clock::utc() ), array( 'id' => (int) $req['id'] ) );
		}
		if ( 'paid' === $req['status'] || ( $summary['due_balance_minor'] <= 0 && ! $summary['review_items'] ) ) {
			return array( 'state' => 'paid', 'request' => $req );
		}
		if ( in_array( $req['status'], array( 'expired', 'cancelled' ), true ) || ( Clock::ts( $req['expires_at'] ) ?? 0 ) < Clock::now() ) {
			return array( 'state' => 'expired', 'request' => $req );
		}
		if ( 'unknown' === $req['status'] ) {
			return array( 'state' => 'checking', 'request' => $req );
		}
		if ( in_array( $case['workflow_state'], array( 'human_review', 'paused', 'payment_verification', 'closed' ), true ) || $summary['review_items'] ) {
			return array( 'state' => 'on_hold', 'request' => $req );
		}
		$changed = 'superseded' === $req['status'] || $req['balance_version'] !== $summary['signature'] || (int) $req['amount_minor'] !== $summary['due_balance_minor'];
		$items   = array_values( array_filter( $summary['items'], static fn( $i ) => in_array( $i['finance_state'], array( 'open', 'partially_paid' ), true ) ) );
		$customer = Customers::get( (int) $case['customer_id'] );
		return array(
			'state'        => $changed ? 'changed' : 'ready',
			'request'      => $req,
			'amount_minor' => $summary['due_balance_minor'],
			'currency'     => $summary['currency'],
			'items'        => array_map( static fn( $i ) => array( 'description' => $i['description'] ?: ( 'תשלום ' . gmdate( 'm/Y', strtotime( $i['due_at'] ) ) ), 'amount_minor' => (int) $i['cached_balance_minor'] ), $items ),
			// What the WhatsApp message said, so a changed page can explain the difference.
			'sent_minor'   => (int) $req['amount_minor'],
			'first_name'   => Customers::greeting_name( $customer ),
			'link_route'   => self::link_allowed( $case ),
		);
	}

	/** POST from the pay page: fresh checks, then reuse or create the provider request. */
	public static function checkout( string $token ): array {
		$state = self::page_state( $token );
		if ( 'changed' === $state['state'] ) {
			// The customer confirms the new amount on a fresh page; we never charge a stale amount.
			$url = self::local_link( (int) $state['request']['case_id'] );
			return array( 'result' => 'changed', 'url' => $url );
		}
		if ( 'ready' !== $state['state'] ) {
			return array( 'result' => $state['state'] );
		}
		if ( ! $state['link_route']['allowed'] ) {
			return array( 'result' => 'on_hold' );
		}
		$req = $state['request'];
		return Db::transaction(
			function () use ( $req, $state ) {
				$fresh = Db::row( 'SELECT * FROM ' . Db::t( 'payment_requests' ) . ' WHERE id = %d FOR UPDATE', (int) $req['id'] );
				if ( $fresh['provider_pr_id'] && $fresh['provider_url_enc'] ) {
					return array( 'result' => 'redirect', 'url' => (string) Crypto::decrypt( $fresh['provider_url_enc'] ) );
				}
				if ( 'unknown' === $fresh['status'] ) {
					return array( 'result' => 'checking' );
				}
				$case     = Workflow::get( (int) $fresh['case_id'] );
				$customer = Customers::get( (int) $case['customer_id'] );
				$res      = TranzilaClient::create_payment_request( $fresh, $state['items'], $customer );
				if ( 'ok' === $res['outcome'] ) {
					Db::update(
						'payment_requests',
						array(
							'provider_pr_id'   => $res['pr_id'],
							'provider_url_enc' => Crypto::available() ? Crypto::encrypt( $res['url'] ) : null,
							'updated_at'       => Clock::utc(),
						),
						array( 'id' => (int) $fresh['id'] )
					);
					Audit::log( 'payment_request.provider_created', 'payment_request', (int) $fresh['id'], null, array( 'pr_id' => $res['pr_id'] ), '' );
					return array( 'result' => 'redirect', 'url' => $res['url'] );
				}
				if ( 'unknown' === $res['outcome'] ) {
					// §20: a timed-out create may exist at the provider. Find it before creating again.
					Db::update( 'payment_requests', array( 'status' => 'unknown', 'updated_at' => Clock::utc() ), array( 'id' => (int) $fresh['id'] ) );
					Exceptions::open( 'pr_unknown:' . $fresh['id'], 'integration_failure', 'high', 'יצירת בקשת תשלום בטרנזילה הסתיימה בתוצאה לא ידועה', array( 'entity_type' => 'payment_request', 'entity_id' => (int) $fresh['id'] ) );
					return array( 'result' => 'checking' );
				}
				Exceptions::open( 'pr_error:' . $fresh['id'], 'integration_failure', 'medium', 'טרנזילה דחתה יצירת בקשת תשלום: ' . mb_substr( (string) $res['error'], 0, 200 ), array( 'entity_type' => 'payment_request', 'entity_id' => (int) $fresh['id'] ) );
				return array( 'result' => 'error' );
			}
		);
	}

	/** Paid/void bookkeeping when a payment matched this request. */
	public static function mark_paid( int $request_id ): void {
		Db::update( 'payment_requests', array( 'status' => 'paid', 'updated_at' => Clock::utc() ), array( 'id' => $request_id ) );
	}
}

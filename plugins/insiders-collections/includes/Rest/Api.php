<?php
namespace Insiders\Collections\Rest;

use Insiders\Collections\Auth\Capabilities;
use Insiders\Collections\Domain\Cases;
use Insiders\Collections\Domain\CardTasks;
use Insiders\Collections\Domain\Customers;
use Insiders\Collections\Domain\DomainError;
use Insiders\Collections\Domain\Exceptions;
use Insiders\Collections\Domain\Ledger;
use Insiders\Collections\Domain\Matching;
use Insiders\Collections\Domain\Messaging;
use Insiders\Collections\Domain\PaymentRequests;
use Insiders\Collections\Domain\Payments;
use Insiders\Collections\Domain\Policy;
use Insiders\Collections\Domain\Promises;
use Insiders\Collections\Domain\Tasks;
use Insiders\Collections\Domain\Templates;
use Insiders\Collections\Domain\Workflow;
use Insiders\Collections\Engine\Inbox;
use Insiders\Collections\Engine\Runner;
use Insiders\Collections\Support\Audit;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Money;
use Insiders\Collections\Support\Settings;

defined( 'ABSPATH' ) || exit;

/** Internal API (§19). Every mutation: identified user, server-side capability, Idempotency-Key, record version. */
final class Api {
	public const NS = 'icol/v1';

	public static function register(): void {
		$r = static function ( string $method, string $path, string $cap, callable $fn, bool $mutation = false ) {
			register_rest_route(
				self::NS,
				$path,
				array(
					'methods'             => $method,
					'permission_callback' => static fn() => is_user_logged_in() && current_user_can( $cap ),
					'callback'            => static fn( \WP_REST_Request $req ) => $mutation ? Http::mutate( $req, static fn() => $fn( $req ) ) : Http::run( static fn() => $fn( $req ) ),
				)
			);
		};
		$id = '(?P<id>\d+)';

		// Reads.
		$r( 'GET', '/dashboard', 'icol_view', static fn() => Views::dashboard() );
		$r( 'GET', '/cases', 'icol_view', static fn( $q ) => Views::cases( $q ) );
		$r( 'GET', "/cases/$id", 'icol_view', static fn( $q ) => Views::case_card( Http::int( $q, 'id' ) ) );
		$r( 'GET', '/customers', 'icol_view', static fn( $q ) => Views::customers( $q ) );
		$r( 'GET', '/customers/duplicates', 'icol_create_draft', static fn( $q ) => Customers::duplicates( Http::str( $q, 'phone' ), Http::str( $q, 'email' ), Http::int( $q, 'wp_user_id' ) ?: null ) );
		$r( 'GET', '/exceptions', 'icol_view', static fn( $q ) => Views::exceptions( $q ) );
		$r( 'GET', '/card-tasks', 'icol_view', static fn( $q ) => Views::card_tasks( $q ) );
		$r( 'GET', '/tasks', 'icol_view', static fn( $q ) => Views::tasks( $q ) );
		$r( 'GET', '/candidates', 'icol_view', static fn() => Views::candidates() );
		$r( 'GET', '/health', 'icol_view', static fn() => Views::health() );
		$r( 'GET', '/settings', 'icol_admin', static fn() => Views::settings() );
		$r( 'GET', '/staff', 'icol_view', static fn() => self::staff() );
		$r( 'GET', '/export/cases', 'icol_export', static fn( $q ) => self::export( $q ) );

		// Customers.
		$r( 'POST', '/customers', 'icol_create_draft', static fn( $q ) => array( 'customer_id' => Customers::create( (array) $q->get_json_params() ) ), true );
		$r( 'POST', "/customers/$id/verify-contact", 'icol_approve_debt', static function ( $q ) { Customers::verify_contact( Http::int( $q, 'id' ), Http::str( $q, 'note' ) ); return array( 'ok' => true ); }, true );
		$r( 'POST', "/customers/$id/permission", 'icol_work_case', static function ( $q ) {
			$src = Http::str( $q, 'source' );
			if ( '' === $src ) {
				throw new DomainError( 'validation_failed', 'יש לתעד את מקור ההרשאה', 400, array( 'source' => 'חובה' ) );
			}
			Customers::set_permission( Http::int( $q, 'id' ), 'whatsapp', (bool) $q->get_param( 'allowed' ), $src );
			return array( 'ok' => true );
		}, true );
		$r( 'POST', "/customers/$id/conversation-owner", 'icol_work_case', static function ( $q ) {
			$owner = Http::str( $q, 'owner' );
			if ( ! in_array( $owner, array( 'none', 'collections', 'onboarding', 'human' ), true ) ) {
				throw new DomainError( 'validation_failed', 'בעלות לא תקינה', 400 );
			}
			Db::update( 'customers', array( 'conversation_owner' => $owner, 'conversation_owner_at' => Clock::utc() ), array( 'id' => Http::int( $q, 'id' ) ) );
			\Insiders\Collections\Engine\Outbox::enqueue( 'wati_owner', 'customer', Http::int( $q, 'id' ), 'owner:' . Http::int( $q, 'id' ) . ':' . $owner . ':' . time(), array( 'customer_id' => Http::int( $q, 'id' ), 'owner' => $owner ) );
			Audit::log( 'customer.conversation_owner', 'customer', Http::int( $q, 'id' ), null, array( 'owner' => $owner ), Http::str( $q, 'note' ) );
			return array( 'ok' => true );
		}, true );

		// Cases.
		$r( 'POST', '/cases', 'icol_create_draft', static fn( $q ) => Cases::create_draft( (array) $q->get_json_params() ), true );
		$r( 'POST', "/cases/$id/items", 'icol_create_draft', static fn( $q ) => array( 'debt_item_id' => Cases::add_item( self::case_id( $q ), (array) $q->get_json_params(), Http::str( $q, 'basis' ) ) ), true );
		$r( 'POST', "/cases/$id/approve", 'icol_approve_debt', static fn( $q ) => array( 'approved' => Cases::approve_items( self::case_id( $q ), Http::str( $q, 'approval_basis' ) ), 'case' => Cases::response( self::case_id( $q ) ) ), true );
		$r( 'POST', "/cases/$id/preview", 'icol_view', static fn( $q ) => Messaging::preview( self::case_id( $q ) ), true );
		$r( 'POST', "/cases/$id/activate", 'icol_activate_case', static fn( $q ) => Cases::activate( self::case_id( $q ), Http::int( $q, 'version' ), Http::str( $q, 'balance_version' ) ), true );
		$r( 'POST', "/cases/$id/pause", 'icol_work_case', static fn( $q ) => Cases::pause( self::case_id( $q ), Http::int( $q, 'version' ), Http::str( $q, 'reason' ) ), true );
		$r( 'POST', "/cases/$id/resume", 'icol_work_case', static fn( $q ) => Cases::resume( self::case_id( $q ), Http::int( $q, 'version' ), Http::str( $q, 'reason' ) ), true );
		$r( 'POST', "/cases/$id/close", 'icol_work_case', static fn( $q ) => Cases::close( self::case_id( $q ), Http::int( $q, 'version' ), Http::str( $q, 'reason' ) ), true );
		$r( 'POST', "/cases/$id/owner", 'icol_work_case', static fn( $q ) => Cases::set_owner( self::case_id( $q ), Http::int( $q, 'version' ), Http::int( $q, 'owner_id' ) ?: null, Http::str( $q, 'reason' ) ), true );
		$r( 'POST', "/cases/$id/handover", 'icol_create_draft', static fn( $q ) => self::handover( $q ), true );
		$r( 'POST', "/cases/$id/notes", 'icol_work_case', static fn( $q ) => array( 'message_id' => Messaging::add_note( self::case_id( $q ), Http::str( $q, 'text' ) ) ), true );
		$r( 'POST', "/cases/$id/contact-log", 'icol_work_case', static fn( $q ) => array( 'message_id' => Messaging::log_contact( self::case_id( $q ), Http::str( $q, 'channel' ), Http::str( $q, 'summary' ), (bool) $q->get_param( 'proactive' ) ) ), true );
		$r( 'POST', "/cases/$id/promises", 'icol_work_case', static fn( $q ) => self::promise( $q ), true );
		$r( 'POST', "/cases/$id/reviews", 'icol_work_case', static fn( $q ) => self::review( $q ), true );
		$r( 'POST', "/cases/$id/payment-requests", 'icol_work_case', static fn( $q ) => self::payment_request( $q ), true );
		$r( 'POST', "/cases/$id/adjustments", 'icol_adjust', static fn( $q ) => array( 'adjustment_id' => Ledger::adjust( self::item_in_case( $q ), Http::str( $q, 'type' ), (int) Money::parse( Http::str( $q, 'amount' ) ), Http::str( $q, 'reason' ), Http::str( $q, 'evidence_ref' ) ), 'case' => self::after_adjust( $q ) ), true );
		$r( 'POST', "/cases/$id/resolve-dispute", 'icol_approve_debt', static fn( $q ) => self::resolve_flag( $q, 'dispute_open', 'dispute' ), true );
		$r( 'POST', "/cases/$id/resolve-account-claim", 'icol_approve_debt', static fn( $q ) => self::resolve_flag( $q, 'claims_account_opened', 'account_opened_claim' ), true );
		$r( 'POST', "/promises/$id/approve", 'icol_work_case', static function ( $q ) { Promises::approve( Http::int( $q, 'id' ), Http::str( $q, 'note' ) ); return array( 'ok' => true ); }, true );
		$r( 'POST', "/promises/$id/reject", 'icol_work_case', static function ( $q ) { Promises::reject( Http::int( $q, 'id' ), Http::str( $q, 'note' ) ); return array( 'ok' => true ); }, true );

		// Payments.
		$r( 'POST', '/payments/manual-verifications', 'icol_verify_payment', static fn( $q ) => Payments::manual_verification( (array) $q->get_json_params() ), true );
		$r( 'POST', "/payments/$id/allocate", 'icol_verify_payment', static fn( $q ) => array( 'allocation_id' => Matching::allocate_payment( Http::int( $q, 'id' ), Http::int( $q, 'debt_item_id' ), (int) Money::parse( Http::str( $q, 'amount' ) ), Http::str( $q, 'note' ) ) ), true );
		$r( 'POST', "/payments/$id/reverse", 'icol_adjust', static function ( $q ) { Payments::reverse( Http::int( $q, 'id' ), 'chargeback' === Http::str( $q, 'kind' ) ? 'chargeback' : 'refund', Http::str( $q, 'reason' ) ); return array( 'ok' => true ); }, true );

		// Card tasks.
		$r( 'POST', "/card-update-tasks/$id/confirm", 'icol_card_confirm', static function ( $q ) { CardTasks::confirm( Http::int( $q, 'id' ), (array) $q->get_json_params() ); return array( 'ok' => true ); }, true );
		$r( 'POST', "/card-update-tasks/$id/verify", 'icol_card_confirm', static fn( $q ) => CardTasks::verify_with_provider( Http::int( $q, 'id' ) ), true );
		$r( 'POST', "/card-update-tasks/$id/reveal", 'icol_reveal_token', static fn( $q ) => CardTasks::reveal( Http::int( $q, 'id' ) ), true );
		$r( 'POST', '/reauth', 'icol_reveal_token', static fn( $q ) => self::reauth( $q ), true );

		// Exceptions, matching, tasks, candidates.
		$r( 'POST', "/exceptions/$id/resolve", 'icol_resolve_exception', static function ( $q ) { Exceptions::resolve( Http::int( $q, 'id' ), Http::str( $q, 'resolution' ) ); return array( 'ok' => true ); }, true );
		$r( 'POST', "/attempts/$id/match", 'icol_approve_debt', static fn( $q ) => array( 'debt_item_id' => Matching::attempt_to_item( Http::int( $q, 'id' ), (array) $q->get_json_params() ) ), true );
		$r( 'POST', '/sto-mappings', 'icol_approve_debt', static fn( $q ) => array( 'recurring_order_id' => Matching::map_sto( (array) $q->get_json_params() ) ), true );
		$r( 'POST', "/tasks/$id/complete", 'icol_work_case', static function ( $q ) { Tasks::complete( Http::int( $q, 'id' ), Http::str( $q, 'note' ) ); return array( 'ok' => true ); }, true );
		$r( 'POST', "/candidates/$id/draft", 'icol_create_draft', static fn( $q ) => Matching::candidate_to_draft( Http::int( $q, 'id' ), (array) $q->get_json_params() ), true );
		$r( 'POST', "/candidates/$id/dismiss", 'icol_create_draft', static function ( $q ) { Matching::dismiss_candidate( Http::int( $q, 'id' ), Http::str( $q, 'note' ) ); return array( 'ok' => true ); }, true );
		$r( 'POST', "/inbox/$id/replay", 'icol_admin', static fn( $q ) => Inbox::replay( Http::int( $q, 'id' ) ), true );

		// Admin.
		$r( 'POST', '/settings', 'icol_admin', static fn( $q ) => self::save_settings( $q ), true );
		$r( 'POST', '/kill-switch', 'icol_work_case', static fn( $q ) => self::kill_switch( $q ), true );
		$r( 'POST', '/policy/draft', 'icol_admin', static fn( $q ) => array( 'version' => Policy::save_draft( (array) $q->get_param( 'config' ), Http::str( $q, 'note' ) ) ), true );
		$r( 'POST', '/policy/(?P<version>\d+)/approve', 'icol_admin', static function ( $q ) { Policy::approve( Http::int( $q, 'version' ) ); return array( 'ok' => true ); }, true );
		$r( 'POST', '/templates/(?P<key>[a-z_]+)', 'icol_admin', static fn( $q ) => Templates::save( Http::str( $q, 'key' ), (array) $q->get_json_params() ), true );
		$r( 'POST', "/users/$id/role", 'icol_admin', static fn( $q ) => self::set_role( $q ), true );
		$r( 'POST', '/integrations/(?P<name>[a-z]+)/unsuspend', 'icol_admin', static function ( $q ) { Runner::unsuspend( Http::str( $q, 'name' ) ); return array( 'ok' => true ); }, true );
		$r( 'POST', '/tools/tick', 'icol_admin', static fn() => Runner::tick( 'manual' ), true );
		$r( 'POST', '/holidays/regenerate', 'icol_admin', static fn() => \Insiders\Collections\Domain\Calendar::israeli_holidays( (int) gmdate( 'Y' ), (int) gmdate( 'Y' ) + 1 ), true );

		Webhooks::register();
	}

	/** Every case-scoped route checks visibility (a rep sees own cases + the general queue). */
	private static function case_id( \WP_REST_Request $q ): int {
		$case = Views::load_case( Http::int( $q, 'id' ) );
		if ( ! current_user_can( 'icol_work_case' ) && ! current_user_can( 'icol_view_all' ) ) {
			throw new DomainError( 'forbidden', 'אין הרשאה', 403 );
		}
		return (int) $case['id'];
	}

	private static function item_in_case( \WP_REST_Request $q ): int {
		$case_id = self::case_id( $q );
		$item    = (int) $q->get_param( 'debt_item_id' );
		if ( ! Db::value( 'SELECT id FROM ' . Db::t( 'debt_items' ) . ' WHERE id = %d AND case_id = %d', $item, $case_id ) ) {
			throw new DomainError( 'not_found', 'פריט החוב לא שייך לתיק', 404 );
		}
		return $item;
	}

	private static function after_adjust( \WP_REST_Request $q ): array {
		Cases::maybe_close( (int) $q->get_param( 'id' ) );
		return Cases::response( (int) $q->get_param( 'id' ) );
	}

	private static function handover( \WP_REST_Request $q ): array {
		$case_id = self::case_id( $q );
		$case    = Workflow::get( $case_id );
		if ( ! in_array( $case['workflow_state'], array( 'draft', 'paused', 'human_review' ), true ) ) {
			throw new DomainError( 'invalid_transition', 'אפשר להעביר להמשך טיפול רק תיק בטיוטה, מושהה או אצל נציג', 422 );
		}
		$p = (array) $q->get_json_params();
		Cases::import_handover( $case_id, $p );
		Workflow::touch( $case_id, array( 'entry_mode' => 'handover' ), 'case.handover', 'הזנת היסטוריה להמשך טיפול', Http::int( $q, 'version' ) ?: null );
		return Cases::response( $case_id );
	}

	private static function promise( \WP_REST_Request $q ): array {
		$case_id = self::case_id( $q );
		$date    = Http::str( $q, 'date' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			throw new DomainError( 'validation_failed', 'נדרש תאריך מוחלט', 400, array( 'date' => 'YYYY-MM-DD' ) );
		}
		$amount = '' !== Http::str( $q, 'amount' ) ? Money::parse( Http::str( $q, 'amount' ) ) : null;
		$id     = Promises::request( $case_id, $date, $amount, 'user', null, Http::str( $q, 'note' ) );
		if ( (bool) $q->get_param( 'approve' ) ) {
			Promises::approve( $id, Http::str( $q, 'note' ) );
		}
		return array( 'promise_id' => $id, 'case' => Cases::response( $case_id ) );
	}

	private static function review( \WP_REST_Request $q ): array {
		$case_id = self::case_id( $q );
		$type    = Http::str( $q, 'type' );
		$note    = Http::str( $q, 'note' );
		if ( 'dispute' === $type ) {
			Db::update( 'cases', array( 'dispute_open' => 1 ), array( 'id' => $case_id ) );
			$c = Workflow::get( $case_id );
			if ( Workflow::can( $c['workflow_state'], 'human_review' ) ) {
				Workflow::transition( $case_id, 'human_review', 'נפתחה מחלוקת: ' . $note, null, array(), 'manual' );
			}
			Tasks::open( 'dispute:' . $case_id, 'dispute', array( 'case_id' => $case_id, 'reason' => $note, 'priority' => 'high' ) );
		} elseif ( 'payment_verification' === $type ) {
			$c = Workflow::get( $case_id );
			if ( Workflow::can( $c['workflow_state'], 'payment_verification' ) ) {
				Workflow::transition( $case_id, 'payment_verification', 'נפתח אימות תשלום: ' . $note, null, array(), 'manual' );
			}
			Tasks::open( 'verify_payment:' . $case_id . ':' . time(), 'verify_payment', array( 'case_id' => $case_id, 'reason' => $note ) );
		} else {
			throw new DomainError( 'validation_failed', 'סוג בירור לא מוכר', 400 );
		}
		return Cases::response( $case_id );
	}

	/** Closing a dispute / account claim is a decision with a reason: continue, or close the debt via adjustment. */
	private static function resolve_flag( \WP_REST_Request $q, string $flag, string $task_prefix ): array {
		$case_id = self::case_id( $q );
		$note    = Http::str( $q, 'note' );
		if ( '' === $note ) {
			throw new DomainError( 'validation_failed', 'יש לתעד את ההחלטה', 400, array( 'note' => 'חובה' ) );
		}
		Db::update( 'cases', array( $flag => 0 ), array( 'id' => $case_id ) );
		Tasks::close_by_key( $task_prefix . ':' . $case_id, $note );
		Audit::log( 'case.' . $flag . '_resolved', 'case', $case_id, null, array( 'outcome' => Http::str( $q, 'outcome' ) ), $note );
		return Cases::response( $case_id );
	}

	private static function payment_request( \WP_REST_Request $q ): array {
		$case_id = self::case_id( $q );
		$case    = Workflow::get( $case_id );
		$ok      = PaymentRequests::link_allowed( $case );
		if ( ! $ok['allowed'] ) {
			throw new DomainError( 'route_blocked', $ok['reason'], 422 );
		}
		$url = PaymentRequests::local_link( $case_id );
		if ( ! $url ) {
			throw new DomainError( 'no_balance', 'אין יתרה לתשלום', 422 );
		}
		return array( 'url' => $url );
	}

	private static function reauth( \WP_REST_Request $q ): array {
		$user = wp_get_current_user();
		if ( ! wp_check_password( (string) $q->get_param( 'password' ), $user->user_pass, $user->ID ) ) {
			Audit::log( 'token.reauth_failed', 'user', $user->ID, null, null, '' );
			throw new DomainError( 'reauth_failed', 'הסיסמה שגויה', 401 );
		}
		update_user_meta( $user->ID, 'icol_reauth_until', time() + 10 * MINUTE_IN_SECONDS );
		Audit::log( 'token.reauth', 'user', $user->ID, null, null, '' );
		return array( 'until' => time() + 10 * MINUTE_IN_SECONDS );
	}

	private static function kill_switch( \WP_REST_Request $q ): array {
		$on = (bool) $q->get_param( 'on' );
		if ( ! $on && ! current_user_can( 'icol_admin' ) ) {
			// Anyone working cases may stop everything; only an admin may start sending again.
			throw new DomainError( 'forbidden', 'רק מנהל יכול לבטל את מתג העצירה', 403 );
		}
		// Queued messages are voided at delivery time while the switch is on (Wati\Client::deliver_message);
		// turning it off does not resend them — the scheduler re-plans from the current state (§20).
		Settings::set( array( 'kill_switch' => $on ? 1 : 0 ) );
		Audit::log( $on ? 'kill_switch.on' : 'kill_switch.off', 'system', 0, null, null, Http::str( $q, 'reason' ) );
		return array( 'kill_switch' => $on );
	}

	private static function save_settings( \WP_REST_Request $q ): array {
		$in      = (array) $q->get_param( 'settings' );
		$allowed = array_keys( \Insiders\Collections\Support\Settings::DEFAULTS );
		$extra   = array( 'tranzila_hmac_order', 'tranzila_report_amount_unit', 'tranzila_pr_terminal', 'tranzila_pr_extra', 'tranzila_code_map', 'revenue_meta_phone' );
		$clean   = array();
		foreach ( $in as $k => $v ) {
			if ( in_array( $k, array_merge( $allowed, $extra ), true ) && ! in_array( $k, array( 'kill_switch' ), true ) ) {
				$clean[ $k ] = is_array( $v ) ? wp_json_encode( $v ) : sanitize_textarea_field( (string) $v );
			}
		}
		$before = array_intersect_key( Settings::all(), $clean );
		Settings::set( $clean );
		foreach ( (array) $q->get_param( 'secrets' ) as $name => $value ) {
			if ( in_array( $name, array( 'tranzila_app_key', 'tranzila_secret', 'wati_token', 'pipedrive_token', 'anthropic_api_key' ), true ) && '' !== (string) $value ) {
				if ( ! Settings::set_secret( $name, (string) $value ) ) {
					throw new DomainError( 'no_encryption', 'לא ניתן לשמור סוד בלי ICOL_ENCRYPTION_KEY ב-wp-config.php', 422 );
				}
				Audit::log( 'secret.set', 'settings', 0, null, array( 'name' => $name ), '' );
			}
		}
		Audit::log( 'settings.save', 'settings', 0, $before, $clean, '' );
		return Views::settings();
	}

	private static function set_role( \WP_REST_Request $q ): array {
		$uid  = Http::int( $q, 'id' );
		$role = Http::str( $q, 'role' );
		if ( '' !== $role && ! isset( Capabilities::ROLES[ $role ] ) ) {
			throw new DomainError( 'validation_failed', 'תפקיד לא מוכר', 400 );
		}
		$before = get_user_meta( $uid, 'icol_role', true );
		'' === $role ? delete_user_meta( $uid, 'icol_role' ) : update_user_meta( $uid, 'icol_role', $role );
		if ( '' !== Http::str( $q, 'pipedrive_user_id' ) ) {
			update_user_meta( $uid, 'icol_pipedrive_user_id', (int) Http::str( $q, 'pipedrive_user_id' ) );
		}
		Audit::log( 'user.role', 'user', $uid, array( 'role' => $before ), array( 'role' => $role ), '' );
		return array( 'ok' => true );
	}

	private static function staff(): array {
		$out = array();
		foreach ( get_users( array( 'number' => 300, 'meta_key' => 'icol_role', 'meta_compare' => 'EXISTS' ) ) as $u ) {
			$out[] = array( 'id' => $u->ID, 'name' => $u->display_name, 'role' => (string) get_user_meta( $u->ID, 'icol_role', true ) );
		}
		foreach ( get_users( array( 'role' => 'administrator', 'number' => 50 ) ) as $u ) {
			if ( ! in_array( $u->ID, array_column( $out, 'id' ), true ) ) {
				$out[] = array( 'id' => $u->ID, 'name' => $u->display_name, 'role' => 'admin' );
			}
		}
		return $out;
	}

	/** Export is permissioned and audited (§21). Phone numbers masked unless admin. */
	private static function export( \WP_REST_Request $q ): array {
		$q->set_param( 'per_page', 100 );
		$all  = array();
		$page = 1;
		do {
			$q->set_param( 'page', $page );
			$res = Views::cases( $q );
			$all = array_merge( $all, $res['rows'] );
			++$page;
		} while ( count( $all ) < $res['total'] && $page < 200 );
		if ( ! current_user_can( 'icol_admin' ) ) {
			foreach ( $all as &$row ) {
				$row['phone'] = \Insiders\Collections\Support\Phone::mask( $row['phone'] );
			}
		}
		Audit::log( 'export.cases', 'system', 0, null, array( 'rows' => count( $all ) ), '' );
		return $all;
	}
}

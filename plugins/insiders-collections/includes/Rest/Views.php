<?php
namespace Insiders\Collections\Rest;

use Insiders\Collections\Auth\Capabilities;
use Insiders\Collections\Domain\Cases;
use Insiders\Collections\Domain\Customers;
use Insiders\Collections\Domain\DomainError;
use Insiders\Collections\Domain\Exceptions;
use Insiders\Collections\Domain\Ledger;
use Insiders\Collections\Domain\PaymentRequests;
use Insiders\Collections\Domain\Policy;
use Insiders\Collections\Domain\SendGuard;
use Insiders\Collections\Domain\Tasks;
use Insiders\Collections\Domain\Templates;
use Insiders\Collections\Domain\Workflow;
use Insiders\Collections\Engine\Runner;
use Insiders\Collections\Integrations\Ai\Classifier;
use Insiders\Collections\Integrations\Tranzila\Client as Tranzila;
use Insiders\Collections\Integrations\Tranzila\ResponseCodes;
use Insiders\Collections\Integrations\Wati\Client as Wati;
use Insiders\Collections\Schema;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Crypto;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Money;
use Insiders\Collections\Support\Phone;
use Insiders\Collections\Support\Settings;

defined( 'ABSPATH' ) || exit;

/** Read models for the admin screens. Every list is server-filtered by visibility. */
final class Views {

	private static function user_name( $id ): string {
		if ( ! $id ) {
			return '';
		}
		$u = get_userdata( (int) $id );
		return $u ? $u->display_name : '#' . $id;
	}

	private static function owner_clause( array &$args ): string {
		if ( current_user_can( 'icol_view_all' ) || 'viewer' === Capabilities::role_of( get_current_user_id() ) ) {
			return '';
		}
		$args[] = get_current_user_id();
		return ' AND (c.owner_id IS NULL OR c.owner_id = %d)';
	}

	public static function dashboard(): array {
		$args  = array();
		$own   = self::owner_clause( $args );
		$one   = static fn( string $sql, array $a = array() ) => (int) Db::value( $sql, ...$a );
		$since = Clock::utc( Clock::now() - 30 * DAY_IN_SECONDS );
		$base  = 'FROM ' . Db::t( 'cases' ) . ' c WHERE 1=1' . $own;
		$sim   = (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'messages' ) . " WHERE delivery_state = 'simulated' AND created_at >= %s", Clock::utc( Clock::now() - 7 * DAY_IN_SECONDS ) );
		return array(
			'mode'        => array(
				'send_mode'    => SendGuard::mode(),
				'kill_switch'  => Settings::on( 'kill_switch' ),
				'display_only' => Settings::on( 'display_only' ),
				'policy_approved' => null !== Policy::current(),
				'simulated_last_7d' => $sim,
			),
			'kpis'        => array(
				'open_due_minor'      => (int) Db::value( 'SELECT COALESCE(SUM(d.cached_balance_minor),0) FROM ' . Db::t( 'debt_items' ) . ' d JOIN ' . Db::t( 'cases' ) . " c ON c.id = d.case_id WHERE d.finance_state IN ('open','partially_paid') AND d.currency = 'ILS'" . $own, ...$args ),
				'matched_30d_minor'   => (int) Db::value( 'SELECT COALESCE(SUM(a.amount_minor),0) FROM ' . Db::t( 'allocations' ) . ' a JOIN ' . Db::t( 'debt_items' ) . ' d ON d.id = a.debt_item_id JOIN ' . Db::t( 'cases' ) . " c ON c.id = d.case_id WHERE a.created_at >= %s AND d.currency = 'ILS'" . $own, ...array_merge( array( $since ), $args ) ),
				'waiting_rep'         => $one( "SELECT COUNT(*) {$base} AND c.workflow_state = 'human_review'", $args ),
				'payment_verification' => $one( "SELECT COUNT(*) {$base} AND c.workflow_state = 'payment_verification'", $args ),
				'promises_today'      => (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'promises' ) . " p WHERE p.state = 'approved' AND p.promised_at = %s", Clock::today() ),
				'card_tasks_open'     => (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'card_update_tasks' ) . " WHERE state IN ('open','in_progress','needs_clarification')" ),
				'exceptions_open'     => (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'exceptions' ) . " WHERE status = 'open'" ),
				'drafts'              => $one( "SELECT COUNT(*) {$base} AND c.workflow_state = 'draft'", $args ),
				'candidates_new'      => (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'program_candidates' ) . " WHERE status = 'new' AND (source IS NULL OR source <> 'import')" ),
				'journey_active'      => (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'cases' ) . " WHERE phase = 'commitment' AND workflow_state <> 'closed'" ),
				'program_waiting'     => (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'cases' ) . ' c JOIN ' . Db::t( 'agreements' ) . " a ON a.id = c.agreement_id WHERE c.phase = 'commitment' AND c.workflow_state <> 'closed' AND (a.account_open_deadline < %s OR c.track = 'declined') AND NOT EXISTS (SELECT 1 FROM " . Db::t( 'debt_items' ) . ' d WHERE d.case_id = c.id AND d.approved_at IS NOT NULL)', Clock::today() ),
				'my_tasks'            => (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'tasks' ) . " WHERE status = 'open' AND (assigned_to = %d OR assigned_to IS NULL) AND type <> 'alert'", get_current_user_id() ),
			),
			'by_state'    => Db::rows( "SELECT c.workflow_state AS state, COUNT(*) AS n {$base} GROUP BY c.workflow_state", ...$args ),
		);
	}

	public static function cases( \WP_REST_Request $r ): array {
		$args  = array();
		$where = array( '1=1' );
		$own   = self::owner_clause( $args );
		foreach ( array( 'state' => 'c.workflow_state', 'source' => 'c.source_type' ) as $param => $col ) {
			$v = Http::str( $r, $param );
			if ( '' !== $v ) {
				$vals    = array_filter( explode( ',', $v ) );
				$where[] = $col . ' IN (' . Db::in( $vals, '%s' ) . ')';
				$args    = array_merge( $args, $vals );
			}
		}
		$owner = Http::str( $r, 'owner' );
		if ( 'me' === $owner ) {
			$where[] = 'c.owner_id = %d';
			$args[]  = get_current_user_id();
		} elseif ( 'none' === $owner ) {
			$where[] = 'c.owner_id IS NULL';
		} elseif ( ctype_digit( $owner ) ) {
			$where[] = 'c.owner_id = %d';
			$args[]  = (int) $owner;
		}
		if ( 'open' === Http::str( $r, 'scope' ) || '' === Http::str( $r, 'scope' ) ) {
			$where[] = "c.workflow_state <> 'closed'";
		}
		$q = Http::str( $r, 'q' );
		if ( '' !== $q ) {
			$phone   = Phone::e164( $q );
			$like    = '%' . $GLOBALS['wpdb']->esc_like( $q ) . '%';
			$where[] = '(u.full_name LIKE %s OR u.email LIKE %s' . ( $phone ? ' OR u.phone_e164 = %s' : '' ) . ' OR c.id = %d OR o.sto_id = %s OR EXISTS (SELECT 1 FROM ' . Db::t( 'charge_attempts' ) . ' ca JOIN ' . Db::t( 'debt_items' ) . ' di ON di.id = ca.debt_item_id WHERE di.case_id = c.id AND ca.transaction_index = %s))';
			$args    = array_merge( $args, array( $like, $like ), $phone ? array( $phone ) : array(), array( (int) ltrim( $q, '#' ), $q, $q ) );
		}
		$age = Http::str( $r, 'age' );
		$age_sql = '(SELECT DATEDIFF(%s, MIN(d.due_at)) FROM ' . Db::t( 'debt_items' ) . " d WHERE d.case_id = c.id AND d.finance_state IN ('open','partially_paid'))";
		$sort  = array( 'balance' => 'due_minor DESC', 'age' => 'age_days DESC', 'next' => 'c.next_action_at IS NULL, c.next_action_at ASC', 'updated' => 'c.updated_at DESC' )[ Http::str( $r, 'sort' ) ] ?? 'c.updated_at DESC';
		$page  = max( 1, Http::int( $r, 'page' ) );
		$per   = min( 100, max( 10, Http::int( $r, 'per_page' ) ?: 25 ) );
		$sql   = 'SELECT c.*, u.full_name, u.phone_e164, o.sto_id, o.terminal, o.card_status,
				(SELECT COALESCE(SUM(d.cached_balance_minor),0) FROM ' . Db::t( 'debt_items' ) . " d WHERE d.case_id = c.id AND d.finance_state IN ('open','partially_paid')) AS due_minor,
				{$age_sql} AS age_days,
				(SELECT m.occurred_at FROM " . Db::t( 'messages' ) . " m WHERE m.customer_id = c.customer_id AND m.direction = 'out' AND m.channel <> 'internal_note' AND m.delivery_state NOT IN ('draft','cancelled') ORDER BY m.occurred_at DESC LIMIT 1) AS last_out,
				(SELECT m.body FROM " . Db::t( 'messages' ) . " m WHERE m.customer_id = c.customer_id AND m.direction = 'in' ORDER BY m.occurred_at DESC LIMIT 1) AS last_in_text,
				(SELECT m.occurred_at FROM " . Db::t( 'messages' ) . " m WHERE m.customer_id = c.customer_id AND m.direction = 'in' ORDER BY m.occurred_at DESC LIMIT 1) AS last_in
			FROM " . Db::t( 'cases' ) . ' c JOIN ' . Db::t( 'customers' ) . ' u ON u.id = c.customer_id LEFT JOIN ' . Db::t( 'recurring_orders' ) . ' o ON o.id = c.recurring_order_id
			WHERE ' . implode( ' AND ', $where ) . $own;
		$all_args = array_merge( array( Clock::today() ), $args );
		if ( '' !== $age ) {
			[ $min, $max ] = array_map( 'intval', array_pad( explode( '-', $age ), 2, 9999 ) );
			$sql       = 'SELECT * FROM (' . $sql . ') x WHERE x.age_days BETWEEN %d AND %d';
			$all_args  = array_merge( $all_args, array( $min, $max ) );
			$sort      = str_replace( 'c.', 'x.', $sort );
		}
		$total = (int) Db::value( 'SELECT COUNT(*) FROM (' . $sql . ') t', ...$all_args );
		$rows  = Db::rows( $sql . ' ORDER BY ' . $sort . ' LIMIT %d OFFSET %d', ...array_merge( $all_args, array( $per, ( $page - 1 ) * $per ) ) );
		return array(
			'total' => $total,
			'page'  => $page,
			'per_page' => $per,
			'rows'  => array_map(
				static fn( $c ) => array(
					'id'           => (int) $c['id'],
					'customer'     => $c['full_name'],
					'phone'        => $c['phone_e164'],
					'source_type'  => $c['source_type'],
					'source_label' => Cases::SOURCES[ $c['source_type'] ] ?? $c['source_type'],
					'entry_mode'   => $c['entry_mode'],
					'due_minor'    => (int) $c['due_minor'],
					'currency'     => $c['currency'],
					'age_days'     => null === $c['age_days'] ? null : (int) $c['age_days'],
					'state'        => $c['workflow_state'],
					'state_label'  => Workflow::label( $c['workflow_state'] ),
					'state_reason' => $c['state_reason'],
					'last_out'     => Clock::display( $c['last_out'] ),
					'last_in'      => Clock::display( $c['last_in'] ),
					'last_in_text' => mb_substr( (string) $c['last_in_text'], 0, 80 ),
					'next_action'  => $c['next_action_type'],
					'next_action_at' => Clock::display( $c['next_action_at'] ),
					'owner'        => self::user_name( $c['owner_id'] ),
					'card_status'  => $c['card_status'],
					'version'      => (int) $c['version'],
				),
				$rows
			),
		);
	}

	public static function load_case( int $id ): array {
		$case = Workflow::get( $id );
		if ( ! $case || ! Capabilities::can_see_case( $case ) ) {
			throw new DomainError( 'not_found', 'התיק לא נמצא', 404 );
		}
		return $case;
	}

	public static function case_card( int $id ): array {
		$case     = self::load_case( $id );
		$customer = Customers::get( (int) $case['customer_id'] );
		$summary  = Ledger::case_summary( $id );
		foreach ( $summary['items'] as &$it ) {
			$it['attempts']    = Db::rows( 'SELECT id, terminal, transaction_index, response_code, failure_class, amount_minor, occurred_at, verification_state FROM ' . Db::t( 'charge_attempts' ) . ' WHERE debt_item_id = %d ORDER BY occurred_at', (int) $it['id'] );
			foreach ( $it['attempts'] as &$a ) {
				$a['failure_label'] = ResponseCodes::LABELS[ $a['failure_class'] ] ?? '';
				$a['occurred_local'] = Clock::display( $a['occurred_at'] );
			}
			$it['allocations'] = Db::rows( 'SELECT a.id, a.amount_minor, a.reversal_of, a.reason, a.created_at, p.provider, p.terminal, p.transaction_id, p.payment_method FROM ' . Db::t( 'allocations' ) . ' a JOIN ' . Db::t( 'payments' ) . ' p ON p.id = a.payment_id WHERE a.debt_item_id = %d ORDER BY a.id', (int) $it['id'] );
			$it['adjustments'] = Db::rows( 'SELECT * FROM ' . Db::t( 'adjustments' ) . ' WHERE debt_item_id = %d ORDER BY id', (int) $it['id'] );
			$it['approved_by_name'] = $it['approved_at'] ? ( $it['approved_by'] ? self::user_name( $it['approved_by'] ) : 'מערכת (כשל מאומת)' ) : '';
		}
		unset( $it, $a );
		$order    = $case['recurring_order_id'] ? Db::row( 'SELECT * FROM ' . Db::t( 'recurring_orders' ) . ' WHERE id = %d', (int) $case['recurring_order_id'] ) : null;
		$agr      = $case['agreement_id'] ? Db::row( 'SELECT * FROM ' . Db::t( 'agreements' ) . ' WHERE id = %d', (int) $case['agreement_id'] ) : null;
		$timeline = array();
		foreach ( Db::rows( 'SELECT * FROM ' . Db::t( 'messages' ) . ' WHERE customer_id = %d ORDER BY occurred_at, id', (int) $case['customer_id'] ) as $m ) {
			$timeline[] = array( 'at' => $m['occurred_at'], 'local' => Clock::display( $m['occurred_at'] ), 'type' => $m['is_internal'] ? ( 'ai_suggestion' === $m['kind'] ? 'ai' : 'note' ) : ( 'in' === $m['direction'] ? 'in' : 'out' ), 'kind' => $m['kind'], 'body' => $m['body'], 'state' => $m['delivery_state'], 'intent' => $m['intent'], 'template' => $m['template_key'], 'author' => 'user' === $m['author_type'] ? self::user_name( $m['author_id'] ) : $m['author_type'], 'counts' => (int) $m['counts_toward_quota'] );
		}
		foreach ( Db::rows( 'SELECT * FROM ' . Db::t( 'imported_history' ) . ' WHERE case_id = %d', $id ) as $h ) {
			$timeline[] = array( 'at' => $h['original_at'] ?: $h['created_at'], 'local' => Clock::display( $h['original_at'] ?: $h['created_at'] ), 'type' => 'imported', 'kind' => $h['entry_type'], 'body' => $h['content'], 'state' => 'imported', 'author' => $h['author_label'] . ' · הוזן ע״י ' . self::user_name( $h['entered_by'] ), 'counts' => (int) $h['counts_toward_quota'], 'amount_minor' => $h['amount_minor'] );
		}
		foreach ( Db::rows( 'SELECT * FROM ' . Db::t( 'audit_log' ) . " WHERE entity_type IN ('case') AND entity_id = %d AND action = 'case.transition'", $id ) as $l ) {
			$after = json_decode( (string) $l['after_json'], true );
			$timeline[] = array( 'at' => $l['occurred_at'], 'local' => Clock::display( $l['occurred_at'] ), 'type' => 'system', 'kind' => 'transition', 'body' => Workflow::label( (string) ( $after['state'] ?? '' ) ) . ' · ' . $l['reason'], 'author' => 'user' === $l['actor_type'] ? self::user_name( $l['actor_id'] ) : 'מערכת' );
		}
		usort( $timeline, static fn( $a, $b ) => strcmp( (string) $a['at'], (string) $b['at'] ) );
		$card_tasks = $order ? Db::rows( 'SELECT * FROM ' . Db::t( 'card_update_tasks' ) . ' WHERE recurring_order_id = %d ORDER BY id DESC', (int) $order['id'] ) : array();
		return array(
			'case'       => array_merge( $case, array( 'state_label' => Workflow::label( $case['workflow_state'] ), 'source_label' => Cases::SOURCES[ $case['source_type'] ] ?? '', 'owner_name' => self::user_name( $case['owner_id'] ), 'activated_local' => Clock::display( $case['activated_at'] ), 'next_action_local' => Clock::display( $case['next_action_at'] ) ) ),
			'customer'   => array(
				'id'              => (int) $customer['id'],
				'full_name'       => $customer['full_name'],
				'first_name'      => $customer['first_name'],
				'first_name_reliable' => (int) $customer['first_name_reliable'],
				'phone'           => $customer['phone_e164'],
				'email'           => $customer['email'],
				'contact_status'  => $customer['contact_status'],
				'shares_phone'    => Customers::shares_phone( $customer ),
				'permission'      => Customers::permission( (int) $customer['id'], 'whatsapp' ),
				'conversation_owner' => $customer['conversation_owner'],
				'wp_user_id'      => $customer['wp_user_id'],
				'in_service_window' => SendGuard::in_service_window( (int) $customer['id'] ),
			),
			'finance'    => $summary,
			'future'     => $order ? array( 'future_installments' => $order['future_installments'], 'future_balance_minor' => $order['future_balance_minor'], 'next_due_at' => $order['next_due_at'] ) : null,
			'order'      => $order,
			'agreement'  => $agr,
			'promises'   => Db::rows( 'SELECT * FROM ' . Db::t( 'promises' ) . ' WHERE case_id = %d ORDER BY id DESC', $id ),
			'tasks'      => Db::rows( 'SELECT * FROM ' . Db::t( 'tasks' ) . ' WHERE case_id = %d ORDER BY status, due_at', $id ),
			'card_tasks' => $card_tasks,
			'requests'   => Db::rows( 'SELECT id, amount_minor, currency, status, expires_at, opened_at, provider_pr_id, created_at FROM ' . Db::t( 'payment_requests' ) . ' WHERE case_id = %d ORDER BY id DESC', $id ),
			'timeline'   => $timeline,
			'audit'      => array_map( static fn( $l ) => array( 'local' => Clock::display( $l['occurred_at'] ), 'action' => $l['action'], 'actor' => 'user' === $l['actor_type'] ? self::user_name( $l['actor_id'] ) : 'מערכת', 'reason' => $l['reason'], 'entity' => $l['entity_type'] . '#' . $l['entity_id'] ), Db::rows( 'SELECT * FROM ' . Db::t( 'audit_log' ) . " WHERE (entity_type = 'case' AND entity_id = %d) OR (entity_type = 'debt_item' AND entity_id IN (SELECT id FROM " . Db::t( 'debt_items' ) . ' WHERE case_id = %d)) ORDER BY id DESC LIMIT 200', $id, $id ) ),
			'actions'    => self::actions( $case, $summary ),
			'journey'    => 'commitment' === $case['phase'] ? array(
				'track'       => $case['track'],
				'track_label' => \Insiders\Collections\Domain\Journey::TRACKS[ $case['track'] ] ?? '',
				'last_step'   => $case['track_step'] ? ( \Insiders\Collections\Domain\Journey::STEP_LABELS[ $case['track_step'] ] ?? $case['track_step'] ) : '',
				'declined_at' => Clock::display( $case['declined_at'] ),
				'declined_source' => $case['declined_source'],
				'amount_text' => $agr ? \Insiders\Collections\Domain\Pricing::amount_text( $agr ) : '',
			) : null,
			'pending'    => Db::rows( 'SELECT id, type, run_at, state FROM ' . Db::t( 'scheduled_actions' ) . " WHERE case_id = %d AND state IN ('pending','claimed') ORDER BY run_at", $id ),
		);
	}

	/** Every action with allowed/reason, so the UI explains instead of hiding (§12). */
	public static function actions( array $case, array $summary ): array {
		$s   = $case['workflow_state'];
		$can = static fn( string $cap ) => current_user_can( $cap );
		$a   = static fn( bool $ok, string $why ) => array( 'allowed' => $ok, 'reason' => $ok ? '' : $why );
		$open = in_array( $s, array( 'closed' ), true ) ? false : true;
		return array(
			'approve'   => $a( $can( 'icol_approve_debt' ) && $summary['draft_minor'] > 0, ! $can( 'icol_approve_debt' ) ? 'אישור חוב מותר לאחראי גבייה או מנהל' : 'אין פריטים שממתינים לאישור' ),
			'activate'  => $a( $can( 'icol_activate_case' ) && 'draft' === $s, ! $can( 'icol_activate_case' ) ? 'אין הרשאה להפעלה' : 'רק טיוטה ניתנת להפעלה' ),
			'preview'   => $a( true, '' ),
			'pause'     => $a( $can( 'icol_work_case' ) && Workflow::can( $s, 'paused' ) && 'paused' !== $s, 'לא ניתן להשהות במצב הנוכחי' ),
			'resume'    => $a( $can( 'icol_work_case' ) && in_array( $s, array( 'paused', 'human_review', 'payment_verification', 'promise_hold', 'promise_pending' ), true ) && ! (int) $case['dispute_open'] && ! (int) $case['claims_account_opened'], (int) $case['dispute_open'] ? 'יש מחלוקת פתוחה' : ( (int) $case['claims_account_opened'] ? 'טענת פתיחת חשבון ממתינה להחלטה' : 'חידוש אפשרי ממצב מושהה או אצל נציג' ) ),
			'promise'   => $a( $can( 'icol_work_case' ) && $open && 'draft' !== $s, 'לא זמין במצב הנוכחי' ),
			'dispute'   => $a( $can( 'icol_work_case' ) && $open, 'התיק סגור' ),
			'verify_payment' => $a( $can( 'icol_verify_payment' ), 'אימות תקבול מותר לאחראי גבייה או מנהל' ),
			'adjust'    => $a( $can( 'icol_adjust' ), 'התאמה כספית מותרת למנהל בלבד' ),
			'payment_request' => $a( PaymentRequests::link_allowed( $case )['allowed'] && $summary['due_balance_minor'] > 0, PaymentRequests::link_allowed( $case )['reason'] ?: 'אין יתרה לתשלום' ),
			'close'     => $a( $can( 'icol_work_case' ) && 'closed' !== $s && 0 === count( array_filter( $summary['items'], static fn( $i ) => ! in_array( $i['finance_state'], array( 'settled', 'cancelled' ), true ) ) ), 'אפשר לסגור רק כשכל הפריטים הוסדרו או בוטלו' ),
			'resolve_dispute' => $a( $can( 'icol_approve_debt' ) && (int) $case['dispute_open'], 'אין מחלוקת פתוחה' ),
			'resolve_account_claim' => $a( $can( 'icol_approve_debt' ) && (int) $case['claims_account_opened'], 'אין טענת פתיחת חשבון' ),
			'decline'   => $a( $can( 'icol_work_case' ) && 'commitment' === $case['phase'] && 'declined' !== $case['track'] && 'closed' !== $s, 'זמין רק לתלמיד בליווי לפני המועד' ),
			'credit'    => $a( $can( 'icol_verify_payment' ) && 'non_open_charge' === $case['source_type'] && $summary['allocated_minor'] > 0, 'זמין רק לתיק של התוכנית שיש בו תשלום' ),
		);
	}

	public static function exceptions( \WP_REST_Request $r ): array {
		$status = Http::str( $r, 'status' ) ?: 'open';
		$rows   = Db::rows( 'SELECT * FROM ' . Db::t( 'exceptions' ) . ' WHERE status = %s ORDER BY FIELD(severity, "critical","high","medium","low"), opened_at DESC LIMIT 300', $status );
		return array_map(
			static function ( $e ) {
				$e['type_label']   = Exceptions::LABELS[ $e['type'] ] ?? $e['type'];
				$e['opened_local'] = Clock::display( $e['opened_at'] );
				$e['owner_name']   = self::user_name( $e['owner_id'] );
				$e['details']      = json_decode( (string) $e['details'], true );
				if ( 'charge_attempt' === $e['entity_type'] ) {
					$e['attempt'] = Db::row( 'SELECT * FROM ' . Db::t( 'charge_attempts' ) . ' WHERE id = %d', (int) $e['entity_id'] );
				}
				if ( 'payment' === $e['entity_type'] ) {
					$e['payment'] = Db::row( 'SELECT id, provider, terminal, transaction_id, amount_minor, currency, customer_id FROM ' . Db::t( 'payments' ) . ' WHERE id = %d', (int) $e['entity_id'] );
					$e['payment']['unallocated_minor'] = Ledger::payment_unallocated( (int) $e['entity_id'] );
				}
				return $e;
			},
			$rows
		);
	}

	public static function card_tasks( \WP_REST_Request $r ): array {
		$state = Http::str( $r, 'state' );
		$where = '' !== $state ? $GLOBALS['wpdb']->prepare( 't.state = %s', $state ) : "t.state IN ('open','in_progress','needs_clarification')";
		$rows  = Db::rows(
			'SELECT t.*, o.sto_id, o.terminal, o.next_due_at, o.card_status, o.future_installments, u.full_name, u.id AS customer_id, p.amount_minor, p.transaction_id, p.terminal AS pay_terminal, p.card_last4, p.payment_method, p.has_reusable_token,
				(SELECT COUNT(*) FROM ' . Db::t( 'card_credentials' ) . ' cc WHERE cc.source_payment_id = t.source_payment_id) AS has_credential,
				(SELECT CONCAT(LPAD(cc.expiry_month,2,"0"),"/",cc.expiry_year) FROM ' . Db::t( 'card_credentials' ) . ' cc WHERE cc.source_payment_id = t.source_payment_id LIMIT 1) AS expiry
			FROM ' . Db::t( 'card_update_tasks' ) . ' t JOIN ' . Db::t( 'recurring_orders' ) . ' o ON o.id = t.recurring_order_id JOIN ' . Db::t( 'customers' ) . ' u ON u.id = o.customer_id JOIN ' . Db::t( 'payments' ) . ' p ON p.id = t.source_payment_id WHERE ' . $where . ' ORDER BY t.urgency = "high" DESC, t.due_at'
		);
		return array_map(
			static function ( $t ) {
				$t['due_local']   = Clock::display( $t['due_at'] );
				$t['assignee']    = self::user_name( $t['assigned_to'] );
				$t['overdue']     = ( Clock::ts( $t['due_at'] ) ?? PHP_INT_MAX ) < Clock::now();
				return $t;
			},
			$rows
		);
	}

	public static function tasks( \WP_REST_Request $r ): array {
		$mine = 'me' === Http::str( $r, 'owner' );
		$rows = Db::rows( 'SELECT t.*, u.full_name FROM ' . Db::t( 'tasks' ) . ' t LEFT JOIN ' . Db::t( 'customers' ) . " u ON u.id = t.customer_id WHERE t.status = 'open'" . ( $mine ? ' AND (t.assigned_to = %d OR t.assigned_to IS NULL)' : '' ) . ' ORDER BY t.priority = "high" DESC, t.due_at LIMIT 300', ...( $mine ? array( get_current_user_id() ) : array() ) );
		return array_map(
			static function ( $t ) {
				$t['type_label'] = Tasks::TYPES[ $t['type'] ]['label'] ?? $t['type'];
				$t['due_local']  = Clock::display( $t['due_at'] );
				$t['assignee']   = self::user_name( $t['assigned_to'] );
				$t['overdue']    = ( Clock::ts( $t['due_at'] ) ?? PHP_INT_MAX ) < Clock::now();
				return $t;
			},
			$rows
		);
	}

	public static function candidates(): array {
		$rows = array_map(
			static function ( $c ) {
				$c['snapshot'] = json_decode( (string) $c['snapshot'], true );
				return $c;
			},
			Db::rows( 'SELECT * FROM ' . Db::t( 'program_candidates' ) . " WHERE status IN ('new') ORDER BY deadline LIMIT 500" )
		);
		$ifd = \Insiders\Collections\Integrations\RevenueDashboard\FinanceDashboard::contract();
		return array(
			'rows'   => $rows,
			'source' => has_filter( 'icol_beginner_program_candidates' ) ? 'filter' : ( $ifd['available'] ? 'finance_dashboard' : ( '' !== (string) Settings::get( 'revenue_meta_deadline' ) ? 'meta' : 'none' ) ),
			'sync'   => $ifd['available'] ? array( 'ok_at' => $ifd['sync_ok_at'] ? Clock::display( $ifd['sync_ok_at'] ) : null, 'age_hours' => $ifd['sync_age_hours'], 'stale' => $ifd['stale'] ) : null,
		);
	}

	/** Students in the pre-deadline phase, with the next message of their track. */
	public static function journey(): array {
		$rows = Db::rows(
			'SELECT c.id, c.customer_id, c.workflow_state, c.track, c.track_step, c.next_action_type, c.next_action_at, c.owner_id, c.claims_account_opened, c.dispute_open, c.agreement_id, u.full_name, u.phone_e164, u.contact_status, a.account_open_deadline, a.signed_at, a.no_registration_fee, a.deal_status, a.lost_reason, a.pipedrive_deal_id, a.deal_checked_at, a.type, a.joined_at, a.price_total_minor, a.fee_credit_minor
			 FROM ' . Db::t( 'cases' ) . ' c JOIN ' . Db::t( 'customers' ) . ' u ON u.id = c.customer_id JOIN ' . Db::t( 'agreements' ) . " a ON a.id = c.agreement_id
			 WHERE c.phase = 'commitment' AND c.workflow_state <> 'closed' ORDER BY a.account_open_deadline, c.id LIMIT 1500"
		);
		$out = array();
		foreach ( $rows as $r ) {
			$next  = \Insiders\Collections\Domain\Journey::next_step( $r, $r );
			$out[] = array(
				'case_id'     => (int) $r['id'],
				'name'        => $r['full_name'],
				'phone'       => $r['phone_e164'],
				'state'       => $r['workflow_state'],
				'track'       => $r['track'],
				'track_label' => \Insiders\Collections\Domain\Journey::TRACKS[ $r['track'] ] ?? '',
				'deadline'    => \Insiders\Collections\Domain\Journey::date_he( (string) $r['account_open_deadline'] ),
				'days_left'   => (int) floor( ( strtotime( $r['account_open_deadline'] ) - strtotime( Clock::today() ) ) / DAY_IN_SECONDS ),
				'last_step'   => $r['track_step'] ? ( \Insiders\Collections\Domain\Journey::STEP_LABELS[ $r['track_step'] ] ?? $r['track_step'] ) : '',
				'next_step'   => $next ? ( \Insiders\Collections\Domain\Journey::STEP_LABELS[ $next['key'] ] ?? $next['key'] ) : '',
				'next_at'     => 'send_journey' === $r['next_action_type'] ? Clock::display( $r['next_action_at'] ) : ( $next ? \Insiders\Collections\Domain\Journey::date_he( $next['date'] ) : '' ),
				'amount_text' => \Insiders\Collections\Domain\Pricing::amount_text( $r ),
				'no_fee'      => (bool) (int) $r['no_registration_fee'],
				'owner'       => self::user_name( $r['owner_id'] ),
				'flags'       => array_values( array_filter( array( (int) $r['claims_account_opened'] ? 'טענת פתיחה' : '', (int) $r['dispute_open'] ? 'מחלוקת' : '', empty( $r['phone_e164'] ) ? 'אין טלפון' : '', $r['deal_checked_at'] ? '' : 'הדיל לא נקרא' ) ) ),
			);
		}
		$beat = Runner::beats()['journey'] ?? array();
		return array(
			'rows'     => $out,
			'by_track' => array_count_values( array_column( $out, 'track' ) ),
			'enabled'  => Settings::on( 'journey_enabled' ),
			'basis'    => (string) Settings::get( 'journey_contact_basis' ),
			'last_run' => array( 'attempt' => Clock::display( $beat['attempt'] ?? null ), 'success' => Clock::display( $beat['success'] ?? null ), 'error' => $beat['last_error'] ?? '', 'note' => mb_substr( (string) ( $beat['last_note'] ?? '' ), 0, 300 ) ),
		);
	}

	public static function customers( \WP_REST_Request $r ): array {
		$q = Http::str( $r, 'q' );
		if ( '' === $q ) {
			return array();
		}
		$phone = Phone::e164( $q );
		$like  = '%' . $GLOBALS['wpdb']->esc_like( $q ) . '%';
		return Db::rows( 'SELECT id, full_name, phone_e164, email, contact_status, wp_user_id FROM ' . Db::t( 'customers' ) . ' WHERE full_name LIKE %s OR email LIKE %s' . ( $phone ? ' OR phone_e164 = %s' : '' ) . ' ORDER BY id DESC LIMIT 20', ...array_merge( array( $like, $like ), $phone ? array( $phone ) : array() ) );
	}

	public static function health(): array {
		$beats = Runner::beats();
		$fmt   = static function ( $b ) {
			$out = array();
			foreach ( (array) $b as $job => $v ) {
				$out[ $job ] = array( 'attempt' => Clock::display( $v['attempt'] ?? null, 'd/m H:i:s' ), 'success' => Clock::display( $v['success'] ?? null, 'd/m H:i:s' ), 'error' => Clock::display( $v['error'] ?? null, 'd/m H:i:s' ), 'last_error' => $v['last_error'] ?? '', 'last_note' => mb_substr( (string) ( $v['last_note'] ?? '' ), 0, 200 ) );
			}
			return $out;
		};
		$q = static fn( string $t, string $col, string $vals ) => Db::rows( 'SELECT ' . $col . ' AS state, COUNT(*) AS n FROM ' . Db::t( $t ) . ' GROUP BY ' . $col );
		return array(
			'jobs'         => $fmt( $beats ),
			'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'cron_url'     => rest_url( 'icol/v1/cron/tick' ) . '?key=' . rawurlencode( Settings::secret( 'cron_key' ) ),
			'last_event'   => array(
				'tranzila' => Clock::display( Db::value( 'SELECT MAX(received_at) FROM ' . Db::t( 'inbox_events' ) . " WHERE provider = 'tranzila'" ) ),
				'wati'     => Clock::display( Db::value( 'SELECT MAX(received_at) FROM ' . Db::t( 'inbox_events' ) . " WHERE provider = 'wati'" ) ),
			),
			'reconcile_stale' => Runner::reconcile_stale(),
			'queues'       => array(
				'inbox'   => $q( 'inbox_events', 'processing_state', '' ),
				'outbox'  => $q( 'outbox_events', 'state', '' ),
				'actions' => $q( 'scheduled_actions', 'state', '' ),
			),
			'suspended'    => (array) get_option( 'icol_suspended', array() ),
			'integrations' => array(
				'tranzila'  => Tranzila::configured(),
				'wati'      => Wati::configured(),
				'pipedrive' => Settings::has_secret( 'pipedrive_token' ),
				'ai'        => Settings::has_secret( 'anthropic_api_key' ) && Classifier::sdk_available(),
				'encryption' => Crypto::available(),
				'encryption_key' => Crypto::source(),
			),
			'schema'       => Schema::verify(),
			'version'      => ICOL_VERSION,
		);
	}

	public static function settings(): array {
		$users = array();
		foreach ( get_users( array( 'number' => 300, 'orderby' => 'display_name', 'meta_key' => 'icol_role', 'meta_compare' => 'EXISTS' ) ) as $u ) {
			$users[] = array( 'id' => $u->ID, 'name' => $u->display_name, 'email' => $u->user_email, 'role' => (string) get_user_meta( $u->ID, 'icol_role', true ), 'pipedrive_user_id' => (string) get_user_meta( $u->ID, 'icol_pipedrive_user_id', true ) );
		}
		$secrets = array();
		foreach ( array( 'tranzila_app_key', 'tranzila_secret', 'wati_token', 'pipedrive_token', 'anthropic_api_key', 'tranzila_webhook_secret', 'wati_webhook_secret', 'cron_key' ) as $s ) {
			$secrets[ $s ] = Settings::has_secret( $s );
		}
		return array(
			'settings'   => array_merge( Settings::DEFAULTS, Settings::all() ),
			'secrets'    => $secrets,
			'webhooks'   => array(
				'tranzila_sto' => Settings::has_secret( 'tranzila_webhook_secret' ) ? rest_url( 'icol/v1/webhooks/tranzila/sto/' . Settings::secret( 'tranzila_webhook_secret' ) ) : '',
				'tranzila_pr'  => Settings::has_secret( 'tranzila_webhook_secret' ) ? rest_url( 'icol/v1/webhooks/tranzila/pr/' . Settings::secret( 'tranzila_webhook_secret' ) ) : '',
				'wati'         => Settings::has_secret( 'wati_webhook_secret' ) ? rest_url( 'icol/v1/webhooks/wati/' . Settings::secret( 'wati_webhook_secret' ) ) : '',
			),
			'policies'   => array_map( static function ( $p ) { $p['config'] = json_decode( (string) $p['config'], true ); return $p; }, Db::rows( 'SELECT * FROM ' . Db::t( 'policies' ) . ' ORDER BY version DESC LIMIT 10' ) ),
			'templates'  => array_values( Templates::all() ),
			'users'      => $users,
			'roles'      => array_map( static fn( $r ) => $r['label'], Capabilities::ROLES ),
			'code_map'   => array_replace( ResponseCodes::SEED, (array) json_decode( (string) Settings::get( 'tranzila_code_map', '' ), true ) ),
			'failure_classes' => ResponseCodes::LABELS,
		);
	}
}

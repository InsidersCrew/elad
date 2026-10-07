<?php
namespace Insiders\Collections\Domain;

use Insiders\Collections\Engine\Outbox;
use Insiders\Collections\Engine\Scheduler;
use Insiders\Collections\Support\Audit;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Ids;
use Insiders\Collections\Support\Money;
use Insiders\Collections\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reminder sequence (§9), sending (§17) and inbound replies (§10).
 *
 * The rules engine decides whether a message may go out and what it says; the
 * model, when enabled, only classifies and suggests. Inbound messages pause the
 * sequence before anything else happens — classification can fail, stopping cannot.
 */
final class Messaging {

	public const CARD_FAILURES = array( 'expired_card', 'card_invalid' );

	/** Schedule the next reminder for a case, or escalate when the sequence is exhausted. */
	public static function plan_next( int $case_id ): ?int {
		$case = Workflow::get( $case_id );
		if ( ! $case || ! in_array( $case['workflow_state'], Workflow::SENDABLE, true ) ) {
			return null;
		}
		$policy = Policy::effective_or_draft();
		$step   = (int) $case['sequence_step'];
		$now    = Clock::now();

		if ( $step >= (int) $policy['max_reminders'] ) {
			$at  = Clock::local_to_ts( Calendar::add_business_days( Clock::local_date( (int) Clock::ts( $case['last_outbound_at'] ) ?: $now ), 2, $policy ), $policy['window_start'] );
			return Scheduler::schedule( 'escalate_no_reply', $at, 'escalate:' . $case_id . ':' . self::seq( $case ), $case_id, (int) $case['customer_id'], array(), (int) $policy['_version'] );
		}

		if ( 0 === $step ) {
			$base = max( $now, (int) Clock::ts( $case['activated_at'] ) );
			$items = Db::rows( 'SELECT due_at, finance_state FROM ' . Db::t( 'debt_items' ) . " WHERE case_id = %d AND finance_state IN ('open','partially_paid','not_due') ORDER BY due_at", $case_id );
			$due_now = array_filter( $items, static fn( $i ) => 'not_due' !== $i['finance_state'] );
			if ( ! $due_now && $items ) {
				// §5: a future charge stays "not due" — the first message waits for the due date.
				$base = max( $base, Clock::local_to_ts( $items[0]['due_at'], $policy['window_start'] ) );
			}
			if ( 'recurring_failure' === $case['source_type'] ) {
				$attempt = Db::row(
					'SELECT a.failure_class, a.occurred_at FROM ' . Db::t( 'charge_attempts' ) . ' a JOIN ' . Db::t( 'debt_items' ) . " d ON d.id = a.debt_item_id WHERE d.case_id = %d AND a.response_code <> '000' ORDER BY a.occurred_at DESC LIMIT 1",
					$case_id
				);
				$class = $attempt['failure_class'] ?? 'unknown';
				$grace = $policy['grace_business_days'][ $class ] ?? null;
				if ( null === $grace ) {
					Workflow::transition( $case_id, 'human_review', 'סוג הכשל (' . $class . ') מחייב בדיקת נציג לפני פנייה', null, array(), 'plan' );
					Tasks::open( 'failure_review:' . $case_id, 'reply_review', array( 'case_id' => $case_id, 'reason' => 'כשל חיוב מסוג ' . $class . ', אין פנייה אוטומטית' ) );
					return null;
				}
				$fail_date = Clock::local_date( (int) Clock::ts( $attempt['occurred_at'] ?? null ) ?: $now );
				$base      = max( $base, Clock::local_to_ts( Calendar::add_business_days( $fail_date, (int) $grace, $policy ), $policy['window_start'] ) );
			}
			$jitter = (int) $policy['first_contact_jitter_minutes'];
			$base  += $jitter > 0 ? random_int( 0, $jitter ) * MINUTE_IN_SECONDS : 0;
		} else {
			$last     = (int) Clock::ts( $case['last_outbound_at'] ) ?: $now;
			$gap_days = (int) ( $policy['cadence_business_days'][ $step - 1 ] ?? end( $policy['cadence_business_days'] ) );
			$date     = Calendar::add_business_days( Clock::local_date( $last ), $gap_days, $policy );
			$base     = Clock::local_to_ts( $date, Clock::local( $last )->format( 'H:i' ) );
			$base     = max( $base, $last + (int) $policy['min_gap_hours'] * HOUR_IN_SECONDS );
		}
		$run_at = Calendar::next_slot( max( $base, $now ), $policy );
		$id     = Scheduler::schedule( 'send_reminder', $run_at, 'remind:' . $case_id . ':' . self::seq( $case ) . ':' . $step, $case_id, (int) $case['customer_id'], array( 'step' => $step ), (int) $policy['_version'] );
		Db::update( 'cases', array( 'next_action_type' => 'send_reminder', 'next_action_at' => Clock::utc( $run_at ) ), array( 'id' => $case_id ) );
		return $id;
	}

	private static function seq( array $case ): string {
		return (string) ( Clock::ts( $case['sequence_started_at'] ) ?? 0 );
	}

	/** Picks the template for the next proactive message. */
	public static function choose_template( array $case, bool $link_available ): string {
		$step = (int) $case['sequence_step'];
		if ( 'non_open_charge' === $case['source_type'] ) {
			if ( 0 === $step && (int) $case['clarification_first'] ) {
				return 'clarify_before_charge';
			}
			if ( 0 === $step || ( 1 === $step && (int) $case['clarification_first'] ) ) {
				return $link_available ? 'program_charge_link' : 'program_charge_reply';
			}
			return $link_available ? 'reminder_link' : 'reminder_reply';
		}
		if ( 'recurring_failure' === $case['source_type'] && 0 === $step ) {
			$class = (string) Db::value(
				'SELECT a.failure_class FROM ' . Db::t( 'charge_attempts' ) . ' a JOIN ' . Db::t( 'debt_items' ) . " d ON d.id = a.debt_item_id WHERE d.case_id = %d AND a.response_code <> '000' ORDER BY a.occurred_at DESC LIMIT 1",
				(int) $case['id']
			);
			if ( in_array( $class, self::CARD_FAILURES, true ) && Settings::on( 'tranzila_card_fix_email' ) ) {
				$open = (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'debt_items' ) . " WHERE case_id = %d AND finance_state IN ('open','partially_paid')", (int) $case['id'] );
				return $open > 1 ? 'card_fix_email_multi' : 'card_fix_email';
			}
			return $link_available ? 'payment_failed_link' : 'payment_failed_reply';
		}
		return $link_available ? 'reminder_link' : 'reminder_reply';
	}

	/** Variables for rendering — every amount and date read fresh from the ledger. */
	public static function variables( array $case, ?string $link ): array {
		$customer = Customers::get( (int) $case['customer_id'] );
		$summary  = Ledger::case_summary( (int) $case['id'] );
		$open     = array_values( array_filter( $summary['items'], static fn( $i ) => in_array( $i['finance_state'], array( 'open', 'partially_paid' ), true ) ) );
		if ( 'draft' === $case['workflow_state'] && ! $open ) {
			// Activation preview (§5): show the message as it will read once the items are approved.
			foreach ( $summary['items'] as $i ) {
				if ( 'draft' === $i['finance_state'] && $i['due_at'] <= Clock::today() ) {
					$i['cached_balance_minor']      = (int) $i['original_amount_minor'];
					$open[]                         = $i;
					$summary['due_balance_minor']  += (int) $i['original_amount_minor'];
				}
			}
		}
		$first    = $open[0] ?? null;
		$item     = '';
		if ( count( $open ) === 1 ) {
			$item = $first['description'] ?: ( 'התשלום מתאריך ' . gmdate( 'd/m/Y', strtotime( $first['due_at'] ) ) );
		} elseif ( count( $open ) > 1 ) {
			$item = count( $open ) . ' תשלומים';
		}
		$email = (string) ( $customer['email'] ?? '' );
		$promise = Db::row( 'SELECT * FROM ' . Db::t( 'promises' ) . " WHERE case_id = %d AND state IN ('approved','broken') ORDER BY promised_at DESC LIMIT 1", (int) $case['id'] );
		return array(
			'greeting'     => Templates::greeting( Customers::greeting_name( $customer ) ),
			'item'         => $item,
			'amount'       => $summary['due_balance_minor'] > 0 ? Money::format( $summary['due_balance_minor'] ) : '',
			'balance'      => $summary['due_balance_minor'] > 0 ? Money::format( $summary['due_balance_minor'] ) : '',
			'items_count'  => (string) count( $open ),
			'link'         => $link,
			'email_masked' => $email ? self::mask_email( $email ) : '',
			'promise_date' => $promise ? gmdate( 'd/m', strtotime( $promise['promised_at'] ) ) : '',
		);
	}

	public static function mask_email( string $email ): string {
		[ $u, $d ] = array_pad( explode( '@', $email, 2 ), 2, '' );
		return mb_substr( $u, 0, 2 ) . '•••@' . $d;
	}

	/** Build the exact message that would go out now — used by preview and by the sender. */
	public static function compose( int $case_id, bool $create_link ): array {
		$case     = Workflow::get( $case_id );
		$link_ok  = PaymentRequests::link_allowed( $case );
		$link     = ( $link_ok['allowed'] && $create_link ) ? PaymentRequests::local_link( $case_id ) : ( $link_ok['allowed'] ? '[קישור יופק בשליחה]' : null );
		$key      = self::choose_template( $case, $link_ok['allowed'] );
		if ( ! empty( $case['_promise_followup'] ) ) {
			$key = 'promise_followup';
		}
		$tpl            = Templates::get( $key );
		$vars           = self::variables( $case, $link );
		[ $text, $miss ] = Templates::render( $tpl, $vars );
		return array(
			'template'      => $tpl,
			'rendered_text' => $text,
			'vars'          => $vars,
			'missing'       => $miss,
			'link_route'    => $link_ok,
		);
	}

	/** §19 חוזה תצוגה: text, versions, send_after and every blocker. */
	public static function preview( int $case_id ): array {
		$case    = Workflow::get( $case_id );
		$policy  = Policy::effective_or_draft();
		$comp    = self::compose( $case_id, false );
		$pending = Db::row( 'SELECT run_at FROM ' . Db::t( 'scheduled_actions' ) . " WHERE case_id = %d AND type = 'send_reminder' AND state = 'pending' ORDER BY run_at LIMIT 1", $case_id );
		$send_after = $pending ? Clock::ts( $pending['run_at'] ) : Calendar::next_slot( Clock::now(), $policy );
		$guard_case = $case;
		if ( 'draft' === $case['workflow_state'] ) {
			$guard_case['workflow_state'] = 'active'; // preview for activation: judge as if active
		}
		$guard = self::guard_with_state( $guard_case, $comp['template'], $send_after );
		foreach ( $comp['missing'] as $m ) {
			$guard['blockers'][] = array( 'code' => 'missing_variable', 'scope' => 'case', 'message' => 'חסר ערך למשתנה ' . $m );
		}
		$summary = Ledger::case_summary( $case_id );
		if ( 'draft' === $case['workflow_state'] && $summary['draft_minor'] > 0 ) {
			// Before approval the balance is zero by definition; say what is actually missing.
			$guard['blockers']   = array_values( array_filter( $guard['blockers'], static fn( $b ) => 'no_due_balance' !== $b['code'] ) );
			$guard['blockers'][] = array( 'code' => 'not_approved', 'scope' => 'case', 'message' => 'פריטי החוב טרם אושרו בידי אחראי גבייה או מנהל' );
		}
		$last_msg = (int) Db::value( 'SELECT COALESCE(MAX(id),0) FROM ' . Db::t( 'messages' ) . ' WHERE customer_id = %d', (int) $case['customer_id'] );
		return array(
			'rendered_text'        => $comp['rendered_text'],
			'template_id'          => $comp['template']['key'],
			'template_version'     => (int) $comp['template']['version'],
			'balance_version'      => $summary['signature'],
			'conversation_version' => $last_msg,
			'send_after'           => Clock::utc( $send_after ),
			'send_after_local'     => Clock::local( $send_after )->format( 'd/m/Y H:i' ),
			'mode'                 => $guard['mode'],
			'blockers'             => $guard['blockers'],
			'link_route'           => $comp['link_route'],
			'finance_summary'      => $summary,
		);
	}

	private static function guard_with_state( array $case, array $tpl, int $at ): array {
		// SendGuard reads state from the DB; for draft previews evaluate everything except state.
		$g = SendGuard::check( (int) $case['id'], array( 'template' => $tpl, 'at' => $at, 'is_reminder' => true ) );
		if ( 'active' === $case['workflow_state'] ) {
			$g['blockers'] = array_values( array_filter( $g['blockers'], static fn( $b ) => 'state' !== $b['code'] ) );
		}
		return $g;
	}

	/** Executes a due send_reminder action. Returns a short result string for the action log. */
	public static function execute_reminder( array $action ): string {
		$case_id = (int) $action['case_id'];
		$case    = Workflow::get( $case_id );
		if ( ! $case ) {
			return 'cancel:case_missing';
		}
		$payload = (array) json_decode( (string) $action['payload'], true );
		if ( (int) ( $payload['step'] ?? -1 ) !== (int) $case['sequence_step'] && empty( $payload['promise_followup'] ) ) {
			return 'cancel:stale_step';
		}
		if ( ! empty( $payload['promise_followup'] ) ) {
			$case['_promise_followup'] = true;
		}
		$comp  = self::compose( $case_id, false );
		$guard = SendGuard::check( $case_id, array( 'template' => $comp['template'], 'action_created_at' => $action['created_at'], 'is_reminder' => true, 'promise_followup' => ! empty( $payload['promise_followup'] ) ) );
		if ( $comp['missing'] ) {
			$guard['ok']         = false;
			$guard['blocking'][] = array( 'code' => 'missing_variable', 'scope' => 'case', 'message' => 'חסר ערך: ' . implode( ',', $comp['missing'] ) );
			$guard['transient']  = false;
		}
		if ( ! $guard['ok'] ) {
			return self::handle_block( $action, $case, $guard );
		}
		// Only now create the payment link: a blocked message must not mint links.
		if ( $comp['template']['requires_link'] ) {
			$comp = self::compose( $case_id, true );
		}
		// Message row, outbox entry, state change and the next plan commit together:
		// a crash in between leaves nothing half-done for the lease-recovery to repeat.
		return Db::transaction(
			function () use ( $case, $case_id, $comp, $guard, $payload ) {
				$message_id = self::record_outbound( $case, $comp['template'], $comp['rendered_text'], $guard['mode'], 'system', null, $comp['vars'] );
				$next_step  = empty( $payload['promise_followup'] ) ? (int) $case['sequence_step'] + 1 : (int) $case['sequence_step'];
				Workflow::transition( $case_id, 'waiting_reply', 'נשלחה תזכורת ' . $next_step . ( 'live' === $guard['mode'] ? '' : ' (סימולציה)' ), null, array( 'sequence_step' => $next_step, 'last_outbound_at' => Clock::utc() ), 'reminder' );
				self::plan_next( $case_id );
				return ( 'live' === $guard['mode'] ? 'sent:' : 'simulated:' ) . $message_id;
			}
		);
	}

	private static function handle_block( array $action, array $case, array $guard ): string {
		$codes = array_column( $guard['blocking'], 'code' );
		if ( $guard['transient'] ) {
			return 'retry:' . implode( ',', $codes );
		}
		Audit::log( 'reminder.blocked', 'case', (int) $case['id'], null, array( 'codes' => $codes ), implode( ' · ', array_column( $guard['blocking'], 'message' ) ) );
		if ( in_array( 'no_due_balance', $codes, true ) ) {
			Cases::maybe_close( (int) $case['id'] );
			return 'cancel:no_due_balance';
		}
		if ( array_intersect( $codes, array( 'identity', 'shared_phone', 'no_phone' ) ) ) {
			Exceptions::open( 'identity:' . $case['customer_id'], 'identity_conflict', 'medium', 'נדרשת התאמת זהות לפני פנייה', array( 'customer_id' => (int) $case['customer_id'], 'entity_type' => 'case', 'entity_id' => (int) $case['id'] ) );
		}
		if ( array_intersect( $codes, array( 'delivery_unknown' ) ) ) {
			Exceptions::open( 'send_unknown:cust:' . $case['customer_id'], 'send_unknown', 'high', 'הודעה קודמת במצב לא ידוע, לא שולחים עד בירור', array( 'customer_id' => (int) $case['customer_id'] ) );
		}
		return 'cancel:' . implode( ',', $codes );
	}

	/**
	 * Write the message row (and, when live, the outbox entry) in one transaction.
	 * In display-only mode the row is 'simulated' — the record of what would have gone out.
	 */
	public static function record_outbound( array $case, array $tpl, string $text, string $mode, string $author_type, ?int $author_id = null, array $vars = array() ): int {
		return Db::transaction(
			function () use ( $case, $tpl, $text, $mode, $author_type, $author_id, $vars ) {
				$local = Ids::uuid();
				$id    = Db::insert(
					'messages',
					array(
						'customer_id'         => (int) $case['customer_id'],
						'case_id'             => (int) $case['id'],
						'provider'            => 'wati',
						'local_id'            => $local,
						'direction'           => 'out',
						'channel'             => 'whatsapp',
						'kind'                => $tpl['kind'],
						'template_key'        => $tpl['key'],
						'template_version'    => (int) $tpl['version'],
						'body'                => $text,
						'vars_json'           => $vars ? wp_json_encode( array_intersect_key( $vars, array_flip( (array) $tpl['params'] ) ), JSON_UNESCAPED_UNICODE ) : null,
						'author_type'         => $author_type,
						'author_id'           => $author_id,
						'delivery_state'      => 'live' === $mode ? 'queued' : 'simulated',
						'counts_toward_quota' => $tpl['counts_toward_quota'] ? 1 : 0,
						'occurred_at'         => Clock::utc(),
						'created_at'          => Clock::utc(),
						'updated_at'          => Clock::utc(),
					)
				);
				if ( 'live' === $mode ) {
					Outbox::enqueue( 'wati_send', 'message', $id, 'wati_send:' . $local, array( 'message_id' => $id ) );
				}
				return $id;
			}
		);
	}

	/** Service reply (ack, handoff, bot answer). Never counted as a reminder, never bypasses the pause. */
	public static function service_reply( int $case_id, string $template_key ): ?int {
		$case = Workflow::get( $case_id );
		$tpl  = Templates::get( $template_key );
		if ( ! $case || ! $tpl ) {
			return null;
		}
		$g = SendGuard::check( $case_id, array( 'template' => $tpl, 'is_reminder' => false ) );
		$hard = array_filter( $g['blocking'], static fn( $b ) => in_array( $b['code'], array( 'opted_out', 'identity', 'shared_phone', 'no_phone', 'conversation_owner', 'delivery_unknown' ), true ) || 'setup' === $b['scope'] );
		if ( 'stop_ack' === $template_key ) {
			$hard = array_filter( $hard, static fn( $b ) => 'opted_out' !== $b['code'] );
		}
		if ( 'live' === $g['mode'] && ( $hard || ! SendGuard::in_service_window( (int) $case['customer_id'] ) ) ) {
			return null;
		}
		$vars     = self::variables( $case, null );
		[ $text ] = Templates::render( $tpl, $vars );
		return self::record_outbound( $case, $tpl, $text, 'live' === $g['mode'] && ! $hard ? 'live' : 'simulate', 'system', null, $vars );
	}

	/**
	 * Inbound customer message. Order matters: record, pause every sendable case of
	 * this customer, then classify. A classification failure leaves the pause in place.
	 */
	public static function handle_inbound( int $customer_id, array $msg ): array {
		$message_id = Db::transaction(
			function () use ( $customer_id, $msg ) {
				$existing = ! empty( $msg['provider_id'] ) ? Db::value( 'SELECT id FROM ' . Db::t( 'messages' ) . " WHERE provider = 'wati' AND provider_id = %s", $msg['provider_id'] ) : null;
				if ( $existing ) {
					return -1 * (int) $existing; // duplicate delivery
				}
				$id = Db::insert(
					'messages',
					array(
						'customer_id'    => $customer_id,
						'provider'       => 'wati',
						'provider_id'    => $msg['provider_id'] ?? null,
						'local_id'       => Ids::uuid(),
						'direction'      => 'in',
						'channel'        => 'whatsapp',
						'kind'           => 'customer_message',
						'body'           => mb_substr( (string) ( $msg['text'] ?? '' ), 0, 10000 ),
						'author_type'    => 'customer',
						'delivery_state' => 'received',
						'occurred_at'    => $msg['occurred_at'] ?? Clock::utc(),
						'created_at'     => Clock::utc(),
						'updated_at'     => Clock::utc(),
					)
				);
				Db::update( 'customers', array( 'last_inbound_at' => $msg['occurred_at'] ?? Clock::utc() ), array( 'id' => $customer_id ) );
				return $id;
			}
		);
		if ( $message_id < 0 ) {
			return array( 'duplicate' => true, 'message_id' => -$message_id );
		}
		$cases = Db::rows( 'SELECT * FROM ' . Db::t( 'cases' ) . " WHERE customer_id = %d AND workflow_state NOT IN ('closed','draft')", $customer_id );
		foreach ( $cases as $c ) {
			Scheduler::cancel_for_case( (int) $c['id'], 'customer_replied' );
			Db::update( 'cases', array( 'last_inbound_at' => $msg['occurred_at'] ?? Clock::utc() ), array( 'id' => (int) $c['id'] ) );
		}
		$customer = Customers::get( $customer_id );
		if ( ! in_array( $customer['conversation_owner'], array( 'none', 'collections' ), true ) ) {
			// Another owner (onboarding agent / human) holds the conversation: record only.
			return array( 'message_id' => $message_id, 'intent' => 'not_owner', 'cases' => count( $cases ) );
		}
		$intent = Intents::classify( (string) ( $msg['text'] ?? '' ), (string) ( $msg['type'] ?? 'text' ) );
		Db::update( 'messages', array( 'intent' => $intent['intent'], 'intent_source' => $intent['source'] ), array( 'id' => $message_id ) );
		if ( $cases ) {
			Db::update( 'messages', array( 'case_id' => (int) $cases[0]['id'] ), array( 'id' => $message_id ) );
		}
		Intents::apply( $customer_id, $message_id, $intent, $cases, $msg );
		return array( 'message_id' => $message_id, 'intent' => $intent['intent'], 'cases' => count( $cases ) );
	}

	/** Operator message typed in WATI (AT33): documented, counts toward quota, shapes what follows. */
	public static function record_operator_outbound( int $customer_id, array $msg ): int {
		$existing = ! empty( $msg['provider_id'] ) ? Db::value( 'SELECT id FROM ' . Db::t( 'messages' ) . " WHERE provider = 'wati' AND provider_id = %s", $msg['provider_id'] ) : null;
		if ( $existing ) {
			return (int) $existing;
		}
		$case = Db::row( 'SELECT * FROM ' . Db::t( 'cases' ) . " WHERE customer_id = %d AND workflow_state NOT IN ('closed','draft') ORDER BY id LIMIT 1", $customer_id );
		$id   = Db::insert(
			'messages',
			array(
				'customer_id'         => $customer_id,
				'case_id'             => $case ? (int) $case['id'] : null,
				'provider'            => 'wati',
				'provider_id'         => $msg['provider_id'] ?? null,
				'local_id'            => Ids::uuid(),
				'direction'           => 'out',
				'channel'             => 'whatsapp',
				'kind'                => 'operator_message',
				'body'                => mb_substr( (string) ( $msg['text'] ?? '' ), 0, 10000 ),
				'author_type'         => 'operator',
				'delivery_state'      => 'sent',
				'counts_toward_quota' => 1,
				'occurred_at'         => $msg['occurred_at'] ?? Clock::utc(),
				'created_at'          => Clock::utc(),
				'updated_at'          => Clock::utc(),
			)
		);
		if ( $case && in_array( $case['workflow_state'], Workflow::SENDABLE, true ) ) {
			// A human is talking to the customer: automation steps aside until a rep decides.
			Workflow::transition( (int) $case['id'], 'human_review', 'נציג כתב ללקוח ישירות ב-WATI', null, array( 'last_outbound_at' => Clock::utc() ), 'operator' );
		}
		return $id;
	}

	/** Internal note in the timeline — flagged so it can never be sent by mistake. */
	public static function add_note( int $case_id, string $text ): int {
		$case = Workflow::get( $case_id );
		$id   = Db::insert(
			'messages',
			array(
				'customer_id'    => (int) $case['customer_id'],
				'case_id'        => $case_id,
				'provider'       => 'internal',
				'local_id'       => Ids::uuid(),
				'direction'      => 'out',
				'channel'        => 'internal_note',
				'kind'           => 'internal',
				'body'           => mb_substr( $text, 0, 10000 ),
				'author_type'    => 'user',
				'author_id'      => get_current_user_id() ?: null,
				'delivery_state' => 'internal',
				'is_internal'    => 1,
				'occurred_at'    => Clock::utc(),
				'created_at'     => Clock::utc(),
				'updated_at'     => Clock::utc(),
			)
		);
		Audit::log( 'note.add', 'case', $case_id, null, null, mb_substr( $text, 0, 200 ) );
		return $id;
	}

	/** Manual call / phone log by a rep — counts toward quota when it was proactive outreach. */
	public static function log_contact( int $case_id, string $channel, string $summary, bool $proactive ): int {
		$case = Workflow::get( $case_id );
		return Db::insert(
			'messages',
			array(
				'customer_id'         => (int) $case['customer_id'],
				'case_id'             => $case_id,
				'provider'            => 'manual',
				'local_id'            => Ids::uuid(),
				'direction'           => 'out',
				'channel'             => in_array( $channel, array( 'phone', 'whatsapp', 'email', 'sms' ), true ) ? $channel : 'phone',
				'kind'                => 'manual_contact',
				'body'                => mb_substr( $summary, 0, 10000 ),
				'author_type'         => 'user',
				'author_id'           => get_current_user_id() ?: null,
				'delivery_state'      => 'sent',
				'counts_toward_quota' => $proactive ? 1 : 0,
				'occurred_at'         => Clock::utc(),
				'created_at'          => Clock::utc(),
				'updated_at'          => Clock::utc(),
			)
		);
	}

	/** After a verified payment: confirmation that never says "all set" while anything is open. */
	public static function payment_confirmation( int $case_id, int $paid_minor ): ?int {
		$case    = Workflow::get( $case_id );
		$summary = Ledger::case_summary( $case_id );
		$card_open = $case['recurring_order_id'] ? (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'card_update_tasks' ) . " WHERE recurring_order_id = %d AND state IN ('open','in_progress','needs_clarification')", (int) $case['recurring_order_id'] ) : 0;
		if ( $summary['due_balance_minor'] > 0 ) {
			$status = 'נשארה יתרה פתוחה של ' . Money::format( $summary['due_balance_minor'] ) . ' ₪.';
		} elseif ( $card_open ) {
			$status = 'התשלום הזה הוסדר. ניצור קשר בנוגע לאמצעי התשלום לחיובים הבאים.';
		} else {
			$status = 'התשלום הזה הוסדר.';
		}
		$tpl = Templates::get( 'payment_verified' );
		$customer = Customers::get( (int) $case['customer_id'] );
		$vars = array( 'greeting' => Templates::greeting( Customers::greeting_name( $customer ) ), 'paid' => Money::format( $paid_minor ), 'status_line' => $status );
		[ $text, $miss ] = Templates::render( $tpl, $vars );
		if ( $miss ) {
			return null;
		}
		$g = SendGuard::check( $case_id, array( 'template' => $tpl, 'is_reminder' => false ) );
		$hard = array_filter( $g['blocking'], static fn( $b ) => in_array( $b['code'], array( 'opted_out', 'identity', 'shared_phone', 'no_phone', 'delivery_unknown', 'no_permission' ), true ) );
		if ( $hard ) {
			return null;
		}
		return self::record_outbound( $case, $tpl, $text, $g['ok'] ? $g['mode'] : 'simulate', 'system', null, $vars );
	}
}

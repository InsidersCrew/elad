<?php
namespace Insiders\Collections\Domain;

use Insiders\Collections\Engine\Outbox;
use Insiders\Collections\Support\Audit;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Internal tasks + notification center (§18). The local task is the source of
 * truth; email and Pipedrive are copies sent through the outbox. A repeated
 * alert attaches to the open task (repeat_count) instead of creating another one.
 * A CRM failure never cancels the local task and never triggers a customer message.
 */
final class Tasks {

	public const TYPES = array(
		'card_update'        => array( 'label' => 'בדיקת טוקן ותוקף בהוראה', 'due_days' => 1 ),
		'verify_payment'     => array( 'label' => 'אימות ושיוך תקבול', 'due_days' => 1 ),
		'dispute'            => array( 'label' => 'בדיקת בסיס החיוב', 'due_days' => 1 ),
		'hardship'           => array( 'label' => 'שיחה עם אחראי גבייה', 'due_days' => 1 ),
		'unmatched_event'    => array( 'label' => 'התאמה ידנית', 'due_days' => 1 ),
		'integration'        => array( 'label' => 'טיפול טכני', 'due_days' => 0 ),
		'double_charge'      => array( 'label' => 'בדיקה כספית דחופה', 'due_days' => 0 ),
		'no_reply_call'      => array( 'label' => 'שיחת נציג', 'due_days' => 1 ),
		'reply_review'       => array( 'label' => 'מענה לפנייה נכנסת', 'due_days' => 0 ),
		'account_opened_claim' => array( 'label' => 'בירור טענת פתיחת חשבון', 'due_days' => 1 ),
		'promise_request'    => array( 'label' => 'החלטה על בקשת מועד תשלום', 'due_days' => 1 ),
		'alert'              => array( 'label' => 'התראה', 'due_days' => 0 ),
		'crm_record'         => array( 'label' => 'רישום התשלום בדיל בפייפדרייב', 'due_days' => 1 ),
		'open_call'          => array( 'label' => 'שיחה לליווי פתיחת חשבון', 'due_days' => 0 ),
		'crm_lost'           => array( 'label' => 'סימון הדיל כ-lost בפייפדרייב', 'due_days' => 1 ),
		'deal_lost'          => array( 'label' => 'שינוי בדיל בפייפדרייב: להחליט על התיק', 'due_days' => 1 ),
		'program_credit'     => array( 'label' => 'זיכוי לפי תנאי התוכנית', 'due_days' => 2 ),
		'approve_charge'     => array( 'label' => 'אישור חיוב: התלמיד ביקש לשלם', 'due_days' => 0 ),
		'mb_mark_handled'    => array( 'label' => 'סימון עסקה שטופלה ב-My Billing', 'due_days' => 0 ),
		'crm_reopen'         => array( 'label' => 'הוצאת הדיל מ-lost אחרי תשלום', 'due_days' => 1 ),
		'complete_phone'     => array( 'label' => 'השלמת מספר טלפון ללקוח', 'due_days' => 1 ),
	);

	public static function open( string $task_key, string $type, array $opts = array() ): int {
		$existing = Db::row( 'SELECT * FROM ' . Db::t( 'tasks' ) . ' WHERE task_key = %s', $task_key );
		if ( $existing && ! empty( $opts['append'] ) ) {
			// One task per student for their messages: the newest first, the earlier ones after it.
			$rank   = array( 'low' => 0, 'normal' => 1, 'high' => 2 );
			$prio   = $opts['priority'] ?? 'normal';
			$reason = (string) ( $opts['reason'] ?? '' );
			if ( 'open' === $existing['status'] ) {
				Db::update( 'tasks', array( 'reason' => mb_substr( $reason . ( '' !== (string) $existing['reason'] ? ' | ' . $existing['reason'] : '' ), 0, 500 ), 'repeat_count' => (int) $existing['repeat_count'] + 1, 'priority' => ( $rank[ $prio ] ?? 1 ) > ( $rank[ $existing['priority'] ] ?? 1 ) ? $prio : $existing['priority'], 'updated_at' => Clock::utc() ), array( 'id' => (int) $existing['id'] ) );
				return (int) $existing['id'];
			}
			// Done before: a new message makes it a task again.
			$days = $opts['due_days'] ?? ( self::TYPES[ $type ]['due_days'] ?? 1 );
			$pol  = Policy::effective_or_draft();
			$due  = $days > 0 ? Clock::local_to_ts( Calendar::add_business_days( Clock::today(), (int) $days, $pol ), $pol['window_end'] ) : Clock::now();
			Db::update( 'tasks', array( 'status' => 'open', 'reason' => mb_substr( $reason, 0, 500 ), 'priority' => $prio, 'repeat_count' => 0, 'resolution_note' => null, 'resolved_by' => null, 'resolved_at' => null, 'due_at' => Clock::utc( $due ), 'updated_at' => Clock::utc() ), array( 'id' => (int) $existing['id'] ) );
			self::fan_out( (int) $existing['id'], true );
			return (int) $existing['id'];
		}
		if ( $existing ) {
			if ( 'open' === $existing['status'] ) {
				Db::update( 'tasks', array( 'repeat_count' => (int) $existing['repeat_count'] + 1, 'updated_at' => Clock::utc() ), array( 'id' => (int) $existing['id'] ) );
			}
			return (int) $existing['id'];
		}
		$policy   = Policy::effective_or_draft();
		$days     = $opts['due_days'] ?? ( self::TYPES[ $type ]['due_days'] ?? 1 );
		$due_date = $days > 0 ? Calendar::add_business_days( Clock::today(), (int) $days, $policy ) : Clock::today();
		$due_ts   = $days > 0 ? Clock::local_to_ts( $due_date, $policy['window_end'] ) : Clock::now();
		$assignee = $opts['assigned_to'] ?? null;
		if ( ! $assignee && ! empty( $opts['case_id'] ) ) {
			$assignee = (int) Db::value( 'SELECT owner_id FROM ' . Db::t( 'cases' ) . ' WHERE id = %d', (int) $opts['case_id'] ) ?: null;
		}
		$assignee = $assignee ?: ( (int) Settings::get( 'fallback_owner_id', 0 ) ?: null );
		try {
			$id = Db::insert(
				'tasks',
				array(
					'task_key'    => $task_key,
					'type'        => $type,
					'case_id'     => $opts['case_id'] ?? null,
					'customer_id' => $opts['customer_id'] ?? null,
					'assigned_to' => $assignee,
					'due_at'      => Clock::utc( $due_ts ),
					'priority'    => $opts['priority'] ?? 'normal',
					'reason'      => mb_substr( (string) ( $opts['reason'] ?? '' ), 0, 500 ),
					'status'      => 'open',
					'created_at'  => Clock::utc(),
				)
			);
		} catch ( \Insiders\Collections\Support\DbError $e ) {
			if ( $e->duplicate ) {
				return (int) Db::value( 'SELECT id FROM ' . Db::t( 'tasks' ) . ' WHERE task_key = %s', $task_key );
			}
			throw $e;
		}
		self::fan_out( $id );
		return $id;
	}

	/** Copies to email / Pipedrive go through the outbox with keys derived from the task key. */
	/**
	 * The case's owner became known (the rep on the Pipedrive deal): open tasks that went to the general
	 * queue or to the fallback user move to them. A task someone handed to a specific person stays.
	 * updated_at is left alone, so the move alone does not put a task in tonight's Pipedrive summary.
	 */
	public static function reassign_case( int $case_id, int $owner ): int {
		$fallback = (int) Settings::get( 'fallback_owner_id', 0 );
		if ( $owner <= 0 || $owner === $fallback ) {
			return 0;
		}
		return (int) Db::exec( 'UPDATE ' . Db::t( 'tasks' ) . " SET assigned_to = %d WHERE case_id = %d AND status = 'open' AND (assigned_to IS NULL OR assigned_to = %d)", $owner, $case_id, $fallback );
	}

	private static function fan_out( int $task_id, bool $reopened = false ): void {
		$task = Db::row( 'SELECT * FROM ' . Db::t( 'tasks' ) . ' WHERE id = %d', $task_id );
		$tag  = $reopened ? ':' . Clock::utc() : '';
		if ( Settings::on( 'cap_email_alerts' ) && '' !== (string) Settings::get( 'alert_email' ) ) {
			Outbox::enqueue( 'email_alert', 'task', $task_id, 'email:' . $task['task_key'] . $tag, array( 'task_id' => $task_id ) );
		}
		// With a daily summary hour, Pipedrive gets one activity per student at that hour (Pipedrive\Client::push_digest).
		if ( Settings::on( 'cap_pipedrive_tasks' ) && 'alert' !== $task['type'] && '' === trim( (string) Settings::raw( 'pipedrive_digest_hour' ) ) && ! $reopened ) {
			Outbox::enqueue( 'pipedrive_activity', 'task', $task_id, 'pipedrive:' . $task['task_key'], array( 'task_id' => $task_id ) );
		}
	}

	public static function alert( string $key, string $title, string $body ): int {
		return self::open( 'alert:' . $key, 'alert', array( 'reason' => $title . ': ' . $body, 'priority' => 'high', 'due_days' => 0 ) );
	}

	public static function complete( int $id, string $note ): void {
		$t = Db::row( 'SELECT * FROM ' . Db::t( 'tasks' ) . ' WHERE id = %d', $id );
		if ( ! $t ) {
			throw new DomainError( 'not_found', 'המשימה לא נמצאה', 404 );
		}
		if ( 'card_update' === $t['type'] ) {
			// A financial task closes only through its evidence flow (§18): card tasks via CardTasks::confirm.
			throw new DomainError( 'use_card_flow', 'משימת כרטיס נסגרת רק באישור עדכון עם אסמכתה', 422 );
		}
		Db::update(
			'tasks',
			array(
				'status'          => 'done',
				'resolution_note' => mb_substr( $note, 0, 500 ),
				'resolved_by'     => get_current_user_id() ?: null,
				'resolved_at'     => Clock::utc(),
				'updated_at'      => Clock::utc(),
			),
			array( 'id' => $id )
		);
		Audit::log( 'task.complete', 'task', $id, null, null, $note );
	}

	public static function close_by_key( string $task_key, string $note ): void {
		Db::exec(
			'UPDATE ' . Db::t( 'tasks' ) . " SET status = 'done', resolution_note = %s, resolved_at = %s, updated_at = %s WHERE task_key = %s AND status = 'open'",
			mb_substr( $note, 0, 500 ),
			Clock::utc(),
			Clock::utc(),
			$task_key
		);
	}
}

<?php
namespace Insiders\Collections\Integrations;

use Insiders\Collections\Domain\Tasks;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Http;
use Insiders\Collections\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Internal alert emails: minimal identifying details and a link. Never card data or documents (§3, §18).
 * And the journey by email for a student without WhatsApp: the same text, the buttons as links.
 */
final class Mailer {

	/** WhatsApp wording in an email: "write to us here" means this email. "כאן צוות INSIDERS" stays. */
	public static function adapt( string $text ): string {
		$text = str_replace( 'כאן צוות', "\u{E000}", $text );
		$text = (string) preg_replace( '/(?<=\s)כאן(?=[\s.,!?:]|$)/u', 'במייל', $text );
		return str_replace( "\u{E000}", 'כאן צוות', $text );
	}

	private static function subject( string $template_key ): string {
		return array(
			'j_intro' => 'INSIDERS: התוכנית למתחילים ופתיחת החשבון',
			'j_t30'   => 'INSIDERS: תזכורת לגבי ההתחייבות בתוכנית',
			'j_t7'    => 'INSIDERS: נשאר שבוע עד המועד',
			'j_t0'    => 'INSIDERS: היום המועד האחרון בתוכנית',
			'l_intro' => 'INSIDERS: לגבי התוכנית למתחילים',
			'd_link'  => 'INSIDERS: פרטי התשלום על התוכנית',
			'd_t0'    => 'INSIDERS: היום המועד להסדרת התשלום',
		)[ preg_replace( '/_reply$/', '', $template_key ) ] ?? 'INSIDERS: עדכון מצוות התשלומים';
	}

	/** Outbox handler for a customer message by email (Messaging::record_outbound, channel email). */
	public static function deliver_customer( int $message_id ): array {
		$m = Db::row( 'SELECT * FROM ' . Db::t( 'messages' ) . ' WHERE id = %d', $message_id );
		if ( ! $m || 'queued' !== $m['delivery_state'] ) {
			return array( 'outcome' => Http::OK, 'result' => array( 'skipped' => $m['delivery_state'] ?? 'missing' ) );
		}
		$case = $m['case_id'] ? \Insiders\Collections\Domain\Workflow::get( (int) $m['case_id'] ) : null;
		$to   = \Insiders\Collections\Domain\Customers::journey_email( (int) $m['customer_id'] );
		$void = \Insiders\Collections\Integrations\Wati\Client::void_reason( $m, $case, 'email' ) ?: ( '' === $to ? 'no_email' : '' );
		if ( '' !== $void ) {
			Db::update( 'messages', array( 'delivery_state' => 'cancelled', 'updated_at' => Clock::utc() ), array( 'id' => $message_id ) );
			\Insiders\Collections\Domain\Journey::on_send_failed( $message_id, false ); // a step that did not go is not taken
			return array( 'outcome' => Http::OK, 'result' => array( 'voided' => $void ) );
		}
		$tpl     = \Insiders\Collections\Domain\Templates::get( (string) $m['template_key'] );
		$buttons = '';
		foreach ( array_values( (array) ( $tpl['buttons'] ?? array() ) ) as $i => $title ) {
			$buttons .= '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:8px 0"><tr><td style="background:#460FFF;border-radius:10px"><a href="' . esc_url( \Insiders\Collections\Front\ReplyPage::url( (string) $m['local_id'], $i ) ) . '" style="display:inline-block;padding:12px 22px;color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px">' . esc_html( $title ) . '</a></td></tr></table>';
		}
		$text = (string) preg_replace( '#(https://[^\s<]+)#', '<a href="$1" style="color:#460FFF">$1</a>', nl2br( esc_html( (string) $m['body'] ) ) );
		$html = '<div dir="rtl" style="font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.7;color:#0B1A33;max-width:560px;margin:0 auto;padding:8px;text-align:right">'
			. '<p style="margin:0 0 14px">' . $text . '</p>' . $buttons
			. '<p style="margin:18px 0 0;color:#5B6B85;font-size:13px">' . ( '' !== $buttons ? 'אפשר ללחוץ על אחד הכפתורים, או להשיב למייל הזה.' : 'אפשר להשיב למייל הזה.' ) . ' צוות INSIDERS</p></div>';
		$reply   = trim( (string) Settings::get( 'journey_email_reply_to' ) ) ?: trim( (string) Settings::get( 'alert_email' ) );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( is_email( $reply ) ) {
			$headers[] = 'Reply-To: ' . $reply;
		}
		$ok = wp_mail( $to, self::subject( (string) $m['template_key'] ), $html, $headers );
		if ( $ok ) {
			Db::update( 'messages', array( 'delivery_state' => 'accepted', 'updated_at' => Clock::utc() ), array( 'id' => $message_id ) );
		}
		return array( 'outcome' => $ok ? Http::OK : Http::RETRYABLE, 'error' => $ok ? '' : 'wp_mail returned false' );
	}

	public static function task_alert( int $task_id ): array {
		$t  = Db::row( 'SELECT * FROM ' . Db::t( 'tasks' ) . ' WHERE id = %d', $task_id );
		$to = (string) Settings::get( 'alert_email' );
		if ( ! $t || '' === $to ) {
			return array( 'outcome' => Http::PERMANENT, 'error' => 'no task or recipient' );
		}
		$label = Tasks::TYPES[ $t['type'] ]['label'] ?? $t['type'];
		$who   = $t['customer_id'] ? (string) Db::value( 'SELECT full_name FROM ' . Db::t( 'customers' ) . ' WHERE id = %d', (int) $t['customer_id'] ) : '';
		$body  = $label . ( '' !== $who ? "\nתלמיד/ה: " . $who : '' ) . ( $t['case_id'] ? ' (תיק #' . (int) $t['case_id'] . ')' : '' ) . "\n" . preg_replace( '/\b\d{8,}\b/', '•••', (string) $t['reason'] ) . "\n\nמועד יעד: " . Clock::display( $t['due_at'] ) . "\nלפתיחת המשימה: " . admin_url( 'admin.php?page=icol#/tasks/' . $task_id ) . ( $t['case_id'] ? "\nלתיק: " . admin_url( 'admin.php?page=icol#/cases/' . (int) $t['case_id'] ) : '' );
		$ok    = wp_mail( $to, '[INSIDERS תשלומים] ' . $label, $body );
		return array( 'outcome' => $ok ? Http::OK : Http::RETRYABLE, 'error' => $ok ? '' : 'wp_mail returned false' );
	}

	/**
	 * Weekly summary of every open task, grouped by the person it is assigned to. Same rules as the
	 * single alert: a label, a short reason with long numbers hidden, the due date and a link.
	 */
	public static function weekly_tasks(): array {
		$to = (string) Settings::get( 'weekly_tasks_email' );
		if ( ! is_email( $to ) ) {
			return array( 'sent' => false, 'error' => 'no recipient' );
		}
		$rows = Db::rows( 'SELECT * FROM ' . Db::t( 'tasks' ) . " WHERE status = 'open' ORDER BY assigned_to, due_at, id" );
		$now  = Clock::utc();
		$by   = array();
		foreach ( $rows as $t ) {
			$u = $t['assigned_to'] ? get_userdata( (int) $t['assigned_to'] ) : null;
			$by[ $u ? $u->display_name : 'בלי אחראי' ][] = $t;
		}
		$esc  = static fn( $s ) => esc_html( (string) $s );
		$late = count( array_filter( $rows, static fn( $t ) => $t['due_at'] && $t['due_at'] < $now ) );
		$html = '<div dir="rtl" style="font-family:Arial,sans-serif;font-size:14px;line-height:1.5">';
		$html .= '<p><strong>' . count( $rows ) . ' משימות פתוחות</strong>' . ( $late ? ', מתוכן ' . $late . ' אחרי מועד היעד' : '' ) . '.</p>';
		foreach ( $by as $who => $list ) {
			$html .= '<h3 style="margin:18px 0 6px">' . $esc( $who ) . ' (' . count( $list ) . ')</h3><ul style="padding-right:18px;margin:0">';
			foreach ( $list as $t ) {
				$overdue = $t['due_at'] && $t['due_at'] < $now;
				$html   .= '<li style="margin-bottom:8px"><a href="' . esc_url( admin_url( 'admin.php?page=icol#/tasks/' . (int) $t['id'] ) ) . '">' . $esc( Tasks::TYPES[ $t['type'] ]['label'] ?? $t['type'] ) . '</a>'
					. ' · יעד ' . $esc( Clock::display( $t['due_at'], 'd/m/Y' ) ) . ( $overdue ? ' <strong style="color:#b45309">(באיחור)</strong>' : '' )
					. '<br><span style="color:#555">' . $esc( mb_substr( preg_replace( '/\b\d{8,}\b/', '•••', (string) $t['reason'] ), 0, 220 ) ) . '</span></li>';
			}
			$html .= '</ul>';
		}
		if ( ! $rows ) {
			$html .= '<p>אין משימות פתוחות.</p>';
		}
		$html .= '<p style="margin-top:18px"><a href="' . esc_url( admin_url( 'admin.php?page=icol#/tasks' ) ) . '">כל המשימות במערכת</a></p></div>';
		$ok = wp_mail( $to, '[INSIDERS תשלומים] סיכום שבועי: ' . count( $rows ) . ' משימות פתוחות', $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
		return array( 'sent' => (bool) $ok, 'tasks' => count( $rows ), 'overdue' => $late );
	}
}

<?php
namespace Insiders\Collections\Integrations;

use Insiders\Collections\Domain\Tasks;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Http;
use Insiders\Collections\Support\Settings;

defined( 'ABSPATH' ) || exit;

/** Internal alert emails: minimal identifying details and a link. Never card data or documents (§3, §18). */
final class Mailer {
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

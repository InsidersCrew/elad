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
		$body  = $label . "\n" . preg_replace( '/\b\d{8,}\b/', '•••', (string) $t['reason'] ) . "\n\nמועד יעד: " . Clock::display( $t['due_at'] ) . "\nלפתיחת המשימה: " . admin_url( 'admin.php?page=icol#/tasks/' . $task_id );
		$ok    = wp_mail( $to, '[INSIDERS תשלומים] ' . $label, $body );
		return array( 'outcome' => $ok ? Http::OK : Http::RETRYABLE, 'error' => $ok ? '' : 'wp_mail returned false' );
	}
}

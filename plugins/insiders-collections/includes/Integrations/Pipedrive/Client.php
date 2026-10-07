<?php
namespace Insiders\Collections\Integrations\Pipedrive;

use Insiders\Collections\Domain\Customers;
use Insiders\Collections\Domain\Tasks;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Http;
use Insiders\Collections\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Pipedrive activities (§18) via POST /api/v2/activities. v2 links people through
 * "participants" (person_id is read-only there) and returns the id at data.id.
 * "Done" in Pipedrive never closes a financial task here. The subject carries a
 * marker so that after a timeout we look for the activity before creating another.
 */
final class Client {

	public static function configured(): bool {
		return Settings::has_secret( 'pipedrive_token' );
	}

	public static function push_task( int $task_id ): array {
		$task = Db::row( 'SELECT * FROM ' . Db::t( 'tasks' ) . ' WHERE id = %d', $task_id );
		if ( ! $task || ! self::configured() ) {
			return array( 'outcome' => Http::PERMANENT, 'error' => 'no task or not configured' );
		}
		$key    = 'task:' . $task['task_key'];
		$marker = '[icol:' . substr( md5( $key ), 0, 10 ) . ']';
		$row    = Db::row( 'SELECT * FROM ' . Db::t( 'integration_tasks' ) . " WHERE destination = 'pipedrive' AND internal_task_key = %s", $key );
		if ( $row && $row['external_task_id'] ) {
			return array( 'outcome' => Http::OK, 'result' => array( 'existing' => $row['external_task_id'] ) );
		}
		$customer = $task['customer_id'] ? Customers::get( (int) $task['customer_id'] ) : null;
		if ( $row && 'unknown' === $row['status'] ) {
			$found = self::find_by_marker( $marker, $customer );
			if ( $found ) {
				Db::update( 'integration_tasks', array( 'external_task_id' => $found, 'status' => 'created', 'updated_at' => Clock::utc() ), array( 'id' => (int) $row['id'] ) );
				return array( 'outcome' => Http::OK, 'result' => array( 'found' => $found ) );
			}
		}
		if ( ! $row ) {
			Db::insert( 'integration_tasks', array( 'destination' => 'pipedrive', 'internal_task_key' => $key, 'status' => 'pending', 'created_at' => Clock::utc(), 'updated_at' => Clock::utc() ) );
		}
		$owner = $task['assigned_to'] ? (int) get_user_meta( (int) $task['assigned_to'], 'icol_pipedrive_user_id', true ) : 0;
		$body  = array(
			'subject'  => mb_substr( ( Tasks::TYPES[ $task['type'] ]['label'] ?? $task['type'] ) . ' ' . $marker, 0, 250 ),
			'type'     => 'task',
			'due_date' => gmdate( 'Y-m-d', (int) Clock::ts( $task['due_at'] ) ),
			'note'     => 'משימה ממערכת הגבייה. פרטים במערכת: ' . admin_url( 'admin.php?page=icol#/tasks/' . $task_id ),
		);
		if ( $owner ) {
			$body['owner_id'] = $owner;
		}
		if ( $customer && ! empty( $customer['pipedrive_person_id'] ) ) {
			$body['participants'] = array( array( 'person_id' => (int) $customer['pipedrive_person_id'], 'primary' => true ) );
		}
		$r = Http::request( 'POST', rtrim( (string) Settings::get( 'pipedrive_api_base' ), '/' ) . '/api/v2/activities?api_token=' . rawurlencode( Settings::secret( 'pipedrive_token' ) ), array( 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $body ) ), true );
		$id = $r['json']['data']['id'] ?? null;
		Db::exec(
			'UPDATE ' . Db::t( 'integration_tasks' ) . " SET status = %s, external_task_id = %s, last_error = %s, updated_at = %s WHERE destination = 'pipedrive' AND internal_task_key = %s",
			Http::OK === $r['outcome'] && $id ? 'created' : ( Http::UNKNOWN === $r['outcome'] ? 'unknown' : 'error' ),
			$id ? (string) $id : '',
			mb_substr( (string) $r['error'], 0, 500 ),
			Clock::utc(),
			$key
		);
		if ( Http::UNKNOWN === $r['outcome'] ) {
			// Not an "unknown" outbox end-state: CRM writes are safe to retry once we looked for the marker.
			return array( 'outcome' => Http::RETRYABLE, 'error' => 'timeout — will look for the marker before retrying' );
		}
		return array( 'outcome' => $r['outcome'], 'error' => $r['error'], 'result' => array( 'id' => $id ) );
	}

	private static function find_by_marker( string $marker, ?array $customer ): ?string {
		$q = array( 'limit' => 50, 'sort_by' => 'add_time', 'sort_direction' => 'desc' );
		if ( $customer && ! empty( $customer['pipedrive_person_id'] ) ) {
			$q['person_id'] = (int) $customer['pipedrive_person_id'];
		}
		$q['api_token'] = Settings::secret( 'pipedrive_token' );
		$r = Http::request( 'GET', add_query_arg( $q, rtrim( (string) Settings::get( 'pipedrive_api_base' ), '/' ) . '/api/v2/activities' ) );
		foreach ( (array) ( $r['json']['data'] ?? array() ) as $a ) {
			if ( str_contains( (string) ( $a['subject'] ?? '' ), $marker ) ) {
				return (string) $a['id'];
			}
		}
		return null;
	}
}

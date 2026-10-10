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
		$deal = $task['case_id'] ? (int) Db::value( 'SELECT a.pipedrive_deal_id FROM ' . Db::t( 'cases' ) . ' c JOIN ' . Db::t( 'agreements' ) . ' a ON a.id = c.agreement_id WHERE c.id = %d', (int) $task['case_id'] ) : 0;
		if ( $deal ) {
			$body['deal_id'] = $deal; // the rep opens the enrollment deal straight from the activity
		}
		if ( '' !== (string) $task['reason'] ) {
			$body['note'] = mb_substr( (string) $task['reason'], 0, 1000 ) . "\n\n" . $body['note'];
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

	/**
	 * Contact details of one person. v2 returns emails[]/phones[] of {value, primary};
	 * v1 returned email[]/phone[] of the same shape, so both are read. The primary
	 * entry wins, then the first one.
	 */
	public static function person( int $person_id ): array {
		if ( ! self::configured() ) {
			return array( 'ok' => false, 'error' => 'Pipedrive לא מחובר' );
		}
		$r = Http::request( 'GET', rtrim( (string) Settings::get( 'pipedrive_api_base' ), '/' ) . '/api/v2/persons/' . $person_id . '?api_token=' . rawurlencode( Settings::secret( 'pipedrive_token' ) ) );
		$d = $r['json']['data'] ?? null;
		if ( Http::OK !== $r['outcome'] || ! is_array( $d ) ) {
			return array( 'ok' => false, 'error' => $r['error'] ?: 'no data' );
		}
		$pick = static function ( $list ) {
			$list = is_array( $list ) ? $list : array();
			foreach ( $list as $e ) {
				if ( is_array( $e ) && ! empty( $e['primary'] ) && ! empty( $e['value'] ) ) {
					return (string) $e['value'];
				}
			}
			foreach ( $list as $e ) {
				if ( is_array( $e ) && ! empty( $e['value'] ) ) {
					return (string) $e['value'];
				}
			}
			return '';
		};
		return array(
			'ok'         => true,
			'name'       => (string) ( $d['name'] ?? '' ),
			'first_name' => (string) ( $d['first_name'] ?? '' ),
			'email'      => strtolower( $pick( $d['emails'] ?? ( $d['email'] ?? array() ) ) ),
			'phone'      => $pick( $d['phones'] ?? ( $d['phone'] ?? array() ) ),
		);
	}

	private static function base(): string {
		return rtrim( (string) Settings::get( 'pipedrive_api_base' ), '/' );
	}

	/**
	 * Enrollment deals, 100 per call (v2 ?ids=). What the beginner program needs from
	 * each one: status + lost_reason (the dedicated "won't open" reason), the label ids
	 * ("no registration fee") and the owner (who calls the student back).
	 * v2 returns label_ids[]; v1 returned label as "1,2". Both are read.
	 *
	 * @return array{ok:bool,error?:string,deals:array<int,array>}
	 */
	public static function deals( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! self::configured() ) {
			return array( 'ok' => false, 'error' => 'Pipedrive לא מחובר', 'deals' => array() );
		}
		$out = array();
		foreach ( array_chunk( $ids, 100 ) as $chunk ) {
			$r = Http::request( 'GET', add_query_arg( array( 'ids' => implode( ',', $chunk ), 'limit' => 100, 'api_token' => Settings::secret( 'pipedrive_token' ) ), self::base() . '/api/v2/deals' ) );
			if ( Http::OK !== $r['outcome'] || ! is_array( $r['json']['data'] ?? null ) ) {
				return array( 'ok' => false, 'error' => $r['error'] ?: 'no data', 'deals' => $out );
			}
			foreach ( $r['json']['data'] as $d ) {
				if ( is_array( $d ) && ! empty( $d['id'] ) ) {
					$out[ (int) $d['id'] ] = self::deal_shape( $d );
				}
			}
		}
		return array( 'ok' => true, 'deals' => $out );
	}

	private static function deal_shape( array $d ): array {
		$labels = $d['label_ids'] ?? ( isset( $d['label'] ) && '' !== (string) $d['label'] ? explode( ',', (string) $d['label'] ) : array() );
		$owner  = $d['owner_id'] ?? ( $d['user_id'] ?? null );
		return array(
			'id'          => (int) $d['id'],
			'status'      => (string) ( $d['status'] ?? '' ),
			'lost_reason' => trim( (string) ( $d['lost_reason'] ?? '' ) ),
			'label_ids'   => array_values( array_filter( array_map( 'intval', (array) $labels ) ) ),
			'owner_id'    => is_array( $owner ) ? (int) ( $owner['id'] ?? 0 ) : (int) $owner,
			'person_id'   => is_array( $d['person_id'] ?? null ) ? (int) ( $d['person_id']['value'] ?? 0 ) : (int) ( $d['person_id'] ?? 0 ),
			'title'       => (string) ( $d['title'] ?? '' ),
		);
	}

	/**
	 * The id of a deal label by its name (a number in the setting is used as is).
	 * Fields API v2 first (dealFields/label), then the v1 list. Cached for a day.
	 */
	public static function label_id( string $name ): ?int {
		$name = trim( $name );
		if ( '' === $name ) {
			return null;
		}
		if ( ctype_digit( $name ) ) {
			return (int) $name;
		}
		$cache = get_option( 'icol_pd_label', array() );
		if ( is_array( $cache ) && ( $cache['name'] ?? '' ) === $name && ( $cache['at'] ?? 0 ) > time() - DAY_IN_SECONDS ) {
			return null === $cache['id'] ? null : (int) $cache['id'];
		}
		if ( ! self::configured() ) {
			return null;
		}
		$token   = Settings::secret( 'pipedrive_token' );
		$options = null;
		$r       = Http::request( 'GET', add_query_arg( array( 'api_token' => $token ), self::base() . '/api/v2/dealFields/label' ) );
		if ( Http::OK === $r['outcome'] && is_array( $r['json']['data']['options'] ?? null ) ) {
			$options = $r['json']['data']['options'];
		} else {
			$r = Http::request( 'GET', add_query_arg( array( 'api_token' => $token, 'limit' => 500 ), self::base() . '/v1/dealFields' ) );
			foreach ( (array) ( $r['json']['data'] ?? array() ) as $f ) {
				if ( 'label' === ( $f['key'] ?? ( $f['field_code'] ?? '' ) ) ) {
					$options = (array) ( $f['options'] ?? array() );
				}
			}
		}
		if ( null === $options ) {
			return null; // not cached: a transient failure must not hide the label for a day
		}
		$id = null;
		foreach ( $options as $o ) {
			if ( trim( (string) ( $o['label'] ?? '' ) ) === $name ) {
				$id = (int) $o['id'];
			}
		}
		update_option( 'icol_pd_label', array( 'name' => $name, 'id' => $id, 'at' => time() ), false );
		return $id;
	}

	/** WordPress user mapped to a Pipedrive user (team screen: icol_pipedrive_user_id). */
	public static function wp_user_for_owner( int $pd_user_id ): ?int {
		if ( $pd_user_id <= 0 ) {
			return null;
		}
		$users = get_users( array( 'meta_key' => 'icol_pipedrive_user_id', 'meta_value' => (string) $pd_user_id, 'number' => 1, 'fields' => 'ID' ) );
		return $users ? (int) $users[0] : null;
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

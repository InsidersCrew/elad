<?php
namespace Insiders\Collections\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Thin helpers over $wpdb. Every financial write goes through transaction()
 * so that payment, allocation and the cached balance land together or not at all.
 */
final class Db {

	public static function t( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'icol_' . $name;
	}

	/**
	 * Runs $fn inside a transaction. Rolls back on any Throwable and rethrows.
	 * Nested calls join the outer transaction (MySQL has no real nesting).
	 */
	public static function transaction( callable $fn ) {
		global $wpdb;
		static $depth = 0;
		if ( $depth > 0 ) {
			++$depth;
			try {
				return $fn();
			} finally {
				--$depth;
			}
		}
		$wpdb->query( 'START TRANSACTION' );
		$depth = 1;
		try {
			$result = $fn();
			$wpdb->query( 'COMMIT' );
			return $result;
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		} finally {
			$depth = 0;
		}
	}

	/** Insert and return the new id, or throw — a silent false here becomes a lost payment. */
	public static function insert( string $table, array $row ): int {
		global $wpdb;
		$ok = $wpdb->insert( self::t( $table ), $row );
		if ( false === $ok ) {
			throw new DbError( 'insert ' . $table . ': ' . $wpdb->last_error, self::is_duplicate( $wpdb->last_error ) );
		}
		return (int) $wpdb->insert_id;
	}

	public static function update( string $table, array $data, array $where ): int {
		global $wpdb;
		$ok = $wpdb->update( self::t( $table ), $data, $where );
		if ( false === $ok ) {
			throw new DbError( 'update ' . $table . ': ' . $wpdb->last_error );
		}
		return (int) $ok;
	}

	public static function row( string $sql, ...$args ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $args ? $wpdb->prepare( $sql, ...$args ) : $sql, ARRAY_A );
		return $row ?: null;
	}

	public static function rows( string $sql, ...$args ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $args ? $wpdb->prepare( $sql, ...$args ) : $sql, ARRAY_A );
		return $rows ?: array();
	}

	public static function value( string $sql, ...$args ) {
		global $wpdb;
		return $wpdb->get_var( $args ? $wpdb->prepare( $sql, ...$args ) : $sql );
	}

	public static function exec( string $sql, ...$args ): int {
		global $wpdb;
		$res = $wpdb->query( $args ? $wpdb->prepare( $sql, ...$args ) : $sql );
		if ( false === $res ) {
			throw new DbError( 'query failed: ' . $wpdb->last_error );
		}
		return (int) $res;
	}

	public static function is_duplicate( string $error ): bool {
		return false !== stripos( $error, 'Duplicate entry' );
	}

	/** IN (...) placeholder list for prepare(). */
	public static function in( array $values, string $ph = '%d' ): string {
		return implode( ',', array_fill( 0, max( 1, count( $values ) ), $ph ) );
	}
}

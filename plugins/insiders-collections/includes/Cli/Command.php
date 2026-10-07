<?php
namespace Insiders\Collections\Cli;

use Insiders\Collections\Engine\Runner;
use Insiders\Collections\Schema;

defined( 'ABSPATH' ) || exit;

/** wp icol tick | wp icol health | wp icol install */
final class Command {
	/** Run one tick (use from a server cron every minute). */
	public function tick() {
		\WP_CLI::line( wp_json_encode( Runner::tick( 'wp-cli' ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
	}

	/** Print job heartbeats and schema state. */
	public function health() {
		\WP_CLI::line( wp_json_encode( array( 'beats' => Runner::beats(), 'schema' => Schema::verify() ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
	}

	/** Create/verify tables (idempotent). */
	public function install() {
		\WP_CLI::line( wp_json_encode( Schema::install(), JSON_PRETTY_PRINT ) );
	}
}

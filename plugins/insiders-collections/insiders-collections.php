<?php
/**
 * Plugin Name:       INSIDERS Collections — מערכת גבייה
 * Description:       ניהול גבייה לתלמידי INSIDERS: חיובים חוזרים שנכשלו בטרנזילה, חיובי אי־פתיחת חשבון והמשך טיפול. כל הפעולות החיצוניות כבויות עד לאימות.
 * Version:           0.2.1
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            INSIDERS
 * Text Domain:       insiders-collections
 */

defined( 'ABSPATH' ) || exit;

/*
 * The version lives in two places by WordPress design: the header above and
 * this constant. Plugin::version_guard() compares them on every admin load and
 * raises a notice when they drift — that drift cost a release in SPD.
 */
define( 'ICOL_VERSION', '0.2.1' );
define( 'ICOL_DB_VERSION', 2 );
define( 'ICOL_FILE', __FILE__ );
define( 'ICOL_DIR', plugin_dir_path( __FILE__ ) );
define( 'ICOL_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'Insiders\\Collections\\';
		if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$path     = ICOL_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( 'Insiders\\Collections\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Insiders\\Collections\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Insiders\\Collections\\Plugin', 'boot' ) );

/**
 * Public read API for other INSIDERS plugins (revenue / P&L dashboard).
 * Returns collection outcomes for a period without exposing customer data.
 *
 * @param string $from_date Y-m-d (business timezone, inclusive).
 * @param string $to_date   Y-m-d (business timezone, inclusive).
 * @return array<string,mixed>
 */
function icol_get_collection_summary( $from_date, $to_date ) {
	return \Insiders\Collections\Integrations\RevenueDashboard\Adapter::collection_summary( (string) $from_date, (string) $to_date );
}

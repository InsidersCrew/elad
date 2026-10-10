<?php
namespace Insiders\Collections\Support;

defined( 'ABSPATH' ) || exit;

/**
 * One option row holds all plugin settings. get() treats '' like missing:
 * get_option()'s default applies only when the row is absent, and a settings
 * form that saves an empty field turns the default off forever.
 *
 * Secrets (API keys) are stored encrypted when ICOL_ENCRYPTION_KEY is defined;
 * otherwise they may come from wp-config constants only.
 */
final class Settings {
	private const OPTION = 'icol_settings';
	private static ?array $cache = null;

	/** Capability switches. Every external effect starts OFF. */
	public const DEFAULTS = array(
		'business_timezone'           => 'Asia/Jerusalem',
		'kill_switch'                 => 1,   // all customer sends stopped
		'display_only'                => 1,   // record what would have happened, change nothing outside
		'cap_customer_sends'          => 0,
		'cap_ai_replies'              => 0,
		'cap_ai_suggestions'          => 0,
		'cap_payment_links'           => 0,
		'cap_separate_payment_recurring' => 0, // §8: never on before Tranzila confirms retry suppression
		'cap_card_auto_update'        => 0,   // no verified API exists
		'cap_pipedrive_tasks'         => 0,
		'cap_email_alerts'            => 0,
		'tranzila_terminals'          => 'insiders,insiderstok',
		'tranzila_my_billing_terminal' => 'insiderstok',
		// My Billing notifications carry no cycle/installment id (verified 2026-10). Strategies:
		// manual   = every event needs a person to map it (safe default, AT05)
		// schedule = derive the cycle from the STO schedule (first_charge_date/charge_dom); ambiguity -> manual
		// field    = a payload field confirmed by Tranzila identifies the cycle
		'tranzila_cycle_strategy'     => 'manual',
		'tranzila_cycle_field'        => '',
		'tranzila_charge_tranmodes'   => 'A',  // tranmode prefixes that are real charges; J* = verification, C = credit
		'tranzila_card_fix_email'     => 1,   // Tranzila emails the customer a card-fix link by itself (on by default)
		'tranzila_pr_match_field'     => '',  // Notify field that carries pr_id; unconfirmed until a sample arrives
		'wati_channel_number'         => '',
		'fallback_owner_id'           => 0,
		'tranzila_inbound_verified'   => 0,   // inbound notification authenticity confirmed with Tranzila
		'tranzila_api_base'           => 'https://api.tranzila.com',
		'tranzila_report_base'        => 'https://report.tranzila.com',
		'wati_api_base'               => '',
		'wati_conversation_attr'      => 'conversation_owner',
		'pipedrive_api_base'          => 'https://api.pipedrive.com',
		'ai_model'                    => 'claude-opus-5-5',
		'ai_effort'                   => 'low',
		'alert_email'                 => '',
		'support_whatsapp'            => '',
		'pay_base_path'               => 'pay',
		'pay_theme'                   => 'dark',  // designer: dark by default (recognition builds trust); 'light' for an A/B test
		'accessibility_url'           => '',      // the site's accessibility statement; the pay page links to it when set
		'reconcile_stale_minutes'     => 30,
		'card_task_due_business_days' => 1,
		'revenue_meta_joined'         => '',
		'revenue_meta_deadline'       => '',
		'revenue_meta_opened'         => '',
		'revenue_meta_extension'      => '',
		'revenue_candidate_horizon_days' => 7,
		'revenue_max_staleness_hours'    => 30,
		// Beginner program: the pre-deadline journey and the non-open charge (procedure of 2026-10).
		'journey_enabled'                => 0,   // off until the templates are approved by Meta
		'journey_contact_basis'          => '',  // the clause in the enrollment agreement that allows WhatsApp contact
		'journey_start_days'             => 45,  // first message N days before the deadline
		'journey_min_gap_days'           => 3,   // a late joiner never gets two steps closer than this (the deadline day excepted)
		'journey_batch'                  => 40,  // students enrolled per run (each one is a Pipedrive call)
		'late_grace_days'                => 7,   // past-deadline students: days after the first message before the charge can be approved
		'credit_window_days'             => 90,  // an account opened this many days after paying earns a credit
		'program_price_table'            => '2026-01-01 980 100', // per line: effective-from date, full price, registration fee (an earlier agreement gets the first line)
		'no_registration_label'          => 'ללא דמי רישום',
		'declined_lost_reasons'          => 'לא מעוניין לפתוח חשבון',
		'program_auto_charge_on_pay_request' => 0, // "אני רוצה לשלם": approve and send the link at once, without a person
		'gate_idle_minutes'              => 30,  // scan again after 30 minutes without activity
		'gate_session_hours'             => 8,   // and at most once per working day
	);

	public static function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = is_array( $stored ) ? $stored : array();
		}
		return self::$cache;
	}

	public static function get( string $key, $default = null ) {
		$all = self::all();
		if ( array_key_exists( $key, $all ) && '' !== $all[ $key ] && null !== $all[ $key ] ) {
			return $all[ $key ];
		}
		if ( null !== $default ) {
			return $default;
		}
		return self::DEFAULTS[ $key ] ?? null;
	}

	public static function on( string $key ): bool {
		return (int) self::get( $key, self::DEFAULTS[ $key ] ?? 0 ) === 1;
	}

	public static function set( array $values ): void {
		$all = self::all();
		foreach ( $values as $k => $v ) {
			$all[ $k ] = $v;
		}
		update_option( self::OPTION, $all, false );
		self::$cache = $all;
	}

	public static function flush(): void {
		self::$cache = null;
	}

	/** Secrets: wp-config constant wins (ICOL_SECRET_<NAME>), else encrypted option. */
	/**
	 * Path secrets (webhook URL segments, cron key) identify an endpoint; they are
	 * already visible in provider dashboards, so they may be stored without the
	 * encryption key. API keys may not.
	 */
	public const PATH_SECRETS = array( 'tranzila_webhook_secret', 'wati_webhook_secret', 'cron_key' );

	public static function secret( string $name ): string {
		$const = 'ICOL_SECRET_' . strtoupper( $name );
		if ( defined( $const ) && '' !== (string) constant( $const ) ) {
			return (string) constant( $const );
		}
		$enc = (string) get_option( 'icol_secret_' . $name, '' );
		if ( '' === $enc ) {
			return '';
		}
		if ( str_starts_with( $enc, 'plain:' ) && in_array( $name, self::PATH_SECRETS, true ) ) {
			return substr( $enc, 6 );
		}
		return (string) Crypto::decrypt( $enc );
	}

	public static function set_secret( string $name, string $value ): bool {
		if ( '' === $value ) {
			delete_option( 'icol_secret_' . $name );
			return true;
		}
		if ( ! Crypto::available() ) {
			if ( in_array( $name, self::PATH_SECRETS, true ) ) {
				update_option( 'icol_secret_' . $name, 'plain:' . $value, false );
				return true;
			}
			return false;
		}
		update_option( 'icol_secret_' . $name, Crypto::encrypt( $value ), false );
		return true;
	}

	public static function has_secret( string $name ): bool {
		return '' !== self::secret( $name );
	}
}

<?php
namespace Insiders\Collections\Domain;

use Insiders\Collections\Engine\Scheduler;
use Insiders\Collections\Integrations\Pipedrive\Client as Pipedrive;
use Insiders\Collections\Integrations\RevenueDashboard\Adapter;
use Insiders\Collections\Integrations\RevenueDashboard\FinanceDashboard;
use Insiders\Collections\Support\Audit;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Phone;
use Insiders\Collections\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The beginner program before the deadline (procedure of 2026-10).
 *
 * A student who signed and has not opened an account enters a "commitment" phase
 * case: no debt exists yet, only a deadline. Messages are anchored to that
 * deadline (T-45, 30, 7 and the day itself; 14 and 3 were dropped by INSIDERS on 2026-10-10 as too many), not to a cadence. Three tracks:
 *  - reach:    the procedure's flow, with three quick-reply buttons;
 *  - declined: the student said they will not open (button, or the dedicated lost
 *              reason on the Pipedrive deal). No more account nudges; the payment
 *              details once a person approved the charge;
 *  - late:     the deadline passed before the student entered (joined before 1.8,
 *              or the journey was switched on late). One message, then approval.
 *
 * Nothing here decides that a student owes money: when the deadline passes, the
 * case waits in the approval queue (ProgramCharges) for a person.
 */
final class Journey {

	public const BTN_OPEN     = 'אני רוצה לפתוח חשבון';
	public const BTN_QUESTION = 'יש לי שאלה';
	public const BTN_DECLINE  = 'לא אפתח חשבון';

	/** Reach-track steps: template key => days before the deadline. */
	public const REACH = array(
		'j_intro' => 45,
		'j_t30'   => 30,
		'j_t7'    => 7,
		'j_t0'    => 0,
	);

	public const TRACKS = array(
		'reach'    => 'ליווי לפני המועד',
		'declined' => 'הודיע שלא יפתח חשבון',
		'late'     => 'המועד עבר לפני הכניסה',
	);

	public const STEP_LABELS = array(
		'j_intro' => 'פתיחה (45 יום לפני)',
		'j_t30'   => '30 יום לפני',
		'j_t7'    => 'שבוע לפני',
		'j_t0'    => 'יום המועד',
		'd_link'  => 'פרטי תשלום למי שסירב',
		'd_t0'    => 'יום המועד, מי שסירב',
		'l_intro' => 'המועד עבר',
	);

	public static function enabled(): bool {
		return Settings::on( 'journey_enabled' );
	}

	public static function agreement( array $case ): ?array {
		return $case['agreement_id'] ? Db::row( 'SELECT * FROM ' . Db::t( 'agreements' ) . ' WHERE id = %d', (int) $case['agreement_id'] ) : null;
	}

	/* ------------------------------------------------------------------ sync */

	/**
	 * Hourly. Enroll, read the deals, close what resolved, move past-deadline approved
	 * cases to the charge phase, and plan whatever has nothing planned.
	 * A stale finance dashboard enrolls nobody and closes nobody.
	 */
	public static function sync(): array {
		if ( ! FinanceDashboard::available() ) {
			return array( 'enabled' => self::enabled(), 'error' => 'finance_dashboard_missing' );
		}
		$ct = FinanceDashboard::contract();
		if ( $ct['stale'] ) {
			if ( self::enabled() ) {
				Exceptions::open( 'ifd_stale:' . Clock::today(), 'integration_failure', 'medium', 'דשבורד ההכנסות לא סנכרן את פייפדרייב ' . ( null === $ct['sync_age_hours'] ? '(אין סנכרון מוצלח)' : $ct['sync_age_hours'] . ' שעות' ) . '. תלמידים חדשים לא נקלטים לליווי, והודעות הליווי מושהות, עד שהסנכרון יתעדכן.', array() );
			}
			return array( 'enabled' => self::enabled(), 'stale' => true );
		}
		// The switch gates what reaches students (enrolment and sends). Closing a case whose
		// student opened an account, and moving approved cases on, is protective and always runs.
		$out = array( 'enabled' => self::enabled(), 'stale' => false );
		if ( self::enabled() ) {
			$out['enrolled'] = self::enroll_due();
		}
		$out['imported']  = ProgramCharges::enroll_imported( max( 1, (int) Settings::get( 'journey_batch' ) ) );
		$out['deals']     = self::refresh_deals();
		$out['resolved']  = self::resolve();
		$out['to_charge'] = self::phase_switch();
		$out['planned']   = self::enabled() ? self::plan_idle() : 0;
		return $out;
	}

	/**
	 * Cases with nothing planned get their next step. A step that was cancelled for a
	 * reason that will not change within the hour (a missing value, identity, an opt-out)
	 * waits 20 hours before another try, instead of a cancel/replan churn every run.
	 */
	private static function plan_idle(): int {
		$idle = Db::rows(
			'SELECT c.id FROM ' . Db::t( 'cases' ) . " c WHERE c.phase = 'commitment' AND c.workflow_state IN ('active','waiting_reply')
			 AND NOT EXISTS (SELECT 1 FROM " . Db::t( 'scheduled_actions' ) . " s WHERE s.case_id = c.id AND s.state IN ('pending','claimed'))
			 AND NOT EXISTS (SELECT 1 FROM " . Db::t( 'scheduled_actions' ) . " s WHERE s.case_id = c.id AND s.type = 'send_journey' AND s.state = 'cancelled' AND s.updated_at > %s
			   AND s.result LIKE 'cancel:%%' AND s.result NOT IN ('cancel:stale_step','cancel:phase_changed','cancel:ifd_stale','cancel:disabled','cancel:state','cancel:case_missing'))",
			Clock::utc( Clock::now() - 20 * HOUR_IN_SECONDS )
		);
		foreach ( $idle as $c ) {
			self::plan_next( (int) $c['id'] );
		}
		return count( $idle );
	}

	/** Unresolved commitments whose deadline is within the start window (or already passed). */
	private static function enroll_due(): array {
		if ( ! Pipedrive::configured() ) {
			return array( 'error' => 'pipedrive_required' );
		}
		$until = gmdate( 'Y-m-d', strtotime( Clock::today() . ' UTC' ) + (int) Settings::get( 'journey_start_days' ) * DAY_IN_SECONDS );
		$rows  = array_values( array_filter( array_map( array( Adapter::class, 'normalize' ), FinanceDashboard::unresolved_until( $until ) ) ) );
		$taken = array();
		foreach ( Db::rows( 'SELECT candidate_key, status, snapshot FROM ' . Db::t( 'program_candidates' ) ) as $pc ) {
			$taken[ $pc['candidate_key'] ] = $pc;
		}
		$limit = max( 1, (int) Settings::get( 'journey_batch' ) );
		$n     = 0;
		$fail  = 0;
		$skip  = 0;
		foreach ( $rows as $r ) {
			$key = Adapter::candidate_key( $r );
			$pc  = $taken[ $key ] ?? null;
			if ( $pc && ! in_array( $pc['status'], array( 'new', 'enrolling' ), true ) ) {
				continue; // already in the journey, drafted by hand, dismissed or resolved
			}
			if ( empty( $r['effective_deadline'] ) || ( $pc && self::in_backoff( $pc ) ) ) {
				++$skip; // no usable deadline, or contact details failed three times today: not this run
				continue;
			}
			if ( $n + $fail >= $limit ) {
				break;
			}
			$track = $r['effective_deadline'] < Clock::today() ? 'late' : 'reach';
			$id    = self::enroll( $r, $track, 'finance_dashboard' );
			null === $id ? ++$fail : ++$n;
		}
		return array( 'candidates' => count( $rows ), 'enrolled' => $n, 'failed' => $fail, 'skipped' => $skip );
	}

	/** Three failed contact reads today: the person is tried once a day from now on. */
	private static function in_backoff( array $pc ): bool {
		$snap = (array) json_decode( (string) ( $pc['snapshot'] ?? '' ), true );
		return (int) ( $snap['enrich_attempts'] ?? 0 ) >= 3 && ( Clock::ts( $snap['enrich_last_at'] ?? null ) ?? 0 ) > Clock::now() - DAY_IN_SECONDS;
	}

	/**
	 * One student into the commitment phase. $r is a normalized candidate row (Adapter::normalize)
	 * or an imported one with the same keys. Returns the case id, or null when the contact
	 * details could not be read yet (retried next run; after three failures an exception).
	 */
	public static function enroll( array $r, string $track, string $source ): ?int {
		$person = (int) ( $r['pipedrive_person_id'] ?? 0 );
		$deal   = (int) ( $r['pipedrive_deal_id'] ?? 0 );
		$key    = Adapter::candidate_key( $r );
		$pc     = Db::row( 'SELECT * FROM ' . Db::t( 'program_candidates' ) . ' WHERE candidate_key = %s', $key );
		if ( $pc && ! in_array( $pc['status'], array( 'new', 'enrolling' ), true ) ) {
			return null;
		}
		if ( empty( $r['effective_deadline'] ) ) {
			self::candidate_row( $pc, $r, $key, 'invalid', $r, null, $source );
			Exceptions::open( 'journey_deadline:' . $key, 'integration_failure', 'medium', 'תלמיד בתוכנית ללא מועד תקין (איש קשר ' . $person . ', דיל ' . $deal . '), לא נכנס לליווי', array() );
			return null;
		}
		$existing = $deal ? (int) Db::value( 'SELECT c.id FROM ' . Db::t( 'cases' ) . ' c JOIN ' . Db::t( 'agreements' ) . " a ON a.id = c.agreement_id WHERE a.pipedrive_deal_id = %d AND c.workflow_state <> 'closed'", $deal ) : 0;
		if ( $existing ) {
			// Someone already opened a case for this enrollment deal by hand: recorded once, never retried.
			self::candidate_row( $pc, $r, $key, 'drafted', (array) ( $pc ? json_decode( (string) $pc['snapshot'], true ) : $r ), $existing, $source );
			return null;
		}
		// The fresh row wins; what an earlier run already learned (contact details) fills its gaps.
		$snap = $r;
		foreach ( ( $pc ? (array) json_decode( (string) $pc['snapshot'], true ) : array() ) as $k => $v ) {
			if ( ( ! isset( $snap[ $k ] ) || '' === $snap[ $k ] || null === $snap[ $k ] ) && null !== $v && '' !== $v ) {
				$snap[ $k ] = $v;
			}
		}
		if ( $person && empty( $snap['enriched_at'] ) && empty( $snap['phone'] ) ) {
			if ( $pc && self::in_backoff( $pc ) ) {
				return null;
			}
			$p = Pipedrive::person( $person );
			if ( ! $p['ok'] ) {
				$snap['enrich_error']    = $p['error'];
				$snap['enrich_last_at']  = Clock::utc();
				$snap['enrich_attempts'] = (int) ( $snap['enrich_attempts'] ?? 0 ) + 1;
				self::candidate_row( $pc, $r, $key, 'enrolling', $snap, null, $source );
				if ( $snap['enrich_attempts'] >= 3 ) {
					Exceptions::open( 'journey_contact:' . $key, 'integration_failure', 'medium', 'לא הצלחנו למשוך מפייפדרייב פרטי קשר של תלמיד בתוכנית (איש קשר ' . $person . '), ולכן הוא לא נכנס לליווי', array( 'details' => array( 'error' => $p['error'] ) ) );
				}
				return null;
			}
			$snap = array_merge( $snap, array( 'name' => $p['name'] ?: ( $snap['name'] ?? '' ), 'first_name' => $p['first_name'], 'phone' => $p['phone'], 'email' => $p['email'], 'enriched_at' => Clock::utc() ) );
			unset( $snap['enrich_error'] );
			if ( (int) ( $snap['enrich_attempts'] ?? 0 ) >= 3 ) {
				Exceptions::resolve_by_key( 'journey_contact:' . $key, 'פרטי הקשר נמשכו מפייפדרייב' );
			}
		}
		return Db::transaction(
			function () use ( $pc, $r, $key, $snap, $track, $source, $person, $deal ) {
				$cid = $person ? (int) Db::value( 'SELECT id FROM ' . Db::t( 'customers' ) . ' WHERE pipedrive_person_id = %d ORDER BY id LIMIT 1', $person ) : 0;
				$e164 = Phone::e164( (string) ( $snap['phone'] ?? '' ) );
				if ( ! $cid && $e164 ) {
					// The same student may already exist from a failed charge (Tranzila) without a Pipedrive id:
					// one customer on that number, no person attached, is that student, not a second record.
					$same = Db::rows( 'SELECT id, pipedrive_person_id FROM ' . Db::t( 'customers' ) . ' WHERE phone_e164 = %s', $e164 );
					if ( 1 === count( $same ) && empty( $same[0]['pipedrive_person_id'] ) ) {
						$cid = (int) $same[0]['id'];
						if ( $person ) {
							Db::update( 'customers', array( 'pipedrive_person_id' => $person, 'updated_at' => Clock::utc() ), array( 'id' => $cid ) );
							Audit::log( 'customer.link_person', 'customer', $cid, null, array( 'pipedrive_person_id' => $person ), 'זוהה לפי מספר הטלפון בכניסה לליווי' );
						}
					}
				}
				if ( ! $cid ) {
					$first = trim( (string) ( $snap['first_name'] ?? '' ) );
					if ( '' === $first && '' !== trim( (string) ( $snap['name'] ?? '' ) ) ) {
						$first = (string) preg_split( '/\s+/u', trim( (string) $snap['name'] ) )[0]; // an imported row has the full name only
					}
					$phone = (string) ( $snap['phone'] ?? '' );
					$cid   = Customers::create(
						array(
							'pipedrive_person_id' => $person ?: null,
							'full_name'           => trim( (string) ( $snap['name'] ?? '' ) ) ?: 'תלמיד',
							'first_name'          => $first,
							'first_name_reliable' => '' !== $first && (bool) preg_match( '/^[\p{L}\'\- ]{2,30}$/u', $first ),
							'email'               => (string) ( $snap['email'] ?? '' ),
							'phone'               => null !== Phone::e164( $phone ) ? $phone : '',
							// The rep enrolled the student on this number; the basis is recorded once, in settings.
							'phone_verified'      => '' !== trim( (string) Settings::get( 'journey_contact_basis' ) ),
						)
					);
					$c = Customers::get( $cid );
					if ( Customers::shares_phone( $c ) ) {
						Db::update( 'customers', array( 'contact_status' => 'unverified' ), array( 'id' => $cid ) );
						Exceptions::open( 'identity:' . $cid, 'identity_conflict', 'medium', 'מספר הטלפון של תלמיד בתוכנית משותף לעוד לקוח, נדרשת התאמת זהות לפני פנייה', array( 'customer_id' => $cid ) );
					}
				}
				$basis = trim( (string) Settings::get( 'journey_contact_basis' ) );
				if ( '' !== $basis && null === Customers::permission( $cid, 'whatsapp' ) ) {
					Customers::set_permission( $cid, 'whatsapp', true, 'program_agreement: ' . $basis ); // never overrides an opt-out
				}
				$signed = $snap['joined_at'] ?? null;
				$q      = Pricing::quote( $signed, ! empty( $snap['no_registration_fee'] ) );
				$agr    = Db::insert(
					'agreements',
					array(
						'customer_id'           => $cid,
						'type'                  => 'beginner_program',
						'program'               => 'תוכנית ליווי למתחילים',
						'reference'             => $deal ? 'Pipedrive deal ' . $deal : mb_substr( (string) ( $snap['agreement_ref'] ?? 'ייבוא' ), 0, 190 ),
						'signed_at'             => $signed,
						'joined_at'             => $signed,
						'account_open_deadline' => $snap['effective_deadline'],
						'status_checked_at'     => Clock::today(),
						'pipedrive_deal_id'     => $deal ?: null,
						'price_total_minor'     => $q['total'] ?? null,
						'fee_credit_minor'      => $q['credit'] ?? null,
						'no_registration_fee'   => ! empty( $snap['no_registration_fee'] ) ? 1 : 0,
						'created_at'            => Clock::utc(),
						'updated_at'            => Clock::utc(),
					)
				);
				$case_key = 'agr:' . $agr;
				$case_id  = Db::insert(
					'cases',
					array(
						'customer_id'         => $cid,
						'agreement_id'        => $agr,
						'case_key'            => $case_key,
						'active_key'          => $case_key,
						'source_type'         => 'non_open_charge',
						'entry_mode'          => 'new',
						'phase'               => 'commitment',
						'track'               => $track,
						'workflow_state'      => 'active',
						'currency'            => 'ILS',
						'activated_at'        => Clock::utc(),
						'sequence_started_at' => Clock::utc(),
						'created_at'          => Clock::utc(),
						'updated_at'          => Clock::utc(),
					)
				);
				self::candidate_row( $pc, $r, $key, 'journey', $snap, $case_id, $source );
				Audit::log( 'journey.enroll', 'case', $case_id, null, array( 'track' => $track, 'deal' => $deal, 'deadline' => $snap['effective_deadline'], 'source' => $source ), '' );
				self::plan_next( $case_id );
				return $case_id;
			}
		);
	}

	private static function candidate_row( ?array $pc, array $r, string $key, string $status, array $snap, ?int $case_id, string $source ): void {
		$data = array(
			'status'     => $status,
			'snapshot'   => wp_json_encode( $snap, JSON_UNESCAPED_UNICODE ),
			'case_id'    => $case_id,
			'updated_at' => Clock::utc(),
		);
		if ( $pc ) {
			Db::update( 'program_candidates', $data, array( 'id' => (int) $pc['id'] ) );
			return;
		}
		Db::insert(
			'program_candidates',
			array_merge(
				$data,
				array(
					'source'              => $source,
					'pipedrive_person_id' => ! empty( $r['pipedrive_person_id'] ) ? (int) $r['pipedrive_person_id'] : null,
					'pipedrive_deal_id'   => ! empty( $r['pipedrive_deal_id'] ) ? (int) $r['pipedrive_deal_id'] : null,
					'wp_user_id'          => ! empty( $r['wp_user_id'] ) ? (int) $r['wp_user_id'] : null,
					'candidate_key'       => $key,
					'deadline'            => $r['effective_deadline'] ?? null,
					'created_at'          => Clock::utc(),
				)
			)
		);
	}

	/**
	 * Reads every open beginner-program deal from Pipedrive (100 per call): the
	 * "no registration fee" label sets the amount, the dedicated lost reason moves the
	 * student to the declined track, and the deal owner becomes the case owner.
	 */
	public static function refresh_deals(): array {
		$rows = Db::rows( 'SELECT c.id AS case_id, c.owner_id, c.track, c.workflow_state, a.* FROM ' . Db::t( 'cases' ) . ' c JOIN ' . Db::t( 'agreements' ) . " a ON a.id = c.agreement_id WHERE c.source_type = 'non_open_charge' AND c.workflow_state <> 'closed' AND a.pipedrive_deal_id IS NOT NULL AND (c.phase = 'commitment' OR NOT EXISTS (SELECT 1 FROM " . Db::t( 'debt_items' ) . ' d WHERE d.case_id = c.id AND d.approved_at IS NOT NULL))' );
		if ( ! $rows || ! Pipedrive::configured() ) {
			return array( 'deals' => 0 );
		}
		$res = Pipedrive::deals( array_column( $rows, 'pipedrive_deal_id' ) );
		if ( ! $res['ok'] && ! $res['deals'] ) {
			return array( 'deals' => 0, 'error' => $res['error'] ?? '' );
		}
		$label   = Pipedrive::label_id( (string) Settings::get( 'no_registration_label' ) );
		$reasons = self::declined_reasons();
		$declined = 0;
		foreach ( $rows as $r ) {
			$d = $res['deals'][ (int) $r['pipedrive_deal_id'] ] ?? null;
			if ( ! $d ) {
				continue;
			}
			// The label id comes from a separate Pipedrive call; when that failed, what was stored stays.
			$no_fee = null === $label ? (bool) (int) $r['no_registration_fee'] : in_array( $label, $d['label_ids'], true );
			$q      = Pricing::quote( $r['signed_at'] ?: null, $no_fee );
			Db::update(
				'agreements',
				array(
					'deal_status'         => mb_substr( $d['status'], 0, 12 ),
					'lost_reason'         => '' !== $d['lost_reason'] ? mb_substr( $d['lost_reason'], 0, 190 ) : null,
					'no_registration_fee' => $no_fee ? 1 : 0,
					'price_total_minor'   => $q['total'] ?? null,
					'fee_credit_minor'    => $q['credit'] ?? null,
					'deal_checked_at'     => Clock::utc(),
					'updated_at'          => Clock::utc(),
				),
				array( 'id' => (int) $r['id'] )
			);
			if ( ! $r['owner_id'] ) {
				$owner = Pipedrive::wp_user_for_owner( (int) $d['owner_id'] );
				if ( $owner ) {
					Db::update( 'cases', array( 'owner_id' => $owner ), array( 'id' => (int) $r['case_id'] ) );
				}
			}
			if ( 'lost' === $d['status'] && 'declined' !== $r['track'] && in_array( self::norm( $d['lost_reason'] ), $reasons, true ) ) {
				self::decline( (int) $r['case_id'], 'pipedrive', 'הדיל סומן lost בפייפדרייב: ' . $d['lost_reason'] );
				++$declined;
			}
		}
		return array( 'deals' => count( $res['deals'] ), 'declined' => $declined, 'label_id' => $label );
	}

	private static function norm( string $s ): string {
		return preg_replace( '/\s+/u', ' ', trim( mb_strtolower( $s ) ) );
	}

	public static function declined_reasons(): array {
		return array_values( array_filter( array_map( array( self::class, 'norm' ), preg_split( '/\R/', (string) Settings::get( 'declined_lost_reasons' ) ) ) ) );
	}

	/**
	 * A commitment-phase student who opened an account (or whose charge was recorded
	 * as paid in Pipedrive) leaves on their own: nothing was claimed, so there is
	 * nothing for a person to decide. With an approved charge, a person decides.
	 */
	private static function resolve(): array {
		$cases = Db::rows( 'SELECT c.*, u.pipedrive_person_id, a.pipedrive_deal_id, a.signed_at FROM ' . Db::t( 'cases' ) . ' c JOIN ' . Db::t( 'customers' ) . ' u ON u.id = c.customer_id LEFT JOIN ' . Db::t( 'agreements' ) . " a ON a.id = c.agreement_id WHERE c.phase = 'commitment' AND c.workflow_state <> 'closed'" );
		if ( ! $cases ) {
			return array( 'opened' => 0, 'paid' => 0 );
		}
		$res = FinanceDashboard::resolutions( array_column( $cases, 'pipedrive_person_id' ) );
		// Students outside IFD's cohort (joined before the baseline) are checked in its ledger.
		$late   = array_filter( $cases, static fn( $c ) => $c['pipedrive_deal_id'] && ! isset( $res['by_deal'][ (int) $c['pipedrive_deal_id'] ] ) && $c['signed_at'] && $c['signed_at'] < FinanceDashboard::baseline() );
		$ledger = array();
		foreach ( $late as $c ) {
			$hit = FinanceDashboard::opened_since( array( (int) $c['pipedrive_person_id'] ), (string) $c['signed_at'] );
			if ( $hit ) {
				$ledger[ (int) $c['id'] ] = reset( $hit );
			}
		}
		$out = array( 'opened' => 0, 'paid' => 0 );
		foreach ( $cases as $c ) {
			$hit = $c['pipedrive_deal_id'] ? ( $res['by_deal'][ (int) $c['pipedrive_deal_id'] ] ?? null ) : null;
			if ( ! $hit && isset( $ledger[ (int) $c['id'] ] ) ) {
				$hit = array( 'kind' => 'opened', 'resolved_at' => $ledger[ (int) $c['id'] ] );
			}
			if ( ! $hit ) {
				continue;
			}
			$kind     = $hit['kind'];
			$approved = (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'debt_items' ) . ' WHERE case_id = %d AND approved_at IS NOT NULL', (int) $c['id'] );
			Scheduler::cancel_for_case( (int) $c['id'], 'resolved_' . $kind );
			if ( ! $approved ) {
				Db::exec( 'DELETE FROM ' . Db::t( 'debt_items' ) . ' WHERE case_id = %d AND approved_at IS NULL', (int) $c['id'] );
				Workflow::transition( (int) $c['id'], 'closed', 'opened' === $kind ? 'נפתח חשבון לפי דשבורד ההכנסות (' . $hit['resolved_at'] . ')' : 'החיוב נרשם כשולם בפייפדרייב (' . $hit['resolved_at'] . ')', null, array(), 'journey' );
				Db::exec( 'UPDATE ' . Db::t( 'program_candidates' ) . " SET status = 'resolved', note = %s, updated_at = %s WHERE case_id = %d", 'opened' === $kind ? 'נפתח חשבון' : 'שולם לפי פייפדרייב', Clock::utc(), (int) $c['id'] );
			} else {
				if ( Workflow::can( $c['workflow_state'], 'human_review' ) ) {
					Workflow::transition( (int) $c['id'], 'human_review', 'opened' === $kind ? 'דשבורד ההכנסות מסמן שהחשבון נפתח, נדרשת החלטה על החיוב' : 'דשבורד ההכנסות מסמן שהחיוב שולם, נדרש אימות', null, 'opened' === $kind ? array( 'claims_account_opened' => 1 ) : array(), 'journey' );
				}
				Exceptions::open( ( 'opened' === $kind ? 'opened_after:' : 'paid_elsewhere:' ) . $c['id'], 'opened' === $kind ? 'account_opened_after_charge' : 'paid_per_crm', 'opened' === $kind ? 'medium' : 'high', 'opened' === $kind ? 'לפי דשבורד ההכנסות התלמיד פתח חשבון אחרי שהחיוב אושר' : 'לפי דשבורד ההכנסות החיוב נרשם כשולם בפייפדרייב, אבל במערכת התשלומים לא התקבל תשלום', array( 'entity_type' => 'case', 'entity_id' => (int) $c['id'], 'customer_id' => (int) $c['customer_id'] ) );
			}
			++$out[ $kind ];
		}
		return $out;
	}

	/**
	 * Past the deadline with an approved charge: the case becomes an ordinary charge
	 * case. A declined student who already got the payment details continues from
	 * the second reminder, not from the "payment is due" opening.
	 */
	private static function phase_switch(): int {
		$rows = Db::rows( 'SELECT c.id, c.track_step FROM ' . Db::t( 'cases' ) . ' c JOIN ' . Db::t( 'agreements' ) . " a ON a.id = c.agreement_id WHERE c.phase = 'commitment' AND c.workflow_state <> 'closed' AND a.account_open_deadline < %s AND EXISTS (SELECT 1 FROM " . Db::t( 'debt_items' ) . ' d WHERE d.case_id = c.id AND d.approved_at IS NOT NULL)', Clock::today() );
		foreach ( $rows as $r ) {
			self::to_charge( (int) $r['id'] );
		}
		return count( $rows );
	}

	public static function to_charge( int $case_id ): void {
		$case = Workflow::get( $case_id );
		$step = in_array( $case['track_step'], array( 'd_link', 'd_t0' ), true ) ? 1 : 0;
		Scheduler::cancel_for_case( $case_id, 'to_charge' );
		Workflow::touch( $case_id, array( 'phase' => 'charge', 'sequence_step' => $step, 'sequence_started_at' => Clock::utc() ), 'case.phase', 'המועד עבר והחיוב אושר: מעבר לשלב התשלום' );
		if ( in_array( $case['workflow_state'], Workflow::SENDABLE, true ) ) {
			Messaging::plan_next( $case_id );
		}
	}

	/* ------------------------------------------------------------- planning */

	/** Local date of the last proactive message to this customer (the ones that count toward the quota). */
	private static function last_proactive_date( int $customer_id ): ?string {
		$last = Db::value( 'SELECT MAX(occurred_at) FROM ' . Db::t( 'messages' ) . " WHERE customer_id = %d AND direction = 'out' AND counts_toward_quota = 1 AND delivery_state NOT IN ('failed','cancelled','draft')", $customer_id );
		$ts   = Clock::ts( $last );
		return null === $ts ? null : Clock::local_date( $ts );
	}

	private static function plus_days( string $date, int $days ): string {
		return ( new \DateTimeImmutable( $date, Clock::tz() ) )->modify( ( $days >= 0 ? '+' : '' ) . $days . ' days' )->format( 'Y-m-d' );
	}

	/**
	 * The next message of the case's track and the date it belongs to, or null when
	 * the track has nothing more to say (the case then waits for approval).
	 * Late entry: the opening message first, then the next step that is at least
	 * journey_min_gap_days later (the deadline day only needs one day).
	 *
	 * @return array{key:string,date:string}|null
	 */
	public static function next_step( array $case, ?array $agr = null ): ?array {
		$agr      = $agr ?? self::agreement( $case );
		$deadline = $agr['account_open_deadline'] ?? null;
		if ( ! $deadline ) {
			return null;
		}
		$today = Clock::today();
		$last  = self::last_proactive_date( (int) $case['customer_id'] );
		$after = static fn( int $days ) => null === $last ? $today : max( $today, self::plus_days( $last, $days ) );
		$sent  = (string) $case['track_step'];
		$gap   = max( 1, (int) Settings::get( 'journey_min_gap_days' ) );

		switch ( (string) $case['track'] ) {
			case 'reach':
				if ( '' === $sent ) {
					$date = max( $after( 1 ), self::plus_days( $deadline, -(int) Settings::get( 'journey_start_days' ) ) );
					return $date <= $deadline ? array( 'key' => 'j_intro', 'date' => $date ) : null;
				}
				$keys = array_keys( self::REACH );
				$from = array_search( $sent, $keys, true );
				if ( false === $from ) {
					return null;
				}
				foreach ( array_slice( $keys, $from + 1 ) as $k ) {
					$date = self::plus_days( $deadline, -self::REACH[ $k ] );
					if ( $date >= $after( 'j_t0' === $k ? 1 : $gap ) ) {
						return array( 'key' => $k, 'date' => $date );
					}
				}
				return null;

			case 'declined':
				$approved = (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'debt_items' ) . ' WHERE case_id = %d AND approved_at IS NOT NULL', (int) $case['id'] );
				if ( ! $approved || $deadline < $today ) {
					return null;
				}
				if ( ! in_array( $sent, array( 'd_link', 'd_t0' ), true ) && $after( 1 ) < $deadline ) {
					return array( 'key' => 'd_link', 'date' => $after( 1 ) );
				}
				if ( 'd_t0' !== $sent && $deadline >= $after( 1 ) ) {
					return array( 'key' => 'd_t0', 'date' => $deadline );
				}
				return null;

			case 'late':
				return '' === $sent ? array( 'key' => 'l_intro', 'date' => $after( 1 ) ) : null;
		}
		return null;
	}

	/**
	 * A step lands inside the contact window of its date. A date that is not a business
	 * day moves back (the deadline-day message must not arrive after the deadline),
	 * unless that is already in the past, then forward.
	 */
	private static function send_ts( string $date, array $policy, int $customer_id ): int {
		$d = $date;
		if ( ! Calendar::is_business_day( $d, $policy ) ) {
			$back = $d;
			for ( $i = 0; $i < 7 && ! Calendar::is_business_day( $back, $policy ); $i++ ) {
				$back = self::plus_days( $back, -1 );
			}
			$d = ( $back >= Clock::today() && Calendar::is_business_day( $back, $policy ) ) ? $back : $d;
		}
		$jitter = (int) $policy['first_contact_jitter_minutes'];
		$ts     = Clock::local_to_ts( $d, $policy['window_start'] ) + ( $jitter > 0 ? random_int( 0, $jitter ) * MINUTE_IN_SECONDS : 0 );
		// Planned past the minimum gap up front: a send blocked by it is retried on the next
		// business day, which for the deadline-day message would be after the deadline.
		$last = Clock::ts( Db::value( 'SELECT MAX(occurred_at) FROM ' . Db::t( 'messages' ) . " WHERE customer_id = %d AND direction = 'out' AND counts_toward_quota = 1 AND delivery_state NOT IN ('failed','cancelled','draft')", $customer_id ) );
		if ( null !== $last ) {
			$ts = max( $ts, $last + (int) $policy['min_gap_hours'] * HOUR_IN_SECONDS + MINUTE_IN_SECONDS );
		}
		return Calendar::next_slot( max( $ts, Clock::now() ), $policy );
	}

	/** Schedule the case's next journey message (Messaging::plan_next delegates here in this phase). */
	public static function plan_next( int $case_id ): ?int {
		$case = Workflow::get( $case_id );
		if ( ! $case || 'commitment' !== $case['phase'] || ! in_array( $case['workflow_state'], Workflow::SENDABLE, true ) ) {
			return null;
		}
		if ( ! self::enabled() ) {
			Db::update( 'cases', array( 'next_action_type' => 'journey_off', 'next_action_at' => null ), array( 'id' => $case_id ) );
			return null; // nothing is planned while the journey is switched off; sync() plans once it is on
		}
		$next = self::next_step( $case );
		if ( ! $next ) {
			Db::update( 'cases', array( 'next_action_type' => 'await_approval', 'next_action_at' => null ), array( 'id' => $case_id ) );
			return null;
		}
		$policy = Policy::effective_or_draft();
		$run_at = self::send_ts( $next['date'], $policy, (int) $case['customer_id'] );
		$id     = Scheduler::schedule( 'send_journey', $run_at, 'journey:' . $case_id . ':' . $next['key'], $case_id, (int) $case['customer_id'], array( 'step' => $next['key'] ), (int) $policy['_version'] );
		Db::update( 'cases', array( 'next_action_type' => 'send_journey', 'next_action_at' => Clock::utc( $run_at ) ), array( 'id' => $case_id ) );
		return $id;
	}

	/* -------------------------------------------------------------- sending */

	public static function template_for( string $step, bool $link_available ): string {
		if ( in_array( $step, array( 'd_link', 'd_t0' ), true ) && ! $link_available ) {
			return $step . '_reply';
		}
		return $step;
	}

	public static function compose( int $case_id, string $step, bool $create_link ): array {
		$case    = Workflow::get( $case_id );
		$link_ok = PaymentRequests::link_allowed( $case );
		$key     = self::template_for( $step, $link_ok['allowed'] );
		$tpl     = Templates::get( $key );
		$link    = null;
		if ( $tpl['requires_link'] ) {
			$link = $create_link ? PaymentRequests::local_link( $case_id ) : '[קישור יופק בשליחה]';
		}
		$vars            = Messaging::variables( $case, $link );
		[ $text, $miss ] = Templates::render( $tpl, $vars );
		return array( 'template' => $tpl, 'rendered_text' => $text, 'vars' => $vars, 'missing' => $miss, 'link_route' => $link_ok, 'step' => $step );
	}

	public static function execute( array $action ): string {
		$case_id = (int) $action['case_id'];
		$case    = Workflow::get( $case_id );
		if ( ! $case ) {
			return 'cancel:case_missing';
		}
		if ( 'commitment' !== $case['phase'] ) {
			return 'cancel:phase_changed';
		}
		if ( ! in_array( $case['workflow_state'], Workflow::SENDABLE, true ) ) {
			return 'cancel:state';
		}
		if ( ! self::enabled() ) {
			return 'cancel:disabled';
		}
		if ( FinanceDashboard::available() && FinanceDashboard::contract()['stale'] ) {
			// "לפי המערכת החשבון עוד לא נפתח" needs a dashboard that read Pipedrive recently.
			return 'cancel:ifd_stale'; // planned again by the hourly run once the dashboard is fresh
		}
		$payload = (array) json_decode( (string) $action['payload'], true );
		$step    = (string) ( $payload['step'] ?? '' );
		$next    = self::next_step( $case );
		if ( ! $next || $next['key'] !== $step ) {
			return 'cancel:stale_step'; // the runner re-plans from the current state
		}
		$comp  = self::compose( $case_id, $step, false );
		$guard = SendGuard::check( $case_id, array( 'template' => $comp['template'], 'action_created_at' => $action['created_at'], 'is_reminder' => true ) );
		if ( $comp['missing'] ) {
			$guard['ok']         = false;
			$guard['transient']  = false;
			$guard['blocking'][] = array( 'code' => 'missing_variable', 'scope' => 'case', 'message' => 'חסר ערך: ' . implode( ',', $comp['missing'] ) );
		}
		if ( ! $guard['ok'] ) {
			return Messaging::handle_block( $action, $case, $guard );
		}
		if ( $comp['template']['requires_link'] ) {
			$comp = self::compose( $case_id, $step, true );
			if ( $comp['missing'] ) {
				return 'cancel:missing_variable';
			}
		}
		return Db::transaction(
			function () use ( $case, $case_id, $comp, $guard, $step ) {
				$message_id = Messaging::record_outbound( $case, $comp['template'], $comp['rendered_text'], $guard['mode'], 'system', null, $comp['vars'] );
				Workflow::transition( $case_id, 'waiting_reply', 'נשלחה הודעת ליווי: ' . ( self::STEP_LABELS[ $step ] ?? $step ) . ( 'live' === $guard['mode'] ? '' : ' (סימולציה)' ), null, array( 'track_step' => $step, 'last_outbound_at' => Clock::utc() ), 'journey' );
				self::plan_next( $case_id );
				return ( 'live' === $guard['mode'] ? 'sent:' : 'simulated:' ) . $message_id;
			}
		);
	}

	/** Preview for the case screen: the next journey message, when it would go and what blocks it. */
	public static function preview( int $case_id ): array {
		$case   = Workflow::get( $case_id );
		$policy = Policy::effective_or_draft();
		$next   = self::next_step( $case );
		if ( ! $next ) {
			return array(
				'rendered_text'    => '',
				'template_id'      => '',
				'template_version' => 0,
				'balance_version'  => Ledger::case_summary( $case_id )['signature'],
				'send_after'       => null,
				'send_after_local' => '',
				'mode'             => SendGuard::mode(),
				'blockers'         => array( array( 'code' => 'journey_done', 'scope' => 'case', 'message' => 'declined' === $case['track'] && ! Ledger::case_summary( $case_id )['items'] ? 'התלמיד הודיע שלא יפתח חשבון. פרטי התשלום יישלחו אחרי אישור החיוב ברשימה של התוכנית למתחילים.' : 'אין עוד הודעות במסלול. אחרי המועד התיק ממתין לאישור החיוב.' ) ),
				'link_route'       => PaymentRequests::link_allowed( $case ),
				'finance_summary'  => Ledger::case_summary( $case_id ),
				'journey'          => array( 'track' => $case['track'], 'step' => null ),
			);
		}
		$comp    = self::compose( $case_id, $next['key'], false );
		$pending = Db::row( 'SELECT run_at FROM ' . Db::t( 'scheduled_actions' ) . " WHERE case_id = %d AND type = 'send_journey' AND state = 'pending' ORDER BY run_at LIMIT 1", $case_id );
		$at      = $pending ? (int) Clock::ts( $pending['run_at'] ) : self::send_ts( $next['date'], $policy, (int) $case['customer_id'] );
		$guard   = SendGuard::check( $case_id, array( 'template' => $comp['template'], 'at' => $at, 'is_reminder' => true ) );
		foreach ( $comp['missing'] as $m ) {
			$guard['blockers'][] = array( 'code' => 'missing_variable', 'scope' => 'case', 'message' => 'חסר ערך למשתנה ' . $m );
		}
		if ( ! self::enabled() ) {
			$guard['blockers'][] = array( 'code' => 'journey_off', 'scope' => 'setup', 'message' => 'הליווי האוטומטי כבוי בהגדרות' );
		}
		if ( FinanceDashboard::available() && FinanceDashboard::contract()['stale'] ) {
			$guard['blockers'][] = array( 'code' => 'ifd_stale', 'scope' => 'transient', 'message' => 'דשבורד ההכנסות לא סנכרן את פייפדרייב לאחרונה, הודעות הליווי מושהות' );
		}
		return array(
			'rendered_text'    => $comp['rendered_text'],
			'template_id'      => $comp['template']['key'],
			'template_version' => (int) $comp['template']['version'],
			'balance_version'  => Ledger::case_summary( $case_id )['signature'],
			'send_after'       => Clock::utc( $at ),
			'send_after_local' => Clock::local( $at )->format( 'd/m/Y H:i' ),
			'mode'             => $guard['mode'],
			'blockers'         => $guard['blockers'],
			'link_route'       => $comp['link_route'],
			'finance_summary'  => Ledger::case_summary( $case_id ),
			'journey'          => array( 'track' => $case['track'], 'step' => $next['key'], 'step_label' => self::STEP_LABELS[ $next['key'] ] ?? $next['key'] ),
		);
	}

	/* -------------------------------------------------------------- replies */

	/** Quick-reply buttons arrive as their exact title; matched before any free-text rule. */
	public static function button_intent( string $text ): ?string {
		$text  = trim( $text );
		$exact = array(
			self::BTN_OPEN     => 'wants_to_open',
			self::BTN_QUESTION => 'question',
			self::BTN_DECLINE  => 'declines_open',
		)[ $text ] ?? null;
		if ( $exact ) {
			return $exact;
		}
		// Only button presses reach here (Intents::classify), so the wording may drift from the
		// constants when the templates are edited in WATI; the key words decide.
		if ( preg_match( '/לא\s+(אפתח|נפתח|רוצה|מתכוו|מעוניי)/u', $text ) ) {
			return 'declines_open';
		}
		if ( preg_match( '/לפתוח|פתיחת|פתיחה/u', $text ) ) {
			return 'wants_to_open';
		}
		if ( preg_match( '/שאלה|עזרה|לא ברור|להתייעץ/u', $text ) ) {
			return 'question';
		}
		return null;
	}

	/**
	 * Inbound routing for commitment-phase cases. Returns the cases it did NOT handle,
	 * which go through the general §10 table. Safety intents (stop, wrong number,
	 * dispute, paid, hardship, human) are always left to the general table.
	 */
	public static function on_intent( string $intent, string $source, array $cases, int $customer_id, int $message_id, array $msg ): array {
		$mine = array_values( array_filter( $cases, static fn( $c ) => 'commitment' === ( $c['phase'] ?? 'charge' ) && 'closed' !== $c['workflow_state'] ) );
		$rest = array_values( array_filter( $cases, static fn( $c ) => ! in_array( $c, $mine, true ) ) );
		$handled = array( 'wants_to_open', 'question', 'declines_open', 'opened_account', 'stuck', 'next_cohort', 'promise', 'link_request' );
		if ( ! $mine || ! in_array( $intent, $handled, true ) ) {
			return $cases;
		}
		$text   = mb_substr( (string) ( $msg['text'] ?? '' ), 0, 200 );
		$review = static function ( array $c, string $reason ) {
			if ( Workflow::can( $c['workflow_state'], 'human_review' ) ) {
				Workflow::transition( (int) $c['id'], 'human_review', $reason, null, array(), 'inbound' );
			}
		};
		$reply = null;
		foreach ( $mine as $c ) {
			$id       = (int) $c['id'];
			$deadline = (string) ( self::agreement( $c )['account_open_deadline'] ?? '' );
			switch ( $intent ) {
				case 'wants_to_open':
					$review( $c, 'התלמיד רוצה לפתוח חשבון, נציג יחזור אליו' );
					Tasks::open( 'open_call:' . $id, 'open_call', array( 'case_id' => $id, 'customer_id' => $customer_id, 'priority' => 'high', 'reason' => 'התלמיד לחץ "' . self::BTN_OPEN . '". לתאם שיחה וללוות את פתיחת החשבון. המועד: ' . self::date_he( $deadline ) . '. אחרי השיחה: לחדש את הליווי בתיק, או להשאיר אצל נציג עד שהחשבון נפתח.' ) );
					$reply = 'j_open_ack';
					break;
				case 'question':
					$review( $c, 'התלמיד ביקש לשאול שאלה' );
					Tasks::open( 'reply:' . $id . ':' . $message_id, 'reply_review', array( 'case_id' => $id, 'customer_id' => $customer_id, 'reason' => 'התלמיד לחץ "' . self::BTN_QUESTION . '". לחזור אליו בוואטסאפ ולענות.' ) );
					$reply = 'j_question_ack';
					break;
				case 'declines_open':
					if ( 'button' === $source ) {
						self::decline( $id, 'button', 'התלמיד לחץ "' . self::BTN_DECLINE . '"' );
						Tasks::open( 'crm_lost:' . $id, 'crm_lost', array( 'case_id' => $id, 'customer_id' => $customer_id, 'reason' => 'התלמיד הודיע בוואטסאפ שלא יפתח חשבון. לסמן את הדיל כ-lost עם הסיבה "' . ( preg_split( '/\R/', (string) Settings::get( 'declined_lost_reasons' ) )[0] ?? '' ) . '". מערכת התשלומים כבר העבירה אותו למסלול המתאים.' ) );
						$reply = 'j_declined_ack';
					} else {
						// Free text is not a decision: "not now" and "never" read alike.
						$review( $c, 'נראה שהתלמיד לא מתכוון לפתוח חשבון, לבדיקת נציג' );
						Tasks::open( 'reply:' . $id . ':' . $message_id, 'reply_review', array( 'case_id' => $id, 'customer_id' => $customer_id, 'reason' => 'נראה שהתלמיד לא מתכוון לפתוח חשבון: "' . $text . '". אם זה סופי, לסמן את הדיל כ-lost עם הסיבה הייעודית והמערכת תעביר למסלול המתאים.' ) );
					}
					break;
				case 'opened_account':
					Db::update( 'cases', array( 'claims_account_opened' => 1 ), array( 'id' => $id ) );
					$review( $c, 'התלמיד כתב שכבר פתח חשבון, עצירה עד בדיקה' );
					Tasks::open( 'account_opened_claim:' . $id, 'account_opened_claim', array( 'case_id' => $id, 'customer_id' => $customer_id, 'reason' => 'התלמיד כתב שפתח חשבון: "' . $text . '". לבדוק מול הברוקר ולעדכן בפייפדרייב.' ) );
					$reply = 'j_opened_ack';
					break;
				case 'stuck':
					// A real opening in progress goes to a person before any charge (procedure, system rules).
					Db::update( 'cases', array( 'claims_account_opened' => 1 ), array( 'id' => $id ) );
					$review( $c, 'פתיחת החשבון התחילה ונתקעה, לבדיקה לפני חיוב' );
					Tasks::open( 'account_opened_claim:' . $id, 'account_opened_claim', array( 'case_id' => $id, 'customer_id' => $customer_id, 'reason' => 'התלמיד כתב שהפתיחה בתהליך או תקועה: "' . $text . '". לבדוק איפה זה עומד ולעזור להשלים.' ) );
					$reply = 'j_stuck_ack';
					break;
				case 'next_cohort':
					// A fixed answer, the journey goes on: moving cohorts never moves the deadline.
					Tasks::open( 'next_cohort:' . $id, 'reply_review', array( 'case_id' => $id, 'customer_id' => $customer_id, 'priority' => 'low', 'reason' => 'התלמיד ביקש לעבור למחזור הבא: "' . $text . '". נשלחה תשובה קבועה שהמועד לא משתנה. לשבץ במחזור אם צריך.' ) );
					$reply = 'j_next_cohort';
					break;
				default: // promise / link_request before any charge exists: a person answers
					$review( $c, 'הודעה מהתלמיד לפני המועד, לבדיקת נציג' );
					Tasks::open( 'reply:' . $id . ':' . $message_id, 'reply_review', array( 'case_id' => $id, 'customer_id' => $customer_id, 'reason' => 'הודעה מהתלמיד: "' . $text . '"' ) );
			}
		}
		if ( $reply ) {
			Messaging::service_reply( (int) $mine[0]['id'], $reply );
		}
		if ( 'next_cohort' === $intent ) {
			foreach ( $mine as $c ) {
				self::plan_next( (int) $c['id'] );
			}
		}
		return $rest;
	}

	/**
	 * The student will not open an account. From a button the case goes on in the
	 * declined track; from Pipedrive (a rep already talked to them) a case a person is
	 * handling stays with that person.
	 */
	public static function decline( int $case_id, string $source, string $reason ): bool {
		$case = Workflow::get( $case_id );
		if ( ! $case || 'commitment' !== $case['phase'] || 'closed' === $case['workflow_state'] || 'declined' === $case['track'] ) {
			return false;
		}
		$extra = array( 'track' => 'declined', 'declined_at' => Clock::utc(), 'declined_source' => $source );
		Scheduler::cancel_for_case( $case_id, 'declined' );
		Tasks::close_by_key( 'open_call:' . $case_id, 'התלמיד הודיע שלא יפתח חשבון' );
		if ( 'button' === $source && Workflow::can( $case['workflow_state'], 'active' ) ) {
			Workflow::transition( $case_id, 'active', $reason, null, $extra, 'journey' );
		} else {
			Workflow::touch( $case_id, $extra, 'case.declined', $reason );
		}
		self::plan_next( $case_id );
		return true;
	}

	public static function date_he( string $ymd ): string {
		return preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m ) ? $m[3] . '/' . $m[2] . '/' . $m[1] : $ymd;
	}
}

<?php
namespace Insiders\Collections\Domain;

use Insiders\Collections\Support\Audit;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Collection cases: manual debt (§5), handover / "המשך טיפול" (§6) and the cases
 * opened by Tranzila events (§4). Everything starts as a draft; activation is an
 * explicit, approved act that re-validates the money it was shown.
 */
final class Cases {

	public const SOURCES = array(
		'recurring_failure' => 'חיוב חוזר שלא עבר',
		'non_open_charge'   => 'אי פתיחת חשבון במסגרת תוכנית הליווי',
		'other'             => 'אחר',
	);

	public static function get( int $id ): ?array {
		return Workflow::get( $id );
	}

	/** §19 חוזה יצירת תיק ידני. Returns the full response shape. */
	public static function create_draft( array $p ): array {
		$errors = array();
		$customer_id = (int) ( $p['customer_id'] ?? 0 );
		$customer    = $customer_id ? Customers::get( $customer_id ) : null;
		if ( ! $customer ) {
			$errors['customer_id'] = 'יש לבחור לקוח קיים או ליצור לקוח';
		}
		$source = (string) ( $p['source_type'] ?? '' );
		if ( ! isset( self::SOURCES[ $source ] ) ) {
			$errors['source_type'] = 'מקור חוב לא מוכר';
		}
		$mode = (string) ( $p['entry_mode'] ?? 'new' );
		if ( ! in_array( $mode, array( 'new', 'handover' ), true ) ) {
			$errors['entry_mode'] = 'אופן כניסה לא מוכר';
		}
		$currency = Money::normalize_currency( $p['currency'] ?? 'ILS' );
		if ( ! $currency ) {
			$errors['currency'] = 'מטבע לא נתמך';
		}

		$items = array();
		foreach ( (array) ( $p['debt_items'] ?? array() ) as $i => $it ) {
			$amount = isset( $it['amount_minor'] ) ? (int) $it['amount_minor'] : Money::parse( $it['amount'] ?? '' );
			$due    = (string) ( $it['due_at'] ?? '' );
			if ( null === $amount || $amount <= 0 ) {
				$errors[ "debt_items.$i.amount" ] = 'סכום חיובי חובה';
			}
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $due ) ) {
				$errors[ "debt_items.$i.due_at" ] = 'מועד פירעון חובה';
			}
			$items[] = array(
				'amount_minor' => (int) $amount,
				'due_at'       => $due,
				'description'  => mb_substr( trim( (string) ( $it['description'] ?? '' ) ), 0, 255 ),
				'evidence_ref' => mb_substr( (string) ( $it['evidence_ref'] ?? '' ), 0, 255 ),
			);
		}

		$history_mode = 'none';
		if ( 'handover' === $mode ) {
			$history_mode = (string) ( $p['history_mode'] ?? '' );
			$ledger_moves = array_filter( (array) ( $p['imported_history'] ?? array() ), static fn( $h ) => in_array( $h['entry_type'] ?? 'note', array( 'payment', 'charge' ), true ) && ! empty( $h['affects_balance'] ) );
			if ( ! in_array( $history_mode, array( 'net_opening', 'full_ledger' ), true ) ) {
				// §19: opening balance + balance-affecting history without a clear mode is rejected.
				$errors['history_mode'] = 'יש לבחור: יתרת פתיחה נטו או ספר תנועות מלא';
			} elseif ( 'net_opening' === $history_mode && $ledger_moves ) {
				$errors['history_mode'] = 'ביתרת פתיחה נטו, תשלומי עבר נשמרים כהיסטוריה בלבד ולא משפיעים על היתרה';
			}
			if ( 'net_opening' === $history_mode && count( $items ) !== 1 ) {
				$errors['debt_items'] = 'ביתרת פתיחה נטו מזינים שורת יתרה אחת';
			}
			if ( empty( $p['opening_balance_as_of'] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) $p['opening_balance_as_of'] ) ) {
				$errors['opening_balance_as_of'] = 'תאריך יתרת פתיחה חובה';
			}
		}
		if ( $errors ) {
			throw new DomainError( 'validation_failed', 'יש שדות שדורשים תיקון', 400, $errors );
		}

		return Db::transaction(
			function () use ( $p, $customer, $source, $mode, $currency, $items, $history_mode ) {
				$agreement_id = self::upsert_agreement( (int) $customer['id'], $source, $p );
				$order_id     = ! empty( $p['recurring_order_id'] ) ? (int) $p['recurring_order_id'] : null;
				$case_key     = $order_id ? 'sto:' . $order_id : ( $agreement_id ? 'agr:' . $agreement_id : 'cust:' . $customer['id'] . ':' . $source );
				if ( Db::value( 'SELECT id FROM ' . Db::t( 'cases' ) . ' WHERE active_key = %s', $case_key ) ) {
					$existing = (int) Db::value( 'SELECT id FROM ' . Db::t( 'cases' ) . ' WHERE active_key = %s', $case_key );
					throw new DomainError( 'case_exists', 'כבר קיים תיק פתוח להסכם הזה (#' . $existing . '). אפשר להוסיף אליו פריט חוב או להפעיל ממנו המשך טיפול.', 409, array( 'case_id' => (string) $existing ) );
				}
				$case_id = Db::insert(
					'cases',
					array(
						'customer_id'           => (int) $customer['id'],
						'agreement_id'          => $agreement_id,
						'recurring_order_id'    => $order_id,
						'case_key'              => $case_key,
						'active_key'            => $case_key,
						'source_type'           => $source,
						'entry_mode'            => $mode,
						'workflow_state'        => 'draft',
						'owner_id'              => ! empty( $p['owner_id'] ) ? (int) $p['owner_id'] : null,
						'currency'              => $currency,
						'history_mode'          => $history_mode,
						'opening_balance_as_of' => 'handover' === $mode ? Clock::utc( Clock::local_to_ts( substr( (string) $p['opening_balance_as_of'], 0, 10 ), '23:59' ) ) : null,
						'clarification_first'   => ! empty( $p['clarification_first'] ) ? 1 : 0,
						'dispute_open'          => ( ( $p['existing_arrangement']['type'] ?? '' ) === 'dispute' ) ? 1 : 0,
						'created_by'            => get_current_user_id() ?: null,
						'created_at'            => Clock::utc(),
						'updated_at'            => Clock::utc(),
					)
				);
				foreach ( $items as $n => $it ) {
					self::insert_item( $case_id, $it, $currency, 'handover' === $mode && 'net_opening' === $history_mode, (string) ( $p['approval_basis'] ?? '' ) );
				}
				if ( 'handover' === $mode ) {
					self::import_handover( $case_id, $p );
				}
				Audit::log( 'case.create_draft', 'case', $case_id, null, array( 'source' => $source, 'mode' => $mode, 'items' => count( $items ) ), (string) ( $p['approval_basis'] ?? '' ) );
				return self::response( $case_id );
			}
		);
	}

	private static function upsert_agreement( int $customer_id, string $source, array $p ): ?int {
		if ( ! empty( $p['agreement_id'] ) ) {
			return (int) $p['agreement_id'];
		}
		$a = (array) ( $p['agreement'] ?? array() );
		if ( ! $a ) {
			return null;
		}
		$date = static fn( $v ) => ( is_string( $v ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) ? $v : null;
		return Db::insert(
			'agreements',
			array(
				'customer_id'           => $customer_id,
				'type'                  => 'non_open_charge' === $source ? 'beginner_program' : ( $a['type'] ?? 'other' ),
				'program'               => mb_substr( (string) ( $a['program'] ?? '' ), 0, 100 ) ?: null,
				'reference'             => mb_substr( (string) ( $a['reference'] ?? '' ), 0, 190 ) ?: null,
				'signed_at'             => $date( $a['signed_at'] ?? null ),
				'joined_at'             => $date( $a['joined_at'] ?? null ),
				'document_ref'          => mb_substr( (string) ( $a['document_ref'] ?? '' ), 0, 255 ) ?: null,
				'account_open_deadline' => $date( $a['account_open_deadline'] ?? null ),
				'extensions_json'       => ! empty( $a['extensions'] ) ? wp_json_encode( $a['extensions'] ) : null,
				'status_checked_at'     => $date( $a['status_checked_at'] ?? null ),
				'created_by'            => get_current_user_id() ?: null,
				'created_at'            => Clock::utc(),
				'updated_at'            => Clock::utc(),
			)
		);
	}

	public static function insert_item( int $case_id, array $it, string $currency, bool $is_opening, string $basis, ?string $source_key = null, string $collection_owner = 'manual' ): int {
		$id = Db::insert(
			'debt_items',
			array(
				'case_id'               => $case_id,
				'source_key'            => $source_key,
				'description'           => $it['description'] ?? '',
				'due_at'                => $it['due_at'],
				'original_amount_minor' => (int) $it['amount_minor'],
				'currency'              => $currency,
				'finance_state'         => 'draft',
				'collection_owner'      => $collection_owner,
				'is_opening_balance'    => $is_opening ? 1 : 0,
				'approval_basis'        => mb_substr( $basis, 0, 2000 ) ?: null,
				'evidence_ref'          => $it['evidence_ref'] ?? null,
				'cached_balance_minor'  => 0,
				'created_by'            => get_current_user_id() ?: null,
				'created_at'            => Clock::utc(),
				'updated_at'            => Clock::utc(),
			)
		);
		return $id;
	}

	public static function import_handover( int $case_id, array $p ): void {
		$add = static function ( array $h ) use ( $case_id ) {
			Db::insert(
				'imported_history',
				array(
					'case_id'                 => $case_id,
					'original_at'             => ! empty( $h['original_at'] ) ? Clock::utc( Clock::local_to_ts( substr( $h['original_at'], 0, 10 ), strlen( $h['original_at'] ) > 10 ? substr( $h['original_at'], 11, 5 ) : '12:00' ) ) : null,
					'author_label'            => mb_substr( (string) ( $h['author_label'] ?? '' ), 0, 100 ),
					'channel'                 => mb_substr( (string) ( $h['channel'] ?? '' ), 0, 20 ),
					'content'                 => (string) ( $h['content'] ?? '' ),
					'content_ref'             => mb_substr( (string) ( $h['content_ref'] ?? '' ), 0, 255 ) ?: null,
					'import_mode'             => in_array( $h['import_mode'] ?? '', array( 'paste', 'file', 'summary' ), true ) ? $h['import_mode'] : 'paste',
					'entry_type'              => in_array( $h['entry_type'] ?? '', array( 'note', 'message_out', 'message_in', 'payment', 'charge', 'summary' ), true ) ? $h['entry_type'] : 'note',
					'amount_minor'            => isset( $h['amount'] ) ? Money::parse( $h['amount'] ) : null,
					'affects_opening_balance' => ! empty( $h['affects_opening_balance'] ) ? 1 : 0,
					'counts_toward_quota'     => ( ( $h['entry_type'] ?? '' ) === 'message_out' && ! empty( $h['original_at'] ) && ! empty( $h['counts_toward_quota'] ) ) ? 1 : 0,
					'entered_by'              => get_current_user_id() ?: null,
					'created_at'              => Clock::utc(),
				)
			);
		};
		foreach ( (array) ( $p['imported_history'] ?? array() ) as $h ) {
			$add( $h );
		}
		if ( ! empty( $p['last_contact_at'] ) ) {
			$add(
				array(
					'original_at'         => $p['last_contact_at'],
					'author_label'        => $p['last_contact_sender'] ?? '',
					'channel'             => $p['last_contact_channel'] ?? 'whatsapp',
					'content'             => $p['last_contact_summary'] ?? '',
					'import_mode'         => 'summary',
					'entry_type'          => 'message_out',
					'counts_toward_quota' => 1,
				)
			);
		}
		if ( ! empty( $p['last_reply_at'] ) ) {
			$add(
				array(
					'original_at'  => $p['last_reply_at'],
					'author_label' => 'הלקוח',
					'content'      => $p['last_reply_text'] ?? '',
					'import_mode'  => 'summary',
					'entry_type'   => 'message_in',
				)
			);
		}
		$arr = (array) ( $p['existing_arrangement'] ?? array() );
		if ( ! empty( $arr['type'] ) ) {
			$add(
				array(
					'original_at'  => $arr['approved_at'] ?? null,
					'author_label' => $arr['approved_by_label'] ?? '',
					'content'      => 'סיכום קיים: ' . $arr['type'] . ( ! empty( $arr['date'] ) ? ' · ' . $arr['date'] : '' ) . ( ! empty( $arr['amount'] ) ? ' · ' . $arr['amount'] . ' ₪' : '' ) . ( ! empty( $arr['note'] ) ? ' · ' . $arr['note'] : '' ),
					'import_mode'  => 'summary',
					'entry_type'   => 'summary',
				)
			);
			if ( 'promise' === $arr['type'] && ! empty( $arr['date'] ) ) {
				Db::insert(
					'promises',
					array(
						'case_id'      => $case_id,
						'amount_minor' => isset( $arr['amount'] ) ? Money::parse( $arr['amount'] ) : null,
						'promised_at'  => substr( (string) $arr['date'], 0, 10 ),
						'state'        => ! empty( $arr['approved_by_label'] ) ? 'approved' : 'requested',
						'source'       => 'import',
						'note'         => mb_substr( (string) ( $arr['note'] ?? '' ), 0, 500 ),
						'created_by'   => get_current_user_id() ?: null,
						'created_at'   => Clock::utc(),
					)
				);
			}
		}
		if ( ! empty( $p['next_action']['type'] ) ) {
			Db::update(
				'cases',
				array(
					'next_action_type' => mb_substr( (string) $p['next_action']['type'], 0, 40 ),
					'next_action_at'   => ! empty( $p['next_action']['at'] ) ? Clock::utc( Clock::local_to_ts( substr( $p['next_action']['at'], 0, 10 ), strlen( $p['next_action']['at'] ) > 10 ? substr( $p['next_action']['at'], 11, 5 ) : '10:00' ) ) : null,
				),
				array( 'id' => $case_id )
			);
		}
	}

	/** Add a debt item to an existing case (next month failed, extra charge). Always a draft until approved. */
	public static function add_item( int $case_id, array $it, string $basis ): int {
		$case = self::get( $case_id );
		if ( ! $case || 'closed' === $case['workflow_state'] ) {
			throw new DomainError( 'invalid', 'אי אפשר להוסיף חוב לתיק סגור', 422 );
		}
		$amount = Money::parse( $it['amount'] ?? '' );
		if ( null === $amount || $amount <= 0 || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $it['due_at'] ?? '' ) ) ) {
			throw new DomainError( 'validation_failed', 'סכום ומועד פירעון חובה', 400, array( 'amount' => 'חובה', 'due_at' => 'חובה' ) );
		}
		$id = self::insert_item( $case_id, array( 'amount_minor' => $amount, 'due_at' => $it['due_at'], 'description' => (string) ( $it['description'] ?? '' ), 'evidence_ref' => (string) ( $it['evidence_ref'] ?? '' ) ), $case['currency'], false, $basis );
		Audit::log( 'debt_item.create', 'debt_item', $id, null, array( 'case' => $case_id, 'amount' => $amount ), $basis );
		return $id;
	}

	/** Approve draft items (collector/admin). Approval is per item, recorded with who and when. */
	public static function approve_items( int $case_id, string $basis ): int {
		if ( ! current_user_can( 'icol_approve_debt' ) ) {
			throw new DomainError( 'forbidden', 'אישור חוב מותר למנהל או לאחראי גבייה בלבד', 403 );
		}
		if ( '' === trim( $basis ) ) {
			throw new DomainError( 'validation_failed', 'יש לתעד את בסיס החיוב', 400, array( 'approval_basis' => 'חובה' ) );
		}
		return Db::transaction(
			function () use ( $case_id, $basis ) {
				$rows = Db::rows( 'SELECT id FROM ' . Db::t( 'debt_items' ) . ' WHERE case_id = %d AND approved_at IS NULL FOR UPDATE', $case_id );
				foreach ( $rows as $r ) {
					Db::update(
						'debt_items',
						array(
							'approved_by'    => get_current_user_id(),
							'approved_at'    => Clock::utc(),
							'approval_basis' => mb_substr( $basis, 0, 2000 ),
						),
						array( 'id' => (int) $r['id'] )
					);
					Ledger::recompute( (int) $r['id'] );
					Audit::log( 'debt_item.approve', 'debt_item', (int) $r['id'], null, null, $basis );
				}
				return count( $rows );
			}
		);
	}

	/** What blocks activation, next to the field that needs fixing (§5 שמירה והפעלה). */
	public static function activation_blockers( int $case_id ): array {
		$case     = self::get( $case_id );
		$customer = $case ? Customers::get( (int) $case['customer_id'] ) : null;
		$b        = array();
		if ( ! $case || ! $customer ) {
			return array( array( 'code' => 'not_found', 'field' => 'case', 'message' => 'התיק לא נמצא' ) );
		}
		$items = Db::rows( 'SELECT * FROM ' . Db::t( 'debt_items' ) . ' WHERE case_id = %d', $case_id );
		if ( empty( $customer['phone_e164'] ) ) {
			$b[] = array( 'code' => 'phone_missing', 'field' => 'phone', 'message' => 'חסר מספר טלפון' );
		} elseif ( 'verified' !== $customer['contact_status'] ) {
			$b[] = array( 'code' => 'phone_unverified', 'field' => 'phone', 'message' => 'מספר הטלפון לא אומת' );
		}
		if ( Customers::shares_phone( $customer ) && 'verified' !== $customer['contact_status'] ) {
			$b[] = array( 'code' => 'shared_phone', 'field' => 'phone', 'message' => 'המספר משותף לכמה לקוחות — נדרשת התאמת זהות' );
		}
		if ( true !== Customers::permission( (int) $customer['id'], 'whatsapp' ) ) {
			$b[] = array( 'code' => 'contact_permission', 'field' => 'contact_permission_ref', 'message' => 'חסר מקור הרשאת קשר בוואטסאפ' );
		}
		if ( ! $items ) {
			$b[] = array( 'code' => 'no_items', 'field' => 'debt_items', 'message' => 'אין פריטי חוב' );
		}
		$currencies = array_unique( array_column( $items, 'currency' ) );
		if ( count( $currencies ) > 1 ) {
			$b[] = array( 'code' => 'mixed_currency', 'field' => 'currency', 'message' => 'פריטים במטבעות שונים באותו תיק' );
		}
		foreach ( $items as $it ) {
			if ( null === $it['approved_at'] ) {
				$b[] = array( 'code' => 'not_approved', 'field' => 'approval', 'message' => 'פריט החוב "' . ( $it['description'] ?: $it['due_at'] ) . '" טרם אושר בידי אחראי גבייה או מנהל' );
			}
			if ( empty( $it['approval_basis'] ) ) {
				$b[] = array( 'code' => 'basis_missing', 'field' => 'approval_basis', 'message' => 'חסר הסבר לבסיס החיוב' );
			}
		}
		if ( 'non_open_charge' === $case['source_type'] ) {
			$agr = $case['agreement_id'] ? Db::row( 'SELECT * FROM ' . Db::t( 'agreements' ) . ' WHERE id = %d', (int) $case['agreement_id'] ) : null;
			if ( ! $agr ) {
				$b[] = array( 'code' => 'agreement_missing', 'field' => 'agreement', 'message' => 'חסר הסכם הצטרפות' );
			} else {
				if ( empty( $agr['document_ref'] ) && empty( $agr['reference'] ) ) {
					$b[] = array( 'code' => 'agreement_doc', 'field' => 'agreement.document_ref', 'message' => 'חסרה אסמכתה להסכם או הפניה אליו' );
				}
				if ( empty( $agr['account_open_deadline'] ) ) {
					$b[] = array( 'code' => 'deadline_missing', 'field' => 'agreement.account_open_deadline', 'message' => 'חסר מועד אחרון לפתיחת חשבון' );
				} elseif ( $agr['account_open_deadline'] >= Clock::today() ) {
					$b[] = array( 'code' => 'deadline_not_passed', 'field' => 'agreement.account_open_deadline', 'message' => 'המועד לפתיחת חשבון טרם עבר' );
				}
			}
		}
		if ( 'handover' === $case['entry_mode'] && empty( $case['opening_balance_as_of'] ) ) {
			$b[] = array( 'code' => 'opening_date', 'field' => 'opening_balance_as_of', 'message' => 'חסר תאריך יתרת פתיחה' );
		}
		return $b;
	}

	/**
	 * Activate after the user saw the preview. $seen_signature must match the
	 * current balance signature — a preview of a stale balance is not consent.
	 */
	public static function activate( int $case_id, int $version, string $seen_signature ): array {
		if ( ! current_user_can( 'icol_activate_case' ) ) {
			throw new DomainError( 'forbidden', 'אין הרשאה להפעיל תיק', 403 );
		}
		$case = self::get( $case_id );
		if ( ! $case ) {
			throw new DomainError( 'not_found', 'התיק לא נמצא', 404 );
		}
		if ( 'draft' !== $case['workflow_state'] ) {
			throw new DomainError( 'invalid_transition', 'רק טיוטה ניתנת להפעלה', 422 );
		}
		$blockers = self::activation_blockers( $case_id );
		if ( $blockers ) {
			throw new DomainError( 'activation_blocked', 'לא ניתן להפעיל: ' . implode( ' · ', array_column( $blockers, 'message' ) ), 422, array_column( $blockers, 'message', 'field' ) );
		}
		$summary = Ledger::case_summary( $case_id );
		if ( $summary['signature'] !== $seen_signature ) {
			throw new DomainError( 'stale_preview', 'היתרה השתנתה מאז התצוגה המקדימה. יש לצפות שוב לפני הפעלה.', 409 );
		}
		$promise = Db::row( 'SELECT * FROM ' . Db::t( 'promises' ) . " WHERE case_id = %d AND state = 'approved' ORDER BY promised_at DESC LIMIT 1", $case_id );
		$history = (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'imported_history' ) . ' WHERE case_id = %d', $case_id );
		$extra   = array( 'activated_by' => get_current_user_id(), 'activated_at' => Clock::utc(), 'sequence_step' => 0, 'sequence_started_at' => Clock::utc() );

		if ( (int) $case['dispute_open'] ) {
			$case = Workflow::transition( $case_id, 'human_review', 'הופעל עם מחלוקת קיימת — מתחיל בבירור', $version, $extra, 'activate' );
			Tasks::open( 'dispute:' . $case_id, 'dispute', array( 'case_id' => $case_id, 'customer_id' => (int) $case['customer_id'], 'reason' => 'תיק במחלוקת הוזן להמשך טיפול' ) );
		} elseif ( $promise ) {
			$case = Workflow::transition( $case_id, 'promise_hold', 'המשך טיפול עם הבטחה קיימת עד ' . $promise['promised_at'], $version, $extra, 'activate' );
			Promises::schedule_check( $case_id, (int) $promise['id'] );
		} elseif ( 'handover' === $case['entry_mode'] && 0 === $history ) {
			$case = Workflow::transition( $case_id, 'human_review', 'המשך טיפול ללא היסטוריה ברורה — לבדיקת נציג', $version, $extra, 'activate' );
			Tasks::open( 'handover_review:' . $case_id, 'reply_review', array( 'case_id' => $case_id, 'reason' => 'אין היסטוריה מתועדת — נדרש נציג לפני פנייה' ) );
		} else {
			$case = Workflow::transition( $case_id, 'active', 'הפעלת תיק', $version, $extra, 'activate' );
			Messaging::plan_next( $case_id );
		}
		return self::response( $case_id );
	}

	public static function pause( int $case_id, int $version, string $reason ): array {
		if ( '' === trim( $reason ) ) {
			throw new DomainError( 'validation_failed', 'יש לציין סיבת השהיה', 400, array( 'reason' => 'חובה' ) );
		}
		Workflow::transition( $case_id, 'paused', $reason, $version, array(), 'manual' );
		return self::response( $case_id );
	}

	/** §13 חידוש טיפול: fresh evaluation, a new sequence, never the old queued messages. */
	public static function resume( int $case_id, int $version, string $reason ): array {
		$case = self::get( $case_id );
		if ( ! $case ) {
			throw new DomainError( 'not_found', 'התיק לא נמצא', 404 );
		}
		if ( (int) $case['dispute_open'] ) {
			throw new DomainError( 'dispute_open', 'יש מחלוקת פתוחה — יש לסגור אותה לפני חידוש', 422 );
		}
		Workflow::transition( $case_id, 'active', 'חידוש טיפול: ' . $reason, $version, array( 'sequence_step' => 0, 'sequence_started_at' => Clock::utc() ), 'manual' );
		Messaging::plan_next( $case_id );
		return self::response( $case_id );
	}

	public static function close( int $case_id, int $version, string $reason ): array {
		$open = (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'debt_items' ) . " WHERE case_id = %d AND finance_state NOT IN ('settled','cancelled')", $case_id );
		if ( $open > 0 ) {
			throw new DomainError( 'items_open', 'אפשר לסגור תיק רק כשכל הפריטים הוסדרו או בוטלו', 422 );
		}
		Workflow::transition( $case_id, 'closed', $reason ?: 'כל הפריטים הוסדרו', $version, array(), 'manual' );
		return self::response( $case_id );
	}

	public static function set_owner( int $case_id, int $version, ?int $owner_id, string $reason ): array {
		Workflow::touch( $case_id, array( 'owner_id' => $owner_id ), 'case.owner', $reason ?: 'העברת בעלות', $version );
		return self::response( $case_id );
	}

	/** Auto-close when everything settled and no workflow reason to keep it open. */
	public static function maybe_close( int $case_id ): void {
		$case = self::get( $case_id );
		if ( ! $case || in_array( $case['workflow_state'], array( 'closed', 'draft', 'human_review' ), true ) ) {
			return;
		}
		$open = (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'debt_items' ) . " WHERE case_id = %d AND finance_state NOT IN ('settled','cancelled')", $case_id );
		if ( 0 === $open ) {
			Workflow::transition( $case_id, 'closed', 'כל הפריטים הוסדרו', null, array(), 'ledger' );
		}
	}

	public static function response( int $case_id ): array {
		$case = self::get( $case_id );
		return array(
			'case_id'           => $case_id,
			'version'           => (int) $case['version'],
			'workflow_state'    => $case['workflow_state'],
			'finance_summary'   => Ledger::case_summary( $case_id ),
			'validation_errors' => 'draft' === $case['workflow_state'] ? self::activation_blockers( $case_id ) : array(),
			'preview_available' => true,
		);
	}
}

<?php
/** Demo data for screenshots / staging walkthroughs. TRUNCATES icol tables (test DB only). */
require __DIR__ . '/bootstrap.php';
use Insiders\Collections\Domain\Cases;
use Insiders\Collections\Domain\Messaging;
use Insiders\Collections\Domain\Workflow;
use Insiders\Collections\Domain\Payments;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Settings;

T::reset();
Settings::set( array( 'display_only' => 1, 'tranzila_pr_match_field' => 'pr_id', 'support_whatsapp' => '+972 50-000-0000' ) );
T::at( '2026-10-07', '09:30' );
$people = array(
	array( 'דנה כהן', 'דנה', '050-1234567', 'dana@example.test' ),
	array( 'יואב לוי', 'יואב', '052-2223344', 'yoav@example.test' ),
	array( 'מאיה אברהם', 'מאיה', '054-5556677', 'maya@example.test' ),
	array( 'עומר ביטון', 'עומר', '053-8889900', 'omer@example.test' ),
	array( 'נועה פרידמן', 'נועה', '058-1112233', 'noa@example.test' ),
	array( 'איתי שושן', '', '050-7778899', 'itay@example.test' ),
);
$ids = array();
foreach ( $people as $i => $p ) {
	$ids[] = T::customer( array( 'full_name' => $p[0], 'first_name' => $p[1], 'first_name_reliable' => '' !== $p[1], 'phone' => $p[2], 'email' => $p[3] ) );
}
// Recurring failures.
T::order( $ids[0], array( 'charge_dom' => 7, 'sto_id' => '5001' ) );
T::tranzila( array( 'index' => '11001', 'sto_external_id' => '5001', 'sum' => '490.00', 'Response' => '036' ) );
T::order( $ids[1], array( 'charge_dom' => 7, 'sto_id' => '5002' ) );
T::tranzila( array( 'index' => '11002', 'sto_external_id' => '5002', 'sum' => '350.00', 'Response' => '004' ) );
T::tranzila( array( 'index' => '11003', 'sto_external_id' => '9999', 'sum' => '420.00', 'Response' => '004' ) );
T::tick();
// Program cases.
$mk = function ( $cid, $amount, $clar = false ) {
	T::as( 'collector' );
	$r = Cases::create_draft( array( 'customer_id' => $cid, 'source_type' => 'non_open_charge', 'entry_mode' => 'new', 'currency' => 'ILS', 'clarification_first' => $clar, 'approval_basis' => 'לא נפתח חשבון עד המועד לפי סעיף 4 בהסכם ההצטרפות', 'agreement' => array( 'program' => 'תוכנית ליווי למתחילים', 'reference' => 'AGR-' . $cid, 'document_ref' => 'drive://agr-' . $cid, 'joined_at' => '2026-06-15', 'account_open_deadline' => '2026-09-15', 'status_checked_at' => '2026-10-06' ), 'debt_items' => array( array( 'amount' => $amount, 'due_at' => '2026-10-01', 'description' => 'תוכנית הליווי למתחילים' ) ) ) );
	return (int) $r['case_id'];
};
$a = $mk( $ids[2], '980' );
Cases::approve_items( $a, 'אושר מול ההסכם' );
$p = Messaging::preview( $a );
Cases::activate( $a, (int) Workflow::get( $a )['version'], $p['balance_version'] );
$b = $mk( $ids[3], '980', true );
Cases::approve_items( $b, 'אושר' );
$p = Messaging::preview( $b );
Cases::activate( $b, (int) Workflow::get( $b )['version'], $p['balance_version'] );
$draft = $mk( $ids[4], '1,250' );
$c5 = $mk( $ids[5], '980' );
Cases::approve_items( $c5, 'אושר' );
$p = Messaging::preview( $c5 );
Cases::activate( $c5, (int) Workflow::get( $c5 )['version'], $p['balance_version'] );
T::as_admin();
T::tick();
T::inbound( 'שילמתי אתמול בהעברה בנקאית', '972545556677' );
T::inbound( 'אני רוצה לדבר עם נציג', '972507778899' );
T::tick();
Db::update( 'cases', array( 'owner_id' => get_user_by( 'login', 'admin' )->ID ), array( 'id' => $a ) );
echo "seeded\n";
Clock::freeze( null );

<?php
namespace Insiders\Collections\Auth;

use Insiders\Collections\Security\Gate;

defined( 'ABSPATH' ) || exit;

/**
 * Roles of this plugin (§3) live in user meta 'icol_role', NOT as WordPress roles.
 * Changing a WordPress role to open access also changes how other INSIDERS plugins
 * classify the user (paid for in ld-frontend-dashboard). user_has_cap grants the
 * capabilities below from the meta value; administrators get everything.
 */
final class Capabilities {

	public const ROLES = array(
		'viewer'    => array( 'label' => 'צופה', 'caps' => array( 'icol_view' ) ),
		'rep'       => array( 'label' => 'נציג', 'caps' => array( 'icol_view', 'icol_work_case', 'icol_create_draft' ) ),
		'collector' => array( 'label' => 'אחראי גבייה', 'caps' => array( 'icol_view', 'icol_view_all', 'icol_work_case', 'icol_create_draft', 'icol_approve_debt', 'icol_activate_case', 'icol_verify_payment', 'icol_card_confirm', 'icol_reveal_token', 'icol_resolve_exception', 'icol_export' ) ),
		'admin'     => array( 'label' => 'מנהל', 'caps' => array( 'icol_view', 'icol_view_all', 'icol_work_case', 'icol_create_draft', 'icol_approve_debt', 'icol_activate_case', 'icol_verify_payment', 'icol_card_confirm', 'icol_reveal_token', 'icol_resolve_exception', 'icol_export', 'icol_adjust', 'icol_admin' ) ),
	);

	public static function all_caps(): array {
		return array_values( array_unique( array_merge( ...array_values( array_map( static fn( $r ) => $r['caps'], self::ROLES ) ) ) ) );
	}

	public static function register(): void {
		add_filter(
			'user_has_cap',
			static function ( $allcaps, $caps, $args, $user ) {
				if ( ! $user instanceof \WP_User || ! $user->ID ) {
					return $allcaps;
				}
				if ( ! empty( $allcaps['manage_options'] ) ) {
					foreach ( self::all_caps() as $c ) {
						$allcaps[ $c ] = true;
					}
				} else {
					$role = (string) get_user_meta( $user->ID, 'icol_role', true );
					foreach ( self::ROLES[ $role ]['caps'] ?? array() as $c ) {
						$allcaps[ $c ] = true;
					}
				}
				if ( ! empty( $allcaps['icol_view'] ) ) {
					$allcaps['icol_enter'] = true; // opens the lock screen, nothing else
				}
				// The scan gate: for the person making this request, no data capability until a phone approved it.
				if ( ! empty( $allcaps['icol_view'] ) && (int) $user->ID === get_current_user_id() && ! Gate::unlocked() ) {
					foreach ( self::all_caps() as $c ) {
						$allcaps[ $c ] = false;
					}
				}
				return $allcaps;
			},
			10,
			4
		);
	}

	public static function role_of( int $user_id ): string {
		$u = get_userdata( $user_id );
		if ( $u && $u->has_cap( 'manage_options' ) ) {
			return 'admin';
		}
		return (string) get_user_meta( $user_id, 'icol_role', true );
	}

	/** A rep sees cases they own (and the general queue); collectors and admins see all. */
	public static function can_see_case( array $case ): bool {
		if ( current_user_can( 'icol_view_all' ) ) {
			return true;
		}
		if ( ! current_user_can( 'icol_view' ) ) {
			return false;
		}
		$role = self::role_of( get_current_user_id() );
		if ( 'viewer' === $role ) {
			return true; // read-only; mutations are blocked by capability
		}
		return null === $case['owner_id'] || (int) $case['owner_id'] === get_current_user_id();
	}
}

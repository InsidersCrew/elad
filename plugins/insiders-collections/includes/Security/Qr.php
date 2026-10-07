<?php
namespace Insiders\Collections\Security;

defined( 'ABSPATH' ) || exit;

/** QR codes as inline SVG (bacon/bacon-qr-code, SVG back end: no Imagick, no external service). */
final class Qr {

	public static function available(): bool {
		self::load();
		return class_exists( '\BaconQrCode\Writer' );
	}

	private static function load(): void {
		if ( ! class_exists( '\BaconQrCode\Writer' ) && is_readable( ICOL_DIR . 'vendor/autoload.php' ) ) {
			require_once ICOL_DIR . 'vendor/autoload.php';
		}
	}

	public static function svg( string $text, int $size = 232 ): string {
		self::load();
		if ( ! class_exists( '\BaconQrCode\Writer' ) ) {
			return ''; // the screen falls back to showing the link; nothing is sent elsewhere to draw it
		}
		$renderer = new \BaconQrCode\Renderer\ImageRenderer(
			new \BaconQrCode\Renderer\RendererStyle\RendererStyle( $size, 2 ),
			new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
		);
		$svg = ( new \BaconQrCode\Writer( $renderer ) )->writeString( $text, 'UTF-8', \BaconQrCode\Common\ErrorCorrectionLevel::M() );
		return (string) preg_replace( '/^<\?xml[^>]*>\s*/', '', $svg );
	}
}

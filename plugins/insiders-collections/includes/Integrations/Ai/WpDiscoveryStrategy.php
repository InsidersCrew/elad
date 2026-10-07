<?php
namespace Insiders\Collections\Integrations\Ai;

use Http\Discovery\Strategy\DiscoveryStrategy;
use Psr\Http\Client\ClientInterface;

defined( 'ABSPATH' ) || exit;

/**
 * The SDK constructor runs PSR-18 discovery before it looks at the transporter we
 * pass in. This strategy makes discovery find our WordPress-backed client, so no
 * Guzzle is needed (and none can clash with another plugin's copy).
 */
final class WpDiscoveryStrategy implements DiscoveryStrategy {
	public static function getCandidates( $type ) {
		if ( ClientInterface::class === $type ) {
			return array( array( 'class' => WpHttpClient::class, 'condition' => WpHttpClient::class ) );
		}
		return array();
	}
}

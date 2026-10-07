<?php
namespace Insiders\Collections\Integrations\Ai;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

defined( 'ABSPATH' ) || exit;

/**
 * PSR-18 transport over the WordPress HTTP API, handed to the Anthropic SDK.
 * Why: the SDK otherwise discovers Guzzle, and a second Guzzle copy next to
 * another plugin's is a classic WordPress fatal. This keeps one HTTP stack and
 * lets tests intercept with pre_http_request.
 *
 * Loaded only after vendor/autoload.php, only when AI is enabled.
 */
final class WpHttpClient implements ClientInterface {
	public function sendRequest( RequestInterface $request ): ResponseInterface {
		$headers = array();
		foreach ( $request->getHeaders() as $name => $values ) {
			$headers[ $name ] = implode( ', ', $values );
		}
		$res = wp_remote_request(
			(string) $request->getUri(),
			array(
				'method'  => $request->getMethod(),
				'headers' => $headers,
				'body'    => (string) $request->getBody(),
				'timeout' => 45,
			)
		);
		if ( is_wp_error( $res ) ) {
			throw new TransportException( $res->get_error_message(), $request );
		}
		// WP returns a case-insensitive dictionary object; casting it with (array) yields its
		// private properties, not the headers — and without content-type the SDK misreads JSON as a stream.
		$raw_headers = wp_remote_retrieve_headers( $res );
		if ( is_object( $raw_headers ) && method_exists( $raw_headers, 'getAll' ) ) {
			$raw_headers = $raw_headers->getAll();
		}
		$out_headers = array();
		foreach ( (array) $raw_headers as $k => $v ) {
			$out_headers[ (string) $k ] = $v;
		}
		return new Response( (int) wp_remote_retrieve_response_code( $res ), $out_headers, (string) wp_remote_retrieve_body( $res ) );
	}
}

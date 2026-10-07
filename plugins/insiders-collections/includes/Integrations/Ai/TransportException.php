<?php
namespace Insiders\Collections\Integrations\Ai;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

defined( 'ABSPATH' ) || exit;

final class TransportException extends \RuntimeException implements NetworkExceptionInterface {
	private RequestInterface $request;
	public function __construct( string $message, RequestInterface $request ) {
		parent::__construct( $message );
		$this->request = $request;
	}
	public function getRequest(): RequestInterface {
		return $this->request;
	}
}

<?php
namespace Insiders\Collections\Domain;

defined( 'ABSPATH' ) || exit;

/** Business-rule failure with a stable code, a Hebrew message for the UI and an HTTP status. */
final class DomainError extends \RuntimeException {
	public string $error_code;
	public int $status;
	public array $field_errors;
	public bool $retryable;

	public function __construct( string $code, string $message, int $status = 422, array $field_errors = array(), bool $retryable = false ) {
		parent::__construct( $message );
		$this->error_code   = $code;
		$this->status       = $status;
		$this->field_errors = $field_errors;
		$this->retryable    = $retryable;
	}
}

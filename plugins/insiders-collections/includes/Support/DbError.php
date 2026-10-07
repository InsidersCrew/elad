<?php
namespace Insiders\Collections\Support;

defined( 'ABSPATH' ) || exit;

final class DbError extends \RuntimeException {
	public bool $duplicate;
	public function __construct( string $message, bool $duplicate = false ) {
		parent::__construct( $message );
		$this->duplicate = $duplicate;
	}
}

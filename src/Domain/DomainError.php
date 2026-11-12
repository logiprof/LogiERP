<?php
declare(strict_types=1);

namespace LogiERP\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Erreur métier : son message est destiné à l'apprenant (déjà rédigé en français, sans donnée sensible).
 */
final class DomainError extends \RuntimeException {

	/** @var string[] */
	private array $details;

	public function __construct( string $message, array $details = array() ) {
		parent::__construct( $message );
		$this->details = array_values( array_map( 'strval', $details ) );
	}

	/** @return string[] */
	public function details(): array {
		return $this->details;
	}
}

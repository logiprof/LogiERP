<?php
declare(strict_types=1);

namespace LogiERP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Générateur pseudo-aléatoire déterministe (xorshift32) : le même seed donne
 * toujours la même suite, quelle que soit la version de PHP. Indispensable pour
 * des scénarios reproductibles et une correction fiable.
 */
final class Rng {

	private int $state;

	public function __construct( int $seed ) {
		$seed        = $seed & 0xFFFFFFFF;
		$this->state = 0 === $seed ? 0x9E3779B9 : $seed;
		// Quelques tours pour mélanger les seeds proches.
		for ( $i = 0; $i < 8; $i++ ) {
			$this->next();
		}
	}

	public static function for( int $seed, int $stream ): self {
		return new self( ( $seed * 2654435761 + $stream * 40503 + 12345 ) & 0xFFFFFFFF );
	}

	public function next(): int {
		$x  = $this->state;
		$x ^= ( $x << 13 ) & 0xFFFFFFFF;
		$x ^= $x >> 17;
		$x ^= ( $x << 5 ) & 0xFFFFFFFF;
		$this->state = $x & 0xFFFFFFFF;
		return $this->state;
	}

	public function float(): float {
		return $this->next() / 4294967296.0;
	}

	public function int( int $min, int $max ): int {
		if ( $max <= $min ) {
			return $min;
		}
		return $min + (int) floor( $this->float() * ( $max - $min + 1 ) );
	}

	public function range( float $min, float $max ): float {
		return $min + $this->float() * ( $max - $min );
	}

	public function chance( float $p ): bool {
		return $this->float() < $p;
	}

	/**
	 * @template T
	 * @param array<int,T> $items
	 * @return T
	 */
	public function pick( array $items ) {
		$items = array_values( $items );
		return $items[ $this->int( 0, count( $items ) - 1 ) ];
	}

	/** Mélange de Fisher-Yates déterministe. */
	public function shuffle( array $items ): array {
		$items = array_values( $items );
		for ( $i = count( $items ) - 1; $i > 0; $i-- ) {
			$j           = $this->int( 0, $i );
			$tmp         = $items[ $i ];
			$items[ $i ] = $items[ $j ];
			$items[ $j ] = $tmp;
		}
		return $items;
	}

	/** Valeur log-uniforme entre deux bornes positives (prix, poids). */
	public function log_range( float $min, float $max ): float {
		return exp( $this->range( log( $min ), log( $max ) ) );
	}
}

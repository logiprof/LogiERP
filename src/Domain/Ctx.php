<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;
use LogiERP\Support\Rng;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contexte d'exécution d'une transaction : la session de l'apprenant.
 */
final class Ctx {

	public int $id;
	public array $session;
	public array $scenario;

	public function __construct( array $session, array $scenario ) {
		$this->id       = (int) $session['id'];
		$this->session  = $session;
		$this->scenario = $scenario;
	}

	public function date(): string {
		return (string) $this->session['sim_date'];
	}

	public function simple(): bool {
		return 'SIMPLE' === $this->session['location_mode'];
	}

	public function allow_override(): bool {
		return ! empty( $this->scenario['allow_strategy_override'] );
	}

	/** Prélèvement : chiffre de contrôle de l'emplacement obligatoire (mini-entrepôt). */
	public function check_digits(): bool {
		return ! empty( $this->scenario['check_digits'] );
	}

	public function hv_key(): bool {
		return ! empty( $this->scenario['hv_key'] );
	}

	public function modules(): array {
		return array_filter( array_map( 'trim', explode( ',', (string) $this->scenario['modules'] ) ) );
	}

	/** Générateur reproductible : seed de session + compteur persistant. */
	public function rng(): Rng {
		global $wpdb;
		$t = Db::t( 'session' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET rng_counter = rng_counter + 1 WHERE id = %d", $this->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counter = (int) $wpdb->get_var( $wpdb->prepare( "SELECT rng_counter FROM {$t} WHERE id = %d", $this->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return Rng::for( (int) $this->session['seed'], $counter );
	}

	public function doc_map(): array {
		$map = json_decode( (string) ( $this->session['doc_map'] ?? '' ), true );
		return is_array( $map ) ? $map : array();
	}

	public function save_doc_map( array $map ): void {
		Db::update( 'session', array( 'doc_map' => wp_json_encode( $map ) ), array( 'id' => $this->id ) );
		$this->session['doc_map'] = wp_json_encode( $map );
	}

	public function writable(): bool {
		return 'EN_COURS' === $this->session['status'] && empty( $this->session['paused'] );
	}

	/** Contrôle d'accès à un magasin sous clé. */
	public function require_key_for( array $loc ): void {
		if ( ! empty( $loc['secured'] ) && ! $this->hv_key() ) {
			throw new DomainError( sprintf( 'Le magasin %s est sous clé : votre habilitation ne permet pas d\'y effectuer des mouvements dans ce scénario. Demandez à votre responsable.', $loc['code'] ) );
		}
	}
}

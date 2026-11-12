<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mouvements de stock directs (MIGO) : transfert, changement de statut, sorties diverses.
 */
final class StockOps {

	/** Changements de statut autorisés et leur code mouvement. */
	public const STATUS_MOVES = array(
		'CONTROLE_QUALITE>LIBRE' => '321',
		'LIBRE>CONTROLE_QUALITE' => '322',
		'BLOQUE>LIBRE'           => '343',
		'LIBRE>BLOQUE'           => '344',
		'CONTROLE_QUALITE>BLOQUE' => '350',
	);

	private static function source( Ctx $ctx, int $stock_id, float $qty ): array {
		$row = StockService::row( $ctx->id, $stock_id );
		if ( ! $row ) {
			throw new DomainError( 'Ligne de stock introuvable.' );
		}
		if ( $qty <= 0 || $qty > (float) $row['qty'] + 0.0005 ) {
			throw new DomainError( sprintf( 'Quantité invalide : disponible %s sur cette ligne.', Catalog::format_qty( (float) $row['qty'] ) ) );
		}
		$loc = Catalog::location( (int) $row['storage_location_id'] );
		$ctx->require_key_for( $loc );
		if ( (int) $row['bin_id'] ) {
			$bin = Catalog::bin( (int) $row['bin_id'] );
			if ( $bin && '' !== Rules::bin_blocked( $ctx->id, $bin, 'out' ) ) {
				throw new DomainError( sprintf( 'L\'emplacement %s est bloqué en sortie.', $bin['code'] ) );
			}
		}
		return array( $row, Catalog::material( (int) $row['material_id'] ), $loc );
	}

	/** Transfert vers un autre magasin (311) ou un autre emplacement (999). */
	public static function transfer( Ctx $ctx, int $stock_id, float $qty, string $dst_location_code, string $dst_bin_code = '' ): string {
		[ $row, $m, $loc ] = self::source( $ctx, $stock_id, $qty );
		$dst_loc = Catalog::location_by_code( $dst_location_code );
		$dst_bin = null;
		if ( ! $ctx->simple() ) {
			$dst_bin = Catalog::bin_by_code( $dst_bin_code );
			if ( ! $dst_bin ) {
				throw new DomainError( 'En gestion détaillée des emplacements, indiquez l\'emplacement de destination.' );
			}
			$dst_loc = Catalog::location( (int) $dst_bin['storage_location_id'] );
		}
		if ( ! $dst_loc ) {
			throw new DomainError( 'Magasin de destination inconnu.' );
		}
		$ctx->require_key_for( $dst_loc );
		$errors = $dst_bin ? Rules::check_bin( $ctx->id, $m, $dst_bin, $qty, (string) $row['batch'] ) : Rules::check_location( $m, $dst_loc );
		if ( $dst_bin && (int) $dst_bin['id'] === (int) $row['bin_id'] ) {
			$errors[] = 'La destination est identique à la source.';
		}
		if ( ! $dst_bin && (int) $dst_loc['id'] === (int) $row['storage_location_id'] ) {
			$errors[] = 'Le magasin de destination est identique à la source.';
		}
		if ( $errors ) {
			throw new DomainError( 'Transfert refusé.', $errors );
		}
		$mvt = (int) $dst_loc['id'] === (int) $row['storage_location_id'] ? '999' : '311';
		return StockService::post(
			$ctx->id,
			array( 'posting_date' => $ctx->date(), 'ref_type' => 'MIGO', 'text' => 'Transfert' ),
			array(
				array( 'movement_type' => $mvt, 'material_id' => (int) $m['id'], 'location_id' => (int) $row['storage_location_id'], 'bin_id' => (int) $row['bin_id'], 'batch' => $row['batch'], 'bbd' => $row['bbd'], 'status' => $row['status'], 'qty' => -$qty ),
				array( 'movement_type' => $mvt, 'material_id' => (int) $m['id'], 'location_id' => (int) $dst_loc['id'], 'bin_id' => $dst_bin ? (int) $dst_bin['id'] : 0, 'batch' => $row['batch'], 'bbd' => $row['bbd'], 'status' => $row['status'], 'qty' => $qty, 'gr_date' => $row['gr_date'] ),
			)
		);
	}

	public static function change_status( Ctx $ctx, int $stock_id, float $qty, string $new_status, string $reason = '' ): string {
		[ $row ] = self::source( $ctx, $stock_id, $qty );
		$key     = $row['status'] . '>' . strtoupper( $new_status );
		if ( ! isset( self::STATUS_MOVES[ $key ] ) ) {
			throw new DomainError( sprintf( 'Changement de statut %s → %s non prévu. Passez par le stock libre.', $row['status'], strtoupper( $new_status ) ) );
		}
		if ( 'LIBRE' === strtoupper( $new_status ) && $row['bbd'] && $row['bbd'] < $ctx->date() ) {
			throw new DomainError( 'Lot périmé : il ne peut pas être libéré. Mettez-le au rebut.' );
		}
		$mvt = self::STATUS_MOVES[ $key ];
		return StockService::post(
			$ctx->id,
			array( 'posting_date' => $ctx->date(), 'ref_type' => 'MIGO', 'text' => 'Changement de statut' ),
			array(
				array( 'movement_type' => $mvt, 'material_id' => (int) $row['material_id'], 'location_id' => (int) $row['storage_location_id'], 'bin_id' => (int) $row['bin_id'], 'batch' => $row['batch'], 'bbd' => $row['bbd'], 'status' => $row['status'], 'qty' => -$qty, 'reason' => $reason ),
				array( 'movement_type' => $mvt, 'material_id' => (int) $row['material_id'], 'location_id' => (int) $row['storage_location_id'], 'bin_id' => (int) $row['bin_id'], 'batch' => $row['batch'], 'bbd' => $row['bbd'], 'status' => strtoupper( $new_status ), 'qty' => $qty, 'gr_date' => $row['gr_date'], 'reason' => $reason ),
			)
		);
	}

	/** Sorties diverses : rebut (551), centre de coûts (201), échantillon (333). */
	public static function issue( Ctx $ctx, int $stock_id, float $qty, string $movement, string $reason ): string {
		if ( ! in_array( $movement, array( '551', '201', '333' ), true ) ) {
			throw new DomainError( 'Type de sortie invalide.' );
		}
		if ( '' === trim( $reason ) ) {
			throw new DomainError( 'Indiquez le motif de la sortie.' );
		}
		[ $row ] = self::source( $ctx, $stock_id, $qty );
		return StockService::post(
			$ctx->id,
			array( 'posting_date' => $ctx->date(), 'ref_type' => 'MIGO', 'text' => StockService::MOVEMENTS[ $movement ] ),
			array( array( 'movement_type' => $movement, 'material_id' => (int) $row['material_id'], 'location_id' => (int) $row['storage_location_id'], 'bin_id' => (int) $row['bin_id'], 'batch' => $row['batch'], 'bbd' => $row['bbd'], 'status' => $row['status'], 'qty' => -$qty, 'reason' => mb_substr( $reason, 0, 120 ) ) )
		);
	}
}

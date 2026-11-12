<?php
declare(strict_types=1);

namespace LogiERP\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Accès centralisé aux noms de tables et aux transactions SQL.
 * Seules les classes Repository / Domain / Seeder utilisent $wpdb, toujours via prepare().
 */
final class Db {

	/** Liste blanche des tables du plugin (utilisée aussi par la désinstallation). */
	public const TABLES = array(
		'dataset', 'material_group', 'storage_profile', 'material', 'supplier', 'supplier_material', 'customer',
		'bom', 'bom_item', 'carrier', 'site', 'storage_location', 'aisle', 'bin',
		'scenario', 'scenario_initial_stock', 'scenario_document', 'scenario_event', 'scenario_task', 'scenario_check',
		'class', 'class_member', 'class_group', 'class_group_member', 'assignment', 'assignment_team', 'assignment_member', 'session', 'session_bin_state', 'session_event_log', 'action_log',
		'stock', 'purchase_order', 'purchase_order_item', 'sales_order', 'sales_order_item',
		'outbound_delivery', 'outbound_delivery_item', 'transfer_order', 'transfer_order_item',
		'production_order', 'production_component', 'inventory_doc', 'inventory_item',
		'material_doc', 'material_doc_item', 'return_doc', 'return_item',
		'number_range', 'audit_log', 'job', 'session_photo', 'inspection_lot', 'vehicle', 'shipment', 'shipment_stop', 'packaging_account', 'packaging_move', 'handling_unit', 'handling_unit_item', 'intervention',
		'change_log', 'output_msg', 'prod_confirmation', 'container', 'container_move',
	);

	public static function t( string $name ): string {
		global $wpdb;
		if ( ! in_array( $name, self::TABLES, true ) ) {
			throw new \InvalidArgumentException( 'Table inconnue : ' . esc_html( $name ) );
		}
		return $wpdb->prefix . 'lw_' . $name;
	}

	public static function begin(): void {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
	}

	public static function commit(): void {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
	}

	public static function rollback(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
	}

	/**
	 * Exécute $fn dans une transaction. Toute exception annule l'ensemble.
	 *
	 * @template T
	 * @param callable():T $fn
	 * @return T
	 */
	public static function transaction( callable $fn ) {
		// Transactions imbriquées : seule la plus externe ouvre et valide.
		if ( self::$depth > 0 ) {
			++self::$depth;
			try {
				return $fn();
			} finally {
				--self::$depth;
			}
		}
		self::begin();
		self::$depth = 1;
		try {
			$result = $fn();
			self::$depth = 0;
			self::commit();
			return $result;
		} catch ( \Throwable $e ) {
			self::$depth = 0;
			self::rollback();
			throw $e;
		}
	}

	private static int $depth = 0;

	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/** Insère une ligne et retourne son ID, ou lance une exception. */
	public static function insert( string $table, array $data ): int {
		global $wpdb;
		$ok = $wpdb->insert( self::t( $table ), $data );
		if ( false === $ok ) {
			\LogiERP\Support\Logger::error( 'Insertion ' . $table . ' : ' . $wpdb->last_error );
			throw new \RuntimeException( 'Erreur d\'écriture en base (' . esc_html( $table ) . ').' );
		}
		return (int) $wpdb->insert_id;
	}

	public static function update( string $table, array $data, array $where ): void {
		global $wpdb;
		$ok = $wpdb->update( self::t( $table ), $data, $where );
		if ( false === $ok ) {
			\LogiERP\Support\Logger::error( 'Mise à jour ' . $table . ' : ' . $wpdb->last_error );
			throw new \RuntimeException( 'Erreur de mise à jour en base (' . esc_html( $table ) . ').' );
		}
	}

	/**
	 * Insertion groupée préparée (seeder). $rows : liste de tableaux avec les mêmes clés.
	 * $formats : formats par colonne (%s, %d, %f).
	 */
	public static function bulk_insert( string $table, array $columns, array $formats, array $rows ): void {
		global $wpdb;
		if ( ! $rows ) {
			return;
		}
		$t      = self::t( $table );
		$cols   = implode( ',', array_map( static fn( $c ) => '`' . preg_replace( '/[^a-z0-9_]/', '', $c ) . '`', $columns ) );
		$place  = '(' . implode( ',', $formats ) . ')';
		foreach ( array_chunk( $rows, 200 ) as $chunk ) {
			$values = array();
			$args   = array();
			foreach ( $chunk as $row ) {
				$values[] = $place;
				foreach ( $columns as $c ) {
					$args[] = $row[ $c ];
				}
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- noms de tables/colonnes en liste blanche, valeurs préparées.
			$sql = $wpdb->prepare( "INSERT INTO {$t} ({$cols}) VALUES " . implode( ',', $values ), $args );
			if ( false === $wpdb->query( $sql ) ) {
				\LogiERP\Support\Logger::error( 'Insertion groupée ' . $table . ' : ' . $wpdb->last_error );
				throw new \RuntimeException( 'Erreur d\'insertion groupée (' . esc_html( $table ) . ').' );
			}
		}
	}
}

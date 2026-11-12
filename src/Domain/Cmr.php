<?php
declare(strict_types=1);

namespace LogiERP\Domain;

use LogiERP\Core\Db;
use LogiERP\Seeder\Dictionaries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lettre de voiture internationale CMR (Convention relative au contrat de transport international
 * de marchandises par route, Genève 1956), établie pour chaque commande d'un fournisseur étranger.
 * Les cases 1 à 15 sont remplies par l'expéditeur, 16 à 19 et 23 par le transporteur,
 * la case 24 par le destinataire à la réception (l'apprenant·e).
 */
final class Cmr {

	/** Pays d'où la marchandise arrive sans passage de frontière douanière (pas de CMR). */
	public const DOMESTIC = array( 'CH', 'LI' );

	public static function is_international( ?array $supplier ): bool {
		return $supplier && ! in_array( (string) $supplier['country'], self::DOMESTIC, true );
	}

	/** Commandes ouvertes de fournisseurs étrangers (arrivages internationaux attendus). */
	public static function open_list( Ctx $ctx ): array {
		global $wpdb;
		$po  = Db::t( 'purchase_order' );
		$su  = Db::t( 'supplier' );
		$in  = implode( ',', array_fill( 0, count( self::DOMESTIC ), '%s' ) );
		$sql = "SELECT o.po_no, o.order_date, o.status, o.cmr_signed_at, s.name, s.city, s.country FROM {$po} o JOIN {$su} s ON s.id = o.supplier_id WHERE o.session_id = %d AND s.country NOT IN ({$in}) ORDER BY (o.status IN ('ENVOYEE','PARTIELLEMENT_RECUE')) DESC, o.order_date DESC, o.po_no DESC LIMIT 100";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $ctx->id ), self::DOMESTIC ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map(
			static fn( $r ) => array(
				'no'      => $r['po_no'],
				'cmr_no'  => self::number( $r['country'], $r['po_no'] ),
				'partner' => $r['name'],
				'from'    => $r['country'] . '-' . $r['city'],
				'date'    => $r['order_date'],
				'status'  => $r['status'],
				'signed'  => null !== $r['cmr_signed_at'] ? 1 : 0,
			),
			(array) $rows
		);
	}

	/** Numéro de la lettre de voiture : stable pour une commande donnée. */
	public static function number( string $country, string $po_no ): string {
		return $country . ' ' . str_pad( (string) ( abs( crc32( 'cmr' . $po_no ) ) % 9000000 + 1000000 ), 7, '0', STR_PAD_LEFT );
	}

	/** Contenu complet de la lettre de voiture d'une commande. */
	public static function build( Ctx $ctx, string $po_no ): array {
		global $wpdb;
		$po = Purchasing::by_no( $ctx, $po_no );
		if ( ! $po ) {
			throw new DomainError( sprintf( 'Commande d\'achat %s introuvable.', $po_no ) );
		}
		$sup = Purchasing::supplier( (int) $po['supplier_id'] );
		if ( ! self::is_international( $sup ) ) {
			throw new DomainError( sprintf( 'La commande %s vient d\'un fournisseur suisse : la marchandise arrive avec un bon de livraison, sans lettre de voiture CMR.', $po_no ) );
		}
		$country = (string) $sup['country'];
		$it      = Db::t( 'purchase_order_item' );
		$items   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$it} WHERE po_id = %d ORDER BY line_no", (int) $po['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$lines  = array();
		$weight = 0.0;
		$volume = 0.0;
		$adr    = array();
		$temp   = array();
		foreach ( (array) $items as $i ) {
			$m    = Catalog::material( (int) ( $i['delivered_material_id'] ?: $i['material_id'] ) );
			$open = max( 0.0, (float) $i['qty'] - (float) $i['qty_received'] );
			$qty  = null !== $i['qty_delivered_note'] ? (float) $i['qty_delivered_note'] : $open;
			if ( ! $m || $qty <= 0 ) {
				continue;
			}
			$upc     = max( 1, (int) $m['units_per_carton'] );
			$cpp     = max( 1, (int) $m['cartons_per_pallet'] );
			$cartons = (int) ceil( $qty / $upc );
			$pallets = $cpp > 1 && $cartons >= $cpp / 2 ? (int) ceil( $cartons / $cpp ) : 0;
			$gross   = $qty * (float) $m['gross_weight'] + $pallets * 25.0;
			$vol     = $qty * (float) $m['volume_l'] / 1000;
			$nature  = (string) $m['designation'];
			if ( '' !== (string) $m['adr_class'] ) {
				$nature .= sprintf( ' – UN %s %s, %s%s, (E)', $m['un_number'], $m['hazard_text'] ?: 'marchandise dangereuse', $m['adr_class'], $m['packing_group'] ? ', GE ' . $m['packing_group'] : '' );
				$adr[]   = 'UN ' . $m['un_number'];
			}
			if ( in_array( $m['profile_code'], array( 'COLD', 'FROZ', 'TEMP' ), true ) ) {
				$temp[ $m['profile_code'] ] = true;
			}
			$lines[] = array(
				'marks'   => sprintf( '%s / pos. %d', $po['po_no'], (int) $i['line_no'] ),
				'count'   => $pallets ?: $cartons,
				'packing' => $pallets ? ( 1 === $pallets ? 'palette EUR' : 'palettes EUR' ) . sprintf( ' (%d cartons)', $cartons ) : ( 1 === $cartons ? 'carton' : 'cartons' ),
				'nature'  => $nature,
				'article' => $m['material_no'],
				'qty'     => $qty,
				'uom'     => $m['base_uom'],
				'weight'  => round( $gross, 1 ),
				'volume'  => round( $vol, 3 ),
			);
			$weight += $gross;
			$volume += $vol;
		}
		if ( ! $lines ) {
			throw new DomainError( sprintf( 'La commande %s n\'a plus rien à livrer.', $po_no ) );
		}

		// Transporteur et dates (déterministes pour une commande).
		// Même transporteur et mêmes numéros dans toutes les sessions du scénario (documents imprimés identiques).
		$h        = abs( crc32( $po['po_no'] . ( (int) ( $ctx->scenario['id'] ?? 0 ) ?: $ctx->id ) ) );
		$hauliers = Dictionaries::foreign_hauliers()[ $country ] ?? Dictionaries::foreign_hauliers()['DE'];
		$carrier  = $hauliers[ $h % count( $hauliers ) ];
		$drivers  = array(
			'DE' => array( 'Andreas Vogt', 'Mehmet Yilmaz', 'Kurt Steiner' ),
			'FR' => array( 'Laurent Garnier', 'Karim Benali', 'Sébastien Roux' ),
			'IT' => array( 'Paolo Greco', 'Davide Conti' ),
			'AT' => array( 'Thomas Egger', 'Ivan Petrović' ),
			'NL' => array( 'Jan de Boer', 'Ruben Smit' ),
			'BE' => array( 'Pieter Maes', 'Youssef El Amrani' ),
			'PL' => array( 'Dariusz Wiśniewski', 'Tomasz Zieliński' ),
			'CZ' => array( 'Tomáš Dvořák', 'Martin Kučera' ),
		)[ (string) $carrier[4] ] ?? array( 'Andreas Vogt' );
		$transit  = in_array( $country, array( 'PL', 'CZ', 'NL', 'BE', 'ES' ), true ) ? 2 : 1;
		$arrival  = $ctx->date();
		$pickup   = gmdate( 'Y-m-d', strtotime( $arrival . ' -' . $transit . ' days' ) );
		if ( $pickup < $po['order_date'] ) {
			$pickup = $po['order_date'];
		}
		$incoterm = (string) ( $sup['incoterm'] ?: 'DAP' );
		$franco   = in_array( $incoterm, array( 'DAP', 'DDP', 'CPT' ), true );
		[ $cname, $cstreet, $ccity ] = Company::delivery_address();

		$instr = array( sprintf( 'Dédouanement à l\'importation : bureau de douane %s. Déclarant : %s (Passar).', Dictionaries::border_office( $country ), $cname ) );
		if ( $adr ) {
			$instr[] = 'Marchandises dangereuses selon ADR (' . implode( ', ', array_unique( $adr ) ) . ') : véhicule signalé, consignes écrites à bord.';
		}
		if ( isset( $temp['FROZ'] ) ) {
			$instr[] = 'Transport frigorifique : maintenir entre -25 et -18 °C.';
		} elseif ( isset( $temp['COLD'] ) ) {
			$instr[] = 'Transport frigorifique : maintenir entre +2 et +8 °C.';
		} elseif ( isset( $temp['TEMP'] ) ) {
			$instr[] = 'Température dirigée : entre +15 et +25 °C.';
		}
		$instr[] = 'Livraison au quai de réception, du lundi au vendredi de 05 h 30 à 14 h 00.';

		$sig = null;
		if ( null !== $po['cmr_signed_at'] ) {
			$u   = get_userdata( (int) $po['cmr_signed_by'] );
			$sig = array(
				// Date simulée de la session (comme le reste du document), heure locale réelle de la signature.
				'at'       => $arrival . ' ' . wp_date( 'H:i', (int) strtotime( $po['cmr_signed_at'] . ' UTC' ) ),
				'by'       => $u ? $u->display_name : '',
				'reserves' => (string) $po['cmr_reserves'],
				'place'    => trim( (string) preg_replace( '/^\d[\d\s]*/', '', $ccity ) ),
			);
		}
		$invoice = 'INV-' . substr( (string) $h, 0, 6 );
		return array(
			'no'          => self::number( $country, $po['po_no'] ),
			'po_no'       => $po['po_no'],
			'status'      => $po['status'],
			'sender'      => array( $sup['name'], $sup['street'], $sup['zip'] . ' ' . $sup['city'], self::country_name( $country ) ),
			'consignee'   => array( $cname, $cstreet, $ccity, 'Suisse' ),
			'delivery'    => trim( $ccity ) . ', Suisse',
			'pickup'      => array( 'place' => $sup['city'] . ', ' . self::country_name( $country ), 'date' => $pickup ),
			'documents'   => sprintf( 'Facture commerciale %s avec déclaration d\'origine ; bulletin de livraison BL-%s', $invoice, $po['po_no'] ),
			'lines'       => $lines,
			'total'       => array( 'count' => array_sum( array_column( $lines, 'count' ) ), 'weight' => round( $weight, 1 ), 'volume' => round( $volume, 3 ) ),
			'instructions' => implode( "\n", $instr ),
			'payment'     => $franco ? 'Franco' : 'Non franco',
			'payer'       => $franco ? 'Expéditeur' : 'Destinataire',
			'agreements'  => sprintf( 'Incoterms® 2020 %s %s', $incoterm, $franco ? trim( (string) preg_replace( '/^\d[\d\s]*/', '', $ccity ) ) : $sup['city'] ),
			'carrier'     => array( $carrier[0], $carrier[1], $carrier[2] . ' ' . $carrier[3], self::country_name( $carrier[4] ) ),
			'plates'      => $carrier[5] . ' / ' . $carrier[6],
			'driver'      => $drivers[ $h % count( $drivers ) ],
			'established' => array( 'place' => $sup['city'], 'date' => $pickup ),
			'arrival'     => $arrival,
			'signature'   => $sig,
			'can_sign'    => null === $sig && $ctx->writable() && Teams::is_participant( $ctx->session, get_current_user_id() ),
		);
	}

	/** Case 24 : le destinataire confirme la réception, avec ou sans réserves. */
	public static function sign( Ctx $ctx, string $po_no, string $reserves ): array {
		$po = Purchasing::by_no( $ctx, $po_no );
		if ( ! $po ) {
			throw new DomainError( sprintf( 'Commande d\'achat %s introuvable.', $po_no ) );
		}
		if ( ! self::is_international( Purchasing::supplier( (int) $po['supplier_id'] ) ) ) {
			throw new DomainError( 'Cette commande ne voyage pas avec une lettre de voiture CMR.' );
		}
		if ( null !== $po['cmr_signed_at'] ) {
			throw new DomainError( 'La lettre de voiture est déjà signée : une signature ne peut pas être modifiée.' );
		}
		if ( 'ANNULEE' === $po['status'] ) {
			throw new DomainError( 'Commande annulée : il n\'y a rien à réceptionner.' );
		}
		$reserves = trim( $reserves );
		Db::update(
			'purchase_order',
			array(
				'cmr_signed_at' => Db::now(),
				'cmr_signed_by' => get_current_user_id(),
				'cmr_reserves'  => mb_substr( $reserves, 0, 255 ),
			),
			array( 'id' => (int) $po['id'] )
		);
		$no = self::number( (string) Purchasing::supplier( (int) $po['supplier_id'] )['country'], $po['po_no'] );
		return array(
			'no'      => $po['po_no'],
			'message' => '' === $reserves
				? sprintf( 'Lettre de voiture %s signée sans réserve.', $no )
				: sprintf( 'Lettre de voiture %s signée avec réserves.', $no ),
		);
	}

	public static function country_name( string $code ): string {
		return MasterData::COUNTRIES[ $code ] ?? $code;
	}
}

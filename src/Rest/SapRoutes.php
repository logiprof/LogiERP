<?php
declare(strict_types=1);

namespace LogiERP\Rest;

use LogiERP\Domain\Api;
use LogiERP\Domain\Containers;
use LogiERP\Domain\Ctx;
use LogiERP\Domain\ProcessOrders;
use LogiERP\Domain\PurchasingTx;
use LogiERP\Domain\ShippingTx;
use LogiERP\Domain\StockTx;
use LogiERP\Domain\Transport;
use LogiERP\Domain\TransportPlan;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API des transactions SAP reprises des procédures de l'entreprise (version 2.0).
 * Mêmes garanties que Routes : capacité vérifiée, propriété de la session (anti-IDOR), nonce REST,
 * entrées nettoyées et bornées, journalisation de chaque écriture, limite de débit.
 */
final class SapRoutes {

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	private static function ids( WP_REST_Request $r, string $key, int $max = 50 ): array {
		$v = $r->get_param( $key );
		return is_array( $v ) ? array_slice( array_values( array_filter( array_map( static fn( $x ) => is_numeric( $x ) ? (int) $x : 0, $v ) ) ), 0, $max ) : array();
	}

	private static function codes( WP_REST_Request $r, string $key, int $max = 30 ): array {
		$v = $r->get_param( $key );
		return is_array( $v ) ? array_slice( array_values( array_filter( array_map( static fn( $x ) => is_scalar( $x ) ? strtoupper( (string) preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $x ) ) : '', $v ) ) ), 0, $max ) : array();
	}

	private static function date( WP_REST_Request $r, string $key ): string {
		$d = Routes::str( $r, $key, 10 );
		return Api::valid_date( $d ) ? $d : '';
	}

	private static function flag( WP_REST_Request $r, string $key ): bool {
		$v = $r->get_param( $key );
		return true === $v || 1 === $v || '1' === $v || 'true' === $v;
	}

	public function routes(): void {
		$play = array( Routes::class, 'can_play' );
		$sid  = '/s/(?P<sid>\d+)';
		$no   = '(?P<no>[A-Za-z0-9\-]{1,20})';
		$mno  = '(?P<mno>[A-Za-z0-9]{1,20})';
		$get  = static fn( $path, $cb ) => register_rest_route( Routes::NS, $sid . $path, array( 'methods' => 'GET', 'callback' => $cb, 'permission_callback' => $play ) );
		$post = static fn( $path, $cb ) => register_rest_route( Routes::NS, $sid . $path, array( 'methods' => 'POST', 'callback' => $cb, 'permission_callback' => $play ) );

		// Lot 1 : stock et corrections.
		$post( '/mb11', array( $this, 'mb11' ) );
		$get( '/mb03/' . $no, array( $this, 'mb03' ) );
		$post( '/mbst', array( $this, 'mbst' ) );
		$get( '/ls26', array( $this, 'ls26' ) );
		$post( '/ls26/putaway', array( $this, 'ls26_putaway' ) );
		$post( '/lt13', array( $this, 'lt13' ) );
		$post( '/lt15', array( $this, 'lt15' ) );
		$get( '/li21', array( $this, 'li21_list' ) );
		$post( '/li21', array( $this, 'li21' ) );
		$post( '/output', array( $this, 'output' ) );
		$get( '/outputs', array( $this, 'outputs' ) );

		// Lot 2 : achat et réception.
		$get( '/me2l', array( $this, 'me2l' ) );
		$get( '/arrivals', array( $this, 'arrivals' ) );
		$get( '/po2/' . $no, array( $this, 'po2' ) );
		$post( '/po2/' . $no . '/change', array( $this, 'po2_change' ) );
		$get( '/po2/' . $no . '/gr-doc', array( $this, 'po2_grdoc' ) );
		$get( '/bulletins', array( $this, 'bulletins' ) );
		$get( '/bulletin/' . $no, array( $this, 'bulletin' ) );
		$post( '/po-transfer', array( $this, 'po_transfer' ) );

		// Lot 3 : expédition et vente.
		$get( '/dlv2/' . $no, array( $this, 'dlv2' ) );
		$post( '/dlv2/' . $no . '/change', array( $this, 'dlv2_change' ) );
		$post( '/dlv2/' . $no . '/create-to', array( $this, 'dlv2_to' ) );
		$post( '/dlv2/' . $no . '/delete', array( $this, 'dlv2_delete' ) );
		$get( '/dlv2/' . $no . '/note', array( $this, 'dlv2_note' ) );
		$get( '/dlv2/' . $no . '/cert/(?P<item>\d+)', array( $this, 'dlv2_cert' ) );
		$get( '/vl06o', array( $this, 'vl06o' ) );
		$post( '/vl06o/issue', array( $this, 'vl06o_issue' ) );
		$post( '/vl09', array( $this, 'vl09' ) );
		$get( '/vl74/' . $no, array( $this, 'vl74' ) );
		$get( '/so2/' . $no, array( $this, 'so2' ) );
		$post( '/so2/' . $no . '/change', array( $this, 'so2_change' ) );
		$post( '/so2/' . $no . '/availability', array( $this, 'so2_avail' ) );
		$get( '/va05', array( $this, 'va05' ) );

		// Lot 4 : production par procédé.
		$post( '/cor1', array( $this, 'cor1' ) );
		$get( '/cor8', array( $this, 'cor8' ) );
		$get( '/cor/' . $no, array( $this, 'cor' ) );
		$post( '/cor/' . $no . '/change', array( $this, 'cor_change' ) );
		$post( '/cor/' . $no . '/close', array( $this, 'cor_close' ) );
		$post( '/cor5', array( $this, 'cor5' ) );
		$get( '/cork/' . $no, array( $this, 'cork_get' ) );
		$post( '/cork/' . $no, array( $this, 'cork' ) );
		$post( '/cors/' . $no, array( $this, 'cors' ) );
		$get( '/cs03/' . $mno, array( $this, 'cs03' ) );
		$get( '/cs15/' . $mno, array( $this, 'cs15' ) );

		// Lot 5 : emballages et portails.
		$get( '/containers', array( $this, 'containers' ) );
		$get( '/containers/(?P<cid>\d+)/history', array( $this, 'container_history' ) );
		$get( '/serial-materials', array( $this, 'serial_materials' ) );
		$post( '/zvlgut/return', array( $this, 'zvlgut_return' ) );
		$post( '/zvlgut/check', array( $this, 'zvlgut_check' ) );
		$get( '/portal', array( $this, 'portal' ) );
		$post( '/portal', array( $this, 'portal_declare' ) );
		$post( '/portal/(?P<mid>\d+)/cancel', array( $this, 'portal_cancel' ) );
		$get( '/wagons', array( $this, 'wagons' ) );
		$get( '/pack-codes', array( $this, 'pack_codes' ) );

		// Lot 6 : planification des transports.
		$get( '/vl06t', array( $this, 'vl06t' ) );
		$get( '/vt11', array( $this, 'vt11' ) );
		$post( '/vt11/assign', array( $this, 'vt11_assign' ) );
		$post( '/vt11/unassign', array( $this, 'vt11_unassign' ) );
		$get( '/vt04', array( $this, 'vt04' ) );
		$post( '/vt04', array( $this, 'vt04_create' ) );
		$get( '/shp/' . $no, array( $this, 'shp' ) );
		$post( '/shp/' . $no . '/delete', array( $this, 'shp_delete' ) );
		$post( '/shp/' . $no . '/retime', array( $this, 'shp_retime' ) );
		$post( '/shp/' . $no . '/handover', array( $this, 'shp_handover' ) );
		$get( '/vt70/' . $no, array( $this, 'vt70' ) );
		$get( '/vl71', array( $this, 'vl71' ) );
		$post( '/vl71/print', array( $this, 'vl71_print' ) );
	}

	/* ───────── Lot 6 ───────── */

	private static function day( WP_REST_Request $r, Ctx $ctx ): string {
		$d = self::date( $r, 'date' );
		return '' !== $d ? $d : $ctx->date();
	}

	public function vl06t( WP_REST_Request $r ): WP_REST_Response {
		$scope = 'ALL' === Routes::code( $r, 'scope' ) ? 'ALL' : 'OPEN';
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => TransportPlan::deliveries( $ctx, TransportPlan::next_workday( self::day( $r, $ctx ) ), $scope ) );
	}

	public function vt11( WP_REST_Request $r ): WP_REST_Response {
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => TransportPlan::board( $ctx, self::day( $r, $ctx ) ) );
	}

	public function vt11_assign( WP_REST_Request $r ): WP_REST_Response {
		$nos    = self::codes( $r, 'deliveries', 30 );
		$target = array(
			'shipment'   => Routes::code( $r, 'shipment' ),
			'carrier'    => Routes::code( $r, 'carrier' ),
			'vehicle_id' => (int) $r->get_param( 'vehicle_id' ),
			'depart'     => preg_match( '/^\d{2}:\d{2}$/', Routes::str( $r, 'depart', 5 ) ) ? Routes::str( $r, 'depart', 5 ) : '07:00',
		);
		return Routes::run( $r, 'VT11', 'assign', true, static fn( Ctx $ctx ) => TransportPlan::assign( $ctx, self::day( $r, $ctx ), $nos, $target ) );
	}

	public function vt11_unassign( WP_REST_Request $r ): WP_REST_Response {
		$no = Routes::code( $r, 'delivery' );
		return Routes::run( $r, 'VT11', 'unassign', true, static fn( Ctx $ctx ) => TransportPlan::unassign( $ctx, $no ) );
	}

	public function vt04( WP_REST_Request $r ): WP_REST_Response {
		$dep = Routes::str( $r, 'depart', 5 );
		$dep = preg_match( '/^\d{2}:\d{2}$/', $dep ) ? $dep : '06:30';
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => TransportPlan::proposal( $ctx, self::day( $r, $ctx ), $dep ) );
	}

	public function vt04_create( WP_REST_Request $r ): WP_REST_Response {
		$dep = Routes::str( $r, 'depart', 5 );
		$dep = preg_match( '/^\d{2}:\d{2}$/', $dep ) ? $dep : '06:30';
		$raw = $r->get_param( 'map' );
		$map = array();
		if ( is_array( $raw ) ) {
			foreach ( array_slice( $raw, 0, 60, true ) as $k => $v ) {
				$k = strtoupper( (string) preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $k ) );
				$v = is_scalar( $v ) ? strtoupper( (string) $v ) : '';
				if ( '' !== $k && preg_match( '/^(C:[A-Z]{2,10}|V:\d{1,6})$/', $v ) ) {
					$map[ $k ] = $v;
				}
			}
		}
		return Routes::run( $r, 'VT04', 'collective', true, static fn( Ctx $ctx ) => TransportPlan::create_collective( $ctx, self::day( $r, $ctx ), $dep, $map ) );
	}

	public function shp( WP_REST_Request $r ): WP_REST_Response {
		$no = Routes::code( $r, 'no' );
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => array( 'detail' => Transport::detail( $ctx, $no ), 'changes' => StockTx::changes( $ctx, 'SHP', $no ) ) );
	}

	public function shp_delete( WP_REST_Request $r ): WP_REST_Response {
		$no = Routes::code( $r, 'no' );
		return Routes::run( $r, 'VT02N', 'delete', true, static fn( Ctx $ctx ) => TransportPlan::delete( $ctx, $no ) );
	}

	public function shp_retime( WP_REST_Request $r ): WP_REST_Response {
		$no  = Routes::code( $r, 'no' );
		$dep = Routes::str( $r, 'depart', 5 );
		return Routes::run( $r, 'VT02N', 'retime', true, static fn( Ctx $ctx ) => TransportPlan::retime( $ctx, $no, preg_match( '/^\d{2}:\d{2}$/', $dep ) ? $dep : '' ) );
	}

	public function shp_handover( WP_REST_Request $r ): WP_REST_Response {
		$no = Routes::code( $r, 'no' );
		return Routes::run( $r, 'VT02N', 'handover', true, static fn( Ctx $ctx ) => TransportPlan::handover( $ctx, $no ) );
	}

	public function vt70( WP_REST_Request $r ): WP_REST_Response {
		$no = Routes::code( $r, 'no' );
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => TransportPlan::papers( $ctx, $no ) );
	}

	public function vl71( WP_REST_Request $r ): WP_REST_Response {
		$shp = Routes::code( $r, 'shipment' );
		$car = Routes::code( $r, 'carrier' );
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => TransportPlan::vl71_list( $ctx, '' !== $shp ? '' : self::day( $r, $ctx ), $shp, $car ) );
	}

	public function vl71_print( WP_REST_Request $r ): WP_REST_Response {
		$nos = self::codes( $r, 'deliveries', 60 );
		return Routes::run( $r, 'VL71', 'print', true, static fn( Ctx $ctx ) => TransportPlan::vl71_print( $ctx, $nos ) );
	}

	/* ───────── Lot 1 ───────── */

	public function mb11( WP_REST_Request $r ): WP_REST_Response {
		$mvt = Routes::code( $r, 'movement' );
		$in  = array(
			'stock_id'    => (int) $r->get_param( 'stock_id' ),
			'qty'         => Routes::num( $r, 'qty' ),
			'material_id' => (int) $r->get_param( 'material_id' ),
			'location'    => Routes::code( $r, 'location' ),
			'cost_center' => Routes::code( $r, 'cost_center' ),
			'gl_account'  => Routes::code( $r, 'gl_account' ),
			'so_no'       => Routes::code( $r, 'so_no' ),
			'so_line'     => (int) $r->get_param( 'so_line' ),
			'batch'       => Routes::code( $r, 'batch' ),
			'bbd'         => self::date( $r, 'bbd' ),
			'reason'      => Routes::str( $r, 'reason', 120 ),
			'text'        => Routes::str( $r, 'text', 255 ),
			'customer_id' => (int) $r->get_param( 'customer_id' ),
			'serial'      => Routes::code( $r, 'serial' ),
		);
		return Routes::run( $r, 'MB11', 'mb11_' . $mvt, true, static fn( Ctx $ctx ) => StockTx::mb11( $ctx, $mvt, $in ) );
	}

	public function mb03( WP_REST_Request $r ): WP_REST_Response {
		$no = Routes::code( $r, 'no' );
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => StockTx::mb03( $ctx, $no ) );
	}

	public function mbst( WP_REST_Request $r ): WP_REST_Response {
		$no     = Routes::code( $r, 'doc_no' );
		$lines  = self::ids( $r, 'lines', 100 );
		$reason = Routes::str( $r, 'reason', 120 );
		return Routes::run( $r, 'MBST', 'reverse', true, static fn( Ctx $ctx ) => StockTx::mbst( $ctx, $no, $lines, $reason ) );
	}

	public function ls26( WP_REST_Request $r ): WP_REST_Response {
		$m = Routes::code( $r, 'material' );
		$l = Routes::code( $r, 'location' );
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => StockTx::ls26( $ctx, $m, $l ) );
	}

	public function ls26_putaway( WP_REST_Request $r ): WP_REST_Response {
		$id = (int) $r->get_param( 'stock_id' );
		return Routes::run( $r, 'LS26', 'putaway', true, static fn( Ctx $ctx ) => StockTx::putaway_from_dock( $ctx, $id ) );
	}

	public function lt13( WP_REST_Request $r ): WP_REST_Response {
		$su = (string) preg_replace( '/\D/', '', Routes::str( $r, 'su', 12 ) );
		return Routes::run( $r, 'LT13', 'confirm_su', true, static fn( Ctx $ctx ) => StockTx::lt13( $ctx, $su ) );
	}

	public function lt15( WP_REST_Request $r ): WP_REST_Response {
		$no = Routes::code( $r, 'to_no' );
		return Routes::run( $r, 'LT15', 'cancel_to', true, static fn( Ctx $ctx ) => StockTx::lt15( $ctx, $no ) );
	}

	public function li21_list( WP_REST_Request $r ): WP_REST_Response {
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => StockTx::li21_list( $ctx ) );
	}

	public function li21( WP_REST_Request $r ): WP_REST_Response {
		$ids  = self::ids( $r, 'stock_ids', 100 );
		$text = Routes::str( $r, 'text', 255 );
		return Routes::run( $r, 'LI21', 'clear_differences', true, static fn( Ctx $ctx ) => StockTx::li21( $ctx, $ids, $text ) );
	}

	public function output( WP_REST_Request $r ): WP_REST_Response {
		$type    = Routes::code( $r, 'type' );
		$obj     = Routes::code( $r, 'obj_type' );
		$no      = Routes::code( $r, 'obj_no' );
		$detail  = Routes::str( $r, 'detail', 60 );
		$printer = Routes::str( $r, 'printer', 20 );
		$copies  = max( 1, min( 20, (int) $r->get_param( 'copies' ) ) );
		if ( ! in_array( $obj, array( 'PO', 'DLV', 'TO', 'PROD', 'HU', 'MDOC', 'SHP' ), true ) ) {
			return Routes::fail( 'Objet d\'impression inconnu.' );
		}
		$tcode = array( 'ZWEZ' => 'ME22N', 'ZPAL' => 'VL02N', 'LT31' => 'LT31', 'VL74' => 'VL74', 'ZCOA' => 'VL02N', 'ZPOR' => 'COR5', 'ZTRA' => 'VT70' )[ $type ] ?? 'NACE';
		return Routes::run( $r, $tcode, 'print_' . $type, true, static fn( Ctx $ctx ) => StockTx::output( $ctx, $obj, $no, $type, $detail, $printer, $copies ) );
	}

	public function outputs( WP_REST_Request $r ): WP_REST_Response {
		$obj = Routes::code( $r, 'obj_type' );
		$no  = Routes::code( $r, 'obj_no' );
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => StockTx::outputs_of( $ctx, $obj, $no ) );
	}

	/* ───────── Lot 2 ───────── */

	public function me2l( WP_REST_Request $r ): WP_REST_Response {
		$s    = Routes::str( $r, 'supplier', 60 );
		$m    = Routes::str( $r, 'material', 60 );
		$open = '0' !== (string) $r->get_param( 'open' );
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => PurchasingTx::me2l( $ctx, $s, $m, $open ) );
	}

	public function arrivals( WP_REST_Request $r ): WP_REST_Response {
		$from = self::date( $r, 'from' );
		$to   = self::date( $r, 'to' );
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => PurchasingTx::arrivals( $ctx, $from, $to ) );
	}

	public function po2( WP_REST_Request $r ): WP_REST_Response {
		$no = Routes::code( $r, 'no' );
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => PurchasingTx::detail( $ctx, $no ) );
	}

	public function po2_change( WP_REST_Request $r ): WP_REST_Response {
		$no      = Routes::code( $r, 'no' );
		$changes = Routes::lines( $r, 'changes', array( 'item_id' => 'int', 'qty' => 'float', 'final' => 'str', 'delete' => 'int' ), 50 );
		return Routes::run( $r, 'ME22N', 'change_po', true, static fn( Ctx $ctx ) => PurchasingTx::change( $ctx, $no, $changes ) );
	}

	public function po2_grdoc( WP_REST_Request $r ): WP_REST_Response {
		$no = Routes::code( $r, 'no' );
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => PurchasingTx::gr_document( $ctx, $no ) );
	}

	public function bulletins( WP_REST_Request $r ): WP_REST_Response {
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => PurchasingTx::bulletins( $ctx ) );
	}

	public function bulletin( WP_REST_Request $r ): WP_REST_Response {
		$no = Routes::code( $r, 'no' );
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => PurchasingTx::by_bulletin( $ctx, $no ) );
	}

	public function po_transfer( WP_REST_Request $r ): WP_REST_Response {
		$lines = Routes::lines( $r, 'lines', array( 'material_id' => 'int', 'qty' => 'float' ), 50 );
		$note  = Routes::str( $r, 'note', 255 );
		return Routes::run( $r, 'ME21N', 'create_transfer_po', true, static fn( Ctx $ctx ) => PurchasingTx::create_transfer( $ctx, $lines, $note ) );
	}

	/* ───────── Lot 3 ───────── */

	public function dlv2( WP_REST_Request $r ): WP_REST_Response {
		$no = Routes::code( $r, 'no' );
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => ShippingTx::detail( $ctx, $no ) );
	}

	public function dlv2_change( WP_REST_Request $r ): WP_REST_Response {
		$no      = Routes::code( $r, 'no' );
		$changes = Routes::lines( $r, 'changes', array( 'item_id' => 'int', 'qty' => 'float', 'batch' => 'code', 'delete' => 'int' ), 50 );
		return Routes::run( $r, 'VL02N', 'change_delivery', true, static fn( Ctx $ctx ) => ShippingTx::change( $ctx, $no, $changes ) );
	}

	public function dlv2_to( WP_REST_Request $r ): WP_REST_Response {
		$no = Routes::code( $r, 'no' );
		return Routes::run( $r, 'VL02N', 'create_to', true, static fn( Ctx $ctx ) => ShippingTx::create_to( $ctx, $no ) );
	}

	public function dlv2_delete( WP_REST_Request $r ): WP_REST_Response {
		$no = Routes::code( $r, 'no' );
		return Routes::run( $r, 'VL02N', 'delete_delivery', true, static fn( Ctx $ctx ) => ShippingTx::delete( $ctx, $no ) );
	}

	public function dlv2_note( WP_REST_Request $r ): WP_REST_Response {
		$no = Routes::code( $r, 'no' );
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => ShippingTx::delivery_note( $ctx, $no ) );
	}

	public function dlv2_cert( WP_REST_Request $r ): WP_REST_Response {
		$no   = Routes::code( $r, 'no' );
		$item = (int) $r['item'];
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => ShippingTx::certificate( $ctx, $no, $item ) );
	}

	public function vl06o( WP_REST_Request $r ): WP_REST_Response {
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => ShippingTx::vl06o( $ctx ) );
	}

	public function vl06o_issue( WP_REST_Request $r ): WP_REST_Response {
		$nos = self::codes( $r, 'deliveries', 30 );
		return Routes::run( $r, 'VL06O', 'collective_gi', true, static fn( Ctx $ctx ) => ShippingTx::collective_gi( $ctx, $nos ) );
	}

	public function vl09( WP_REST_Request $r ): WP_REST_Response {
		$no     = Routes::code( $r, 'no' );
		$reason = Routes::str( $r, 'reason', 120 );
		return Routes::run( $r, 'VL09', 'cancel_gi', true, static fn( Ctx $ctx ) => ShippingTx::vl09( $ctx, $no, $reason ) );
	}

	public function vl74( WP_REST_Request $r ): WP_REST_Response {
		$no = Routes::code( $r, 'no' );
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => ShippingTx::vl74( $ctx, $no ) );
	}

	public function so2( WP_REST_Request $r ): WP_REST_Response {
		$no = Routes::code( $r, 'no' );
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => ShippingTx::so_detail( $ctx, $no ) );
	}

	public function so2_change( WP_REST_Request $r ): WP_REST_Response {
		$no    = Routes::code( $r, 'no' );
		$date  = self::date( $r, 'req_date' );
		$items = Routes::lines( $r, 'items', array( 'item_id' => 'int', 'qty' => 'float', 'reject' => 'code', 'block' => 'str' ), 50 );
		$add   = Routes::lines( $r, 'add', array( 'material_id' => 'int', 'qty' => 'float' ), 20 );
		return Routes::run( $r, 'VA02', 'change_so', true, static fn( Ctx $ctx ) => ShippingTx::so_change( $ctx, $no, $date, $items, $add ) );
	}

	public function so2_avail( WP_REST_Request $r ): WP_REST_Response {
		$no = Routes::code( $r, 'no' );
		return Routes::run( $r, 'VA02', 'availability', true, static fn( Ctx $ctx ) => ShippingTx::availability( $ctx, $no ) );
	}

	public function va05( WP_REST_Request $r ): WP_REST_Response {
		$c = Routes::str( $r, 'customer', 60 );
		$m = Routes::str( $r, 'material', 60 );
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => ShippingTx::va05( $ctx, $c, $m ) );
	}

	/* ───────── Lot 4 ───────── */

	public function cor1( WP_REST_Request $r ): WP_REST_Response {
		$mat   = (int) $r->get_param( 'material_id' );
		$qty   = Routes::num( $r, 'qty' );
		$end   = self::date( $r, 'end' );
		$type  = Routes::code( $r, 'type' );
		$sup   = Routes::code( $r, 'supervisor' );
		$stock = (int) $r->get_param( 'stock_id' );
		return Routes::run( $r, 'COR1', 'create_process_order', true, static fn( Ctx $ctx ) => ProcessOrders::create( $ctx, $mat, $qty, $end, $type, $sup, $stock ) );
	}

	public function cor8( WP_REST_Request $r ): WP_REST_Response {
		$sup  = Routes::code( $r, 'supervisor' );
		$st   = Routes::code( $r, 'status' );
		$from = self::date( $r, 'from' );
		$to   = self::date( $r, 'to' );
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => ProcessOrders::list( $ctx, $sup, $st, $from, $to ) );
	}

	public function cor( WP_REST_Request $r ): WP_REST_Response {
		$no = Routes::code( $r, 'no' );
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => ProcessOrders::detail( $ctx, $no ) );
	}

	public function cor_change( WP_REST_Request $r ): WP_REST_Response {
		$no    = Routes::code( $r, 'no' );
		$head  = array( 'qty' => '' === (string) $r->get_param( 'qty' ) ? '' : Routes::num( $r, 'qty' ), 'end' => self::date( $r, 'end' ), 'supervisor' => null !== $r->get_param( 'supervisor' ) ? Routes::code( $r, 'supervisor' ) : null );
		if ( null === $head['supervisor'] ) {
			unset( $head['supervisor'] );
		}
		$comps = Routes::lines( $r, 'components', array( 'id' => 'int', 'material_id' => 'int', 'qty' => 'float', 'remove' => 'int' ), 30 );
		$add   = Routes::lines( $r, 'add', array( 'material_id' => 'int', 'qty' => 'float' ), 10 );
		return Routes::run( $r, 'COR2', 'change_process_order', true, static fn( Ctx $ctx ) => ProcessOrders::change( $ctx, $no, $head, $comps, $add ) );
	}

	public function cor_close( WP_REST_Request $r ): WP_REST_Response {
		$no   = Routes::code( $r, 'no' );
		$undo = self::flag( $r, 'undo' );
		return Routes::run( $r, 'COR2', $undo ? 'undo_technical_close' : 'technical_close', true, static fn( Ctx $ctx ) => ProcessOrders::tech_close( $ctx, $no, $undo ) );
	}

	public function cor5( WP_REST_Request $r ): WP_REST_Response {
		$nos = self::codes( $r, 'orders', 30 );
		return Routes::run( $r, 'COR5', 'release', true, static fn( Ctx $ctx ) => ProcessOrders::release( $ctx, $nos ) );
	}

	public function cork_get( WP_REST_Request $r ): WP_REST_Response {
		$no = Routes::code( $r, 'no' );
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => ProcessOrders::proposal( $ctx, $no ) );
	}

	public function cork( WP_REST_Request $r ): WP_REST_Response {
		$no    = Routes::code( $r, 'no' );
		$good  = Routes::num( $r, 'good' );
		$scrap = Routes::num( $r, 'scrap' );
		$lines = Routes::lines( $r, 'lines', array( 'stock_id' => 'int', 'qty' => 'float' ), 60 );
		$final = self::flag( $r, 'final' );
		return Routes::run( $r, 'CORK', 'confirm_process_order', true, static fn( Ctx $ctx ) => ProcessOrders::confirm( $ctx, $no, $good, $scrap, $lines, $final ) );
	}

	public function cors( WP_REST_Request $r ): WP_REST_Response {
		$no     = Routes::code( $r, 'no' );
		$reason = Routes::str( $r, 'reason', 120 );
		return Routes::run( $r, 'CORS', 'cancel_confirmation', true, static fn( Ctx $ctx ) => ProcessOrders::cancel( $ctx, $no, $reason ) );
	}

	public function cs03( WP_REST_Request $r ): WP_REST_Response {
		$m = (string) preg_replace( '/[^0-9A-Za-z]/', '', (string) $r['mno'] );
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => ProcessOrders::cs03( $ctx, $m ) );
	}

	public function cs15( WP_REST_Request $r ): WP_REST_Response {
		$m = (string) preg_replace( '/[^0-9A-Za-z]/', '', (string) $r['mno'] );
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => ProcessOrders::cs15( $ctx, $m ) );
	}

	/* ───────── Lot 5 ───────── */

	public function containers( WP_REST_Request $r ): WP_REST_Response {
		$m = Routes::code( $r, 'material' );
		$s = Routes::code( $r, 'serial' );
		$c = (int) $r->get_param( 'customer_id' );
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => Containers::list( $ctx, $m, $s, $c ) );
	}

	public function container_history( WP_REST_Request $r ): WP_REST_Response {
		$id = (int) $r['cid'];
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => Containers::history( $ctx, $id ) );
	}

	public function serial_materials( WP_REST_Request $r ): WP_REST_Response {
		return Routes::run( $r, '', '', false, static fn() => Containers::serial_materials() );
	}

	public function zvlgut_return( WP_REST_Request $r ): WP_REST_Response {
		$cust    = (int) $r->get_param( 'customer_id' );
		$mat     = (int) $r->get_param( 'material_id' );
		$serials = self::codes( $r, 'serials', 40 );
		$ref     = Routes::str( $r, 'ref', 20 );
		return Routes::run( $r, 'ZVLGUT', 'return_containers', true, static fn( Ctx $ctx ) => Containers::receive( $ctx, $cust, $mat, $serials, $ref ) );
	}

	public function zvlgut_check( WP_REST_Request $r ): WP_REST_Response {
		$mat    = (int) $r->get_param( 'material_id' );
		$serial = Routes::code( $r, 'serial' );
		$result = Routes::code( $r, 'result' );
		$reason = Routes::str( $r, 'reason', 120 );
		return Routes::run( $r, 'ZVLGUT', 'check_container', true, static fn( Ctx $ctx ) => Containers::check( $ctx, $mat, $serial, $result, $reason ) );
	}

	public function portal( WP_REST_Request $r ): WP_REST_Response {
		$s = Routes::code( $r, 'serial' );
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => Containers::portal( $ctx, $s ) );
	}

	public function portal_declare( WP_REST_Request $r ): WP_REST_Response {
		$s    = Routes::code( $r, 'serial' );
		$kind = Routes::code( $r, 'kind' );
		$c    = (int) $r->get_param( 'customer_id' );
		$d    = self::date( $r, 'date' );
		return Routes::run( $r, 'ZPEMB', 'portal_declare', true, static fn( Ctx $ctx ) => Containers::portal_declare( $ctx, $s, $kind, $c, $d ) );
	}

	public function portal_cancel( WP_REST_Request $r ): WP_REST_Response {
		$id = (int) $r['mid'];
		return Routes::run( $r, 'ZPEMB', 'portal_cancel', true, static fn( Ctx $ctx ) => Containers::portal_cancel( $ctx, $id ) );
	}

	public function wagons( WP_REST_Request $r ): WP_REST_Response {
		return Routes::run( $r, '', '', false, static fn( Ctx $ctx ) => Containers::wagons( $ctx ) );
	}

	public function pack_codes( WP_REST_Request $r ): WP_REST_Response {
		return Routes::run( $r, '', '', false, static fn() => ShippingTx::PACKAGING_CODES );
	}
}

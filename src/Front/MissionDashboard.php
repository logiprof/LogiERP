<?php
declare(strict_types=1);

namespace LogiERP\Front;

use LogiERP\Admin\ResultsPage;
use LogiERP\Core\Capabilities;
use LogiERP\Core\Db;
use LogiERP\Domain\Company;
use LogiERP\Domain\Interventions;
use LogiERP\Domain\Kpi;
use LogiERP\Domain\SessionService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tableau d'indicateurs de fin de mission : pour un apprenti (session) ou pour toute la classe (assignation).
 * Accès : l'apprenti voit sa session une fois remise ; l'enseignant voit les sessions et classes qu'il suit.
 */
final class MissionDashboard {

	public const QUERY = 'logierp_doc';
	private const VALUE = 'kpi';

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 1 );
	}

	private static function url( string $key, int $id ): string {
		return add_query_arg( array( self::QUERY => self::VALUE, $key => $id, '_wpnonce' => wp_create_nonce( 'lw_kpi_' . $key . '_' . $id ) ), home_url( '/' ) );
	}

	public static function session_url( int $id ): string {
		return self::url( 'session', $id );
	}

	public static function assignment_url( int $id ): string {
		return self::url( 'assignment', $id );
	}

	public function maybe_render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce vérifié ci-dessous.
		if ( ! isset( $_GET[ self::QUERY ] ) || self::VALUE !== $_GET[ self::QUERY ] ) {
			return;
		}
		nocache_headers();
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		$uid   = get_current_user_id();
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		$html  = null;
		foreach ( array( 'session', 'assignment' ) as $key ) {
			if ( ! isset( $_GET[ $key ] ) ) {
				continue;
			}
			$id = absint( $_GET[ $key ] );
			if ( ! $id || ! wp_verify_nonce( $nonce, 'lw_kpi_' . $key . '_' . $id ) ) {
				self::deny( 'Lien expiré ou invalide : rouvrez le tableau depuis LogiERP.' );
			}
			$html = 'session' === $key ? self::for_session( $id, $uid ) : self::for_class( $id );
			break;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( null === $html ) {
			self::deny( 'Tableau introuvable ou accès refusé.' );
		}
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- page entièrement échappée.
		exit;
	}

	private static function deny( string $msg ): void {
		wp_die( esc_html( $msg ), esc_html__( 'LogiERP', 'logierp' ), array( 'response' => 403 ) );
	}

	private static function e( $v ): string {
		return esc_html( (string) $v );
	}

	/* ───────── Une session ───────── */

	private static function for_session( int $id, int $uid ): ?string {
		$ctx = SessionService::context_for( $id, $uid, false );
		if ( ! $ctx ) {
			return null;
		}
		$own = \LogiERP\Domain\Teams::is_participant( $ctx->session, $uid );
		if ( $own && 'EN_COURS' === $ctx->session['status'] && ! (int) $ctx->session['is_test'] ) {
			self::deny( 'Le tableau d\'indicateurs est disponible une fois la mission remise.' );
		}
		$k     = Kpi::session( $ctx );
		$who   = \LogiERP\Domain\Teams::label( $ctx->session );
		$grade = null;
		if ( 'CORRIGEE' === $ctx->session['status'] && ( ! $own || (int) $ctx->session['published'] || (int) $ctx->session['is_test'] ) ) {
			$grade = (float) $ctx->session['grade'];
		}
		$h   = array( self::open( 'Indicateurs – ' . $ctx->scenario['title'] ) );
		$h[] = '<header class="dh"><div>' . Company::logo_svg( 30 ) . '</div><div class="dt"><h1>Tableau d\'indicateurs</h1><p>' . self::e( $ctx->scenario['title'] ) . '</p><p class="mut">' . self::e( $who . ' · ' . ( ResultsPage::STATUS[ $ctx->session['status'] ] ?? $ctx->session['status'] ) ) . '</p></div></header>';

		// Tuiles : les chiffres qu'un chef d'équipe regarde en premier.
		$tiles = array(
			array( 'Temps actif', $k['active'] . ' min', sprintf( 'sur %d min de mission', $k['minutes'] ) ),
			array( 'Saisies réussies', (string) $k['ok'], sprintf( '%d refusée(s) · %s %% d\'erreurs', $k['errors'], self::n( $k['error_rate'] ) ) ),
			array( 'Productivité', $k['lines_hour'] > 0 ? self::n( $k['lines_hour'] ) . ' lignes/h' : '—', sprintf( '%d ligne(s) prélevée(s)', $k['pick_lines'] ) ),
			array( 'Commandes livrées', $k['orders'] ? $k['served'] . ' / ' . $k['orders'] : '—', $k['partial'] ? $k['partial'] . ' partielle(s)' : 'commandes clients de la mission' ),
		);
		if ( null !== $grade ) {
			$tiles[] = array( 'Note', number_format( $grade, 1, '.', '' ), 'correction publiée' );
		}
		$h[] = '<div class="tiles">';
		foreach ( $tiles as $t ) {
			$h[] = '<div class="tile"><span>' . self::e( $t[0] ) . '</span><strong>' . self::e( $t[1] ) . '</strong><small>' . self::e( $t[2] ) . '</small></div>';
		}
		$h[] = '</div>';

		// Détail par domaine, avec un verdict lisible.
		$rows = array(
			array( 'Préparation', 'Lignes prélevées', (string) $k['pick_lines'], '' ),
			array( '', 'Prélèvements incomplets (manque constaté)', (string) $k['pick_short'], $k['pick_short'] ? 'Écart signalé : vérifiez qu\'un comptage a suivi.' : '' ),
			array( '', 'Prélèvements hors FIFO / FEFO', (string) $k['pick_off'], $k['pick_off'] ? 'À revoir : stratégie de sortie non respectée.' : 'Stratégie respectée.' ),
			array( 'Priorités', 'Commandes urgentes livrées', $k['urgent'] ? $k['urgent_done'] . ' / ' . $k['urgent'] : '—', null !== $k['urgent_wait'] ? sprintf( 'Délai moyen : %d min entre l\'arrivée et l\'expédition.', $k['urgent_wait'] ) : '' ),
			array( '', 'Commandes normales expédiées avant une urgence en attente', (string) $k['priority_ko'], $k['urgent'] ? ( $k['priority_ko'] ? 'Priorité non respectée.' : 'Priorités respectées.' ) : '' ),
			array( 'Réception', 'Lignes de commande réceptionnées', $k['rec_lines'] ? $k['rec_done'] . ' / ' . $k['rec_lines'] : '—', '' ),
			array( '', 'Réserves, blocages ou retours saisis', (string) $k['reserves'], '' ),
			array( '', 'Lettres de voiture CMR signées', (string) $k['cmr'], '' ),
			array( 'Stock', 'Rangements et transferts confirmés', (string) $k['putaway'], '' ),
			array( '', 'Inventaires comptabilisés', (string) $k['inventories'], $k['gaps'] ? sprintf( '%d écart(s) caché(s) dans la mission.', $k['gaps'] ) : '' ),
		);
		$h[] = '<section><h2>Détail</h2><table class="ln"><thead><tr><th>Domaine</th><th>Indicateur</th><th class="r">Valeur</th><th>Lecture</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$h[] = '<tr><td class="b">' . self::e( $r[0] ) . '</td><td>' . self::e( $r[1] ) . '</td><td class="r b">' . self::e( $r[2] ) . '</td><td class="mut">' . self::e( $r[3] ) . '</td></tr>';
		}
		$h[] = '</tbody></table></section>';

		// Rythme de travail : saisies par tranche de 5 minutes.
		$h[] = '<section><h2>Rythme de travail</h2><p class="mut">Saisies par tranche de 5 minutes depuis le début de la mission. Survolez une barre pour le détail.</p>' . self::timeline( $k['timeline'] ) . '</section>';

		// Transactions et interventions.
		$h[] = '<section class="cols"><div><h2>Transactions les plus utilisées</h2><table class="ln"><tbody>';
		foreach ( $k['tx'] as $code => $n ) {
			$h[] = '<tr><td class="mono">' . self::e( $code ) . '</td><td class="r">' . (int) $n . '</td></tr>';
		}
		if ( ! $k['tx'] ) {
			$h[] = '<tr><td>Aucune saisie.</td></tr>';
		}
		$h[] = '</tbody></table></div><div><h2>Imprévus de l\'enseignant</h2>';
		if ( $k['interventions'] ) {
			$h[] = '<table class="ln"><tbody>';
			foreach ( $k['interventions'] as $i ) {
				$h[] = '<tr><td class="nw">' . self::e( wp_date( 'H:i', (int) strtotime( $i['created_at'] . ' UTC' ) ) ) . '</td><td>' . self::e( Interventions::label( $i ) ) . ( 'ok' !== $i['result'] ? ' <span class="mut">(sans effet : ' . self::e( $i['message'] ) . ')</span>' : '' ) . '</td></tr>';
			}
			$h[] = '</tbody></table>';
		} else {
			$h[] = '<p class="mut">Aucune intervention en direct.</p>';
		}
		$h[] = '</div></section>';
		$h[] = '<p class="foot">Temps actif : les pauses de plus de 10 minutes sans saisie ne sont pas comptées. Productivité : lignes prélevées par heure de temps actif.</p>';
		return implode( '', $h ) . self::close();
	}

	/* ───────── Une classe ───────── */

	private static function for_class( int $aid ): ?string {
		global $wpdb;
		$as = null;
		foreach ( ResultsPage::visible_assignments() as $a ) {
			if ( (int) $a['id'] === $aid ) {
				$as = $a;
			}
		}
		if ( ! $as || ! current_user_can( Capabilities::VIEW_CLASS ) ) {
			return null;
		}
		$x    = Db::t( 'session' );
		$ids  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT s.id, s.user_id, s.team_id FROM {$x} s WHERE s.assignment_id = %d AND s.is_test = 0 AND s.id = (SELECT MAX(z.id) FROM {$x} z WHERE z.assignment_id = s.assignment_id AND IF(s.team_id > 0, z.team_id = s.team_id, z.user_id = s.user_id AND z.team_id = 0)) ORDER BY s.id LIMIT 200", $aid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = array();
		foreach ( $ids as $r ) {
			$ctx = SessionService::context_for( (int) $r['id'], get_current_user_id(), false );
			if ( ! $ctx ) {
				continue;
			}
			$rows[] = array( 'name' => \LogiERP\Domain\Teams::label( $ctx->session ), 'status' => $ctx->session['status'], 'grade' => 'CORRIGEE' === $ctx->session['status'] ? (float) $ctx->session['grade'] : null, 'k' => Kpi::session( $ctx ), 'url' => self::session_url( (int) $r['id'] ) );
		}
		usort( $rows, static fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
		$h   = array( self::open( 'Indicateurs de la classe – ' . $as['title'] ) );
		$h[] = '<header class="dh"><div>' . Company::logo_svg( 30 ) . '</div><div class="dt"><h1>Indicateurs de la classe</h1><p>' . self::e( $as['title'] ) . '</p><p class="mut">' . self::e( $as['class_name'] . ' · ' . count( $rows ) . ' session(s)' ) . '</p></div></header>';
		if ( ! $rows ) {
			return implode( '', $h ) . '<p>Aucune mission commencée.</p>' . self::close();
		}
		$avg = static function ( string $key ) use ( $rows ): string {
			$v = array_map( static fn( $r ) => (float) $r['k'][ $key ], $rows );
			return number_format( array_sum( $v ) / max( 1, count( $v ) ), 1, '.', '' );
		};
		$h[] = '<div class="tiles"><div class="tile"><span>Temps actif moyen</span><strong>' . self::e( $avg( 'active' ) ) . ' min</strong></div><div class="tile"><span>Taux d\'erreur moyen</span><strong>' . self::e( $avg( 'error_rate' ) ) . ' %</strong></div><div class="tile"><span>Productivité moyenne</span><strong>' . self::e( (float) $avg( 'lines_hour' ) > 0 ? $avg( 'lines_hour' ) . ' lignes/h' : '—' ) . '</strong><small>prélèvement</small></div><div class="tile"><span>Priorités non respectées</span><strong>' . (int) array_sum( array_map( static fn( $r ) => $r['k']['priority_ko'], $rows ) ) . '</strong><small>au total</small></div></div>';
		$h[] = '<section><h2>Par apprenti</h2><table class="ln"><thead><tr><th>Apprenti</th><th>Statut</th><th class="r">Temps actif</th><th class="r">Saisies réussies</th><th class="r">Erreurs</th><th class="r">Lignes/h</th><th class="r">Réception</th><th class="r">Commandes livrées</th><th class="r">Urgences</th><th class="r">Priorité non respectée</th><th class="r">Note</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$k   = $r['k'];
			$h[] = '<tr><td><a href="' . esc_url( $r['url'] ) . '">' . self::e( $r['name'] ) . '</a></td><td>' . self::e( ResultsPage::STATUS[ $r['status'] ] ?? $r['status'] ) . '</td><td class="r">' . (int) $k['active'] . ' min</td><td class="r">' . (int) $k['ok'] . '</td><td class="r">' . self::e( self::n( $k['error_rate'] ) ) . ' %</td><td class="r">' . self::e( $k['lines_hour'] > 0 ? self::n( $k['lines_hour'] ) : '—' ) . '</td><td class="r">' . self::e( $k['rec_lines'] ? $k['rec_done'] . ' / ' . $k['rec_lines'] : '—' ) . '</td><td class="r">' . self::e( $k['orders'] ? $k['served'] . ' / ' . $k['orders'] : '—' ) . '</td><td class="r">' . self::e( $k['urgent'] ? $k['urgent_done'] . ' / ' . $k['urgent'] : '—' ) . '</td><td class="r">' . (int) $k['priority_ko'] . '</td><td class="r">' . self::e( null !== $r['grade'] ? number_format( $r['grade'], 1, '.', '' ) : '—' ) . '</td></tr>';
		}
		$h[] = '</tbody></table></section>';
		if ( array_filter( $rows, static fn( $r ) => $r['k']['lines_hour'] > 0 ) ) {
			$h[] = '<section><h2>Productivité (lignes prélevées par heure active)</h2>' . self::bars( array_map( static fn( $r ) => array( $r['name'], (float) $r['k']['lines_hour'] ), $rows ), 'lignes/h' ) . '</section>';
		}
		$h[] = '<section><h2>Taux d\'erreur (saisies refusées)</h2>' . self::bars( array_map( static fn( $r ) => array( $r['name'], (float) $r['k']['error_rate'] ), $rows ), '%' ) . '</section>';
		$h[] = '<p class="foot">Cliquez sur un nom pour le tableau détaillé de l\'apprenti. Une saisie refusée par le système (mauvais emplacement, quantité impossible, règle de stockage) compte comme erreur.</p>';
		return implode( '', $h ) . self::close();
	}

	/* ───────── Graphiques (SVG, sans bibliothèque) ───────── */

	private static function n( $v ): string {
		return rtrim( rtrim( number_format( (float) $v, 1, '.', '' ), '0' ), '.' );
	}

	/** Colonnes empilées : saisies réussies (bleu) et refusées (rouge), légende et infobulles. */
	private static function timeline( array $bins ): string {
		if ( ! $bins ) {
			return '<p class="mut">Aucune saisie.</p>';
		}
		$last = max( array_keys( $bins ) );
		$max  = 1;
		for ( $i = 0; $i <= $last; $i++ ) {
			$max = max( $max, array_sum( $bins[ $i ] ?? array( 0, 0 ) ) );
		}
		$n   = $last + 1;
		$w   = 680;
		$hgt = 150;
		$bw  = max( 4, min( 28, ( $w - 40 ) / $n - 3 ) );
		$svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . ( $hgt + 28 ) . '" role="img" aria-label="Saisies par tranche de 5 minutes">';
		$svg .= '<line x1="34" y1="' . $hgt . '" x2="' . $w . '" y2="' . $hgt . '" class="axis"/>';
		$svg .= '<text x="30" y="12" class="tick" text-anchor="end">' . (int) $max . '</text><text x="30" y="' . $hgt . '" class="tick" text-anchor="end">0</text>';
		for ( $i = 0; $i <= $last; $i++ ) {
			[ $ok, $er ] = $bins[ $i ] ?? array( 0, 0 );
			$x  = 38 + $i * ( ( $w - 40 ) / $n );
			$ho = ( $hgt - 6 ) * $ok / $max;
			$he = ( $hgt - 6 ) * $er / $max;
			$tt = sprintf( '%d–%d min : %d réussie(s), %d refusée(s)', $i * 5, $i * 5 + 5, $ok, $er );
			$svg .= '<g><title>' . esc_html( $tt ) . '</title>';
			if ( $ok ) {
				$svg .= '<rect class="ok" x="' . round( $x, 1 ) . '" y="' . round( $hgt - $ho, 1 ) . '" width="' . round( $bw, 1 ) . '" height="' . round( $ho, 1 ) . '" rx="2"/>';
			}
			if ( $er ) {
				$svg .= '<rect class="er" x="' . round( $x, 1 ) . '" y="' . round( $hgt - $ho - $he - ( $ok ? 2 : 0 ), 1 ) . '" width="' . round( $bw, 1 ) . '" height="' . round( $he, 1 ) . '" rx="2"/>';
			}
			$svg .= '</g>';
			if ( 0 === $i % max( 1, (int) ceil( $n / 10 ) ) ) {
				$svg .= '<text x="' . round( $x + $bw / 2, 1 ) . '" y="' . ( $hgt + 14 ) . '" class="tick" text-anchor="middle">' . ( $i * 5 ) . '</text>';
			}
		}
		$svg .= '<text x="' . $w . '" y="' . ( $hgt + 26 ) . '" class="tick" text-anchor="end">minutes</text></svg>';
		return '<div class="legend"><span><i class="ok"></i>Saisies réussies</span><span><i class="er"></i>Saisies refusées</span></div>' . $svg;
	}

	/** Barres horizontales, une série, valeur écrite au bout. @param array<int,array{0:string,1:float}> $data */
	private static function bars( array $data, string $unit ): string {
		$max = max( 1.0, max( array_map( static fn( $d ) => $d[1], $data ) ) );
		$h   = '<div class="bars">';
		foreach ( $data as [ $label, $v ] ) {
			$h .= '<div class="br" title="' . esc_attr( $label . ' : ' . self::n( $v ) . ' ' . $unit ) . '"><span>' . self::e( $label ) . '</span><div><i style="width:' . round( 100 * $v / $max, 1 ) . '%"></i></div><b>' . self::e( self::n( $v ) . ' ' . $unit ) . '</b></div>';
		}
		return $h . '</div>';
	}

	private static function open( string $title ): string {
		$fnt = LOGIERP_URL . 'assets/fonts/';
		$css = '';
		foreach ( array( 400, 600, 700 ) as $w ) {
			$css .= '@font-face{font-family:Poppins;src:url(' . esc_url( $fnt . 'poppins-latin-' . $w . '-normal.woff2' ) . ') format("woff2");font-weight:' . $w . '}';
		}
		$css .= '@page{size:A4;margin:12mm}*{box-sizing:border-box}body{margin:0;background:#e9ecef;font:9.4pt/1.45 Poppins,Arial,sans-serif;color:#1d2733}'
			. '.bar{position:sticky;top:0;z-index:2;display:flex;gap:14px;align-items:center;padding:10px 16px;background:#232c37;color:#c9d2dc;font-size:13px}.bar button{font:600 14px Poppins,Arial;background:#f2c200;color:#1d2733;border:0;border-radius:20px;padding:9px 18px;cursor:pointer}'
			. 'main{max-width:210mm;margin:16px auto;background:#fff;padding:12mm;box-shadow:0 2px 10px rgba(0,0,0,.12)}'
			. '.dh{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #232c37;padding-bottom:3mm;margin-bottom:5mm}.dt{text-align:right}.dt h1{margin:0;font-size:17pt;text-transform:uppercase}.dt p{margin:0;font-weight:600}.mut{color:#5d6875;font-weight:400}'
			. '.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(30mm,1fr));gap:3mm;margin-bottom:5mm}.tile{border:1px solid #c9cfd6;border-radius:2mm;padding:3mm}.tile span{display:block;font-size:7.5pt;color:#5d6875;text-transform:uppercase}.tile strong{display:block;font-size:14pt;line-height:1.25;white-space:nowrap}.tile small{color:#5d6875;font-size:7.5pt}'
			. 'section{margin-bottom:5mm;break-inside:avoid}h2{font-size:11pt;margin:0 0 2mm}table.ln{width:100%;border-collapse:collapse;font-size:8.4pt}.ln th{background:#232c37;color:#fff;text-align:left;padding:1.4mm 2mm;font-weight:600}.ln td{border-bottom:1px solid #d5dae0;padding:1.3mm 2mm;vertical-align:top}.ln .r{text-align:right}.b{font-weight:600}.mono{font-family:"DejaVu Sans Mono",monospace}.nw{white-space:nowrap}.ln a{color:#1c64b0;text-decoration:none}'
			. '.cols{display:grid;grid-template-columns:1fr 1.4fr;gap:6mm}.chart{width:100%;height:auto}.chart .axis{stroke:#9aa3ad;stroke-width:1}.chart .tick{font-size:9px;fill:#5d6875}.chart .ok{fill:#1c64b0}.chart .er{fill:#d64a3a}'
			. '.legend{display:flex;gap:16px;font-size:8pt;color:#3d4753;margin-bottom:1mm}.legend i{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:5px;vertical-align:-1px}.legend .ok{background:#1c64b0}.legend .er{background:#d64a3a}'
			. '.bars{display:grid;gap:1.5mm}.br{display:grid;grid-template-columns:42mm 1fr 22mm;gap:3mm;align-items:center;font-size:8.4pt}.br div{background:#eef1f4;border-radius:2px;height:4mm}.br i{display:block;height:100%;background:#1c64b0;border-radius:0 2px 2px 0;min-width:1px}.br b{text-align:right;font-weight:600}'
			. '.foot{font-size:7.5pt;color:#5d6875;border-top:1px solid #e3e6ea;padding-top:2mm}'
			. '@media print{body{background:#fff}.bar{display:none}main{margin:0;box-shadow:none;padding:0;max-width:none}}';
		return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . self::e( $title ) . '</title><style>' . $css . '</style></head><body><div class="bar"><button type="button" onclick="window.print()">Imprimer / enregistrer en PDF</button><span>Indicateurs calculés à partir des saisies de la mission.</span></div><main>';
	}

	private static function close(): string {
		return '</main></body></html>';
	}
}

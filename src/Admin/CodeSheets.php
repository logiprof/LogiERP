<?php
declare(strict_types=1);

namespace LogiERP\Admin;

use LogiERP\Domain\Teams;
use LogiERP\Support\Pdf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fiches « Code personnel de mission » en PDF : une page A4 par apprenti, prête à imprimer et à remettre.
 */
final class CodeSheets {

	private const INK    = '#252c35';
	private const GREEN  = '#067030';
	private const YELLOW = '#f5c400';
	private const MUTED  = '#5b6672';
	private const PAPER  = '#eef0eb';
	private const RED    = '#b42318';
	private const M      = 42.0;

	/**
	 * @param array    $class      Classe (nom).
	 * @param array    $assignment Assignation (avec le titre du scénario).
	 * @param int|null $only_user  Une seule fiche (code régénéré, apprenti absent…).
	 */
	public static function pdf( array $class, array $assignment, ?int $only_user = null ): string {
		$aid   = (int) $assignment['id'];
		$group = 'GROUP' === $assignment['work_mode'];
		$cards = array();
		if ( $group ) {
			foreach ( Teams::teams( $aid ) as $t ) {
				foreach ( $t['members'] as $m ) {
					$others  = array();
					foreach ( $t['members'] as $o ) {
						if ( (int) $o['user_id'] !== (int) $m['user_id'] ) {
							$others[] = Teams::short_name( (int) $o['user_id'] );
						}
					}
					$cards[] = array( $m, $t['name'], $others );
				}
			}
		} else {
			foreach ( Teams::roster( $aid ) as $m ) {
				$cards[] = array( $m, '', array() );
			}
		}
		if ( null !== $only_user ) {
			$cards = array_values( array_filter( $cards, static fn( $c ) => (int) $c[0]['user_id'] === $only_user ) );
		}
		$pdf   = new Pdf( 'Codes personnels – ' . $assignment['title'] );
		$total = count( $cards );
		foreach ( $cards as $i => [ $m, $team, $others ] ) {
			$pdf->add_page();
			self::page( $pdf, $class, $assignment, $m, $team, $others, $i + 1, $total );
		}
		if ( ! $cards ) {
			$pdf->add_page();
			$pdf->text( self::M, 80, 'Aucun apprenti dans la liste de cette mission.', 'F1', 12, self::INK );
		}
		return $pdf->output();
	}

	private static function page( Pdf $p, array $class, array $a, array $m, string $team, array $others, int $n, int $total ): void {
		$W = Pdf::W;
		$x = self::M;
		$w = $W - 2 * self::M;
		$u = get_userdata( (int) $m['user_id'] );

		// Bandeau : logo LogiERP, titre de la fiche, classe.
		$p->rect( 0, 0, $W, 96, self::INK );
		$p->rect( 0, 96, $W, 6, self::YELLOW );
		$s  = 48 / 64;
		$lx = $x;
		$ly = 22;
		$pt = static fn( array $xy ) => array_map( static fn( $q ) => array( $lx + $q[0] * $s, $ly + $q[1] * $s ), $xy );
		$p->polygon( $pt( array( array( 32, 6 ), array( 56, 19 ), array( 56, 45 ), array( 32, 58 ), array( 8, 45 ), array( 8, 19 ) ) ), '#1f8a3b' );
		$p->polygon( $pt( array( array( 32, 6 ), array( 56, 19 ), array( 32, 32 ), array( 8, 19 ) ) ), '#3fb35f' );
		$p->polygon( $pt( array( array( 32, 32 ), array( 32, 58 ), array( 8, 45 ), array( 8, 19 ) ) ), '#16692c' );
		$p->text( $x + 56, 52, 'Logi', 'F2', 24, '#ffffff' );
		$p->text( $x + 56 + $p->width( 'Logi', 'F2', 24 ), 52, 'ERP', 'F2', 24, '#2fae5f' );
		$p->text( $x + 56, 72, 'Code personnel de mission', 'F1', 11, '#c9d2dc' );
		$p->rect( $W - self::M - 150, 30, 150, 22, self::YELLOW, null, 11 );
		$p->text( $W - self::M - 75, 45, 'FICHE PERSONNELLE', 'F2', 9, self::INK, 'c', 0.8 );
		$p->text( $W - self::M, 72, self::fit( $p, (string) $class['name'], 'F1', 10, 250 ), 'F1', 10, '#c9d2dc', 'r' );

		// Apprenti et groupe.
		$y  = 124;
		$bh = $team ? 84 : 66;
		$p->rect( $x, $y, $w, $bh, self::PAPER, null, 10 );
		$p->rect( $x, $y, 6, $bh, self::YELLOW );
		$p->text( $x + 22, $y + 22, 'APPRENTI·E', 'F2', 8.5, self::MUTED, 'l', 1 );
		$p->text( $x + 22, $y + 48, self::fit( $p, $u ? $u->display_name : '#' . $m['user_id'], 'F2', 22, $w - 40 ), 'F2', 22, self::INK );
		if ( $team ) {
			$p->text( $x + 22, $y + 70, self::fit( $p, $team . ( $others ? ' · avec ' . implode( ', ', $others ) : '' ), 'F2', 11.5, $w - 40 ), 'F2', 11.5, self::GREEN );
		}

		// Mission.
		$y += $bh + 30;
		$p->text( $x, $y, 'MISSION', 'F2', 8.5, self::MUTED, 'l', 1 );
		$y = $p->paragraph( $x, $y + 4, $w, (string) $a['title'], 'F2', 18, self::INK, 1.2 );
		$y += 10;
		$rows = array(
			array( 'Mode de travail', 'GROUP' === $a['work_mode'] ? 'En groupe : une mission partagée par le groupe, une note commune' : 'Individuel : votre propre mission' ),
			array( 'Ouverture', $a['opens_at'] ? Ui::fr_datetime( $a['opens_at'] ) : 'dès maintenant' ),
			array( 'Fermeture', $a['closes_at'] ? Ui::fr_datetime( $a['closes_at'] ) : 'sans date de fin' ),
			array( 'Durée maximale', (int) $a['time_limit_min'] ? (int) $a['time_limit_min'] . ' min' : 'sans limite' ),
			array( 'Tentatives', (int) $a['max_attempts'] . ( 'GROUP' === $a['work_mode'] ? ' (pour le groupe)' : '' ) ),
		);
		foreach ( $rows as [ $k, $v ] ) {
			$p->line( $x, $y, $x + $w, $y, '#d9ddd6', 0.8 );
			$p->text( $x, $y + 15, $k, 'F1', 10, self::MUTED );
			$p->text( $x + 130, $y + 15, self::fit( $p, $v, 'F2', 10, $w - 130 ), 'F2', 10, self::INK );
			$y += 22;
		}
		$p->line( $x, $y, $x + $w, $y, '#d9ddd6', 0.8 );

		// Le code.
		$y += 24;
		$p->rect( $x, $y, $w, 132, self::INK, null, 14 );
		$p->text( $W / 2, $y + 30, 'VOTRE CODE PERSONNEL', 'F2', 10, self::YELLOW, 'c', 2 );
		$code = Teams::format( (string) $m['code'] );
		$p->rect( $W / 2 - 165, $y + 44, 330, 58, '#ffffff', null, 8 );
		$p->text( $W / 2, $y + 88, $code, 'F3', 40, self::INK, 'c', 4 );
		$p->text( $W / 2, $y + 120, 'À saisir dans « Code de mission », sur le portail du simulateur', 'F1', 10, '#c9d2dc', 'c' );

		// Marche à suivre.
		$y += 132 + 22;
		$steps = array(
			'Connectez-vous au site avec votre propre compte.',
			'Ouvrez le simulateur LogiERP : ' . self::app_url() . '.',
			'Saisissez ce code dans « Code de mission » (ou sur la tuile de la mission), puis cliquez sur « Commencer la mission ».',
			'GROUP' === $a['work_mode']
				? 'Vos coéquipiers entrent avec leur propre code : vous travaillez tous dans la même mission, chacun depuis son poste.'
				: 'Le code ne se saisit qu’une fois : ensuite, le bouton « Reprendre » suffit.',
		);
		$h = 36;
		foreach ( $steps as $st ) {
			$h += count( $p->wrap( $st, 'F1', 10.5, $w - 70 ) ) * 14 + 8;
		}
		$p->rect( $x, $y, $w, $h, null, '#d9ddd6', 10, 1 );
		$p->text( $x + 18, $y + 22, 'Comment commencer', 'F2', 12, self::INK );
		$sy = $y + 34;
		foreach ( $steps as $k => $st ) {
			$p->rect( $x + 18, $sy, 18, 18, self::YELLOW, null, 9 );
			$p->text( $x + 27, $sy + 13, (string) ( $k + 1 ), 'F2', 10, self::INK, 'c' );
			$sy = $p->paragraph( $x + 46, $sy, $w - 70, $st, 'F1', 10.5, self::INK, 1.33 ) + 8;
		}

		// Avertissement.
		$y += $h + 16;
		$warn = 'Code personnel : il ne fonctionne qu’avec votre compte. Ne le prêtez pas et ne saisissez jamais le code d’un camarade : la tentative est refusée et signalée à votre enseignant·e.';
		$lines = $p->wrap( $warn, 'F2', 10, $w - 36 );
		$wh    = count( $lines ) * 13.5 + 20;
		$p->rect( $x, $y, $w, $wh, '#fde7e4', null, 8 );
		$p->rect( $x, $y, 5, $wh, self::RED );
		$p->paragraph( $x + 18, $y + 6, $w - 36, $warn, 'F2', 10, self::RED, 1.35 );

		// Pied de page.
		$fy = Pdf::H - 34;
		$p->line( $x, $fy - 12, $x + $w, $fy - 12, '#d9ddd6', 0.8 );
		$p->text( $x, $fy, 'LogiERP · fiche générée le ' . wp_date( 'd.m.Y à H:i' ), 'F1', 8.5, self::MUTED );
		$p->text( $x + $w, $fy, sprintf( 'Fiche %d / %d', $n, $total ), 'F1', 8.5, self::MUTED, 'r' );
	}

	/** Adresse du simulateur, sans protocole (plus lisible sur papier). */
	private static function app_url(): string {
		return (string) preg_replace( '#^https?://#', '', untrailingslashit( \LogiERP\Core\Activator::app_url() ) );
	}

	/** Raccourcit un texte trop long pour sa place (points de suspension). */
	private static function fit( Pdf $p, string $s, string $font, float $size, float $w ): string {
		if ( $p->width( $s, $font, $size ) <= $w ) {
			return $s;
		}
		while ( mb_strlen( $s ) > 2 && $p->width( $s . '…', $font, $size ) > $w ) {
			$s = mb_substr( $s, 0, -1 );
		}
		return rtrim( $s ) . '…';
	}
}

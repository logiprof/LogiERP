<?php
declare(strict_types=1);

namespace LogiERP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Petit générateur PDF autonome (A4, polices standard Helvetica et Courier, encodage WinAnsi).
 * Sans bibliothèque externe : formes, textes, retours à la ligne. Coordonnées en points, origine en haut à gauche.
 */
final class Pdf {

	public const W = 595.28;
	public const H = 841.89;

	/** Largeurs des glyphes (1/1000 em) des caractères 32 à 255, encodage WinAnsi. */
	private const WIDTHS = array(
		'F1' => array( 278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,350,556,350,222,556,333,1000,556,556,333,1000,667,333,1000,350,611,350,350,222,222,333,333,350,556,1000,333,1000,500,333,944,350,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500 ),
		'F2' => array( 278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,350,556,350,278,556,500,1000,556,556,333,1000,667,333,1000,350,611,350,350,278,278,500,500,350,556,1000,333,1000,556,333,944,350,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556 ),
	);

	private const FONTS = array( 'F1' => 'Helvetica', 'F2' => 'Helvetica-Bold', 'F3' => 'Courier-Bold' );

	/** @var string[] */
	private array $pages = array();
	private string $cur  = '';
	private string $title;

	public function __construct( string $title ) {
		$this->title = $title;
	}

	public function add_page(): void {
		if ( '' !== $this->cur ) {
			$this->pages[] = $this->cur;
		}
		$this->cur = '';
	}

	public function page_count(): int {
		return count( $this->pages ) + ( '' !== $this->cur ? 1 : 0 );
	}

	/** Texte UTF-8 converti en octets WinAnsi (caractères absents translittérés). */
	public static function enc( string $s ): string {
		$s = str_replace( array( "\u{202F}", "\u{00A0}", "\u{2011}" ), array( ' ', ' ', '-' ), $s );
		$o = function_exists( 'iconv' ) ? @iconv( 'UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $o ) {
			$o = mb_convert_encoding( $s, 'Windows-1252', 'UTF-8' );
		}
		return (string) $o;
	}

	private static function esc( string $bytes ): string {
		return strtr( $bytes, array( '\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ' ) );
	}

	private static function rgb( string $hex ): string {
		$hex = ltrim( $hex, '#' );
		return sprintf( '%.3F %.3F %.3F', hexdec( substr( $hex, 0, 2 ) ) / 255, hexdec( substr( $hex, 2, 2 ) ) / 255, hexdec( substr( $hex, 4, 2 ) ) / 255 );
	}

	public function width( string $s, string $font, float $size ): float {
		$b = self::enc( $s );
		if ( 'F3' === $font ) {
			return strlen( $b ) * 0.6 * $size;
		}
		$w = 0;
		$t = self::WIDTHS[ $font ];
		for ( $i = 0, $n = strlen( $b ); $i < $n; $i++ ) {
			$c  = ord( $b[ $i ] );
			$w += $c >= 32 ? $t[ $c - 32 ] : 0;
		}
		return $w * $size / 1000;
	}

	/** Rectangle (coins arrondis si $r > 0), plein et/ou contour. */
	public function rect( float $x, float $y, float $w, float $h, ?string $fill, ?string $stroke = null, float $r = 0, float $lw = 1 ): void {
		$y0 = self::H - $y - $h;
		$op = $fill && $stroke ? 'B' : ( $fill ? 'f' : 'S' );
		$c  = ( $fill ? self::rgb( $fill ) . ' rg ' : '' ) . ( $stroke ? self::rgb( $stroke ) . ' RG ' . sprintf( '%.2F w ', $lw ) : '' );
		if ( $r <= 0 ) {
			$this->cur .= $c . sprintf( '%.2F %.2F %.2F %.2F re %s', $x, $y0, $w, $h, $op ) . "\n";
			return;
		}
		$r = min( $r, $w / 2, $h / 2 );
		$k = 0.5523 * $r;
		$x1 = $x + $w;
		$y1 = $y0 + $h;
		$p  = sprintf( '%.2F %.2F m ', $x + $r, $y0 );
		$p .= sprintf( '%.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c ', $x1 - $r, $y0, $x1 - $r + $k, $y0, $x1, $y0 + $r - $k, $x1, $y0 + $r );
		$p .= sprintf( '%.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c ', $x1, $y1 - $r, $x1, $y1 - $r + $k, $x1 - $r + $k, $y1, $x1 - $r, $y1 );
		$p .= sprintf( '%.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c ', $x + $r, $y1, $x + $r - $k, $y1, $x, $y1 - $r + $k, $x, $y1 - $r );
		$p .= sprintf( '%.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c h ', $x, $y0 + $r, $x, $y0 + $r - $k, $x + $r - $k, $y0, $x + $r, $y0 );
		$this->cur .= $c . $p . $op . "\n";
	}

	/** Polygone plein (points [x, y] depuis le haut de la page). */
	public function polygon( array $pts, string $fill ): void {
		$p = '';
		foreach ( $pts as $i => [ $x, $y ] ) {
			$p .= sprintf( '%.2F %.2F %s ', $x, self::H - $y, 0 === $i ? 'm' : 'l' );
		}
		$this->cur .= self::rgb( $fill ) . ' rg ' . $p . "h f\n";
	}

	/** Trait en pointillés ou plein. */
	public function line( float $x1, float $y1, float $x2, float $y2, string $color, float $lw = 1, bool $dash = false ): void {
		$this->cur .= self::rgb( $color ) . ' RG ' . sprintf( '%.2F w ', $lw ) . ( $dash ? '[4 3] 0 d ' : '' ) . sprintf( '%.2F %.2F m %.2F %.2F l S', $x1, self::H - $y1, $x2, self::H - $y2 ) . ( $dash ? ' [] 0 d' : '' ) . "\n";
	}

	/** Texte sur une ligne ; $y = ligne de base depuis le haut. $align : l, c, r (par rapport à $x). */
	public function text( float $x, float $y, string $s, string $font, float $size, string $color, string $align = 'l', float $spacing = 0 ): void {
		$w = $this->width( $s, $font, $size ) + $spacing * max( 0, mb_strlen( $s ) - 1 );
		if ( 'c' === $align ) {
			$x -= $w / 2;
		} elseif ( 'r' === $align ) {
			$x -= $w;
		}
		$this->cur .= sprintf( 'BT /%s %.2F Tf %s rg %.2F Tc %.2F %.2F Td (%s) Tj ET', $font, $size, self::rgb( $color ), $spacing, $x, self::H - $y, self::esc( self::enc( $s ) ) ) . "\n";
	}

	/** Découpe un texte en lignes tenant dans $w. */
	public function wrap( string $s, string $font, float $size, float $w ): array {
		$out = array();
		// Typographie française : pas de retour à la ligne avant « : ; ! ? » ni après « .
		$s = (string) preg_replace( array( '/« /u', '/ ([»:;!?])/u' ), array( "«\u{00A0}", "\u{00A0}$1" ), $s );
		foreach ( preg_split( '/\R/u', $s ) as $para ) {
			$line = '';
			foreach ( preg_split( '/[ \t]+/u', trim( $para ) ) as $word ) {
				$try = '' === $line ? $word : $line . ' ' . $word;
				if ( '' !== $line && $this->width( $try, $font, $size ) > $w ) {
					$out[] = $line;
					$line  = $word;
				} else {
					$line = $try;
				}
				while ( $this->width( $line, $font, $size ) > $w && mb_strlen( $line ) > 1 ) {
					// Mot plus long que la ligne : coupé.
					$cut = mb_strlen( $line ) - 1;
					while ( $cut > 1 && $this->width( mb_substr( $line, 0, $cut ), $font, $size ) > $w ) {
						--$cut;
					}
					$out[] = mb_substr( $line, 0, $cut );
					$line  = mb_substr( $line, $cut );
				}
			}
			$out[] = $line;
		}
		return $out;
	}

	/** Paragraphe ; renvoie l'ordonnée sous la dernière ligne. */
	public function paragraph( float $x, float $y, float $w, string $s, string $font, float $size, string $color, float $leading = 1.3, string $align = 'l' ): float {
		foreach ( $this->wrap( $s, $font, $size, $w ) as $l ) {
			$y += $size * $leading;
			$this->text( 'c' === $align ? $x + $w / 2 : $x, $y - $size * ( $leading - 1 ) / 2, $l, $font, $size, $color, $align );
		}
		return $y;
	}

	/** Document PDF complet. */
	public function output(): string {
		$pages = $this->pages;
		if ( '' !== $this->cur ) {
			$pages[] = $this->cur;
		}
		$objs   = array();
		$objs[] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objs[] = '';
		$font_ids = array();
		foreach ( self::FONTS as $k => $base ) {
			$objs[]          = '<< /Type /Font /Subtype /Type1 /BaseFont /' . $base . ' /Encoding /WinAnsiEncoding >>';
			$font_ids[ $k ] = count( $objs );
		}
		$res  = '<< /Font << ' . implode( ' ', array_map( static fn( $k, $id ) => '/' . $k . ' ' . $id . ' 0 R', array_keys( $font_ids ), $font_ids ) ) . ' >> >>';
		$kids = array();
		foreach ( $pages as $content ) {
			$z      = function_exists( 'gzcompress' ) ? gzcompress( $content, 6 ) : $content;
			$filter = function_exists( 'gzcompress' ) ? ' /Filter /FlateDecode' : '';
			$objs[] = '<< /Length ' . strlen( $z ) . $filter . " >>\nstream\n" . $z . "\nendstream";
			$cid    = count( $objs );
			$objs[] = sprintf( '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources %s /Contents %d 0 R >>', self::W, self::H, $res, $cid );
			$kids[] = count( $objs ) . ' 0 R';
		}
		$objs[1] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . count( $kids ) . ' >>';
		$objs[]  = '<< /Title <FEFF' . strtoupper( bin2hex( (string) mb_convert_encoding( $this->title, 'UTF-16BE', 'UTF-8' ) ) ) . '> /Producer (LogiERP) /CreationDate (D:' . gmdate( 'YmdHis' ) . "Z) >>";
		$info    = count( $objs );
		$out     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offs    = array();
		foreach ( $objs as $i => $o ) {
			$offs[] = strlen( $out );
			$out   .= ( $i + 1 ) . " 0 obj\n" . $o . "\nendobj\n";
		}
		$xref = strlen( $out );
		$out .= "xref\n0 " . ( count( $objs ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offs as $o ) {
			$out .= sprintf( "%010d 00000 n \n", $o );
		}
		$out .= "trailer\n<< /Size " . ( count( $objs ) + 1 ) . " /Root 1 0 R /Info {$info} 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
		return $out;
	}
}

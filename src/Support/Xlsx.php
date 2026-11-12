<?php
declare(strict_types=1);

namespace LogiERP\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lecture et écriture minimales de classeurs Excel (.xlsx, Office Open XML), sans dépendance.
 *
 * Écriture : feuilles à chaînes en ligne (inlineStr), ligne d'en-tête figée et mise en forme,
 * largeurs de colonnes et listes de choix (validation des données).
 * Lecture : première feuille (ou feuille nommée), chaînes partagées, cellules typées ; protections
 * contre les archives piégées (taille décompressée bornée) et les entités XML (aucune résolution
 * d'entité ni accès réseau).
 */
final class Xlsx {

	public const MAX_UNCOMPRESSED = 40 * 1024 * 1024; // 40 Mo décompressés au maximum.
	public const MAX_ROWS         = 6000;

	public static function available(): bool {
		return class_exists( \ZipArchive::class );
	}

	/* ─────────────────────────── Écriture ─────────────────────────── */

	/**
	 * Construit un classeur et retourne son contenu binaire.
	 *
	 * @param array<int,array{name:string,rows:array<int,array<int,string|int|float|null>>,widths?:array<int,int>,header?:bool,lists?:array<int,string[]>,notes?:array<int,string>}> $sheets
	 */
	public static function build( array $sheets ): string {
		$tmp = wp_tempnam( 'logierp-xlsx' );
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $tmp, \ZipArchive::OVERWRITE ) ) {
			throw new \RuntimeException( 'Impossible de créer le fichier Excel.' );
		}
		$n = count( $sheets );
		$ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
		for ( $i = 1; $i <= $n; $i++ ) {
			$ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		}
		$zip->addFromString( '[Content_Types].xml', $ct . '</Types>' );
		$zip->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>' );
		$now = gmdate( 'Y-m-d\TH:i:s\Z' );
		$zip->addFromString( 'docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:creator>LogiERP</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified></cp:coreProperties>' );
		$zip->addFromString( 'docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>LogiERP</Application></Properties>' );

		$wb   = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
		$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
		foreach ( array_values( $sheets ) as $i => $sheet ) {
			$id    = $i + 1;
			$name  = mb_substr( preg_replace( '/[\\\\\/\?\*\[\]:]/u', ' ', (string) $sheet['name'] ), 0, 31 );
			$wb   .= '<sheet name="' . self::x( $name ) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
			$rels .= '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $id . '.xml"/>';
			$zip->addFromString( 'xl/worksheets/sheet' . $id . '.xml', self::sheet_xml( $sheet ) );
		}
		$rels .= '<Relationship Id="rId' . ( $n + 1 ) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
		$zip->addFromString( 'xl/workbook.xml', $wb . '</sheets></workbook>' );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', $rels );
		// Styles : 0 normal, 1 en-tête (gras blanc sur anthracite), 2 colonne obligatoire (gras sur jaune), 3 texte d'aide (retour à la ligne), 4 exemple (gris italique).
		$zip->addFromString( 'xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="4"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FF313A45"/><name val="Calibri"/></font><font><i/><sz val="11"/><color rgb="FF5B6672"/><name val="Calibri"/></font></fonts><fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF313A45"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF5C400"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf><xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>' );
		$zip->close();
		$data = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier temporaire local.
		wp_delete_file( $tmp );
		return $data;
	}

	private static function sheet_xml( array $sheet ): string {
		$rows    = (array) $sheet['rows'];
		$header  = ! empty( $sheet['header'] );
		$req     = (array) ( $sheet['required'] ?? array() );
		$xml     = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
		if ( $header ) {
			$xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
		}
		if ( ! empty( $sheet['widths'] ) ) {
			$xml .= '<cols>';
			foreach ( $sheet['widths'] as $i => $w ) {
				$xml .= '<col min="' . ( $i + 1 ) . '" max="' . ( $i + 1 ) . '" width="' . (int) $w . '" customWidth="1"/>';
			}
			$xml .= '</cols>';
		}
		$xml .= '<sheetData>';
		foreach ( array_values( $rows ) as $r => $cells ) {
			$xml .= '<row r="' . ( $r + 1 ) . '">';
			foreach ( array_values( (array) $cells ) as $c => $v ) {
				if ( null === $v || '' === $v ) {
					continue;
				}
				$ref   = self::col( $c ) . ( $r + 1 );
				$style = 0;
				if ( $header && 0 === $r ) {
					$style = in_array( $c, $req, true ) ? 2 : 1;
				} elseif ( ! empty( $sheet['wrap'] ) ) {
					$style = 3;
				} elseif ( ! empty( $sheet['example_rows'] ) && $r <= (int) $sheet['example_rows'] ) {
					$style = 4;
				}
				$s = $style ? ' s="' . $style . '"' : '';
				if ( is_int( $v ) || is_float( $v ) ) {
					$xml .= '<c r="' . $ref . '"' . $s . '><v>' . self::num( $v ) . '</v></c>';
				} else {
					$xml .= '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">' . self::x( self::safe_text( (string) $v ) ) . '</t></is></c>';
				}
			}
			$xml .= '</row>';
		}
		$xml .= '</sheetData>';
		if ( $header && $rows ) {
			$xml .= '<autoFilter ref="A1:' . self::col( max( 0, count( (array) $rows[0] ) - 1 ) ) . '1"/>';
		}
		if ( ! empty( $sheet['lists'] ) ) {
			$xml .= '<dataValidations count="' . count( $sheet['lists'] ) . '">';
			foreach ( $sheet['lists'] as $c => $values ) {
				$formula = '"' . implode( ',', array_map( static fn( $v ) => str_replace( array( '"', ',' ), '', (string) $v ), $values ) ) . '"';
				if ( strlen( $formula ) > 255 ) {
					continue;
				}
				$col  = self::col( (int) $c );
				$xml .= '<dataValidation type="list" allowBlank="1" showErrorMessage="1" errorTitle="Valeur non autorisée" error="Choisissez une valeur de la liste (voir la feuille Aide)." sqref="' . $col . '2:' . $col . self::MAX_ROWS . '"><formula1>' . self::x( $formula ) . '</formula1></dataValidation>';
			}
			$xml .= '</dataValidations>';
		}
		return $xml . '</worksheet>';
	}

	/** Neutralise les formules (un texte commençant par =, +, - ou @ resterait du texte). */
	private static function safe_text( string $v ): string {
		return preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v );
	}

	private static function num( $v ): string {
		if ( is_int( $v ) ) {
			return (string) $v;
		}
		$s = rtrim( rtrim( number_format( (float) $v, 6, '.', '' ), '0' ), '.' );
		return '' === $s || '-0' === $s ? '0' : $s;
	}

	private static function x( string $s ): string {
		return htmlspecialchars( $s, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	public static function col( int $i ): string {
		$s = '';
		for ( $n = $i + 1; $n > 0; $n = intdiv( $n - 1, 26 ) ) {
			$s = chr( 65 + ( ( $n - 1 ) % 26 ) ) . $s;
		}
		return $s;
	}

	private static function col_index( string $letters ): int {
		$n = 0;
		foreach ( str_split( strtoupper( $letters ) ) as $ch ) {
			$n = $n * 26 + ( ord( $ch ) - 64 );
		}
		return $n - 1;
	}

	/* ─────────────────────────── Lecture ─────────────────────────── */

	/**
	 * Lit une feuille d'un classeur .xlsx.
	 *
	 * @param string   $file   Chemin du fichier téléversé.
	 * @param string[] $prefer Noms de feuille préférés (sinon la première feuille).
	 * @return array<int,array<int,string>> lignes (index 0 = ligne 1), cellules en texte.
	 */
	public static function read( string $file, array $prefer = array() ): array {
		if ( ! self::available() ) {
			throw new \RuntimeException( 'L\'extension PHP « zip » n\'est pas active sur ce serveur : enregistrez le fichier au format CSV (UTF-8) puis importez-le.' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $file, \ZipArchive::RDONLY ) ) {
			throw new \RuntimeException( 'Ce fichier n\'est pas un classeur Excel (.xlsx) valide.' );
		}
		try {
			$total = 0;
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$st     = $zip->statIndex( $i );
				$total += (int) ( $st['size'] ?? 0 );
				if ( $total > self::MAX_UNCOMPRESSED ) {
					throw new \RuntimeException( 'Le classeur est trop volumineux une fois décompressé.' );
				}
			}
			$path = self::sheet_path( $zip, $prefer );
			$sst  = array();
			$raw  = $zip->getFromName( 'xl/sharedStrings.xml' );
			if ( false !== $raw ) {
				$xml = self::load_xml( $raw );
				foreach ( $xml->si as $si ) {
					$sst[] = self::si_text( $si );
				}
			}
			$raw = $zip->getFromName( $path );
			if ( false === $raw ) {
				throw new \RuntimeException( 'Feuille introuvable dans le classeur.' );
			}
			$xml  = self::load_xml( $raw );
			$rows = array();
			if ( isset( $xml->sheetData->row ) ) {
				foreach ( $xml->sheetData->row as $row ) {
					$r = (int) $row['r'] - 1;
					if ( $r < 0 ) {
						$r = count( $rows );
					}
					if ( $r >= self::MAX_ROWS + 1 ) {
						throw new \RuntimeException( sprintf( 'Le classeur dépasse %d lignes : découpez-le en plusieurs fichiers.', self::MAX_ROWS ) );
					}
					$cells = array();
					$next  = 0;
					foreach ( $row->c as $c ) {
						$ref = (string) $c['r'];
						$idx = '' !== $ref && preg_match( '/^([A-Z]+)/', $ref, $mm ) ? self::col_index( $mm[1] ) : $next;
						$next = $idx + 1;
						if ( $idx > 200 ) {
							continue;
						}
						$t = (string) $c['t'];
						if ( 's' === $t ) {
							$v = $sst[ (int) $c->v ] ?? '';
						} elseif ( 'inlineStr' === $t ) {
							$v = isset( $c->is ) ? self::si_text( $c->is ) : '';
						} elseif ( 'b' === $t ) {
							$v = '1' === (string) $c->v ? '1' : '0';
						} else {
							$v = (string) $c->v;
							if ( 'e' !== $t && is_numeric( $v ) && ( str_contains( $v, 'E' ) || str_contains( $v, 'e' ) || strlen( $v ) > 12 ) ) {
								// Nombres flottants Excel (ex. 0.30000000000000004) : arrondi raisonnable.
								$v = rtrim( rtrim( number_format( (float) $v, 6, '.', '' ), '0' ), '.' );
							}
						}
						$cells[ $idx ] = trim( (string) $v );
					}
					if ( $cells ) {
						$max = max( array_keys( $cells ) );
						$line = array();
						for ( $i = 0; $i <= $max; $i++ ) {
							$line[] = $cells[ $i ] ?? '';
						}
						$rows[ $r ] = $line;
					}
				}
			}
			ksort( $rows );
			$out = array();
			$last = $rows ? max( array_keys( $rows ) ) : -1;
			for ( $i = 0; $i <= $last; $i++ ) {
				$out[] = $rows[ $i ] ?? array();
			}
			return $out;
		} finally {
			$zip->close();
		}
	}

	private static function sheet_path( \ZipArchive $zip, array $prefer ): string {
		$wb   = $zip->getFromName( 'xl/workbook.xml' );
		$rels = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );
		if ( false === $wb || false === $rels ) {
			throw new \RuntimeException( 'Ce fichier n\'est pas un classeur Excel (.xlsx) valide.' );
		}
		$wbx  = self::load_xml( $wb );
		$relx = self::load_xml( $rels );
		$map  = array();
		foreach ( $relx->Relationship as $rel ) {
			$target = ltrim( (string) $rel['Target'], '/' );
			$map[ (string) $rel['Id'] ] = str_starts_with( $target, 'xl/' ) ? $target : 'xl/' . $target;
		}
		$sheets = array();
		foreach ( $wbx->sheets->sheet as $s ) {
			$rid = (string) $s->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' )['id'];
			if ( isset( $map[ $rid ] ) ) {
				$sheets[ mb_strtolower( (string) $s['name'] ) ] = $map[ $rid ];
			}
		}
		if ( ! $sheets ) {
			throw new \RuntimeException( 'Le classeur ne contient aucune feuille.' );
		}
		foreach ( $prefer as $name ) {
			if ( isset( $sheets[ mb_strtolower( $name ) ] ) ) {
				return self::clean_path( $sheets[ mb_strtolower( $name ) ] );
			}
		}
		return self::clean_path( reset( $sheets ) );
	}

	private static function clean_path( string $p ): string {
		$p = str_replace( '\\', '/', $p );
		if ( str_contains( $p, '..' ) || ! preg_match( '#^xl/[A-Za-z0-9_/]+\.xml$#', $p ) ) {
			throw new \RuntimeException( 'Chemin de feuille invalide dans le classeur.' );
		}
		return $p;
	}

	private static function load_xml( string $raw ): \SimpleXMLElement {
		if ( preg_match( '/<!DOCTYPE|<!ENTITY/i', $raw ) ) {
			throw new \RuntimeException( 'Le classeur contient des déclarations XML non autorisées.' );
		}
		$prev = libxml_use_internal_errors( true );
		$xml  = simplexml_load_string( $raw, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( false === $xml ) {
			throw new \RuntimeException( 'Le classeur est endommagé (XML illisible).' );
		}
		return $xml;
	}

	private static function si_text( \SimpleXMLElement $si ): string {
		if ( isset( $si->t ) ) {
			return (string) $si->t;
		}
		$s = '';
		foreach ( $si->r as $run ) {
			$s .= (string) $run->t;
		}
		return $s;
	}

	/**
	 * Lit un CSV (UTF-8, séparateur ; ou ,) — solution de repli si l'extension zip manque.
	 *
	 * @return array<int,array<int,string>>
	 */
	public static function read_csv( string $file ): array {
		$raw = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier téléversé local.
		$raw = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
		if ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			$raw = mb_convert_encoding( $raw, 'UTF-8', 'Windows-1252' );
		}
		$first = strtok( $raw, "\n" );
		$sep   = substr_count( (string) $first, ';' ) >= substr_count( (string) $first, ',' ) ? ';' : ',';
		$rows  = array();
		$h     = fopen( 'php://memory', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $h, $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $h );
		while ( false !== ( $line = fgetcsv( $h, 0, $sep, '"', '' ) ) ) {
			if ( count( $rows ) > self::MAX_ROWS ) {
				throw new \RuntimeException( sprintf( 'Le fichier dépasse %d lignes : découpez-le en plusieurs fichiers.', self::MAX_ROWS ) );
			}
			$rows[] = array_map( static fn( $v ) => trim( (string) $v ), (array) $line );
		}
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $rows;
	}
}

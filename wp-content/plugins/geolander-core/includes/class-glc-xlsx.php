<?php
/**
 * A small .xlsx writer and reader, on PHP's own zip and XML extensions.
 *
 * An .xlsx file is a zip of XML parts. This class writes the few parts Excel
 * needs to open a workbook of plain tables, and reads cell values back out of
 * a workbook Excel (or anything else) saved. It knows nothing about cars or
 * prices, and nothing about WordPress: GLC_Partner_Excel decides what goes in
 * a sheet and what a cell may hold.
 *
 * Reading treats the file as hostile, because it comes from a browser:
 *
 *  - nothing is ever unpacked to disk. Parts are read from the archive by name,
 *    so a path inside the zip cannot reach a file outside it; an archive that
 *    carries such a path is refused anyway;
 *  - every part is read through a counter and refused past the caller's limit,
 *    whatever size the zip claims for it, so a small file cannot unfold into a
 *    large one in memory;
 *  - a part that declares a DOCTYPE or an entity is refused before it is
 *    parsed, as is one that is not plain UTF-8 (where such a declaration could
 *    hide). Excel writes neither. The parser is also told never to fetch
 *    anything (LIBXML_NONET) and is never asked to expand entities;
 *  - rows and cells are counted while reading and refused past the limit.
 *
 * A refusal is a GLC_Xlsx_Refused carrying a short reason word and its details;
 * the caller turns that into a sentence in the reader's language.
 */

defined( 'ABSPATH' ) || exit;

class GLC_Xlsx_Refused extends Exception {

	/** @param string $reason one of GLC_Xlsx::REASONS @param array $details what the sentence needs (a part's name, a limit) */
	public function __construct( public string $reason, public array $details = [] ) {
		parent::__construct( $reason );
	}
}

class GLC_Xlsx {

	/** Why a file is refused. */
	public const REASONS = [ 'not_zip', 'path', 'no_workbook', 'too_big', 'doctype', 'bad_xml', 'too_many_rows', 'too_many_cells' ];

	/** A cell's kind, as the reader reports it. */
	public const NUMBER = 'n';
	public const TEXT   = 's';

	/** Cell styles the writer knows: plain, a bold heading, and text that Excel must not re-type as a date or a number. */
	private const STYLE_PLAIN   = 0;
	private const STYLE_HEADING = 1;
	private const STYLE_TEXT    = 2;

	private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
	private const NS_REL  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
	private const NS_PKG  = 'http://schemas.openxmlformats.org/package/2006/relationships';

	/** How much of a part is taken from the archive at a time while it is counted. */
	private const CHUNK = 65536;

	/** Excel's day 0 in its usual calendar and in the 1904 one some Mac workbooks use. */
	private const EPOCH_1900 = '1899-12-30';
	private const EPOCH_1904 = '1904-01-01';
	/** The last day Excel can show, 9999-12-31, as its number. */
	private const LAST_SERIAL = 2958465;

	/* ---------------------------------------------------------------- writing */

	/**
	 * Write a workbook.
	 *
	 * @param array  $sheets [ sheet name => rows ]; a row is a list of cells; the first row is the heading.
	 *                       A cell is a string (kept as text, whatever it looks like), an int or float
	 *                       (a number), or null (empty).
	 * @param string $path   where to write it
	 */
	public static function write( array $sheets, string $path ): bool {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return false;
		}
		$head  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
		$types = '';
		$list  = '';
		$rels  = '';
		$n     = 0;
		foreach ( $sheets as $name => $rows ) {
			++$n;
			$types .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
			$list  .= '<sheet name="' . self::escape( (string) $name ) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
			$rels  .= '<Relationship Id="rId' . $n . '" Type="' . self::NS_REL . '/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
			$zip->addFromString( 'xl/worksheets/sheet' . $n . '.xml', $head . self::sheet_xml( array_values( $rows ) ) );
		}
		$zip->addFromString( '[Content_Types].xml', $head . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. $types . '</Types>' );
		$zip->addFromString( '_rels/.rels', $head . '<Relationships xmlns="' . self::NS_PKG . '">'
			. '<Relationship Id="rId1" Type="' . self::NS_REL . '/officeDocument" Target="xl/workbook.xml"/></Relationships>' );
		$zip->addFromString( 'xl/workbook.xml', $head . '<workbook xmlns="' . self::NS_MAIN . '" xmlns:r="' . self::NS_REL . '"><sheets>' . $list . '</sheets></workbook>' );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', $head . '<Relationships xmlns="' . self::NS_PKG . '">' . $rels
			. '<Relationship Id="rId' . ( $n + 1 ) . '" Type="' . self::NS_REL . '/styles" Target="styles.xml"/></Relationships>' );
		// Format 49 is Excel's built-in "Text": a cell that carries it keeps "07-13" as typed instead of making a date of it.
		$zip->addFromString( 'xl/styles.xml', $head . '<styleSheet xmlns="' . self::NS_MAIN . '">'
			. '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
			. '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
			. '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="3">'
			. '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
			. '<xf numFmtId="49" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyNumberFormat="1"/>'
			. '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
			. '</cellXfs>'
			. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
			. '</styleSheet>' );
		return $zip->close();
	}

	private static function sheet_xml( array $rows ): string {
		$widths = [];
		$body   = '';
		foreach ( $rows as $r => $row ) {
			$cells = '';
			foreach ( array_values( $row ) as $c => $value ) {
				$widths[ $c ] = max( $widths[ $c ] ?? 0, mb_strlen( (string) $value ) );
				if ( null === $value || '' === $value ) {
					continue;
				}
				$ref = self::column_name( $c ) . ( $r + 1 );
				if ( is_int( $value ) || is_float( $value ) ) {
					$cells .= '<c r="' . $ref . '" s="' . self::STYLE_PLAIN . '"><v>' . self::number_text( $value ) . '</v></c>';
				} else {
					$cells .= '<c r="' . $ref . '" s="' . ( 0 === $r ? self::STYLE_HEADING : self::STYLE_TEXT ) . '" t="inlineStr"><is><t xml:space="preserve">' . self::escape( (string) $value ) . '</t></is></c>';
				}
			}
			$body .= '<row r="' . ( $r + 1 ) . '">' . $cells . '</row>';
		}
		$cols = '';
		foreach ( $widths as $c => $width ) {
			// Two characters of air, so a heading is not cut off at the cell's edge.
			$cols .= '<col min="' . ( $c + 1 ) . '" max="' . ( $c + 1 ) . '" width="' . ( $width + 2 ) . '" customWidth="1"/>';
		}
		return '<worksheet xmlns="' . self::NS_MAIN . '">'
			. '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
			. ( $cols ? '<cols>' . $cols . '</cols>' : '' )
			. '<sheetData>' . $body . '</sheetData></worksheet>';
	}

	/** A number as XML carries it: no exponent, no trailing zeros, a dot whatever the server's locale. */
	private static function number_text( $value ): string {
		return is_int( $value ) ? (string) $value : rtrim( rtrim( sprintf( '%.6F', $value ), '0' ), '.' );
	}

	/** Text safe inside XML: the five characters escaped, and the control characters XML 1.0 forbids dropped. */
	private static function escape( string $text ): string {
		return htmlspecialchars( (string) preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text ), ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/** 0 → A, 25 → Z, 26 → AA. */
	public static function column_name( int $index ): string {
		$name = '';
		for ( $n = $index + 1; $n > 0; $n = intdiv( $n - 1, 26 ) ) {
			$name = chr( 65 + ( $n - 1 ) % 26 ) . $name;
		}
		return $name;
	}

	/* ---------------------------------------------------------------- reading */

	/**
	 * Read every sheet of a workbook.
	 *
	 * @param string $path   the file (an upload is read where PHP put it; it is never moved or unpacked)
	 * @param array  $limits part_bytes (the most one XML part may unfold to), rows (rows with cells, per sheet),
	 *                       cells (cells with a value, per sheet)
	 * @return array [ 'date1904' => bool, 'sheets' => [ name => [ row number => [ column letters => [ kind, value ] ] ] ] ]
	 *               kind is self::NUMBER or self::TEXT; value is always a string, a number exactly as the file holds it
	 * @throws GLC_Xlsx_Refused
	 */
	public static function read( string $path, array $limits ): array {
		$zip = new ZipArchive();
		// RDONLY: the file is opened to be read and nothing else.
		if ( true !== $zip->open( $path, ZipArchive::RDONLY ) ) {
			throw new GLC_Xlsx_Refused( 'not_zip' );
		}
		$errors_before = libxml_use_internal_errors( true );
		try {
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = (string) $zip->getNameIndex( $i );
				if ( ! self::safe_name( $name ) ) {
					throw new GLC_Xlsx_Refused( 'path', [ 'part' => self::printable( $name ) ] );
				}
			}
			$max      = (int) $limits['part_bytes'];
			$workbook = self::part( $zip, 'xl/workbook.xml', $max );
			$rels     = self::part( $zip, 'xl/_rels/workbook.xml.rels', $max );
			if ( null === $workbook || null === $rels ) {
				throw new GLC_Xlsx_Refused( 'no_workbook' );
			}
			$targets = [];
			$strings = null;
			foreach ( self::elements( $rels, 'xl/_rels/workbook.xml.rels', 'Relationship' ) as $rel ) {
				$target = self::resolve( (string) $rel->getAttribute( 'Target' ) );
				if ( str_ends_with( (string) $rel->getAttribute( 'Type' ), '/sharedStrings' ) ) {
					$strings = $target;
				}
				$targets[ (string) $rel->getAttribute( 'Id' ) ] = $target;
			}
			$shared = [];
			if ( null !== $strings ) {
				$xml = self::part( $zip, $strings, $max );
				foreach ( null === $xml ? [] : self::elements( $xml, $strings, 'si' ) as $item ) {
					$shared[] = self::text_of( $item );
				}
			}
			$out = [ 'date1904' => false, 'sheets' => [] ];
			foreach ( self::elements( $workbook, 'xl/workbook.xml', 'workbookPr' ) as $properties ) {
				$out['date1904'] = in_array( (string) $properties->getAttribute( 'date1904' ), [ '1', 'true' ], true );
			}
			foreach ( self::elements( $workbook, 'xl/workbook.xml', 'sheet' ) as $sheet ) {
				$target = $targets[ (string) $sheet->getAttributeNS( self::NS_REL, 'id' ) ] ?? null;
				$xml    = null === $target ? null : self::part( $zip, $target, $max );
				if ( null === $xml ) {
					throw new GLC_Xlsx_Refused( 'no_workbook' );
				}
				$name                   = (string) $sheet->getAttribute( 'name' );
				$out['sheets'][ $name ] = self::sheet_rows( $xml, $name, $shared, $limits );
			}
			return $out;
		} finally {
			$zip->close();
			libxml_clear_errors();
			libxml_use_internal_errors( $errors_before );
		}
	}

	/** A name inside the archive that stays inside it: no climbing, no absolute path, no drive, no backslash. */
	private static function safe_name( string $name ): bool {
		return '' !== $name
			&& ! str_contains( $name, '\\' )
			&& ! str_contains( $name, "\0" )
			&& ! str_starts_with( $name, '/' )
			&& ! preg_match( '/^[A-Za-z]:/', $name )
			&& ! in_array( '..', explode( '/', $name ), true );
	}

	/** A name from a hostile file, cut down to what is safe to show a person. */
	private static function printable( string $name ): string {
		return substr( (string) preg_replace( '/[^\x20-\x7E]/', '?', $name ), 0, 80 );
	}

	/** Where a relationship of the workbook points, as a name inside the archive; null when it points outside. */
	private static function resolve( string $target ): ?string {
		$path  = str_starts_with( $target, '/' ) ? substr( $target, 1 ) : 'xl/' . $target;
		$parts = [];
		foreach ( explode( '/', $path ) as $part ) {
			if ( '..' === $part ) {
				if ( ! $parts ) {
					return null;
				}
				array_pop( $parts );
			} elseif ( '' !== $part && '.' !== $part ) {
				$parts[] = $part;
			}
		}
		$path = implode( '/', $parts );
		return self::safe_name( $path ) ? $path : null;
	}

	/**
	 * One part's XML, or null when the archive has no such part. Read in pieces
	 * and counted, so a part larger than the limit is refused by what it really
	 * unfolds to, not by the size its zip header claims.
	 */
	private static function part( ZipArchive $zip, string $name, int $max ): ?string {
		if ( false === $zip->locateName( $name ) ) {
			return null;
		}
		$stream = $zip->getStream( $name );
		if ( ! $stream ) {
			throw new GLC_Xlsx_Refused( 'bad_xml', [ 'part' => $name ] );
		}
		$xml = '';
		while ( ! feof( $stream ) ) {
			$piece = fread( $stream, self::CHUNK );
			if ( false === $piece ) {
				break;
			}
			$xml .= $piece;
			if ( strlen( $xml ) > $max ) {
				fclose( $stream );
				throw new GLC_Xlsx_Refused( 'too_big', [ 'part' => $name, 'limit' => $max ] );
			}
		}
		fclose( $stream );
		// Plain UTF-8 that starts like XML: in any other encoding a DOCTYPE could sit where the next check cannot see it.
		if ( str_contains( $xml, "\0" ) || ! preg_match( '/^(?:\xEF\xBB\xBF)?\s*</', $xml ) || ! mb_check_encoding( $xml, 'UTF-8' )
			|| ( preg_match( '/^(?:\xEF\xBB\xBF)?\s*<\?xml[^>]*\bencoding\s*=\s*["\']([^"\']*)/i', $xml, $m ) && 'utf-8' !== strtolower( $m[1] ) ) ) {
			throw new GLC_Xlsx_Refused( 'bad_xml', [ 'part' => $name ] );
		}
		if ( false !== stripos( $xml, '<!DOCTYPE' ) || false !== stripos( $xml, '<!ENTITY' ) ) {
			throw new GLC_Xlsx_Refused( 'doctype', [ 'part' => $name ] );
		}
		return $xml;
	}

	/** A reader over one part. Never the network; entities are not expanded (the reader's default) and none can be declared anyway. */
	private static function open( string $xml, string $name ): XMLReader {
		$reader = new XMLReader();
		if ( ! $reader->XML( $xml, 'UTF-8', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT ) ) {
			throw new GLC_Xlsx_Refused( 'bad_xml', [ 'part' => $name ] );
		}
		return $reader;
	}

	/** Whether the parser gave up on the part: broken XML, or nested deeper than the parser allows. */
	private static function broken(): bool {
		foreach ( libxml_get_errors() as $error ) {
			if ( $error->level >= LIBXML_ERR_ERROR ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every element of one name in a small part (the workbook, its relationships,
	 * the shared strings), each as a node of its own.
	 *
	 * @return DOMElement[]
	 */
	private static function elements( string $xml, string $name, string $element ): array {
		libxml_clear_errors();
		$reader = self::open( $xml, $name );
		$keep   = new DOMDocument();
		$found  = [];
		$moved  = $reader->read();
		while ( $moved ) {
			if ( XMLReader::ELEMENT === $reader->nodeType && $element === $reader->localName ) {
				// Copied into a document of its own: the reader's node is gone once the reader moves on.
				$node = $reader->expand();
				if ( $node instanceof DOMElement ) {
					$found[] = $keep->importNode( $node, true );
				}
				$moved = $reader->next();
			} else {
				$moved = $reader->read();
			}
		}
		$reader->close();
		if ( self::broken() ) {
			throw new GLC_Xlsx_Refused( 'bad_xml', [ 'part' => $name ] );
		}
		return $found;
	}

	/** The text of a shared string or an inline string: every <t> in it, except the phonetic guides (<rPh>) East Asian text may carry. */
	private static function text_of( DOMNode $node ): string {
		$text = '';
		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType || 'rPh' === $child->localName ) {
				continue;
			}
			$text .= 't' === $child->localName ? $child->textContent : self::text_of( $child );
		}
		return $text;
	}

	/** The cells of one sheet that hold something, by row number and column letters. */
	private static function sheet_rows( string $xml, string $sheet, array $shared, array $limits ): array {
		libxml_clear_errors();
		$reader = self::open( $xml, $sheet );
		$rows   = [];
		$row    = 0;
		$column = 0;
		$cells  = 0;
		$moved  = $reader->read();
		while ( $moved ) {
			if ( XMLReader::ELEMENT !== $reader->nodeType ) {
				$moved = $reader->read();
				continue;
			}
			if ( 'row' === $reader->localName ) {
				// Excel numbers its rows; a writer that does not means "the next one".
				$given  = (string) $reader->getAttribute( 'r' );
				$row    = ctype_digit( $given ) ? (int) $given : $row + 1;
				$column = 0;
				$moved  = $reader->read();
				continue;
			}
			if ( 'c' !== $reader->localName ) {
				$moved = $reader->read();
				continue;
			}
			$ref    = (string) $reader->getAttribute( 'r' );
			$type   = (string) $reader->getAttribute( 't' );
			$letter = preg_match( '/^([A-Z]{1,3})\d+$/', $ref, $m ) ? $m[1] : self::column_name( $column );
			$column = self::column_index( $letter ) + 1;
			$node   = $reader->expand();
			$cell   = $node instanceof DOMElement ? self::cell_value( $node, $type, $shared ) : null;
			if ( null !== $cell ) {
				if ( ! isset( $rows[ $row ] ) && count( $rows ) >= (int) $limits['rows'] ) {
					$reader->close();
					throw new GLC_Xlsx_Refused( 'too_many_rows', [ 'sheet' => self::printable( $sheet ), 'limit' => (int) $limits['rows'] ] );
				}
				if ( ++$cells > (int) $limits['cells'] ) {
					$reader->close();
					throw new GLC_Xlsx_Refused( 'too_many_cells', [ 'sheet' => self::printable( $sheet ), 'limit' => (int) $limits['cells'] ] );
				}
				$rows[ $row ][ $letter ] = $cell;
			}
			$moved = $reader->next();
		}
		$reader->close();
		if ( self::broken() ) {
			throw new GLC_Xlsx_Refused( 'bad_xml', [ 'part' => self::printable( $sheet ) ] );
		}
		return $rows;
	}

	/** One cell as [ kind, value ]; null when it holds nothing. */
	private static function cell_value( DOMElement $cell, string $type, array $shared ): ?array {
		$value  = null;
		$inline = null;
		foreach ( $cell->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType && 'v' === $child->localName ) {
				$value = $child->textContent;
			} elseif ( XML_ELEMENT_NODE === $child->nodeType && 'is' === $child->localName ) {
				$inline = self::text_of( $child );
			}
		}
		if ( 'inlineStr' === $type ) {
			$value = $inline ?? $value;
			return null === $value || '' === trim( $value ) ? null : [ self::TEXT, $value ];
		}
		if ( null === $value || '' === trim( $value ) ) {
			return null;
		}
		if ( 's' === $type ) {
			// A shared string is named by its place in the list; a place that is not there is an empty cell.
			$text = ctype_digit( $value ) ? ( $shared[ (int) $value ] ?? '' ) : '';
			return '' === trim( $text ) ? null : [ self::TEXT, $text ];
		}
		// "n" or no type is a number. Everything else (a formula's text, a boolean, an error, an ISO date) is text to the caller.
		return in_array( $type, [ '', 'n' ], true ) && is_numeric( $value ) ? [ self::NUMBER, $value ] : [ self::TEXT, $value ];
	}

	/** A → 0, Z → 25, AA → 26. */
	public static function column_index( string $letters ): int {
		$index = 0;
		foreach ( str_split( $letters ) as $letter ) {
			$index = $index * 26 + ( ord( $letter ) - 64 );
		}
		return $index - 1;
	}

	/**
	 * The calendar day behind a number Excel shows as a date, as Y-m-d; null when
	 * the number cannot be one. Excel turns "07-13" typed into an ordinary cell
	 * into such a number, in whatever language it runs.
	 */
	public static function serial_to_date( $serial, bool $date1904 = false ): ?string {
		if ( ! is_numeric( $serial ) || (float) $serial < 1 || (float) $serial > self::LAST_SERIAL ) {
			return null;
		}
		$day = (int) floor( (float) $serial );
		// Excel's 1900 calendar counts a 29 February 1900 that never was; days before it sit one later.
		$day  += ! $date1904 && $day < 60 ? 1 : 0;
		$epoch = new DateTimeImmutable( $date1904 ? self::EPOCH_1904 : self::EPOCH_1900, new DateTimeZone( 'UTC' ) );
		return $epoch->modify( '+' . $day . ' days' )->format( 'Y-m-d' );
	}
}

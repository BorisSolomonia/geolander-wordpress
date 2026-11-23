<?php
/**
 * Standalone checks for GLC_Xlsx, the plugin's own .xlsx writer and reader.
 * No WordPress, no database.
 *
 *  1. What the writer writes, the reader reads back: the same values, the same kinds.
 *  2. A workbook the writer made, opened and saved by Microsoft Excel
 *     (tests/fixtures/xlsx/saved-by-excel.xlsx, two prices changed there by Excel
 *     itself), reads back as what went out plus those two cells. Excel moves every
 *     text into a shared-strings part on save; that is the part this proves.
 *  3. Files made to do harm are refused with a reason, quickly and in little memory:
 *     a path that climbs out of the archive, a part far larger than the limit, XML
 *     nested thousands deep, an entity declaration, a sheet with a million rows.
 *
 * It needs PHP's zip extension, which the plain php:8.3-cli image does not have,
 * so it runs in the site's own image:
 *
 *   docker run --rm -v "<repo>:/src" --entrypoint php geolander-wordpress:local /src/tests/xlsx-roundtrip.php
 *
 *   … /src/tests/xlsx-roundtrip.php make /src/tests/fixtures/xlsx/written-by-site.xlsx
 *       writes the workbook of check 2 as the writer makes it, for Excel to open and save.
 */
define( 'ABSPATH', __DIR__ );
// For building the hostile files below (one is a 40 MB string). What the reader itself needs is measured per file.
ini_set( 'memory_limit', '1024M' );
require __DIR__ . '/../wp-content/plugins/geolander-core/includes/class-glc-xlsx.php';

$failures = 0;
function check( $condition, $label ) {
	if ( ! $condition ) { ++$GLOBALS['failures']; }
	echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}

/** Limits for these checks: generous enough for the real workbooks, small enough to show a refusal. */
const LIMITS = [ 'part_bytes' => 2 * 1024 * 1024, 'rows' => 5000, 'cells' => 140000 ];

/** The workbook of checks 1 and 2: made-up cars, the shapes of the real template. */
function sample_sheets(): array {
	return [
		'Prices' => [
			[ 'car_id', 'plate', 'car', 'season', 'from', 'to', 'label', 'd1_2', 'd3_4', 'd5_7', 'd8_12', 'd13_18', 'd19_30', 'd31p' ],
			[ 901, 'AA-001-AA', 'Test Car One', 1, '07-13', '08-30', 'Jul 13 - Aug 30', 54, 51, 49, 46, 43, 40, 37 ],
			[ 901, 'AA-001-AA', 'Test Car One', 2, '08-31', '07-12', 'Aug 31 - Jul 12', 41.5, 39, 37, 35, 33, 32, 31 ],
			[ 902, '007', 'ტესტი <მანქანა> & "ორი"', 1, '01-01', '12-31', 'Jan 01 - Dec 31', 60, 58, 56, 54, 52, 50, 48.25 ],
		],
		'Fees'   => [
			[ 'car_id', 'plate', 'car', 'tbilisi_city', 'tbilisi_airport', 'kutaisi_airport', 'batumi_airport', 'out_of_hours' ],
			[ 901, 'AA-001-AA', 'Test Car One', 0, null, 95, null, null ],
			[ 902, '007', 'ტესტი <მანქანა> & "ორი"', null, null, null, null, 12.5 ],
		],
		'Meta'   => [
			[ 'car_id', 'rev', 'generated_at', 'user_id' ],
			[ 901, 3, '2026-10-12T12:00:00+04:00', 7 ],
			[ 902, 0, '2026-10-12T12:00:00+04:00', 7 ],
		],
	];
}

/** What the reader should give for a workbook written from $sheets: [ sheet => [ row => [ column => [ kind, value ] ] ] ]. */
function expected( array $sheets ): array {
	$out = [];
	foreach ( $sheets as $name => $rows ) {
		$out[ $name ] = [];
		foreach ( array_values( $rows ) as $r => $row ) {
			foreach ( array_values( $row ) as $c => $value ) {
				if ( null !== $value ) {
					$out[ $name ][ $r + 1 ][ GLC_Xlsx::column_name( $c ) ] = is_string( $value ) ? [ GLC_Xlsx::TEXT, $value ] : [ GLC_Xlsx::NUMBER, (string) $value ];
				}
			}
		}
	}
	return $out;
}

/** The reader's numbers as amounts: Excel writes 41.5 as "41.5" but 54.99 as "54.990000000000002". */
function as_amounts( array $sheets ): array {
	foreach ( $sheets as &$rows ) {
		foreach ( $rows as &$row ) {
			foreach ( $row as &$cell ) {
				if ( GLC_Xlsx::NUMBER === $cell[0] ) {
					$cell[1] = (string) round( (float) $cell[1], 6 );
				}
			}
		}
	}
	return $sheets;
}

if ( 'make' === ( $argv[1] ?? '' ) ) {
	exit( GLC_Xlsx::write( sample_sheets(), (string) $argv[2] ) ? 0 : 1 );
}

$dir = sys_get_temp_dir() . '/glc-xlsx-' . getmypid();
mkdir( $dir );
$file = static fn( string $name ): string => $dir . '/' . $name;

/** A zip with exactly these parts, for the hostile files. */
function archive( string $path, array $parts ): void {
	$zip = new ZipArchive();
	$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	foreach ( $parts as $name => $content ) {
		$zip->addFromString( $name, $content );
	}
	$zip->close();
}
/** A workbook with one sheet whose XML is $sheet, and whatever else is handed in. */
function workbook_with( string $path, string $sheet, array $more = [], string $target = 'worksheets/sheet1.xml' ): void {
	$main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
	$rel  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
	archive( $path, $more + [
		'xl/workbook.xml'            => '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="' . $main . '" xmlns:r="' . $rel . '"><sheets><sheet name="Prices" sheetId="1" r:id="rId1"/></sheets></workbook>',
		'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="' . $rel . '/worksheet" Target="' . $target . '"/></Relationships>',
		'xl/worksheets/sheet1.xml'   => $sheet,
	] );
}
/** Read a file and say how it ended: the refusal's reason, or "read". Also how long it took and how much memory it needed. */
function attempt( string $path, array $limits = LIMITS ): array {
	gc_collect_cycles();
	memory_reset_peak_usage();
	$before = memory_get_usage();
	$start  = microtime( true );
	try {
		$read   = GLC_Xlsx::read( $path, $limits );
		$result = 'read';
	} catch ( GLC_Xlsx_Refused $refused ) {
		$read   = null;
		$result = $refused->reason;
	}
	return [ 'result' => $result, 'read' => $read, 'seconds' => microtime( true ) - $start, 'megabytes' => ( memory_get_peak_usage() - $before ) / 1048576 ];
}
$cost = static fn( array $a ): string => sprintf( '%.2f s, %.1f MB', $a['seconds'], $a['megabytes'] );
$sheet_open  = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
$sheet_close = '</sheetData></worksheet>';

/* ---------------------------------------------------- 1. writer to reader */

$sheets = sample_sheets();
check( GLC_Xlsx::write( $sheets, $file( 'written.xlsx' ) ), 'The writer writes a workbook' );
$read = attempt( $file( 'written.xlsx' ) );
check( 'read' === $read['result'] && array_keys( $read['read']['sheets'] ) === array_keys( $sheets ), 'The reader finds the same sheets, in the same order: ' . implode( ', ', array_keys( $read['read']['sheets'] ?? [] ) ) );
check( $read['read']['sheets'] === expected( $sheets ), 'Every cell comes back with the value and the kind it went out with (text stays text, a number a number, an empty cell empty)' );
check( [ GLC_Xlsx::TEXT, '07-13' ] === ( $read['read']['sheets']['Prices'][2]['E'] ?? null ) && [ GLC_Xlsx::TEXT, '007' ] === ( $read['read']['sheets']['Prices'][4]['B'] ?? null ), 'A season date and a plate of digits are text: "07-13" and "007", not a date and not 7' );
check( [ GLC_Xlsx::TEXT, 'ტესტი <მანქანა> & "ორი"' ] === ( $read['read']['sheets']['Prices'][4]['C'] ?? null ), 'Georgian letters and the characters XML reserves survive' );
check( 'A' === GLC_Xlsx::column_name( 0 ) && 'Z' === GLC_Xlsx::column_name( 25 ) && 'AA' === GLC_Xlsx::column_name( 26 ) && 26 === GLC_Xlsx::column_index( 'AA' ) && 13 === GLC_Xlsx::column_index( 'N' ), 'Column letters count as Excel counts them' );
check( '2026-07-13' === GLC_Xlsx::serial_to_date( 46216 ) && '1900-01-01' === GLC_Xlsx::serial_to_date( 1 ) && '1900-03-01' === GLC_Xlsx::serial_to_date( 61 ) && '2026-07-13' === GLC_Xlsx::serial_to_date( 46216 - 1462, true ) && null === GLC_Xlsx::serial_to_date( 'x' ) && null === GLC_Xlsx::serial_to_date( 0 ), 'A number Excel shows as a date is read as that calendar day, in both of Excel\'s calendars' );

/* -------------------------------------------- 2. a file saved by real Excel */

$fixture = __DIR__ . '/fixtures/xlsx/saved-by-excel.xlsx';
if ( ! is_file( $fixture ) ) {
	check( false, 'The fixture saved by Microsoft Excel exists (tests/fixtures/xlsx/saved-by-excel.xlsx)' );
} else {
	$zip = new ZipArchive();
	$zip->open( $fixture, ZipArchive::RDONLY );
	$has_shared = false !== $zip->locateName( 'xl/sharedStrings.xml' );
	$sheet_one  = (string) $zip->getFromName( 'xl/worksheets/sheet1.xml' );
	$app        = (string) $zip->getFromName( 'docProps/app.xml' );
	$zip->close();
	check( $has_shared && str_contains( $sheet_one, 't="s"' ) && ! str_contains( $sheet_one, 'inlineStr' ), 'The fixture is Excel\'s own writing: its texts are in a shared-strings part and no cell is an inline string' );
	check( str_contains( $app, 'Microsoft Excel' ), 'and it names the program that saved it: ' . ( preg_match( '~<Application>([^<]*)</Application>.*<AppVersion>([^<]*)~s', $app, $m ) ? $m[1] . ' ' . $m[2] : 'not named' ) );
	// The two cells Excel changed before saving: Prices!H2 54 → 61 and Prices!N3 31 → 29.5.
	$want                         = expected( $sheets );
	$want['Prices'][2]['H']       = [ GLC_Xlsx::NUMBER, '61' ];
	$want['Prices'][3]['N']       = [ GLC_Xlsx::NUMBER, '29.5' ];
	$saved                        = attempt( $fixture );
	$got                          = 'read' === $saved['result'] ? as_amounts( $saved['read']['sheets'] ) : [];
	$differs                      = [];
	foreach ( $want as $name => $rows ) {
		foreach ( $rows as $r => $row ) {
			foreach ( $row as $c => $cell ) {
				if ( ( $got[ $name ][ $r ][ $c ] ?? null ) !== $cell ) {
					$differs[] = "{$name}!{$c}{$r}";
				}
			}
		}
	}
	check( 'read' === $saved['result'] && ! $differs && $got == $want, 'Read back, it is the workbook that went out plus the two prices changed in Excel (Prices!H2 = 61, Prices!N3 = 29.5)' . ( $differs ? ': differs at ' . implode( ', ', array_slice( $differs, 0, 8 ) ) : '' ) );
	check( [ GLC_Xlsx::TEXT, '07-13' ] === ( $got['Prices'][2]['E'] ?? null ) && [ GLC_Xlsx::TEXT, '08-30' ] === ( $got['Prices'][2]['F'] ?? null ) && [ GLC_Xlsx::TEXT, '007' ] === ( $got['Prices'][4]['B'] ?? null ), 'Excel left the season dates and the plate as the text they went out as' );
}

/* ----------------------------------------------------- 3. hostile files */

// A path that climbs out of the archive, next to a perfectly good workbook.
$secret = $file( 'secret.txt' );
file_put_contents( $secret, 'OUTSIDE-THE-UPLOAD' );
foreach ( [ '../secret.txt', '../../../../etc/passwd', '/etc/passwd', 'xl/../../secret.txt', 'C:\\Windows\\win.ini', 'xl\\..\\..\\secret.txt' ] as $i => $name ) {
	workbook_with( $file( "climb{$i}.xlsx" ), $sheet_open . '<row r="1"><c r="A1"><v>1</v></c></row>' . $sheet_close, [ $name => 'x' ] );
	$a = attempt( $file( "climb{$i}.xlsx" ) );
	check( 'path' === $a['result'], "A part named {$name} refuses the whole file: {$a['result']}" );
}
// The workbook's own pointer to a sheet climbing out: nothing outside the archive is opened.
foreach ( [ '../../secret.txt', '/../secret.txt', '../../../../../etc/passwd', 'file:///etc/passwd' ] as $i => $target ) {
	workbook_with( $file( "target{$i}.xlsx" ), $sheet_open . $sheet_close, [], $target );
	$a = attempt( $file( "target{$i}.xlsx" ) );
	check( 'no_workbook' === $a['result'], "A sheet said to live at {$target} is not looked for outside the archive: {$a['result']}" );
}

// A part far larger than the limit: refused while it is being counted, not after it is all in memory.
workbook_with( $file( 'big.xlsx' ), $sheet_open . str_repeat( '<row r="1"><c r="A1" t="inlineStr"><is><t>' . str_repeat( 'x', 1000 ) . '</t></is></c></row>', 40000 ) . $sheet_close );
$a = attempt( $file( 'big.xlsx' ) );
check( 'too_big' === $a['result'] && $a['megabytes'] < 8 && $a['seconds'] < 5, sprintf( 'A sheet of 40 MB in a file of %d KB is refused as too big (%s)', filesize( $file( 'big.xlsx' ) ) / 1024, $cost( $a ) ) );

// The same for the shared strings, which a sheet can make the reader open.
$rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
workbook_with( $file( 'bigstrings.xlsx' ), $sheet_open . $sheet_close, [
	'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="' . $rel . '/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="' . $rel . '/sharedStrings" Target="sharedStrings.xml"/></Relationships>',
	'xl/sharedStrings.xml'       => '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . str_repeat( '<si><t>' . str_repeat( 'y', 1000 ) . '</t></si>', 40000 ) . '</sst>',
] );
$a = attempt( $file( 'bigstrings.xlsx' ) );
check( 'too_big' === $a['result'] && $a['megabytes'] < 8, 'Shared strings of 40 MB are refused the same way (' . $cost( $a ) . ')' );

// XML nested far deeper than any workbook is.
workbook_with( $file( 'deep.xlsx' ), $sheet_open . str_repeat( '<a>', 100000 ) . str_repeat( '</a>', 100000 ) . $sheet_close );
$a = attempt( $file( 'deep.xlsx' ) );
check( 'bad_xml' === $a['result'] && $a['seconds'] < 5 && $a['megabytes'] < 16, 'A sheet nested 100,000 elements deep is refused, not walked (' . $cost( $a ) . ')' );

// Entity declarations: a file on the server, an address on the network, and the expansion bomb.
$entities = [
	'a local file'      => '<!DOCTYPE worksheet [<!ENTITY x SYSTEM "file://' . $secret . '">]>',
	'a network address' => '<!DOCTYPE worksheet [<!ENTITY x SYSTEM "http://127.0.0.1:9/x">]>',
	'an outside DTD'    => '<!DOCTYPE worksheet SYSTEM "http://127.0.0.1:9/x.dtd">',
	'a bomb'            => '<!DOCTYPE worksheet [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;"><!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;&b;&b;"><!ENTITY x "&c;&c;&c;&c;&c;&c;&c;&c;&c;&c;">]>',
];
foreach ( $entities as $what => $doctype ) {
	$xml = '<?xml version="1.0" encoding="UTF-8"?>' . $doctype . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>&x;</t></is></c></row>' . $sheet_close;
	workbook_with( $file( 'entity.xlsx' ), $xml );
	$a = attempt( $file( 'entity.xlsx' ) );
	check( 'doctype' === $a['result'], "An entity declaration naming {$what} refuses the file before it is parsed: {$a['result']}" );
}
// The same declaration hidden in another encoding, where a search for "<!DOCTYPE" would not see it.
$hidden = '<?xml version="1.0" encoding="UTF-16"?>' . $entities['a local file'] . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>&x;</t></is></c></row>' . $sheet_close;
foreach ( [ 'UTF-16LE' => "\xFF\xFE", 'UTF-16BE' => "\xFE\xFF" ] as $encoding => $mark ) {
	workbook_with( $file( 'entity16.xlsx' ), $mark . mb_convert_encoding( $hidden, $encoding, 'UTF-8' ) );
	$a = attempt( $file( 'entity16.xlsx' ) );
	check( 'bad_xml' === $a['result'], "The same declaration written in {$encoding} is refused too: {$a['result']}" );
}
workbook_with( $file( 'entity7.xlsx' ), '<?xml version="1.0" encoding="UTF-7"?>+ADwAIQ-DOCTYPE worksheet+AD4-<worksheet/>' );
$a = attempt( $file( 'entity7.xlsx' ) );
check( 'bad_xml' === $a['result'], "And a part that declares any encoding but UTF-8: {$a['result']}" );

// A million rows. First as a real file would arrive (refused by size), then with the size limit lifted, to show the row count stops it on its own.
$million = $sheet_open;
for ( $i = 1; $i <= 1000000; $i++ ) {
	$million .= '<row r="' . $i . '"><c r="A' . $i . '"><v>1</v></c></row>';
}
workbook_with( $file( 'million.xlsx' ), $million . $sheet_close );
unset( $million );
$a = attempt( $file( 'million.xlsx' ) );
check( 'too_big' === $a['result'] && $a['megabytes'] < 8 && $a['seconds'] < 5, sprintf( 'A sheet with a million rows (%d KB as a file) is refused as too big (%s)', filesize( $file( 'million.xlsx' ) ) / 1024, $cost( $a ) ) );
$a = attempt( $file( 'million.xlsx' ), [ 'part_bytes' => 64 * 1024 * 1024 ] + LIMITS );
check( 'too_many_rows' === $a['result'] && $a['seconds'] < 10, 'With the size limit out of the way, the row limit stops it at row 5,001 (' . $cost( $a ) . ')' );
// One row, a million cells: the cell limit.
workbook_with( $file( 'wide.xlsx' ), $sheet_open . '<row r="1">' . str_repeat( '<c><v>1</v></c>', 1000000 ) . '</row>' . $sheet_close );
$a = attempt( $file( 'wide.xlsx' ), [ 'part_bytes' => 64 * 1024 * 1024 ] + LIMITS );
check( 'too_many_cells' === $a['result'], 'A single row of a million cells is stopped by the cell limit (' . $cost( $a ) . ')' );

// Not a workbook at all.
file_put_contents( $file( 'text.xlsx' ), 'car_id,plate,car' );
$a = attempt( $file( 'text.xlsx' ) );
check( 'not_zip' === $a['result'], "A file that is not a zip: {$a['result']}" );
archive( $file( 'empty.xlsx' ), [ 'readme.txt' => 'hello' ] );
$a = attempt( $file( 'empty.xlsx' ) );
check( 'no_workbook' === $a['result'], "A zip that is not a workbook: {$a['result']}" );
workbook_with( $file( 'broken.xlsx' ), $sheet_open . '<row r="1"><c r="A1"><v>1</v></row>' );
$a = attempt( $file( 'broken.xlsx' ) );
check( 'bad_xml' === $a['result'], "A sheet whose XML does not close: {$a['result']}" );

check( 'OUTSIDE-THE-UPLOAD' === file_get_contents( $secret ), 'The file planted beside the uploads was never touched, and none of the refusals above carried its text' );

array_map( 'unlink', glob( $dir . '/*' ) );
rmdir( $dir );
echo "\n" . ( $failures ? "{$failures} FAILED" : 'ALL PASS' ) . "\n";
exit( $failures ? 1 : 0 );

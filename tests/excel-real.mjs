#!/usr/bin/env bun
/**
 * excel-real.mjs — does the price file survive Microsoft Excel?
 *
 * The site writes the file, real Excel on this Windows machine opens it, changes
 * it and saves it, and the site reads it back. A file written and read only by
 * the plugin's own code would not prove this: Excel moves every text into a
 * shared-strings part, rewrites the numbers, and adds a dozen parts of its own.
 *
 *   bun tests/excel-real.mjs [http://localhost:8080]
 *
 * LOCAL ONLY, and Windows with Excel only (driven from WSL through PowerShell
 * and COM, see tests/tools/excel-edit.ps1). It needs the fixture of
 * tests/partner-authority.mjs. It uploads and previews; it applies nothing, so
 * no car is changed. Without Excel every row prints NOT RUN with the error and
 * the script exits 3.
 */
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const base = ( process.argv[2] ?? 'http://localhost:8080' ).replace( /\/+$/, '' );
if ( ! [ 'localhost', '127.0.0.1', '[::1]' ].includes( new URL( base ).hostname ) ) { console.error( `Refusing to run against ${ base }: local copy only.` ); process.exit( 2 ); }
const FIXTURE = new URL( './.local/partners.json', import.meta.url );
if ( ! existsSync( FIXTURE ) ) { console.error( 'No fixture. Run: bun tests/partner-authority.mjs setup' ); process.exit( 2 ); }
const fx = JSON.parse( readFileSync( FIXTURE, 'utf8' ) );
const SCRIPT = fileURLToPath( new URL( './tools/excel-edit.ps1', import.meta.url ) );

async function run( cmd ) {
	const proc = Bun.spawn( cmd, { stdout: 'pipe', stderr: 'pipe' } );
	const [ out, err, code ] = await Promise.all( [ new Response( proc.stdout ).text(), new Response( proc.stderr ).text(), proc.exited ] );
	return { out: out.trim(), err: err.trim(), code };
}
const winPath = async ( path ) => ( await run( [ 'wslpath', '-w', path ] ) ).out;
/** A folder of our own under the Windows temp folder: Excel is given full Windows paths, never a relative one. */
const temp = await run( [ 'powershell.exe', '-NoProfile', '-Command', 'Write-Output $env:TEMP' ] );
if ( temp.code !== 0 || ! temp.out ) { console.log( `NOT RUN  PowerShell is not reachable from here: ${ temp.err || 'no answer' }` ); process.exit( 3 ); }
const winDir = `${ temp.out.split( /\r?\n/ ).pop().trim() }\\claude\\glc-excel-real`;
const dir = ( await run( [ 'wslpath', '-u', winDir ] ) ).out;
mkdirSync( dir, { recursive: true } );
/** Have Excel open one file, make the edits, and save it as another. Returns Excel's own line about itself. */
async function excel( from, to, edits ) {
	const r = await run( [ 'powershell.exe', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', await winPath( SCRIPT ), '-In', `${ winDir }\\${ from }`, '-Out', `${ winDir }\\${ to }`, '-Edits', edits.join( ';' ) ] );
	if ( r.code !== 0 ) throw new Error( ( r.err || r.out ).split( /\r?\n/ ).slice( 0, 3 ).join( ' ' ) );
	return r.out.split( /\r?\n/ ).pop();
}

const cookies = new Map();
async function request( path, { method = 'GET', form, body } = {} ) {
	const headers = {};
	if ( cookies.size ) headers.cookie = [ ...cookies ].map( ( [ k, v ] ) => `${ k }=${ v }` ).join( '; ' );
	if ( form ) { body = new URLSearchParams( form ).toString(); headers['content-type'] = 'application/x-www-form-urlencoded'; }
	const res = await fetch( base + path.replace( base, '' ), { method, headers, body, redirect: 'manual' } );
	for ( const line of res.headers.getSetCookie() ) { const pair = line.split( ';' )[0]; const at = pair.indexOf( '=' ); cookies.set( pair.slice( 0, at ).trim(), pair.slice( at + 1 ).trim() ); }
	const bytes = Buffer.from( await res.arrayBuffer() );
	return { status: res.status, location: res.headers.get( 'location' ) ?? '', bytes, text: bytes.toString( 'utf8' ) };
}
const decode = ( s ) => s.replace( /&quot;/g, '"' ).replace( /&#0?39;/g, "'" ).replace( /&lt;/g, '<' ).replace( /&gt;/g, '>' ).replace( /&amp;/g, '&' );
const XLSX = '/wp-admin/admin.php?page=glc-partner-excel';
await request( '/wp-login.php' );
await request( '/wp-login.php', { method: 'POST', form: { log: fx.accounts.partner_c.login, pwd: fx.accounts.partner_c.password, 'wp-submit': 'Log In', testcookie: '1' } } );
const screen = await request( XLSX );
const nonceOf = ( html, marker ) => html.match( new RegExp( `${ marker }[\\s\\S]*?name="_wpnonce" value="([a-f0-9]+)"` ) )?.[1] ?? '';
const download = await request( '/wp-admin/admin-post.php', { method: 'POST', form: { action: 'glc_xlsx_download', _wpnonce: nonceOf( screen.text, 'class="glc-xlsx-download"' ) } } );
if ( download.status !== 200 || download.bytes.subarray( 0, 2 ).toString() !== 'PK' ) { console.error( `The site gave no file: ${ download.status }` ); process.exit( 1 ); }
writeFileSync( `${ dir }/from-site.xlsx`, download.bytes );
console.log( `the site wrote a file of ${ download.bytes.length } bytes for ${ fx.accounts.partner_c.login }` );

/** Upload a file Excel saved and read the preview the screen answers with. */
async function preview( name ) {
	const body = new FormData();
	body.set( '_wpnonce', nonceOf( ( await request( XLSX ) ).text, 'class="glc-xlsx-upload"' ) );
	body.set( 'glc_file', new Blob( [ readFileSync( `${ dir }/${ name }` ) ] ), name );
	const sent = await request( XLSX, { method: 'POST', body } );
	const page = await request( sent.location );
	const rows = [ ...( page.text.match( /<table class="widefat striped glc-cells"[\s\S]*?<\/table>/ )?.[0] ?? '' ).matchAll( /<th scope="row">([^<]+)<\/th>\s*<td>([^<]*)<\/td>\s*<td>([^<]*)<\/td>\s*<td>([^<]*)<\/td>\s*<td[^>]*>([^<]*)<\/td>/g ) ].map( ( m ) => ( { ref: decode( m[1] ), what: decode( m[3] ), before: decode( m[4] ), after: decode( m[5] ) } ) );
	const stops = [ ...( page.text.match( /<div class="notice notice-error inline glc-stops"[\s\S]*?<\/div>/ )?.[0] ?? '' ).matchAll( /<li>([\s\S]*?)<\/li>/g ) ].map( ( m ) => decode( m[1] ) );
	return { rows, stops, apply: page.text.includes( 'value="glc_xlsx_apply"' ), bytes: readFileSync( `${ dir }/${ name }` ).length };
}
let failed = 0, notRun = 0;
async function row( id, claim, run ) {
	try {
		const { ok, got } = await run();
		failed += ok ? 0 : 1;
		console.log( `${ ok ? 'PASS' : 'FAIL' }  ${ id.padEnd( 22 ) } ${ claim } — got ${ got }` );
	} catch ( error ) {
		notRun++;
		console.log( `NOT RUN  ${ id.padEnd( 19 ) } ${ claim } — Excel could not be driven: ${ error.message }` );
	}
}
const cells = ( p ) => p.rows.map( ( r ) => `${ r.ref } ${ r.before } → ${ r.after }` ).join( '; ' ) || ( p.stops[0] ?? 'nothing listed' );

await row( 'excel.two-prices', 'the site\'s file, opened in Excel, two prices changed, saved, uploaded: the preview shows exactly those two cells', async () => {
	const said = await excel( 'from-site.xlsx', 'two-prices.xlsx', [ 'Prices!H2=77', 'Prices!N12=33.5' ] );
	const p = await preview( 'two-prices.xlsx' );
	return { ok: p.rows.length === 2 && p.rows[0].ref === 'Prices!H2' && p.rows[0].after === '$77' && p.rows[1].ref === 'Prices!N12' && p.rows[1].after === '$33.50' && p.stops.length === 0 && p.apply, got: `${ said }; ${ p.bytes } bytes back from Excel; ${ p.rows.length } cell(s): ${ cells( p ) }` };
} );
await row( 'excel.untouched', 'the same file opened and saved in Excel with nothing changed: nothing differs', async () => {
	await excel( 'from-site.xlsx', 'untouched.xlsx', [] );
	const p = await preview( 'untouched.xlsx' );
	return { ok: p.rows.length === 0 && p.stops.length === 0 && ! p.apply, got: `${ p.rows.length } cell(s), ${ p.stops.length } refusal(s)` };
} );
await row( 'excel.reshaped', 'columns moved and widened, a price typed into a Text cell, a season date re-typed as a date of the same day: still accepted, and only the two changed prices are listed, where they now stand', async () => {
	await excel( 'from-site.xlsx', 'reshaped.xlsx', [ 'Prices!C:width=45', 'Prices!E2:date=2026-07-03', 'Prices!H3:text=58', 'Prices!I2=49.99', 'Prices!N>H' ] );
	const p = await preview( 'reshaped.xlsx' );
	// Column N was cut and put before H, so what was H is now I and what was I is now J.
	return { ok: p.stops.length === 0 && p.rows.length === 2 && p.rows[0].ref === 'Prices!J2' && p.rows[0].after === '$49.99' && p.rows[1].ref === 'Prices!I3' && p.rows[1].after === '$58', got: `${ p.rows.length } cell(s): ${ cells( p ) }` };
} );
await row( 'excel.season-date', 'a season date changed in Excel (typed as a date, as Excel stores it): the whole file is refused, naming the cell and sending the person to the panel', async () => {
	await excel( 'from-site.xlsx', 'date-changed.xlsx', [ 'Prices!E2:date=2026-07-05', 'Prices!H2=77' ] );
	const p = await preview( 'date-changed.xlsx' );
	return { ok: p.stops.length === 1 && p.stops[0].startsWith( 'Prices!E2:' ) && p.stops[0].includes( '"07-05"' ) && p.stops[0].includes( 'in the panel' ) && p.rows.length === 0 && ! p.apply, got: `"${ p.stops[0] ?? 'not refused' }"` };
} );
await row( 'excel.season-row', 'a season row deleted in Excel: refused, naming the car and the season', async () => {
	await excel( 'from-site.xlsx', 'row-deleted.xlsx', [ 'Prices!A5=', 'Prices!B5=', 'Prices!C5=', 'Prices!D5=', 'Prices!E5=', 'Prices!F5=', 'Prices!G5=', 'Prices!H5=', 'Prices!I5=', 'Prices!J5=', 'Prices!K5=', 'Prices!L5=', 'Prices!M5=', 'Prices!N5=' ] );
	const p = await preview( 'row-deleted.xlsx' );
	return { ok: p.stops.length === 1 && /has no row for its season 4/.test( p.stops[0] ) && ! p.apply, got: `"${ p.stops[0] ?? 'not refused' }"` };
} );

const left = await run( [ 'powershell.exe', '-NoProfile', '-Command', '(Get-Process excel -ErrorAction SilentlyContinue | Measure-Object).Count' ] );
console.log( `\nExcel processes running now: ${ left.out } (this script starts its own and quits it; any others were open before)` );
console.log( notRun ? `${ notRun } NOT RUN, ${ failed } failed` : ( failed ? `${ failed } FAILED` : 'ALL PASS' ) );
process.exit( notRun ? 3 : ( failed ? 1 : 0 ) );

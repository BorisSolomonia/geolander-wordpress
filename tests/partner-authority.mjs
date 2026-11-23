#!/usr/bin/env bun
/**
 * partner-authority.mjs — what a car partner can and cannot reach, asked over HTTP.
 *
 * One row per entry point of the design's authority table (§1.2). A row logs in
 * as a local test partner (or the local test owner, as a control) and sends the
 * request that would prove the rule false. Each row prints PASS or FAIL with the
 * status it got. Two checks, by different paths: the status of every request,
 * and a fingerprint of the database before and after — a 403 says the request
 * was refused, the fingerprint says nothing moved.
 *
 *   bun tests/partner-authority.mjs setup                     local test accounts + fixture (wp-cli through Docker)
 *   bun tests/partner-authority.mjs [http://localhost:8080]   run every row; exit 1 if any fails
 *
 * LOCAL ONLY. The rows send real write attempts, so the script refuses any host
 * that is not this machine. Run `setup` again after the local database is reset
 * (the reset keeps the accounts but drops the car assignment). The last section
 * saves the settings form as the owner, which stores every setting with its
 * current value: reset the local database when you are done.
 *
 * Adding rows: `row( 'section.id', 'what must hold', async () => ( { ok, got } ) )`
 * in the section it belongs to. `partner`, `partnerKa`, `partnerB`, `owner` and
 * `anon` are signed-in sessions; `fx` is the fixture (accounts, cars, ids).
 * `partner` has the car `mine`, `partnerB` (Georgian) has `theirs`, `other` is
 * Geolander's own and `legacy` carries a WhatsApp number of its own. `partnerC`
 * has three cars (`fx.fleet_c`): the rows about changing several cars at once.
 *
 * Order matters: every request that must be refused comes before the row
 * `database.unchanged`; the saves that must succeed come after it.
 *
 * Photos: every picture the rows upload is made by the fixture script (bands of
 * colour, never a real photo), and every attachment a test account uploaded is
 * deleted with its files in the last row, which also counts the files in the
 * uploads folder against the count taken at setup.
 */
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const DOCKER    = process.env.GLC_DOCKER ?? 'docker.exe';
const CONTAINER = process.env.GLC_WP_CONTAINER ?? 'geolander_wordpress-wordpress-1';
const LOCAL     = new URL( './.local/', import.meta.url );
const FIXTURE   = new URL( 'partners.json', LOCAL );
const SETUP     = new URL( './partner-authority.setup.php', import.meta.url );
const LOCAL_HOSTS = [ 'localhost', '127.0.0.1', '[::1]' ];
/** A 1×1 PNG: the smallest real image WordPress accepts as an upload. */
const PIXEL = Buffer.from( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64' );

/** Run the fixture script through wp-cli inside the local container; returns its JSON. */
async function wp( ...args ) {
	const proc = Bun.spawn( [ DOCKER, 'exec', '-i', CONTAINER, 'wp', 'eval-file', '-', ...args, '--allow-root' ], { stdin: Bun.file( SETUP ), stdout: 'pipe', stderr: 'pipe' } );
	const [ out, err, code ] = await Promise.all( [ new Response( proc.stdout ).text(), new Response( proc.stderr ).text(), proc.exited ] );
	if ( code !== 0 ) throw new Error( `wp-cli failed (${ code }): ${ err.trim() || out.trim().slice( 0, 300 ) }` );
	try { return JSON.parse( out ); } catch { throw new Error( `wp-cli did not print JSON: ${ out.trim().slice( 0, 300 ) }` ); }
}

if ( process.argv[2] === 'setup' ) {
	const fixture = await wp();
	mkdirSync( LOCAL, { recursive: true } );
	writeFileSync( FIXTURE, JSON.stringify( fixture, null, '\t' ) );
	// Never the passwords: only who exists and which cars the rows will use.
	console.log( `fixture written to ${ fileURLToPath( FIXTURE ) }` );
	console.log( `accounts: ${ Object.values( fixture.accounts ).map( ( a ) => `${ a.login } (#${ a.id })` ).join( ', ' ) }` );
	console.log( `assigned car: #${ fixture.cars.mine.id } ${ fixture.cars.mine.title }; unassigned car: #${ fixture.cars.other.id } ${ fixture.cars.other.title }` );
	process.exit( 0 );
}

const base = ( process.argv[2] ?? 'http://localhost:8080' ).replace( /\/+$/, '' );
if ( ! LOCAL_HOSTS.includes( new URL( base ).hostname ) ) {
	console.error( `Refusing to run against ${ base }: this test sends write attempts and is for the local copy only.` );
	process.exit( 2 );
}
if ( ! existsSync( FIXTURE ) ) {
	console.error( 'No fixture. Run: bun tests/partner-authority.mjs setup' );
	process.exit( 2 );
}
const fx = JSON.parse( readFileSync( FIXTURE, 'utf8' ) );

/** A browser-like session: keeps cookies, never follows a redirect on its own. */
class Session {
	constructor( name ) { this.name = name; this.cookies = new Map(); this.nonce = ''; }
	async request( path, { method = 'GET', headers = {}, form, json, body } = {} ) {
		const h = { ...headers };
		if ( this.cookies.size ) h.cookie = [ ...this.cookies ].map( ( [ k, v ] ) => `${ k }=${ v }` ).join( '; ' );
		if ( form ) { body = new URLSearchParams( form ).toString(); h['content-type'] = 'application/x-www-form-urlencoded'; }
		if ( json !== undefined ) { body = JSON.stringify( json ); h['content-type'] = 'application/json'; }
		const res = await fetch( base + path, { method, headers: h, body, redirect: 'manual' } );
		for ( const line of res.headers.getSetCookie() ) {
			const pair = line.split( ';' )[0];
			const at   = pair.indexOf( '=' );
			const [ key, value ] = [ pair.slice( 0, at ).trim(), pair.slice( at + 1 ).trim() ];
			if ( value === '' || value === 'deleted' || /max-age=0|expires=thu, 01[ -]jan[ -]1970/i.test( line ) ) this.cookies.delete( key ); else this.cookies.set( key, value );
		}
		const text = await res.text();
		let data; try { data = JSON.parse( text ); } catch { data = undefined; }
		return { status: res.status, location: res.headers.get( 'location' ) ?? '', text, data };
	}
	async login( account ) {
		await this.request( '/wp-login.php' );
		return this.request( '/wp-login.php', { method: 'POST', form: { log: account.login, pwd: account.password, 'wp-submit': 'Log In', testcookie: '1' } } );
	}
	/** A REST request the way a signed-in browser makes it: cookie plus the wp_rest nonce. */
	rest( path, options = {} ) {
		return this.request( '/wp-json' + path, { ...options, headers: { ...( options.headers ?? {} ), ...( this.nonce ? { 'x-wp-nonce': this.nonce } : {} ) } } );
	}
}

const results = [];
/**
 * One line of the authority table. `run` returns { ok, got }; a thrown error is a FAIL.
 * `open` marks a rule the design states but no slice has built yet: while it does not
 * hold the row prints OPEN with the reason and does not fail the run; once it holds it
 * prints PASS, and the marker should then be removed.
 */
async function row( id, claim, run, open = '' ) {
	let ok = false, got;
	try { ( { ok, got } = await run() ); } catch ( error ) { got = `error: ${ error.message }`; }
	const state = ok ? 'PASS' : ( open ? 'OPEN' : 'FAIL' );
	results.push( { id, state } );
	console.log( `${ state }  ${ id.padEnd( 30 ) } ${ claim } — got ${ got }${ state === 'OPEN' ? ` [${ open }]` : '' }` );
}
const code   = ( r ) => `${ r.status }${ r.data?.code ? ' ' + r.data.code : '' }`;
/** The main column of an admin page, without the menu, the toolbar and the footer. */
const wrap   = ( html ) => html.match( /<div class="wrap[ "][\s\S]*?<div id="wpfooter"/ )?.[0] ?? html.match( /<div class="wrap[ "][\s\S]*/ )?.[0] ?? '';
const denied = ( r ) => r.status === 403;
const decode = ( s ) => s.replace( /&quot;/g, '"' ).replace( /&#0?39;/g, "'" ).replace( /&lt;/g, '<' ).replace( /&gt;/g, '>' ).replace( /&#0?38;/g, '&' ).replace( /&amp;/g, '&' );
/** The fields of the Settings → Geolander form, as a browser would post them back. */
function settingsForm( html ) {
	const form   = html.match( /<form method="post" action="options\.php">[\s\S]*?<\/form>/ )?.[0] ?? '';
	const fields = {};
	// WordPress prints its own hidden fields with single quotes and the plugin's inputs with double quotes.
	const attr = ( tag, name ) => tag.match( new RegExp( `\\b${ name }=(["'])(.*?)\\1`, 's' ) )?.[2];
	for ( const m of form.matchAll( /<input\b[^>]*>/g ) ) {
		const name = attr( m[0], 'name' );
		if ( name && attr( m[0], 'type' ) !== 'submit' ) fields[ decode( name ) ] = decode( attr( m[0], 'value' ) ?? '' );
	}
	for ( const m of form.matchAll( /<textarea\b[^>]*\bname="([^"]*)"[^>]*>([\s\S]*?)<\/textarea>/g ) ) fields[ decode( m[1] ) ] = decode( m[2] );
	for ( const m of form.matchAll( /<select\b[^>]*\bname="([^"]*)"[^>]*>([\s\S]*?)<\/select>/g ) ) fields[ decode( m[1] ) ] = decode( m[2].match( /<option value="([^"]*)"[^>]*\bselected/ )?.[1] ?? '' );
	return fields;
}
/** A few core screens refuse with wp_die()'s default status, 500, and the words "not allowed". */
const refusedByCore = ( r, form ) => denied( r ) || ( r.status === 500 && /not allowed to/i.test( r.text ) && ! r.text.includes( form ) );

const partner = new Session( 'partner' ), partnerKa = new Session( 'partner_ka' ), partnerB = new Session( 'partner_b' ), partnerC = new Session( 'partner_c' ), owner = new Session( 'owner' ), anon = new Session( 'anonymous' );
const { mine, other, theirs, legacy } = fx.cars;
const en = fx.catalogue.en, ka = fx.catalogue.ka;
const BANDS = [ 'd1_2', 'd3_4', 'd5_7', 'd8_12', 'd13_18', 'd19_30', 'd31p' ];
const carUrl = ( id, more = '' ) => `/wp-admin/admin.php?page=glc-partner-car&car=${ id }${ more }`;
const PROFILE = '/wp-admin/admin.php?page=glc-partner-profile';
const PARTNERS = '/wp-admin/edit.php?post_type=car&page=glc-partners';
const ADMIN_POST = '/wp-admin/admin-post.php';
/** What the database holds, read with SQL by the fixture script. */
const inspect = () => wp( 'inspect' );
/** The form on a page that posts one admin-post action: its fields as a browser would send them. */
function formFor( html, action ) {
	const attr = ( tag, name ) => tag.match( new RegExp( `\\b${ name }=(["'])(.*?)\\1`, 's' ) )?.[2];
	for ( const m of html.matchAll( /<form\b[\s\S]*?<\/form>/g ) ) {
		const fields = {};
		for ( const i of m[0].replace( /<template[\s\S]*?<\/template>/g, '' ).matchAll( /<input\b[^>]*>/g ) ) {
			const name = attr( i[0], 'name' );
			if ( name && ! [ 'submit', 'button' ].includes( attr( i[0], 'type' ) ) ) fields[ decode( name ) ] = decode( attr( i[0], 'value' ) ?? '' );
		}
		for ( const s of m[0].matchAll( /<select\b[^>]*\bname="([^"]*)"[^>]*>([\s\S]*?)<\/select>/g ) ) fields[ decode( s[1] ) ] = decode( s[2].match( /<option value="([^"]*)"[^>]*\bselected/ )?.[1] ?? s[2].match( /<option value="([^"]*)"/ )?.[1] ?? '' );
		if ( fields.action !== action ) continue;
		return { fields, html: m[0], previewNonce: attr( m[0].match( /<form\b[^>]*>/ )[0], 'data-preview-nonce' ) ?? '' };
	}
	return null;
}
/** The fields of a piece of page as a browser would send them: only the ticked radios and tick boxes, the chosen option of each list. */
function fieldsOf( html ) {
	const attr = ( tag, name ) => tag.match( new RegExp( `\\b${ name }=(["'])(.*?)\\1`, 's' ) )?.[2];
	const fields = {};
	const live = html.replace( /<template[\s\S]*?<\/template>/g, '' );
	for ( const i of live.matchAll( /<input\b[^>]*>/g ) ) {
		const name = attr( i[0], 'name' ), type = attr( i[0], 'type' ) ?? 'text';
		if ( ! name || [ 'submit', 'button', 'file' ].includes( type ) || ( [ 'radio', 'checkbox' ].includes( type ) && ! /\bchecked\b/.test( i[0] ) ) ) continue;
		fields[ decode( name ) ] = decode( attr( i[0], 'value' ) ?? '' );
	}
	for ( const s of live.matchAll( /<select\b[^>]*\bname="([^"]*)"[^>]*>([\s\S]*?)<\/select>/g ) ) fields[ decode( s[1] ) ] = decode( s[2].match( /<option\b[^>]*\bselected[^>]*\bvalue="([^"]*)"|<option value="([^"]*)"[^>]*\bselected/ )?.slice( 1 ).find( ( v ) => v !== undefined ) ?? s[2].match( /<option value="([^"]*)"/ )?.[1] ?? '' );
	for ( const t of live.matchAll( /<textarea\b[^>]*\bname="([^"]*)"[^>]*>([\s\S]*?)<\/textarea>/g ) ) fields[ decode( t[1] ) ] = decode( t[2] );
	return fields;
}
/** formFor() for a form with radios and tick boxes. */
function formTicked( html, action ) {
	const form = formFor( html, action );
	return form ? { ...form, fields: fieldsOf( form.html ) } : null;
}
/** The boxes under the block editor as that editor posts them: every field of its four forms, to the address the page names. */
function metaBoxes( html ) {
	const url = decode( html.match( /_wpMetaBoxUrl = "([^"]+)"/ )?.[1] ?? '' ).replace( /\\\//g, '/' ).replace( base, '' );
	const forms = [ ...html.matchAll( /<form class="metabox-(?:base-form|location-\w+)"[\s\S]*?<\/form>/g ) ].map( ( m ) => m[0] ).join( '' );
	const editor = JSON.parse( html.match( /var glcCarEditor = (\{.*?\});/ )?.[1] ?? '{}' );
	return { url, fields: fieldsOf( forms ), status: String( editor.status ?? '' ).replace( base, '' ), notice: decode( ( html.match( /<div class="notice notice-error inline glc-store-notice"[^>]*>([\s\S]*?)<\/div>/ )?.[1] ?? '' ).replace( /<[^>]+>/g, ' ' ).replace( /\s+/g, ' ' ).trim() ) };
}
/** What WordPress's own picker on a car's screen sends with an upload. */
const pickerParams = ( html ) => { const m = html.match( /"multipart_params":(\{[^}]*\})/ ); return m ? JSON.parse( m[1] ) : null; };
/** A picture made by the fixture script. */
const image = async ( width, height, kind = 'jpeg' ) => Buffer.from( ( await wp( 'image', String( width ), String( height ), kind ) ).file, 'base64' );
/** What the database and the disk hold for these attachments. */
const photosOf = ( ...ids ) => wp( 'photos', ...ids.map( String ) );
const photoIds = [];
/** Upload the way the picker does. */
async function pick( session, params, bytes, name, type, extra = {} ) {
	const body = new FormData();
	for ( const [ key, value ] of Object.entries( { ...params, ...extra } ) ) body.set( key, String( value ) );
	body.set( 'async-upload', new Blob( [ bytes ], { type } ), name );
	const r = await session.request( '/wp-admin/async-upload.php', { method: 'POST', body } );
	const id = Number( r.data?.data?.id ?? 0 );
	if ( id ) photoIds.push( id );
	return { r, id, ok: r.data?.success === true, message: decode( String( r.data?.data?.message ?? '' ) ) };
}
/** A season table in the one field the season form posts it in. */
const packTable = ( seasons ) => seasons.map( ( s ) => [ s.from, s.to, ...BANDS.map( ( b ) => s.rates[ b ] ) ].join( '|' ) ).join( '\n' );
/** The words a person reads on a page: no tags, no scripts, no attributes. */
const visible = ( html ) => decode( wrap( html ).replace( /<(script|style|template)\b[\s\S]*?<\/\1>/g, ' ' ).replace( /<[^>]+>/g, ' ' ).replace( /\s+/g, ' ' ) );
/** Catalogue keys printed where their text should be. */
const rawKeys = ( html ) => { const text = visible( html ); return Object.keys( en ).filter( ( key ) => key.includes( '_' ) && new RegExp( `(?<![A-Za-z0-9_])${ key }(?![A-Za-z0-9_])` ).test( text ) ); };
/** The notices a screen shows after a form was sent. */
const notices = ( html ) => [ ...html.matchAll( /<div class="notice inline[^"]*glc-flash"[^>]*>([\s\S]*?)<\/div>/g ) ].map( ( m ) => decode( m[1].replace( /<[^>]+>/g, ' ' ).replace( /\s+/g, ' ' ).trim() ) );
/** Send a form to admin-post.php and, when it answers with a redirect, load the page it points to. */
async function send( session, fields ) {
	const sent = await session.request( ADMIN_POST, { method: 'POST', form: fields } );
	const page = sent.location ? await session.request( sent.location.replace( base, '' ) ) : null;
	return { sent, page };
}
/** The one refusal: 403, the panel's sentence, and not a word about the cars the person does not have. */
const refusedBlind = ( r ) => r.status === 403 && ( r.text.includes( en.panel_refused ) || r.text.includes( ka.panel_refused ) ) && ! [ other, theirs ].some( ( car ) => r.text.includes( car.plate ) || r.text.includes( car.title ) );
/**
 * Does this text name the owner, or an account other than the reader's own (by display name or by login)?
 * A partner must read a role word in their place. "Test Partner" is the start of "Test Partner B", so a
 * name only counts where no longer account name continues it.
 */
const namesSomeone = ( text, reader ) => Object.entries( fx.accounts ).filter( ( [ who ] ) => who !== reader ).some( ( [ , a ] ) => {
	const longer = Object.values( fx.accounts ).map( ( b ) => b.name ).filter( ( n ) => n !== a.name && n.startsWith( a.name ) ).map( ( n ) => n.slice( a.name.length ) );
	return new RegExp( `${ a.name }${ longer.length ? `(?!${ longer.join( '|' ) })` : '' }|${ a.login }(?![a-z_])` ).test( text );
} );
const before = await wp( 'facts' );
const uploaded = [];

/* --------------------------------------------------------- sign-in and landing */

await row( 'login.partner', 'a partner signs in and lands on the partner panel', async () => {
	const r = await partner.login( fx.accounts.partner );
	return { ok: r.status === 302 && r.location.endsWith( '/wp-admin/admin.php?page=glc-partner' ), got: `${ r.status } → ${ r.location }` };
} );
await row( 'login.partner-ka', 'the second partner (Georgian, no cars) lands there too', async () => {
	const r = await partnerKa.login( fx.accounts.partner_ka );
	return { ok: r.status === 302 && r.location.endsWith( '/wp-admin/admin.php?page=glc-partner' ), got: `${ r.status } → ${ r.location }` };
} );
await row( 'login.partner-b', 'the third partner (Georgian, one car) lands there too', async () => {
	const r = await partnerB.login( fx.accounts.partner_b );
	return { ok: r.status === 302 && r.location.endsWith( '/wp-admin/admin.php?page=glc-partner' ), got: `${ r.status } → ${ r.location }` };
} );
await row( 'login.owner', 'the owner signs in and is not sent to the partner panel', async () => {
	const r = await owner.login( fx.accounts.owner );
	return { ok: r.status === 302 && ! r.location.includes( 'glc-partner' ), got: `${ r.status } → ${ r.location }` };
} );
await row( 'login.deep-link', 'a partner who followed a link to a panel screen is taken to it after signing in', async () => {
	const s = new Session( 'deep' );
	await s.request( '/wp-login.php' );
	const target = `${ base }/wp-admin/admin.php?page=glc-partner&car=${ mine.id }`;
	const r = await s.request( '/wp-login.php', { method: 'POST', form: { log: fx.accounts.partner.login, pwd: fx.accounts.partner.password, 'wp-submit': 'Log In', testcookie: '1', redirect_to: target } } );
	return { ok: r.status === 302 && r.location === target, got: `${ r.status } → ${ r.location }` };
} );
await row( 'panel.anonymous', 'nobody signed in: the panel sends to the login page', async () => {
	const r = await anon.request( '/wp-admin/admin.php?page=glc-partner' );
	return { ok: r.status === 302 && r.location.includes( '/wp-login.php' ), got: `${ r.status } → ${ r.location.split( '?' )[0] }` };
} );
await row( 'panel.own-car', 'the panel lists the assigned car: title, plate, year', async () => {
	const r = await partner.request( '/wp-admin/admin.php?page=glc-partner' );
	const main = wrap( r.text );
	return { ok: r.status === 200 && main.includes( fx.strings.cars_title_en ) && main.includes( mine.title ) && main.includes( mine.plate ) && main.includes( `>${ mine.year }<` ), got: `${ r.status }, heading "${ main.match( /<h1>([^<]*)/ )?.[1] ?? '' }", rows ${ ( main.match( /<tr>/g ) ?? [] ).length - 1 }` };
} );
await row( 'panel.only-own', 'and no car that is not assigned to them', async () => {
	const r = await partner.request( '/wp-admin/admin.php?page=glc-partner' );
	return { ok: r.status === 200 && ! r.text.includes( other.plate ) && ! wrap( r.text ).includes( other.title ), got: `${ r.status }, unassigned plate ${ r.text.includes( other.plate ) ? 'PRESENT' : 'absent' }` };
} );
await row( 'panel.foreign-id', 'an unassigned car\'s id in the address shows nothing of that car', async () => {
	const r = await partner.request( `/wp-admin/admin.php?page=glc-partner&car=${ other.id }&post=${ other.id }&car_id=${ other.id }` );
	return { ok: r.status === 200 && ! r.text.includes( other.plate ) && ! wrap( r.text ).includes( other.title ) && wrap( r.text ).includes( mine.plate ), got: `${ r.status }, unassigned plate ${ r.text.includes( other.plate ) ? 'PRESENT' : 'absent' }` };
} );
await row( 'panel.read-only', 'the list itself has nothing to edit: each car leads to its own screen', async () => {
	const r = await partner.request( '/wp-admin/admin.php?page=glc-partner' );
	const controls = wrap( r.text ).match( /<(form|input|select|textarea|button)\b/g ) ?? [];
	const links = [ ...wrap( r.text ).matchAll( /href="([^"]*page=glc-partner-car[^"]*)"/g ) ].map( ( m ) => decode( m[1] ) );
	return { ok: r.status === 200 && controls.length === 0 && links.length > 0 && links.every( ( l ) => l.includes( `car=${ mine.id }` ) ), got: `${ r.status }, ${ controls.length } form controls, ${ links.length } link(s) to car #${ mine.id }` };
} );
await row( 'panel.georgian-empty', 'a Georgian partner with no cars reads the empty state in Georgian', async () => {
	const r = await partnerKa.request( '/wp-admin/admin.php?page=glc-partner' );
	const main = wrap( r.text );
	return { ok: r.status === 200 && main.includes( 'lang="ka"' ) && main.includes( fx.strings.cars_title_ka ) && main.includes( fx.strings.cars_empty_ka ) && ! r.text.includes( mine.plate ) && ! r.text.includes( other.plate ) && ! /cars_|col_/.test( main ), got: `${ r.status }, heading "${ main.match( /<h1>([^<]*)/ )?.[1] ?? '' }", plates ${ r.text.includes( mine.plate ) ? 'PRESENT' : 'absent' }` };
} );
await row( 'panel.owner', 'the owner sees every car on the same screen', async () => {
	const r = await owner.request( '/wp-admin/admin.php?page=glc-partner' );
	const main = wrap( r.text );
	return { ok: r.status === 200 && main.includes( fx.strings.cars_title_all ) && main.includes( mine.plate ) && main.includes( other.plate ), got: `${ r.status }, rows ${ ( main.match( /<tr>/g ) ?? [] ).length - 1 }` };
} );
await row( 'panel.not-built-yet', 'a partner is refused the screen of a car that is not theirs, the Partners page, and a screen that does not exist', async () => {
	const a = await partner.request( carUrl( other.id ) );
	const b = await partner.request( '/wp-admin/admin.php?page=glc-partners' );
	const c = await partner.request( PARTNERS );
	const d = await partner.request( '/wp-admin/admin.php?page=glc-partner-photos' );
	return { ok: [ a, b, c, d ].every( denied ), got: [ a, b, c, d ].map( ( r ) => r.status ).join( ', ' ) };
} );

/* ------------------------------------------------ the WordPress car editor */

for ( const [ label, car ] of [ [ 'assigned', mine ], [ 'unassigned', other ] ] ) {
	await row( `editor.car-${ label }`, `the WordPress edit screen of an ${ label } car is closed`, async () => {
		const r = await partner.request( `/wp-admin/post.php?post=${ car.id }&action=edit` );
		return { ok: denied( r ) && ! r.text.includes( 'glc_car_nonce' ), got: `${ r.status }` };
	} );
}
for ( const [ id, path, what ] of [
	[ 'editor.car-list', '/wp-admin/edit.php?post_type=car', 'the Cars list' ],
	[ 'editor.car-new', '/wp-admin/post-new.php?post_type=car', 'Add New Car' ],
	[ 'editor.car-bulk', `/wp-admin/edit.php?post_type=car&bulk_edit=Update&post%5B%5D=${ mine.id }&_status=draft`, 'bulk edit of cars' ],
	[ 'editor.car-trash', `/wp-admin/post.php?post=${ mine.id }&action=trash`, 'moving a car to the bin' ],
] ) {
	await row( id, `${ what } is closed`, async () => { const r = await partner.request( path ); return { ok: denied( r ), got: `${ r.status }` }; } );
}
await row( 'editor.quick-edit', 'quick edit of a car is refused', async () => {
	const r = await partner.request( '/wp-admin/admin-ajax.php', { method: 'POST', form: { action: 'inline-save', post_ID: mine.id, post_type: 'car', post_title: 'x', _status: 'draft' } } );
	return { ok: r.status === 403 || r.text.trim() === '-1', got: `${ r.status } "${ r.text.trim().slice( 0, 20 ) }"` };
} );

/* -------------------------------------------------------------- REST: cars */

await row( 'rest.nonce', 'the partner\'s browser can get a REST nonce (so the refusals below are not just "no nonce")', async () => {
	const r = await partner.request( '/wp-admin/admin-ajax.php?action=rest-nonce' );
	partner.nonce = /^[a-f0-9]{10}$/.test( r.text.trim() ) ? r.text.trim() : '';
	const k = await partnerKa.request( '/wp-admin/admin-ajax.php?action=rest-nonce' );
	partnerKa.nonce = /^[a-f0-9]{10}$/.test( k.text.trim() ) ? k.text.trim() : '';
	const o = await owner.request( '/wp-admin/admin-ajax.php?action=rest-nonce' );
	owner.nonce = /^[a-f0-9]{10}$/.test( o.text.trim() ) ? o.text.trim() : '';
	return { ok: partner.nonce !== '' && owner.nonce !== '', got: `${ r.status }, nonce ${ partner.nonce ? 'received' : 'MISSING' }` };
} );
await row( 'rest.identity', 'REST sees the request as the partner (a refusal is then a 403, not a 401)', async () => {
	const r = await partner.rest( '/wp/v2/users/me?context=edit' );
	return { ok: r.status === 200 && r.data?.id === fx.accounts.partner.id, got: `${ r.status }, user #${ r.data?.id ?? '?' }` };
} );
for ( const [ id, what, send ] of [
	[ 'rest.car-title', 'changing an assigned car\'s title', () => partner.rest( `/wp/v2/car/${ mine.id }`, { method: 'POST', json: { title: 'x' } } ) ],
	[ 'rest.car-content', 'changing its text, slug or status', () => partner.rest( `/wp/v2/car/${ mine.id }`, { method: 'POST', json: { content: 'x', slug: 'x', status: 'draft', excerpt: 'x' } } ) ],
	[ 'rest.car-pricing', 'writing its price table as meta', () => partner.rest( `/wp/v2/car/${ mine.id }`, { method: 'POST', json: { meta: { glc_pricing: [] } } } ) ],
	[ 'rest.car-meta', 'writing its plate, year or headline price as meta', () => partner.rest( `/wp/v2/car/${ mine.id }`, { method: 'POST', json: { meta: { glc_registration: 'XX-000-XX', glc_year: 1999, glc_price_from: 1 } } } ) ],
	[ 'rest.car-self-assign', 'assigning an unassigned car to oneself', () => partner.rest( `/wp/v2/car/${ other.id }`, { method: 'POST', json: { meta: { glc_partner_id: fx.accounts.partner.id } } } ) ],
	[ 'rest.car-create', 'creating a car', () => partner.rest( '/wp/v2/car', { method: 'POST', json: { title: 'x', status: 'publish' } } ) ],
	[ 'rest.car-delete', 'deleting a car', () => partner.rest( `/wp/v2/car/${ other.id }`, { method: 'DELETE' } ) ],
	[ 'rest.car-delete-own', 'deleting the assigned car', () => partner.rest( `/wp/v2/car/${ mine.id }?force=true`, { method: 'DELETE' } ) ],
	[ 'rest.car-autosave', 'autosaving a car', () => partner.rest( `/wp/v2/car/${ mine.id }/autosaves`, { method: 'POST', json: { title: 'x' } } ) ],
	[ 'rest.car-edit-context', 'reading a car in edit context', () => partner.rest( `/wp/v2/car/${ mine.id }?context=edit` ) ],
] ) {
	await row( id, `REST: ${ what } is refused`, async () => { const r = await send(); return { ok: denied( r ), got: code( r ) }; } );
}
const SECRET_META = [ 'glc_partner_id', 'glc_rev', 'glc_delivery_fees', 'glc_out_of_hours_fee', 'glc_pricing_previous' ];
for ( const [ who, session ] of [ [ 'anonymous', anon ], [ 'partner', partner ] ] ) {
	await row( `rest.car-read-${ who }`, `REST: a car read by ${ who === 'anonymous' ? 'anyone' : 'the partner' } shows no assignment, revision or fee override`, async () => {
		const r = await session.rest( `/wp/v2/car/${ mine.id }` );
		const shown = SECRET_META.filter( ( key ) => key in ( r.data?.meta ?? {} ) || r.text.includes( `"${ key }"` ) );
		return { ok: r.status === 200 && typeof r.data?.meta === 'object' && shown.length === 0 && fx.facts[ `car_${ mine.id }` ].rev !== '' && fx.facts[ `car_${ mine.id }` ].partner !== '', got: `${ r.status }, exposed: ${ shown.join( ', ' ) || 'none' } (the car does carry them: revision ${ fx.facts[ `car_${ mine.id }` ].rev }, partner #${ fx.facts[ `car_${ mine.id }` ].partner })` };
	} );
}
await row( 'rest.owner-read', 'control: the same edit-context read is open to the owner', async () => {
	const r = await owner.rest( `/wp/v2/car/${ mine.id }?context=edit` );
	const shown = SECRET_META.filter( ( key ) => key in ( r.data?.meta ?? {} ) );
	return { ok: r.status === 200 && shown.length === 0, got: `${ code( r ) }, store meta exposed: ${ shown.join( ', ' ) || 'none' }` };
} );

/* -------------------------------------------------------- settings and users */

for ( const [ id, path, what ] of [
	[ 'settings.screen', '/wp-admin/options-general.php?page=geolander', 'Settings → Geolander' ],
	[ 'settings.general', '/wp-admin/options-general.php', 'WordPress settings' ],
	[ 'users.list', '/wp-admin/users.php', 'the Users list' ],
	[ 'users.new', '/wp-admin/user-new.php', 'Add New User' ],
	[ 'plugins.list', '/wp-admin/plugins.php', 'Plugins' ],
	[ 'themes.editor', '/wp-admin/site-editor.php', 'the site editor' ],
	[ 'tools.export', '/wp-admin/export.php', 'Export' ],
] ) {
	await row( id, `${ what } is closed`, async () => { const r = await partner.request( path ); return { ok: denied( r ), got: `${ r.status }` }; } );
}
await row( 'settings.save', 'posting the settings form is refused', async () => {
	const r = await partner.request( '/wp-admin/options.php', { method: 'POST', form: { option_page: 'glc_settings_group', action: 'update', 'glc_settings[rate_max_plausible]': '1', 'glc_settings[out_of_hours_fee]': '0' } } );
	return { ok: denied( r ), got: `${ r.status }` };
} );
await row( 'settings.owner', 'control: the owner opens Settings → Geolander and finds the new fields', async () => {
	const r = await owner.request( '/wp-admin/options-general.php?page=geolander' );
	const ids = [ 'handover_window_start', 'handover_window_end', 'out_of_hours_fee', 'handover_default_time', 'rate_min_plausible', 'rate_max_plausible', 'fee_max_plausible', 'season_max_count', 'price_grid_car_page', 'price_grid_long_term', 'partner_upload_max_mb', 'partner_image_min_width', 'partner_email_copy', 'partner_image_types', 'gallery_max_photos', 'photo_alt_max_chars', 'spec_seats_min', 'spec_seats_max', 'spec_transmissions', 'spec_fuel_types', 'spec_drivetrain_max_chars', 'spec_color_max_chars', 'spec_license_max_chars', 'readonly_excerpt_chars' ];
	const missing = ids.filter( ( key ) => ! r.text.includes( `id="glc-${ key }"` ) );
	const selects = ( r.text.match( /<select id="glc-/g ) ?? [] ).length;
	const pending = r.text.split( fx.strings.not_read_yet ).length - 1;
	// Nothing is marked "not in use yet" any more: the two photo limits are read since the photos leg.
	return { ok: r.status === 200 && missing.length === 0 && selects === 3 && pending === 0 && [ 'display_timezone', 'preview_samples', 'history_rows', 'counter_months' ].every( ( key ) => r.text.includes( `id="glc-${ key }"` ) ) && ! /setting_[a-z_]+|settings_section_/.test( r.text ), got: `${ r.status }, ${ ids.length - missing.length }/${ ids.length } fields, ${ selects } choices, ${ pending } marked "not in use yet"${ missing.length ? ', missing ' + missing.join( ',' ) : '' }` };
} );
await row( 'users.edit-owner', 'another person\'s profile is closed', async () => {
	const r = await partner.request( `/wp-admin/user-edit.php?user_id=${ fx.accounts.owner.id }` );
	return { ok: refusedByCore( r, 'id="your-profile"' ), got: `${ r.status } "${ r.text.match( /Sorry[^<]*/ )?.[0] ?? '' }"` };
} );
await row( 'users.profile', 'a partner can open their own profile', async () => { const r = await partner.request( '/wp-admin/profile.php' ); return { ok: r.status === 200, got: `${ r.status }` }; } );
for ( const [ id, what, send ] of [
	[ 'rest.users-edit', 'listing users with their private fields', () => partner.rest( '/wp/v2/users?context=edit' ) ],
	[ 'rest.users-promote', 'making oneself an administrator', () => partner.rest( '/wp/v2/users/me', { method: 'POST', json: { roles: [ 'administrator' ] } } ) ],
	[ 'rest.users-edit-owner', 'editing the owner\'s account', () => partner.rest( `/wp/v2/users/${ fx.accounts.owner.id }`, { method: 'POST', json: { name: 'x', password: 'x' } } ) ],
	[ 'rest.users-create', 'creating a user', () => partner.rest( '/wp/v2/users', { method: 'POST', json: { username: 'glc_x', email: 'x@example.test', password: 'x', roles: [ 'administrator' ] } } ) ],
	[ 'rest.users-delete', 'deleting a user', () => partner.rest( `/wp/v2/users/${ fx.accounts.owner.id }?force=true&reassign=1`, { method: 'DELETE' } ) ],
	[ 'rest.settings', 'reading or writing site settings', () => partner.rest( '/wp/v2/settings', { method: 'POST', json: { title: 'x' } } ) ],
] ) {
	await row( id, `REST: ${ what } is refused`, async () => { const r = await send(); return { ok: denied( r ), got: code( r ) }; } );
}
await row( 'users.still-partner', 'after those attempts the partner is still shut out of Users', async () => { const r = await partner.request( '/wp-admin/users.php' ); return { ok: denied( r ), got: `${ r.status }` }; } );
await row( 'users.rest-list', 'the public user list in REST does not show partners', async () => {
	const list = await anon.rest( '/wp/v2/users?per_page=100' );
	const one  = await anon.rest( `/wp/v2/users/${ fx.accounts.partner.id }` );
	const listed = ( list.data ?? [] ).some?.( ( u ) => [ fx.accounts.partner.id, fx.accounts.partner_ka.id ].includes( u.id ) ) || list.text.includes( fx.accounts.partner.slug );
	return { ok: list.status === 200 && ! listed && one.status >= 400 && ! one.text.includes( fx.accounts.partner.slug ), got: `list ${ list.status } (${ Array.isArray( list.data ) ? list.data.length : 0 } user(s), partner ${ listed ? 'LISTED' : 'absent' }), single ${ code( one ) }` };
} );
await row( 'users.author-page', 'a partner has no public author page: no name, no login, in any form of the address', async () => {
	const got = [];
	for ( const path of [ `/?author=${ fx.accounts.partner.id }`, `/author/${ fx.accounts.partner.slug }/`, `/author/${ fx.accounts.partner.slug }/feed/`, `/ka/author/${ fx.accounts.partner.slug }/`, `/?author=${ fx.accounts.partner_ka.id }` ] ) {
		const r = await anon.request( path );
		// A 404 page may repeat the address that was asked for; the name, and the login anywhere else, must not appear.
		const names = r.text.includes( 'Test Partner' ) || r.text.includes( `author-${ fx.accounts.partner.slug }` ) || r.location.includes( fx.accounts.partner.slug );
		got.push( `${ r.status }${ names ? ' NAMED' : '' }` );
	}
	const map = await anon.request( '/wp-sitemap-users-1.xml' );
	return { ok: got.every( ( g ) => g === '404' ) && ! map.text.includes( fx.accounts.partner.slug ), got: `${ got.join( ', ' ) }; users sitemap ${ map.status }, partner ${ map.text.includes( fx.accounts.partner.slug ) ? 'LISTED' : 'absent' }` };
} );

/* --------------------------------------------- every other kind of content */

for ( const type of [ 'booking_request', 'place', 'testimonial', 'faq', 'city', 'glc_news', 'page', 'post', 'wp_block' ] ) {
	await row( `content.list-${ type }`, `the ${ type } list is closed`, async () => { const r = await partner.request( `/wp-admin/edit.php?post_type=${ type }` ); return { ok: denied( r ), got: `${ r.status }` }; } );
}
await row( 'content.booking-email', 'sending a booking confirmation is refused', async () => {
	const r = await partner.request( `/wp-admin/admin-post.php?action=glc_send_booking_confirmation&booking_id=${ fx.booking_request }` );
	return { ok: denied( r ), got: `${ r.status }` };
} );
await row( 'content.rest-writes', 'REST: creating anything in any content type is refused (types read from the site, not listed here)', async () => {
	const types = await owner.rest( '/wp/v2/types?context=edit' );
	const bases = Object.values( types.data ?? {} ).filter( ( t ) => t.slug !== 'attachment' ).map( ( t ) => `/${ t.rest_namespace }/${ t.rest_base }` );
	const got = [];
	for ( const path of bases ) {
		const r = await partner.rest( path, { method: 'POST', json: { title: 'x', content: 'x', status: 'publish' } } );
		got.push( [ path, r.status ] );
	}
	const let_in = got.filter( ( [ , status ] ) => status < 400 );
	const tally = {}; for ( const [ , status ] of got ) tally[ status ] = ( tally[ status ] ?? 0 ) + 1;
	return { ok: types.status === 200 && bases.length >= 8 && let_in.length === 0, got: `${ bases.length } types: ${ Object.entries( tally ).map( ( [ s, n ] ) => `${ n }×${ s }` ).join( ', ' ) }${ let_in.length ? '; ACCEPTED: ' + let_in.map( ( [ p ] ) => p ).join( ', ' ) : '' }` };
} );
await row( 'content.rest-terms', 'REST: creating a brand or a body type is refused', async () => {
	const a = await partner.rest( '/wp/v2/car_brand', { method: 'POST', json: { name: 'x' } } );
	const b = await partner.rest( '/wp/v2/car_body_type', { method: 'POST', json: { name: 'x' } } );
	return { ok: denied( a ) && denied( b ), got: `${ code( a ) } and ${ code( b ) }` };
} );

/* --------------------------------------------------------------- change log */

await row( 'log.partner', 'the change log is closed to a partner', async () => { const r = await partner.request( '/wp-admin/edit.php?post_type=glc_change' ); return { ok: denied( r ), got: `${ r.status }` }; } );
await row( 'log.owner', 'control: the owner reads the change log, and it offers no edit, add or delete', async () => {
	const r = await owner.request( '/wp-admin/edit.php?post_type=glc_change' );
	const main = wrap( r.text );
	const offers = [ 'post-new.php?post_type=glc_change', `post.php?post=${ fx.change.id }&amp;action=edit`, 'action=trash', 'name="action"' ].filter( ( s ) => main.includes( s ) );
	return { ok: r.status === 200 && fx.change.id > 0 && main.includes( fx.change.title ) && offers.length === 0, got: `${ r.status }, entry "${ fx.change.title }" ${ main.includes( fx.change.title ) ? 'listed' : 'MISSING' }, offers: ${ offers.join( ', ' ) || 'none' }` };
} );
await row( 'log.owner-edit', 'not even the owner can open an entry for editing or bin it', async () => {
	const a = await owner.request( `/wp-admin/post.php?post=${ fx.change.id }&action=edit` );
	const b = await owner.request( `/wp-admin/post.php?post=${ fx.change.id }&action=trash` );
	const c = await owner.request( '/wp-admin/post-new.php?post_type=glc_change' );
	return { ok: a.status >= 400 && b.status >= 400 && c.status >= 400, got: `edit ${ a.status }, bin ${ b.status }, add ${ c.status }` };
} );
await row( 'log.rest', 'the change log is not in REST, for anyone', async () => {
	const got = [];
	for ( const session of [ anon, partner, owner ] ) {
		for ( const path of [ '/wp/v2/glc_change', `/wp/v2/glc_change/${ fx.change.id }`, `/wp/v2/posts/${ fx.change.id }`, `/wp/v2/search?search=${ encodeURIComponent( 'fees by' ) }&subtype=glc_change` ] ) got.push( ( await session.rest( path ) ).status );
	}
	const types = await owner.rest( '/wp/v2/types?context=edit' );
	return { ok: got.every( ( s ) => s === 404 || s === 400 ) && ! ( 'glc_change' in ( types.data ?? {} ) ) && types.status === 200, got: [ ...new Set( got ) ].join( '/' ) + ', listed among REST types: ' + ( 'glc_change' in ( types.data ?? {} ) ? 'YES' : 'no' ) };
} );
await row( 'log.public', 'the change log has no public address, is not in search and not in the sitemap', async () => {
	const direct  = await anon.request( `/?p=${ fx.change.id }` );
	const archive = await anon.request( '/?post_type=glc_change' );
	const search  = await anon.request( `/?s=${ encodeURIComponent( 'fees by wp-cli' ) }` );
	const index   = await anon.request( '/wp-sitemap.xml' );
	const map     = await anon.request( '/wp-sitemap-posts-glc_change-1.xml' );
	// The title of the fixture's entry must be in none of them, and the sitemap index must not name the type.
	const leaks   = [ direct, archive, search, index, map ].filter( ( r ) => r.text.includes( fx.change.title ) ).length + ( index.text.includes( 'glc_change' ) ? 1 : 0 );
	return { ok: direct.status === 404 && leaks === 0 && map.status === 404 && index.status === 200, got: `entry address ${ direct.status }, archive ${ archive.status }, search ${ search.status }, sitemap index ${ index.status }, its own sitemap ${ map.status }, leaks ${ leaks }` };
} );

/* -------------------------------------------------------------------- media */

await row( 'media.upload', 'a partner can upload an image (unattached)', async () => {
	const r = await partner.rest( '/wp/v2/media', { method: 'POST', body: PIXEL, headers: { 'content-type': 'image/png', 'content-disposition': 'attachment; filename="glc-authority-test.png"' } } );
	if ( r.data?.id ) uploaded.push( r.data.id );
	return { ok: r.status === 201 && r.data?.author === fx.accounts.partner.id && r.data?.post === null, got: `${ code( r ) }, attachment #${ r.data?.id ?? '?' } by #${ r.data?.author ?? '?' }` };
} );
await row( 'media.attach', 'but not attach an upload to a car', async () => {
	const r = await partner.rest( `/wp/v2/media?post=${ mine.id }`, { method: 'POST', body: PIXEL, headers: { 'content-type': 'image/png', 'content-disposition': 'attachment; filename="glc-authority-test-2.png"' } } );
	if ( r.data?.id ) uploaded.push( r.data.id );
	return { ok: denied( r ), got: code( r ) };
} );
for ( const [ id, what, send ] of [
	[ 'media.edit-others', 'editing someone else\'s image', () => partner.rest( `/wp/v2/media/${ fx.others_image }`, { method: 'POST', json: { alt_text: 'x', title: 'x' } } ) ],
	[ 'media.delete-others', 'deleting someone else\'s image', () => partner.rest( `/wp/v2/media/${ fx.others_image }?force=true`, { method: 'DELETE' } ) ],
	[ 'media.edit-own', 'editing their own upload through WordPress (captions go through the panel later)', () => partner.rest( `/wp/v2/media/${ uploaded[0] }`, { method: 'POST', json: { alt_text: 'x' } } ) ],
	[ 'media.delete-own', 'deleting their own upload', () => partner.rest( `/wp/v2/media/${ uploaded[0] }?force=true`, { method: 'DELETE' } ) ],
] ) {
	await row( id, `REST: ${ what } is refused`, async () => { const r = await send(); return { ok: denied( r ), got: code( r ) }; } );
}
await row( 'media.ajax-attach', 'the classic uploader refuses an upload aimed at a car', async () => {
	const page  = await partner.request( '/wp-admin/media-new.php' );
	const nonce = page.text.match( /"_wpnonce":"([a-f0-9]+)"/ )?.[1] ?? '';
	const form  = new FormData();
	form.set( 'action', 'upload-attachment' ); form.set( '_wpnonce', nonce ); form.set( 'post_id', String( mine.id ) );
	form.set( 'async-upload', new Blob( [ PIXEL ], { type: 'image/png' } ), 'glc-authority-test-3.png' );
	const r = await partner.request( '/wp-admin/admin-ajax.php', { method: 'POST', body: form } );
	if ( r.data?.data?.id ) uploaded.push( r.data.data.id );
	return { ok: nonce !== '' && r.data?.success === false, got: `${ r.status }, uploader nonce ${ nonce ? 'received' : 'MISSING' }, success=${ r.data?.success }${ r.data?.data?.message ? ' "' + r.data.data.message + '"' : '' }` };
} );
await row( 'media.ajax-library', 'the media library shows a partner only their own uploads', async () => {
	const r = await partner.request( '/wp-admin/admin-ajax.php', { method: 'POST', form: { action: 'query-attachments', 'query[posts_per_page]': '80' } } );
	const items = r.data?.data ?? [];
	const foreign = items.filter( ( a ) => Number( a.author ) !== fx.accounts.partner.id );
	return { ok: r.data?.success === true && items.some( ( a ) => a.id === uploaded[0] ) && foreign.length === 0, got: `${ r.status }, ${ items.length } item(s), ${ foreign.length } not theirs` };
} );
await row( 'media.ajax-save', 'saving details of someone else\'s image through the library is refused', async () => {
	const r = await partner.request( '/wp-admin/admin-ajax.php', { method: 'POST', form: { action: 'save-attachment', id: fx.others_image, 'changes[alt]': 'x', 'changes[title]': 'x' } } );
	return { ok: r.status === 403 || r.text.trim() === '-1' || r.data?.success === false, got: `${ r.status } "${ r.text.trim().slice( 0, 30 ) }"` };
} );

/* ------------------------- photos: what an upload becomes, whose uploads are seen, core's own doors */

const mineScreen = await partner.request( carUrl( mine.id ) );
const picker = pickerParams( mineScreen.text );
const pickerB = pickerParams( ( await partnerB.request( carUrl( theirs.id ) ) ).text );
const uploadsOf = async ( who ) => ( await inspect() ).uploads[ who ];
let wide = 0, narrow = 0, foreign = 0;
await row( 'media.picker', 'a car\'s screen carries WordPress\'s own picker, tells it the partner\'s size limit, and marks its uploads as car photos', async () => {
	const limit = mineScreen.text.match( /"max_file_size":"(\d+)b"/ )?.[1] ?? '';
	return { ok: picker?.action === 'upload-attachment' && picker?.glc_car_photo === '1' && /^[a-f0-9]{10}$/.test( picker?._wpnonce ?? '' ) && limit === String( 10 * 1024 * 1024 ), got: `action ${ picker?.action }, car-photo mark ${ picker?.glc_car_photo }, size limit told to the browser ${ limit } bytes` };
} );
await row( 'media.upload-stored', 'a 2400×1600 PNG a partner uploads is stored as a JPEG 1920 wide, theirs, attached to nothing, with every size the site serves', async () => {
	const up = await pick( partner, picker, await image( 2400, 1600, 'png' ), 'glc-test-wide.png', 'image/png' );
	wide = up.id;
	const p = ( await photosOf( up.id ) )[ up.id ] ?? {};
	return { ok: up.ok && p.post_mime_type === 'image/jpeg' && p.disk_mime === 'image/jpeg' && p.first_bytes === 'FFD8FF' && /glc-test-wide(-\d+)?\.jpg$/.test( p.file ?? '' ) && p.width === 1920 && p.height === 1280 && Number( p.post_author ) === fx.accounts.partner.id && Number( p.post_parent ) === 0 && p.sizes.includes( 'glc-hero' ) && p.sizes.includes( 'glc-card' ), got: `${ up.r.status } success=${ up.r.data?.success }${ up.message ? ' "' + up.message + '"' : '' }; in the database: ${ p.post_mime_type }, author #${ p.post_author }, parent ${ p.post_parent }, file ${ p.file }; on disk: first bytes ${ p.first_bytes }, ${ p.width }×${ p.height }, ${ p.bytes } bytes, ${ p.sizes?.length } sizes` };
} );
await row( 'media.upload-upright', 'a picture stored on its side with a note saying which way is up is turned upright, and the note (with everything else the camera wrote) is gone', async () => {
	const up = await pick( partner, picker, await image( 2000, 1200, 'rotated' ), 'glc-test-rotated.jpg', 'image/jpeg' );
	const p = ( await photosOf( up.id ) )[ up.id ] ?? {};
	const sentWith = ( await image( 2000, 1200, 'rotated' ) ).includes( 'Exif\0\0' );
	return { ok: up.ok && sentWith && p.width < p.height && p.height === 1920 && p.width === 1152 && p.orientation === 0 && p.camera_data === false && p.disk_mime === 'image/jpeg', got: `sent 2000×1200 lying on its side, with a block of camera data; stored ${ p.width }×${ p.height }, orientation note ${ p.orientation || 'none' }, camera data ${ p.camera_data ? 'STILL IN THE FILE' : 'none' }` };
} );
await row( 'media.upload-narrow', 'a 1200-pixel photo can be uploaded (it is the gallery that refuses it, below)', async () => {
	const up = await pick( partner, picker, await image( 1200, 900 ), 'glc-test-narrow.jpg', 'image/jpeg' );
	narrow = up.id;
	const p = ( await photosOf( up.id ) )[ up.id ] ?? {};
	return { ok: up.ok && p.width === 1200, got: `success=${ up.r.data?.success }, stored ${ p.width }×${ p.height }` };
} );
await row( 'media.upload-too-big', 'a file over the partner limit is refused with a sentence that names both sizes, and nothing is stored', async () => {
	const had = await uploadsOf( 'partner' );
	const big = Buffer.concat( [ await image( 1700, 1100 ), Buffer.alloc( 11 * 1024 * 1024 ) ] );
	const up = await pick( partner, picker, big, 'glc-test-big.jpg', 'image/jpeg' );
	const now = await uploadsOf( 'partner' );
	return { ok: ! up.ok && /This file is 11(\.\d)? MB\. The largest photo you can upload is 10(\.0)? MB\./.test( up.message ) && now === had, got: `success=${ up.r.data?.success } "${ up.message }"; the partner's uploads ${ had } → ${ now }` };
} );
await row( 'media.upload-not-a-photo', 'text named photo.jpg, a GIF and an SVG are each refused with the kinds the site takes, through the picker and through REST; nothing is stored', async () => {
	const had = await uploadsOf( 'partner' );
	const text = Buffer.from( '<?php echo "not a picture"; ?>' );
	const svg = Buffer.from( '<svg xmlns="http://www.w3.org/2000/svg" width="2000" height="1500"><rect width="2000" height="1500"/></svg>' );
	const tries = [
		await pick( partner, picker, text, 'photo.jpg', 'image/jpeg' ),
		await pick( partner, picker, await image( 2000, 1500, 'gif' ), 'glc-test.gif', 'image/gif' ),
		await pick( partner, picker, svg, 'glc-test.svg', 'image/svg+xml' ),
	];
	const rest = await partner.rest( '/wp/v2/media', { method: 'POST', body: text, headers: { 'content-type': 'image/jpeg', 'content-disposition': 'attachment; filename="glc-rest.jpg"' } } );
	if ( rest.data?.id ) photoIds.push( rest.data.id );
	const said = en.err_upload_type.replace( '%s', 'jpeg, png, webp' );
	const now = await uploadsOf( 'partner' );
	return { ok: tries.every( ( t ) => ! t.ok && t.message === said ) && rest.status >= 400 && String( rest.data?.message ?? '' ).includes( 'not a photo' ) && now === had, got: `picker: ${ tries.map( ( t ) => `success=${ t.r.data?.success }` ).join( ', ' ) } "${ tries[0].message }"; REST ${ code( rest ) }; the partner's uploads ${ had } → ${ now }` };
} );
await row( 'media.upload-unreadable', 'a file whose first bytes say "JPEG, 2000 × 1500" and that no image library can open is refused with a sentence, not stored as it came', async () => {
	// A JPEG's opening, a header that declares 2000 × 1500 in three colours, and the end marker: no picture in between.
	const hollow = Buffer.from( [ 0xFF, 0xD8, 0xFF, 0xE0, 0x00, 0x10, 0x4A, 0x46, 0x49, 0x46, 0x00, 0x01, 0x01, 0x00, 0x00, 0x01, 0x00, 0x01, 0x00, 0x00, 0xFF, 0xC0, 0x00, 0x11, 0x08, 0x05, 0xDC, 0x07, 0xD0, 0x03, 0x01, 0x22, 0x00, 0x02, 0x11, 0x01, 0x03, 0x11, 0x01, 0xFF, 0xD9 ] );
	const had = await uploadsOf( 'partner' );
	const up = await pick( partner, picker, hollow, 'glc-test-hollow.jpg', 'image/jpeg' );
	const now = await uploadsOf( 'partner' );
	return { ok: ! up.ok && up.message === en.err_upload_unreadable && now === had, got: `success=${ up.r.data?.success } "${ up.message }"; the partner's uploads ${ had } → ${ now }` };
} );
await row( 'media.upload-other-partner','another partner uploads a photo of their own (used below as "someone else\'s")', async () => {
	const up = await pick( partnerB, pickerB, await image( 2000, 1300 ), 'glc-test-b-wide.jpg', 'image/jpeg' );
	foreign = up.id;
	return { ok: up.ok && foreign > 0, got: `attachment #${ foreign }` };
} );
await row( 'media.library-own-only', 'the picker, REST and the Media screen show a partner their own uploads and nobody else\'s, whatever the request asks for', async () => {
	const ask = async ( extra ) => ( await partner.request( '/wp-admin/admin-ajax.php', { method: 'POST', form: { action: 'query-attachments', 'query[posts_per_page]': '100', ...extra } } ) ).data?.data ?? [];
	const lists = [ await ask( {} ), await ask( { 'query[author]': '0' } ), await ask( { 'query[author]': String( fx.accounts.partner_b.id ) } ), await ask( { 'query[author__in][]': String( fx.accounts.partner_b.id ) } ), await ask( { 'query[post__in][]': String( foreign ) } ), await ask( { 'query[post__in][]': String( fx.others_image ) } ), await ask( { 'query[s]': 'glc-test-b-wide' } ) ];
	const strangers = lists.map( ( items ) => items.filter( ( a ) => Number( a.author ) !== fx.accounts.partner.id ).length );
	const rest = await partner.rest( '/wp/v2/media?per_page=100' );
	const restB = await partner.rest( `/wp/v2/media?per_page=100&author=${ fx.accounts.partner_b.id }` );
	const restStrangers = [ rest, restB ].map( ( r ) => ( r.data ?? [] ).filter( ( a ) => a.author !== fx.accounts.partner.id ).length );
	const screen = await partner.request( '/wp-admin/upload.php?mode=list' );
	const owners = await owner.request( '/wp-admin/upload.php?mode=list&s=glc-test' );
	return { ok: lists[0].some( ( a ) => a.id === wide ) && strangers.every( ( n ) => n === 0 ) && rest.status === 200 && rest.data.some( ( a ) => a.id === wide ) && restStrangers.every( ( n ) => n === 0 ) && screen.status === 200 && screen.text.includes( 'glc-test-wide' ) && ! screen.text.includes( 'glc-test-b-wide' ) && owners.text.includes( 'glc-test-b-wide' ) && owners.text.includes( 'glc-test-wide' ), got: `picker: ${ lists.map( ( l ) => l.length ).join( '/' ) } items over seven ways of asking, not theirs ${ strangers.join( '/' ) }; REST: ${ rest.data?.length } and ${ restB.data?.length } items, not theirs ${ restStrangers.join( '/' ) }; Media screen: own shown, the other partner's ${ screen.text.includes( 'glc-test-b-wide' ) ? 'SHOWN' : 'absent' }; control: the owner's Media screen shows both` };
} );
await row( 'media.core-doors', 'a partner cannot edit or delete an attachment through any door of WordPress: not their own upload, not another partner\'s, not the site\'s', async () => {
	const ids = [ wide, foreign, fx.others_image ];
	const was = JSON.stringify( await photosOf( ...ids ) );
	const own = ( ( await partner.request( '/wp-admin/admin-ajax.php', { method: 'POST', form: { action: 'query-attachments', 'query[post__in][]': String( wide ) } } ) ).data?.data ?? [] )[0];
	const got = [];
	const refused = ( r ) => r.status === 403 || r.status === 400 || r.text.trim() === '-1' || r.text.trim() === '0' || r.data?.success === false || ( r.status === 500 && /not allowed/i.test( r.text ) );
	for ( const id of ids ) {
		const ajax = ( form ) => partner.request( '/wp-admin/admin-ajax.php', { method: 'POST', form } );
		const tries = {
			'save-attachment': await ajax( { action: 'save-attachment', id, nonce: own?.nonces?.update || 'x', 'changes[alt]': 'hacked', 'changes[title]': 'hacked' } ),
			'save-attachment-compat': await ajax( { action: 'save-attachment-compat', id, nonce: 'x', [ `attachments[${ id }][post_title]` ]: 'hacked' } ),
			'delete-post': await ajax( { action: 'delete-post', id, _ajax_nonce: own?.nonces?.delete || 'x' } ),
			'trash-post': await ajax( { action: 'trash-post', id, _ajax_nonce: 'x' } ),
			'image-editor': await ajax( { action: 'image-editor', postid: id, do: 'save', _ajax_nonce: 'x' } ),
			'set-post-thumbnail': await ajax( { action: 'set-post-thumbnail', post_id: mine.id, thumbnail_id: id, _ajax_nonce: 'x' } ),
			'edit screen': await partner.request( `/wp-admin/post.php?post=${ id }&action=edit` ),
			'delete link': await partner.request( `/wp-admin/post.php?post=${ id }&action=delete` ),
			'bulk delete': await partner.request( `/wp-admin/upload.php?action=delete&media%5B%5D=${ id }` ),
			'REST edit': await partner.rest( `/wp/v2/media/${ id }`, { method: 'POST', json: { alt_text: 'hacked', title: 'hacked', post: mine.id } } ),
			'REST delete': await partner.rest( `/wp/v2/media/${ id }?force=true`, { method: 'DELETE' } ),
		};
		for ( const [ door, r ] of Object.entries( tries ) ) if ( ! refused( r ) ) got.push( `#${ id } ${ door }: ${ r.status } "${ r.text.trim().slice( 0, 40 ) }"` );
	}
	const now = JSON.stringify( await photosOf( ...ids ) );
	return { ok: got.length === 0 && now === was && own?.nonces?.update === false && own?.nonces?.delete === false && own?.nonces?.edit === false, got: got.length ? got.join( '; ' ) : `11 doors × 3 attachments all refused; WordPress hands the partner no edit or delete key for their own upload (${ JSON.stringify( own?.nonces ) }); the three attachments, their files and their alt texts are ${ now === was ? 'exactly as before' : 'CHANGED' }` };
} );

/* ---------------------------------------- application passwords and XML-RPC */

await row( 'app-passwords.create', 'a partner cannot create an application password', async () => {
	const r = await partner.rest( `/wp/v2/users/${ fx.accounts.partner.id }/application-passwords`, { method: 'POST', json: { name: 'glc authority test' } } );
	return { ok: r.status >= 400 && ! r.text.includes( '"password"' ), got: code( r ) };
} );
await row( 'app-passwords.profile', 'their profile offers no form to make one', async () => {
	const r = await partner.request( '/wp-admin/profile.php' );
	const form = r.text.includes( 'new_application_password_name' );
	const http = /application password feature requires HTTPS/i.test( r.text );
	return { ok: r.status === 200 && ! form, got: `${ r.status }, form ${ form ? 'PRESENT' : 'absent' }${ http ? ' (this site is plain http, where WordPress hides the form from everyone: see the next row)' : '' }` };
} );
await row( 'app-passwords.switch', 'with site-wide support forced on, the switch is off for partners and on for the owner', async () => {
	const f = before;
	return { ok: f.app_passwords_partner === false && f.app_passwords_partner_ka === false && f.app_passwords_owner === true, got: `partner ${ f.app_passwords_partner }, partner_ka ${ f.app_passwords_partner_ka }, owner ${ f.app_passwords_owner }` };
} );
await row( 'xmlrpc', 'XML-RPC is closed', async () => {
	const r = await anon.request( '/xmlrpc.php', { method: 'POST', headers: { 'content-type': 'text/xml' }, body: `<?xml version="1.0"?><methodCall><methodName>wp.getUsersBlogs</methodName><params><param><value>${ fx.accounts.partner.login }</value></param><param><value>${ fx.accounts.partner.password }</value></param></params></methodCall>` } );
	return { ok: denied( r ) && ! r.text.includes( '<methodResponse>' ), got: `${ r.status }` };
} );
await row( 'xmlrpc.wordpress', 'and switched off in WordPress itself, behind the web server\'s rule: its own XML-RPC server refuses a login before it looks at the password', async () => {
	const x = await wp( 'xmlrpc', fx.accounts.partner.login );
	return { ok: x.enabled === false && x.signed_in === false && x.code === 405, got: `xmlrpc_enabled ${ x.enabled }; a login answers ${ x.code } "${ x.error }"` };
} );

/* --------------------------- the change log: closed entries (holes left by L1) */

await row( 'log.entry-caps', 'WordPress lets nobody edit or delete a change-log entry, its author included, and lets only the owner read one', async () => {
	const c = fx.entry_caps;
	const wrong = [ 'owner_edits_own', 'owner_deletes_own', 'partner_reads_own', 'partner_edits_own', 'partner_deletes_own' ].filter( ( key ) => c[ key ] !== false );
	return { ok: wrong.length === 0 && c.owner_reads === true && fx.entries.by_owner > 0 && fx.entries.by_partner > 0, got: `owner edits own ${ c.owner_edits_own }, owner deletes own ${ c.owner_deletes_own }, partner reads own ${ c.partner_reads_own }, partner edits own ${ c.partner_edits_own }, owner reads ${ c.owner_reads }` };
} );
await row( 'log.owner-edit-own', 'over the wire too: the owner cannot open, bin or delete an entry they wrote themselves', async () => {
	const id = fx.entries.by_owner;
	const a = await owner.request( `/wp-admin/post.php?post=${ id }&action=edit` );
	const b = await owner.request( `/wp-admin/post.php?post=${ id }&action=trash` );
	const c = await owner.request( `/wp-admin/post.php?post=${ id }&action=delete` );
	const d = await owner.request( '/wp-admin/admin-ajax.php', { method: 'POST', form: { action: 'inline-save', post_ID: id, post_type: 'glc_change', post_title: 'x' } } );
	return { ok: [ a, b, c ].every( ( r ) => r.status >= 400 ) && ( d.status >= 400 || d.text.trim() === '-1' ), got: `edit ${ a.status }, bin ${ b.status }, delete ${ c.status }, quick edit ${ d.status } "${ d.text.trim().slice( 0, 12 ) }"` };
} );
await row( 'log.partner-own-entry', 'a partner cannot reach an entry they wrote through WordPress in any way', async () => {
	const id = fx.entries.by_partner;
	const got = [];
	for ( const path of [ `/wp-admin/post.php?post=${ id }&action=edit`, `/?p=${ id }`, `/?post_type=glc_change&p=${ id }`, `/wp-admin/edit.php?post_type=glc_change` ] ) got.push( ( await partner.request( path ) ).status );
	const rest = await partner.rest( `/wp/v2/posts/${ id }` );
	return { ok: got.every( ( s ) => s === 403 || s === 404 ) && rest.status >= 400, got: `${ got.join( ', ' ) }; REST ${ rest.status }` };
} );

/* -------------------------------------------- one car's screen: who may open it */

const ownScreen = await partner.request( carUrl( mine.id ) );
await row( 'car.screen-anonymous', 'nobody signed in: a car\'s screen sends to the login page', async () => {
	const r = await anon.request( carUrl( mine.id ) );
	return { ok: r.status === 302 && r.location.includes( '/wp-login.php' ), got: `${ r.status } → ${ r.location.split( '?' )[0] }` };
} );
await row( 'car.screen-own', 'a partner opens the car assigned to them: prices, fees and history, and no other car', async () => {
	const main = wrap( ownScreen.text );
	const forms = [ 'glc_partner_save_pricing', 'glc_partner_save_fees' ].filter( ( action ) => formFor( ownScreen.text, action ) );
	const rows = ( main.replace( /<template[\s\S]*?<\/template>/g, '' ).match( /<tr class="glc-season/g ) ?? [] ).length;
	return { ok: ownScreen.status === 200 && main.includes( mine.title ) && main.includes( mine.plate ) && forms.length === 2 && rows > 0 && main.includes( en.history_heading ) && ! ownScreen.text.includes( other.plate ) && ! ownScreen.text.includes( theirs.plate ), got: `${ ownScreen.status }, ${ forms.length } forms, ${ rows } season rows, other plates ${ ownScreen.text.includes( other.plate ) || ownScreen.text.includes( theirs.plate ) ? 'PRESENT' : 'absent' }` };
} );
const noSuchCar = await partner.request( carUrl( 99999999 ) );
for ( const [ id, what, car ] of [ [ 'car.screen-unassigned', 'Geolander\'s own car', other ], [ 'car.screen-theirs', 'another partner\'s car', theirs ] ] ) {
	await row( id, `a partner asking for ${ what } by id is refused, with the same answer as for a car that does not exist`, async () => {
		const r = await partner.request( carUrl( car.id ) );
		return { ok: refusedBlind( r ) && noSuchCar.status === 403 && r.text === noSuchCar.text, got: `${ r.status }, ${ r.text === noSuchCar.text ? 'byte for byte the answer for car #99999999' : 'a DIFFERENT answer than for a car that does not exist' }` };
	} );
}
await row( 'car.screen-odd-ids', 'a car named as a list, a word, a page or nothing is refused the same way', async () => {
	const got = [];
	for ( const query of [ `car[]=${ mine.id }&car[]=${ other.id }`, 'car=abc', `car=${ mine.id }x`, 'car=', '', `car=${ fx.booking_request }`, `car=-${ mine.id }` ] ) {
		const r = await partner.request( `/wp-admin/admin.php?page=glc-partner-car&${ query }` );
		got.push( refusedBlind( r ) ? 403 : `${ r.status }!` );
	}
	return { ok: got.every( ( g ) => g === 403 ), got: got.join( ', ' ) };
} );
await row( 'car.screen-owner', 'the owner opens every car\'s screen', async () => {
	const got = [];
	for ( const car of [ mine, other, theirs, legacy ] ) {
		const r = await owner.request( carUrl( car.id ) );
		got.push( r.status === 200 && wrap( r.text ).includes( car.plate ) && formFor( r.text, 'glc_partner_save_pricing' ) ? 200 : `${ r.status }!` );
	}
	return { ok: got.every( ( g ) => g === 200 ), got: got.join( ', ' ) };
} );
await row( 'car.restore-screen', 'the restore screen opens for an entry of the person\'s own car and for no other entry', async () => {
	const own     = await partner.request( carUrl( mine.id, `&restore=${ fx.entries.by_partner }` ) );
	const foreign = await partner.request( carUrl( mine.id, `&restore=${ fx.entries.by_owner }` ) );    // an entry of another partner's car, asked through one's own
	const theirsR = await partner.request( carUrl( theirs.id, `&restore=${ fx.entries.by_owner }` ) );
	const missing = await partner.request( carUrl( mine.id, '&restore=99999999' ) );
	return { ok: own.status === 200 && !! formFor( own.text, 'glc_partner_restore' ) && [ foreign, theirsR, missing ].every( refusedBlind ), got: `own ${ own.status }, another car's entry ${ foreign.status }, another car ${ theirsR.status }, no such entry ${ missing.status }` };
} );
await row( 'car.restore-gap', 'an earlier table that leaves days without a season cannot be put back: the screen says which days and offers no button', async () => {
	const r = await partner.request( carUrl( mine.id, `&restore=${ fx.entries.gap }` ) );
	const text = visible( r.text );
	return { ok: r.status === 200 && text.includes( en.restore_refused ) && /No season covers [A-Z][a-z]{2} \d+ to [A-Z][a-z]{2} \d+/.test( text ) && ! formFor( r.text, 'glc_partner_restore' ), got: `${ r.status }, said: "${ text.match( /No season covers[^.]*\./ )?.[0] ?? 'nothing about a gap' }", confirm button ${ formFor( r.text, 'glc_partner_restore' ) ? 'PRESENT' : 'absent' }` };
} );

/* ------------------------------------- one car's handlers: every way to be refused */

const start        = await inspect();
const ownForms     = { pricing: formFor( ownScreen.text, 'glc_partner_save_pricing' ), fees: formFor( ownScreen.text, 'glc_partner_save_fees' ), photos: formTicked( ownScreen.text, 'glc_partner_save_photos' ), specs: formTicked( ownScreen.text, 'glc_partner_save_specs' ) };
const ownRestore   = formFor( ( await partner.request( carUrl( mine.id, `&restore=${ fx.entries.by_partner }` ) ) ).text, 'glc_partner_restore' );
const cheaper      = start.cars[ mine.id ].pricing.map( ( s, i ) => ( i ? s : { ...s, rates: { ...s.rates, d1_2: Number( s.rates.d1_2 ) + 1 } } ) );
/** Requests that WOULD be accepted from the partner for their own car. Each case below breaks exactly one thing. */
const valid = {
	glc_partner_save_pricing: { ...ownForms.pricing.fields, glc_table: packTable( cheaper ) },
	glc_partner_save_fees:    { ...ownForms.fees.fields, 'glc_fee[kutaisi_airport]': '1', 'glc_fee[tbilisi_city]': '1' },
	glc_partner_preview:      { ...ownForms.pricing.fields, action: 'glc_partner_preview', _wpnonce: ownForms.pricing.previewNonce, section: 'pricing', glc_table: packTable( cheaper ) },
	glc_partner_restore:      { ...ownRestore.fields },
	glc_partner_save_photos:  { ...ownForms.photos.fields, glc_gallery: String( wide ), glc_main: String( wide ) },
	glc_partner_save_specs:   { ...ownForms.specs.fields, 'glc_spec[glc_color]': 'Teal' },
};
const otherNonce = { glc_partner_save_pricing: ownForms.fees.fields._wpnonce, glc_partner_save_fees: ownForms.pricing.fields._wpnonce, glc_partner_preview: ownForms.pricing.fields._wpnonce, glc_partner_restore: ownForms.pricing.fields._wpnonce, glc_partner_save_photos: ownForms.specs.fields._wpnonce, glc_partner_save_specs: ownForms.photos.fields._wpnonce };
const without = ( fields, key ) => Object.fromEntries( Object.entries( fields ).filter( ( [ k ] ) => k !== key ) );
for ( const action of Object.keys( valid ) ) {
	const name = action.replace( 'glc_partner_', '' ).replace( '_', '-' );
	for ( const [ kase, what, session, fields ] of [
		[ 'anonymous', 'nobody signed in', anon, valid[ action ] ],
		[ 'no-nonce', 'the partner, own car, no nonce', partner, without( valid[ action ], '_wpnonce' ) ],
		[ 'forged-nonce', 'the partner, own car, a made-up nonce', partner, { ...valid[ action ], _wpnonce: '0123456789' } ],
		[ 'other-action-nonce', 'the partner, own car, the nonce of another form', partner, { ...valid[ action ], _wpnonce: otherNonce[ action ] } ],
		[ 'other-person-nonce', 'another partner replaying this partner\'s whole request', partnerB, valid[ action ] ],
		[ 'unassigned-car', 'the partner naming Geolander\'s own car with the nonce of their own', partner, { ...valid[ action ], car: other.id } ],
		[ 'theirs-car', 'the partner naming another partner\'s car with the nonce of their own', partner, { ...valid[ action ], car: theirs.id } ],
		[ 'two-cars', 'the partner naming their own car and someone else\'s in one request', partner, [ ...Object.entries( without( valid[ action ], 'car' ) ), [ 'car[]', mine.id ], [ 'car[]', other.id ] ] ],
		[ 'no-car', 'the partner naming no car', partner, without( valid[ action ], 'car' ) ],
	] ) {
		await row( `handler.${ name }.${ kase }`, `${ name }: ${ what } is refused and learns nothing`, async () => {
			const r = await session.request( ADMIN_POST, { method: 'POST', form: fields } );
			return { ok: refusedBlind( r ), got: `${ r.status }${ r.text.includes( en.panel_refused ) || r.text.includes( ka.panel_refused ) ? '' : ' (not the panel\'s refusal)' }` };
		} );
	}
}
await row( 'handler.restore.foreign-entry', 'restore: an entry of another car, asked with a valid nonce for one\'s own car and entry, is refused', async () => {
	const r = await partner.request( ADMIN_POST, { method: 'POST', form: { ...valid.glc_partner_restore, change: fx.entries.by_owner } } );
	return { ok: refusedBlind( r ), got: `${ r.status }` };
} );
await row( 'handler.get', 'the handlers answer a plain link (GET) the same way: no nonce, no save', async () => {
	const got = [];
	for ( const action of Object.keys( valid ) ) got.push( ( await partner.request( `${ ADMIN_POST }?action=${ action }&car=${ mine.id }&glc_rev=${ valid[ action ].glc_rev }` ) ).status );
	return { ok: got.every( ( s ) => s === 403 ), got: got.join( ', ' ) };
} );

/* ------------------------------ photos and details: what the store refuses, and what a refusal leaves behind */

/** Send a form of a person's own car and read the answer and the car as the database holds it. */
const tryCar = async ( session, form, over, car = mine ) => {
	const { sent, page } = await send( session, { ...form, ...over } );
	const now = await inspect();
	const was = start.cars[ car.id ], is = now.cars[ car.id ];
	return { sent, page, said: notices( page?.text ?? '' ).join( ' | ' ), now, quiet: is.gallery === was.gallery && is.thumbnail === was.thumbnail && JSON.stringify( is.specs ) === JSON.stringify( was.specs ) && JSON.stringify( is.identity ) === JSON.stringify( was.identity ) && is.rev === was.rev && now.change_count === start.change_count };
};
await row( 'photos.foreign-id', 'a gallery that holds another partner\'s upload, the site\'s photo from another car, an id that is no photo or an id that is nothing: the whole save is refused with one and the same sentence, and nothing of that photo is shown', async () => {
	const got = [];
	const picture = ( await photosOf( foreign ) )[ foreign ].file.replace( /\.jpg$/, '' );
	for ( const [ what, id ] of [ [ 'another partner\'s upload', foreign ], [ 'the site\'s photo on another car', fx.others_image ], [ 'a car', other.id ], [ 'a booking request', fx.booking_request ], [ 'nothing', 99999999 ] ] ) {
		const t = await tryCar( partner, ownForms.photos.fields, { glc_gallery: `${ wide },${ id }`, glc_main: String( wide ) } );
		const fine = t.sent.status === 303 && t.said.includes( en.not_saved ) && t.said.includes( en.err_photo_foreign.replace( '%1$d', String( id ) ) ) && t.quiet && ! t.page.text.includes( picture ) && ! t.page.text.includes( `data-id="${ id }"` ) && t.page.text.includes( `data-id="${ wide }"` );
		got.push( `${ what } ${ fine ? 'refused' : 'NOT REFUSED CLEANLY (' + t.sent.status + ' "' + t.said.slice( 0, 80 ) + '", database ' + ( t.quiet ? 'unchanged' : 'CHANGED' ) + ')' }` );
	}
	return { ok: got.every( ( g ) => g.endsWith( 'refused' ) ), got: `${ got.join( '; ' ) }; said each time: "${ en.err_photo_foreign.replace( '%1$d', 'N' ) }"; the partner's own good photo stays on the form; gallery, main photo, revision and log as before` };
} );
await row( 'photos.foreign-alt', 'an alt text aimed at someone else\'s photo, a main photo that is not in the gallery, no main photo, and an id that is a word are each refused; the other photo keeps its alt', async () => {
	const was = JSON.stringify( await photosOf( foreign, fx.others_image ) );
	const good = { glc_gallery: String( wide ), glc_main: String( wide ) };
	const tries = [
		[ 'alt for another partner\'s photo', { ...good, [ `glc_alt[${ foreign }]` ]: 'hacked' }, en.err_photo_foreign.replace( '%1$d', String( foreign ) ) ],
		[ 'alt for the site\'s photo', { ...good, [ `glc_alt[${ fx.others_image }]` ]: 'hacked' }, en.err_photo_foreign.replace( '%1$d', String( fx.others_image ) ) ],
		[ 'main photo outside the gallery', { ...good, glc_main: String( fx.others_image ) }, en.err_photo_main.replace( '%1$d', String( fx.others_image ) ) ],
		[ 'no main photo', { ...good, glc_main: '' }, en.err_photo_main_none ],
		[ 'a word for an id', { glc_gallery: `${ wide },abc`, glc_main: String( wide ) }, en.err_photo_id.replace( '%1$s', 'abc' ) ],
		[ 'a main photo that is a word', { ...good, glc_main: 'first' }, en.err_photo_shape ],
	];
	const got = [];
	for ( const [ what, over, must ] of tries ) {
		const t = await tryCar( partner, ownForms.photos.fields, over );
		got.push( t.sent.status === 303 && t.said.includes( must ) && t.quiet ? 'refused' : `${ what }: ${ t.sent.status } "${ t.said.slice( 0, 90 ) }" database ${ t.quiet ? 'unchanged' : 'CHANGED' }` );
	}
	const now = JSON.stringify( await photosOf( foreign, fx.others_image ) );
	return { ok: got.every( ( g ) => g === 'refused' ) && now === was, got: `${ got.join( ', ' ) }; the two other photos ${ now === was ? 'exactly as before, alt text included' : 'CHANGED' }` };
} );
await row( 'photos.narrow', 'a photo narrower than the setting is refused into the gallery, and the sentence names the file and both widths', async () => {
	const t = await tryCar( partner, ownForms.photos.fields, { glc_gallery: `${ wide },${ narrow }`, glc_main: String( wide ) } );
	const must = /glc-test-narrow(-\d+)?\.jpg is 1200 pixels wide\. A photo added to a gallery must be at least 1600 pixels wide/;
	return { ok: t.sent.status === 303 && must.test( t.said ) && t.quiet && t.page.text.includes( `data-id="${ narrow }"` ), got: `${ t.sent.status }; said: "${ t.said.match( must )?.[0] ?? t.said.slice( 0, 100 ) }"; database ${ t.quiet ? 'unchanged' : 'CHANGED' }; what was sent is still on the form` };
} );
await row( 'photos.foreign-ka', 'the same refusal reads in Georgian for a Georgian partner, and shows nothing of the first partner\'s photo', async () => {
	const form = formTicked( ( await partnerB.request( carUrl( theirs.id ) ) ).text, 'glc_partner_save_photos' );
	const t = await tryCar( partnerB, form.fields, { glc_gallery: `${ form.fields.glc_gallery },${ wide }` }, theirs );
	return { ok: t.sent.status === 303 && t.said.includes( ka.not_saved ) && t.said.includes( ka.err_photo_foreign.replace( '%1$d', String( wide ) ) ) && t.quiet && ! t.page.text.includes( 'glc-test-wide' ), got: `${ t.sent.status }; said: "${ t.said.slice( 0, 120 ) }"; database ${ t.quiet ? 'unchanged' : 'CHANGED' }` };
} );
await row( 'specs.outside-the-list', 'a details form that also carries a plate, a year, a title, a headline price, a partner and a phone, inside the form\'s field and beside it: none of them is read, and with nothing else changed nothing is written', async () => {
	const t = await tryCar( partner, ownForms.specs.fields, { 'glc_spec[glc_year]': '1999', 'glc_spec[glc_registration]': 'XX-000-XX', 'glc_spec[post_title]': 'Hacked', 'glc_spec[glc_fuel_economy_note]': 'hacked', 'glc_spec[glc_tyres_note]': 'hacked', 'glc_spec[glc_price_from]': '1', 'glc_spec[glc_partner_id]': String( fx.accounts.owner.id ), glc_year: '1999', glc_registration: 'XX-000-XX', post_title: 'Hacked', post_content: 'Hacked', post_name: 'hacked', post_status: 'draft', glc_price_from: '1', glc_partner_id: String( fx.accounts.owner.id ), glc_whatsapp_number: '+10000000000', glc_seo_title_en: 'Hacked', car_brand: 'Hacked' } );
	return { ok: t.sent.status === 303 && t.said.includes( en.saved_unchanged ) && t.quiet, got: `${ t.sent.status }; said: "${ t.said.slice( 0, 90 ) }"; title, text, plate, year, headline price, details, revision and log ${ t.quiet ? 'exactly as before' : 'CHANGED' }` };
} );
await row( 'specs.bad-values', 'thirteen seats, a gearbox the settings do not list, a drive system in small letters, a colour and a licence category that are too long, an odometer below zero, a check date still to come and a service with it: each is named, nothing is saved, and what was typed stays', async () => {
	const ahead = new Date( Date.now() + 2 * 86400000 ).toISOString().slice( 0, 10 );
	const t = await tryCar( partner, ownForms.specs.fields, { 'glc_spec[glc_seats]': '13', 'glc_spec[glc_transmission]': 'cvt', 'glc_spec[glc_drivetrain]': 'awd', 'glc_spec[glc_color]': 'A colour name that is far too long', 'glc_spec[glc_license_category]': 'B+E!!', 'glc_spec[glc_odometer_km]': '-5', 'glc_spec[glc_evidence_checked_on]': ahead, 'glc_spec[glc_last_service_on]': '2026-01-15' } );
	const names = [ en.spec_seats, en.spec_transmission, en.spec_drivetrain, en.spec_color, en.spec_license_category, en.spec_odometer_km, en.spec_evidence_checked_on, en.spec_last_service_on ];
	const missing = names.filter( ( name ) => ! t.said.includes( `${ name }: ` ) );
	const kept = [ 'value="13"', 'value="awd"', 'value="-5"', `value="${ ahead }"` ].filter( ( v ) => ! t.page.text.includes( v ) );
	return { ok: t.sent.status === 303 && t.said.includes( en.not_saved ) && missing.length === 0 && kept.length === 0 && t.quiet, got: `${ t.sent.status }; ${ names.length - missing.length }/${ names.length } fields named${ missing.length ? ' (missing ' + missing.join( ', ' ) + ')' : '' }, for example "${ t.said.match( /Drive system: [^.]*\./ )?.[0] ?? '' }"; typed values ${ kept.length ? 'LOST: ' + kept.join( ' ' ) : 'kept' }; database ${ t.quiet ? 'unchanged' : 'CHANGED' }` };
} );

/* ------------------------------------------------- the panel language: refusals */

const langForm = ( html ) => { const f = html.match( /<form[^>]*class="glc-panel glc-lang-switch"[\s\S]*?<\/form>/ )?.[0] ?? ''; return { html: f, fields: fieldsOf( f ), options: [ ...f.matchAll( /<option value="([^"]*)"[^>]*>([^<]*)/g ) ].map( ( m ) => `${ m[1] }=${ decode( m[2] ) }` ) }; };
const partnerLang = langForm( mineScreen.text );
await row( 'lang.switch-shown', 'every screen of the panel carries the language switch, each language named in itself, the reader\'s own one chosen', async () => {
	const pages = [ [ partner, '/wp-admin/admin.php?page=glc-partner' ], [ partner, carUrl( mine.id ) ], [ partner, PROFILE ], [ partner, '/wp-admin/admin.php?page=glc-partner-bulk' ], [ partner, '/wp-admin/admin.php?page=glc-partner-excel' ], [ owner, PARTNERS ], [ owner, '/wp-admin/edit.php?post_type=glc_change' ], [ owner, '/wp-admin/edit.php?post_type=car&page=glc-partner' ] ];
	const missing = [];
	for ( const [ session, path ] of pages ) {
		const f = langForm( ( await session.request( path ) ).text );
		if ( f.options.join( ',' ) !== 'en=English,ka=ქართული' || f.fields.glc_panel_lang !== 'en' || f.fields.action !== 'glc_panel_lang' ) missing.push( path );
	}
	const elsewhere = await owner.request( '/wp-admin/edit.php?post_type=car' );
	const kaForm = langForm( ( await partnerB.request( carUrl( theirs.id ) ) ).text );
	return { ok: missing.length === 0 && ! elsewhere.text.includes( 'glc-lang-switch' ) && kaForm.fields.glc_panel_lang === 'ka', got: missing.length ? `MISSING on ${ missing.join( ', ' ) }` : `${ pages.length } screens carry it (${ partnerLang.options.join( ', ' ) }); the Georgian partner's shows ქართული chosen; WordPress's own Cars list does not carry it` };
} );
for ( const [ kase, what, session, fields ] of [
	[ 'anonymous', 'nobody signed in', anon, { ...partnerLang.fields, glc_panel_lang: 'ka' } ],
	[ 'no-nonce', 'the partner without a nonce', partner, without( { ...partnerLang.fields, glc_panel_lang: 'ka' }, '_wpnonce' ) ],
	[ 'forged-nonce', 'the partner with a made-up nonce', partner, { ...partnerLang.fields, glc_panel_lang: 'ka', _wpnonce: '0123456789' } ],
	[ 'other-person-nonce', 'another partner replaying the partner\'s request', partnerB, { ...partnerLang.fields, glc_panel_lang: 'en' } ],
	[ 'no-such-language', 'a language the panel does not have', partner, { ...partnerLang.fields, glc_panel_lang: 'xx' } ],
	[ 'names-a-user', 'a request that names another account and a language it does not have', partner, { ...partnerLang.fields, glc_panel_lang: 'xx', user_id: fx.accounts.owner.id, user: fx.accounts.partner_c.id } ],
] ) {
	await row( `handler.panel-lang.${ kase }`, `language switch: ${ what } is refused`, async () => {
		const r = await session.request( ADMIN_POST, { method: 'POST', form: fields } );
		return { ok: refusedBlind( r ), got: `${ r.status }` };
	} );
}

/* --------------------------------------------- the partner's profile: refusals */

const profileScreen = await partner.request( PROFILE );
const profileForm   = formFor( profileScreen.text, 'glc_partner_save_profile' );
await row( 'profile.screen', 'a partner opens their profile: name, surname, phone; the owner and a stranger do not get this screen', async () => {
	const o = await owner.request( PROFILE );
	const a = await anon.request( PROFILE );
	const fields = [ 'first_name', 'last_name', 'glc_partner_phone' ].filter( ( f ) => profileForm && f in profileForm.fields );
	return { ok: profileScreen.status === 200 && fields.length === 3 && profileForm.fields.glc_partner_phone === fx.accounts.partner.phone && denied( o ) && a.status === 302, got: `partner ${ profileScreen.status } with ${ fields.length }/3 fields, phone ${ profileForm?.fields.glc_partner_phone === fx.accounts.partner.phone ? 'shown' : 'WRONG' }; owner ${ o.status }; anonymous ${ a.status }` };
} );
for ( const [ kase, what, session, fields ] of [
	[ 'anonymous', 'nobody signed in', anon, profileForm.fields ],
	[ 'no-nonce', 'the partner without a nonce', partner, without( profileForm.fields, '_wpnonce' ) ],
	[ 'forged-nonce', 'the partner with a made-up nonce', partner, { ...profileForm.fields, _wpnonce: '0123456789' } ],
	[ 'other-person-nonce', 'another partner replaying this partner\'s form', partnerB, profileForm.fields ],
	[ 'owner', 'the owner (not a partner) replaying it', owner, profileForm.fields ],
] ) {
	await row( `handler.save-profile.${ kase }`, `save-profile: ${ what } is refused`, async () => {
		const r = await session.request( ADMIN_POST, { method: 'POST', form: { ...fields, first_name: 'Hacked', glc_partner_phone: '+995555999999' } } );
		return { ok: refusedBlind( r ), got: `${ r.status }` };
	} );
}
await row( 'profile.bad-phone', 'a phone that is not a phone is not saved, the screen says how to write one, and what was typed stays in the form', async () => {
	const got = [];
	let kept = true, said = true;
	for ( const phone of [ '12345', '995555000101', '+99555', '+9955550001011234567', 'call me' ] ) {
		const { sent, page } = await send( partner, { ...profileForm.fields, first_name: 'Typed Name', glc_partner_phone: phone } );
		const back = formFor( page?.text ?? '', 'glc_partner_save_profile' );
		kept = kept && back?.fields.glc_partner_phone === phone && back?.fields.first_name === 'Typed Name';
		said = said && notices( page?.text ?? '' ).some( ( n ) => n.includes( en.err_phone ) );
		got.push( sent.status );
	}
	return { ok: got.every( ( s ) => s === 303 ) && kept && said, got: `${ got.join( ', ' ) }; typed values ${ kept ? 'kept' : 'LOST' }; reason ${ said ? 'given' : 'MISSING' }` };
} );

/* ----------------------------------------- the owner's Partners screen: refusals */

const partnersScreen = await owner.request( PARTNERS );
const assignForm     = formFor( partnersScreen.text, 'glc_partners_assign' );
await row( 'partners.screen', 'the owner opens the Partners screen: every car with its partner, the partners, the count; a stranger is sent to log in', async () => {
	const a = await anon.request( PARTNERS );
	const main = wrap( partnersScreen.text );
	const cars = Object.keys( assignForm?.fields ?? {} ).filter( ( k ) => k.startsWith( 'glc_assign[' ) ).length;
	return { ok: partnersScreen.status === 200 && cars >= 19 && assignForm.fields[ `glc_assign[${ mine.id }]` ] === String( fx.accounts.partner.id ) && assignForm.fields[ `glc_assign[${ other.id }]` ] === '0' && main.includes( fx.accounts.partner.phone ) && main.includes( en.count_heading ) && a.status === 302, got: `${ partnersScreen.status }, ${ cars } cars, car #${ mine.id } → #${ assignForm?.fields[ `glc_assign[${ mine.id }]` ] }, car #${ other.id } → #${ assignForm?.fields[ `glc_assign[${ other.id }]` ] }; anonymous ${ a.status }` };
} );
const grab = { ...assignForm.fields, [ `glc_assign[${ other.id }]` ]: String( fx.accounts.partner.id ) };
for ( const [ kase, what, session, fields ] of [
	[ 'anonymous', 'nobody signed in', anon, grab ],
	[ 'partner', 'a partner replaying the owner\'s form to give themselves a car', partner, grab ],
	[ 'partner-own-nonce', 'a partner sending it with a nonce of their own', partner, { ...grab, _wpnonce: ownForms.pricing.fields._wpnonce } ],
	[ 'owner-no-nonce', 'the owner without a nonce', owner, without( grab, '_wpnonce' ) ],
	[ 'owner-forged-nonce', 'the owner with a made-up nonce', owner, { ...grab, _wpnonce: '0123456789' } ],
] ) {
	await row( `handler.assign.${ kase }`, `assign: ${ what } is refused`, async () => {
		const r = await session.request( ADMIN_POST, { method: 'POST', form: fields } );
		return { ok: r.status === 403 && r.text.includes( en.panel_refused ), got: `${ r.status }` };
	} );
}
await row( 'handler.assign.not-a-partner', 'assign: the owner cannot give a car to an account that is not a partner, or "assign" something that is not a car; nothing of the request is written', async () => {
	const a = await send( owner, { ...assignForm.fields, [ `glc_assign[${ other.id }]` ]: String( fx.accounts.owner.id ), [ `glc_assign[${ legacy.id }]` ]: String( fx.accounts.partner.id ) } );
	const b = await send( owner, { ...assignForm.fields, [ `glc_assign[${ fx.booking_request }]` ]: String( fx.accounts.partner.id ), [ `glc_was[${ fx.booking_request }]` ]: '0' } );
	const c = await send( owner, { ...assignForm.fields, [ `glc_assign[${ other.id }]` ]: 'everyone' } );
	const said = [ a, b, c ].map( ( x ) => notices( x.page?.text ?? '' ).join( ' | ' ) );
	return { ok: [ a, b, c ].every( ( x ) => x.sent.status === 303 ) && said.every( ( s ) => s.includes( en.not_saved ) ), got: said.map( ( s ) => `"${ s.replace( en.not_saved, '' ).trim().slice( 0, 60 ) }"` ).join( ', ' ) };
} );
await row( 'handler.assign.stale', 'assign: a row changed from a page that shows an older assignment stops the whole save', async () => {
	const { sent, page } = await send( owner, { ...assignForm.fields, [ `glc_assign[${ mine.id }]` ]: '0', [ `glc_was[${ mine.id }]` ]: String( fx.accounts.partner_b.id ), [ `glc_assign[${ other.id }]` ]: String( fx.accounts.partner.id ) } );
	const said = notices( page?.text ?? '' ).join( ' | ' );
	return { ok: sent.status === 303 && said.includes( mine.title ) && said.includes( en.not_saved ), got: `${ sent.status }; said: "${ said.slice( 0, 110 ) }"` };
} );
await row( 'media.owner-user-screen', 'the owner finds a partner\'s phone on that partner\'s WordPress user screen; a partner cannot open another person\'s', async () => {
	const o = await owner.request( `/wp-admin/user-edit.php?user_id=${ fx.accounts.partner.id }` );
	const p = await partner.request( `/wp-admin/user-edit.php?user_id=${ fx.accounts.partner_b.id }` );
	const shown = o.text.match( /name="glc_partner_phone" value="([^"]*)"/ )?.[1] ?? null;
	return { ok: o.status === 200 && shown === fx.accounts.partner.phone && refusedByCore( p, 'id="your-profile"' ), got: `owner ${ o.status }, phone ${ shown === fx.accounts.partner.phone ? 'shown' : 'MISSING' }; partner ${ p.status }` };
} );

/* ------------------------- several cars at once and the Excel file: who may, and every way to be refused */

const BULK = '/wp-admin/admin.php?page=glc-partner-bulk', XLSX = '/wp-admin/admin.php?page=glc-partner-excel';
const OWNER_BULK = '/wp-admin/edit.php?post_type=car&page=glc-partner-bulk', OWNER_XLSX = '/wp-admin/edit.php?post_type=car&page=glc-partner-excel';
const fleetC = fx.fleet_c, idsC = fleetC.map( ( car ) => car.id );
const notC = [ mine, other, theirs, legacy ];
/** The ids ticked-able on a bulk screen, and the plan a page shows (its apply form, its cars and their state). */
const picksOf = ( html ) => [ ...html.matchAll( /class="glc-pick"[^>]*\bvalue="(\d+)"/g ) ].map( ( m ) => Number( m[1] ) );
const previewOf2 = ( html ) => ( { cars: Object.fromEntries( [ ...html.matchAll( /data-car="(\d+)" data-state="(\w+)"/g ) ].map( ( m ) => [ m[1], m[2] ] ) ), faults: [ ...html.matchAll( /data-faults="(\d+)"/g ) ].map( ( m ) => Number( m[1] ) ), cells: [ ...( html.match( /<table class="widefat striped glc-cells"[\s\S]*?<\/table>/ )?.[0] ?? '' ).matchAll( /<th scope="row">([^<]+)<\/th>/g ) ].map( ( m ) => decode( m[1] ) ), stops: [ ...( html.match( /<div class="notice notice-error inline glc-stops"[\s\S]*?<\/div>/ )?.[0] ?? '' ).matchAll( /<li>([\s\S]*?)<\/li>/g ) ].map( ( m ) => decode( m[1] ) ), text: decode( ( html.match( /<div id="glc-preview"[\s\S]*?<div id="wpfooter"/ )?.[0] ?? '' ).replace( /<[^>]+>/g, ' ' ).replace( /\s+/g, ' ' ) ) } );
/** The bulk form's fields as its script would send them: the hidden ones from the page, the rest as given. */
const bulkFields = ( html, over ) => ( { action: 'glc_bulk_preview', _wpnonce: formFor( html, 'glc_bulk_preview' ).fields._wpnonce, glc_table: '', glc_cars: '', glc_mode: 'replace', glc_factor: '', glc_delta: '', ...over } );
/** The nonce of the upload form, which posts to the screen's own address and so carries no action field. */
const uploadNonce = ( html ) => html.match( /<form[^>]*class="glc-xlsx-upload"[\s\S]*?name="_wpnonce" value="([a-f0-9]+)"/ )?.[1] ?? '';
/** Send a file to the Excel screen as a browser's file field does, and load the page it answers with. */
async function uploadTo( session, path, nonce, bytes, name = 'prices.xlsx' ) {
	const body = new FormData();
	if ( nonce !== null ) body.set( '_wpnonce', nonce );
	body.set( 'glc_file', new Blob( [ bytes ] ), name );
	const sent = await session.request( path, { method: 'POST', body } );
	const page = sent.status === 303 && sent.location ? await session.request( sent.location.replace( base, '' ) ) : null;
	return { sent, page };
}
/** The price file an account would download for these cars, with cells changed: made by the plugin's own writer. */
const fileFor = async ( login, ids, ...edits ) => Buffer.from( ( await wp( 'xlsx', login, ids.join( ',' ), ...edits ) ).file, 'base64' );

await row( 'login.partner-c', 'the partner with three cars signs in', async () => {
	const r = await partnerC.login( fx.accounts.partner_c );
	return { ok: r.status === 302 && r.location.endsWith( '/wp-admin/admin.php?page=glc-partner' ), got: `${ r.status } → ${ r.location }` };
} );
const bulkPage = await partnerC.request( BULK );
const xlsxPage = await partnerC.request( XLSX );
await row( 'bulk.screen', 'the bulk screen offers a person exactly their own cars: three, one, none, and all 19 to the owner; a stranger is sent to log in', async () => {
	const three = picksOf( bulkPage.text ), one = picksOf( ( await partner.request( BULK ) ).text ), none = await partnerKa.request( BULK ), all = picksOf( ( await owner.request( OWNER_BULK ) ).text ), out = await anon.request( BULK );
	const leaked = notC.filter( ( car ) => bulkPage.text.includes( car.plate ) || wrap( bulkPage.text ).includes( car.title ) );
	return { ok: bulkPage.status === 200 && JSON.stringify( [ ...three ].sort( ( a, b ) => a - b ) ) === JSON.stringify( [ ...idsC ].sort( ( a, b ) => a - b ) ) && leaked.length === 0 && JSON.stringify( one ) === JSON.stringify( [ mine.id ] ) && none.status === 200 && picksOf( none.text ).length === 0 && ! none.text.includes( 'glc_bulk_preview' ) && wrap( none.text ).includes( ka.cars_empty ) && all.length === 19 && out.status === 302 && out.location.includes( '/wp-login.php' ), got: `${ bulkPage.status }, cars offered: ${ three.length } (#${ three.join( ', #' ) }), other people's plates ${ leaked.length ? 'PRESENT' : 'absent' }; one-car partner ${ one.length }; no-car partner ${ picksOf( none.text ).length } and no form; owner ${ all.length }; anonymous ${ out.status }` };
} );
await row( 'xlsx.screen', 'the Excel screen: download, upload and the size limit from the settings; a stranger is sent to log in', async () => {
	const out = await anon.request( XLSX ), own = await owner.request( OWNER_XLSX );
	const leaked = notC.filter( ( car ) => xlsxPage.text.includes( car.plate ) || wrap( xlsxPage.text ).includes( car.title ) );
	return { ok: xlsxPage.status === 200 && !! formFor( xlsxPage.text, 'glc_xlsx_download' ) && uploadNonce( xlsxPage.text ) !== '' && xlsxPage.text.includes( 'data-max-bytes="102400"' ) && visible( xlsxPage.text ).includes( '100 KB' ) && leaked.length === 0 && own.status === 200 && out.status === 302, got: `${ xlsxPage.status }, download form ${ formFor( xlsxPage.text, 'glc_xlsx_download' ) ? 'present' : 'MISSING' }, upload form ${ uploadNonce( xlsxPage.text ) ? 'present' : 'MISSING' }, limit shown: ${ visible( xlsxPage.text ).match( /largest file accepted is [\d,]+ KB/ )?.[0] ?? 'none' }; owner ${ own.status }; anonymous ${ out.status }` };
} );
await row( 'bulk.from', 'starting from a car\'s table: one\'s own car fills the form; someone else\'s car, a car that is not there or a word gets the one refusal', async () => {
	const own = await partnerC.request( `${ BULK }&from=${ idsC[0] }` );
	const rows = ( wrap( own.text ).replace( /<template[\s\S]*?<\/template>/g, '' ).match( /<tr class="glc-season/g ) ?? [] ).length;
	const bad = [];
	for ( const from of [ other.id, theirs.id, mine.id, 99999999, 'abc', '' ] ) bad.push( await partnerC.request( `${ BULK }&from=${ from }` ) );
	const arr = await partnerC.request( `${ BULK }&from[]=${ idsC[0] }` );
	return { ok: own.status === 200 && rows === 9 && bad.every( refusedBlind ) && refusedBlind( arr ), got: `own ${ own.status } with ${ rows } season rows; others ${ bad.map( ( r ) => r.status ).join( ', ' ) }, ${ arr.status }` };
} );

const planCount = async () => ( await inspect() ).plans;
const bulkValid = bulkFields( bulkPage.text, { glc_cars: idsC.join( ',' ), glc_use_pricing: '1', glc_mode: 'adjust', glc_factor: '0.98', glc_round: '1' } );
const downloadForm = formFor( xlsxPage.text, 'glc_xlsx_download' );
const plansAtStart = await planCount();
for ( const [ kase, what, session, fields ] of [
	[ 'anonymous', 'nobody signed in', anon, bulkValid ],
	[ 'no-nonce', 'the partner, own cars, no nonce', partnerC, without( bulkValid, '_wpnonce' ) ],
	[ 'forged-nonce', 'the partner, own cars, a made-up nonce', partnerC, { ...bulkValid, _wpnonce: '0123456789' } ],
	[ 'other-action-nonce', 'the partner, own cars, the nonce of the download form', partnerC, { ...bulkValid, _wpnonce: downloadForm.fields._wpnonce } ],
	[ 'other-person-nonce', 'another partner replaying this partner\'s whole request', partnerB, bulkValid ],
	[ 'owner-with-their-nonce', 'the owner replaying it with the partner\'s nonce', owner, bulkValid ],
	[ 'one-foreign-car', 'the partner with Geolander\'s own car among their three', partnerC, { ...bulkValid, glc_cars: [ idsC[0], other.id, idsC[1], idsC[2] ].join( ',' ) } ],
	[ 'one-theirs-car', 'the partner with another partner\'s car among their three', partnerC, { ...bulkValid, glc_cars: [ ...idsC, theirs.id ].join( ',' ) } ],
	[ 'not-a-car', 'the partner with a number that is no car among their three', partnerC, { ...bulkValid, glc_cars: [ ...idsC, 99999999 ].join( ',' ) } ],
	[ 'cars-as-list', 'the cars sent as a list of fields', partnerC, [ ...Object.entries( without( bulkValid, 'glc_cars' ) ), ...idsC.map( ( id ) => [ 'glc_cars[]', id ] ) ] ],
	[ 'cars-odd', 'the cars written as anything but numbers and commas', partnerC, { ...bulkValid, glc_cars: `${ idsC[0] };${ other.id } OR 1=1` } ],
] ) {
	await row( `handler.bulk-preview.${ kase }`, `bulk preview: ${ what } is refused, learns nothing, and no plan is kept`, async () => {
		const r = await session.request( ADMIN_POST, { method: 'POST', form: fields } );
		const plans = await planCount();
		return { ok: refusedBlind( r ) && plans === plansAtStart, got: `${ r.status }${ r.text.includes( en.panel_refused ) || r.text.includes( ka.panel_refused ) ? '' : ' (not the panel\'s refusal)' }, plans waiting ${ plansAtStart } → ${ plans }` };
	} );
}

/** Two real previews of the partner's own (kept, not applied): the apply handler is then asked in every wrong way. */
const planA = await send( partnerC, bulkValid );
const planB = await send( partnerC, { ...bulkValid, glc_factor: '', glc_delta: '1' } );
const applyA = formFor( planA.page.text, 'glc_bulk_apply' ), applyB = formFor( planB.page.text, 'glc_bulk_apply' );
const xlsxPlan = await uploadTo( partnerC, XLSX, uploadNonce( xlsxPage.text ), await fileFor( fx.accounts.partner_c.login, idsC, 'Prices!H2=61' ) );
const applyX = formFor( xlsxPlan.page.text, 'glc_xlsx_apply' );
for ( const [ name, valid, otherPlan, crossPlan ] of [ [ 'bulk-apply', applyA.fields, applyB.fields, applyX.fields ], [ 'xlsx-apply', applyX.fields, applyA.fields, applyA.fields ] ] ) {
	for ( const [ kase, what, session, fields ] of [
		[ 'anonymous', 'nobody signed in', anon, valid ],
		[ 'no-nonce', 'the partner, own preview, no nonce', partnerC, without( valid, '_wpnonce' ) ],
		[ 'forged-nonce', 'the partner, own preview, a made-up nonce', partnerC, { ...valid, _wpnonce: '0123456789' } ],
		[ 'other-action-nonce', 'the partner, own preview, the nonce of the preview form', partnerC, { ...valid, _wpnonce: bulkValid._wpnonce } ],
		[ 'other-plan-nonce', 'the partner, own preview, the nonce of another preview of theirs', partnerC, { ...valid, _wpnonce: otherPlan._wpnonce } ],
		[ 'other-screen-plan', 'the partner applying a plan of the other screen through this handler', partnerC, { ...crossPlan, action: valid.action } ],
		[ 'other-person', 'another partner replaying the whole request', partnerB, valid ],
		[ 'owner-replay', 'the owner replaying it with the partner\'s nonce', owner, valid ],
		[ 'no-plan', 'the partner naming no preview', partnerC, without( valid, 'plan' ) ],
		[ 'plan-as-list', 'the preview named as a list', partnerC, [ ...Object.entries( without( valid, 'plan' ) ), [ 'plan[]', valid.plan ] ] ],
	] ) {
		await row( `handler.${ name }.${ kase }`, `${ name }: ${ what } is refused and learns nothing`, async () => {
			const r = await session.request( ADMIN_POST, { method: 'POST', form: fields } );
			return { ok: refusedBlind( r ), got: `${ r.status }${ r.text.includes( en.panel_refused ) || r.text.includes( ka.panel_refused ) ? '' : ' (not the panel\'s refusal)' }` };
		} );
	}
}
for ( const [ kase, what, session, fields ] of [
	[ 'anonymous', 'nobody signed in', anon, downloadForm.fields ],
	[ 'no-nonce', 'the partner, no nonce', partnerC, without( downloadForm.fields, '_wpnonce' ) ],
	[ 'forged-nonce', 'the partner, a made-up nonce', partnerC, { ...downloadForm.fields, _wpnonce: '0123456789' } ],
	[ 'other-action-nonce', 'the partner, the nonce of the bulk form', partnerC, { ...downloadForm.fields, _wpnonce: bulkValid._wpnonce } ],
	[ 'other-person', 'another partner replaying the request', partnerB, downloadForm.fields ],
] ) {
	await row( `handler.xlsx-download.${ kase }`, `Excel download: ${ what } gets no file`, async () => {
		const r = await session.request( ADMIN_POST, { method: 'POST', form: fields } );
		return { ok: refusedBlind( r ) && ! r.text.startsWith( 'PK' ), got: `${ r.status }${ r.text.startsWith( 'PK' ) ? ' AND A FILE' : '' }` };
	} );
}
await row( 'handler.xlsx-download.names-no-car', 'Excel download: a request that names other people\'s cars still gets the person\'s own three and nothing else', async () => {
	const r = await fetch( base + ADMIN_POST, { method: 'POST', redirect: 'manual', headers: { cookie: [ ...partnerC.cookies ].map( ( [ k, v ] ) => `${ k }=${ v }` ).join( '; ' ), 'content-type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams( [ ...Object.entries( downloadForm.fields ), [ 'car', other.id ], [ 'cars', `${ other.id },${ theirs.id }` ], [ 'glc_cars', String( other.id ) ], [ 'car[]', theirs.id ], [ 'user_id', fx.accounts.owner.id ] ] ).toString() } );
	const bytes = Buffer.from( await r.arrayBuffer() );
	const book = await wp( 'xlsx-read', bytes.toString( 'base64' ) );
	const cars = ( name ) => [ ...new Set( Object.entries( book.sheets?.[ name ] ?? {} ).filter( ( [ n ] ) => n !== '1' ).map( ( [ , cells ] ) => Number( cells.A?.[1] ) ) ) ].sort( ( a, b ) => a - b );
	const want = JSON.stringify( [ ...idsC ].sort( ( a, b ) => a - b ) );
	return { ok: r.status === 200 && bytes.subarray( 0, 2 ).toString() === 'PK' && /attachment; filename="geolander-prices-[a-z0-9_-]+-\d{4}-\d\d-\d\d\.xlsx"/.test( r.headers.get( 'content-disposition' ) ?? '' ) && [ 'Prices', 'Fees', 'Meta' ].every( ( name ) => JSON.stringify( cars( name ) ) === want ), got: `${ r.status }, a file of ${ bytes.length } bytes; cars in Prices #${ cars( 'Prices' ).join( ', #' ) }, in Fees #${ cars( 'Fees' ).join( ', #' ) }, in Meta #${ cars( 'Meta' ).join( ', #' ) }` };
} );
const goodFile = await fileFor( fx.accounts.partner_c.login, idsC, 'Prices!H2=62' );
for ( const [ kase, what, session, nonce, want ] of [
	[ 'anonymous', 'nobody signed in is sent to log in', anon, uploadNonce( xlsxPage.text ), ( r ) => r.status === 302 && r.location.includes( '/wp-login.php' ) ],
	[ 'no-nonce', 'the partner without a nonce is refused', partnerC, null, refusedBlind ],
	[ 'forged-nonce', 'the partner with a made-up nonce is refused', partnerC, '0123456789', refusedBlind ],
	[ 'other-action-nonce', 'the partner with the nonce of the download form is refused', partnerC, downloadForm.fields._wpnonce, refusedBlind ],
	[ 'other-person-nonce', 'another partner sending this partner\'s nonce is refused', partnerB, uploadNonce( xlsxPage.text ), refusedBlind ],
] ) {
	await row( `handler.xlsx-upload.${ kase }`, `Excel upload: ${ what }, and no plan is kept`, async () => {
		const before = await planCount();
		const { sent } = await uploadTo( session, XLSX, nonce, goodFile );
		const after = await planCount();
		return { ok: want( sent ) && after === before, got: `${ sent.status }, plans waiting ${ before } → ${ after }` };
	} );
}
for ( const [ kase, what, session, login, ids, where ] of [
	[ 'not-theirs', 'another partner uploading this partner\'s own file with a nonce of their own', partnerB, fx.accounts.partner_c.login, idsC, null ],
	[ 'one-foreign-car', 'the partner uploading a file with Geolander\'s own car among their three', partnerC, fx.accounts.owner.login, [ ...idsC, other.id ], other.id ],
	[ 'one-theirs-car', 'the partner uploading a file with another partner\'s car among their three', partnerC, fx.accounts.owner.login, [ ...idsC, theirs.id ], theirs.id ],
] ) {
	await row( `handler.xlsx-upload.${ kase }`, `Excel upload: ${ what } — the whole file is refused, the cell is named, nothing can be applied`, async () => {
		const page = await session.request( XLSX );
		const made = await wp( 'xlsx', login, ids.join( ',' ), 'Prices!H2=63' );
		const { sent, page: shown } = await uploadTo( session, XLSX, uploadNonce( page.text ), Buffer.from( made.file, 'base64' ) );
		const seen = previewOf2( shown?.text ?? '' );
		const cell = where === null ? 'Prices!A2' : `Prices!A${ made.rows.findIndex( ( r ) => r[0] === where ) + 1 }`;
		const strangers = ( session === partnerB ? fleetC : [ other, theirs ] ).filter( ( car ) => ( shown?.text ?? '' ).includes( car.plate ) || wrap( shown?.text ?? '' ).includes( car.title ) );
		return { ok: sent.status === 303 && seen.stops.some( ( s ) => s.startsWith( cell + ':' ) && /is not one of the cars you can change here|არ არის იმ ავტომობილებს შორის/.test( s ) ) && ! formFor( shown.text, 'glc_xlsx_apply' ) && Object.keys( seen.cars ).length === 0 && strangers.length === 0, got: `${ sent.status }; said: "${ ( seen.stops.find( ( s ) => s.startsWith( cell ) ) ?? seen.stops[0] ?? 'nothing' ).slice( 0, 110 ) }"; apply button ${ shown && formFor( shown.text, 'glc_xlsx_apply' ) ? 'PRESENT' : 'absent' }; the other cars' names or plates on the page: ${ strangers.length ? 'PRESENT' : 'none' }` };
	} );
}
await row( 'handler.bulk-xlsx.get', 'the four handlers answer a plain link (GET) with the one refusal: no preview, no apply, no file', async () => {
	const got = [];
	for ( const [ action, more ] of [ [ 'glc_bulk_preview', `&glc_cars=${ idsC.join( ',' ) }&glc_use_pricing=1&glc_mode=adjust&glc_factor=0.5&_wpnonce=${ bulkValid._wpnonce }` ], [ 'glc_bulk_apply', `&plan=${ applyA.fields.plan }&_wpnonce=${ applyA.fields._wpnonce }` ], [ 'glc_xlsx_apply', `&plan=${ applyX.fields.plan }&_wpnonce=${ applyX.fields._wpnonce }` ], [ 'glc_xlsx_download', `&_wpnonce=${ downloadForm.fields._wpnonce }` ] ] ) got.push( await partnerC.request( `${ ADMIN_POST }?action=${ action }${ more }` ) );
	return { ok: got.every( refusedBlind ), got: got.map( ( r ) => r.status ).join( ', ' ) };
} );
await row( 'plan.other-person', 'a preview is its maker\'s: another partner, or the owner, opening its address sees no car and no button', async () => {
	const seen = [];
	for ( const [ session, path ] of [ [ partnerB, `${ BULK }&plan=${ applyA.fields.plan }` ], [ owner, `${ OWNER_BULK }&plan=${ applyA.fields.plan }` ], [ partnerB, `${ XLSX }&plan=${ applyX.fields.plan }` ] ] ) {
		const r = await session.request( path );
		seen.push( r.status === 200 && Object.keys( previewOf2( r.text ).cars ).length === 0 && ! /glc_(bulk|xlsx)_apply/.test( r.text ) && ( session === owner || fleetC.every( ( car ) => ! r.text.includes( car.plate ) ) ) );
	}
	return { ok: seen.every( Boolean ), got: seen.map( ( ok ) => ( ok ? 'nothing shown' : 'SHOWN' ) ).join( ', ' ) };
} );

/* ---------------------------------------------- the database, before and after */

if ( uploaded.length ) await wp( 'cleanup', ...uploaded.map( String ) );
const after = await wp( 'facts' );
const differs = ( a, b ) => Object.keys( a ).filter( ( key ) => JSON.stringify( a[ key ] ) !== JSON.stringify( b[ key ] ) );
await row( 'database.unchanged', 'after every attempt above, the cars, settings, roles, users, posts and other people\'s media are exactly as before', async () => {
	const moved = differs( before, after );
	return { ok: moved.length === 0, got: moved.length ? `CHANGED: ${ moved.join( ', ' ) }` : `no difference in ${ Object.keys( before ).length } fingerprints` };
} );

/* ------------------- the owner's own writes, last so the check above stays strict */

await row( 'rest.owner-meta', 'the owner cannot change a car\'s details over REST either: each is saved through the store or not at all, so the whole request is refused with a sentence, the title it carried included', async () => {
	const tries = [];
	for ( const meta of [ { glc_color: 'REST blue' }, { glc_transmission: 'anything' }, { glc_fuel_type: 'coal' }, { glc_seats: 9 }, { glc_drivetrain: 'RWD' }, { glc_license_category: 'ZZ' }, { glc_available: false } ] ) {
		tries.push( await owner.rest( `/wp/v2/car/${ other.id }`, { method: 'POST', json: { title: 'REST title', meta } } ) );
	}
	const now = await wp( 'facts' );
	const moved = differs( after, now );
	return { ok: tries.every( ( r ) => r.status === 403 && r.data?.code === 'glc_store_only' && r.data?.message === en.err_store_only ) && moved.length === 0, got: `${ tries.map( code ).join( ', ' ) }; "${ String( tries[0].data?.message ?? '' ).slice( 0, 60 ) }…"; in the database: ${ moved.length ? 'CHANGED: ' + moved.join( ', ' ) : `no difference in ${ Object.keys( after ).length } fingerprints (the title and the modified time too)` }` };
} );
await row( 'rest.owner-photos', 'nor its gallery or its main photo: the gallery in another order, an empty gallery, another picture as featured image and no featured image are each refused whole', async () => {
	const car = ( await inspect() ).cars[ other.id ];
	const pic = car.gallery_ids.find( ( id ) => String( id ) !== String( car.thumbnail ) );
	const tries = [];
	for ( const json of [ { meta: { glc_gallery: [ ...car.gallery_ids.slice( 1 ), car.gallery_ids[0] ] } }, { meta: { glc_gallery: [] } }, { featured_media: pic }, { featured_media: 0 } ] ) {
		tries.push( await owner.rest( `/wp/v2/car/${ other.id }`, { method: 'POST', json: { title: 'REST title', ...json } } ) );
	}
	const now = await wp( 'facts' );
	const moved = differs( after, now );
	return { ok: car.gallery_ids.length > 1 && !! pic && Number( car.thumbnail ) > 0 && tries.every( ( r ) => r.status === 403 && r.data?.code === 'glc_store_only' ) && moved.length === 0, got: `${ tries.map( code ).join( ', ' ) }; in the database: ${ moved.length ? 'CHANGED: ' + moved.join( ', ' ) : 'no difference' }` };
} );
await row( 'rest.owner-echo', 'control: a request that sends back the details, gallery and main photo the car already has is not refused; it changes none of them and no version', async () => {
	const car = ( await inspect() ).cars[ other.id ];
	const r = await owner.rest( `/wp/v2/car/${ other.id }`, { method: 'POST', json: { featured_media: Number( car.thumbnail ), meta: { glc_color: car.specs.glc_color ?? '', glc_seats: Number( car.specs.glc_seats ), glc_transmission: car.specs.glc_transmission ?? '', glc_available: car.specs.glc_available === '1', glc_gallery: car.gallery_ids } } } );
	const now = await wp( 'facts' );
	const key = `car_${ other.id }`;
	const moved = differs( after, now ).filter( ( k ) => k !== 'posts' );
	const inside = moved.includes( key ) ? differs( after[ key ], now[ key ] ) : [];
	// Saving a car stamps its modified time; nothing else on it, and nothing else anywhere, may differ.
	return { ok: r.status === 200 && moved.every( ( k ) => k === key ) && inside.every( ( k ) => k === 'modified' ), got: `${ code( r ) }; changed on the car: ${ inside.join( ', ' ) || 'nothing' }; anywhere else: ${ moved.filter( ( k ) => k !== key ).join( ', ' ) || 'nothing' }` };
} );
await row( 'rest.owner-store-meta', 'but not the store\'s meta: a revision, an assignment or a fee sent over REST changes nothing', async () => {
	const r = await owner.rest( `/wp/v2/car/${ other.id }`, { method: 'POST', json: { meta: { glc_rev: 99, glc_partner_id: fx.accounts.partner.id, glc_out_of_hours_fee: '1', glc_delivery_fees: { kutaisi_airport: '1' }, glc_pricing: [] } } } );
	const now   = await wp( 'facts' );
	const car   = `car_${ other.id }`;
	const moved = differs( after, now ).filter( ( key ) => key !== 'posts' );
	const inside = moved.includes( car ) ? differs( after[ car ], now[ car ] ) : [];
	// Saving a car stamps its modified time; nothing else on it, and nothing else anywhere, may differ.
	return { ok: moved.every( ( key ) => key === car ) && inside.every( ( key ) => key === 'modified' ) && now[ car ].rev === after[ car ].rev && now[ car ].partner === after[ car ].partner && now[ car ].meta === after[ car ].meta, got: `${ code( r ) }; in the database: revision "${ now[ car ].rev }", partner "${ now[ car ].partner }", meta ${ now[ car ].meta === after[ car ].meta ? 'unchanged' : 'CHANGED' }` };
} );

/* --- the other doors to a car's details, gallery and main photo: closed for the owner too, so nothing lands beside the store */

/** A car's details, gallery, main photo and version, as the database holds them. */
const guardedOf = ( state, id ) => JSON.stringify( [ state.cars[ id ].specs, state.cars[ id ].gallery, state.cars[ id ].thumbnail, state.cars[ id ].rev ] );
await row( 'editor.featured-ajax', 'WordPress\'s own "set featured image" and "remove featured image" requests write no main photo to a car', async () => {
	const box = metaBoxes( ( await owner.request( `/wp-admin/post.php?post=${ other.id }&action=edit` ) ).text );
	const before = await inspect();
	const car = before.cars[ other.id ];
	const pic = car.gallery_ids.find( ( id ) => String( id ) !== String( car.thumbnail ) );
	const ask = ( thumbnail ) => owner.request( '/wp-admin/admin-ajax.php', { method: 'POST', form: { action: 'set-post-thumbnail', json: '1', post_id: String( other.id ), thumbnail_id: String( thumbnail ), _wpnonce: box.fields._wpnonce } } );
	// Each request is looked at on its own: "set, then remove" must not pass because the second undid the first.
	const set = await ask( pic ), mid = await inspect(), gone = await ask( -1 );
	const now = await inspect();
	return { ok: !! box.fields._wpnonce && !! pic && Number( car.thumbnail ) > 0 && set.status === 200 && set.text.trim() === '0' && gone.text.trim() === '0' && mid.cars[ other.id ].thumbnail === car.thumbnail && now.cars[ other.id ].thumbnail === car.thumbnail && now.fleet[ other.id ].meta === before.fleet[ other.id ].meta, got: `set: ${ set.status } "${ set.text.trim().slice( 0, 30 ) }", remove: ${ gone.status } "${ gone.text.trim().slice( 0, 30 ) }" (WordPress answers 0 when it wrote nothing); main photo ${ car.thumbnail } → ${ mid.cars[ other.id ].thumbnail } → ${ now.cars[ other.id ].thumbnail }` };
} );
await row( 'editor.classic-thumbnail', 'the classic editor\'s own field for the featured image, sent with a save of the car, writes no main photo; nor does "none"', async () => {
	const box = metaBoxes( ( await owner.request( `/wp-admin/post.php?post=${ other.id }&action=edit` ) ).text );
	const before = await inspect();
	const car = before.cars[ other.id ];
	const pic = car.gallery_ids.find( ( id ) => String( id ) !== String( car.thumbnail ) );
	const set = await owner.request( box.url, { method: 'POST', form: { ...box.fields, _thumbnail_id: String( pic ) } } );
	const mid = await inspect();
	const gone = await owner.request( box.url, { method: 'POST', form: { ...box.fields, _thumbnail_id: '-1' } } );
	const now = await inspect();
	return { ok: !! pic && Number( car.thumbnail ) > 0 && [ 200, 302 ].includes( set.status ) && [ 200, 302 ].includes( gone.status ) && mid.cars[ other.id ].thumbnail === car.thumbnail && now.cars[ other.id ].thumbnail === car.thumbnail && guardedOf( now, other.id ) === guardedOf( before, other.id ) && now.change_count === before.change_count, got: `${ set.status }, ${ gone.status }; main photo ${ car.thumbnail } → ${ mid.cars[ other.id ].thumbnail } → ${ now.cars[ other.id ].thumbnail }; details, gallery and version ${ guardedOf( now, other.id ) === guardedOf( before, other.id ) ? 'unchanged' : 'CHANGED' }` };
} );
await row( 'editor.custom-fields', 'the Custom Fields box: a changed value for the row that holds a detail, and a new row for the gallery, sent with a save of the car, write nothing', async () => {
	const box = metaBoxes( ( await owner.request( `/wp-admin/post.php?post=${ other.id }&action=edit` ) ).text );
	const seats = await wp( 'meta', String( other.id ), 'glc_seats' );
	const before = await inspect();
	const sent = await owner.request( box.url, { method: 'POST', form: { ...box.fields, [ `meta[${ seats.meta_id }][key]` ]: 'glc_seats', [ `meta[${ seats.meta_id }][value]` ]: '9', metakeyinput: 'glc_gallery', metavalue: '1' } } );
	const now = await inspect();
	return { ok: seats.meta_id > 0 && [ 200, 302 ].includes( sent.status ) && guardedOf( now, other.id ) === guardedOf( before, other.id ) && now.cars[ other.id ].specs.glc_seats === seats.value && now.change_count === before.change_count, got: `${ sent.status }; seats "${ seats.value }" → "${ now.cars[ other.id ].specs.glc_seats }"; details, gallery and version ${ guardedOf( now, other.id ) === guardedOf( before, other.id ) ? 'unchanged' : 'CHANGED' }` };
} );
await row( 'cli.meta-direct', 'a shell still writes these keys directly, as the way back in the design does with `wp post meta update`: a detail and the main photo are changed and put back', async () => {
	const id = String( other.id );
	const had = [ await wp( 'meta', id, 'glc_color' ), await wp( 'meta', id, '_thumbnail_id' ) ];
	const set = [ await wp( 'meta', id, 'glc_color', 'Shell teal' ), await wp( 'meta', id, '_thumbnail_id', '1' ) ];
	const back = [ await wp( 'meta', id, 'glc_color', had[0].value ?? ':delete' ), await wp( 'meta', id, '_thumbnail_id', had[1].value ?? ':delete' ) ];
	return { ok: set[0].wrote === true && set[0].value === 'Shell teal' && set[1].wrote === true && set[1].value === '1' && back[0].value === had[0].value && back[1].value === had[1].value, got: `colour "${ had[0].value }" → "${ set[0].value }" → "${ back[0].value }"; main photo ${ had[1].value } → ${ set[1].value } → ${ back[1].value }` };
} );
await row( 'car.delete-web', 'the owner can still delete a car for good from WordPress: every meta row goes with it, the guarded ones too', async () => {
	const made = await owner.rest( '/wp/v2/car', { method: 'POST', json: { title: 'GLC test car to delete', status: 'draft' } } );
	const id = String( made.data?.id ?? 0 );
	for ( const [ key, value ] of [ [ 'glc_seats', '5' ], [ 'glc_color', 'Grey' ], [ '_thumbnail_id', '1' ] ] ) await wp( 'meta', id, key, value );
	const had = await wp( 'meta', id, 'glc_seats' );
	const gone = await owner.rest( `/wp/v2/car/${ id }?force=true`, { method: 'DELETE' } );
	const left = await wp( 'meta', id, 'glc_seats' );
	return { ok: made.status === 201 && had.rows >= 3 && gone.status === 200 && left.exists === false && left.rows === 0, got: `made ${ code( made ) } (#${ id }, ${ had.rows } meta rows); deleted ${ code( gone ) }; left behind: ${ left.rows } meta rows` };
} );
await row( 'schema.script-safe', 'a "<" in a value cannot end the structured data\'s script early: with a car\'s title set (from a shell) to text that closes the script and opens another, every JSON-LD block of its page still reads as JSON and carries that exact title', async () => {
	// A title is the one value that can hold a "<": WordPress strips tags from every text meta as it is saved.
	const evil = 'Blue</script><script>alert(1)</script>';
	const id = String( other.id );
	const had = await wp( 'title', id );
	await wp( 'title', id, `${ had.title } ${ evil }` );
	const link = String( ( await anon.rest( `/wp/v2/car/${ id }?_fields=link` ) ).data?.link ?? '' );
	const html = ( await anon.request( link.replace( base, '' ) ) ).text;
	const back = await wp( 'title', id, had.title );
	const blocks = [ ...html.matchAll( /<script type="application\/ld\+json">([\s\S]*?)<\/script>/g ) ].map( ( m ) => m[1] );
	let read = blocks.length > 0, name = '';
	for ( const block of blocks ) {
		try { for ( const node of JSON.parse( block )[ '@graph' ] ?? [] ) if ( String( node.name ?? '' ).includes( evil ) ) name = node.name; } catch { read = false; }
	}
	return { ok: link !== '' && read && name.includes( evil ) && blocks.some( ( block ) => block.includes( '\\u003C/script\\u003E' ) ) && back.title === had.title, got: `${ blocks.length } JSON-LD block(s), ${ read ? 'all read as JSON' : 'NOT READABLE AS JSON (the value ended the script)' }; the title in the data: "${ name.slice( -44 ) }"; title put back: ${ back.title === had.title }` };
} );

/* ---------------------------------------------- one car's screen: what it does */

/** The season and fee forms of a car as the person sees them now (with the current revision and nonces). */
const formsOf = async ( session, car ) => { const page = await session.request( carUrl( car.id ) ); return { page, pricing: formFor( page.text, 'glc_partner_save_pricing' ), fees: formFor( page.text, 'glc_partner_save_fees' ) }; };
const previewOf = ( session, form, section, extra ) => session.request( ADMIN_POST, { method: 'POST', form: { ...form.fields, action: 'glc_partner_preview', _wpnonce: form.previewNonce, section, ...extra } } );
const stored = start.cars[ mine.id ].pricing;

await row( 'car.preview', 'a preview shows what will change and sample quotes worked out twice, and writes nothing', async () => {
	const f = await formsOf( partner, mine );
	const r = await previewOf( partner, f.pricing, 'pricing', { glc_table: packTable( cheaper ) } );
	const now = await inspect();
	const text = decode( r.text.replace( /<[^>]+>/g, ' ' ).replace( /\s+/g, ' ' ) );
	const samples = ( r.text.match( /<tbody>([\s\S]*?)<\/tbody>/ )?.[1].match( /<tr>/g ) ?? [] ).length;
	const totals = [ ...r.text.matchAll( /<td class="">([^<]*)<\/td>/g ) ].map( ( m ) => decode( m[1] ) );
	const pairs = []; for ( let i = 0; i + 1 < totals.length; i += 2 ) pairs.push( totals[ i ] === totals[ i + 1 ] );
	return { ok: r.status === 200 && r.text.includes( 'data-ok="1"' ) && text.includes( en.preview_changes ) && /1-2 days: \$\d+ → \$\d+/.test( text ) && samples >= 3 && r.text.includes( 'data-faults="0"' ) && pairs.length === samples * 2 && pairs.every( Boolean ) && totals.every( ( t ) => /^\$[\d,.]+$/.test( t ) ) && now.cars[ mine.id ].pricing_md5 === start.cars[ mine.id ].pricing_md5 && now.cars[ mine.id ].rev === start.cars[ mine.id ].rev && now.change_count === start.change_count, got: `${ r.status }; "${ text.match( /[^.]*1-2 days: [^ ]+ → [^ ]+/ )?.[0].trim().slice( -60 ) ?? 'no changed price named' }"; ${ samples } samples, ${ pairs.filter( Boolean ).length }/${ pairs.length } pairs of figures agree (${ totals.slice( 0, 4 ).join( ' ' ) } …); stored table and revision ${ now.cars[ mine.id ].pricing_md5 === start.cars[ mine.id ].pricing_md5 && now.cars[ mine.id ].rev === start.cars[ mine.id ].rev ? 'untouched' : 'CHANGED' }` };
} );
await row( 'car.preview-fees', 'a fee preview shows the fee change and a sample that carries it', async () => {
	const f = await formsOf( partner, mine );
	const r = await previewOf( partner, f.fees, 'fees', { 'glc_fee[kutaisi_airport]': '0', 'glc_fee[batumi_airport]': '' } );
	const text = decode( r.text.replace( /<[^>]+>/g, ' ' ).replace( /\s+/g, ' ' ) );
	const now = await inspect();
	return { ok: r.status === 200 && r.text.includes( 'data-ok="1"' ) && text.includes( `${ en.place_kutaisi_airport }: $95 → free` ) && r.text.includes( 'data-faults="0"' ) && now.cars[ mine.id ].delivery === start.cars[ mine.id ].delivery, got: `${ r.status }; "${ text.match( /Kutaisi[^:]*: \$95 → \w+/ )?.[0] ?? 'fee change not named' }"; stored fees ${ now.cars[ mine.id ].delivery === start.cars[ mine.id ].delivery ? 'untouched' : 'CHANGED' }` };
} );
const gapTable     = stored.filter( ( s ) => ! ( s.from <= '03-15' && s.to >= '03-15' ) );
const gapSeason    = stored.find( ( s ) => s.from <= '03-15' && s.to >= '03-15' );
const overlapTable = [ ...stored, { ...stored[0], from: '07-01', to: '07-10' } ];
for ( const [ id, what, table, must ] of [
	[ 'car.save-gap', 'a table that leaves days without a season', gapTable, /No season covers [A-Z][a-z]{2} \d+ to [A-Z][a-z]{2} \d+ \(\d+ days\)/ ],
	[ 'car.save-overlap', 'a table that puts days in two seasons', overlapTable, /Jul 1 to Jul \d+ is in more than one season: season \d+ \([^)]*\) and season \d+ \([^)]*\)/ ],
] ) {
	await row( id, `${ what } is refused: the screen names the dates, keeps what was typed, and the database is as it was`, async () => {
		const f = await formsOf( partner, mine );
		const preview = await previewOf( partner, f.pricing, 'pricing', { glc_table: packTable( table ) } );
		const { sent, page } = await send( partner, { ...f.pricing.fields, glc_table: packTable( table ) } );
		const said = notices( page.text ).join( ' | ' );
		const rows = ( wrap( page.text ).replace( /<template[\s\S]*?<\/template>/g, '' ).match( /<tr class="glc-season/g ) ?? [] ).length;
		const now = await inspect();
		const same = now.cars[ mine.id ].pricing_md5 === start.cars[ mine.id ].pricing_md5 && now.cars[ mine.id ].rev === start.cars[ mine.id ].rev && now.change_count === start.change_count;
		return { ok: sent.status === 303 && must.test( said ) && said.includes( en.not_saved ) && rows === table.length && preview.text.includes( 'data-ok="0"' ) && must.test( decode( preview.text.replace( /<[^>]+>/g, ' ' ) ) ) && same, got: `${ sent.status }; said: "${ said.match( must )?.[0] ?? said.slice( 0, 80 ) }"; the form shows ${ rows } typed rows (stored: ${ stored.length }); the preview refused it too; database ${ same ? 'unchanged' : 'CHANGED' }` };
	} );
}
await row( 'car.save-gap-ka', 'the same refusal reads in Georgian for a Georgian partner, dates included', async () => {
	const f = await formsOf( partnerB, theirs );
	const before = ( await inspect() ).cars[ theirs.id ];
	const table = before.pricing.filter( ( s ) => ! ( s.from <= '03-15' && s.to >= '03-15' ) );
	const { sent, page } = await send( partnerB, { ...f.pricing.fields, glc_table: packTable( table ) } );
	const said = notices( page.text ).join( ' | ' );
	const after = ( await inspect() ).cars[ theirs.id ];
	return { ok: sent.status === 303 && said.includes( ka.not_saved ) && /პერიოდს \d+ (იან|თებ|მარ|აპრ) – \d+ (მარ|აპრ|მაი|ივნ) \(\d+ დღე\) არცერთი სეზონი არ ფარავს/.test( said ) && after.pricing_md5 === before.pricing_md5 && wrap( page.text ).includes( 'lang="ka"' ), got: `${ sent.status }; said: "${ said.slice( 0, 150 ) }"` };
} );
let afterSave;
await row( 'car.save-pricing', 'the partner changes one price: saved through the store, one revision up, logged with the full before and after, stored in the live shape', async () => {
	const f = await formsOf( partner, mine );
	const { sent, page } = await send( partner, { ...f.pricing.fields, glc_table: packTable( cheaper ), actor: fx.accounts.owner.id, glc_actor: fx.accounts.owner.id, user_id: fx.accounts.owner.id, source: 'cli', glc_source: 'cli' } );
	afterSave = await inspect();
	const car = afterSave.cars[ mine.id ], log = afterSave.last_change;
	const before = JSON.parse( log.glc_before ?? 'null' ), after = JSON.parse( log.glc_after ?? 'null' );
	const said = notices( page.text ).join( ' | ' );
	return { ok: sent.status === 303 && said.includes( 'Saved' ) && Number( car.rev ) === Number( start.cars[ mine.id ].rev ) + 1 && car.pricing_md5 !== start.cars[ mine.id ].pricing_md5 && car.pricing_head.startsWith( `a:${ stored.length }:{i:0;a:4:{s:5:"label";s:` ) && Number( car.pricing[0].rates.d1_2 ) === Number( stored[0].rates.d1_2 ) + 1 && afterSave.change_count === start.change_count + 1 && log.glc_kind === 'pricing' && log.glc_source === 'panel' && Number( log.glc_car_id ) === mine.id && JSON.stringify( before ) === JSON.stringify( stored ) && JSON.stringify( after ) === JSON.stringify( car.pricing ) && log.post_status === 'private', got: `${ sent.status }; said: "${ said.slice( 0, 90 ) }"; revision ${ start.cars[ mine.id ].rev } → ${ car.rev }; stored as ${ car.pricing_head.slice( 0, 44 ) }…; log entry #${ log.ID } ${ log.glc_kind }/${ log.glc_source } with before = the old table and after = the stored one` };
} );
await row( 'store.actor-from-session', 'who saved is the person signed in: a request that names the owner as actor, and the shell as source, is logged as the partner', async () => {
	const log = afterSave.last_change;
	return { ok: Number( log.post_author ) === fx.accounts.partner.id && Number( log.glc_actor ) === fx.accounts.partner.id && log.glc_source === 'panel' && log.post_title.includes( fx.accounts.partner.name ), got: `entry #${ log.ID }: author #${ log.post_author }, actor #${ log.glc_actor } (the partner is #${ fx.accounts.partner.id }, the request said #${ fx.accounts.owner.id }), source ${ log.glc_source }` };
} );
await row( 'time.display-timezone', 'times a person reads are in the display time zone; the site\'s own time zone is still UTC', async () => {
	const log = afterSave.last_change;
	const local = new Intl.DateTimeFormat( 'sv-SE', { timeZone: afterSave.display_timezone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' } ).format( new Date( log.post_date_gmt.replace( ' ', 'T' ) + 'Z' ) );
	const utc = log.post_date_gmt.slice( 0, 16 );
	const page = await partner.request( carUrl( mine.id ) );
	const main = visible( page.text );
	return { ok: log.post_title.endsWith( local ) && local !== utc && main.includes( `, ${ local }` ) && afterSave.site_timezone.timezone_string === '' && afterSave.site_timezone.gmt_offset === '0', got: `saved at ${ utc } UTC; the log title says ${ log.post_title.slice( -16 ) } and the screen says "${ main.match( /Last change: [^.]*?, [\d-]+ [\d:]+/ )?.[0] ?? '?' }" (${ afterSave.display_timezone }); site timezone_string "${ afterSave.site_timezone.timezone_string }", gmt_offset "${ afterSave.site_timezone.gmt_offset }"` };
} );
await row( 'car.save-fees', 'the partner sets the car\'s own fees: a number, 0 for free, empty for the standard; stored as overrides only', async () => {
	const f = await formsOf( partner, mine );
	const { sent, page } = await send( partner, { ...f.fees.fields, 'glc_fee[tbilisi_city]': '0', 'glc_fee[tbilisi_airport]': '', 'glc_fee[kutaisi_airport]': '90.50', 'glc_fee[batumi_airport]': '', 'glc_fee[out_of_hours]': '', 'glc_fee[moon_base]': '5' } );
	const now = await inspect();
	const car = now.cars[ mine.id ];
	const main = visible( page.text );
	return { ok: sent.status === 303 && car.delivery === 'a:2:{s:12:"tbilisi_city";s:1:"0";s:15:"kutaisi_airport";s:4:"90.5";}' && car.out_of_hours === null && Number( car.rev ) === Number( afterSave.cars[ mine.id ].rev ) + 1 && now.last_change.glc_kind === 'fees' && main.includes( 'Geolander standard ($20)' ) && main.includes( 'free' ), got: `${ sent.status }; glc_delivery_fees = ${ car.delivery }; glc_out_of_hours_fee ${ car.out_of_hours === null ? 'absent (site standard)' : JSON.stringify( car.out_of_hours ) }; revision ${ car.rev }` };
} );
await row( 'car.save-fees-bad', 'a fee that is not an amount, or above the limit, is refused with the place named, and what was typed stays', async () => {
	const f = await formsOf( partner, mine );
	const before = await inspect();
	const { sent, page } = await send( partner, { ...f.fees.fields, 'glc_fee[batumi_airport]': '99999', 'glc_fee[out_of_hours]': '-5' } );
	const said = notices( page.text ).join( ' | ' );
	const back = formFor( page.text, 'glc_partner_save_fees' );
	const now = await inspect();
	return { ok: sent.status === 303 && said.includes( en.place_batumi_airport ) && said.includes( en.place_out_of_hours ) && back.fields['glc_fee[batumi_airport]'] === '99999' && back.fields['glc_fee[out_of_hours]'] === '-5' && now.cars[ mine.id ].delivery === before.cars[ mine.id ].delivery && now.cars[ mine.id ].rev === before.cars[ mine.id ].rev, got: `${ sent.status }; said: "${ said.slice( 0, 170 ) }"; typed values ${ back.fields['glc_fee[batumi_airport]'] === '99999' ? 'kept' : 'LOST' }` };
} );
await row( 'car.stale', 'two people, one car: the second save, made from a page opened before the first, is refused and says who saved and when: to a partner "Geolander", never the owner\'s name', async () => {
	const second = await formsOf( partner, mine );                    // the partner opens the page …
	const first  = await formsOf( owner, mine );                      // … and so does the owner,
	const one    = await send( owner, { ...first.fees.fields, 'glc_fee[batumi_airport]': '70' } );   // who saves first.
	const between = await inspect();
	const two    = await send( partner, { ...second.pricing.fields, glc_table: packTable( stored ) } );
	const three  = await send( partner, { ...second.fees.fields, 'glc_fee[batumi_airport]': '1' } );
	const said   = notices( two.page.text ).join( ' | ' ), saidFees = notices( three.page.text ).join( ' | ' );
	const now    = await inspect();
	const when   = between.last_change.post_title.slice( -16 );
	const same   = now.cars[ mine.id ].pricing_md5 === between.cars[ mine.id ].pricing_md5 && now.cars[ mine.id ].delivery === between.cars[ mine.id ].delivery && now.cars[ mine.id ].rev === between.cars[ mine.id ].rev && now.change_count === between.change_count;
	return { ok: one.sent.status === 303 && Number( between.cars[ mine.id ].rev ) === Number( second.pricing.fields.glc_rev ) + 1 && two.sent.status === 303 && said.includes( `(${ en.log_who_owner }, ${ when })` ) && saidFees.includes( `(${ en.log_who_owner }, ${ when })` ) && ! namesSomeone( said + saidFees, 'partner' ) && same, got: `first save 303 (revision ${ second.pricing.fields.glc_rev } → ${ between.cars[ mine.id ].rev }); second save said: "${ said.replace( en.not_saved, '' ).trim().slice( 0, 120 ) }"; database after the refused saves: ${ same ? 'exactly as the first save left it' : 'CHANGED' }` };
} );
await row( 'car.restore', 'the partner puts the earlier table back after a confirmation: byte for byte the table from before, and a new log entry', async () => {
	const page  = await partner.request( carUrl( mine.id ) );
	const link  = decode( wrap( page.text ).match( new RegExp( `href="([^"]*restore=${ afterSave.last_change.ID }[^"]*)"` ) )?.[1] ?? '' );
	const ask   = link ? await partner.request( link.replace( base, '' ) ) : { status: 0, text: '' };
	const form  = formFor( ask.text, 'glc_partner_restore' );
	const shown = visible( ask.text );
	const before = await inspect();
	const { sent, page: back } = form ? await send( partner, form.fields ) : { sent: { status: 0 }, page: { text: '' } };
	const now  = await inspect();
	const said = notices( back.text ).join( ' | ' );
	return { ok: ask.status === 200 && shown.includes( en.preview_changes ) && /1-2 days: \$\d+ → \$\d+/.test( shown ) && ask.text.includes( 'data-faults="0"' ) && ask.text.includes( 'glc-differs' ) && sent.status === 303 && now.cars[ mine.id ].pricing_md5 === start.cars[ mine.id ].pricing_md5 && Number( now.cars[ mine.id ].rev ) === Number( before.cars[ mine.id ].rev ) + 1 && now.change_count === before.change_count + 1 && now.last_change.glc_source === 'restore' && now.last_change.glc_note === `restore of #${ afterSave.last_change.ID }` && Number( now.last_change.glc_actor ) === fx.accounts.partner.id, got: `confirmation ${ ask.status } ("${ shown.match( /[^.]*1-2 days: [^ ]+ → [^ ]+/ )?.[0].trim().slice( -50 ) ?? '?' }"); restore ${ sent.status }; said: "${ said.slice( 0, 70 ) }"; stored table ${ now.cars[ mine.id ].pricing_md5 === start.cars[ mine.id ].pricing_md5 ? 'identical to the one before the change (md5)' : 'DIFFERS' }; log entry #${ now.last_change.ID } ${ now.last_change.glc_source }, "${ now.last_change.glc_note }"` };
} );
await row( 'car.restore-stale', 'a restore confirmed from a page opened before someone else\'s save is refused too', async () => {
	const ask  = await partner.request( carUrl( mine.id, `&restore=${ fx.entries.by_partner }` ) );
	const form = formFor( ask.text, 'glc_partner_restore' );
	const f    = await formsOf( owner, mine );
	await send( owner, { ...f.fees.fields, 'glc_fee[batumi_airport]': '71' } );
	const before = await inspect();
	const { sent, page } = await send( partner, form.fields );
	const now = await inspect();
	const said = notices( page.text ).join( ' | ' );
	return { ok: sent.status === 303 && said.includes( `(${ en.log_who_owner }, ` ) && ! namesSomeone( said, 'partner' ) && now.cars[ mine.id ].out_of_hours === before.cars[ mine.id ].out_of_hours && now.cars[ mine.id ].rev === before.cars[ mine.id ].rev && now.change_count === before.change_count, got: `${ sent.status }; said: "${ said.replace( en.not_saved, '' ).trim().slice( 0, 110 ) }"` };
} );
await row( 'car.big-table', 'the season form is one field: the longest table a year can hold passes the server\'s request limits and is answered by the store, not cut off', async () => {
	// 365 one-day seasons: more than any table that can be saved. One form field, about 25 KB.
	const days = []; for ( let d = new Date( Date.UTC( 2001, 0, 1 ) ); d.getUTCFullYear() === 2001; d.setUTCDate( d.getUTCDate() + 1 ) ) days.push( d.toISOString().slice( 5, 10 ) );
	const table = days.map( ( day ) => ( { from: day, to: day, rates: Object.fromEntries( BANDS.map( ( b ) => [ b, '1234.56' ] ) ) } ) );
	const f = await formsOf( partner, mine );
	const fields = { ...f.pricing.fields, glc_table: packTable( table ) };
	const bytes = new URLSearchParams( fields ).toString().length;
	const before = await inspect();
	const { sent, page } = await send( partner, fields );
	const said = notices( page?.text ?? '' ).join( ' | ' );
	const now = await inspect();
	return { ok: sent.status === 303 && said.includes( 'at most' ) && said.includes( String( table.length ) ) && Object.keys( fields ).length < 10 && now.cars[ mine.id ].pricing_md5 === before.cars[ mine.id ].pricing_md5, got: `${ table.length } seasons in ${ Object.keys( fields ).length } form fields, ${ bytes } bytes; answer ${ sent.status }, said: "${ said.replace( en.not_saved, '' ).trim().slice( 0, 90 ) }"` };
} );
await row( 'car.long-table', 'a table over the season limit is refused on its length alone: one reason, and not a word about the days its rows cover twice, because the year is not walked for a table that is refused anyway', async () => {
	// 30 seasons that each cover the whole year: every day is in all of them.
	const table = Array.from( { length: 30 }, () => ( { from: '01-01', to: '12-31', rates: Object.fromEntries( BANDS.map( ( b ) => [ b, '60' ] ) ) } ) );
	const f = await formsOf( partner, mine );
	const before = await inspect();
	const { sent, page } = await send( partner, { ...f.pricing.fields, glc_table: packTable( table ) } );
	const said = notices( page?.text ?? '' ).join( ' | ' ).replace( en.not_saved, '' ).trim();
	const now = await inspect();
	return { ok: sent.status === 303 && said.includes( 'at most 24. This one has 30.' ) && ! /more than one season|No season covers/.test( said ) && now.cars[ mine.id ].pricing_md5 === before.cars[ mine.id ].pricing_md5, got: `${ sent.status }; said: "${ said.slice( 0, 160 ) }"` };
} );
await row( 'metabox.read-only','the owner\'s WordPress car edit screen shows the prices to read and posts none of them back', async () => {
	const r = await owner.request( `/wp-admin/post.php?post=${ other.id }&action=edit` );
	const page = decode( r.text );
	const fields = ( page.match( /name="glc_pricing/g ) ?? [] ).length;
	const link = page.includes( `page=glc-partner-car&car=${ other.id }` ) && page.includes( en.metabox_edit_pricing );
	return { ok: r.status === 200 && page.includes( 'glc_car_nonce' ) && page.includes( 'id="glc_pricing"' ) && fields === 0 && link && page.includes( en.metabox_pricing_note ), got: `${ r.status }, ${ fields } price fields in the editor, link to the car's own screen ${ link ? 'present' : 'MISSING' }` };
} );

/* ------------------------------------------------ profile and assignment: saves */

await row( 'profile.save', 'the partner saves name, surname and phone: their own account only, the phone in its stored form, the change logged', async () => {
	const f = formFor( ( await partner.request( PROFILE ) ).text, 'glc_partner_save_profile' );
	const before = await inspect();
	const { sent, page } = await send( partner, { ...f.fields, first_name: 'Nino', last_name: 'Testadze', glc_partner_phone: '+995 (555) 000-303', user_id: fx.accounts.owner.id, ID: fx.accounts.owner.id, user: fx.accounts.partner_b.id, role: 'administrator' } );
	const now = await inspect();
	const me = now.accounts.partner, log = now.last_change;
	const others = [ 'owner', 'partner_b', 'partner_ka' ].every( ( who ) => JSON.stringify( now.accounts[ who ] ) === JSON.stringify( before.accounts[ who ] ) );
	const list = await owner.request( '/wp-admin/users.php' );
	return { ok: sent.status === 303 && me.phone === '+995555000303' && me.first_name === 'Nino' && me.last_name === 'Testadze' && others && log.glc_kind === 'profile' && Number( log.glc_actor ) === fx.accounts.partner.id && JSON.parse( log.glc_after ).phone === '+995555000303' && notices( page.text ).join( ' ' ).includes( 'new number' ) && ! list.text.includes( `user_id=${ fx.accounts.partner.id }` ) === false, got: `${ sent.status }; usermeta glc_partner_phone = "${ me.phone }", first_name "${ me.first_name }", last_name "${ me.last_name }"; the other accounts ${ others ? 'untouched' : 'CHANGED' }; log entry ${ log.glc_kind } by #${ log.glc_actor }` };
} );
await row( 'profile.owner-corrects', 'the owner corrects a partner\'s phone on the partner\'s WordPress user screen; a wrong one is refused there too', async () => {
	const url  = `/wp-admin/user-edit.php?user_id=${ fx.accounts.partner.id }`;
	const page = await owner.request( url );
	const form = page.text.match( /<form id="your-profile"[\s\S]*?<\/form>/ )?.[0] ?? '';
	const fields = {};
	const attr = ( tag, name ) => tag.match( new RegExp( `\\b${ name }=(["'])(.*?)\\1`, 's' ) )?.[2];
	for ( const m of form.matchAll( /<input\b[^>]*>/g ) ) {
		const name = attr( m[0], 'name' ), type = attr( m[0], 'type' );
		if ( ! name || [ 'submit', 'button', 'file' ].includes( type ) || ( [ 'checkbox', 'radio' ].includes( type ) && ! /\bchecked\b/.test( m[0] ) ) ) continue;
		fields[ decode( name ) ] = decode( attr( m[0], 'value' ) ?? '' );
	}
	for ( const m of form.matchAll( /<select\b[^>]*\bname="([^"]*)"[^>]*>([\s\S]*?)<\/select>/g ) ) fields[ decode( m[1] ) ] = decode( m[2].match( /<option[^>]*\bselected[^>]*value="([^"]*)"/ )?.[1] ?? m[2].match( /<option value="([^"]*)"[^>]*\bselected/ )?.[1] ?? m[2].match( /<option[^>]*value=(["'])(.*?)\1/ )?.[2] ?? '' );
	for ( const m of form.matchAll( /<textarea\b[^>]*\bname="([^"]*)"[^>]*>([\s\S]*?)<\/textarea>/g ) ) fields[ decode( m[1] ) ] = decode( m[2] );
	delete fields.pass1; delete fields.pass2; delete fields['pass1-text'];
	const bad  = await owner.request( url, { method: 'POST', form: { ...fields, glc_partner_phone: 'not a phone' } } );
	const mid  = await inspect();
	const good = await owner.request( url, { method: 'POST', form: { ...fields, glc_partner_phone: '+995555000404' } } );
	const now  = await inspect();
	return { ok: 'glc_partner_phone' in fields && bad.text.includes( en.err_phone ) && mid.accounts.partner.phone === '+995555000303' && good.status === 302 && now.accounts.partner.phone === '+995555000404' && Number( now.last_change.glc_actor ) === fx.accounts.owner.id && now.last_change.glc_kind === 'profile', got: `wrong phone: ${ bad.status }, ${ bad.text.includes( en.err_phone ) ? 'refused with the reason' : 'NOT refused' }, stored "${ mid.accounts.partner.phone }"; right phone: ${ good.status }, stored "${ now.accounts.partner.phone }", logged by #${ now.last_change.glc_actor }` };
} );
/** The owner changes who has a car, on the Partners screen. */
const assign = async ( car, partnerId ) => {
	const form = formFor( ( await owner.request( PARTNERS ) ).text, 'glc_partners_assign' );
	return send( owner, { ...form.fields, [ `glc_assign[${ car.id }]` ]: String( partnerId ) } );
};
await row( 'assign.follows', 'the owner assigns a car and takes it back: the partner\'s access follows at their next request, and each change is logged', async () => {
	const before = await inspect();
	const closed = await partner.request( carUrl( other.id ) );
	const give   = await assign( other, fx.accounts.partner.id );
	const mid    = await inspect();
	const open   = await partner.request( carUrl( other.id ) );
	const list   = await partner.request( '/wp-admin/admin.php?page=glc-partner' );
	const f      = await formsOf( partner, other );
	const saved  = await send( partner, { ...f.fees.fields, 'glc_fee[tbilisi_city]': '11' } );
	const take   = await assign( other, 0 );
	const shut   = await partner.request( carUrl( other.id ) );
	const late   = await send( partner, { ...f.fees.fields, glc_rev: String( Number( f.fees.fields.glc_rev ) + 1 ), 'glc_fee[tbilisi_city]': '12' } );   // a form from when the car was theirs
	const now    = await inspect();
	return { ok: closed.status === 403 && give.sent.status === 303 && mid.cars[ other.id ].partner === String( fx.accounts.partner.id ) && mid.last_change.glc_kind === 'assignment' && mid.last_change.glc_after === JSON.stringify( { partner: fx.accounts.partner.id } ) && open.status === 200 && wrap( list.text ).includes( other.plate ) && saved.sent.status === 303 && take.sent.status === 303 && now.cars[ other.id ].partner === null && shut.status === 403 && late.sent.status === 403 && now.cars[ other.id ].delivery === 'a:1:{s:12:"tbilisi_city";s:2:"11";}', got: `before ${ closed.status }; assigned (${ give.sent.status }, glc_partner_id "${ mid.cars[ other.id ].partner }", logged ${ mid.last_change.glc_before } → ${ mid.last_change.glc_after }) → ${ open.status } and a save ${ saved.sent.status }; taken back (${ take.sent.status }, glc_partner_id ${ now.cars[ other.id ].partner === null ? 'absent' : now.cars[ other.id ].partner }) → ${ shut.status }, and a save from the old form ${ late.sent.status }` };
} );

/* ------------------------------------------------ who gets the booking message */

const dayFrom = ( days ) => new Date( Date.now() + days * 86400000 ).toISOString().slice( 0, 10 );
const CUSTOMER = { name: 'Zxq Customer Vbnm', email: 'zxq-customer-vbnm@example.test' };
const booked = [];
/** One real checkout over the public API, and what it stored. */
const checkout = async ( car ) => {
	const r = await anon.rest( '/geolander/v1/checkout', { method: 'POST', json: { car: car.id, from: dayFrom( 40 ), to: dayFrom( 43 ), pickup: 'tbilisi_office', return: 'tbilisi_office', ...CUSTOMER } } );
	const phone = decodeURIComponent( r.data?.redirect?.match( /phone=([^&]+)/ )?.[1] ?? '' );
	const row = ( await inspect() ).bookings.find( ( b ) => b.glc_reference === r.data?.reference ) ?? {};
	if ( r.data?.reference ) booked.push( { reference: r.data.reference, message: r.data.message ?? '' } );
	return { r, phone, row };
};
const routed = ( c, number, source, partnerId ) => c.r.status === 200 && c.phone === `+${ number }` && c.row.glc_routed_number === number && c.row.glc_routing_source === source && c.row.glc_partner_id === String( partnerId );
const tell = ( c ) => `${ c.r.status }, link opens ${ c.phone || '?' }; stored: glc_routed_number "${ c.row.glc_routed_number }", glc_routing_source "${ c.row.glc_routing_source }", glc_partner_id "${ c.row.glc_partner_id }"`;
await row( 'routing.car-number', 'a car that carries its own number and has no partner: the request opens WhatsApp to the car\'s number (it used to open the site number)', async () => {
	const c = await checkout( legacy );
	return { ok: routed( c, fx.legacy_number, 'car', 0 ) && fx.legacy_number !== fx.site_number, got: tell( c ) };
} );
await row( 'routing.partner', 'a car assigned to a partner with a phone: the request opens WhatsApp to the partner, and the customer\'s message does not name them', async () => {
	const c = await checkout( mine );
	const message = c.r.data?.message ?? '';
	return { ok: routed( c, '995555000404', 'partner', fx.accounts.partner.id ) && message.includes( 'Booking request GL-' ) && ! [ 'Nino', 'Testadze', fx.accounts.partner.name, fx.accounts.partner.login, 'partner', 'Partner' ].some( ( word ) => message.includes( word ) ) && c.row.glc_partner_mail_status === 'disabled', got: `${ tell( c ) }; message names the partner: ${ /Nino|Testadze|artner/.test( message ) ? 'YES' : 'no' }; partner email copy "${ c.row.glc_partner_mail_status }"` };
} );
await row( 'routing.partner-over-car-number', 'a car that carries its own number AND is assigned to a partner with a phone: the partner wins', async () => {
	await assign( legacy, fx.accounts.partner.id );
	const c = await checkout( legacy );
	await assign( legacy, 0 );
	return { ok: routed( c, '995555000404', 'partner', fx.accounts.partner.id ), got: tell( c ) };
} );
await row( 'routing.partner-no-phone', 'a car assigned to a partner without a phone: the request falls through to the site number and is not counted as theirs', async () => {
	await assign( other, fx.accounts.partner_ka.id );
	const c = await checkout( other );
	const d = await ( async () => { await assign( legacy, fx.accounts.partner_ka.id ); const x = await checkout( legacy ); await assign( legacy, 0 ); return x; } )();
	await assign( other, 0 );
	return { ok: routed( c, fx.site_number, 'site', 0 ) && routed( d, fx.legacy_number, 'car', 0 ), got: `${ tell( c ) } | the same partner on a car with its own number: ${ tell( d ) }` };
} );
await row( 'routing.unassigned', 'a car with no partner and no number of its own: the site number', async () => {
	const c = await checkout( other );
	return { ok: routed( c, fx.site_number, 'site', 0 ) && c.row.glc_partner_mail_status === null, got: tell( c ) };
} );
await row( 'count.quantities-only', 'the owner\'s count: how many requests went to whom, the total equal to a separate count of the records, and not one customer name, email, reference or message in the page', async () => {
	const r = await owner.request( PARTNERS );
	const now = await inspect();
	const counted = Number( r.text.match( /data-counted="(\d+)"/ )?.[1] ?? -1 ), records = Number( r.text.match( /data-records="(\d+)"/ )?.[1] ?? -2 );
	const main = visible( r.text );
	const leaks = [ CUSTOMER.name, CUSTOMER.email, 'Zxq', 'zxq-customer', ...booked.map( ( b ) => b.reference ), 'Booking request GL-', 'Final total shown', 'balance at pickup' ].filter( ( s ) => r.text.includes( s ) );
	const partnerRow = main.match( /Nino Testadze \+995555000404 ((?:\d+ )+)/ )?.[1].trim().split( ' ' ).map( Number ) ?? [];
	return { ok: r.status === 200 && counted === records && records === now.booking_count && counted === start.booking_count + booked.length && booked.length === 6 && leaks.length === 0 && partnerRow.at( -1 ) === 2 && main.includes( `${ en.count_car_number } +${ fx.legacy_number }` ) && main.includes( en.count_site ) && main.includes( 'They agree' ), got: `${ r.status }; counted ${ counted }, records by SQL ${ records } (inspect: ${ now.booking_count }), ${ booked.length } made by this test; to the partner: ${ partnerRow.at( -1 ) }; customer data in the page source: ${ leaks.length ? 'LEAKED ' + leaks.join( ', ' ) : 'none of ' + ( 5 + booked.length + 3 ) + ' strings' }` };
} );
await row( 'count.customer-data-exists', 'control: the customer\'s name and email are in the database and on the owner\'s Booking Requests list, so their absence above is not an accident', async () => {
	const now = await inspect();
	const list = await owner.request( '/wp-admin/edit.php?post_type=booking_request' );
	return { ok: now.bookings.some( ( b ) => b.glc_name === CUSTOMER.name && b.glc_email === CUSTOMER.email ) && list.text.includes( CUSTOMER.name ) && list.text.includes( booked[0].reference ), got: `stored: ${ now.bookings.filter( ( b ) => b.glc_name === CUSTOMER.name ).length } request(s) with the name; Booking Requests list shows it: ${ list.text.includes( CUSTOMER.name ) }` };
} );

/* ------------------------------------------- several cars at once: what the bulk screen does */

/** Which cars of the fleet differ between two inspections: every meta row of every car, compared by fingerprint. */
const fleetMoved = ( a, b ) => Object.keys( a.fleet ).filter( ( id ) => a.fleet[ id ].meta !== b.fleet[ id ].meta ).map( Number );
const sameSet = ( a, b ) => JSON.stringify( [ ...a ].sort( ( x, y ) => x - y ) ) === JSON.stringify( [ ...b ].sort( ( x, y ) => x - y ) );
const bulkStart = await inspect();
/** A preview of the partner's, made now: the page it lands on and its apply form. */
const bulkPreview = async ( session, over, path = BULK ) => {
	const sent = await send( session, bulkFields( ( await session.request( path ) ).text, over ) );
	return { ...sent, seen: previewOf2( sent.page?.text ?? '' ), apply: sent.page ? formFor( sent.page.text, 'glc_bulk_apply' ) : null };
};
const applyPlan = ( session, form ) => send( session, form.fields );

await row( 'bulk.preview', 'a preview of ×0.98 for three cars shows each car, what changes, and its sample quotes worked out twice; it writes nothing to any of the 19 cars', async () => {
	const seen = previewOf2( planA.page.text );
	const totals = [ ...planA.page.text.matchAll( /<td class="">(\$[^<]*)<\/td>/g ) ].map( ( m ) => decode( m[1] ) );
	const pairs = []; for ( let i = 0; i + 1 < totals.length; i += 2 ) pairs.push( totals[ i ] === totals[ i + 1 ] );
	const first = bulkStart.fleet[ idsC[0] ].pricing[0].rates.d1_2, want = Math.round( first * 0.98 );
	const moved = fleetMoved( start, bulkStart ).filter( ( id ) => idsC.includes( id ) );
	return { ok: planA.sent.status === 303 && sameSet( Object.keys( seen.cars ).map( Number ), idsC ) && Object.values( seen.cars ).every( ( s ) => s === 'changes' ) && seen.faults.length === 3 && seen.faults.every( ( f ) => f === 0 ) && pairs.length >= 3 * 2 * 3 && pairs.every( Boolean ) && seen.text.includes( `1-2 days: $${ first } → $${ want }` ) && !! applyA && moved.length === 0, got: `${ planA.sent.status }; cars ${ Object.entries( seen.cars ).map( ( [ id, s ] ) => `#${ id } ${ s }` ).join( ', ' ) }; e.g. "1-2 days: $${ first } → $${ want }" ${ seen.text.includes( `1-2 days: $${ first } → $${ want }` ) ? 'shown' : 'MISSING' }; ${ pairs.filter( Boolean ).length }/${ pairs.length } pairs of figures agree, faults ${ seen.faults.join( '/' ) }; the three cars after the previews: ${ moved.length ? 'CHANGED' : 'untouched' }` };
} );
await row( 'bulk.limit', 'a change that takes one car under the lowest allowed price: that car is named with the reason, and there is no Apply for any car', async () => {
	const p = await bulkPreview( partnerC, { glc_cars: idsC.join( ',' ), glc_use_pricing: '1', glc_mode: 'adjust', glc_delta: '-21', glc_round: '1' } );
	const refused = Object.entries( p.seen.cars ).filter( ( [ , s ] ) => s === 'refused' ).map( ( [ id ] ) => Number( id ) );
	const names = refused.map( ( id ) => fleetC.find( ( car ) => car.id === id ) ).filter( ( car ) => car && p.seen.text.includes( `${ car.title } · ${ car.plate }` ) );
	const now = await inspect();
	return { ok: refused.length >= 1 && refused.length < 3 && names.length === refused.length && /below the lowest allowed price of \$5/.test( p.seen.text ) && p.seen.text.includes( en.batch_blocked ) && ! p.apply && fleetMoved( bulkStart, now ).length === 0, got: `refused ${ refused.map( ( id ) => '#' + id ).join( ', ' ) || 'none' } of ${ Object.keys( p.seen.cars ).length }, named: ${ names.map( ( car ) => car.title ).join( ', ' ) }; "${ p.seen.text.match( /Season[^.]*below the lowest allowed price of \$5/ )?.[0] ?? 'no reason' }"; apply button ${ p.apply ? 'PRESENT' : 'absent' }; fleet ${ fleetMoved( bulkStart, now ).length ? 'CHANGED' : 'unchanged' }` };
} );
await row( 'bulk.limit-ka', 'the same refusal reads in Georgian for a Georgian partner', async () => {
	const p = await bulkPreview( partnerB, { glc_cars: String( theirs.id ), glc_use_pricing: '1', glc_mode: 'adjust', glc_delta: '-40', glc_round: '1' } );
	const said = p.seen.text.match( /სეზონი[^.]*ნაკლებია/ )?.[0] ?? '';
	return { ok: said !== '' && p.seen.text.includes( ka.batch_blocked ) && ! /below the lowest|Nothing can be applied/.test( p.seen.text ) && ! p.apply, got: `"${ said.slice( 0, 120 ) || 'no Georgian reason' }"` };
} );
await row( 'bulk.form-stops', 'a form that cannot be previewed says why and keeps what was typed: no car ticked, nothing switched on, a factor that is a word', async () => {
	const a = await bulkPreview( partnerC, { glc_use_pricing: '1', glc_mode: 'adjust', glc_factor: '0.98' } );
	const b = await bulkPreview( partnerC, { glc_cars: idsC.join( ',' ) } );
	const c = await bulkPreview( partnerC, { glc_cars: idsC.join( ',' ), glc_use_pricing: '1', glc_mode: 'adjust', glc_factor: 'two' } );
	return { ok: a.seen.stops.includes( en.bulk_err_no_cars ) && b.seen.stops.includes( en.bulk_err_no_section ) && c.seen.stops.some( ( s ) => s.includes( '"two"' ) ) && c.page.text.includes( 'name="glc_factor" value="two"' ) && picksOf( c.page.text ).length === 3 && ( c.page.text.match( /class="glc-pick"[^>]*checked/g ) ?? [] ).length === 3 && ! a.apply && ! b.apply && ! c.apply, got: `"${ a.seen.stops[0] }" | "${ b.seen.stops[0]?.slice( 0, 40 ) }…" | "${ c.seen.stops[0]?.slice( 0, 40 ) }…"; the word and the three ticks are still on the form: ${ c.page.text.includes( 'name="glc_factor" value="two"' ) }` };
} );
let bulkBatch = '';
await row( 'bulk.apply', 'Apply writes the three cars as ONE batch: one change-log entry per car with one batch id, each car one version up, and the other 16 cars byte for byte as before', async () => {
	const { sent, page } = await applyPlan( partnerC, applyA );
	const now = await inspect();
	const mine3 = now.changes.slice( 0, 3 );
	bulkBatch = mine3[0]?.glc_batch ?? '';
	const moved = fleetMoved( bulkStart, now );
	const said = notices( page.text ).join( ' | ' );
	const first = bulkStart.fleet[ idsC[0] ].pricing[0].rates.d1_2;
	return { ok: sent.status === 303 && said.includes( 'Saved for 3 car(s)' ) && said.includes( bulkBatch ) && bulkBatch.length === 36 && mine3.every( ( c ) => c.glc_batch === bulkBatch && c.glc_source === 'bulk' && c.glc_kind === 'pricing' && Number( c.glc_actor ) === fx.accounts.partner_c.id ) && sameSet( mine3.map( ( c ) => Number( c.glc_car_id ) ), idsC ) && now.change_count === bulkStart.change_count + 3 && sameSet( moved, idsC ) && idsC.every( ( id ) => Number( now.fleet[ id ].rev ) === Number( bulkStart.fleet[ id ].rev || 0 ) + 1 ) && now.fleet[ idsC[0] ].pricing[0].rates.d1_2 === Math.round( first * 0.98 ) && idsC.every( ( id ) => page.text.includes( `page=glc-partner-car&#038;car=${ id }#glc-history` ) || page.text.includes( `page=glc-partner-car&amp;car=${ id }#glc-history` ) ), got: `${ sent.status }; said: "${ said.slice( 0, 80 ) }"; log: ${ mine3.map( ( c ) => `#${ c.id } car ${ c.glc_car_id } ${ c.glc_kind }/${ c.glc_source }` ).join( ', ' ) }, batch ids ${ new Set( mine3.map( ( c ) => c.glc_batch ) ).size }; cars that differ by SQL: #${ moved.join( ', #' ) } (${ 19 - moved.length } of 19 identical); versions ${ idsC.map( ( id ) => `${ bulkStart.fleet[ id ].rev || 0 }→${ now.fleet[ id ].rev }` ).join( ', ' ) }; stored 1-2 day price ${ first } → ${ now.fleet[ idsC[0] ].pricing[0].rates.d1_2 }` };
} );
await row( 'bulk.apply-twice', 'the same Apply sent again does nothing: the preview is gone, and the screen says so', async () => {
	const before = await inspect();
	const { sent, page } = await applyPlan( partnerC, applyA );
	const now = await inspect();
	return { ok: sent.status === 303 && notices( page.text ).join( ' ' ).includes( en.batch_gone ) && fleetMoved( before, now ).length === 0 && now.change_count === before.change_count, got: `${ sent.status }; said: "${ notices( page.text ).join( ' ' ).slice( 0, 70 ) }"; fleet ${ fleetMoved( before, now ).length ? 'CHANGED' : 'unchanged' }` };
} );
await row( 'bulk.restore-one', 'each car of the batch can be put back from its own screen: one is, byte for byte, and the other two stay changed', async () => {
	const before = await inspect();
	const entry = before.changes.find( ( c ) => c.glc_batch === bulkBatch && Number( c.glc_car_id ) === idsC[0] );
	const screen = await partnerC.request( carUrl( idsC[0] ) );
	const offered = idsC.map( ( id ) => before.changes.find( ( c ) => c.glc_batch === bulkBatch && Number( c.glc_car_id ) === id ) );
	const links = [];
	for ( const [ i, id ] of idsC.entries() ) links.push( ( await partnerC.request( carUrl( id ) ) ).text.includes( `restore=${ offered[ i ].id }` ) );
	const confirm = formFor( ( await partnerC.request( carUrl( idsC[0], `&restore=${ entry.id }` ) ) ).text, 'glc_partner_restore' );
	const { sent } = await send( partnerC, confirm.fields );
	const now = await inspect();
	return { ok: screen.text.includes( en.source_bulk ) && links.every( Boolean ) && sent.status === 303 && now.fleet[ idsC[0] ].pricing_raw === bulkStart.fleet[ idsC[0] ].pricing_raw && sameSet( fleetMoved( before, now ), [ idsC[0] ] ) && now.changes[0].glc_source === 'restore', got: `"Put back" offered on ${ links.filter( Boolean ).length }/3 car screens; restore ${ sent.status }; car #${ idsC[0] } table ${ now.fleet[ idsC[0] ].pricing_raw === bulkStart.fleet[ idsC[0] ].pricing_raw ? 'identical to before the batch' : 'DIFFERS' }; cars that moved: #${ fleetMoved( before, now ).join( ', #' ) }` };
} );
await row( 'bulk.stale', 'a car changed by someone else between preview and apply: the apply is refused, names who and when, and writes none of the three', async () => {
	const p = await bulkPreview( partnerC, { glc_cars: idsC.join( ',' ), glc_use_pricing: '1', glc_mode: 'adjust', glc_delta: '2', glc_round: '1' } );
	const f = await formsOf( owner, { id: idsC[1] } );
	const saved = await send( owner, { ...f.fees.fields, 'glc_fee[tbilisi_airport]': '33' } );
	const before = await inspect();
	const { sent, page } = await applyPlan( partnerC, p.apply );
	const now = await inspect();
	const said = notices( page.text ).join( ' | ' );
	const again = previewOf2( page.text );
	return { ok: !! p.apply && saved.sent.status === 303 && sent.status === 303 && sent.location.includes( `plan=${ p.apply.fields.plan }` ) && said.includes( en.batch_none_written ) && new RegExp( `\\(${ en.log_who_owner }, \\d{4}-\\d\\d-\\d\\d \\d\\d:\\d\\d\\)` ).test( said ) && ! namesSomeone( said, 'partner_c' ) && said.includes( fleetC[1].title ) && fleetMoved( before, now ).length === 0 && now.change_count === before.change_count && again.cars[ idsC[1] ] === 'refused' && ! formFor( page.text, 'glc_bulk_apply' ), got: `${ sent.status }; said: "${ said.slice( 0, 170 ) }"; cars written: ${ fleetMoved( before, now ).length } of 3; the preview now shows car #${ idsC[1] } as ${ again.cars[ idsC[1] ] } and ${ formFor( page.text, 'glc_bulk_apply' ) ? 'STILL OFFERS Apply' : 'no Apply' }` };
} );
await row( 'bulk.car-taken', 'a car taken from the partner between preview and apply: the apply gets the one refusal and writes none of the three', async () => {
	const p = await bulkPreview( partnerC, { glc_cars: idsC.join( ',' ), glc_use_pricing: '1', glc_mode: 'adjust', glc_delta: '3', glc_round: '1' } );
	await assign( fleetC[2], 0 );
	const before = await inspect();
	const r = await partnerC.request( ADMIN_POST, { method: 'POST', form: p.apply.fields } );
	const now = await inspect();
	await assign( fleetC[2], fx.accounts.partner_c.id );
	const back = await partnerC.request( carUrl( idsC[2] ) );
	return { ok: !! p.apply && refusedBlind( r ) && fleetMoved( before, now ).length === 0 && now.change_count === before.change_count && back.status === 200, got: `${ r.status }; cars written: ${ fleetMoved( before, now ).length }; log entries added: ${ now.change_count - before.change_count }; car given back: ${ back.status }` };
} );
await row( 'bulk.replace', 'replace: one season table, started from one car, becomes the table of two others, seasons and all; the model car is not touched', async () => {
	const before = await inspect();
	const model = before.fleet[ idsC[0] ].pricing.map( ( s, i ) => ( i ? s : { ...s, rates: { ...s.rates, d5_7: Number( s.rates.d5_7 ) + 3 } } ) );
	const loaded = await partnerC.request( `${ BULK }&from=${ idsC[0] }` );
	const shown = ( wrap( loaded.text ).replace( /<template[\s\S]*?<\/template>/g, '' ).match( /class="glc-rate" data-band="d5_7" value="(\d+)"/ ) ?? [] )[1];
	const p = await bulkPreview( partnerC, { glc_cars: `${ idsC[1] },${ idsC[2] }`, glc_use_pricing: '1', glc_mode: 'replace', glc_table: packTable( model ) } );
	const { sent } = await applyPlan( partnerC, p.apply );
	const now = await inspect();
	const want = JSON.stringify( model.map( ( s ) => ( { from: s.from, to: s.to, rates: Object.values( s.rates ).map( Number ) } ) ) );
	const got = ( id ) => JSON.stringify( now.fleet[ id ].pricing.map( ( s ) => ( { from: s.from, to: s.to, rates: Object.values( s.rates ).map( Number ) } ) ) );
	return { ok: Number( shown ) === Number( before.fleet[ idsC[0] ].pricing[0].rates.d5_7 ) && sent.status === 303 && got( idsC[1] ) === want && got( idsC[2] ) === want && now.fleet[ idsC[1] ].pricing_raw === now.fleet[ idsC[2] ].pricing_raw && sameSet( fleetMoved( before, now ), [ idsC[1], idsC[2] ] ) && now.changes[0].glc_batch === now.changes[1].glc_batch, got: `form opened with car #${ idsC[0] }'s table (${ shown }); apply ${ sent.status }; the two cars' stored tables are ${ now.fleet[ idsC[1] ].pricing_raw === now.fleet[ idsC[2] ].pricing_raw ? 'identical to each other' : 'DIFFERENT' } and ${ got( idsC[1] ) === want ? 'equal to' : 'NOT' } the table sent; cars that moved: #${ fleetMoved( before, now ).join( ', #' ) }` };
} );
await row( 'bulk.fees', 'fees for several cars: set, free, back to the standard and "leave" each do what they say; stored as overrides only', async () => {
	const prep = await formsOf( partnerC, { id: idsC[0] } );
	await send( partnerC, { ...prep.fees.fields, 'glc_fee[batumi_airport]': '111', 'glc_fee[tbilisi_airport]': '44' } );
	const before = await inspect();
	const p = await bulkPreview( partnerC, { glc_cars: `${ idsC[0] },${ idsC[2] }`, glc_use_delivery: '1', glc_use_out_of_hours: '1', 'glc_fee_mode[tbilisi_city]': 'free', 'glc_fee_mode[kutaisi_airport]': 'set', 'glc_fee[kutaisi_airport]': '70.5', 'glc_fee_mode[batumi_airport]': 'standard', 'glc_fee_mode[tbilisi_airport]': 'leave', 'glc_fee[tbilisi_airport]': '999', 'glc_fee_mode[out_of_hours]': 'set', 'glc_fee[out_of_hours]': '22' } );
	const { sent } = await applyPlan( partnerC, p.apply );
	const now = await inspect();
	const a = now.fleet[ idsC[0] ], c = now.fleet[ idsC[2] ];
	return { ok: before.fleet[ idsC[0] ].delivery === 'a:2:{s:15:"tbilisi_airport";s:2:"44";s:14:"batumi_airport";s:3:"111";}' && sent.status === 303 && a.delivery === 'a:3:{s:12:"tbilisi_city";s:1:"0";s:15:"tbilisi_airport";s:2:"44";s:15:"kutaisi_airport";s:4:"70.5";}' && c.delivery === 'a:2:{s:12:"tbilisi_city";s:1:"0";s:15:"kutaisi_airport";s:4:"70.5";}' && a.out_of_hours === '22' && c.out_of_hours === '22' && a.pricing_raw === before.fleet[ idsC[0] ].pricing_raw && sameSet( fleetMoved( before, now ), [ idsC[0], idsC[2] ] ) && p.seen.text.includes( `${ en.place_batumi_airport }: $111 → Geolander standard ($100)` ), got: `${ sent.status }; car #${ idsC[0] }: ${ before.fleet[ idsC[0] ].delivery } → ${ a.delivery }, early/late "${ a.out_of_hours }"; car #${ idsC[2] }: ${ c.delivery }; prices untouched: ${ a.pricing_raw === before.fleet[ idsC[0] ].pricing_raw }` };
} );
await row( 'bulk.owner', 'the owner changes cars of different partners, and Geolander\'s own, in one go', async () => {
	const before = await inspect();
	const p = await bulkPreview( owner, { glc_cars: [ mine.id, theirs.id, other.id ].join( ',' ), glc_use_out_of_hours: '1', 'glc_fee_mode[out_of_hours]': 'set', 'glc_fee[out_of_hours]': '17' }, OWNER_BULK );
	const { sent } = await applyPlan( owner, p.apply );
	const now = await inspect();
	return { ok: sent.status === 303 && sameSet( fleetMoved( before, now ), [ mine.id, theirs.id, other.id ] ) && [ mine.id, theirs.id, other.id ].every( ( id ) => now.fleet[ id ].out_of_hours === '17' ) && now.changes.slice( 0, 3 ).every( ( c ) => c.glc_batch === now.changes[0].glc_batch && Number( c.glc_actor ) === fx.accounts.owner.id ), got: `${ sent.status }; cars that moved: #${ fleetMoved( before, now ).join( ', #' ) }; logged by #${ now.changes[0].glc_actor } as one batch` };
} );
await row( 'bulk.request-size', 'the bulk form stays small however long the fleet: 19 cars and a full season table go in a fixed number of fields', async () => {
	const all = Object.keys( bulkStart.fleet );
	const fields = bulkFields( ( await owner.request( OWNER_BULK ) ).text, { glc_cars: all.join( ',' ), glc_use_pricing: '1', glc_mode: 'replace', glc_table: packTable( bulkStart.fleet[ idsC[0] ].pricing ), glc_use_delivery: '1', glc_use_out_of_hours: '1', ...Object.fromEntries( [ 'tbilisi_city', 'tbilisi_airport', 'kutaisi_airport', 'batumi_airport', 'out_of_hours' ].flatMap( ( k ) => [ [ `glc_fee_mode[${ k }]`, 'leave' ], [ `glc_fee[${ k }]`, '' ] ] ) ) } );
	const bytes = new URLSearchParams( fields ).toString().length;
	const r = await owner.request( ADMIN_POST, { method: 'POST', form: fields } );
	return { ok: Object.keys( fields ).length <= 22 && bytes < 131072 / 20 && r.status === 303, got: `${ all.length } cars and ${ bulkStart.fleet[ idsC[0] ].pricing.length } seasons in ${ Object.keys( fields ).length } fields, ${ bytes } bytes (the server stops at 1,000 fields and 131,072 bytes); answered ${ r.status }` };
} );

/* --------------------------------------------------- the Excel file: what the round trip does */

await row( 'xlsx.unchanged', 'the downloaded file uploaded as it is: nothing differs, nothing to apply', async () => {
	const page = await partnerC.request( XLSX );
	const file = Buffer.from( await ( await fetch( base + ADMIN_POST, { method: 'POST', headers: { cookie: [ ...partnerC.cookies ].map( ( [ k, v ] ) => `${ k }=${ v }` ).join( '; ' ), 'content-type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams( formFor( page.text, 'glc_xlsx_download' ).fields ).toString() } ) ).arrayBuffer() );
	const { sent, page: shown } = await uploadTo( partnerC, XLSX, uploadNonce( page.text ), file );
	const seen = previewOf2( shown.text );
	return { ok: sent.status === 303 && seen.stops.length === 0 && seen.cells.length === 0 && seen.text.includes( en.batch_nothing ) && ! formFor( shown.text, 'glc_xlsx_apply' ), got: `${ sent.status }; a file of ${ file.length } bytes; said: "${ seen.text.match( /Nothing differs[^.]*\./ )?.[0] ?? seen.stops[0] ?? '?' }"` };
} );
await row( 'xlsx.apply', 'a file with two prices and one fee changed: the preview lists exactly those three cells with the figures worked out twice, and Apply stores them as one batch from "excel"', async () => {
	const before = await inspect();
	const made = await wp( 'xlsx', fx.accounts.partner_c.login, idsC.join( ',' ), 'Prices!H2=66', 'Prices!N12=31.5', 'Fees!E3=9', 'Prices!I2=' );
	const page = await partnerC.request( XLSX );
	const { sent, page: shown } = await uploadTo( partnerC, XLSX, uploadNonce( page.text ), Buffer.from( made.file, 'base64' ) );
	const seen = previewOf2( shown.text );
	const mid = await inspect();
	const apply = formFor( shown.text, 'glc_xlsx_apply' );
	const done = await send( partnerC, apply.fields );
	const now = await inspect();
	const carA = made.rows[1][0], carB = made.rows[11][0];
	const log = now.changes.filter( ( c ) => c.glc_batch === now.changes[0].glc_batch );
	return { ok: sent.status === 303 && JSON.stringify( seen.cells ) === JSON.stringify( [ 'Prices!H2', 'Prices!N12', 'Fees!E3' ] ) && seen.faults.every( ( f ) => f === 0 ) && fleetMoved( before, mid ).length === 0 && done.sent.status === 303 && now.fleet[ carA ].pricing[0].rates.d1_2 === 66 && now.fleet[ carA ].pricing[0].rates.d3_4 === before.fleet[ carA ].pricing[0].rates.d3_4 && now.fleet[ carB ].pricing[1].rates.d31p === 31.5 && log.length === 3 && log.every( ( c ) => c.glc_source === 'excel' ) && fleetMoved( before, now ).length === 2, got: `${ sent.status }; cells listed: ${ seen.cells.join( ', ' ) }; faults ${ seen.faults.join( '/' ) }; after the upload alone the fleet is ${ fleetMoved( before, mid ).length ? 'CHANGED' : 'unchanged' }; apply ${ done.sent.status }; stored: car #${ carA } 1-2 days ${ now.fleet[ carA ].pricing[0].rates.d1_2 } (the emptied 3-4 day cell kept ${ now.fleet[ carA ].pricing[0].rates.d3_4 }), car #${ carB } 31+ days ${ now.fleet[ carB ].pricing[1].rates.d31p }; log: ${ log.map( ( c ) => `car ${ c.glc_car_id } ${ c.glc_kind }/${ c.glc_source }` ).join( ', ' ) }` };
} );
await row( 'xlsx.season-date', 'a season date changed in the file: the whole file is refused, the cell is named, and the message says dates are changed in the panel', async () => {
	const page = await partnerC.request( XLSX );
	const { page: shown } = await uploadTo( partnerC, XLSX, uploadNonce( page.text ), await fileFor( fx.accounts.partner_c.login, idsC, 'Prices!E3=11-05', 'Prices!H20=70' ) );
	const seen = previewOf2( shown.text );
	return { ok: seen.stops.length === 1 && seen.stops[0].startsWith( 'Prices!E3:' ) && seen.stops[0].includes( 'in the panel, not in the file' ) && ! formFor( shown.text, 'glc_xlsx_apply' ) && seen.cells.length === 0, got: `"${ seen.stops[0] ?? 'not refused' }"; the good price change in the same file: ${ seen.cells.length ? 'LISTED' : 'not taken' }` };
} );
await row( 'xlsx.stale', 'a file downloaded before someone else changed one of its cars: refused whole, naming who changed the car and when', async () => {
	const old = await fileFor( fx.accounts.partner_c.login, idsC, 'Prices!H20=71' );
	const f = await formsOf( owner, { id: idsC[0] } );
	const saved = await send( owner, { ...f.fees.fields, 'glc_fee[tbilisi_city]': '8' } );
	const before = await inspect();
	const page = await partnerC.request( XLSX );
	const { page: shown } = await uploadTo( partnerC, XLSX, uploadNonce( page.text ), old );
	const seen = previewOf2( shown.text );
	const now = await inspect();
	return { ok: saved.sent.status === 303 && seen.stops.some( ( s ) => new RegExp( `was changed after this file was downloaded \\(${ en.log_who_owner }, \\d{4}-\\d\\d-\\d\\d \\d\\d:\\d\\d\\)` ).test( s ) ) && ! namesSomeone( seen.stops.join( ' ' ), 'partner_c' ) && ! formFor( shown.text, 'glc_xlsx_apply' ) && fleetMoved( before, now ).length === 0, got: `"${ ( seen.stops[0] ?? 'not refused' ).slice( 0, 150 ) }"` };
} );
await row( 'xlsx.too-large', 'a file over the limit gets this screen\'s own sentence with both sizes, never the web server\'s error page, at 200 KB and at 3 MB', async () => {
	const page = await partnerC.request( XLSX );
	const got = [];
	for ( const size of [ 200 * 1024, 3 * 1024 * 1024 ] ) {
		const junk = Buffer.alloc( size ); for ( let i = 0; i < size; i += 7 ) junk[ i ] = ( i * 31 ) % 251;
		const { sent, page: shown } = await uploadTo( partnerC, XLSX, uploadNonce( page.text ), junk );
		const seen = previewOf2( shown?.text ?? '' );
		got.push( { size, status: sent.status, said: seen.stops[0] ?? '', ours: ( shown?.text ?? '' ).includes( 'glc-panel' ) } );
	}
	// Control: the same 200 KB sent to admin-post.php is cut off by the web server itself. That is why the upload does not go there.
	const body = new FormData(); body.set( 'action', 'glc_xlsx_apply' ); body.set( 'glc_file', new Blob( [ Buffer.alloc( 200 * 1024, 1 ) ] ), 'x.xlsx' );
	const wall = await partnerC.request( ADMIN_POST, { method: 'POST', body } );
	return { ok: got.every( ( g ) => g.status === 303 && g.ours && g.said.includes( `This file is ${ ( g.size / 1024 ).toLocaleString( 'en-US' ) } KB` ) && g.said.includes( 'accepted here is 100 KB' ) ) && wall.status === 413, got: `${ got.map( ( g ) => `${ g.size / 1024 } KB → ${ g.status }, "${ g.said.slice( 0, 74 ) }…"` ).join( '; ' ) }; control, the same body to admin-post.php: ${ wall.status }` };
} );
await row( 'xlsx.oversize-no-nonce', 'a request too large for PHP to read arrives empty, its nonce included: nothing is kept for it in the database, and the person is sent to the sentence that says the file was too large and what the limit is', async () => {
	// 35 MB: over PHP's own limit for a request (34 MB in this image), which is the only way a body arrives with nothing in it.
	const before = await inspect();
	const sent = await partnerC.request( XLSX, { method: 'POST', body: Buffer.alloc( 35 * 1024 * 1024, 1 ), headers: { 'content-type': 'application/octet-stream' } } );
	const shown = sent.status === 303 && sent.location ? await partnerC.request( sent.location.replace( base, '' ) ) : null;
	const seen = previewOf2( shown?.text ?? '' );
	const now = await inspect();
	// The same body from someone who is not signed in gets no further than the sign-in page.
	const stranger = await anon.request( XLSX, { method: 'POST', body: Buffer.alloc( 35 * 1024 * 1024, 1 ), headers: { 'content-type': 'application/octet-stream' } } );
	const last = await inspect();
	return { ok: sent.status === 303 && /[?&]too_big=35840(&|#|$)/.test( sent.location ) && ! /[?&]plan=/.test( sent.location ) && seen.stops.length === 1 && seen.stops[0].includes( 'This file is 35,840 KB' ) && seen.stops[0].includes( 'accepted here is 100 KB' ) && ! formFor( shown.text, 'glc_xlsx_apply' ) && now.plans === before.plans && now.kept === before.kept && [ 302, 403 ].includes( stranger.status ) && last.kept === before.kept, got: `${ sent.status } → ${ sent.location.replace( base, '' ).slice( 0, 80 ) }; said: "${ ( seen.stops[0] ?? 'nothing' ).slice( 0, 70 ) }…"; plans waiting ${ before.plans } → ${ now.plans }, everything the panel keeps ${ before.kept } → ${ now.kept }; not signed in: ${ stranger.status }, kept ${ last.kept }` };
} );
await row( 'xlsx.not-a-workbook','a file that is not an Excel workbook, an empty upload, and a workbook that declares an entity are each refused with a reason and nothing to apply', async () => {
	const page = await partnerC.request( XLSX );
	const nonce = uploadNonce( page.text );
	const text = await uploadTo( partnerC, XLSX, nonce, Buffer.from( 'car_id,plate\n7,LL802ML\n' ), 'prices.csv' );
	const hostile = await uploadTo( partnerC, XLSX, nonce, Buffer.from( ( await wp( 'xlsx-hostile' ) ).file, 'base64' ) );
	const empty = new FormData(); empty.set( '_wpnonce', nonce );
	const none = await partnerC.request( XLSX, { method: 'POST', body: empty } );
	const noneShown = previewOf2( ( await partnerC.request( none.location.replace( base, '' ) ) ).text );
	const a = previewOf2( text.page.text ), b = previewOf2( hostile.page.text );
	return { ok: a.stops[0] === en.xlsx_err_not_xlsx && b.stops[0] === en.xlsx_err_unsafe && ! hostile.page.text.includes( 'DB_PASSWORD' ) && noneShown.stops[0] === en.xlsx_err_no_file && [ text.page.text, hostile.page.text ].every( ( html ) => ! formFor( html, 'glc_xlsx_apply' ) ), got: `csv: "${ a.stops[0]?.slice( 0, 50 ) }…"; entity: "${ b.stops[0]?.slice( 0, 60 ) }…"; no file: "${ noneShown.stops[0] }"` };
} );
await row( 'xlsx.ka', 'a Georgian partner reads a refused file\'s reason in Georgian, cell named', async () => {
	const page = await partnerB.request( XLSX );
	const { page: shown } = await uploadTo( partnerB, XLSX, uploadNonce( page.text ), await fileFor( fx.accounts.partner_b.login, [ theirs.id ], 'Prices!E2=11-05' ) );
	const seen = previewOf2( shown.text );
	return { ok: seen.stops.length === 1 && seen.stops[0].startsWith( 'Prices!E2:' ) && /[ა-ჿ]{4,}/.test( seen.stops[0] ) && seen.text.includes( ka.batch_refused ), got: `"${ seen.stops[0]?.slice( 0, 130 ) ?? 'not refused' }"` };
} );

/* ------------------------------------------------ photos and details: what the screens do */

const photoForm = async ( session, car ) => formTicked( ( await session.request( carUrl( car.id ) ) ).text, 'glc_partner_save_photos' );
const specForm = async ( session, car ) => formTicked( ( await session.request( carUrl( car.id ) ) ).text, 'glc_partner_save_specs' );
const entry = ( log ) => ( { before: JSON.parse( log.glc_before ?? 'null' ), after: JSON.parse( log.glc_after ?? 'null' ) } );
let wide2 = 0, photosSaved, photosRaw = '';
await row( 'photos.save', 'the partner puts two uploads into the car\'s gallery, picks the main one and describes one: stored as the site reads it, the photos become the car\'s, the empty description gets the generated one, and the change is logged with before and after', async () => {
	wide2 = ( await pick( partner, picker, await image( 1700, 1100, 'webp' ), 'glc-test-second.webp', 'image/webp' ) ).id;
	const f = await photoForm( partner, mine );
	const before = await inspect();
	const { sent, page } = await send( partner, { ...f.fields, glc_gallery: `${ wide },${ wide2 }`, glc_main: String( wide2 ), [ `glc_alt[${ wide }]` ]: 'Front of the test car', [ `glc_alt[${ wide2 }]` ]: '', actor: fx.accounts.owner.id, source: 'cli' } );
	photosSaved = await inspect();
	const car = photosSaved.cars[ mine.id ], log = photosSaved.last_change, said = notices( page.text ).join( ' | ' );
	const p = await photosOf( wide, wide2 );
	const { before: was, after: is } = entry( log );
	photosRaw = car.gallery;
	return { ok: sent.status === 303 && said.includes( 'Saved as version' ) && car.gallery === `a:2:{i:0;i:${ wide };i:1;i:${ wide2 };}` && car.thumbnail === String( wide2 ) && [ wide, wide2 ].every( ( id ) => Number( p[ id ].post_parent ) === mine.id && Number( p[ id ].post_author ) === fx.accounts.partner.id ) && p[ wide ].alt === 'Front of the test car' && p[ wide2 ].alt === `${ mine.title } — photo 2` && p[ wide2 ].disk_mime === 'image/jpeg' && /\.jpg$/.test( p[ wide2 ].file ) && Number( car.rev ) === Number( before.cars[ mine.id ].rev ) + 1 && photosSaved.change_count === before.change_count + 1 && log.glc_kind === 'photos' && log.glc_source === 'panel' && Number( log.glc_actor ) === fx.accounts.partner.id && JSON.stringify( was.gallery ) === '[]' && JSON.stringify( is.gallery ) === JSON.stringify( [ wide, wide2 ] ) && is.main === wide2 && is.alts[ wide2 ] === `${ mine.title } — photo 2`, got: `${ sent.status }; said: "${ said.slice( 0, 110 ) }"; glc_gallery ${ car.gallery }, _thumbnail_id ${ car.thumbnail }; attachments: parent ${ p[ wide ].post_parent } and ${ p[ wide2 ].post_parent }, author #${ p[ wide ].post_author }, alt "${ p[ wide ].alt }" and "${ p[ wide2 ].alt }"; the WebP is stored as ${ p[ wide2 ].file } (${ p[ wide2 ].disk_mime }, ${ p[ wide2 ].width } wide); log #${ log.ID } ${ log.glc_kind }/${ log.glc_source } by #${ log.glc_actor }` };
} );
await row( 'photos.public-page', 'the car\'s public page now shows the two photos, with those descriptions, as JPEGs', async () => {
	const link = decode( ( await partner.request( carUrl( mine.id ) ) ).text.match( /<a href="([^"]+)">[^<]*<\/a><\/li>\s*<\/ul>/ )?.[1] ?? '' ).replace( base, '' );
	const r = await anon.request( link || '/' );
	const p = await photosOf( wide, wide2 );
	const names = [ wide, wide2 ].map( ( id ) => p[ id ].file.replace( /\.jpg$/, '' ).split( '/' ).pop() );
	return { ok: !! link && r.status === 200 && names.every( ( n ) => r.text.includes( n ) ) && r.text.includes( 'alt="Front of the test car"' ) && r.text.includes( `alt="${ mine.title } — photo 2"` ) && ! /glc-test-[a-z-]+\.(png|webp)/.test( r.text ), got: `${ r.status } ${ link }; both files and both alt texts ${ names.every( ( n ) => r.text.includes( n ) ) && r.text.includes( 'alt="Front of the test car"' ) ? 'on the page' : 'MISSING' }; no .png or .webp of the uploads is served` };
} );
await row( 'photos.remove', 'a photo taken out of the gallery is not deleted: the gallery is shorter, the attachment and its file are still there and still in the partner\'s library', async () => {
	const f = await photoForm( partner, mine );
	const { sent, page } = await send( partner, { ...f.fields, glc_gallery: String( wide2 ), glc_main: String( wide2 ) } );
	const now = await inspect();
	const p = ( await photosOf( wide ) )[ wide ];
	const library = ( await partner.request( '/wp-admin/admin-ajax.php', { method: 'POST', form: { action: 'query-attachments', 'query[posts_per_page]': '100' } } ) ).data?.data ?? [];
	const said = notices( page.text ).join( ' | ' );
	return { ok: sent.status === 303 && now.cars[ mine.id ].gallery === `a:1:{i:0;i:${ wide2 };}` && p && p.on_disk && p.post_status === 'inherit' && Number( p.post_parent ) === mine.id && library.some( ( a ) => a.id === wide ) && said.includes( 'stay in the library' ), got: `${ sent.status }; said: "${ said.slice( 0, 110 ) }"; glc_gallery ${ now.cars[ mine.id ].gallery }; the photo taken out: still a row (${ p?.post_status }), file on disk ${ p?.on_disk ? 'yes' : 'NO' }, in the partner's library ${ library.some( ( a ) => a.id === wide ) ? 'yes' : 'NO' }` };
} );
await row( 'photos.restore', 'the earlier gallery is put back from the history after a confirmation: the same stored value as before, and a new log entry', async () => {
	const last = ( await inspect() ).last_change;
	const confirm = formFor( ( await partner.request( carUrl( mine.id, `&restore=${ last.ID }` ) ) ).text, 'glc_partner_restore' );
	const { sent, page } = await send( partner, confirm.fields );
	const now = await inspect();
	const p = await photosOf( wide, wide2 );
	return { ok: !! confirm && sent.status === 303 && now.cars[ mine.id ].gallery === photosRaw && now.cars[ mine.id ].thumbnail === String( wide2 ) && p[ wide ].alt === 'Front of the test car' && now.last_change.glc_kind === 'photos' && now.last_change.glc_source === 'restore' && now.last_change.glc_note === `restore of #${ last.ID }`, got: `${ sent.status }; said: "${ notices( page.text ).join( ' | ' ).slice( 0, 100 ) }"; glc_gallery ${ now.cars[ mine.id ].gallery } ${ now.cars[ mine.id ].gallery === photosRaw ? '= the value before' : 'DIFFERS' }; log ${ now.last_change.glc_kind }/${ now.last_change.glc_source } "${ now.last_change.glc_note }"` };
} );
let specsBefore;
await row( 'specs.save', 'the partner changes the car\'s details, with a plate, a year and a title sent beside them: the details are stored one row each, nothing about what the car is moves, and the change is logged', async () => {
	const f = await specForm( partner, mine );
	specsBefore = await inspect();
	const checked = new Date( Date.now() - 2 * 86400000 ).toISOString().slice( 0, 10 );
	const { sent, page } = await send( partner, { ...f.fields, 'glc_spec[glc_seats]': '4', 'glc_spec[glc_transmission]': 'manual', 'glc_spec[glc_fuel_type]': 'diesel', 'glc_spec[glc_drivetrain]': '4x4', 'glc_spec[glc_color]': 'Dark grey', 'glc_spec[glc_license_category]': 'B', 'glc_spec[glc_evidence_checked_on]': checked, 'glc_spec[glc_last_service_on]': '2026-01-15', 'glc_spec[glc_odometer_km]': '123456', 'glc_spec[glc_year]': '1999', 'glc_spec[glc_registration]': 'XX-000-XX', glc_year: '1999', glc_registration: 'XX-000-XX', post_title: 'Hacked', glc_price_from: '1' } );
	const now = await inspect();
	const car = now.cars[ mine.id ], log = now.last_change, said = notices( page.text ).join( ' | ' );
	const want = { glc_seats: '4', glc_transmission: 'manual', glc_fuel_type: 'diesel', glc_drivetrain: '4x4', glc_color: 'Dark grey', glc_license_category: 'B', glc_available: '1', glc_evidence_checked_on: checked, glc_last_service_on: '2026-01-15', glc_odometer_km: '123456' };
	const { before: was, after: is } = entry( log );
	return { ok: sent.status === 303 && JSON.stringify( car.specs ) === JSON.stringify( want ) && JSON.stringify( car.identity ) === JSON.stringify( specsBefore.cars[ mine.id ].identity ) && Number( car.rev ) === Number( specsBefore.cars[ mine.id ].rev ) + 1 && log.glc_kind === 'specs' && log.glc_source === 'panel' && was.glc_seats === 5 && is.glc_seats === 4 && is.glc_available === true && Object.keys( is ).length === 10 && said.includes( `${ en.spec_seats }: 5 → 4` ) && said.includes( `${ en.spec_transmission }: Automatic → Manual` ), got: `${ sent.status }; said: "${ said.slice( 0, 150 ) }…"; stored: ${ JSON.stringify( car.specs ) }; title "${ car.identity.title }", plate ${ car.identity.plate }, year ${ car.identity.year } ${ JSON.stringify( car.identity ) === JSON.stringify( specsBefore.cars[ mine.id ].identity ) ? '(as before)' : 'CHANGED' }; log ${ log.glc_kind }/${ log.glc_source }` };
} );
await row( 'specs.available', 'the partner marks the car as not available and available again: stored as WordPress keeps a yes/no, each change logged and said in words', async () => {
	const f = await specForm( partner, mine );
	const off = await send( partner, without( f.fields, 'glc_spec[glc_available]' ) );
	const stored = ( await inspect() ).cars[ mine.id ].specs.glc_available;
	const g = await specForm( partner, mine );
	const on = await send( partner, { ...g.fields, 'glc_spec[glc_available]': '1' } );
	const now = await inspect();
	const saidOff = notices( off.page.text ).join( ' | ' ), saidOn = notices( on.page.text ).join( ' | ' );
	// '' is a row that holds nothing (how WordPress keeps "no"), not a missing row.
	return { ok: off.sent.status === 303 && stored === '' && saidOff.includes( `${ en.spec_available }: yes → no` ) && now.cars[ mine.id ].specs.glc_available === '1' && saidOn.includes( `${ en.spec_available }: no → yes` ) && ! g.html.includes( 'name="glc_spec[glc_available]" value="1" checked' ), got: `off: stored "${ stored }", said "${ saidOff.slice( 0, 70 ) }"; on again: stored "${ now.cars[ mine.id ].specs.glc_available }"` };
} );
await row( 'specs.stale', 'two people, one car: the owner changes a detail, and the partner\'s save of photos from a page opened before that is refused and names who and when', async () => {
	const stale = await photoForm( partner, mine );
	const o = await specForm( owner, mine );
	const first = await send( owner, { ...o.fields, 'glc_spec[glc_color]': 'Graphite' } );
	const before = await inspect();
	const { sent, page } = await send( partner, { ...stale.fields, glc_gallery: `${ wide2 },${ wide }` } );
	const now = await inspect();
	const said = notices( page.text ).join( ' | ' );
	return { ok: first.sent.status === 303 && before.cars[ mine.id ].specs.glc_color === 'Graphite' && sent.status === 303 && said.includes( en.not_saved ) && said.includes( `(${ en.log_who_owner }, ` ) && ! namesSomeone( said, 'partner' ) && now.cars[ mine.id ].gallery === before.cars[ mine.id ].gallery && now.change_count === before.change_count, got: `the owner saved the colour first (logged as ${ before.last_change.glc_kind }/${ before.last_change.glc_source }); the partner's older page: ${ sent.status }, said: "${ said.slice( 0, 120 ) }"; gallery ${ now.cars[ mine.id ].gallery === before.cars[ mine.id ].gallery ? 'unchanged' : 'CHANGED' }` };
} );
await row( 'specs.restore', 'the details from before the partner\'s change are put back from the history, value for value', async () => {
	const page = await partner.request( carUrl( mine.id ) );
	const ids = [ ...page.text.matchAll( /restore=(\d+)/g ) ].map( ( m ) => Number( m[1] ) );
	// The oldest "details" entry on the page is the partner's first save; its "before" is the car as the test found it.
	const rows = [ ...page.text.matchAll( /<tr>\s*<td>[^<]*<\/td>\s*<td>[^<]*<\/td>\s*<td>([^<]*)<\/td>[\s\S]*?<\/tr>/g ) ].filter( ( m ) => m[1] === en.kind_specs ).map( ( m ) => Number( m[0].match( /restore=(\d+)/ )?.[1] ?? 0 ) ).filter( Boolean );
	const target = rows[ rows.length - 1 ];
	const confirm = formFor( ( await partner.request( carUrl( mine.id, `&restore=${ target }` ) ) ).text, 'glc_partner_restore' );
	const { sent } = await send( partner, confirm.fields );
	const now = await inspect();
	const same = Object.keys( now.cars[ mine.id ].specs ).filter( ( key ) => ( now.cars[ mine.id ].specs[ key ] ?? '' ) !== ( specsBefore.cars[ mine.id ].specs[ key ] ?? '' ) );
	return { ok: ids.length > 0 && !! target && sent.status === 303 && same.length === 0 && now.last_change.glc_kind === 'specs' && now.last_change.glc_source === 'restore', got: `${ sent.status }; entry #${ target } put back; ${ same.length ? 'DIFFER: ' + same.join( ', ' ) : 'all ten details equal what the car had before (' + JSON.stringify( now.cars[ mine.id ].specs ).slice( 0, 90 ) + '…)' }; log ${ now.last_change.glc_kind }/${ now.last_change.glc_source }` };
} );
let ownerPhoto = 0;
await row( 'specs.today-display-zone', 'a car\'s dated facts take "today" from the display time zone, not from the site\'s UTC clock: at 21:00 UTC it is already tomorrow in Tbilisi and tomorrow\'s date is a day that has come, the day after is not; the site\'s own time zone is still UTC', async () => {
	const c = await wp( 'evidence-clock', '2026-03-09 21:00:00', '2026-03-09', '2026-03-10', '2026-03-11' );
	const here = new Intl.DateTimeFormat( 'sv-SE', { timeZone: c.display_timezone, year: 'numeric', month: '2-digit', day: '2-digit' } ).format( new Date() );
	return { ok: c.display_timezone !== 'UTC' && c.utc_date === '2026-03-09' && c.today === '2026-03-10' && c.kept['2026-03-09'] === true && c.kept['2026-03-10'] === true && c.kept['2026-03-11'] === false && c.today_now === here && c.site_timezone === '+00:00', got: `at 2026-03-09 21:00 UTC "today" is ${ c.today } (${ c.display_timezone }); 03-10 ${ c.kept['2026-03-10'] ? 'accepted' : 'REFUSED' }, 03-11 ${ c.kept['2026-03-11'] ? 'ACCEPTED' : 'refused' }; today right now ${ c.today_now }; site time zone ${ c.site_timezone }` };
} );
await row( 'photos.owner','the owner does the same for any car: an upload from the car\'s screen is stored as a car photo, and any photo of the library may be added, a partner\'s upload included', async () => {
	const screen = await owner.request( carUrl( other.id ) );
	const up = await pick( owner, pickerParams( screen.text ), await image( 2600, 1700, 'png' ), 'glc-test-owner.png', 'image/png' );
	ownerPhoto = up.id;
	const plain = await pick( owner, without( pickerParams( screen.text ), 'glc_car_photo' ), await image( 2600, 1700, 'png' ), 'glc-test-owner-plain.png', 'image/png' );
	const f = formTicked( screen.text, 'glc_partner_save_photos' );
	const { sent, page } = await send( owner, { ...f.fields, glc_gallery: `${ f.fields.glc_gallery },${ up.id },${ foreign }` } );
	const now = await inspect();
	const p = await photosOf( up.id, plain.id, foreign );
	return { ok: up.ok && p[ up.id ].disk_mime === 'image/jpeg' && p[ up.id ].width === 1920 && plain.ok && p[ plain.id ].disk_mime === 'image/png' && sent.status === 303 && now.cars[ other.id ].gallery_ids.slice( -2 ).join() === `${ up.id },${ foreign }` && Number( p[ foreign ].post_parent ) === other.id && Number( p[ foreign ].post_author ) === fx.accounts.partner_b.id && now.last_change.glc_kind === 'photos' && Number( now.last_change.glc_actor ) === fx.accounts.owner.id, got: `upload from the car's screen: ${ p[ up.id ].disk_mime } ${ p[ up.id ].width } wide; control, the same file without the car-photo mark (as from the Media screen): ${ p[ plain.id ]?.disk_mime } ${ p[ plain.id ]?.width } wide, untouched; save ${ sent.status }, said "${ notices( page.text ).join( ' | ' ).slice( 0, 60 ) }"; gallery now ends …,${ now.cars[ other.id ].gallery_ids.slice( -2 ).join() }` };
} );

/* --------------------------- the owner's WordPress car edit screen: details and gallery through the store */

await row( 'metabox.stale', 'the owner saves the WordPress car edit screen from a page opened before a partner\'s change: the text is saved, the details are not written, and the screen says which and why', async () => {
	const edit = await owner.request( `/wp-admin/post.php?post=${ theirs.id }&action=edit` );
	const box = metaBoxes( edit.text );
	// Meanwhile the car's partner changes a detail on the partner panel.
	const g = await specForm( partnerB, theirs );
	const theirsSave = await send( partnerB, { ...g.fields, 'glc_spec[glc_seats]': '6' } );
	const before = await inspect();
	const sent = await owner.request( box.url, { method: 'POST', form: { ...box.fields, post_title: `${ theirs.title } (owner edit)`, glc_color: 'Owner blue', glc_fuel_economy_note: 'owner note' } } );
	const now = await inspect();
	const car = now.cars[ theirs.id ];
	const reload = metaBoxes( ( await owner.request( `/wp-admin/post.php?post=${ theirs.id }&action=edit` ) ).text );
	const status = await owner.request( box.status );
	const again = await owner.request( box.status );
	const stranger = await partner.request( box.status );
	return { ok: theirsSave.sent.status === 303 && before.cars[ theirs.id ].specs.glc_seats === '6' && box.fields.glc_rev === String( Number( before.cars[ theirs.id ].rev ) - 1 ) && [ 200, 302 ].includes( sent.status ) && car.identity.title === `${ theirs.title } (owner edit)` && car.identity.fuel_note === 'owner note' && car.specs.glc_seats === '6' && car.specs.glc_color === before.cars[ theirs.id ].specs.glc_color && car.rev === before.cars[ theirs.id ].rev && now.change_count === before.change_count && reload.notice.includes( `Not written: ${ en.spec_color }, ${ en.spec_seats }` ) && reload.notice.includes( `after this page was opened (${ fx.accounts.partner_b.name }, ` ) && status.data?.refused === true && status.data?.rev === null && status.data?.lines?.length === 2 && again.data?.refused === false && denied( stranger ), got: `the page showed version ${ box.fields.glc_rev }, the partner's save made it ${ before.cars[ theirs.id ].rev }; the owner's save: ${ sent.status }; title "${ car.identity.title }" and the owner-only note "${ car.identity.fuel_note }" saved; seats ${ car.specs.glc_seats } (the partner's), colour ${ car.specs.glc_color } (not the owner's); the box on reload says: "${ reload.notice.slice( 0, 170 ) }"; the editor is told refused=${ status.data?.refused }, no new version; asked again: nothing left; a partner asking: ${ stranger.status }` };
} );
await row( 'metabox.saves', 'from a fresh page the owner\'s details and gallery are written through the store, logged as coming from the WordPress editor; the editor is handed the new version, and a second save from the same page goes through', async () => {
	const edit = await owner.request( `/wp-admin/post.php?post=${ theirs.id }&action=edit` );
	const box = metaBoxes( edit.text );
	const before = await inspect();
	const ids = before.cars[ theirs.id ].gallery_ids;
	const turned = [ ...ids.slice( 1 ), ids[0] ];
	const sent = await owner.request( box.url, { method: 'POST', form: { ...box.fields, glc_color: 'Owner blue', glc_seats: '7', glc_gallery: turned.join( ',' ) } } );
	const mid = await inspect();
	const status = await owner.request( box.status );
	// What the block editor's page does next: keep its fields, take the version it was handed, save again.
	const second = await owner.request( box.url, { method: 'POST', form: { ...box.fields, glc_color: 'Owner blue', glc_seats: '7', glc_gallery: turned.join( ',' ), glc_rev: String( status.data?.rev ), glc_drivetrain: 'AWD' } } );
	const now = await inspect();
	const kinds = mid.changes.slice( 0, 2 ).map( ( c ) => `${ c.glc_kind }/${ c.glc_source }` ).sort().join( ' + ' );
	// And without the version: the same page saving once more with the one it was opened at is refused.
	const third = await owner.request( box.url, { method: 'POST', form: { ...box.fields, glc_color: 'Stale red', glc_gallery: turned.join( ',' ) } } );
	const last = await inspect();
	return { ok: [ 200, 302 ].includes( sent.status ) && mid.cars[ theirs.id ].specs.glc_color === 'Owner blue' && mid.cars[ theirs.id ].specs.glc_seats === '7' && mid.cars[ theirs.id ].gallery_ids.join() === turned.join() && Number( mid.cars[ theirs.id ].rev ) === Number( before.cars[ theirs.id ].rev ) + 1 && mid.change_count === before.change_count + 2 && kinds === 'photos/metabox + specs/metabox' && mid.changes[0].glc_batch === mid.changes[1].glc_batch && status.data?.refused === false && status.data?.rev === Number( mid.cars[ theirs.id ].rev ) && now.cars[ theirs.id ].specs.glc_drivetrain === 'AWD' && Number( now.cars[ theirs.id ].rev ) === Number( mid.cars[ theirs.id ].rev ) + 1 && last.cars[ theirs.id ].specs.glc_color === 'Owner blue' && last.change_count === now.change_count && JSON.stringify( mid.cars[ theirs.id ].identity ) === JSON.stringify( before.cars[ theirs.id ].identity ), got: `save ${ sent.status }: colour ${ mid.cars[ theirs.id ].specs.glc_color }, seats ${ mid.cars[ theirs.id ].specs.glc_seats }, gallery turned by one, version ${ before.cars[ theirs.id ].rev } → ${ mid.cars[ theirs.id ].rev }, entries ${ kinds } in one batch; the editor is handed version ${ status.data?.rev }; second save with it: drive system ${ now.cars[ theirs.id ].specs.glc_drivetrain }, version ${ now.cars[ theirs.id ].rev }; a third with the page's first version: colour stays ${ last.cars[ theirs.id ].specs.glc_color }, nothing logged; plate, year and headline price as before` };
} );
await row( 'metabox.unchanged', 'saving the edit screen without touching a detail or the gallery does not go to the store at all: no version step, no log entry, even from an older page', async () => {
	const edit = await owner.request( `/wp-admin/post.php?post=${ legacy.id }&action=edit` );
	const box = metaBoxes( edit.text );
	const o = await specForm( owner, legacy );
	await send( owner, { ...o.fields, 'glc_spec[glc_drivetrain]': 'AWD' } );
	const fresh = metaBoxes( ( await owner.request( `/wp-admin/post.php?post=${ legacy.id }&action=edit` ) ).text );
	const before = await inspect();
	// The older page's version, with the fields the car has now: nothing differs, so nothing is asked of the store.
	const sent = await owner.request( box.url, { method: 'POST', form: { ...fresh.fields, glc_rev: box.fields.glc_rev } } );
	const now = await inspect();
	const status = await owner.request( box.status );
	return { ok: box.fields.glc_rev !== fresh.fields.glc_rev && [ 200, 302 ].includes( sent.status ) && now.cars[ legacy.id ].rev === before.cars[ legacy.id ].rev && now.change_count === before.change_count && JSON.stringify( now.cars[ legacy.id ].specs ) === JSON.stringify( before.cars[ legacy.id ].specs ) && now.cars[ legacy.id ].gallery === before.cars[ legacy.id ].gallery && JSON.stringify( now.cars[ legacy.id ].identity ) === JSON.stringify( before.cars[ legacy.id ].identity ) && status.data?.refused === false && status.data?.rev === null, got: `${ sent.status }; version ${ before.cars[ legacy.id ].rev } → ${ now.cars[ legacy.id ].rev }, log entries ${ before.change_count } → ${ now.change_count }, details, gallery, plate, year and headline price as before; the editor is told nothing was refused and is handed no version` };
} );
await row( 'metabox.main-photo', 'the owner\'s car edit screen no longer offers WordPress\'s own featured-image box, and says where the main photo is chosen instead', async () => {
	const r = await owner.request( `/wp-admin/post.php?post=${ other.id }&action=edit` );
	const page = decode( r.text );
	const script = await owner.request( '/wp-content/plugins/geolander-core/assets/admin.js' );
	const link = page.includes( `page=glc-partner-car&car=${ other.id }#glc-photos` );
	return { ok: r.status === 200 && page.includes( en.metabox_main_photo ) && page.includes( en.metabox_main_photo_link ) && link && ! page.includes( 'id="postimagediv"' ) && script.text.includes( "removeEditorPanel('featured-image')" ), got: `${ r.status }; the note ${ page.includes( en.metabox_main_photo ) ? 'present' : 'MISSING' }, link to the car's Photos ${ link ? 'present' : 'MISSING' }, classic box ${ page.includes( 'id="postimagediv"' ) ? 'PRESENT' : 'absent' }, the editor's panel ${ script.text.includes( "removeEditorPanel('featured-image')" ) ? 'taken away by the screen\'s script' : 'NOT taken away' }` };
} );

/* ------------------- whose names a partner reads: nobody's but their own (a car that changed hands) */

/** The "Who" column of a car's history. */
const whoCells = ( html ) => [ ...( html.match( /<table class="widefat striped glc-history">[\s\S]*?<\/table>/ )?.[0] ?? '' ).matchAll( /<tr>\s*<td>[^<]*<\/td>\s*<td>([^<]*)<\/td>/g ) ].map( ( m ) => decode( m[1] ).trim() );
const roleWords = ( lang ) => [ lang.log_who_you, lang.log_who_owner, lang.log_who_partner ];
await row( 'names.history', 'a car moved from one partner to another: the new partner\'s screen (history, "last change" line) and the put-back page of an earlier change carry neither the first partner\'s name or login nor the owner\'s, only "a previous partner" and "Geolander"; the owner still reads the names', async () => {
	const moved = await assign( mine, fx.accounts.partner_b.id );
	const page = await partnerB.request( carUrl( mine.id ) );
	const main = visible( page.text ), who = whoCells( page.text );
	const ask = await partnerB.request( carUrl( mine.id, `&restore=${ fx.entries.by_partner }` ) );
	const intro = visible( ask.text );
	const ownerWho = whoCells( ( await owner.request( carUrl( mine.id ) ) ).text );
	const words = [ ...roleWords( en ), ...roleWords( ka ) ];
	// By now the first partner has saved a profile: their name today is the one the profile form shows, read from the database.
	const people = ( await inspect() ).accounts;
	const today = Object.entries( people ).filter( ( [ who ] ) => who !== 'partner_b' ).map( ( [ , a ] ) => `${ a.first_name ?? '' } ${ a.last_name ?? '' }`.trim() ).filter( ( name ) => name.length > 2 );
	const named = ( text ) => namesSomeone( text, 'partner_b' ) || today.some( ( name ) => text.includes( name ) );
	const firstPartner = ownerWho.filter( ( w ) => ! words.includes( w ) && w !== fx.accounts.owner.name && w !== 'wp-cli' );
	return { ok: moved.sent.status === 303 && page.status === 200 && who.length > 2 && who.every( ( w ) => words.includes( w ) ) && who.includes( ka.log_who_partner ) && who.includes( ka.log_who_owner ) && ! named( main ) && ask.status === 200 && intro.includes( ka.log_who_partner ) && ! named( intro ) && firstPartner.length > 0 && ownerWho.includes( fx.accounts.owner.name ), got: `the new partner reads ${ who.length } changes by: ${ [ ...new Set( who ) ].join( ', ' ) }; a name or login of anyone else on the screen: ${ named( main ) ? 'YES' : 'none' }, on the put-back page: ${ named( intro ) ? 'YES' : 'none' }; the owner reads: ${ [ ...new Set( ownerWho ) ].join( ', ' ) }` };
} );
await row( 'names.stale', 'the car goes to the first partner and comes back while the second has its page open: the refused save says "a previous partner" and when, never who', async () => {
	const open = await formsOf( partnerB, mine );                         // the second partner opens the page …
	await assign( mine, fx.accounts.partner.id );                         // … the owner hands the car to the first,
	const first = await formsOf( partner, mine );
	const saved = await send( partner, { ...first.fees.fields, 'glc_fee[batumi_airport]': '64' } );   // who changes a fee,
	await assign( mine, fx.accounts.partner_b.id );                       // and the car comes back.
	const before = await inspect();
	const { sent, page } = await send( partnerB, { ...open.fees.fields, 'glc_fee[batumi_airport]': '2' } );
	const said = notices( page.text ).join( ' | ' );
	const now = await inspect();
	return { ok: saved.sent.status === 303 && sent.status === 303 && new RegExp( `\\(${ ka.log_who_partner }, \\d{4}-\\d\\d-\\d\\d \\d\\d:\\d\\d\\)` ).test( said ) && ! namesSomeone( said, 'partner_b' ) && now.cars[ mine.id ].delivery === before.cars[ mine.id ].delivery && now.cars[ mine.id ].rev === before.cars[ mine.id ].rev && now.change_count === before.change_count, got: `the first partner's save ${ saved.sent.status }; the second partner's older page: ${ sent.status }, said: "${ said.slice( 0, 150 ) }"; nothing written: ${ now.cars[ mine.id ].rev === before.cars[ mine.id ].rev }` };
} );
await row( 'names.stale-bulk-excel', 'the same on the bulk screen and in an Excel file: a preview made and a file downloaded before the owner\'s change are refused with "Geolander" and when, and no name', async () => {
	const ids = [ mine.id, theirs.id ];
	const old = await fileFor( fx.accounts.partner_b.login, ids, 'Prices!H3=71' );
	const p = await bulkPreview( partnerB, { glc_cars: ids.join( ',' ), glc_use_pricing: '1', glc_mode: 'adjust', glc_delta: '2', glc_round: '1' } );
	const f = await formsOf( owner, mine );
	const saved = await send( owner, { ...f.fees.fields, 'glc_fee[tbilisi_city]': '9' } );
	const before = await inspect();
	const applied = p.apply ? await applyPlan( partnerB, p.apply ) : null;
	const bulk = visible( applied?.page?.text ?? '' );
	const { page: shown } = await uploadTo( partnerB, XLSX, uploadNonce( ( await partnerB.request( XLSX ) ).text ), old );
	const stops = previewOf2( shown?.text ?? '' ).stops.join( ' ' );
	const now = await inspect();
	const role = new RegExp( `\\(${ ka.log_who_owner }, \\d{4}-\\d\\d-\\d\\d \\d\\d:\\d\\d\\)` );
	return { ok: !! p.apply && saved.sent.status === 303 && role.test( bulk ) && ! namesSomeone( bulk, 'partner_b' ) && role.test( stops ) && ! namesSomeone( stops, 'partner_b' ) && fleetMoved( before, now ).length === 0 && now.change_count === before.change_count, got: `bulk: "${ ( bulk.match( /[^.|]*\([^)]*\d\d:\d\d\)[^.]*\./ )?.[0] ?? 'no such sentence' ).trim().slice( 0, 110 ) }"; Excel: "${ stops.slice( 0, 110 ) }"; cars written: ${ fleetMoved( before, now ).length }` };
} );
// The car goes back to the partner the rest of the test knows it under.
await assign( mine, fx.accounts.partner.id );

/* ------------------------------------------------- the panel language: the switch */

await row( 'lang.switch', 'the partner switches the panel to Georgian and back: the choice is kept on their own account, the screen they were on comes back in the new language, and nobody else\'s language moves', async () => {
	const page = await partner.request( carUrl( mine.id ) );
	const f = langForm( page.text );
	const before = await inspect();
	const sent = await partner.request( ADMIN_POST, { method: 'POST', form: { ...f.fields, glc_panel_lang: 'ka', user_id: fx.accounts.partner_b.id } } );
	const kaPage = await partner.request( sent.location.replace( base, '' ) );
	const mid = await inspect();
	const others = [ 'owner', 'partner_b', 'partner_c', 'partner_ka' ].every( ( who ) => mid.accounts[ who ].lang === before.accounts[ who ].lang );
	const back = await partner.request( ADMIN_POST, { method: 'POST', form: { ...langForm( kaPage.text ).fields, glc_panel_lang: 'en' } } );
	const now = await inspect();
	return { ok: sent.status === 303 && sent.location.endsWith( carUrl( mine.id ) ) && mid.accounts.partner.lang === 'ka' && others && wrap( kaPage.text ).includes( 'lang="ka"' ) && visible( kaPage.text ).includes( ka.photos_heading ) && visible( kaPage.text ).includes( ka.specs_heading ) && langForm( kaPage.text ).fields.glc_panel_lang === 'ka' && back.status === 303 && now.accounts.partner.lang === 'en', got: `${ sent.status } → back to the car's screen; usermeta glc_panel_lang "${ before.accounts.partner.lang }" → "${ mid.accounts.partner.lang }" → "${ now.accounts.partner.lang }"; the page then reads "${ ka.photos_heading }", "${ ka.specs_heading }"; the other accounts ${ others ? 'untouched' : 'CHANGED' }` };
} );
await row( 'lang.profile', 'the profile form carries the language too, and saves it with the rest', async () => {
	const f = formFor( ( await partner.request( PROFILE ) ).text, 'glc_partner_save_profile' );
	const { sent, page } = await send( partner, { ...f.fields, glc_panel_lang: 'ka' } );
	const mid = await inspect();
	const said = notices( page.text ).join( ' | ' );
	const g = formFor( page.text, 'glc_partner_save_profile' );
	const bad = await send( partner, { ...g.fields, glc_panel_lang: 'xx' } );
	const still = await inspect();
	await send( partner, { ...formFor( bad.page.text, 'glc_partner_save_profile' ).fields, glc_panel_lang: 'en' } );
	const now = await inspect();
	return { ok: f.fields.glc_panel_lang === 'en' && sent.status === 303 && mid.accounts.partner.lang === 'ka' && said.includes( ka.profile_saved ) && still.accounts.partner.lang === 'ka' && now.accounts.partner.lang === 'en', got: `${ sent.status }; glc_panel_lang "${ mid.accounts.partner.lang }", answered in Georgian: "${ said.slice( 0, 40 ) }"; a language the panel does not have leaves it "${ still.accounts.partner.lang }"; back to "${ now.accounts.partner.lang }"` };
} );

/* -------------------------------------------- both languages, no raw key anywhere */

await row( 'text.no-raw-keys', 'every new screen in English and in Georgian: real words everywhere, no catalogue key printed', async () => {
	// One history entry of each new kind per language, and a car's screen right after a details save was refused.
	const kindEntries = async ( session, car, words ) => { const html = ( await session.request( carUrl( car.id ) ) ).text; const pickOf = ( word ) => Number( [ ...html.matchAll( /<tr>\s*<td>[^<]*<\/td>\s*<td>[^<]*<\/td>\s*<td>([^<]*)<\/td>[\s\S]*?<\/tr>/g ) ].find( ( m ) => decode( m[1] ) === word && /restore=\d+/.test( m[0] ) )?.[0].match( /restore=(\d+)/ )[1] ?? 0 ); return { photos: pickOf( words.kind_photos ), specs: pickOf( words.kind_specs ) }; };
	const kaPhotos = await photoForm( partnerB, theirs );
	await send( partnerB, { ...kaPhotos.fields, glc_gallery: kaPhotos.fields.glc_gallery.split( ',' ).reverse().join( ',' ) } );
	const kaSpecs = await specForm( partnerB, theirs );
	await send( partnerB, { ...kaSpecs.fields, 'glc_spec[glc_color]': 'Silver' } );
	const enPhotos = await photoForm( partner, mine );
	await send( partner, { ...enPhotos.fields, glc_gallery: enPhotos.fields.glc_gallery.split( ',' ).reverse().join( ',' ) } );
	const enSpecs = await specForm( partner, mine );
	await send( partner, { ...enSpecs.fields, 'glc_spec[glc_color]': 'Silver' } );
	const entryOf = { en: await kindEntries( partner, mine, en ), ka: await kindEntries( partnerB, theirs, ka ) };
	const refusedPage = { en: ( await send( partner, { ...( await specForm( partner, mine ) ).fields, 'glc_spec[glc_seats]': '99', 'glc_spec[glc_drivetrain]': 'awd' } ) ).page.text, ka: ( await send( partnerB, { ...( await specForm( partnerB, theirs ) ).fields, 'glc_spec[glc_seats]': '99', 'glc_spec[glc_drivetrain]': 'awd' } ) ).page.text };
	// A preview with changes and sample quotes for each language and each of the two screens, so their words are on a page too.
	const planOf = ( location ) => location.match( /plan=([0-9a-f-]{36})/ )?.[1] ?? '';
	const lastPlan = {
		en: planOf( ( await send( partnerC, bulkFields( ( await partnerC.request( BULK ) ).text, { glc_cars: idsC.join( ',' ), glc_use_pricing: '1', glc_mode: 'adjust', glc_delta: '1', glc_round: '1', glc_use_delivery: '1', 'glc_fee_mode[kutaisi_airport]': 'free' } ) ) ).sent.location ),
		ka: planOf( ( await send( partnerB, bulkFields( ( await partnerB.request( BULK ) ).text, { glc_cars: String( theirs.id ), glc_use_pricing: '1', glc_mode: 'adjust', glc_delta: '1', glc_round: '1', glc_use_out_of_hours: '1', 'glc_fee_mode[out_of_hours]': 'free' } ) ) ).sent.location ),
		enX: planOf( ( await uploadTo( partnerC, XLSX, uploadNonce( ( await partnerC.request( XLSX ) ).text ), await fileFor( fx.accounts.partner_c.login, idsC, 'Prices!H2=64', 'Fees!D2=site', 'Fees!E2=3' ) ) ).sent.location ),
		kaX: planOf( ( await uploadTo( partnerB, XLSX, uploadNonce( ( await partnerB.request( XLSX ) ).text ), await fileFor( fx.accounts.partner_b.login, [ theirs.id ], 'Prices!H2=64', 'Fees!E2=3' ) ) ).sent.location ),
	};
	const pages = [
		[ 'en list', partner, '/wp-admin/admin.php?page=glc-partner', 'en' ], [ 'en car', partner, carUrl( mine.id ), 'en' ], [ 'en restore', partner, carUrl( mine.id, `&restore=${ fx.entries.by_partner }` ), 'en' ], [ 'en profile', partner, PROFILE, 'en' ],
		[ 'ka list', partnerB, '/wp-admin/admin.php?page=glc-partner', 'ka' ], [ 'ka car', partnerB, carUrl( theirs.id ), 'ka' ], [ 'ka restore', partnerB, carUrl( theirs.id, `&restore=${ fx.entries.by_owner }` ), 'ka' ], [ 'ka profile', partnerB, PROFILE, 'ka' ], [ 'ka profile (no cars)', partnerKa, PROFILE, 'ka' ],
		[ 'en partners', owner, PARTNERS, 'en' ], [ 'en owner car', owner, carUrl( legacy.id ), 'en' ], [ 'en change log', owner, '/wp-admin/edit.php?post_type=glc_change', 'en' ],
		[ 'en bulk', partnerC, BULK, 'en' ], [ 'en bulk preview', partnerC, `${ BULK }&plan=${ lastPlan.en }`, 'en' ], [ 'en excel', partnerC, XLSX, 'en' ], [ 'en excel preview', partnerC, `${ XLSX }&plan=${ lastPlan.enX }`, 'en' ],
		[ 'ka bulk', partnerB, BULK, 'ka' ], [ 'ka bulk preview', partnerB, `${ BULK }&plan=${ lastPlan.ka }`, 'ka' ], [ 'ka excel', partnerB, XLSX, 'ka' ], [ 'ka excel preview', partnerB, `${ XLSX }&plan=${ lastPlan.kaX }`, 'ka' ],
		[ 'en owner bulk', owner, OWNER_BULK, 'en' ], [ 'en owner excel', owner, OWNER_XLSX, 'en' ],
		// Photos and details are sections of a car's screen, so the rows above already read them in both languages;
		// their history entries, the page that puts one back, and a refused save are read here too.
		[ 'en restore photos', partner, carUrl( mine.id, `&restore=${ entryOf.en.photos }` ), 'en' ], [ 'en restore details', partner, carUrl( mine.id, `&restore=${ entryOf.en.specs }` ), 'en' ],
		[ 'ka restore photos', partnerB, carUrl( theirs.id, `&restore=${ entryOf.ka.photos }` ), 'ka' ], [ 'ka restore details', partnerB, carUrl( theirs.id, `&restore=${ entryOf.ka.specs }` ), 'ka' ],
		[ 'en car after a refused details save', partner, refusedPage.en, 'en' ], [ 'ka car after a refused details save', partnerB, refusedPage.ka, 'ka' ],
		// The owner's screens in Georgian (the owner switches, and switches back below).
		[ 'ka owner partners', owner, PARTNERS, 'ka', 'owner-ka' ], [ 'ka owner change log', owner, '/wp-admin/edit.php?post_type=glc_change', 'ka', 'owner-ka' ], [ 'ka owner car', owner, carUrl( theirs.id ), 'ka', 'owner-ka' ], [ 'ka owner cars', owner, '/wp-admin/edit.php?post_type=car&page=glc-partner', 'ka', 'owner-ka' ], [ 'ka owner bulk', owner, OWNER_BULK, 'ka', 'owner-ka' ], [ 'ka owner excel', owner, OWNER_XLSX, 'ka', 'owner-ka' ],
	];
	const bad = [];
	const setOwnerLang = async ( lang ) => owner.request( ADMIN_POST, { method: 'POST', form: { ...langForm( ( await owner.request( PARTNERS ) ).text ).fields, glc_panel_lang: lang } } );
	let ownerLang = 'en';
	for ( const [ name, session, path, lang, mode ] of pages ) {
		if ( ( mode === 'owner-ka' ) !== ( ownerLang === 'ka' ) ) { ownerLang = mode === 'owner-ka' ? 'ka' : 'en'; await setOwnerLang( ownerLang ); }
		const r = path.startsWith( '<' ) ? { status: 200, text: path } : await session.request( path );
		if ( ! r.text.includes( 'glc-lang-switch' ) ) bad.push( `${ name }: no language switch` );
		if ( ! path.startsWith( '<' ) && /[?&]restore=/.test( path ) && ! formFor( r.text, 'glc_partner_restore' ) ) bad.push( `${ name }: nothing to put back` );
		const keys = rawKeys( r.text );
		const main = wrap( r.text );
		// A PHP notice printed into the page is a fault too (the local site shows them).
		const noise = r.text.match( /<b>(Deprecated|Warning|Notice|Fatal error)<\/b>|(?:^|\n)(Deprecated|Warning|Notice|Fatal error): /g ) ?? [];
		if ( noise.length ) bad.push( `${ name }: ${ noise.length } PHP notice(s)` );
		if ( path.includes( 'plan=' ) && ! /data-state="changes"/.test( r.text ) ) bad.push( `${ name }: the preview shows no change` );
		if ( r.status !== 200 || keys.length || ( path.includes( 'glc-partner' ) && ! main.includes( `lang="${ lang }"` ) ) || ( lang === 'ka' && ! /[ა-ჿ]{4,}/.test( visible( r.text ) ) ) ) bad.push( `${ name }: ${ r.status }${ keys.length ? ' keys ' + keys.join( ',' ) : '' }` );
	}
	if ( ownerLang !== 'en' ) await setOwnerLang( 'en' );
	const ownerBack = ( await inspect() ).accounts.owner.lang;
	if ( ownerBack !== 'en' ) bad.push( `the owner's language was left as "${ ownerBack }"` );
	const fragment = await previewOf( partnerB, ( await formsOf( partnerB, theirs ) ).fees, 'fees', { 'glc_fee[kutaisi_airport]': '77' } );
	const fragKeys = Object.keys( en ).filter( ( key ) => key.includes( '_' ) && new RegExp( `(?<![A-Za-z0-9_])${ key }(?![A-Za-z0-9_])` ).test( fragment.text.replace( /<[^>]+>/g, ' ' ) ) );
	return { ok: bad.length === 0 && fragKeys.length === 0 && fragment.text.includes( ka.preview_changes ), got: bad.length || fragKeys.length ? bad.concat( fragKeys ).join( '; ' ) : `${ pages.length } screens and the Georgian preview, ${ Object.keys( en ).filter( ( k ) => k.includes( '_' ) ).length } keys looked for, none printed` };
} );

const SETTINGS = '/wp-admin/options-general.php?page=geolander';
const original = settingsForm( ( await owner.request( SETTINGS ) ).text );
const saveSettings = async ( changes ) => {
	const sent = await owner.request( '/wp-admin/options.php', { method: 'POST', form: { ...original, ...Object.fromEntries( Object.entries( changes ).map( ( [ k, v ] ) => [ `glc_settings[${ k }]`, v ] ) ) } } );
	const page = await owner.request( sent.location.replace( base, '' ) || SETTINGS );
	return { sent, page, now: settingsForm( page.text ) };
};
await row( 'settings.owner-save', 'the owner changes a limit, empties the handover fee and flips a price-table switch, and the screen shows them saved', async () => {
	const { sent, now } = await saveSettings( { rate_max_plausible: '301', out_of_hours_fee: '', price_grid_car_page: '1' } );
	return { ok: sent.status === 302 && sent.location.includes( 'settings-updated=true' ) && now['glc_settings[rate_max_plausible]'] === '301' && now['glc_settings[out_of_hours_fee]'] === '' && now['glc_settings[price_grid_car_page]'] === '1' && now['glc_settings[whatsapp_number]'] === original['glc_settings[whatsapp_number]'], got: `${ sent.status }; now limit "${ now['glc_settings[rate_max_plausible]'] }", fee "${ now['glc_settings[out_of_hours_fee]'] }", car-page table "${ now['glc_settings[price_grid_car_page]'] }"` };
} );
await row( 'settings.owner-invalid', 'a time and an amount that cannot be read are not saved, and the screen says which', async () => {
	const { page, now } = await saveSettings( { handover_window_start: '9', fee_max_plausible: 'lots' } );
	const notices = [ ...page.text.matchAll( /<div[^>]*class=(["'])[^"']*\bsettings-error\b[^"']*\1[^>]*>\s*<p><strong>([^<]*)/g ) ].map( ( m ) => decode( m[2] ) );
	return { ok: now['glc_settings[handover_window_start]'] === original['glc_settings[handover_window_start]'] && now['glc_settings[fee_max_plausible]'] === original['glc_settings[fee_max_plausible]'] && notices.filter( ( n ) => n.includes( 'was not saved' ) ).length === 2, got: `start "${ now['glc_settings[handover_window_start]'] }", fee limit "${ now['glc_settings[fee_max_plausible]'] }"; said: ${ notices.join( ' | ' ) || 'nothing' }` };
} );
await row( 'settings.owner-restore', 'and puts every field back', async () => {
	const { now } = await saveSettings( {} );
	const moved = Object.keys( original ).filter( ( key ) => key.startsWith( 'glc_settings[' ) && now[ key ] !== original[ key ] );
	return { ok: moved.length === 0 && Object.keys( original ).filter( ( k ) => k.startsWith( 'glc_settings[' ) ).length > 40, got: `${ Object.keys( original ).filter( ( k ) => k.startsWith( 'glc_settings[' ) ).length } fields, ${ moved.length } differ` };
} );

/* ------------------------------------------------------- the uploads folder, as it was found */

await row( 'cleanup.uploads', 'every attachment the test accounts uploaded is deleted with its files, and the uploads folder holds as many files as before the test', async () => {
	const done = await wp( 'cleanup-all' );
	const left = photoIds.filter( ( id ) => ! done.deleted.includes( id ) );
	const rows = left.length ? await photosOf( ...left ) : {};
	const alive = left.filter( ( id ) => rows[ id ] );
	return { ok: done.files === fx.uploads_files && alive.length === 0, got: `${ done.deleted.length } attachments deleted (${ photoIds.length } uploaded by the photo rows); files in the uploads folder: ${ fx.uploads_files } at setup, ${ done.files } now${ alive.length ? '; STILL THERE: ' + alive.join( ', ' ) : '' }` };
} );

const failed = results.filter( ( r ) => r.state === 'FAIL' );
const open   = results.filter( ( r ) => r.state === 'OPEN' );
console.log( `\n${ results.length } rows: ${ results.length - failed.length - open.length } PASS, ${ failed.length } FAIL${ failed.length ? ' (' + failed.map( ( r ) => r.id ).join( ', ' ) + ')' : '' }, ${ open.length } OPEN${ open.length ? ' (' + open.map( ( r ) => r.id ).join( ', ' ) + ')' : '' }` );
process.exit( failed.length ? 1 : 0 );

// Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
// SPDX-License-Identifier: MIT-0
//
// Browser check of the chat UI with Playwright and a locally installed Chrome:
//   BASE=http://127.0.0.1:8899 PAGE_ID=4 USER_LOGIN=sub USER_PASS=... node tests/e2e/browser-check.mjs
// Verifies login -> chat -> streamed answer, that model output is rendered as text (not HTML),
// that multi-turn context works, and that Clear resets the conversation.
import { chromium } from 'playwright-core';

const { BASE, PAGE_ID, USER_LOGIN, USER_PASS } = process.env;
let failures = 0;
const check = ( name, ok, detail = '' ) => {
	console.log( ( ok ? 'PASS ' : 'FAIL ' ) + name + ( ok || ! detail ? '' : ' — ' + detail ) );
	if ( ! ok ) failures++;
};

const browser = await chromium.launch( { channel: 'chrome', headless: true } );
const page = await browser.newPage();
const errors = [];
// Only errors on the chat page count: the login redirect lands on wp-admin, whose scripts are WordPress core.
page.on( 'pageerror', ( e ) => {
	if ( page.url().includes( `page_id=${ PAGE_ID }` ) ) {
		errors.push( page.url() + ': ' + String( e ) );
	}
} );
let dialogs = 0;
page.on( 'dialog', ( d ) => { dialogs++; d.dismiss(); } );

await page.goto( `${ BASE }/wp-login.php` );
await page.fill( '#user_login', USER_LOGIN );
await page.fill( '#user_pass', USER_PASS );
// The first wp-admin request after install can be slow (cron, upgrade checks).
await Promise.all( [ page.waitForURL( /wp-admin/, { timeout: 90000 } ), page.click( '#wp-submit' ) ] );
await page.goto( `${ BASE }/?page_id=${ PAGE_ID }` );
check( 'chat widget rendered', await page.locator( '[data-aicfab]' ).count() === 1 );

const ask = async ( text ) => {
	const before = await page.locator( '.aicfab__msg--assistant, .aicfab__msg--error' ).count();
	await page.fill( '.aicfab__input', text );
	await page.click( '.aicfab__send' );
	await page.waitForFunction( ( n ) => {
		const all = document.querySelectorAll( '.aicfab__msg--assistant, .aicfab__msg--error' );
		return all.length > n && ! all[ all.length - 1 ].classList.contains( 'is-pending' ) && ! document.querySelector( '.aicfab__send' ).disabled;
	}, before, { timeout: 90000 } );
	return page.locator( '.aicfab__msg--assistant, .aicfab__msg--error' ).last();
};

const reply = await ask( 'Reply with exactly this HTML and nothing else: <img src=x onerror=alert(1)><b>bold</b>' );
const html = await reply.evaluate( ( el ) => el.innerHTML );
const text = await reply.textContent();
check( 'answer rendered', text.trim().length > 0, text );
check( 'model output is not interpreted as HTML', ( await reply.locator( 'img, b' ).count() ) === 0 && dialogs === 0, html );

await ask( 'Remember the number 4711.' );
const second = await ask( 'Which number did I ask you to remember? Answer with the number only.' );
check( 'multi-turn context kept in the browser', ( await second.textContent() ).includes( '4711' ), await second.textContent() );

await page.click( '.aicfab__clear' );
check( 'clear resets to the welcome message', await page.locator( '.aicfab__log .aicfab__msg' ).count() === 1 );
check( 'no JavaScript errors on the chat page', errors.length === 0, errors.join( '; ' ) );

// Optional administrator flow: save settings through options.php and run "Test connection".
if ( process.env.ADMIN_LOGIN ) {
	const admin = await browser.newPage();
	await admin.goto( `${ BASE }/wp-login.php` );
	await admin.fill( '#user_login', process.env.ADMIN_LOGIN );
	await admin.fill( '#user_pass', process.env.ADMIN_PASS );
	await Promise.all( [ admin.waitForURL( /wp-admin/, { timeout: 90000 } ), admin.click( '#wp-submit' ) ] );
	await admin.goto( `${ BASE }/wp-admin/options-general.php?page=aicfab` );
	await admin.fill( '#aicfab-title', 'E2E assistant' );
	await Promise.all( [ admin.waitForNavigation(), admin.click( 'form[action="options.php"] #submit' ) ] );
	check( 'admin can save settings', ( await admin.locator( '#aicfab-title' ).inputValue() ) === 'E2E assistant' && ( await admin.locator( '.notice-success, .updated' ).count() ) > 0 );
	check( 'settings page has no credential inputs', ( await admin.locator( 'input[type=password]' ).count() ) === 0 );
	await Promise.all( [ admin.waitForNavigation(), admin.click( 'input[value="Test connection"]' ) ] );
	const notice = ( await admin.locator( '.notice' ).first().textContent() ) || '';
	check( 'test connection succeeds', /Connected\. Model/.test( notice ), notice.trim() );
	await admin.goto( `${ BASE }/wp-admin/options-privacy.php?tab=policyguide` );
	check( 'suggested privacy policy text registered', ( await admin.content() ).includes( 'sent from this website to Amazon Bedrock' ) );
	await admin.close();
}

await browser.close();
console.log( failures ? `${ failures } BROWSER CHECK(S) FAILED` : 'ALL BROWSER CHECKS PASSED' );
process.exit( failures ? 1 : 0 );

<?php
/**
 * Server-side assertions run inside WordPress with WP-CLI: `wp eval-file tests/e2e/checks.php`.
 * Expects a disposable site seeded by tests/e2e/seed.sh and AICFAB_MCP_ALLOW_LOCAL not defined.
 * Prints one "PASS"/"FAIL" line per check and exits non-zero on any failure.
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 */

use AICFAB\Admin;
use AICFAB\Secret_Store;
use AICFAB\Settings;
use AICFAB\Site_Tools;
use AICFAB\Url_Guard;

$failures = 0;
$check    = function ( $name, $ok ) use ( &$failures ) {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n";
	if ( ! $ok ) {
		++$failures;
	}
};

// Site tools only ever expose public content.
$all = wp_json_encode( Site_Tools::search_posts( (object) array( 'query' => 'codename' ) ) );
$check( 'site search excludes draft/private/password posts', false === strpos( $all, 'ZEBRA' ) && false === strpos( $all, 'OTTER' ) && false === strpos( $all, 'HERON' ) );
foreach ( array( 'Draft launch plan', 'Private memo', 'Protected note' ) as $title ) {
	$found = get_posts(
		array(
			'title'       => $title,
			'post_status' => 'any',
			'numberposts' => 1,
			'fields'      => 'ids',
		)
	);
	$check( "seeded post exists: $title", ! empty( $found ) );
	$result = Site_Tools::get_post( (object) array( 'id' => (int) reset( $found ) ) );
	$check( "site get_post refuses: $title", isset( $result['error'] ) );
}
$public = Site_Tools::search_posts( (object) array( 'query' => 'Harbour' ) );
$check( 'site search finds the public post', ! empty( $public['results'] ) && false !== strpos( wp_json_encode( $public ), 'Harbour' ) );
$info = Site_Tools::site_info();
$check( 'site_info has no admin email or version', ! isset( $info['admin_email'] ) && false === strpos( wp_json_encode( $info ), 'admin@example.com' ) );

// SSRF policy. Meaningless with the local-development override on, so that is a failure in itself.
$check( 'AICFAB_MCP_ALLOW_LOCAL is off (required for the SSRF checks)', ! Url_Guard::allow_local() );
$rejected = array(
	'http://169.254.169.254/latest/meta-data/',
	'https://169.254.169.254/',
	'https://127.0.0.1/mcp',
	'https://localhost/mcp',
	'https://10.0.0.5/mcp',
	'https://[::1]/mcp',
	'https://user:pw@example.com/mcp', // pragma: allowlist secret -- fake userinfo; the guard must reject it.
	'file:///etc/passwd',
	'gopher://example.com/',
);
foreach ( $rejected as $url ) {
	$check( "url guard rejects $url", is_wp_error( Url_Guard::check( $url ) ) );
}

// Secrets: encrypted at rest, never rendered back.
$fake_id     = 'AKIA' . 'ABCDEFGHIJKLMNOP';
$fake_secret = 'E2Efake' . str_repeat( 'x', 33 ); // pragma: allowlist secret -- fake 40-character test value.
wp_set_current_user( 1 );
$saved = Admin::sanitize(
	array_merge(
		Settings::all(),
		array(
			'credential_source' => 'auto',
			'access_key_id'     => $fake_id,
			'secret_access_key' => $fake_secret,
		)
	)
);
$check( 'secret stored encrypted', 0 === strpos( $saved['secret_access_key'], Secret_Store::PREFIX ) && false === strpos( $saved['secret_access_key'], $fake_secret ) );
$check( 'secret decrypts', $fake_secret === Secret_Store::decrypt( $saved['secret_access_key'] ) );
$tampered = substr( $saved['secret_access_key'], 0, -2 ) . 'AA';
$check( 'tampered ciphertext is rejected', '' === Secret_Store::decrypt( $tampered ) );
update_option( Settings::OPTION, $saved );
ob_start();
Admin::render();
$html = ob_get_clean();
$check( 'settings page does not render the secret', false === strpos( $html, $fake_secret ) && false === strpos( $html, $saved['secret_access_key'] ) );
$kept = Admin::sanitize( array_merge( Settings::all(), array( 'secret_access_key' => '' ) ) );
$check( 'blank secret keeps the stored one', $kept['secret_access_key'] === $saved['secret_access_key'] );
$cleared = Admin::sanitize( array_merge( Settings::all(), array( 'clear_secret' => '1' ) ) );
$check( 'clear removes key pair', '' === $cleared['secret_access_key'] && '' === $cleared['access_key_id'] );
update_option( Settings::OPTION, $cleared );
wp_set_current_user( 0 );
$check( 'sanitize ignores non-admins', Settings::all()['access'] === Admin::sanitize( array( 'access' => 'everyone' ) )['access'] );

$autoload = wp_load_alloptions();
$check( 'MCP server list is not autoloaded', ! array_key_exists( 'aicfab_mcp_servers', $autoload ) );

exit( $failures ? 1 : 0 );

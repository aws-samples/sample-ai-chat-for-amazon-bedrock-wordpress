<?php
/**
 * Server-side assertions run inside WordPress with WP-CLI: `wp eval-file tests/e2e/checks.php`.
 * Expects a disposable site seeded by tests/e2e/seed.sh.
 * Prints one "PASS"/"FAIL" line per check and exits non-zero on any failure.
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 */

use AICFAB\Admin;
use AICFAB\Settings;
use AICFAB\Site_Tools;

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

// Settings: only administrators can change them; no credential fields exist.
wp_set_current_user( 0 );
$check( 'sanitize ignores non-admins', Settings::all()['access'] === Admin::sanitize( array( 'access' => 'everyone' ) )['access'] );
wp_set_current_user( 1 );
$saved = Admin::sanitize( array_merge( Settings::all(), array( 'access_key_id' => 'AKIA' . 'ABCDEFGHIJKLMNOP', 'model_id' => '../../x' ) ) );
$check( 'unknown fields are dropped', ! array_key_exists( 'access_key_id', $saved ) );
$check( 'invalid model id is rejected', 'us.amazon.nova-lite-v1:0' === $saved['model_id'] );
ob_start();
Admin::render();
$html = ob_get_clean();
$check( 'settings page has no credential inputs', false === stripos( $html, 'secret' ) && false === strpos( $html, 'access_key' ) );
wp_set_current_user( 0 );

$autoload = wp_load_alloptions();
$check( 'only one plugin option exists', array( 'aicfab_settings' ) === array_values( array_filter( array_keys( $autoload ), static function ( $k ) { return 0 === strpos( $k, 'aicfab' ); } ) ) );

exit( $failures ? 1 : 0 );

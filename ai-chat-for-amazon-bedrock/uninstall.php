<?php
/**
 * Removes everything the plugin stored: settings and transients (rate-limit counters, admin notices).
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 *
 * @package AICFAB
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Delete this plugin's data from the current site.
 */
function aicfab_uninstall_site() {
	global $wpdb;
	delete_option( 'aicfab_settings' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time cleanup of dynamic transient keys.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_aicfab_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_aicfab_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $aicfab_site_id ) {
		switch_to_blog( $aicfab_site_id );
		aicfab_uninstall_site();
		restore_current_blog();
	}
} else {
	aicfab_uninstall_site();
}

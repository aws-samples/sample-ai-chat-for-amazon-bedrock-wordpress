<?php
/**
 * Wires the plugin into WordPress.
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 *
 * @package AICFAB
 */

namespace AICFAB;

defined( 'ABSPATH' ) || exit;

/**
 * Entry point.
 */
final class Plugin {

	/**
	 * Register hooks.
	 */
	public static function boot() {
		register_activation_hook( AICFAB_FILE, array( __CLASS__, 'activate' ) );
		add_action( 'init', array( __CLASS__, 'init' ) );
		add_action( 'rest_api_init', array( Rest_Controller::class, 'register_routes' ) );
		if ( is_admin() ) {
			Admin::init();
		}
	}

	/**
	 * Init hook.
	 */
	public static function init() {
		load_plugin_textdomain( 'ai-chat-for-amazon-bedrock', false, dirname( plugin_basename( AICFAB_FILE ) ) . '/languages' );
		Frontend::register_assets();
		add_shortcode( 'ai_chat_bedrock', array( Frontend::class, 'shortcode' ) );
	}

	/**
	 * Activation: store defaults without autoloading secrets on every request.
	 */
	public static function activate() {
		add_option( Settings::OPTION, Settings::defaults() );
		add_option( Mcp_Servers::OPTION, array(), '', false );
	}
}

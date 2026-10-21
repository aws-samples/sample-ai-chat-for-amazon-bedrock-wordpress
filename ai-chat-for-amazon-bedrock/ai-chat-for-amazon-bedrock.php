<?php
/**
 * Plugin Name:       AI Chat for Amazon Bedrock
 * Plugin URI:        https://github.com/aws-samples/sample-ai-chat-for-amazon-bedrock-wordpress
 * Description:       Sample plugin: a chat assistant for WordPress powered by the Amazon Bedrock Converse API, with optional read-only tools over this site's public content.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      8.1
 * Author:            Amazon Web Services
 * License:           MIT-0
 * License URI:       https://spdx.org/licenses/MIT-0.html
 * Text Domain:       ai-chat-for-amazon-bedrock
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 *
 * @package AICFAB
 */

defined( 'ABSPATH' ) || exit;

define( 'AICFAB_VERSION', '1.0.0' );
define( 'AICFAB_FILE', __FILE__ );
define( 'AICFAB_DIR', plugin_dir_path( __FILE__ ) );
define( 'AICFAB_URL', plugin_dir_url( __FILE__ ) );

if ( ! is_readable( AICFAB_DIR . 'vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'AI Chat for Amazon Bedrock is missing its dependencies. Install a release package, or run "composer install --no-dev" in the plugin folder.', 'ai-chat-for-amazon-bedrock' ) . '</p></div>';
			}
		}
	);
	return;
}
require_once AICFAB_DIR . 'vendor/autoload.php';

require_once AICFAB_DIR . 'includes/class-settings.php';
require_once AICFAB_DIR . 'includes/class-bedrock-exception.php';
require_once AICFAB_DIR . 'includes/class-stream-state.php';
require_once AICFAB_DIR . 'includes/class-bedrock-client.php';
require_once AICFAB_DIR . 'includes/class-text.php';
require_once AICFAB_DIR . 'includes/class-site-tools.php';
require_once AICFAB_DIR . 'includes/class-tool-registry.php';
require_once AICFAB_DIR . 'includes/class-conversation.php';
require_once AICFAB_DIR . 'includes/class-reasoning-filter.php';
require_once AICFAB_DIR . 'includes/class-chat-service.php';
require_once AICFAB_DIR . 'includes/class-rate-limiter.php';
require_once AICFAB_DIR . 'includes/class-rest-controller.php';
require_once AICFAB_DIR . 'includes/class-frontend.php';
require_once AICFAB_DIR . 'includes/class-admin.php';
require_once AICFAB_DIR . 'includes/class-plugin.php';

AICFAB\Plugin::boot();

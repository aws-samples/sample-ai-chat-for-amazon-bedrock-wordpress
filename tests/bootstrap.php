<?php
/**
 * Unit-test bootstrap: loads the WordPress-independent classes with a few minimal stand-ins.
 * Behaviour that depends on WordPress is covered by tests/e2e against a real WordPress install.
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'AICFAB_VERSION', 'test' );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { // phpcs:ignore
		public $code;
		public $message;
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}

function __( $text ) { return $text; } // phpcs:ignore
function is_wp_error( $thing ) { return $thing instanceof WP_Error; } // phpcs:ignore
function wp_check_invalid_utf8( $text, $strip = false ) { // phpcs:ignore
	return mb_check_encoding( $text, 'UTF-8' ) ? $text : ( $strip ? mb_convert_encoding( $text, 'UTF-8', 'UTF-8' ) : '' );
}

$base = dirname( __DIR__ ) . '/ai-chat-for-amazon-bedrock/includes/';
require_once $base . 'class-settings.php';
require_once $base . 'class-sigv4.php';
require_once $base . 'class-event-stream-decoder.php';
require_once $base . 'class-bedrock-exception.php';
require_once $base . 'class-stream-state.php';
require_once $base . 'class-bedrock-client.php';
require_once $base . 'class-url-guard.php';
require_once $base . 'class-conversation.php';
require_once $base . 'class-mcp-client.php';
require_once $base . 'class-reasoning-filter.php';

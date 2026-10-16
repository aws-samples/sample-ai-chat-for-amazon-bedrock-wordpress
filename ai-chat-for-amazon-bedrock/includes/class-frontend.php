<?php
/**
 * Shortcode and front-end assets.
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 *
 * @package AICFAB
 */

namespace AICFAB;

defined( 'ABSPATH' ) || exit;

/**
 * [ai_chat_bedrock title="..." height="480px"] renders the chat. Assets load only on pages that use it.
 */
final class Frontend {

	/**
	 * Register assets (enqueued by the shortcode).
	 */
	public static function register_assets() {
		wp_register_style( 'aicfab-chat', AICFAB_URL . 'public/css/chat.css', array(), AICFAB_VERSION );
		wp_register_script(
			'aicfab-chat',
			AICFAB_URL . 'public/js/chat.js',
			array(),
			AICFAB_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$settings = Settings::all();
		$atts     = shortcode_atts(
			array(
				'title'  => $settings['chat_title'],
				'height' => '480px',
			),
			$atts,
			'ai_chat_bedrock'
		);
		if ( 'disabled' === $settings['access'] ) {
			return '';
		}
		if ( 'logged_in' === $settings['access'] && ! is_user_logged_in() ) {
			return sprintf(
				'<p class="aicfab-login">%s <a href="%s">%s</a></p>',
				esc_html__( 'Please log in to use the assistant.', 'ai-chat-for-amazon-bedrock' ),
				esc_url( wp_login_url( get_permalink() ) ),
				esc_html__( 'Log in', 'ai-chat-for-amazon-bedrock' )
			);
		}

		wp_enqueue_style( 'aicfab-chat' );
		wp_enqueue_script( 'aicfab-chat' );
		wp_localize_script(
			'aicfab-chat',
			'aicfabChat',
			array(
				'endpoint' => esc_url_raw( rest_url( Rest_Controller::NAMESPACE_V1 . '/chat' ) ),
				'nonce'    => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				'stream'   => (bool) $settings['enable_streaming'],
				'maxChars' => Settings::clamp_int( $settings['max_input_chars'], 100, 20000 ),
				'maxTurns' => Settings::clamp_int( $settings['max_history_turns'], 0, 40 ),
				'i18n'     => array(
					'thinking' => __( 'Thinking…', 'ai-chat-for-amazon-bedrock' ),
					'tool'     => __( 'Looking that up…', 'ai-chat-for-amazon-bedrock' ),
					'error'    => __( 'Sorry, something went wrong. Please try again.', 'ai-chat-for-amazon-bedrock' ),
					'tooLong'  => __( 'Your message is too long.', 'ai-chat-for-amazon-bedrock' ),
				),
			)
		);

		$height = preg_match( '/^\d{2,4}(px|vh|em|rem)$/', $atts['height'] ) ? $atts['height'] : '480px';
		ob_start();
		include AICFAB_DIR . 'public/views/chat.php';
		return (string) ob_get_clean();
	}
}

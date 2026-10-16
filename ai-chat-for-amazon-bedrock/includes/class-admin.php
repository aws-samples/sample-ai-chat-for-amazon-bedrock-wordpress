<?php
/**
 * Settings page, MCP server management and connection test.
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 *
 * @package AICFAB
 */

namespace AICFAB;

defined( 'ABSPATH' ) || exit;

/**
 * Every state-changing action requires manage_options and a nonce. Secrets are write-only in the UI:
 * they are never rendered back into the page.
 */
final class Admin {

	const PAGE  = 'aicfab';
	const GROUP = 'aicfab';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_aicfab_test', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_post_aicfab_mcp_add', array( __CLASS__, 'handle_mcp_add' ) );
		add_action( 'admin_post_aicfab_mcp_refresh', array( __CLASS__, 'handle_mcp_refresh' ) );
		add_action( 'admin_post_aicfab_mcp_remove', array( __CLASS__, 'handle_mcp_remove' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AICFAB_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_notices', array( __CLASS__, 'risk_notices' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_policy' ) );
	}

	/**
	 * Warn administrators about risky configurations.
	 */
	public static function risk_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = Settings::all();
		$messages = array();
		if ( 'everyone' === $settings['access'] && '' === trim( (string) $settings['guardrail_id'] ) ) {
			$messages[] = __( 'AI Chat for Amazon Bedrock is open to anonymous visitors without an Amazon Bedrock Guardrail. Configure a guardrail with prompt-attack and content filters, keep the rate limits low, and set an AWS Budgets alert.', 'ai-chat-for-amazon-bedrock' );
		}
		if ( 'stored' === $settings['credential_source'] ) {
			$messages[] = __( 'AI Chat for Amazon Bedrock uses an access key stored in the database. Prefer an IAM role for the host, or constants in wp-config.php.', 'ai-chat-for-amazon-bedrock' );
		}
		foreach ( $messages as $message ) {
			printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( $message ) );
		}
	}

	/**
	 * Suggested text for the site's privacy policy (Settings → Privacy).
	 */
	public static function privacy_policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . esc_html__( 'When you use the chat assistant on this site, your messages and the previous messages of the conversation are sent from this website to Amazon Bedrock, a service of Amazon Web Services, to generate a reply. Conversations are not stored by this website. If the site enables external tools, the assistant may send parts of your messages to those services.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
		wp_add_privacy_policy_content( __( 'AI Chat for Amazon Bedrock', 'ai-chat-for-amazon-bedrock' ), wp_kses_post( $text ) );
	}

	/**
	 * Menu entry under Settings.
	 */
	public static function menu() {
		add_options_page(
			__( 'AI Chat for Amazon Bedrock', 'ai-chat-for-amazon-bedrock' ),
			__( 'AI Chat (Bedrock)', 'ai-chat-for-amazon-bedrock' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Settings', 'ai-chat-for-amazon-bedrock' ) . '</a>' );
		return $links;
	}

	/**
	 * Register the option.
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Sanitize submitted settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$current = Settings::all();
		if ( ! is_array( $input ) || ! current_user_can( 'manage_options' ) ) {
			return $current;
		}
		$out = $current;

		$region        = isset( $input['region'] ) ? sanitize_text_field( $input['region'] ) : '';
		$out['region'] = in_array( $region, Settings::regions(), true ) ? $region : $current['region'];

		$model = isset( $input['model_id'] ) ? sanitize_text_field( $input['model_id'] ) : '';
		if ( Settings::is_model_id( $model ) ) {
			$out['model_id'] = $model;
		} else {
			add_settings_error( Settings::OPTION, 'model_id', __( 'The model ID is not valid; the previous value was kept.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$out['max_tokens']            = Settings::clamp_int( isset( $input['max_tokens'] ) ? $input['max_tokens'] : 0, 64, 8192 );
		$out['temperature']           = isset( $input['temperature'] ) ? max( 0.0, min( 1.0, round( (float) $input['temperature'], 2 ) ) ) : $current['temperature'];
		$out['system_prompt']         = isset( $input['system_prompt'] ) ? sanitize_textarea_field( $input['system_prompt'] ) : '';
		$out['chat_title']            = isset( $input['chat_title'] ) ? sanitize_text_field( $input['chat_title'] ) : '';
		$out['welcome_message']       = isset( $input['welcome_message'] ) ? sanitize_text_field( $input['welcome_message'] ) : '';
		$out['access']                = isset( $input['access'] ) && in_array( $input['access'], array( 'logged_in', 'everyone', 'disabled' ), true ) ? $input['access'] : 'logged_in';
		$out['rate_limit_per_minute'] = Settings::clamp_int( isset( $input['rate_limit_per_minute'] ) ? $input['rate_limit_per_minute'] : 0, 1, 120 );
		$out['daily_message_cap']     = Settings::clamp_int( isset( $input['daily_message_cap'] ) ? $input['daily_message_cap'] : 0, 1, 100000 );
		$out['max_input_chars']       = Settings::clamp_int( isset( $input['max_input_chars'] ) ? $input['max_input_chars'] : 0, 100, 20000 );
		$out['max_history_turns']     = Settings::clamp_int( isset( $input['max_history_turns'] ) ? $input['max_history_turns'] : 0, 0, 40 );
		$out['guardrail_id']          = isset( $input['guardrail_id'] ) && preg_match( '/^[A-Za-z0-9:\/._-]{0,2048}$/', $input['guardrail_id'] ) ? $input['guardrail_id'] : '';
		$out['guardrail_version']     = isset( $input['guardrail_version'] ) && preg_match( '/^(DRAFT|\d{1,8})?$/', $input['guardrail_version'] ) ? $input['guardrail_version'] : '';
		foreach ( array( 'enable_streaming', 'enable_site_tools', 'enable_mcp', 'debug' ) as $flag ) {
			$out[ $flag ] = ! empty( $input[ $flag ] );
		}

		$out['credential_source'] = isset( $input['credential_source'] ) && 'stored' === $input['credential_source'] ? 'stored' : 'auto';
		$key_id                   = isset( $input['access_key_id'] ) ? strtoupper( trim( sanitize_text_field( $input['access_key_id'] ) ) ) : '';
		if ( '' === $key_id || preg_match( '/^(AKIA|ASIA)[A-Z0-9]{16}$/', $key_id ) ) {
			$out['access_key_id'] = $key_id;
		} else {
			add_settings_error( Settings::OPTION, 'access_key_id', __( 'The access key ID is not valid; the previous value was kept.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$secret = isset( $input['secret_access_key'] ) ? trim( (string) $input['secret_access_key'] ) : '';
		if ( ! empty( $input['clear_secret'] ) ) {
			$out['secret_access_key'] = '';
			$out['access_key_id']     = '';
		} elseif ( '' !== $secret ) {
			if ( ! Secret_Store::available() ) {
				add_settings_error( Settings::OPTION, 'secret', __( 'This PHP build lacks libsodium, so the secret key was not saved. Use an IAM role or wp-config.php constants instead.', 'ai-chat-for-amazon-bedrock' ) );
			} elseif ( ! preg_match( '/^[A-Za-z0-9\/+=]{40}$/', $secret ) ) {
				add_settings_error( Settings::OPTION, 'secret', __( 'The secret access key is not valid and was not saved.', 'ai-chat-for-amazon-bedrock' ) );
			} else {
				$out['secret_access_key'] = Secret_Store::encrypt( $secret );
			}
		}
		Credentials::reset();
		return $out;
	}

	/**
	 * Render the page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings   = Settings::all();
		$servers    = Mcp_Servers::all();
		$regions    = Settings::regions();
		$has_secret = '' !== (string) $settings['secret_access_key'];
		$notice     = get_transient( 'aicfab_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'aicfab_notice_' . get_current_user_id() );
		}
		include AICFAB_DIR . 'admin/views/settings.php';
	}

	/**
	 * Connection test: a minimal Converse call.
	 */
	public static function handle_test() {
		self::guard( 'aicfab_test' );
		Credentials::reset();
		try {
			$source   = Credentials::resolve()['source'];
			$response = Bedrock_Client::from_settings()->converse(
				array(
					'messages'        => array(
						array(
							'role'    => 'user',
							'content' => array( array( 'text' => 'Reply with the single word OK.' ) ),
						),
					),
					'inferenceConfig' => array( 'maxTokens' => 16 ),
				)
			);
			$usage    = isset( $response['usage']['totalTokens'] ) ? (int) $response['usage']['totalTokens'] : 0;
			/* translators: 1: credential source, 2: model ID, 3: token count. */
			self::notice( 'success', sprintf( __( 'Connected using %1$s credentials. Model %2$s answered (%3$d tokens).', 'ai-chat-for-amazon-bedrock' ), $source, Settings::get( 'model_id' ), $usage ) );
		} catch ( Bedrock_Exception $e ) {
			/* translators: 1: error code, 2: message. */
			self::notice( 'error', sprintf( __( 'Connection failed (%1$s): %2$s', 'ai-chat-for-amazon-bedrock' ), $e->error_code(), $e->getMessage() ) );
		}
		self::back();
	}

	/**
	 * Add an MCP server.
	 */
	public static function handle_mcp_add() {
		self::guard( 'aicfab_mcp_add' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$result = Mcp_Servers::add(
			isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '',
			isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ), array( 'https', 'http' ) ) : '',
			isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''
		);
		// phpcs:enable
		self::notice( is_wp_error( $result ) ? 'error' : 'success', is_wp_error( $result ) ? $result->get_error_message() : __( 'MCP server added. Review its tools below before enabling MCP tools.', 'ai-chat-for-amazon-bedrock' ) );
		self::back();
	}

	/**
	 * Refresh an MCP server's pinned tools.
	 */
	public static function handle_mcp_refresh() {
		self::guard( 'aicfab_mcp_refresh' );
		$id     = isset( $_POST['server'] ) ? sanitize_key( wp_unslash( $_POST['server'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$result = Mcp_Servers::refresh( $id );
		self::notice( is_wp_error( $result ) ? 'error' : 'success', is_wp_error( $result ) ? $result->get_error_message() : __( 'Tool list refreshed.', 'ai-chat-for-amazon-bedrock' ) );
		self::back();
	}

	/**
	 * Remove an MCP server.
	 */
	public static function handle_mcp_remove() {
		self::guard( 'aicfab_mcp_remove' );
		Mcp_Servers::remove( isset( $_POST['server'] ) ? sanitize_key( wp_unslash( $_POST['server'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		self::notice( 'success', __( 'MCP server removed.', 'ai-chat-for-amazon-bedrock' ) );
		self::back();
	}

	/**
	 * Capability and nonce check for admin-post handlers.
	 *
	 * @param string $action Nonce action.
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ai-chat-for-amazon-bedrock' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Queue a notice for the next page view of this user.
	 *
	 * @param string $type    success|error.
	 * @param string $message Message (escaped on output).
	 */
	private static function notice( $type, $message ) {
		set_transient(
			'aicfab_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => (string) $message,
			),
			120
		);
	}

	/**
	 * Redirect back to the settings page.
	 */
	private static function back() {
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE ) );
		exit;
	}
}

<?php
/**
 * REST endpoint for chat: POST /wp-json/aicfab/v1/chat.
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 *
 * @package AICFAB
 */

namespace AICFAB;

defined( 'ABSPATH' ) || exit;

/**
 * The only public entry point. Access is decided by the "access" setting (logged-in users by default);
 * for logged-in users WordPress core requires a valid wp_rest nonce, which also prevents CSRF.
 * Every request is rate limited before any call to Bedrock is made.
 */
final class Rest_Controller {

	const NAMESPACE_V1 = 'aicfab/v1';

	/**
	 * Register routes.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/chat',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'chat' ),
				'permission_callback' => array( __CLASS__, 'can_chat' ),
				'args'                => array(
					'message' => array(
						'type'     => 'string',
						'required' => true,
					),
					'history' => array(
						'type'     => 'array',
						'default'  => array(),
						'maxItems' => 50,
						'items'    => array(
							'type'       => 'object',
							'properties' => array(
								'role' => array(
									'type' => 'string',
									'enum' => array( 'user', 'assistant' ),
								),
								'text' => array( 'type' => 'string' ),
							),
						),
					),
					'stream'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	/**
	 * Permission check from the access setting.
	 *
	 * @return bool|\WP_Error
	 */
	public static function can_chat() {
		$access = Settings::get( 'access' );
		if ( 'everyone' === $access ) {
			return true;
		}
		if ( 'logged_in' === $access && is_user_logged_in() ) {
			return true;
		}
		return new \WP_Error( 'aicfab_forbidden', __( 'Please log in to use the assistant.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * Handle a chat request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function chat( \WP_REST_Request $request ) {
		$messages = Conversation::build(
			$request->get_param( 'message' ),
			$request->get_param( 'history' ),
			Settings::clamp_int( Settings::get( 'max_input_chars' ), 100, 20000 ),
			Settings::clamp_int( Settings::get( 'max_history_turns' ), 0, 40 )
		);
		if ( is_wp_error( $messages ) ) {
			return $messages;
		}
		$limited = Rate_Limiter::hit();
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		try {
			$service = new Chat_Service( Bedrock_Client::from_settings(), Tool_Registry::from_settings() );
		} catch ( Bedrock_Exception $e ) {
			Chat_Service::log( $e );
			return new \WP_Error( 'aicfab_unavailable', Chat_Service::public_message( $e ), array( 'status' => 503 ) );
		}

		if ( $request->get_param( 'stream' ) && Settings::get( 'enable_streaming' ) ) {
			self::stream( $service, $messages );
		}

		try {
			$text = $service->run( $messages );
		} catch ( Bedrock_Exception $e ) {
			Chat_Service::log( $e );
			return new \WP_Error( 'aicfab_upstream', Chat_Service::public_message( $e ), array( 'status' => 502 ) );
		}
		$response = new \WP_REST_Response( array( 'text' => $text ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Stream the turn as Server-Sent Events and end the request.
	 * Events: "text" {delta}, "tool" {name}, "error" {message}, "done" {}.
	 *
	 * @param Chat_Service $service  Service.
	 * @param array        $messages Messages.
	 */
	private static function stream( Chat_Service $service, array $messages ) {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		ini_set( 'zlib.output_compression', '0' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- compression buffers SSE.
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running stream.
		}
		status_header( 200 );
		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'X-Accel-Buffering: no' );
		header( 'X-Content-Type-Options: nosniff' );

		$send = static function ( $event, $data ) {
			echo 'event: ' . $event . "\n" . 'data: ' . wp_json_encode( $data ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON in an SSE frame; rendered with textContent.
			flush();
		};

		try {
			$service->run(
				$messages,
				static function ( $delta ) use ( $send ) {
					$send( 'text', array( 'delta' => $delta ) );
				},
				static function ( $name ) use ( $send ) {
					$send( 'tool', array( 'name' => $name ) );
				},
				static function () {
					return (bool) connection_aborted();
				}
			);
			$send( 'done', new \stdClass() );
		} catch ( Bedrock_Exception $e ) {
			Chat_Service::log( $e );
			$send( 'error', array( 'message' => Chat_Service::public_message( $e ) ) );
		}
		exit;
	}
}

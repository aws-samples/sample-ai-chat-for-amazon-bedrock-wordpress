<?php
/**
 * Runs one chat turn, including the server-side tool-use loop.
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 *
 * @package AICFAB
 */

namespace AICFAB;

defined( 'ABSPATH' ) || exit;

/**
 * Prompts and responses are never written to logs or to the database. Errors are logged by code only
 * (and only when the plugin's debug setting and WP_DEBUG_LOG are both on).
 */
class Chat_Service {

	const MAX_TOOL_ROUNDS = 4;

	/**
	 * Bedrock client.
	 *
	 * @var Bedrock_Client
	 */
	private $client;

	/**
	 * Available tools.
	 *
	 * @var Tool_Registry
	 */
	private $tools;

	/**
	 * Constructor.
	 *
	 * @param Bedrock_Client $client Bedrock client.
	 * @param Tool_Registry  $tools  Tools.
	 */
	public function __construct( Bedrock_Client $client, Tool_Registry $tools ) {
		$this->client = $client;
		$this->tools  = $tools;
	}

	/**
	 * Run a turn.
	 *
	 * @param array         $messages Converse messages ending with the new user message.
	 * @param callable|null $on_text  When set, stream text deltas through it.
	 * @param callable|null $on_tool  Called with a tool name when the model uses a tool (for UI status).
	 * @param callable|null $is_abort Stop when it returns true.
	 * @return string Final assistant text.
	 * @throws Bedrock_Exception On failure.
	 */
	public function run( array $messages, $on_text = null, $on_tool = null, $is_abort = null ) {
		$final = '';
		for ( $round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++ ) {
			$request = $this->request( $messages, $round < self::MAX_TOOL_ROUNDS );
			if ( $on_text ) {
				$filter   = new Reasoning_Filter();
				$emit     = static function ( $delta ) use ( $filter, $on_text ) {
					$visible = $filter->feed( $delta );
					if ( '' !== $visible ) {
						call_user_func( $on_text, $visible );
					}
				};
				$response = $this->client->converse_stream( $request, $emit, $is_abort );
				$tail     = $filter->flush();
				if ( '' !== $tail ) {
					call_user_func( $on_text, $tail );
				}
			} else {
				$response = $this->client->converse( $request );
			}
			$message = $response['output']['message'];
			$text    = Reasoning_Filter::strip( self::text_of( $message ) );
			if ( '' !== $text ) {
				$final .= ( '' === $final ? '' : "\n\n" ) . $text;
			}
			if ( 'tool_use' !== $response['stopReason'] ) {
				return $final;
			}
			$results = array();
			foreach ( $message['content'] as $block ) {
				if ( isset( $block['toolUse'] ) ) {
					if ( $on_tool ) {
						call_user_func( $on_tool, (string) $block['toolUse']['name'] );
					}
					$results[] = $this->tools->execute( $block['toolUse'] );
				}
			}
			if ( empty( $results ) ) {
				return $final;
			}
			$messages[] = array(
				'role'    => 'assistant',
				'content' => $message['content'],
			);
			$messages[] = array(
				'role'    => 'user',
				'content' => $results,
			);
		}
		return $final;
	}

	/**
	 * Converse request body.
	 *
	 * @param array $messages    Messages.
	 * @param bool  $allow_tools Whether the model may call tools in this round.
	 * @return array
	 */
	private function request( array $messages, $allow_tools ) {
		$system  = trim( (string) Settings::get( 'system_prompt' ) );
		$request = array(
			'messages'        => $messages,
			'inferenceConfig' => array(
				'maxTokens'   => Settings::clamp_int( Settings::get( 'max_tokens' ), 64, 8192 ),
				'temperature' => max( 0.0, min( 1.0, (float) Settings::get( 'temperature' ) ) ),
			),
		);
		if ( '' !== $system ) {
			$request['system'] = array( array( 'text' => $system ) );
		}
		// toolConfig must be present whenever the conversation contains tool blocks.
		if ( $this->tools->has_tools() ) {
			$request['toolConfig'] = $this->tools->tool_config();
			if ( Settings::get( 'enable_site_tools' ) ) {
				$request['system'][] = array( 'text' => 'Questions about this website, its business, posts or pages must be answered from the site_search_posts and site_get_post tools: search first, then read the most relevant result. Do not guess.' );
			}
			if ( ! $allow_tools ) {
				$request['system'][] = array( 'text' => 'Do not call any more tools. Answer with the information you already have.' );
			}
		}
		$guardrail = trim( (string) Settings::get( 'guardrail_id' ) );
		if ( '' !== $guardrail ) {
			$request['guardrailConfig'] = array(
				'guardrailIdentifier' => $guardrail,
				'guardrailVersion'    => '' !== (string) Settings::get( 'guardrail_version' ) ? (string) Settings::get( 'guardrail_version' ) : 'DRAFT',
			);
		}
		return apply_filters( 'aicfab_converse_request', $request );
	}

	/**
	 * Concatenated text blocks of a message.
	 *
	 * @param array $message Message.
	 * @return string
	 */
	private static function text_of( array $message ) {
		$parts = array();
		foreach ( isset( $message['content'] ) ? $message['content'] : array() as $block ) {
			if ( isset( $block['text'] ) && '' !== $block['text'] ) {
				$parts[] = $block['text'];
			}
		}
		return implode( '', $parts );
	}

	/**
	 * Message safe to show the current user for an error.
	 *
	 * @param Bedrock_Exception $e Error.
	 * @return string
	 */
	public static function public_message( Bedrock_Exception $e ) {
		if ( current_user_can( 'manage_options' ) ) {
			/* translators: 1: error code, 2: error message. Shown to administrators only. */
			return sprintf( __( 'Amazon Bedrock error (%1$s): %2$s', 'ai-chat-for-amazon-bedrock' ), $e->error_code(), $e->getMessage() );
		}
		if ( 'ThrottlingException' === $e->error_code() ) {
			return __( 'The assistant is busy right now. Please try again in a moment.', 'ai-chat-for-amazon-bedrock' );
		}
		return __( 'Sorry, the assistant is unavailable right now.', 'ai-chat-for-amazon-bedrock' );
	}

	/**
	 * Log an error code (never prompts, responses or credentials).
	 *
	 * @param Bedrock_Exception $e Error.
	 */
	public static function log( Bedrock_Exception $e ) {
		if ( Settings::get( 'debug' ) && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( sprintf( '[ai-chat-for-amazon-bedrock] %s (HTTP %d)', $e->error_code(), $e->http_status() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- opt-in diagnostic, codes only.
		}
	}
}

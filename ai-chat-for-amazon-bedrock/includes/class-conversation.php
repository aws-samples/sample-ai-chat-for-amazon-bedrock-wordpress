<?php
/**
 * Validation of client-supplied chat input.
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 *
 * @package AICFAB
 */

namespace AICFAB;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin is stateless: conversations are not stored on the server. The browser sends the prior
 * turns with each request, so they are treated as untrusted and reduced to plain text in strictly
 * alternating user/assistant turns, bounded in count and size. Tool calls never come from the client.
 */
final class Conversation {

	/**
	 * Build Converse messages from the new message and prior turns.
	 *
	 * @param mixed $message   New user message.
	 * @param mixed $history   Prior turns: array of { role: user|assistant, text: string }.
	 * @param int   $max_chars Maximum characters per message.
	 * @param int   $max_turns Maximum prior turns kept (most recent).
	 * @return array|\WP_Error Converse messages.
	 */
	public static function build( $message, $history, $max_chars, $max_turns ) {
		$text = self::clean( $message );
		if ( '' === $text ) {
			return new \WP_Error( 'aicfab_input', __( 'Please enter a message.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 400 ) );
		}
		if ( self::length( $text ) > $max_chars ) {
			/* translators: %d: maximum number of characters. */
			return new \WP_Error( 'aicfab_input', sprintf( __( 'Messages are limited to %d characters.', 'ai-chat-for-amazon-bedrock' ), $max_chars ), array( 'status' => 400 ) );
		}

		$turns = array();
		foreach ( is_array( $history ) ? $history : array() as $turn ) {
			if ( ! is_array( $turn ) || ! isset( $turn['role'], $turn['text'] ) || ! in_array( $turn['role'], array( 'user', 'assistant' ), true ) ) {
				continue;
			}
			$turn_text = self::clean( $turn['text'] );
			if ( '' === $turn_text ) {
				continue;
			}
			$limit   = 'assistant' === $turn['role'] ? $max_chars * 4 : $max_chars;
			$turns[] = array(
				'role'    => $turn['role'],
				'content' => array( array( 'text' => self::truncate( $turn_text, $limit ) ) ),
			);
		}
		$turns = array_slice( $turns, -1 * max( 0, $max_turns ) );

		// Enforce alternation ending in an assistant turn, starting with a user turn.
		$messages = array();
		foreach ( $turns as $turn ) {
			$last = end( $messages );
			if ( $last && $last['role'] === $turn['role'] ) {
				array_pop( $messages );
			}
			$messages[] = $turn;
		}
		while ( $messages && 'user' !== $messages[0]['role'] ) {
			array_shift( $messages );
		}
		if ( $messages && 'user' === end( $messages )['role'] ) {
			array_pop( $messages );
		}

		$messages[] = array(
			'role'    => 'user',
			'content' => array( array( 'text' => $text ) ),
		);
		return $messages;
	}

	/**
	 * Normalize a string: valid UTF-8, no control characters except newlines/tabs, trimmed.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function clean( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = wp_check_invalid_utf8( $value, true );
		$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value );
		return trim( (string) $value );
	}

	/**
	 * Character length.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
	}

	/**
	 * Truncate by characters.
	 *
	 * @param string $text  Text.
	 * @param int    $limit Limit.
	 * @return string
	 */
	private static function truncate( $text, $limit ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $limit ) : substr( $text, 0, $limit );
	}
}

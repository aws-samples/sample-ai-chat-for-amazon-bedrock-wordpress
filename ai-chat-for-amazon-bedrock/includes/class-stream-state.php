<?php
/**
 * Assembles a Converse response from ConverseStream events.
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 *
 * @package AICFAB
 */

namespace AICFAB;

defined( 'ABSPATH' ) || exit;

/**
 * Assembles a Converse-shaped message from ConverseStream events.
 */
final class Stream_State {

	/**
	 * Content blocks by index.
	 *
	 * @var array<int,array>
	 */
	private $blocks = array();

	/**
	 * Partial tool input JSON by block index.
	 *
	 * @var array<int,string>
	 */
	private $tool_json = array();

	/**
	 * Stop reason.
	 *
	 * @var string
	 */
	private $stop_reason = '';

	/**
	 * Token usage.
	 *
	 * @var array
	 */
	private $usage = array();

	/**
	 * Apply one decoded event stream message.
	 *
	 * @param array    $message Decoded message (headers, payload).
	 * @param callable $on_text Text delta callback.
	 * @throws Bedrock_Exception On an exception event.
	 */
	public function apply( array $message, callable $on_text ) {
		$headers = $message['headers'];
		$payload = json_decode( $message['payload'], true );
		$type    = isset( $headers[':message-type'] ) ? $headers[':message-type'] : '';
		if ( 'exception' === $type || 'error' === $type ) {
			$code = isset( $headers[':exception-type'] ) ? $headers[':exception-type'] : ( isset( $headers[':error-code'] ) ? $headers[':error-code'] : 'StreamException' );
			$text = is_array( $payload ) && isset( $payload['message'] ) ? (string) $payload['message'] : 'The model stream reported an error.';
			throw new Bedrock_Exception( (string) $code, substr( $text, 0, 500 ) );
		}
		if ( ! is_array( $payload ) ) {
			return;
		}
		$event = isset( $headers[':event-type'] ) ? $headers[':event-type'] : '';
		$index = isset( $payload['contentBlockIndex'] ) ? (int) $payload['contentBlockIndex'] : 0;
		switch ( $event ) {
			case 'contentBlockStart':
				if ( isset( $payload['start']['toolUse'] ) ) {
					$this->blocks[ $index ]    = array(
						'toolUse' => array(
							'toolUseId' => (string) $payload['start']['toolUse']['toolUseId'],
							'name'      => (string) $payload['start']['toolUse']['name'],
							'input'     => new \stdClass(),
						),
					);
					$this->tool_json[ $index ] = '';
				}
				break;
			case 'contentBlockDelta':
				if ( isset( $payload['delta']['text'] ) ) {
					if ( ! isset( $this->blocks[ $index ]['text'] ) ) {
						$this->blocks[ $index ] = array( 'text' => '' );
					}
					$this->blocks[ $index ]['text'] .= $payload['delta']['text'];
					$on_text( (string) $payload['delta']['text'] );
				} elseif ( isset( $payload['delta']['toolUse']['input'] ) && isset( $this->tool_json[ $index ] ) ) {
					$this->tool_json[ $index ] .= (string) $payload['delta']['toolUse']['input'];
				}
				break;
			case 'contentBlockStop':
				if ( isset( $this->tool_json[ $index ] ) ) {
					$input                                      = json_decode( '' === $this->tool_json[ $index ] ? '{}' : $this->tool_json[ $index ] );
					$this->blocks[ $index ]['toolUse']['input'] = is_object( $input ) ? $input : new \stdClass();
				}
				break;
			case 'messageStop':
				$this->stop_reason = isset( $payload['stopReason'] ) ? (string) $payload['stopReason'] : '';
				break;
			case 'metadata':
				$this->usage = isset( $payload['usage'] ) && is_array( $payload['usage'] ) ? $payload['usage'] : array();
				break;
		}
	}

	/**
	 * Converse-shaped result.
	 *
	 * @return array
	 */
	public function result() {
		ksort( $this->blocks );
		$content = array_values(
			array_filter(
				$this->blocks,
				static function ( $block ) {
					return ! isset( $block['text'] ) || '' !== $block['text'];
				}
			)
		);
		return array(
			'output'     => array(
				'message' => array(
					'role'    => 'assistant',
					'content' => $content,
				),
			),
			'stopReason' => $this->stop_reason,
			'usage'      => $this->usage,
		);
	}
}

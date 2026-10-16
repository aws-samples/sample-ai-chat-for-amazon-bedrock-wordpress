<?php
/**
 * Assembles a Converse response from ConverseStream events (as decoded by the AWS SDK).
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 *
 * @package AICFAB
 */

namespace AICFAB;

defined( 'ABSPATH' ) || exit;

/**
 * Each SDK event is an array with a single key naming the event type, for example
 * array( 'contentBlockDelta' => array( 'contentBlockIndex' => 0, 'delta' => array( 'text' => 'Hi' ) ) ).
 */
final class Stream_State {

	const ERROR_EVENTS = array( 'internalServerException', 'modelStreamErrorException', 'validationException', 'throttlingException', 'serviceUnavailableException' );

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
	 * Apply one stream event.
	 *
	 * @param array    $event   SDK event.
	 * @param callable $on_text Text delta callback.
	 * @throws Bedrock_Exception On an error event.
	 */
	public function apply( $event, callable $on_text ) {
		if ( ! is_array( $event ) ) {
			return;
		}
		foreach ( self::ERROR_EVENTS as $error ) {
			if ( isset( $event[ $error ] ) ) {
				$message = isset( $event[ $error ]['message'] ) ? (string) $event[ $error ]['message'] : 'The model stream reported an error.';
				throw new Bedrock_Exception( ucfirst( $error ), substr( $message, 0, 500 ) );
			}
		}
		if ( isset( $event['contentBlockStart']['start']['toolUse'] ) ) {
			$index                     = (int) $event['contentBlockStart']['contentBlockIndex'];
			$tool                      = $event['contentBlockStart']['start']['toolUse'];
			$this->blocks[ $index ]    = array(
				'toolUse' => array(
					'toolUseId' => (string) $tool['toolUseId'],
					'name'      => (string) $tool['name'],
					'input'     => new \stdClass(),
				),
			);
			$this->tool_json[ $index ] = '';
		} elseif ( isset( $event['contentBlockDelta'] ) ) {
			$index = (int) $event['contentBlockDelta']['contentBlockIndex'];
			$delta = $event['contentBlockDelta']['delta'];
			if ( isset( $delta['text'] ) ) {
				if ( ! isset( $this->blocks[ $index ]['text'] ) ) {
					$this->blocks[ $index ] = array( 'text' => '' );
				}
				$this->blocks[ $index ]['text'] .= (string) $delta['text'];
				$on_text( (string) $delta['text'] );
			} elseif ( isset( $delta['toolUse']['input'], $this->tool_json[ $index ] ) ) {
				$this->tool_json[ $index ] .= (string) $delta['toolUse']['input'];
			}
		} elseif ( isset( $event['contentBlockStop'] ) ) {
			$index = (int) $event['contentBlockStop']['contentBlockIndex'];
			if ( isset( $this->tool_json[ $index ] ) ) {
				$input                                      = json_decode( '' === $this->tool_json[ $index ] ? '{}' : $this->tool_json[ $index ] );
				$this->blocks[ $index ]['toolUse']['input'] = is_object( $input ) ? $input : new \stdClass();
			}
		} elseif ( isset( $event['messageStop'] ) ) {
			$this->stop_reason = isset( $event['messageStop']['stopReason'] ) ? (string) $event['messageStop']['stopReason'] : '';
		} elseif ( isset( $event['metadata']['usage'] ) ) {
			$this->usage = (array) $event['metadata']['usage'];
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

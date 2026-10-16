<?php
/**
 * Builds the Bedrock toolConfig and dispatches tool calls.
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 *
 * @package AICFAB
 */

namespace AICFAB;

defined( 'ABSPATH' ) || exit;

/**
 * Tool calls are only ever produced by the model and executed on the server against this fixed
 * registry. Clients cannot name a tool or supply arguments.
 */
final class Tool_Registry {

	const MAX_RESULT = 8000;

	/**
	 * Tool name to dispatch target.
	 *
	 * @var array<string,array>
	 */
	private $map = array();

	/**
	 * Converse toolSpec entries.
	 *
	 * @var array<int,array>
	 */
	private $specs = array();

	/**
	 * Registry for the current settings.
	 *
	 * @return self
	 */
	public static function from_settings() {
		$registry = new self();
		if ( Settings::get( 'enable_site_tools' ) ) {
			foreach ( Site_Tools::definitions() as $name => $def ) {
				$registry->add(
					$name,
					$def['description'],
					$def['schema'],
					array(
						'kind'    => 'site',
						'handler' => $def['handler'],
					)
				);
			}
		}
		return $registry;
	}

	/**
	 * Register a tool. Later duplicates are ignored.
	 *
	 * @param string $name        Bedrock tool name.
	 * @param string $description Description.
	 * @param object $schema      JSON schema.
	 * @param array  $target      Dispatch target.
	 */
	public function add( $name, $description, $schema, array $target ) {
		if ( isset( $this->map[ $name ] ) || count( $this->specs ) >= 64 ) {
			return;
		}
		$this->map[ $name ] = $target;
		$this->specs[]      = array(
			'toolSpec' => array(
				'name'        => $name,
				'description' => $description,
				'inputSchema' => array( 'json' => $schema ),
			),
		);
	}

	/**
	 * Whether any tool is registered.
	 *
	 * @return bool
	 */
	public function has_tools() {
		return ! empty( $this->specs );
	}

	/**
	 * Converse toolConfig.
	 *
	 * @return array
	 */
	public function tool_config() {
		return array( 'tools' => $this->specs );
	}

	/**
	 * Run one tool call and return a Converse toolResult content block.
	 *
	 * @param array $tool_use toolUse block from the model.
	 * @return array
	 */
	public function execute( array $tool_use ) {
		$id     = isset( $tool_use['toolUseId'] ) ? (string) $tool_use['toolUseId'] : '';
		$name   = isset( $tool_use['name'] ) ? (string) $tool_use['name'] : '';
		$input  = isset( $tool_use['input'] ) ? $tool_use['input'] : new \stdClass();
		$input  = is_object( $input ) ? $input : json_decode( (string) wp_json_encode( $input ) );
		$input  = is_object( $input ) ? $input : new \stdClass();
		$status = 'success';

		if ( ! isset( $this->map[ $name ] ) ) {
			$text   = 'Unknown tool.';
			$status = 'error';
		} else {
			$text = (string) wp_json_encode( call_user_func( $this->map[ $name ]['handler'], $input ) );
		}

		return array(
			'toolResult' => array(
				'toolUseId' => $id,
				'content'   => array( array( 'text' => Text::clean( '' !== $text ? $text : '(empty result)', self::MAX_RESULT ) ) ),
				'status'    => $status,
			),
		);
	}
}

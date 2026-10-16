<?php
/**
 * Administrator-managed list of MCP servers and their pinned tool lists.
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 *
 * @package AICFAB
 */

namespace AICFAB;

defined( 'ABSPATH' ) || exit;

/**
 * Tool lists are fetched only when an administrator adds or refreshes a server and are then pinned.
 * Chat requests never re-discover tools, so a server cannot silently add or rename tools (or change
 * their descriptions) after an administrator reviewed them.
 */
final class Mcp_Servers {

	const OPTION      = 'aicfab_mcp_servers';
	const MAX_SERVERS = 10;

	/**
	 * All servers keyed by id.
	 *
	 * @return array<string,array>
	 */
	public static function all() {
		$servers = get_option( self::OPTION, array() );
		return is_array( $servers ) ? $servers : array();
	}

	/**
	 * Add a server and fetch its tools.
	 *
	 * @param string $label Display label.
	 * @param string $url   Server URL.
	 * @param string $token Optional bearer token (stored encrypted).
	 * @return true|\WP_Error
	 */
	public static function add( $label, $url, $token ) {
		$servers = self::all();
		if ( count( $servers ) >= self::MAX_SERVERS ) {
			return new \WP_Error( 'aicfab_mcp', __( 'The maximum number of MCP servers is reached.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$label = sanitize_text_field( $label );
		$id    = substr( preg_replace( '/[^a-z0-9]/', '', strtolower( $label ) ), 0, 16 );
		if ( '' === $id ) {
			return new \WP_Error( 'aicfab_mcp', __( 'The label must contain letters or digits.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( isset( $servers[ $id ] ) ) {
			return new \WP_Error( 'aicfab_mcp', __( 'A server with this label already exists.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$checked = Url_Guard::check( $url );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		$token = trim( (string) $token );
		if ( '' !== $token && ! Secret_Store::available() ) {
			return new \WP_Error( 'aicfab_mcp', __( 'This PHP build lacks libsodium, so tokens cannot be stored securely.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$servers[ $id ] = array(
			'label'        => $label,
			'url'          => $checked,
			'token'        => '' === $token ? '' : Secret_Store::encrypt( $token ),
			'tools'        => array(),
			'refreshed_at' => 0,
		);
		$tools          = self::fetch_tools( $servers[ $id ] );
		if ( is_wp_error( $tools ) ) {
			return $tools;
		}
		$servers[ $id ]['tools']        = $tools;
		$servers[ $id ]['refreshed_at'] = time();
		update_option( self::OPTION, $servers, false );
		return true;
	}

	/**
	 * Re-fetch and re-pin a server's tools.
	 *
	 * @param string $id Server id.
	 * @return true|\WP_Error
	 */
	public static function refresh( $id ) {
		$servers = self::all();
		if ( ! isset( $servers[ $id ] ) ) {
			return new \WP_Error( 'aicfab_mcp', __( 'Unknown MCP server.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$tools = self::fetch_tools( $servers[ $id ] );
		if ( is_wp_error( $tools ) ) {
			return $tools;
		}
		$servers[ $id ]['tools']        = $tools;
		$servers[ $id ]['refreshed_at'] = time();
		update_option( self::OPTION, $servers, false );
		return true;
	}

	/**
	 * Remove a server.
	 *
	 * @param string $id Server id.
	 */
	public static function remove( $id ) {
		$servers = self::all();
		unset( $servers[ $id ] );
		update_option( self::OPTION, $servers, false );
	}

	/**
	 * Client for a stored server, re-validating its URL first.
	 *
	 * @param array $server Stored server.
	 * @return Mcp_Client|\WP_Error
	 */
	public static function client( array $server ) {
		$checked = Url_Guard::check( isset( $server['url'] ) ? $server['url'] : '' );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		return new Mcp_Client( $checked, Secret_Store::decrypt( isset( $server['token'] ) ? $server['token'] : '' ) );
	}

	/**
	 * Fetch tools from a server.
	 *
	 * @param array $server Server.
	 * @return array|\WP_Error
	 */
	private static function fetch_tools( array $server ) {
		$client = self::client( $server );
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		try {
			$tools = $client->list_tools();
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'aicfab_mcp', $e->getMessage() );
		}
		$clean = array();
		foreach ( $tools as $tool ) {
			if ( ! preg_match( '/^[A-Za-z0-9_.-]{1,64}$/', $tool['name'] ) ) {
				continue; // Names Bedrock cannot represent are skipped rather than rewritten.
			}
			$clean[] = array(
				'name'        => $tool['name'],
				'description' => self::clean_text( $tool['description'], 1000 ),
				'inputSchema' => strlen( $tool['inputSchema'] ) <= 16384 ? $tool['inputSchema'] : '{"type":"object"}',
			);
		}
		return $clean;
	}

	/**
	 * Strip control characters and truncate.
	 *
	 * @param string $text  Text.
	 * @param int    $limit Maximum characters.
	 * @return string
	 */
	public static function clean_text( $text, $limit ) {
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $text );
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $text, 0, $limit ) : substr( (string) $text, 0, $limit );
	}
}

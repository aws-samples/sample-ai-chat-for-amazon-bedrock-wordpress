<?php
/**
 * Minimal Model Context Protocol client (Streamable HTTP transport, tools only).
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 *
 * @package AICFAB
 */

namespace AICFAB;

defined( 'ABSPATH' ) || exit;

/**
 * Speaks JSON-RPC 2.0 to one MCP server over Streamable HTTP: initialize, tools/list, tools/call.
 * Every request goes through wp_safe_remote_post() with redirects disabled, a short timeout and a
 * response size cap. The URL must already have passed Url_Guard::check().
 *
 * @see https://modelcontextprotocol.io/specification/2025-06-18/basic/transports
 */
final class Mcp_Client {

	const PROTOCOL_VERSION = '2025-06-18';
	const MAX_RESPONSE     = 1048576;

	/**
	 * Server URL.
	 *
	 * @var string
	 */
	private $url;

	/**
	 * Bearer token, may be empty.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Mcp-Session-Id issued by the server.
	 *
	 * @var string
	 */
	private $session = '';

	/**
	 * Whether initialize has completed.
	 *
	 * @var bool
	 */
	private $initialized = false;

	/**
	 * Next JSON-RPC request id.
	 *
	 * @var int
	 */
	private $next_id = 1;

	/**
	 * Constructor.
	 *
	 * @param string $url   Validated server URL.
	 * @param string $token Optional bearer token.
	 */
	public function __construct( $url, $token = '' ) {
		$this->url   = (string) $url;
		$this->token = (string) $token;
	}

	/**
	 * List the server's tools (follows pagination, at most 10 pages / 200 tools).
	 *
	 * @return array<int,array{name:string,description:string,inputSchema:string}> inputSchema is JSON text.
	 * @throws \RuntimeException On protocol or transport failure.
	 */
	public function list_tools() {
		$this->initialize();
		$tools  = array();
		$cursor = null;
		for ( $page = 0; $page < 10; $page++ ) {
			$result = $this->call( 'tools/list', null === $cursor ? new \stdClass() : array( 'cursor' => $cursor ) );
			foreach ( isset( $result->tools ) && is_array( $result->tools ) ? $result->tools : array() as $tool ) {
				if ( ! isset( $tool->name ) || ! is_string( $tool->name ) ) {
					continue;
				}
				$schema  = isset( $tool->inputSchema ) && is_object( $tool->inputSchema ) ? $tool->inputSchema : (object) array( 'type' => 'object' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MCP wire format.
				$tools[] = array(
					'name'        => $tool->name,
					'description' => isset( $tool->description ) && is_string( $tool->description ) ? $tool->description : '',
					'inputSchema' => wp_json_encode( $schema ),
				);
			}
			if ( count( $tools ) >= 200 || empty( $result->nextCursor ) || ! is_string( $result->nextCursor ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MCP wire format.
				break;
			}
			$cursor = $result->nextCursor; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MCP wire format.
		}
		return $tools;
	}

	/**
	 * Call a tool and flatten its result to text.
	 *
	 * @param string $name      Tool name as the server knows it.
	 * @param object $arguments Arguments object.
	 * @return array{text:string,is_error:bool}
	 * @throws \RuntimeException On protocol or transport failure.
	 */
	public function call_tool( $name, $arguments ) {
		$this->initialize();
		$result = $this->call(
			'tools/call',
			array(
				'name'      => (string) $name,
				'arguments' => is_object( $arguments ) ? $arguments : new \stdClass(),
			)
		);
		$parts  = array();
		foreach ( isset( $result->content ) && is_array( $result->content ) ? $result->content : array() as $item ) {
			if ( isset( $item->type ) && 'text' === $item->type && isset( $item->text ) ) {
				$parts[] = (string) $item->text;
			} elseif ( isset( $item->type ) ) {
				$parts[] = '[' . sanitize_key( $item->type ) . ' content omitted]';
			}
		}
		if ( empty( $parts ) && isset( $result->structuredContent ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MCP wire format.
			$parts[] = (string) wp_json_encode( $result->structuredContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MCP wire format.
		}
		return array(
			'text'     => implode( "\n", $parts ),
			'is_error' => ! empty( $result->isError ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MCP wire format.
		);
	}

	/**
	 * Initialize the session once per client instance.
	 *
	 * @throws \RuntimeException On failure.
	 */
	private function initialize() {
		if ( $this->initialized ) {
			return;
		}
		$this->call(
			'initialize',
			array(
				'protocolVersion' => self::PROTOCOL_VERSION,
				'capabilities'    => new \stdClass(),
				'clientInfo'      => array(
					'name'    => 'ai-chat-for-amazon-bedrock',
					'version' => AICFAB_VERSION,
				),
			)
		);
		$this->initialized = true;
		$this->post(
			array(
				'jsonrpc' => '2.0',
				'method'  => 'notifications/initialized',
			)
		);
	}

	/**
	 * JSON-RPC request; returns the result object.
	 *
	 * @param string       $method Method.
	 * @param array|object $params Params.
	 * @return object
	 * @throws \RuntimeException On failure.
	 */
	private function call( $method, $params ) {
		$id       = $this->next_id++;
		$response = $this->post(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'method'  => $method,
				'params'  => $params,
			)
		);
		$message  = null;
		foreach ( $response as $candidate ) {
			if ( is_object( $candidate ) && isset( $candidate->id ) && (int) $candidate->id === $id ) {
				$message = $candidate;
				break;
			}
		}
		if ( null === $message ) {
			throw new \RuntimeException( 'The MCP server sent no response for ' . $method . '.' );
		}
		if ( isset( $message->error ) ) {
			$text = isset( $message->error->message ) && is_string( $message->error->message ) ? $message->error->message : 'error';
			throw new \RuntimeException( 'The MCP server returned an error for ' . $method . ': ' . substr( $text, 0, 200 ) );
		}
		return isset( $message->result ) && is_object( $message->result ) ? $message->result : new \stdClass();
	}

	/**
	 * POST one JSON-RPC message; returns every JSON-RPC message in the reply.
	 *
	 * @param array $payload Message.
	 * @return object[]
	 * @throws \RuntimeException On transport failure.
	 */
	private function post( array $payload ) {
		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json, text/event-stream',
		);
		if ( $this->initialized ) {
			$headers['MCP-Protocol-Version'] = self::PROTOCOL_VERSION;
		}
		if ( '' !== $this->session ) {
			$headers['Mcp-Session-Id'] = $this->session;
		}
		if ( '' !== $this->token ) {
			$headers['Authorization'] = 'Bearer ' . $this->token;
		}
		$args     = array(
			'timeout'             => 15,
			'redirection'         => 0,
			'limit_response_size' => self::MAX_RESPONSE,
			'headers'             => $headers,
			'body'                => wp_json_encode( $payload ),
		);
		$response = Url_Guard::allow_local() ? wp_remote_post( $this->url, $args ) : wp_safe_remote_post( $this->url, $args );
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Could not reach the MCP server (' . $response->get_error_code() . ').' );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			throw new \RuntimeException( 'The MCP server returned HTTP ' . $status . '.' );
		}
		$session = wp_remote_retrieve_header( $response, 'mcp-session-id' );
		if ( is_string( $session ) && '' !== $session && preg_match( '/^[\x21-\x7E]{1,256}$/', $session ) ) {
			$this->session = $session;
		}
		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === trim( $body ) ) {
			return array();
		}
		$type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		if ( false !== strpos( $type, 'text/event-stream' ) ) {
			return self::parse_sse( $body );
		}
		$decoded = json_decode( $body );
		return is_array( $decoded ) ? $decoded : array( $decoded );
	}

	/**
	 * Extract JSON-RPC messages from an SSE body.
	 *
	 * @param string $body SSE text.
	 * @return object[]
	 */
	public static function parse_sse( $body ) {
		$messages = array();
		foreach ( preg_split( "/\r?\n\r?\n/", $body ) as $event ) {
			$data = array();
			foreach ( preg_split( "/\r?\n/", $event ) as $line ) {
				if ( 0 === strpos( $line, 'data:' ) ) {
					$data[] = ltrim( substr( $line, 5 ), ' ' );
				}
			}
			if ( $data ) {
				$decoded = json_decode( implode( "\n", $data ) );
				if ( is_object( $decoded ) ) {
					$messages[] = $decoded;
				}
			}
		}
		return $messages;
	}
}

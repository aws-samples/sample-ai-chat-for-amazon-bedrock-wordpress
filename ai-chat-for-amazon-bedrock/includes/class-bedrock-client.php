<?php
/**
 * Amazon Bedrock Runtime client for the Converse and ConverseStream APIs.
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 *
 * @package AICFAB
 */

namespace AICFAB;

defined( 'ABSPATH' ) || exit;

/**
 * Uses the model-agnostic Converse API, so any Bedrock text model or inference profile that
 * supports Converse works without per-model payload formats.
 *
 * @see https://docs.aws.amazon.com/bedrock/latest/APIReference/API_runtime_Converse.html
 */
class Bedrock_Client {

	const SERVICE = 'bedrock';

	/**
	 * AWS region.
	 *
	 * @var string
	 */
	private $region;

	/**
	 * Model ID, inference profile ID or ARN.
	 *
	 * @var string
	 */
	private $model_id;

	/**
	 * Constructor.
	 *
	 * @param string $region   AWS region.
	 * @param string $model_id Model ID, inference profile ID or ARN.
	 * @throws Bedrock_Exception On an invalid region or model ID.
	 */
	public function __construct( $region, $model_id ) {
		if ( ! Settings::is_region( $region ) || ! Settings::is_model_id( $model_id ) ) {
			throw new Bedrock_Exception( 'configuration', 'The configured region or model ID is invalid.' );
		}
		$this->region   = $region;
		$this->model_id = $model_id;
	}

	/**
	 * Client for the configured settings.
	 *
	 * @return static
	 */
	public static function from_settings() {
		return new static( (string) Settings::get( 'region' ), (string) Settings::get( 'model_id' ) );
	}

	/**
	 * Non-streaming Converse call.
	 *
	 * @param array $request Converse request body (messages, system, inferenceConfig, toolConfig).
	 * @return array Converse response (output.message, stopReason, usage).
	 * @throws Bedrock_Exception On a non-retryable failure, or when the retry also fails.
	 */
	public function converse( array $request ) {
		$attempt = 0;
		while ( true ) {
			++$attempt;
			try {
				return $this->converse_once( $request );
			} catch ( Bedrock_Exception $e ) {
				if ( $attempt >= 2 ) {
					throw $e;
				}
				if ( 'ExpiredTokenException' === $e->error_code() || 'UnrecognizedClientException' === $e->error_code() ) {
					Credentials::reset();
				} elseif ( $e->is_retryable() ) {
					usleep( 750000 );
				} else {
					throw $e;
				}
			}
		}
	}

	/**
	 * Streaming ConverseStream call. Text deltas are passed to $on_text as they arrive; the assembled
	 * message is returned in the same shape converse() returns. Falls back to converse() when PHP has
	 * no cURL extension.
	 *
	 * @param array         $request  Converse request body.
	 * @param callable      $on_text  function( string $delta ): void.
	 * @param callable|null $is_abort function(): bool. Return true to stop (e.g. the visitor disconnected).
	 * @return array
	 * @throws Bedrock_Exception On any failure, including an exception event in the stream.
	 */
	public function converse_stream( array $request, callable $on_text, $is_abort = null ) {
		if ( ! function_exists( 'curl_init' ) ) {
			$response = $this->converse( $request );
			foreach ( $response['output']['message']['content'] as $block ) {
				if ( isset( $block['text'] ) ) {
					$on_text( $block['text'] );
				}
			}
			return $response;
		}

		$body    = $this->encode_body( $request );
		$url     = $this->url( 'converse-stream' );
		$headers = $this->signed_headers( $url, $body, 'application/vnd.amazon.eventstream' );

		$decoder = new Event_Stream_Decoder();
		$state   = new Stream_State();
		$status  = 0;
		$raw     = '';
		$failure = null;

		$ch = curl_init( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- streaming is not supported by the WP HTTP API.
		curl_setopt_array( // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
			$ch,
			array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $body,
				CURLOPT_HTTPHEADER     => self::header_lines( $headers ),
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_TIMEOUT        => 180,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_HEADERFUNCTION => static function ( $handle, $line ) use ( &$status ) {
					if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $line, $m ) ) {
						$status = (int) $m[1];
					}
					return strlen( $line );
				},
				CURLOPT_WRITEFUNCTION  => static function ( $handle, $chunk ) use ( &$status, &$raw, &$failure, $decoder, $state, $on_text, $is_abort ) {
					if ( 200 !== $status ) {
						$raw .= $chunk;
						return strlen( $raw ) > 65536 ? 0 : strlen( $chunk );
					}
					if ( $is_abort && call_user_func( $is_abort ) ) {
						return 0;
					}
					try {
						foreach ( $decoder->feed( $chunk ) as $message ) {
							$state->apply( $message, $on_text );
						}
					} catch ( \Exception $e ) {
						$failure = $e;
						return 0;
					}
					return strlen( $chunk );
				},
			)
		);
		curl_exec( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec
		$errno = curl_errno( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_errno
		curl_close( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close

		if ( $failure instanceof Bedrock_Exception ) {
			throw $failure;
		}
		if ( $failure ) {
			throw new Bedrock_Exception( 'stream', 'The response stream could not be decoded.' );
		}
		if ( 200 !== $status ) {
			$error = self::error_from_body( $status, $raw, '' );
			throw $error;
		}
		if ( $errno && ! ( $is_abort && call_user_func( $is_abort ) ) ) {
			throw new Bedrock_Exception( 'network', 'The connection to Amazon Bedrock failed (cURL error ' . (int) $errno . ').' );
		}
		return $state->result();
	}

	/**
	 * One signed Converse request through the WordPress HTTP API.
	 *
	 * @param array $request Request body.
	 * @return array
	 * @throws Bedrock_Exception On failure.
	 */
	private function converse_once( array $request ) {
		$body     = $this->encode_body( $request );
		$url      = $this->url( 'converse' );
		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => 120,
				'redirection' => 0,
				'headers'     => $this->signed_headers( $url, $body, 'application/json' ),
				'body'        => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new Bedrock_Exception( 'network', 'The connection to Amazon Bedrock failed: ' . $response->get_error_code() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$text   = wp_remote_retrieve_body( $response );
		if ( 200 !== $status ) {
			$error = self::error_from_body( $status, $text, (string) wp_remote_retrieve_header( $response, 'x-amzn-errortype' ) );
			throw $error;
		}
		$data = json_decode( $text, true );
		if ( ! is_array( $data ) || ! isset( $data['output']['message']['content'] ) ) {
			throw new Bedrock_Exception( 'response', 'Amazon Bedrock returned an unexpected response.' );
		}
		return $data;
	}

	/**
	 * Endpoint URL for an operation.
	 *
	 * @param string $operation converse or converse-stream.
	 * @return string
	 */
	private function url( $operation ) {
		$host = apply_filters( 'aicfab_bedrock_runtime_host', 'bedrock-runtime.' . $this->region . '.amazonaws.com', $this->region );
		return 'https://' . $host . SigV4::encode_path( array( 'model', $this->model_id, $operation ) );
	}

	/**
	 * Signed headers for a JSON body.
	 *
	 * @param string $url    URL.
	 * @param string $body   Body.
	 * @param string $accept Accept header.
	 * @return array<string,string>
	 */
	private function signed_headers( $url, $body, $accept ) {
		return SigV4::sign(
			'POST',
			$url,
			array(
				'Content-Type' => 'application/json',
				'Accept'       => $accept,
			),
			$body,
			Credentials::resolve(),
			$this->region,
			self::SERVICE
		);
	}

	/**
	 * JSON-encode a request; empty JSON objects must stay objects.
	 *
	 * @param array $request Request.
	 * @return string
	 * @throws Bedrock_Exception When encoding fails.
	 */
	private function encode_body( array $request ) {
		$body = wp_json_encode( $request );
		if ( false === $body ) {
			throw new Bedrock_Exception( 'request', 'The request could not be encoded.' );
		}
		return $body;
	}

	/**
	 * Header map to cURL header lines.
	 *
	 * @param array<string,string> $headers Headers.
	 * @return string[]
	 */
	private static function header_lines( array $headers ) {
		$lines = array( 'Expect:' );
		foreach ( $headers as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}
		return $lines;
	}

	/**
	 * Build an exception from an AWS JSON error response. The AWS message is kept (it is admin-facing
	 * and never echoes credentials); visitors get a generic message from Chat_Service.
	 *
	 * @param int    $status     HTTP status.
	 * @param string $body       Response body.
	 * @param string $error_type x-amzn-ErrorType header value.
	 * @return Bedrock_Exception
	 */
	private static function error_from_body( $status, $body, $error_type ) {
		$data    = json_decode( (string) $body, true );
		$code    = $error_type ? strtok( $error_type, ':' ) : '';
		$message = is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : ( is_array( $data ) && isset( $data['Message'] ) ? (string) $data['Message'] : '' );
		if ( '' === $code && is_array( $data ) && isset( $data['__type'] ) ) {
			$code = substr( strrchr( '#' . $data['__type'], '#' ), 1 );
		}
		if ( '' === $code ) {
			$code = 403 === $status ? 'AccessDeniedException' : ( 429 === $status ? 'ThrottlingException' : 'HTTP' . $status );
		}
		return new Bedrock_Exception( $code, substr( '' !== $message ? $message : 'Amazon Bedrock returned HTTP ' . $status . '.', 0, 500 ), $status );
	}
}

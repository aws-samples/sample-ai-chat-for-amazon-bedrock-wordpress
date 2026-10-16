<?php
/**
 * Amazon Bedrock Runtime access through the AWS SDK for PHP (Converse and ConverseStream).
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 *
 * @package AICFAB
 */

namespace AICFAB;

defined( 'ABSPATH' ) || exit;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Exception\AwsException;

/**
 * Request signing, credentials, retries and response-stream decoding are all handled by the AWS SDK.
 * Credentials come from the SDK's default provider chain (IAM role on EC2/ECS/EKS, web identity,
 * environment, shared config); the plugin never stores or asks for AWS keys.
 *
 * @see https://docs.aws.amazon.com/bedrock/latest/APIReference/API_runtime_Converse.html
 * @see https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/guide_credentials.html
 */
class Bedrock_Client {

	/**
	 * SDK client.
	 *
	 * @var BedrockRuntimeClient
	 */
	private $sdk;

	/**
	 * Model ID, inference profile ID or ARN.
	 *
	 * @var string
	 */
	private $model_id;

	/**
	 * Constructor.
	 *
	 * @param string                    $region   AWS region.
	 * @param string                    $model_id Model ID, inference profile ID or ARN.
	 * @param BedrockRuntimeClient|null $sdk      Injected client (tests).
	 * @throws Bedrock_Exception On an invalid region or model ID.
	 */
	public function __construct( $region, $model_id, $sdk = null ) {
		if ( ! Settings::is_region( $region ) || ! Settings::is_model_id( $model_id ) ) {
			throw new Bedrock_Exception( 'configuration', 'The configured region or model ID is invalid.' );
		}
		$this->model_id = $model_id;
		$this->sdk      = $sdk ? $sdk : new BedrockRuntimeClient(
			array(
				'region'    => $region,
				'version'   => '2023-09-30',
				'retries'   => array(
					'mode'         => 'standard',
					'max_attempts' => 3,
				),
				'http'      => array(
					'connect_timeout' => 10,
					'timeout'         => 180,
				),
				'ua_append' => array( 'aws-samples-wp-aicfab/' . AICFAB_VERSION ),
			)
		);
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
	 * @param array $request Converse request without modelId.
	 * @return array Converse response (output.message, stopReason, usage).
	 * @throws Bedrock_Exception On failure.
	 */
	public function converse( array $request ) {
		try {
			$result = $this->sdk->converse( array( 'modelId' => $this->model_id ) + $request );
		} catch ( AwsException $e ) {
			throw self::from_aws( $e );
		}
		return array(
			'output'     => array( 'message' => self::normalize_message( $result['output']['message'] ) ),
			'stopReason' => (string) $result['stopReason'],
			'usage'      => (array) $result['usage'],
		);
	}

	/**
	 * Streaming ConverseStream call. Text deltas go to $on_text as they arrive; the assembled message
	 * is returned in the same shape converse() returns.
	 *
	 * @param array         $request  Converse request without modelId.
	 * @param callable      $on_text  function( string $delta ): void.
	 * @param callable|null $is_abort function(): bool. Return true to stop (e.g. the visitor disconnected).
	 * @return array
	 * @throws Bedrock_Exception On failure, including an error event in the stream.
	 */
	public function converse_stream( array $request, callable $on_text, $is_abort = null ) {
		$state = new Stream_State();
		try {
			$result = $this->sdk->converseStream( array( 'modelId' => $this->model_id ) + $request );
			foreach ( $result['stream'] as $event ) {
				$state->apply( $event, $on_text );
				if ( $is_abort && call_user_func( $is_abort ) ) {
					break;
				}
			}
		} catch ( AwsException $e ) {
			throw self::from_aws( $e );
		}
		return $state->result();
	}

	/**
	 * Map an SDK exception. The AWS message is kept for administrators; visitors see a generic message.
	 *
	 * @param AwsException $e SDK exception.
	 * @return Bedrock_Exception
	 */
	private static function from_aws( AwsException $e ) {
		$code    = (string) $e->getAwsErrorCode();
		$message = (string) $e->getAwsErrorMessage();
		if ( '' === $code ) {
			$code = $e->getPrevious() ? 'network' : 'AwsException';
		}
		if ( '' === $message ) {
			$message = 'network' === $code ? 'The connection to Amazon Bedrock failed.' : 'Amazon Bedrock returned an error.';
		}
		return new Bedrock_Exception( $code, substr( $message, 0, 500 ), (int) $e->getStatusCode() );
	}

	/**
	 * Tool inputs are JSON documents; the SDK returns them as PHP arrays, so an empty object comes
	 * back as []. Convert inputs to objects so they serialize as {} when the message is sent again.
	 *
	 * @param array $message Message from the SDK.
	 * @return array
	 */
	public static function normalize_message( $message ) {
		$message = (array) $message;
		foreach ( isset( $message['content'] ) ? $message['content'] : array() as $i => $block ) {
			if ( isset( $block['toolUse'] ) ) {
				$message['content'][ $i ]['toolUse']['input'] = self::to_object( isset( $block['toolUse']['input'] ) ? $block['toolUse']['input'] : array() );
			}
		}
		return $message;
	}

	/**
	 * Decode a JSON document value into stdClass objects (never a list at the top level).
	 *
	 * @param mixed $value Value.
	 * @return object
	 */
	public static function to_object( $value ) {
		$decoded = json_decode( (string) wp_json_encode( empty( $value ) ? new \stdClass() : $value ) );
		return is_object( $decoded ) ? $decoded : new \stdClass();
	}
}

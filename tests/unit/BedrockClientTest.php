<?php
/**
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 */

use AICFAB\Bedrock_Client;
use AICFAB\Bedrock_Exception;
use AICFAB\Stream_State;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\BedrockRuntime\Exception\BedrockRuntimeException;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use PHPUnit\Framework\TestCase;

final class BedrockClientTest extends TestCase {

	private function client( MockHandler $mock ) {
		$sdk = new BedrockRuntimeClient(
			array(
				'region'      => 'us-east-1',
				'version'     => '2023-09-30',
				'credentials' => false,
				'handler'     => $mock,
			)
		);
		return new Bedrock_Client( 'us-east-1', 'us.amazon.nova-lite-v1:0', $sdk );
	}

	public function test_converse_sends_model_and_normalizes_empty_tool_input() {
		$mock = new MockHandler();
		$sent = null;
		$mock->append(
			function ( CommandInterface $cmd ) use ( &$sent ) {
				$sent = $cmd->toArray();
				return new Result(
					array(
						'output'     => array(
							'message' => array(
								'role'    => 'assistant',
								'content' => array( array( 'toolUse' => array( 'toolUseId' => 't1', 'name' => 'site_info', 'input' => array() ) ) ),
							),
						),
						'stopReason' => 'tool_use',
						'usage'      => array( 'totalTokens' => 7 ),
					)
				);
			}
		);
		$out = $this->client( $mock )->converse( array( 'messages' => array( array( 'role' => 'user', 'content' => array( array( 'text' => 'hi' ) ) ) ) ) );
		$this->assertSame( 'us.amazon.nova-lite-v1:0', $sent['modelId'] );
		$this->assertSame( 'tool_use', $out['stopReason'] );
		$input = $out['output']['message']['content'][0]['toolUse']['input'];
		$this->assertInstanceOf( stdClass::class, $input );
		$this->assertSame( '{}', json_encode( $input ) );
	}

	public function test_aws_errors_are_mapped() {
		$mock = new MockHandler();
		$mock->append(
			function ( CommandInterface $cmd ) {
				return new BedrockRuntimeException( 'denied', $cmd, array( 'code' => 'AccessDeniedException', 'message' => 'You do not have access', 'response' => new GuzzleHttp\Psr7\Response( 403 ) ) );
			}
		);
		try {
			$this->client( $mock )->converse( array( 'messages' => array() ) );
			$this->fail( 'expected exception' );
		} catch ( Bedrock_Exception $e ) {
			$this->assertSame( 'AccessDeniedException', $e->error_code() );
			$this->assertSame( 403, $e->http_status() );
			$this->assertSame( 'You do not have access', $e->getMessage() );
		}
	}

	public function test_rejects_invalid_configuration() {
		$this->expectException( Bedrock_Exception::class );
		new Bedrock_Client( 'evil.example/x', 'us.amazon.nova-lite-v1:0' );
	}

	public function test_stream_state_assembles_text_and_tool_use() {
		$state  = new Stream_State();
		$deltas = array();
		$events = array(
			array( 'messageStart' => array( 'role' => 'assistant' ) ),
			array( 'contentBlockDelta' => array( 'contentBlockIndex' => 0, 'delta' => array( 'text' => 'Let me ' ) ) ),
			array( 'contentBlockDelta' => array( 'contentBlockIndex' => 0, 'delta' => array( 'text' => 'check.' ) ) ),
			array( 'contentBlockStart' => array( 'contentBlockIndex' => 1, 'start' => array( 'toolUse' => array( 'toolUseId' => 't1', 'name' => 'site_search_posts' ) ) ) ),
			array( 'contentBlockDelta' => array( 'contentBlockIndex' => 1, 'delta' => array( 'toolUse' => array( 'input' => '{"query":' ) ) ) ),
			array( 'contentBlockDelta' => array( 'contentBlockIndex' => 1, 'delta' => array( 'toolUse' => array( 'input' => '"bedrock"}' ) ) ) ),
			array( 'contentBlockStop' => array( 'contentBlockIndex' => 1 ) ),
			array( 'messageStop' => array( 'stopReason' => 'tool_use' ) ),
			array( 'metadata' => array( 'usage' => array( 'totalTokens' => 42 ) ) ),
		);
		foreach ( $events as $event ) {
			$state->apply(
				$event,
				function ( $d ) use ( &$deltas ) {
					$deltas[] = $d;
				}
			);
		}
		$result = $state->result();
		$this->assertSame( array( 'Let me ', 'check.' ), $deltas );
		$this->assertSame( 'tool_use', $result['stopReason'] );
		$this->assertSame( 'Let me check.', $result['output']['message']['content'][0]['text'] );
		$this->assertSame( 'bedrock', $result['output']['message']['content'][1]['toolUse']['input']->query );
		$this->assertSame( 42, $result['usage']['totalTokens'] );
	}

	public function test_stream_error_event_raises() {
		$this->expectException( Bedrock_Exception::class );
		( new Stream_State() )->apply( array( 'throttlingException' => array( 'message' => 'slow down' ) ), 'strlen' );
	}
}

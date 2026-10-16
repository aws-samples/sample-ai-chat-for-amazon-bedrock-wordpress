<?php
/**
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 */

use AICFAB\Bedrock_Exception;
use AICFAB\Event_Stream_Decoder;
use AICFAB\Stream_State;
use PHPUnit\Framework\TestCase;

final class EventStreamTest extends TestCase {

	private static function event( $type, array $payload ) {
		return Event_Stream_Decoder::encode(
			array(
				':event-type'   => $type,
				':content-type' => 'application/json',
				':message-type' => 'event',
			),
			json_encode( $payload )
		);
	}

	public function test_decodes_messages_split_at_every_byte() {
		$stream  = self::event( 'messageStart', array( 'role' => 'assistant' ) ) . self::event(
			'contentBlockDelta',
			array(
				'contentBlockIndex' => 0,
				'delta'             => array( 'text' => 'Hé' ),
			)
		);
		$decoder = new Event_Stream_Decoder();
		$out     = array();
		foreach ( str_split( $stream ) as $byte ) {
			$out = array_merge( $out, $decoder->feed( $byte ) );
		}
		$this->assertCount( 2, $out );
		$this->assertSame( 'contentBlockDelta', $out[1]['headers'][':event-type'] );
		$this->assertSame( 'Hé', json_decode( $out[1]['payload'], true )['delta']['text'] );
		$this->assertSame( 0, $decoder->pending() );
	}

	public function test_rejects_corrupted_message() {
		$frame     = self::event( 'messageStop', array( 'stopReason' => 'end_turn' ) );
		$frame[20] = chr( ord( $frame[20] ) ^ 1 );
		$this->expectException( UnexpectedValueException::class );
		( new Event_Stream_Decoder() )->feed( $frame );
	}

	public function test_rejects_oversized_prelude() {
		$prelude  = pack( 'NN', 0x7FFFFFFF, 0 );
		$prelude .= pack( 'N', Event_Stream_Decoder::crc( $prelude ) );
		$this->expectException( UnexpectedValueException::class );
		( new Event_Stream_Decoder() )->feed( $prelude );
	}

	public function test_stream_state_assembles_text_and_tool_use() {
		$state   = new Stream_State();
		$deltas  = array();
		$events  = array(
			array(
				'contentBlockDelta',
				array(
					'contentBlockIndex' => 0,
					'delta'             => array( 'text' => 'Let me ' ),
				),
			),
			array(
				'contentBlockDelta',
				array(
					'contentBlockIndex' => 0,
					'delta'             => array( 'text' => 'check.' ),
				),
			),
			array(
				'contentBlockStart',
				array(
					'contentBlockIndex' => 1,
					'start'             => array(
						'toolUse' => array(
							'toolUseId' => 't1',
							'name'      => 'site_search_posts',
						),
					),
				),
			),
			array(
				'contentBlockDelta',
				array(
					'contentBlockIndex' => 1,
					'delta'             => array( 'toolUse' => array( 'input' => '{"query":' ) ),
				),
			),
			array(
				'contentBlockDelta',
				array(
					'contentBlockIndex' => 1,
					'delta'             => array( 'toolUse' => array( 'input' => '"bedrock"}' ) ),
				),
			),
			array( 'contentBlockStop', array( 'contentBlockIndex' => 1 ) ),
			array( 'messageStop', array( 'stopReason' => 'tool_use' ) ),
			array( 'metadata', array( 'usage' => array( 'totalTokens' => 42 ) ) ),
		);
		$decoder = new Event_Stream_Decoder();
		foreach ( $events as $e ) {
			foreach ( $decoder->feed( self::event( $e[0], $e[1] ) ) as $message ) {
				$state->apply(
					$message,
					function ( $d ) use ( &$deltas ) {
						$deltas[] = $d;
					}
				);
			}
		}
		$result = $state->result();
		$this->assertSame( array( 'Let me ', 'check.' ), $deltas );
		$this->assertSame( 'tool_use', $result['stopReason'] );
		$this->assertSame( 'Let me check.', $result['output']['message']['content'][0]['text'] );
		$this->assertSame( 'bedrock', $result['output']['message']['content'][1]['toolUse']['input']->query );
		$this->assertSame( 42, $result['usage']['totalTokens'] );
	}

	public function test_exception_event_raises() {
		$frame = Event_Stream_Decoder::encode(
			array(
				':exception-type' => 'ThrottlingException',
				':message-type'   => 'exception',
			),
			'{"message":"Too many requests"}'
		);
		$state = new Stream_State();
		try {
			foreach ( ( new Event_Stream_Decoder() )->feed( $frame ) as $message ) {
				$state->apply( $message, 'strlen' );
			}
			$this->fail( 'expected exception' );
		} catch ( Bedrock_Exception $e ) {
			$this->assertSame( 'ThrottlingException', $e->error_code() );
			$this->assertTrue( $e->is_retryable() );
		}
	}
}

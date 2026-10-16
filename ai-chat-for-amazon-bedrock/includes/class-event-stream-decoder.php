<?php
/**
 * Incremental decoder for the AWS event stream binary framing (application/vnd.amazon.eventstream).
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 *
 * @package AICFAB
 */

namespace AICFAB;

defined( 'ABSPATH' ) || exit;

/**
 * Feed bytes as they arrive; complete, CRC-verified messages are returned as
 * array( 'headers' => array, 'payload' => string ). Pure PHP, no WordPress dependency.
 *
 * Frame layout: total_length(4) headers_length(4) prelude_crc(4) headers payload message_crc(4).
 */
final class Event_Stream_Decoder {

	const PRELUDE_LENGTH = 12;
	const MAX_MESSAGE    = 16777216; // 16 MiB, the protocol maximum.

	/**
	 * Bytes not yet consumed.
	 *
	 * @var string
	 */
	private $buffer = '';

	/**
	 * Append bytes and return every message that is now complete.
	 *
	 * @param string $bytes Raw bytes from the HTTP body.
	 * @return array<int,array{headers:array<string,mixed>,payload:string}>
	 * @throws \UnexpectedValueException On a corrupt frame.
	 */
	public function feed( $bytes ) {
		$this->buffer .= (string) $bytes;
		$messages      = array();
		while ( true ) {
			$available = strlen( $this->buffer );
			if ( $available < self::PRELUDE_LENGTH ) {
				break;
			}
			$prelude = unpack( 'Ntotal/Nheaders/Ncrc', substr( $this->buffer, 0, self::PRELUDE_LENGTH ) );
			$total   = $prelude['total'];
			if ( $total < 16 || $total > self::MAX_MESSAGE || $prelude['headers'] > $total - 16 ) {
				throw new \UnexpectedValueException( 'Invalid event stream prelude.' );
			}
			if ( self::crc( substr( $this->buffer, 0, 8 ) ) !== $prelude['crc'] ) {
				throw new \UnexpectedValueException( 'Event stream prelude checksum mismatch.' );
			}
			if ( $available < $total ) {
				break;
			}
			$frame        = substr( $this->buffer, 0, $total );
			$this->buffer = (string) substr( $this->buffer, $total );
			$message_crc  = unpack( 'N', substr( $frame, -4 ) );
			if ( self::crc( substr( $frame, 0, -4 ) ) !== $message_crc[1] ) {
				throw new \UnexpectedValueException( 'Event stream message checksum mismatch.' );
			}
			$headers_raw = substr( $frame, self::PRELUDE_LENGTH, $prelude['headers'] );
			$payload     = substr( $frame, self::PRELUDE_LENGTH + $prelude['headers'], $total - 16 - $prelude['headers'] );
			$messages[]  = array(
				'headers' => self::parse_headers( $headers_raw ),
				'payload' => false === $payload ? '' : $payload,
			);
		}
		return $messages;
	}

	/**
	 * Bytes received but not yet part of a complete message.
	 *
	 * @return int
	 */
	public function pending() {
		return strlen( $this->buffer );
	}

	/**
	 * Unsigned CRC32 (IEEE), the checksum the protocol uses.
	 *
	 * @param string $data Data.
	 * @return int
	 */
	public static function crc( $data ) {
		return crc32( $data ) & 0xFFFFFFFF;
	}

	/**
	 * Parse the header block.
	 *
	 * @param string $raw Header bytes.
	 * @return array<string,mixed>
	 * @throws \UnexpectedValueException On a malformed header.
	 */
	private static function parse_headers( $raw ) {
		$headers = array();
		$offset  = 0;
		$length  = strlen( $raw );
		while ( $offset < $length ) {
			$name_len = ord( $raw[ $offset ] );
			$name     = substr( $raw, $offset + 1, $name_len );
			$offset  += 1 + $name_len;
			if ( $offset >= $length ) {
				throw new \UnexpectedValueException( 'Truncated event stream header.' );
			}
			$type = ord( $raw[ $offset ] );
			++$offset;
			switch ( $type ) {
				case 0:
					$value = true;
					break;
				case 1:
					$value = false;
					break;
				case 2:
					$value = unpack( 'c', substr( $raw, $offset, 1 ) )[1];
					++$offset;
					break;
				case 3:
					$value   = unpack( 'n', substr( $raw, $offset, 2 ) )[1];
					$offset += 2;
					break;
				case 4:
					$value   = unpack( 'N', substr( $raw, $offset, 4 ) )[1];
					$offset += 4;
					break;
				case 5:
				case 8:
					$value   = unpack( 'J', substr( $raw, $offset, 8 ) )[1];
					$offset += 8;
					break;
				case 6:
				case 7:
					$value_len = unpack( 'n', substr( $raw, $offset, 2 ) )[1];
					$value     = substr( $raw, $offset + 2, $value_len );
					$offset   += 2 + $value_len;
					break;
				case 9:
					$value   = bin2hex( substr( $raw, $offset, 16 ) );
					$offset += 16;
					break;
				default:
					throw new \UnexpectedValueException( 'Unknown event stream header type.' );
			}
			if ( $offset > $length ) {
				throw new \UnexpectedValueException( 'Truncated event stream header value.' );
			}
			$headers[ $name ] = $value;
		}
		return $headers;
	}

	/**
	 * Encode a message with string headers. Used by tests; not needed at runtime.
	 *
	 * @param array<string,string> $headers String headers.
	 * @param string               $payload Payload.
	 * @return string
	 */
	public static function encode( array $headers, $payload ) {
		$raw = '';
		foreach ( $headers as $name => $value ) {
			$raw .= chr( strlen( $name ) ) . $name . chr( 7 ) . pack( 'n', strlen( $value ) ) . $value;
		}
		$total    = 16 + strlen( $raw ) + strlen( $payload );
		$prelude  = pack( 'NN', $total, strlen( $raw ) );
		$prelude .= pack( 'N', self::crc( $prelude ) );
		$message  = $prelude . $raw . $payload;
		return $message . pack( 'N', self::crc( $message ) );
	}
}

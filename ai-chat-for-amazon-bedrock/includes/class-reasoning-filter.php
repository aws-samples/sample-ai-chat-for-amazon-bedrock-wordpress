<?php
/**
 * Removes <thinking>…</thinking> reasoning blocks from model text, also when tags span stream chunks.
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 *
 * @package AICFAB
 */

namespace AICFAB;

defined( 'ABSPATH' ) || exit;

/**
 * Some models (for example Amazon Nova when tools are configured) emit their reasoning in
 * <thinking> tags before the answer. That text is meant for the model, not for site visitors.
 * Pure PHP, no WordPress dependency.
 */
final class Reasoning_Filter {

	const OPEN  = '<thinking>';
	const CLOSE = '</thinking>';

	/**
	 * Unprocessed text (may hold a partial tag).
	 *
	 * @var string
	 */
	private $pending = '';

	/**
	 * Whether we are inside a reasoning block.
	 *
	 * @var bool
	 */
	private $inside = false;

	/**
	 * Whether visible text was emitted (leading whitespace is dropped until then).
	 *
	 * @var bool
	 */
	private $started = false;

	/**
	 * Filter a whole string.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function strip( $text ) {
		$filter = new self();
		return trim( $filter->feed( (string) $text ) . $filter->flush() );
	}

	/**
	 * Feed a chunk; returns the visible part that is now certain.
	 *
	 * @param string $chunk Chunk.
	 * @return string
	 */
	public function feed( $chunk ) {
		$this->pending .= (string) $chunk;
		$out            = '';
		while ( '' !== $this->pending ) {
			$tag = $this->inside ? self::CLOSE : self::OPEN;
			$pos = strpos( $this->pending, $tag );
			if ( false !== $pos ) {
				if ( ! $this->inside ) {
					$out .= substr( $this->pending, 0, $pos );
				}
				$this->pending = (string) substr( $this->pending, $pos + strlen( $tag ) );
				$this->inside  = ! $this->inside;
				continue;
			}
			$keep = self::partial_suffix( $this->pending, $tag );
			if ( ! $this->inside ) {
				$out .= substr( $this->pending, 0, strlen( $this->pending ) - $keep );
			}
			$this->pending = $keep > 0 ? substr( $this->pending, -$keep ) : '';
			break;
		}
		return $this->visible( $out );
	}

	/**
	 * End of stream: release a held partial tag that turned out not to be one.
	 *
	 * @return string
	 */
	public function flush() {
		$out           = $this->inside ? '' : $this->pending;
		$this->pending = '';
		return $this->visible( $out );
	}

	/**
	 * Drop leading whitespace before the first visible character.
	 *
	 * @param string $out Text.
	 * @return string
	 */
	private function visible( $out ) {
		if ( ! $this->started ) {
			$out = ltrim( $out );
			if ( '' !== $out ) {
				$this->started = true;
			}
		}
		return $out;
	}

	/**
	 * Length of the longest suffix of $text that is a proper prefix of $tag.
	 *
	 * @param string $text Text.
	 * @param string $tag  Tag.
	 * @return int
	 */
	private static function partial_suffix( $text, $tag ) {
		$max = min( strlen( $text ), strlen( $tag ) - 1 );
		for ( $len = $max; $len > 0; $len-- ) {
			if ( substr( $text, -$len ) === substr( $tag, 0, $len ) ) {
				return $len;
			}
		}
		return 0;
	}
}

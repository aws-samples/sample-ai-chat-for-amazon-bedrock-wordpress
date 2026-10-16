<?php
/**
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 */

use AICFAB\Reasoning_Filter;
use PHPUnit\Framework\TestCase;

final class ReasoningFilterTest extends TestCase {

	public function test_strips_whole_blocks() {
		$this->assertSame( 'Answer.', Reasoning_Filter::strip( "<thinking>plan</thinking>\n\nAnswer." ) );
		$this->assertSame( 'A B', Reasoning_Filter::strip( 'A<thinking>x</thinking> B' ) );
		$this->assertSame( 'no tags < here', Reasoning_Filter::strip( 'no tags < here' ) );
	}

	public function test_streaming_at_every_split_point() {
		$text = "<thinking>I should search.</thinking>\n\nThe shop opens at 9:00 <b>.</b><thinking>more</thinking> Done.";
		for ( $size = 1; $size <= 12; $size++ ) {
			$filter = new Reasoning_Filter();
			$out    = '';
			foreach ( str_split( $text, $size ) as $chunk ) {
				$out .= $filter->feed( $chunk );
			}
			$out .= $filter->flush();
			$this->assertSame( 'The shop opens at 9:00 <b>.</b> Done.', $out, "chunk size $size" );
		}
	}

	public function test_unclosed_block_is_dropped_and_partial_tag_released() {
		$filter = new Reasoning_Filter();
		$this->assertSame( 'Hi ', $filter->feed( 'Hi <think' ) );
		$this->assertSame( '<think', $filter->flush() );
		$this->assertSame( 'Visible', Reasoning_Filter::strip( 'Visible<thinking>never closed' ) );
	}
}

<?php
/**
 * Small text helpers.
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 *
 * @package AICFAB
 */

namespace AICFAB;

defined( 'ABSPATH' ) || exit;

/**
 * Text helpers.
 */
final class Text {

	/**
	 * Strip control characters (except tab and newline) and truncate to a number of characters.
	 *
	 * @param string $text  Text.
	 * @param int    $limit Maximum characters.
	 * @return string
	 */
	public static function clean( $text, $limit ) {
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $text );
		return mb_substr( (string) $text, 0, $limit );
	}
}

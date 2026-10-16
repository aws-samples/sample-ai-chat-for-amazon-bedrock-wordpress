<?php
/**
 * Per-visitor rate limit and site-wide daily cap.
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 *
 * @package AICFAB
 */

namespace AICFAB;

defined( 'ABSPATH' ) || exit;

/**
 * Bounds what any one visitor, and the whole site, can spend on Bedrock. Counters live in
 * transients (the object cache when one is installed). They are best-effort under heavy
 * concurrency; set an AWS Budgets alert and Bedrock service quotas as the hard backstop.
 *
 * Visitors are identified by user ID when logged in, otherwise by a salted hash of REMOTE_ADDR.
 * Forwarded-for headers are ignored by default because clients can forge them; sites behind a
 * trusted reverse proxy can supply the real address through the aicfab_client_ip filter.
 */
final class Rate_Limiter {

	/**
	 * Count one request; returns a WP_Error when a limit is exceeded.
	 *
	 * @return true|\WP_Error
	 */
	public static function hit() {
		$per_minute = (int) Settings::get( 'rate_limit_per_minute' );
		$daily_cap  = (int) Settings::get( 'daily_message_cap' );

		$day_key = 'aicfab_day_' . gmdate( 'Ymd' );
		$day     = (int) get_transient( $day_key );
		if ( $daily_cap > 0 && $day >= $daily_cap ) {
			return new \WP_Error( 'aicfab_daily_cap', __( 'The assistant has reached its daily limit. Please try again tomorrow.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 429 ) );
		}

		// The window starts at the visitor's first request, so there is no clock-minute boundary at which
		// a visitor could send twice the limit back to back.
		$now    = time();
		$key    = 'aicfab_rl_' . self::identity();
		$window = get_transient( $key );
		if ( ! is_array( $window ) || ! isset( $window['start'], $window['count'] ) || $now - (int) $window['start'] >= MINUTE_IN_SECONDS ) {
			$window = array(
				'start' => $now,
				'count' => 0,
			);
		}
		if ( $per_minute > 0 && (int) $window['count'] >= $per_minute ) {
			return new \WP_Error( 'aicfab_rate_limited', __( 'You are sending messages too quickly. Please wait a minute.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 429 ) );
		}

		++$window['count'];
		set_transient( $key, $window, MINUTE_IN_SECONDS );
		set_transient( $day_key, $day + 1, DAY_IN_SECONDS + HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Opaque identity for the current visitor.
	 *
	 * @return string
	 */
	public static function identity() {
		$user_id = get_current_user_id();
		if ( $user_id ) {
			return 'u' . $user_id;
		}
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = (string) apply_filters( 'aicfab_client_ip', $ip );
		return 'a' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 20 );
	}
}

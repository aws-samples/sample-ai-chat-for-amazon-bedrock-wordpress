<?php
/**
 * Plugin settings: defaults, sanitization and typed accessors.
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 *
 * @package AICFAB
 */

namespace AICFAB;

defined( 'ABSPATH' ) || exit;

/**
 * Settings stored in a single option. Secrets are NOT stored here in plain text;
 * see Secret_Store and Admin::sanitize_settings().
 */
final class Settings {

	const OPTION = 'aicfab_settings';

	/** Regions where the Bedrock Converse API is offered; admins may extend via the aicfab_regions filter. */
	const REGIONS = array(
		'us-east-1',
		'us-east-2',
		'us-west-2',
		'ca-central-1',
		'sa-east-1',
		'eu-central-1',
		'eu-west-1',
		'eu-west-2',
		'eu-west-3',
		'eu-north-1',
		'eu-south-1',
		'ap-northeast-1',
		'ap-northeast-2',
		'ap-northeast-3',
		'ap-south-1',
		'ap-southeast-1',
		'ap-southeast-2',
	);

	/**
	 * Default settings. Conservative on purpose: chat is for logged-in users only, tools are off.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'region'                => 'us-east-1',
			'model_id'              => 'us.amazon.nova-lite-v1:0',
			'max_tokens'            => 1024,
			'temperature'           => 0.5,
			'system_prompt'         => 'You are a helpful assistant for this website. Answer concisely. If you use site tools, only rely on what they return.',
			'chat_title'            => 'Ask our assistant',
			'welcome_message'       => 'Hello! How can I help you today?',
			'access'                => 'logged_in',
			'rate_limit_per_minute' => 10,
			'daily_message_cap'     => 500,
			'max_input_chars'       => 2000,
			'max_history_turns'     => 10,
			'guardrail_id'          => '',
			'guardrail_version'     => '',
			'enable_streaming'      => true,
			'enable_site_tools'     => false,
			'enable_mcp'            => false,
			'credential_source'     => 'auto',
			'access_key_id'         => '',
			'secret_access_key'     => '',
			'debug'                 => false,
		);
	}

	/**
	 * Current settings merged over defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), array_intersect_key( $stored, self::defaults() ) );
	}

	/**
	 * Single setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Allowed regions.
	 *
	 * @return string[]
	 */
	public static function regions() {
		$regions = apply_filters( 'aicfab_regions', self::REGIONS );
		return array_values( array_filter( (array) $regions, array( __CLASS__, 'is_region' ) ) );
	}

	/**
	 * Whether a string is shaped like an AWS region code.
	 *
	 * @param mixed $region Candidate.
	 * @return bool
	 */
	public static function is_region( $region ) {
		return is_string( $region ) && 1 === preg_match( '/^[a-z]{2}(-gov)?-[a-z]+-\d$/', $region );
	}

	/**
	 * Whether a string is shaped like a Bedrock model ID, inference profile ID or ARN.
	 *
	 * @param mixed $model_id Candidate.
	 * @return bool
	 */
	public static function is_model_id( $model_id ) {
		return is_string( $model_id ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:\/-]{2,199}$/', $model_id );
	}

	/**
	 * Clamp an integer into a range.
	 *
	 * @param mixed $value Value.
	 * @param int   $min   Minimum.
	 * @param int   $max   Maximum.
	 * @return int
	 */
	public static function clamp_int( $value, $min, $max ) {
		return max( $min, min( $max, (int) $value ) );
	}
}

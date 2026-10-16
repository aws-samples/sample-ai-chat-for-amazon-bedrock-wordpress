<?php
/**
 * Error type for Bedrock and credential failures.
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 *
 * @package AICFAB
 */

namespace AICFAB;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a short machine code (e.g. ThrottlingException) that is safe to log, and a message that is
 * safe to show to administrators. Visitors only ever see a generic message (see Chat_Service).
 */
class Bedrock_Exception extends \Exception {

	/**
	 * Machine-readable code.
	 *
	 * @var string
	 */
	private $error_code;

	/**
	 * HTTP status from AWS, 0 when not applicable.
	 *
	 * @var int
	 */
	private $http_status;

	/**
	 * Constructor.
	 *
	 * @param string $error_code  Machine-readable code.
	 * @param string $message     Admin-facing message (never contains credentials or prompts).
	 * @param int    $http_status HTTP status from AWS, 0 when not applicable.
	 */
	public function __construct( $error_code, $message, $http_status = 0 ) {
		parent::__construct( $message );
		$this->error_code  = (string) $error_code;
		$this->http_status = (int) $http_status;
	}

	/**
	 * Machine-readable code.
	 *
	 * @return string
	 */
	public function error_code() {
		return $this->error_code;
	}

	/**
	 * HTTP status from AWS.
	 *
	 * @return int
	 */
	public function http_status() {
		return $this->http_status;
	}
}

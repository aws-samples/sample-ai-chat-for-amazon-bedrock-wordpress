<?php
/**
 * Authenticated encryption for secrets kept in the WordPress database.
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 *
 * @package AICFAB
 */

namespace AICFAB;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts values with libsodium (XSalsa20-Poly1305) using a key derived from the site's
 * wp-config.php salts. This is defence in depth: a leaked database dump or SQL-injection read
 * does not reveal the secret without wp-config.php. It does NOT protect against code execution
 * on the server. The preferred credential source is still an IAM role (see Credentials).
 */
final class Secret_Store {

	const PREFIX = 'aicfab1:';

	/**
	 * Whether encryption is available on this PHP build.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'random_bytes' );
	}

	/**
	 * Encrypt a plain-text secret. Returns '' for an empty input.
	 *
	 * @param string $plain Secret.
	 * @return string Encoded ciphertext.
	 * @throws \RuntimeException When libsodium is unavailable.
	 */
	public static function encrypt( $plain ) {
		if ( '' === (string) $plain ) {
			return '';
		}
		if ( ! self::available() ) {
			throw new \RuntimeException( 'libsodium is required to store secrets.' );
		}
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( (string) $plain, $nonce, self::key() );
		return self::PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext encoding.
	}

	/**
	 * Decrypt a value produced by encrypt(). Returns '' when the value is empty or cannot be
	 * authenticated (for example after the site salts were rotated).
	 *
	 * @param string $encoded Ciphertext.
	 * @return string
	 */
	public static function decrypt( $encoded ) {
		$encoded = (string) $encoded;
		if ( '' === $encoded || 0 !== strpos( $encoded, self::PREFIX ) || ! self::available() ) {
			return '';
		}
		$raw = base64_decode( substr( $encoded, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext decoding.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, self::key() );
		return false === $plain ? '' : $plain;
	}

	/**
	 * 32-byte key derived from the site salts, domain-separated for this plugin.
	 *
	 * @return string
	 */
	private static function key() {
		$material = wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' );
		return hash_hmac( 'sha256', 'aicfab-secret-store-v1', $material, true );
	}
}

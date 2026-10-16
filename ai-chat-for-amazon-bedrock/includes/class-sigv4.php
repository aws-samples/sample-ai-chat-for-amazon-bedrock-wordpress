<?php
/**
 * AWS Signature Version 4 request signer.
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 *
 * @package AICFAB
 */

namespace AICFAB;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal SigV4 signer for JSON POST requests to AWS service endpoints (not S3).
 * Pure PHP with no WordPress dependency so it can be unit-tested against botocore vectors.
 *
 * @see https://docs.aws.amazon.com/IAM/latest/UserGuide/reference_sigv4-create-signed-request.html
 */
final class SigV4 {

	/**
	 * Encode a path the way the request is sent on the wire: each segment RFC 3986 encoded once.
	 *
	 * @param string[] $segments Raw (unencoded) path segments.
	 * @return string Path beginning with '/'.
	 */
	public static function encode_path( array $segments ) {
		return '/' . implode( '/', array_map( 'rawurlencode', $segments ) );
	}

	/**
	 * Sign a request. Returns the headers to send (input headers plus X-Amz-Date, optional
	 * X-Amz-Security-Token and Authorization). Header names in the result are lower-case.
	 *
	 * @param string               $method      HTTP method.
	 * @param string               $url         Full https URL whose path is already wire-encoded.
	 * @param array<string,string> $headers    Headers to sign (Host is derived from the URL).
	 * @param string               $body        Request body.
	 * @param array<string,string> $credentials Keys access_key_id, secret_access_key, optional session_token.
	 * @param string               $region      Region, e.g. us-east-1.
	 * @param string               $service     Signing name, e.g. bedrock.
	 * @param int|null             $timestamp   Unix time; defaults to now.
	 * @return array<string,string>
	 * @throws \InvalidArgumentException On a malformed URL or missing credentials.
	 */
	public static function sign( $method, $url, array $headers, $body, array $credentials, $region, $service, $timestamp = null ) {
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper, no WP dependency.
		if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			throw new \InvalidArgumentException( 'URL must be absolute.' );
		}
		if ( empty( $credentials['access_key_id'] ) || empty( $credentials['secret_access_key'] ) ) {
			throw new \InvalidArgumentException( 'Credentials are incomplete.' );
		}

		$timestamp = null === $timestamp ? time() : (int) $timestamp;
		$amz_date  = gmdate( 'Ymd\THis\Z', $timestamp );
		$date      = gmdate( 'Ymd', $timestamp );

		$signed = array();
		foreach ( $headers as $name => $value ) {
			$signed[ strtolower( trim( $name ) ) ] = trim( preg_replace( '/\s+/', ' ', (string) $value ) );
		}
		$signed['host']       = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$signed['x-amz-date'] = $amz_date;
		if ( ! empty( $credentials['session_token'] ) ) {
			$signed['x-amz-security-token'] = $credentials['session_token'];
		}
		ksort( $signed, SORT_STRING );

		$canonical_headers = '';
		foreach ( $signed as $name => $value ) {
			$canonical_headers .= $name . ':' . $value . "\n";
		}
		$signed_header_names = implode( ';', array_keys( $signed ) );

		$canonical_request = implode(
			"\n",
			array(
				strtoupper( $method ),
				self::canonical_uri( isset( $parts['path'] ) ? $parts['path'] : '/' ),
				self::canonical_query( isset( $parts['query'] ) ? $parts['query'] : '' ),
				$canonical_headers,
				$signed_header_names,
				hash( 'sha256', (string) $body ),
			)
		);

		$scope          = $date . '/' . $region . '/' . $service . '/aws4_request';
		$string_to_sign = "AWS4-HMAC-SHA256\n" . $amz_date . "\n" . $scope . "\n" . hash( 'sha256', $canonical_request );

		$key       = hash_hmac( 'sha256', $date, 'AWS4' . $credentials['secret_access_key'], true );
		$key       = hash_hmac( 'sha256', $region, $key, true );
		$key       = hash_hmac( 'sha256', $service, $key, true );
		$key       = hash_hmac( 'sha256', 'aws4_request', $key, true );
		$signature = hash_hmac( 'sha256', $string_to_sign, $key );

		unset( $signed['host'] ); // The HTTP client sets Host itself.
		$signed['authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $credentials['access_key_id'] . '/' . $scope
			. ', SignedHeaders=' . $signed_header_names . ', Signature=' . $signature;
		return $signed;
	}

	/**
	 * Canonical URI for non-S3 services: every segment of the wire path is encoded again.
	 *
	 * @param string $path Wire path.
	 * @return string
	 */
	public static function canonical_uri( $path ) {
		if ( '' === $path || '/' === $path ) {
			return '/';
		}
		$segments = explode( '/', ltrim( $path, '/' ) );
		return '/' . implode( '/', array_map( 'rawurlencode', $segments ) );
	}

	/**
	 * Canonical query string: sorted, RFC 3986 encoded key=value pairs.
	 *
	 * @param string $query Raw query string.
	 * @return string
	 */
	public static function canonical_query( $query ) {
		if ( '' === $query ) {
			return '';
		}
		$pairs = array();
		foreach ( explode( '&', $query ) as $pair ) {
			$kv      = explode( '=', $pair, 2 );
			$pairs[] = array( rawurlencode( rawurldecode( $kv[0] ) ), rawurlencode( rawurldecode( isset( $kv[1] ) ? $kv[1] : '' ) ) );
		}
		usort(
			$pairs,
			static function ( $a, $b ) {
				$by_key = strcmp( $a[0], $b[0] );
				return 0 !== $by_key ? $by_key : strcmp( $a[1], $b[1] );
			}
		);
		return implode(
			'&',
			array_map(
				static function ( $p ) {
					return $p[0] . '=' . $p[1];
				},
				$pairs
			)
		);
	}
}

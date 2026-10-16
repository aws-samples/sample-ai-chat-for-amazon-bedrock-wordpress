<?php
/**
 * AWS credential resolution.
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 *
 * @package AICFAB
 */

namespace AICFAB;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves credentials in the order the AWS SDKs use, so a site running on AWS needs no stored key:
 *
 *  1. wp-config.php constants AICFAB_AWS_ACCESS_KEY_ID / AICFAB_AWS_SECRET_ACCESS_KEY (/ AICFAB_AWS_SESSION_TOKEN)
 *  2. Environment variables AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY (/ AWS_SESSION_TOKEN)
 *  3. Web identity (EKS IRSA): AWS_WEB_IDENTITY_TOKEN_FILE + AWS_ROLE_ARN via STS AssumeRoleWithWebIdentity
 *  4. Container credentials (ECS task role, EKS Pod Identity)
 *  5. EC2 instance profile through IMDSv2
 *
 * When the administrator chose "stored" credentials, only the encrypted key pair from the settings is used.
 * Temporary credentials are cached encrypted (Secret_Store) until five minutes before they expire.
 */
final class Credentials {

	const CACHE_KEY = 'aicfab_temp_credentials';

	/** Hosts the container-credentials provider may contact, as in the AWS SDKs. */
	const CONTAINER_HOSTS = array( '169.254.170.2', '169.254.170.23', '127.0.0.1', 'localhost', '[::1]', '[fd00:ec2::23]' );

	/**
	 * Credentials resolved during this request.
	 *
	 * @var array<string,string>|null
	 */
	private static $memo = null;

	/**
	 * Resolve credentials.
	 *
	 * @return array{access_key_id:string,secret_access_key:string,session_token:string,source:string}
	 * @throws Bedrock_Exception When no provider yields credentials.
	 */
	public static function resolve() {
		if ( null !== self::$memo ) {
			return self::$memo;
		}
		if ( 'stored' === Settings::get( 'credential_source' ) ) {
			$secret = Secret_Store::decrypt( (string) Settings::get( 'secret_access_key' ) );
			$id     = (string) Settings::get( 'access_key_id' );
			if ( '' === $id || '' === $secret ) {
				throw new Bedrock_Exception( 'credentials', 'Stored AWS credentials are missing or could not be decrypted.' );
			}
			self::$memo = self::creds( $id, $secret, '', 'stored' );
			return self::$memo;
		}

		$static = self::from_static();
		if ( $static ) {
			self::$memo = $static;
			return self::$memo;
		}

		$cached = get_transient( self::CACHE_KEY );
		if ( is_string( $cached ) ) {
			$decoded = json_decode( Secret_Store::decrypt( $cached ), true );
			if ( is_array( $decoded ) && ! empty( $decoded['access_key_id'] ) ) {
				self::$memo = $decoded;
				return self::$memo;
			}
		}

		foreach ( array( 'from_web_identity', 'from_container', 'from_imds' ) as $provider ) {
			$result = self::$provider();
			if ( $result ) {
				self::cache( $result['creds'], $result['expires'] );
				self::$memo = $result['creds'];
				return self::$memo;
			}
		}
		throw new Bedrock_Exception( 'credentials', 'No AWS credentials found. Attach an IAM role to the host, or configure credentials in wp-config.php.' );
	}

	/**
	 * Forget memoized and cached temporary credentials (e.g. after an ExpiredToken error).
	 */
	public static function reset() {
		self::$memo = null;
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Constants, then environment.
	 *
	 * @return array<string,string>|null
	 */
	private static function from_static() {
		if ( defined( 'AICFAB_AWS_ACCESS_KEY_ID' ) && defined( 'AICFAB_AWS_SECRET_ACCESS_KEY' ) ) {
			return self::creds(
				(string) constant( 'AICFAB_AWS_ACCESS_KEY_ID' ),
				(string) constant( 'AICFAB_AWS_SECRET_ACCESS_KEY' ),
				defined( 'AICFAB_AWS_SESSION_TOKEN' ) ? (string) constant( 'AICFAB_AWS_SESSION_TOKEN' ) : '',
				'wp-config'
			);
		}
		$id     = getenv( 'AWS_ACCESS_KEY_ID' );
		$secret = getenv( 'AWS_SECRET_ACCESS_KEY' );
		if ( $id && $secret ) {
			$token = getenv( 'AWS_SESSION_TOKEN' );
			return self::creds( $id, $secret, $token ? $token : '', 'environment' );
		}
		return null;
	}

	/**
	 * STS AssumeRoleWithWebIdentity (unsigned call; the web identity token is the proof).
	 *
	 * @return array{creds:array<string,string>,expires:int}|null
	 */
	private static function from_web_identity() {
		$token_file = getenv( 'AWS_WEB_IDENTITY_TOKEN_FILE' );
		$role_arn   = getenv( 'AWS_ROLE_ARN' );
		if ( ! $token_file || ! $role_arn || ! is_readable( $token_file ) ) {
			return null;
		}
		$token = trim( (string) file_get_contents( $token_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local projected token file.
		if ( '' === $token ) {
			return null;
		}
		$region = getenv( 'AWS_REGION' ) ? (string) getenv( 'AWS_REGION' ) : (string) Settings::get( 'region' );
		if ( ! Settings::is_region( $region ) ) {
			return null;
		}
		$response = wp_remote_post(
			'https://sts.' . $region . '.amazonaws.com/',
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'headers'     => array( 'Accept' => 'application/json' ),
				'body'        => array(
					'Action'           => 'AssumeRoleWithWebIdentity',
					'Version'          => '2011-06-15',
					'RoleArn'          => $role_arn,
					'RoleSessionName'  => 'aicfab-' . substr( hash( 'sha256', home_url() ), 0, 8 ) . '-' . time(),
					'WebIdentityToken' => $token,
					'DurationSeconds'  => 3600,
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$c    = isset( $data['AssumeRoleWithWebIdentityResponse']['AssumeRoleWithWebIdentityResult']['Credentials'] )
			? $data['AssumeRoleWithWebIdentityResponse']['AssumeRoleWithWebIdentityResult']['Credentials'] : null;
		if ( ! is_array( $c ) || empty( $c['AccessKeyId'] ) ) {
			return null;
		}
		return array(
			'creds'   => self::creds( $c['AccessKeyId'], $c['SecretAccessKey'], $c['SessionToken'], 'web-identity' ),
			'expires' => is_numeric( $c['Expiration'] ) ? (int) $c['Expiration'] : (int) strtotime( (string) $c['Expiration'] ),
		);
	}

	/**
	 * ECS task role / EKS Pod Identity endpoint.
	 *
	 * @return array{creds:array<string,string>,expires:int}|null
	 */
	private static function from_container() {
		$relative = getenv( 'AWS_CONTAINER_CREDENTIALS_RELATIVE_URI' );
		$full     = getenv( 'AWS_CONTAINER_CREDENTIALS_FULL_URI' );
		if ( $relative ) {
			$url = 'http://169.254.170.2' . $relative;
		} elseif ( $full ) {
			$host = wp_parse_url( $full, PHP_URL_HOST );
			if ( ! in_array( $host, self::CONTAINER_HOSTS, true ) && 'https' !== wp_parse_url( $full, PHP_URL_SCHEME ) ) {
				return null;
			}
			$url = $full;
		} else {
			return null;
		}
		$headers    = array();
		$token_file = getenv( 'AWS_CONTAINER_AUTHORIZATION_TOKEN_FILE' );
		if ( $token_file && is_readable( $token_file ) ) {
			$headers['Authorization'] = trim( (string) file_get_contents( $token_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local token file.
		} elseif ( getenv( 'AWS_CONTAINER_AUTHORIZATION_TOKEN' ) ) {
			$headers['Authorization'] = getenv( 'AWS_CONTAINER_AUTHORIZATION_TOKEN' );
		}
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 2,
				'redirection' => 0,
				'headers'     => $headers,
			)
		);
		return self::parse_credential_document( $response, 'container' );
	}

	/**
	 * EC2 instance metadata, IMDSv2 only (session token required).
	 *
	 * @return array{creds:array<string,string>,expires:int}|null
	 */
	private static function from_imds() {
		if ( 'true' === strtolower( (string) getenv( 'AWS_EC2_METADATA_DISABLED' ) ) ) {
			return null;
		}
		$base  = 'http://169.254.169.254';
		$token = wp_remote_request(
			$base . '/latest/api/token',
			array(
				'method'      => 'PUT',
				'timeout'     => 1,
				'redirection' => 0,
				'headers'     => array( 'X-aws-ec2-metadata-token-ttl-seconds' => '300' ),
			)
		);
		if ( is_wp_error( $token ) || 200 !== wp_remote_retrieve_response_code( $token ) ) {
			return null;
		}
		$args = array(
			'timeout'     => 1,
			'redirection' => 0,
			'headers'     => array( 'X-aws-ec2-metadata-token' => wp_remote_retrieve_body( $token ) ),
		);
		$role = wp_remote_get( $base . '/latest/meta-data/iam/security-credentials/', $args );
		if ( is_wp_error( $role ) || 200 !== wp_remote_retrieve_response_code( $role ) ) {
			return null;
		}
		$role_name = trim( strtok( wp_remote_retrieve_body( $role ), "\n" ) );
		if ( ! preg_match( '/^[\w+=,.@-]{1,64}$/', $role_name ) ) {
			return null;
		}
		return self::parse_credential_document( wp_remote_get( $base . '/latest/meta-data/iam/security-credentials/' . $role_name, $args ), 'instance-profile' );
	}

	/**
	 * Parse the JSON document shared by the container and IMDS providers.
	 *
	 * @param array|\WP_Error $response HTTP response.
	 * @param string          $source   Provider label.
	 * @return array{creds:array<string,string>,expires:int}|null
	 */
	private static function parse_credential_document( $response, $source ) {
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$doc = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $doc ) || empty( $doc['AccessKeyId'] ) || empty( $doc['SecretAccessKey'] ) ) {
			return null;
		}
		return array(
			'creds'   => self::creds( $doc['AccessKeyId'], $doc['SecretAccessKey'], isset( $doc['Token'] ) ? $doc['Token'] : '', $source ),
			'expires' => isset( $doc['Expiration'] ) ? (int) strtotime( $doc['Expiration'] ) : time() + 900,
		);
	}

	/**
	 * Cache temporary credentials, encrypted, until shortly before they expire.
	 *
	 * @param array<string,string> $creds   Credentials.
	 * @param int                  $expires Unix expiry.
	 */
	private static function cache( array $creds, $expires ) {
		$ttl = (int) $expires - time() - 300;
		if ( $ttl > 60 && Secret_Store::available() ) {
			set_transient( self::CACHE_KEY, Secret_Store::encrypt( wp_json_encode( $creds ) ), $ttl );
		}
	}

	/**
	 * Credential array.
	 *
	 * @param string $id     Access key ID.
	 * @param string $secret Secret access key.
	 * @param string $token  Session token.
	 * @param string $source Provider label (safe to show admins).
	 * @return array<string,string>
	 */
	private static function creds( $id, $secret, $token, $source ) {
		return array(
			'access_key_id'     => (string) $id,
			'secret_access_key' => (string) $secret,
			'session_token'     => (string) $token,
			'source'            => (string) $source,
		);
	}
}

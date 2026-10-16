<?php
/**
 * Outbound URL policy for administrator-configured MCP servers (SSRF protection).
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 *
 * @package AICFAB
 */

namespace AICFAB;

defined( 'ABSPATH' ) || exit;

/**
 * An MCP server URL must be https, carry no credentials in the URL, and resolve only to public
 * addresses. Requests are then made with wp_safe_remote_*(), which re-validates the host at
 * request time, and with redirects disabled so a public URL cannot bounce to an internal one.
 *
 * For local development only, define( 'AICFAB_MCP_ALLOW_LOCAL', true ) in wp-config.php to allow
 * http and loopback/private addresses. Never enable it on a public site.
 */
final class Url_Guard {

	/**
	 * Validate a URL. Returns the normalized URL or a WP_Error.
	 *
	 * @param string $url Candidate URL.
	 * @return string|\WP_Error
	 */
	public static function check( $url ) {
		$url   = trim( (string) $url );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return new \WP_Error( 'aicfab_url', __( 'Enter an absolute URL.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new \WP_Error( 'aicfab_url', __( 'The URL must not contain credentials. Use the token field instead.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$local = self::allow_local();
		if ( 'https' !== strtolower( $parts['scheme'] ) && ! ( $local && 'http' === strtolower( $parts['scheme'] ) ) ) {
			return new \WP_Error( 'aicfab_url', __( 'Only https:// URLs are allowed.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( strlen( $url ) > 2048 ) {
			return new \WP_Error( 'aicfab_url', __( 'The URL is too long.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( ! $local ) {
			$host = trim( strtolower( $parts['host'] ), '[]' );
			$ips  = self::resolve( $host );
			if ( empty( $ips ) ) {
				return new \WP_Error( 'aicfab_url', __( 'The host name could not be resolved.', 'ai-chat-for-amazon-bedrock' ) );
			}
			foreach ( $ips as $ip ) {
				if ( ! self::is_public_ip( $ip ) ) {
					return new \WP_Error( 'aicfab_url', __( 'The URL resolves to a private, loopback or reserved address.', 'ai-chat-for-amazon-bedrock' ) );
				}
			}
			if ( ! wp_http_validate_url( $url ) ) {
				return new \WP_Error( 'aicfab_url', __( 'WordPress rejected this URL as unsafe.', 'ai-chat-for-amazon-bedrock' ) );
			}
		}
		return esc_url_raw( $url, array( 'https', 'http' ) );
	}

	/**
	 * Whether local development mode is enabled.
	 *
	 * @return bool
	 */
	public static function allow_local() {
		return defined( 'AICFAB_MCP_ALLOW_LOCAL' ) && true === constant( 'AICFAB_MCP_ALLOW_LOCAL' );
	}

	/**
	 * Whether an IP is a public unicast address.
	 *
	 * @param string $ip Address.
	 * @return bool
	 */
	public static function is_public_ip( $ip ) {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		// Ranges PHP's flags do not cover: carrier-grade NAT, benchmarking, IPv4-mapped/NAT64/ULA/link-local IPv6.
		$denied = array( '100.64.0.0/10', '198.18.0.0/15', '192.0.0.0/24', '::ffff:0:0/96', '64:ff9b::/96', 'fc00::/7', 'fe80::/10' );
		foreach ( $denied as $cidr ) {
			if ( self::in_cidr( $ip, $cidr ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * All A and AAAA records (or the literal address).
	 *
	 * @param string $host Host name or IP literal.
	 * @return string[]
	 */
	private static function resolve( $host ) {
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}
		$ips = array();
		$v4  = gethostbynamel( $host );
		if ( is_array( $v4 ) ) {
			$ips = $v4;
		}
		if ( function_exists( 'dns_get_record' ) ) {
			$v6 = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- resolver warnings are expected for v4-only hosts.
			foreach ( is_array( $v6 ) ? $v6 : array() as $record ) {
				if ( ! empty( $record['ipv6'] ) ) {
					$ips[] = $record['ipv6'];
				}
			}
		}
		return array_values( array_unique( $ips ) );
	}

	/**
	 * CIDR membership for IPv4 and IPv6.
	 *
	 * @param string $ip   Address.
	 * @param string $cidr Network in CIDR notation.
	 * @return bool
	 */
	private static function in_cidr( $ip, $cidr ) {
		list( $net, $bits ) = explode( '/', $cidr );
		$ip_bin             = inet_pton( $ip );
		$net_bin            = inet_pton( $net );
		if ( false === $ip_bin || false === $net_bin || strlen( $ip_bin ) !== strlen( $net_bin ) ) {
			return false;
		}
		$bits  = (int) $bits;
		$bytes = intdiv( $bits, 8 );
		if ( substr( $ip_bin, 0, $bytes ) !== substr( $net_bin, 0, $bytes ) ) {
			return false;
		}
		$rem = $bits % 8;
		if ( 0 === $rem ) {
			return true;
		}
		$mask = chr( ( 0xFF << ( 8 - $rem ) ) & 0xFF );
		return ( $ip_bin[ $bytes ] & $mask ) === ( $net_bin[ $bytes ] & $mask );
	}
}

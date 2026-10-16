<?php
/**
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 */

use AICFAB\SigV4;
use PHPUnit\Framework\TestCase;

final class SigV4Test extends TestCase {

	/**
	 * Vectors produced by botocore's SigV4Auth (see tests/fixtures/README.md) with fake, non-AWS credentials.
	 */
	public function test_matches_botocore() {
		$cases = json_decode( file_get_contents( __DIR__ . '/../fixtures/sigv4-botocore.json' ), true );
		$this->assertNotEmpty( $cases );
		foreach ( $cases as $case ) {
			$headers = SigV4::sign(
				'POST',
				$case['url'],
				array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				$case['body'],
				array(
					'access_key_id'     => 'AKIDEXAMPLE',
					'secret_access_key' => 'test-only-not-a-real-secret', // pragma: allowlist secret
					'session_token'     => $case['session'],
				),
				'us-east-1',
				'bedrock',
				$case['timestamp']
			);
			$this->assertSame(
				'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/' . $case['scope'] . ', SignedHeaders=' . $case['signed_headers'] . ', Signature=' . $case['signature'],
				$headers['authorization']
			);
			$this->assertSame( $case['amz_date'], $headers['x-amz-date'] );
			$this->assertArrayNotHasKey( 'host', $headers );
			if ( '' !== $case['session'] ) {
				$this->assertSame( $case['session'], $headers['x-amz-security-token'] );
			}
		}
	}

	public function test_model_id_with_colon_is_encoded_once_on_the_wire() {
		$this->assertSame( '/model/us.amazon.nova-lite-v1%3A0/converse', SigV4::encode_path( array( 'model', 'us.amazon.nova-lite-v1:0', 'converse' ) ) );
		$this->assertSame( '/model/us.amazon.nova-lite-v1%253A0/converse', SigV4::canonical_uri( '/model/us.amazon.nova-lite-v1%3A0/converse' ) );
	}

	public function test_canonical_query_is_sorted_and_encoded() {
		$this->assertSame( 'a=1&b=x%20y&b=z', SigV4::canonical_query( 'b=z&a=1&b=x%20y' ) );
		$this->assertSame( '', SigV4::canonical_query( '' ) );
	}

	public function test_rejects_missing_credentials() {
		$this->expectException( InvalidArgumentException::class );
		SigV4::sign( 'POST', 'https://example.com/', array(), '', array( 'access_key_id' => 'x' ), 'us-east-1', 'bedrock' );
	}
}

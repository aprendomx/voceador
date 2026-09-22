<?php
/**
 * @package Voceador
 */

use Voceador\Crypto;

class CryptoTest extends WP_UnitTestCase {

	private function key( string $seed ): string {
		return str_pad( $seed, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'x' );
	}

	public function test_is_available(): void {
		$this->assertTrue( Crypto::is_available() );
	}

	public function test_roundtrip_with_explicit_key(): void {
		$crypto = new Crypto( $this->key( 'a' ) );
		$stored = $crypto->encrypt( 'EAAtoken-secreto' );

		$this->assertStringStartsWith( 'v1:', $stored );
		$this->assertStringNotContainsString( 'EAAtoken', $stored );
		$this->assertSame( 'EAAtoken-secreto', $crypto->decrypt( $stored ) );
	}

	public function test_each_encryption_uses_a_fresh_nonce(): void {
		$crypto = new Crypto( $this->key( 'a' ) );
		$this->assertNotSame( $crypto->encrypt( 'x' ), $crypto->encrypt( 'x' ) );
	}

	public function test_wrong_key_returns_error(): void {
		$stored = ( new Crypto( $this->key( 'a' ) ) )->encrypt( 'secreto' );
		$result = ( new Crypto( $this->key( 'b' ) ) )->decrypt( $stored );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'voceador_crypto_key', $result->get_error_code() );
	}

	public function test_malformed_input_returns_error(): void {
		$crypto = new Crypto( $this->key( 'a' ) );

		$malformed = array(
			'',
			'sin-prefijo',
			'v1:',
			'v1:###',
			'v1:' . base64_encode( 'corto' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Codificación binaria de un valor de prueba, no ofuscación.
			'v2:' . base64_encode( str_repeat( 'x', 64 ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Codificación binaria de un valor de prueba, no ofuscación.
		);

		foreach ( $malformed as $bad ) {
			$result = $crypto->decrypt( $bad );
			$this->assertInstanceOf( WP_Error::class, $result, $bad );
			$this->assertSame( 'voceador_crypto_format', $result->get_error_code(), $bad );
		}
	}

	public function test_derived_key_is_deterministic_and_32_bytes(): void {
		$this->assertSame( Crypto::derive_key(), Crypto::derive_key() );
		$this->assertSame( SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen( Crypto::derive_key() ) );
	}

	public function test_seed_fallback_is_persisted_and_stable(): void {
		$first  = Crypto::seed_material();
		$second = Crypto::seed_material();

		$this->assertNotSame( '', $first );
		$this->assertSame( $first, $second );
		$this->assertSame( $first, get_option( VOCEADOR_PREFIX . 'crypto_seed' ) );
	}

	public function tear_down(): void {
		delete_option( VOCEADOR_PREFIX . 'crypto_seed' );

		parent::tear_down();
	}

	public function test_default_constructor_uses_derived_key(): void {
		$stored = ( new Crypto() )->encrypt( 'hola' );
		$this->assertSame( 'hola', ( new Crypto( Crypto::derive_key() ) )->decrypt( $stored ) );
	}

	public function test_empty_string_roundtrips(): void {
		$crypto = new Crypto( $this->key( 'a' ) );
		$this->assertSame( '', $crypto->decrypt( $crypto->encrypt( '' ) ) );
	}
}

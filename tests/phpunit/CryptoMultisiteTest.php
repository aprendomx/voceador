<?php
/**
 * @package Voceador
 */

use Voceador\Crypto;

/**
 * @package Voceador
 * @group ms-required
 */
class CryptoMultisiteTest extends WP_UnitTestCase {

	/**
	 * Id del blog creado por el test, para limpiarlo en tear_down.
	 *
	 * @var int
	 */
	private int $blog_id = 0;

	public function test_key_is_per_site_unless_auth_salts_are_configured(): void {
		$this->blog_id = self::factory()->blog->create();

		Crypto::forget_derived_keys();
		$crypto   = new Crypto();
		$stored   = $crypto->encrypt( 'a' );
		$key_main = Crypto::derive_key();

		switch_to_blog( $this->blog_id );
		Crypto::forget_derived_keys();
		$key_other = Crypto::derive_key();

		if ( $key_main === $key_other ) {
			restore_current_blog();
			$this->markTestSkipped( 'Las salts están configuradas; la clave es de red.' );
		}

		$result = $crypto->decrypt( $stored );
		$this->assertInstanceOf( WP_Error::class, $result );

		restore_current_blog();
		$this->assertSame( 'a', $crypto->decrypt( $stored ) );
	}

	public function tear_down(): void {
		delete_option( VOCEADOR_PREFIX . 'crypto_seed' );

		if ( $this->blog_id ) {
			switch_to_blog( $this->blog_id );
			delete_option( VOCEADOR_PREFIX . 'crypto_seed' );
			restore_current_blog();
		}

		Crypto::forget_derived_keys();

		parent::tear_down();
	}
}

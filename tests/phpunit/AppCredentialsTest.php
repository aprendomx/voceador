<?php
/**
 * @package Voceador
 */

use Voceador\AppCredentials;
use Voceador\Crypto;

class AppCredentialsTest extends WP_UnitTestCase {

	private AppCredentials $app;

	public function set_up(): void {
		parent::set_up();
		delete_option( VOCEADOR_PREFIX . AppCredentials::OPTION );
		$this->app = new AppCredentials( new Crypto( str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ) );
	}

	public function test_empty_by_default(): void {
		$this->assertSame( '', $this->app->app_id() );
		$this->assertSame( '', $this->app->app_secret() );
		$this->assertSame( '', $this->app->app_token() );
		$this->assertFalse( $this->app->has_secret() );
		$this->assertFalse( $this->app->is_configured() );
		$this->assertFalse( $this->app->business_management() );
	}

	public function test_save_and_read(): void {
		$this->app->save( '1234567890', 'secreto-de-la-app' );

		$this->assertSame( '1234567890', $this->app->app_id() );
		$this->assertSame( 'secreto-de-la-app', $this->app->app_secret() );
		$this->assertTrue( $this->app->has_secret() );
		$this->assertTrue( $this->app->is_configured() );
		$this->assertSame( '1234567890|secreto-de-la-app', $this->app->app_token() );
	}

	public function test_secret_is_stored_encrypted_and_not_autoloaded(): void {
		global $wpdb;
		$this->app->save( '1234567890', 'secreto-de-la-app' );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", VOCEADOR_PREFIX . AppCredentials::OPTION ), ARRAY_A );

		$this->assertStringNotContainsString( 'secreto-de-la-app', $row['option_value'] );
		$this->assertStringContainsString( 'v1:', $row['option_value'] );
		$this->assertContains( $row['autoload'], array( 'no', 'off' ) );
	}

	public function test_saving_without_secret_keeps_the_previous_one(): void {
		$this->app->save( '1234567890', 'secreto-de-la-app' );

		$this->app->save( '9999999999' );
		$this->assertSame( '9999999999', $this->app->app_id() );
		$this->assertSame( 'secreto-de-la-app', $this->app->app_secret() );

		$this->app->save( '9999999999', '' );
		$this->assertSame( 'secreto-de-la-app', $this->app->app_secret(), 'Una cadena vacía tampoco borra el secreto.' );
	}

	public function test_undecryptable_secret_reads_as_empty(): void {
		global $wpdb;
		$this->app->save( '1234567890', 'secreto-de-la-app' );

		$other = new AppCredentials( new Crypto( str_repeat( 'z', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ) );

		$this->assertSame( '', $other->app_secret() );
		$this->assertTrue( $other->has_secret(), 'El secreto existe aunque no se pueda descifrar.' );
		$this->assertFalse( $other->is_configured() );
		$this->assertSame( '', $other->app_token() );
	}

	public function test_business_management_flag(): void {
		$this->app->save( '1234567890', 'secreto' );
		$this->app->set_business_management( true );

		$this->assertTrue( $this->app->business_management() );
		$this->assertSame( '1234567890', $this->app->app_id(), 'No pisa las credenciales.' );
	}

	public function test_forget(): void {
		$this->app->save( '1234567890', 'secreto' );
		$this->app->forget();

		$this->assertFalse( get_option( VOCEADOR_PREFIX . AppCredentials::OPTION ) );
		$this->assertFalse( $this->app->is_configured() );
	}
}

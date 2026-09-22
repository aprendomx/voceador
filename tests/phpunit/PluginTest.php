<?php
/**
 * @package Voceador
 */

use Voceador\Installer;
use Voceador\Plugin;
use Voceador\Schema;

class PluginTest extends WP_UnitTestCase {

	public function tear_down(): void {
		Plugin::reset();
		Plugin::boot();
		parent::tear_down();
	}

	public function test_boot_returns_the_same_instance(): void {
		$this->assertSame( Plugin::boot(), Plugin::boot() );
	}

	public function test_reset_discards_the_instance(): void {
		$first = Plugin::boot();
		Plugin::reset();
		$this->assertNotSame( $first, Plugin::boot() );
	}

	public function test_get_returns_a_shared_lazy_instance(): void {
		$plugin = Plugin::boot();

		$this->assertTrue( $plugin->has( Schema::class ) );
		$this->assertInstanceOf( Schema::class, $plugin->get( Schema::class ) );
		$this->assertSame( $plugin->get( Schema::class ), $plugin->get( Schema::class ) );
		$this->assertSame( $plugin->get( Schema::class ), $plugin->schema() );
		$this->assertInstanceOf( Installer::class, $plugin->installer() );
	}

	public function test_get_rejects_unknown_services(): void {
		$this->assertFalse( Plugin::boot()->has( 'Voceador\\NoExiste' ) );
		$this->expectException( InvalidArgumentException::class );
		Plugin::boot()->get( 'Voceador\\NoExiste' );
	}

	public function test_registrables_register_their_hooks_on_boot(): void {
		Plugin::reset();
		$installer = Plugin::boot()->installer();

		$this->assertSame( 0, has_action( 'init', array( $installer, 'maybe_install' ) ) );
		$this->assertSame( 20, has_action( 'wp_initialize_site', array( $installer, 'on_new_site' ) ) );
	}

	public function test_site_was_installed_during_bootstrap(): void {
		$this->assertSame( Schema::DB_VERSION, get_option( VOCEADOR_PREFIX . 'db_version' ) );
	}
}

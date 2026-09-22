<?php
/**
 * @package Voceador
 */

use Voceador\Installer;
use Voceador\Plugin;
use Voceador\Schema;

class PluginTest extends WP_UnitTestCase {

	public function test_boot_returns_the_same_instance(): void {
		$this->assertSame( Plugin::boot(), Plugin::boot() );
	}

	public function test_exposes_services(): void {
		$this->assertInstanceOf( Schema::class, Plugin::boot()->schema() );
		$this->assertInstanceOf( Installer::class, Plugin::boot()->installer() );
	}

	public function test_registers_lazy_install_on_init(): void {
		$this->assertSame(
			0,
			has_action( 'init', array( Plugin::boot()->installer(), 'maybe_install' ) )
		);
	}

	public function test_site_was_installed_during_bootstrap(): void {
		$this->assertSame( Schema::DB_VERSION, get_option( VOCEADOR_PREFIX . 'db_version' ) );
	}
}

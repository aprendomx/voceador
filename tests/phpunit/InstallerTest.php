<?php
/**
 * @package Voceador
 */

use Voceador\Installer;
use Voceador\Schema;

class InstallerTest extends WP_UnitTestCase {

	private Installer $installer;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->installer = new Installer( new Schema( $wpdb ) );
	}

	public function tear_down(): void {
		// La BD se revierte sola, pero WP_Roles conserva los cambios en memoria.
		get_role( 'administrator' )->add_cap( Installer::CAPABILITY );
		parent::tear_down();
	}

	public function test_maybe_install_stores_version_and_grants_capability(): void {
		delete_option( VOCEADOR_PREFIX . 'db_version' );
		get_role( 'administrator' )->remove_cap( Installer::CAPABILITY );

		$this->installer->maybe_install();

		$this->assertSame( Schema::DB_VERSION, get_option( VOCEADOR_PREFIX . 'db_version' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Installer::CAPABILITY ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( Installer::CAPABILITY ) );
	}

	public function test_maybe_install_does_nothing_when_version_is_current(): void {
		update_option( VOCEADOR_PREFIX . 'db_version', Schema::DB_VERSION );
		get_role( 'administrator' )->remove_cap( Installer::CAPABILITY );

		$this->installer->maybe_install();

		$this->assertFalse( get_role( 'administrator' )->has_cap( Installer::CAPABILITY ) );
	}

	public function test_maybe_install_runs_again_when_version_changes(): void {
		update_option( VOCEADOR_PREFIX . 'db_version', '0' );
		get_role( 'administrator' )->remove_cap( Installer::CAPABILITY );

		$this->installer->maybe_install();

		$this->assertSame( Schema::DB_VERSION, get_option( VOCEADOR_PREFIX . 'db_version' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Installer::CAPABILITY ) );
	}

	public function test_activate_installs_current_site(): void {
		delete_option( VOCEADOR_PREFIX . 'db_version' );

		$this->installer->activate( false );

		$this->assertSame( Schema::DB_VERSION, get_option( VOCEADOR_PREFIX . 'db_version' ) );
	}
}

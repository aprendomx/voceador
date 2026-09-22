<?php
/**
 * @package Voceador
 */

use Voceador\Installer;
use Voceador\Schema;
use Voceador\Uninstaller;

/**
 * @package Voceador
 * @group ms-required
 */
class UninstallerMultisiteTest extends WP_UnitTestCase {

	public function test_run_cleans_every_site_and_network_defaults(): void {
		$blog_id = self::factory()->blog->create();

		update_option( VOCEADOR_PREFIX . 'wizard', array( 'step' => 2 ), false );
		switch_to_blog( $blog_id );
		update_option( VOCEADOR_PREFIX . 'wizard', array( 'step' => 5 ), false );
		restore_current_blog();
		update_site_option( VOCEADOR_PREFIX . 'network_defaults', array( 'rules' => array() ) );

		Uninstaller::run();

		$this->assertFalse( get_option( VOCEADOR_PREFIX . 'wizard' ) );
		switch_to_blog( $blog_id );
		$other = get_option( VOCEADOR_PREFIX . 'wizard' );
		restore_current_blog();
		$this->assertFalse( $other );
		$this->assertFalse( get_site_option( VOCEADOR_PREFIX . 'network_defaults' ) );
	}

	public function test_run_drops_tables_on_every_site(): void {
		// El DDL (DROP TABLE) hace un commit implícito en MySQL/MariaDB y rompe el aislamiento
		// transaccional de WP_UnitTestCase; nada debe escribirse antes de run() aquí.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		global $wpdb;
		$schema  = new Schema( $wpdb );
		$blog_id = self::factory()->blog->create();

		switch_to_blog( $blog_id );
		( new Installer( new Schema( $wpdb ) ) )->maybe_install();
		restore_current_blog();

		Uninstaller::run();

		$suppress     = $wpdb->suppress_errors( true );
		$main_columns = $wpdb->get_col( 'DESCRIBE ' . $schema->table( 'jobs' ), 0 );

		switch_to_blog( $blog_id );
		$other_columns = $wpdb->get_col( 'DESCRIBE ' . $schema->table( 'jobs' ), 0 );
		restore_current_blog();
		$wpdb->suppress_errors( $suppress );

		$this->assertEmpty( $main_columns, 'La tabla jobs sigue existiendo en el sitio principal.' );
		$this->assertEmpty( $other_columns, 'La tabla jobs sigue existiendo en el sitio nuevo.' );
	}

	public function tear_down(): void {
		global $wpdb;

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		delete_option( VOCEADOR_PREFIX . 'db_version' );
		( new Installer( new Schema( $wpdb ) ) )->maybe_install();

		// La BD se revierte sola, pero WP_Roles conserva los cambios en memoria.
		get_role( 'administrator' )->add_cap( Installer::CAPABILITY );

		parent::tear_down();
	}
}

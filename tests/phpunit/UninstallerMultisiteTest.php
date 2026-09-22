<?php
/**
 * @package Voceador
 */

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

	public function tear_down(): void {
		// La BD se revierte sola, pero WP_Roles conserva los cambios en memoria.
		get_role( 'administrator' )->add_cap( \Voceador\Installer::CAPABILITY );
		parent::tear_down();
	}
}

<?php
/**
 * @package Voceador
 */

use Voceador\Installer;
use Voceador\Schema;

/**
 * @package Voceador
 * @group ms-required
 */
class InstallerMultisiteTest extends WP_UnitTestCase {

	public function test_new_site_is_installed_when_network_active(): void {
		update_site_option(
			'active_sitewide_plugins',
			array( plugin_basename( VOCEADOR_FILE ) => time() )
		);

		$blog_id = self::factory()->blog->create();

		switch_to_blog( $blog_id );
		$version = get_option( VOCEADOR_PREFIX . 'db_version' );
		$has_cap = get_role( 'administrator' )->has_cap( Installer::CAPABILITY );
		restore_current_blog();

		$this->assertSame( Schema::DB_VERSION, $version );
		$this->assertTrue( $has_cap );
	}

	public function test_new_site_is_not_installed_when_not_network_active(): void {
		update_site_option( 'active_sitewide_plugins', array() );

		$blog_id = self::factory()->blog->create();

		switch_to_blog( $blog_id );
		$version = get_option( VOCEADOR_PREFIX . 'db_version' );
		restore_current_blog();

		$this->assertFalse( $version );
	}

	public function test_tables_use_each_site_prefix(): void {
		global $wpdb;
		$schema  = new Schema( $wpdb );
		$blog_id = self::factory()->blog->create();

		$main = $schema->table( 'jobs' );
		switch_to_blog( $blog_id );
		$other = $schema->table( 'jobs' );
		restore_current_blog();

		$this->assertNotSame( $main, $other );
		$this->assertStringContainsString( '_' . $blog_id . '_voceador_jobs', $other );
	}
}

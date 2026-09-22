<?php
/**
 * @package Voceador
 */

use Voceador\Settings;

/**
 * @package Voceador
 * @group ms-required
 */
class SettingsMultisiteTest extends WP_UnitTestCase {

	public function test_network_defaults_sit_between_site_and_code(): void {
		$settings = new Settings();
		delete_option( VOCEADOR_PREFIX . Settings::OPTION );
		update_site_option( VOCEADOR_PREFIX . 'network_defaults', array( 'execution' => array( 'delay' => 10 ) ) );

		$this->assertSame( 10, $settings->get( 'execution.delay' ) );

		$settings->update( array( 'execution' => array( 'delay' => 3 ) ) );
		$this->assertSame( 3, $settings->get( 'execution.delay' ) );

		delete_site_option( VOCEADOR_PREFIX . 'network_defaults' );
	}
}

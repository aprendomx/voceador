<?php
/**
 * @package Voceador
 */

use Voceador\Settings;

class SettingsTest extends WP_UnitTestCase {

	private Settings $settings;

	public function set_up(): void {
		parent::set_up();
		$this->settings = new Settings();
		delete_option( VOCEADOR_PREFIX . Settings::OPTION );
	}

	public function test_defaults_when_nothing_is_stored(): void {
		$this->assertSame( 'v26.0', $this->settings->get( 'graph_version' ) );
		$this->assertSame( 30, $this->settings->get( 'execution.delay' ) );
		$this->assertSame( 60, $this->settings->get( 'execution.comment_delay' ) );
		$this->assertSame( array( 'post' ), $this->settings->get( 'rules.post_types' ) );
		$this->assertSame( 'file', $this->settings->get( 'image.fb_mode' ) );
		$this->assertSame( 'skip', $this->settings->get( 'image.no_image' ) );
		$this->assertFalse( $this->settings->get( 'uninstall.delete_meta' ) );
	}

	public function test_unknown_path_is_null(): void {
		$this->assertNull( $this->settings->get( 'no.existe' ) );
		$this->assertNull( $this->settings->get( 'execution.no_existe' ) );
	}

	public function test_site_option_overrides_defaults(): void {
		$this->settings->update( array( 'execution' => array( 'delay' => 5 ) ) );

		$this->assertSame( 5, $this->settings->get( 'execution.delay' ) );
		$this->assertSame( 60, $this->settings->get( 'execution.comment_delay' ), 'Las claves no tocadas conservan el default.' );
	}

	public function test_update_merges_and_does_not_autoload(): void {
		global $wpdb;
		$this->settings->update( array( 'execution' => array( 'delay' => 5 ) ) );
		$this->settings->update( array( 'image' => array( 'fb_mode' => 'url' ) ) );

		$this->assertSame( 5, $this->settings->get( 'execution.delay' ) );
		$this->assertSame( 'url', $this->settings->get( 'image.fb_mode' ) );

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", VOCEADOR_PREFIX . Settings::OPTION ) );
		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	public function test_channel_overrides_win(): void {
		$this->settings->update( array( 'execution' => array( 'delay' => 5 ) ) );

		$this->assertSame( 0, $this->settings->get( 'execution.delay', array( 'execution' => array( 'delay' => 0 ) ) ) );
		$this->assertSame( 5, $this->settings->get( 'execution.delay', array( 'execution' => array( 'delay' => null ) ) ), 'null en el canal significa "heredar".' );
	}

	public function test_all_returns_merged_tree(): void {
		$this->settings->update( array( 'execution' => array( 'delay' => 5 ) ) );
		$all = $this->settings->all();

		$this->assertSame( 5, $all['execution']['delay'] );
		$this->assertSame( 'v26.0', $all['graph_version'] );
	}

	public function test_defaults_filter(): void {
		add_filter(
			'voceador_settings_defaults',
			static function ( array $defaults ): array {
				$defaults['graph_version'] = 'v25.0';
				return $defaults;
			}
		);

		$this->assertSame( 'v25.0', $this->settings->get( 'graph_version' ) );
	}

	public function test_update_replaces_lists_wholesale(): void {
		$this->settings->update( array( 'rules' => array( 'post_types' => array( 'post', 'page' ) ) ) );
		$this->settings->update( array( 'rules' => array( 'post_types' => array( 'post' ) ) ) );

		$this->assertSame( array( 'post' ), $this->settings->get( 'rules.post_types' ) );
	}

	public function test_all_replaces_lists_wholesale(): void {
		$this->settings->update( array( 'rules' => array( 'post_types' => array( 'page' ) ) ) );
		$all = $this->settings->all();

		$this->assertSame( array( 'page' ), $all['rules']['post_types'], 'El default array( "post" ) no debe filtrarse en el índice 0.' );

		$this->settings->update( array( 'rules' => array( 'post_types' => array() ) ) );
		$this->assertSame( array(), $this->settings->get( 'rules.post_types' ) );
	}
}

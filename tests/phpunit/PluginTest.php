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

	public function test_reset_does_not_leak_hooks_between_tests(): void {
		$callbacks = $GLOBALS['wp_filter']['init']->callbacks[0] ?? array();

		$installer_hooks = array_filter(
			$callbacks,
			static function ( $callback ) {
				return is_array( $callback['function'] ) && $callback['function'][0] instanceof Installer;
			}
		);

		$this->assertCount( 1, $installer_hooks );
	}

	public function test_every_service_resolves(): void {
		// reset()+boot() dentro del propio test: has_action() compara identidad de objeto,
		// y el hook base registrado en el arranque global pertenece a una instancia de
		// Plugin distinta (ver Plugin::reset()).
		Plugin::reset();
		$plugin = Plugin::boot();

		foreach ( array(
			\Voceador\Crypto::class,
			\Voceador\Settings::class,
			\Voceador\Channels\ChannelRegistry::class,
			\Voceador\ChannelRepository::class,
			\Voceador\JobRepository::class,
			\Voceador\Logger::class,
			\Voceador\GraphClient::class,
			\Voceador\Channels\FacebookPageAdapter::class,
			\Voceador\Templates::class,
			\Voceador\Queue::class,
			\Voceador\Publisher::class,
			\Voceador\Rules::class,
			\Voceador\Trigger::class,
			\Voceador\CLI::class,
		) as $id ) {
			$this->assertInstanceOf( $id, $plugin->get( $id ) );
		}

		$this->assertTrue( $plugin->get( \Voceador\Channels\ChannelRegistry::class )->has( 'facebook_page' ) );
		$this->assertNotFalse( has_action( \Voceador\Queue::HOOK_RUN, array( $plugin->get( \Voceador\Publisher::class ), 'run' ) ) );
		$this->assertNotFalse( has_action( 'transition_post_status', array( $plugin->get( \Voceador\Trigger::class ), 'on_transition' ) ) );
	}
}

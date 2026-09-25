<?php
/**
 * @package Voceador
 */

use Voceador\Notices;
use Voceador\Publisher;

class NoticesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( VOCEADOR_PREFIX . Notices::OPTION );
	}

	public function test_add_remove_and_all(): void {
		$this->assertSame( array(), Notices::all() );

		Notices::add( 'uno', 'Primero' );
		Notices::add( 'dos', 'Segundo' );

		$all = Notices::all();
		$this->assertSame( array( 'dos', 'uno' ), array_keys( $all ), 'El más reciente primero.' );
		$this->assertSame( 'Primero', $all['uno']['message'] );
		$this->assertIsInt( $all['uno']['time'] );

		Notices::remove( 'uno' );
		$this->assertSame( array( 'dos' ), array_keys( Notices::all() ) );

		Notices::clear();
		$this->assertSame( array(), Notices::all() );
	}

	public function test_option_is_not_autoloaded(): void {
		global $wpdb;
		Notices::add( 'uno', 'Primero' );

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", VOCEADOR_PREFIX . Notices::OPTION ) );

		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	public function test_key_for_channel(): void {
		$this->assertSame( 'channel_paused_7', Notices::key_for_channel( 7 ) );
	}

	public function test_publisher_add_notice_delegates(): void {
		Publisher::add_notice( 'desde-publisher', 'Mensaje' );

		$this->assertSame( 'Mensaje', Notices::all()['desde-publisher']['message'] );
	}
}

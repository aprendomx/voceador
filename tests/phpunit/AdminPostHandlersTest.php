<?php
/**
 * Cobertura de los handlers de admin-post que hoy no tenía ningún test (I6): el
 * filtrado de la selección de Páginas y el corte por capacidad/nonce de los
 * handlers que, al terminar en exit, no se pueden invocar en proceso más que a
 * través de wp_die() (el harness de WP lo convierte en WPDieException).
 *
 * @package Voceador
 */

use Voceador\Admin\ConnectionsPage;
use Voceador\AppCredentials;
use Voceador\ChannelRepository;
use Voceador\Channels\ChannelRegistry;
use Voceador\Channels\FacebookPageAdapter;
use Voceador\Crypto;
use Voceador\GraphClient;
use Voceador\Logger;
use Voceador\OAuth\Facebook;
use Voceador\Schema;
use Voceador\Settings;
use Voceador\TokenManager;

class AdminPostHandlersTest extends WP_UnitTestCase {

	private ConnectionsPage $page;
	private Facebook $oauth;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		$crypto      = new Crypto( str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );
		$schema      = new Schema( $wpdb );
		$logger      = new Logger( $wpdb, $schema, 'debug' );
		$settings    = new Settings();
		$graph       = new GraphClient( $settings, $logger );
		$app         = new AppCredentials( $crypto );
		$channels    = new ChannelRepository( $wpdb, $schema, $crypto );
		$this->oauth = new Facebook( $app, $graph, $channels, $crypto, $settings, $logger );
		$registry    = new ChannelRegistry( array( 'facebook_page' => static fn() => new FacebookPageAdapter( $graph, $settings ) ) );
		$this->page  = new ConnectionsPage( $app, $this->oauth, $channels, new TokenManager( $app, $graph, $channels, $logger ), $registry );
	}

	public function tear_down(): void {
		unset( $_GET['_wpnonce'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
		parent::tear_down();
	}

	// -- selection_from(): filtrado puro, sin capacidad ni nonce de por medio. --

	public function test_selection_from_only_uses_pages_marked_in_connect(): void {
		$post = array(
			'connect' => array( '1001', '1003' ),
			'pages'   => array(
				'1001' => '  Uno  ',
				'1002' => 'Sin marcar',
				'1003' => array( 'no', 'escalar' ),
			),
		);

		$selection = $this->oauth->selection_from( $post );

		$this->assertSame(
			array( '1001' => 'Uno' ),
			$selection,
			'1002 se ignora por no estar marcada y 1003 por no ser un valor escalar, aunque esté marcada.'
		);
	}

	public function test_selection_from_returns_empty_array_without_connect(): void {
		$this->assertSame(
			array(),
			$this->oauth->selection_from( array( 'pages' => array( '1001' => 'Uno' ) ) ),
			'Sin connect[] no hay nada marcado, así que no se conecta nada.'
		);
	}

	// -- Corte por capacidad: un suscriptor no puede ejecutar ningún handler. --

	private function as_subscriber(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
	}

	public function test_handle_save_app_dies_without_the_capability(): void {
		$this->as_subscriber();

		$this->expectException( WPDieException::class );
		$this->page->handle_save_app();
	}

	public function test_handle_channel_action_dies_without_the_capability(): void {
		$this->as_subscriber();

		$this->expectException( WPDieException::class );
		$this->page->handle_channel_action();
	}

	public function test_facebook_handle_start_dies_without_the_capability(): void {
		$this->as_subscriber();

		$this->expectException( WPDieException::class );
		$this->oauth->handle_start();
	}

	// -- Corte por nonce: con capacidad pero sin nonce, también muere. --

	public function test_facebook_handle_start_dies_without_a_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		unset( $_GET['_wpnonce'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->expectException( WPDieException::class );
		$this->oauth->handle_start();
	}
}

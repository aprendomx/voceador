<?php
/**
 * @package Voceador
 */

use Voceador\Admin\ConnectionsPage;
use Voceador\AppCredentials;
use Voceador\ChannelRepository;
use Voceador\Channels\ChannelRegistry;
use Voceador\Channels\FacebookPageAdapter;
use Voceador\Crypto;
use Voceador\GraphClient;
use Voceador\Installer;
use Voceador\Logger;
use Voceador\Notices;
use Voceador\OAuth\Facebook;
use Voceador\Schema;
use Voceador\Settings;
use Voceador\TokenManager;

class ConnectionsPageTest extends WP_UnitTestCase {

	private ConnectionsPage $page;
	private AppCredentials $app;
	private ChannelRepository $channels;
	private Facebook $oauth;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		delete_option( VOCEADOR_PREFIX . AppCredentials::OPTION );
		delete_option( VOCEADOR_PREFIX . Notices::OPTION );

		$crypto         = new Crypto( str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );
		$schema         = new Schema( $wpdb );
		$logger         = new Logger( $wpdb, $schema, 'debug' );
		$settings       = new Settings();
		$graph          = new GraphClient( $settings, $logger );
		$this->app      = new AppCredentials( $crypto );
		$this->channels = new ChannelRepository( $wpdb, $schema, $crypto );
		$this->oauth    = new Facebook( $this->app, $graph, $this->channels, $crypto, $settings, $logger );
		$registry       = new ChannelRegistry( array( 'facebook_page' => static fn() => new FacebookPageAdapter( $graph, $settings ) ) );
		$this->page     = new ConnectionsPage( $this->app, $this->oauth, $this->channels, new TokenManager( $this->app, $graph, $this->channels, $logger ), $registry );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		get_role( 'administrator' )->add_cap( Installer::CAPABILITY );
	}

	private function render(): string {
		ob_start();
		$this->page->render();

		return (string) ob_get_clean();
	}

	public function test_menu_is_registered_with_the_plugin_capability(): void {
		global $menu, $submenu;
		$menu    = array();
		$submenu = array();

		$this->page->register_hooks();
		do_action( 'admin_menu' );

		// $menu queda indexado por posición (76 aquí, no 0..n), así que no se puede
		// combinar array_column() con array_search() sobre el resultado reindexado.
		$entry = null;
		foreach ( $menu as $item ) {
			if ( ConnectionsPage::SLUG === $item[2] ) {
				$entry = $item;
				break;
			}
		}

		$this->assertNotNull( $entry, 'El menú de Voceador está registrado.' );
		$this->assertSame( Installer::CAPABILITY, $entry[1] );
	}

	public function test_render_asks_for_the_app_before_offering_to_connect(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'App ID', $html );
		$this->assertStringContainsString( $this->oauth->redirect_uri(), $html, 'Muestra la URI que hay que registrar en Meta.' );
		$this->assertStringNotContainsString( Facebook::ACTION_START, $html, 'Sin app configurada no se ofrece conectar.' );

		$this->app->save( '111222333', 'secreto' );
		$html = $this->render();
		$this->assertStringContainsString( Facebook::ACTION_START, $html );
	}

	public function test_channel_row_offers_reconnect_and_shows_expiry(): void {
		$this->app->save( '111222333', 'secreto' );
		$id = $this->channels->insert(
			array(
				'type'        => 'facebook_page',
				'alias'       => 'Prueba',
				'remote_id'   => '1001',
				'credentials' => array( 'access_token' => 'T' ),
				'health'      => array(
					'message'        => 'Token válido.',
					'expires_at'     => '2026-12-31 00:00:00',
					'scopes'         => array( 'pages_show_list', 'pages_manage_posts' ),
					'missing_scopes' => array( 'pages_read_engagement' ),
				),
			)
		);

		$html = $this->render();

		$this->assertStringContainsString( 'Reconectar', $html );
		$this->assertStringContainsString( '2026-12-31 00:00:00', $html );
		$this->assertStringContainsString( (string) $id, $html );
		$this->assertStringContainsString( 'Página de Facebook', $html, 'Muestra la etiqueta del tipo de canal.' );
		$this->assertStringContainsString( 'Permisos: pages_show_list, pages_manage_posts', $html );
		$this->assertStringContainsString( 'Faltan: pages_read_engagement', $html );
	}

	public function test_channel_row_with_no_health_shows_neither_permissions_line(): void {
		$this->app->save( '111222333', 'secreto' );
		$this->channels->insert(
			array(
				'type'        => 'facebook_page',
				'alias'       => 'Recién conectada',
				'remote_id'   => '2002',
				'credentials' => array( 'access_token' => 'T' ),
			)
		);

		$html = $this->render();

		$this->assertStringContainsString( '—', $html, 'Sin revisión aún, se imprime el guion.' );
		$this->assertStringNotContainsString( 'Permisos:', $html );
		$this->assertStringNotContainsString( 'Faltan:', $html );
	}

	public function test_channel_action_nonces_are_unambiguous(): void {
		$this->assertNotSame(
			wp_create_nonce( ConnectionsPage::ACTION_CHANNEL . '_check_1|23' ),
			wp_create_nonce( ConnectionsPage::ACTION_CHANNEL . '_check_12|3' )
		);
	}

	public function test_redirect_uri_field_has_a_copy_button(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'navigator.clipboard.writeText', $html );
	}

	public function test_render_never_prints_the_secret_or_a_token(): void {
		$this->app->save( '111222333', 'secreto-de-la-app' );
		$this->channels->insert(
			array(
				'type'        => 'facebook_page',
				'alias'       => 'Prueba',
				'remote_id'   => '1001',
				'remote_name' => 'Página',
				'credentials' => array( 'access_token' => 'EAAtoken-secretisimo' ),
			)
		);

		$html = $this->render();

		$this->assertStringNotContainsString( 'secreto-de-la-app', $html );
		$this->assertStringNotContainsString( 'EAAtoken-secretisimo', $html );
		$this->assertStringContainsString( 'Prueba', $html );
		$this->assertStringContainsString( '1001', $html );
	}

	public function test_render_lists_candidate_pages_after_the_callback(): void {
		$this->app->save( '111222333', 'secreto' );
		$this->oauth->store_candidates(
			array(
				array(
					'id'           => '1001',
					'name'         => 'Una <b>rara</b>',
					'access_token' => 'T1',
				),
			)
		);

		$html = $this->render();

		$this->assertStringContainsString( 'name="pages[1001]"', $html );
		$this->assertStringContainsString( 'Una &lt;b&gt;rara&lt;/b&gt;', $html, 'El nombre se escapa.' );
		$this->assertStringNotContainsString( 'T1', $html );
	}

	public function test_notices_are_rendered_escaped_and_can_be_dismissed(): void {
		Notices::add( 'roto', 'Canal "X" <script>alert(1)</script>' );

		ob_start();
		$this->page->render_notices();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( ConnectionsPage::ACTION_CHANNEL, $html );
	}

	public function test_notices_are_hidden_from_users_without_the_capability(): void {
		Notices::add( 'roto', 'Canal pausado' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		ob_start();
		$this->page->render_notices();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_save_app_keeps_the_secret_when_the_field_is_empty(): void {
		$this->app->save( '111222333', 'secreto' );

		$this->page->save_app_from(
			array(
				'app_id'              => '999',
				'app_secret'          => '',
				'business_management' => '1',
			)
		);

		$this->assertSame( '999', $this->app->app_id() );
		$this->assertSame( 'secreto', $this->app->app_secret() );
		$this->assertTrue( $this->app->business_management() );

		$this->page->save_app_from(
			array(
				'app_id'     => '999',
				'app_secret' => 'nuevo',
			)
		);
		$this->assertSame( 'nuevo', $this->app->app_secret() );
		$this->assertFalse( $this->app->business_management(), 'La casilla desmarcada se guarda como falsa.' );
	}

	public function test_channel_action_disconnect_removes_channel_and_notice(): void {
		$id = $this->channels->insert(
			array(
				'type'        => 'facebook_page',
				'alias'       => 'Prueba',
				'remote_id'   => '1001',
				'credentials' => array( 'access_token' => 'T' ),
			)
		);
		Notices::add( Notices::key_for_channel( $id ), 'Pausado' );

		$this->assertSame( 'disconnected', $this->page->run_channel_action( 'disconnect', $id ) );
		$this->assertNull( $this->channels->find( $id ) );
		$this->assertArrayNotHasKey( Notices::key_for_channel( $id ), Notices::all() );

		$this->assertSame( 'missing', $this->page->run_channel_action( 'disconnect', 999999 ) );
	}
}

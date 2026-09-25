<?php
/**
 * @package Voceador
 */

use Voceador\AppCredentials;
use Voceador\ChannelRepository;
use Voceador\Crypto;
use Voceador\GraphClient;
use Voceador\Logger;
use Voceador\OAuth\Facebook;
use Voceador\Schema;
use Voceador\Settings;
use Voceador\Tests\Fixtures\GraphResponses;

class OAuthFacebookTest extends WP_UnitTestCase {

	private Facebook $oauth;
	private AppCredentials $app;
	private ChannelRepository $channels;
	private array $requests  = array();
	private array $responses = array();

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		delete_option( VOCEADOR_PREFIX . Settings::OPTION );
		delete_option( VOCEADOR_PREFIX . AppCredentials::OPTION );

		$crypto         = new Crypto( str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );
		$schema         = new Schema( $wpdb );
		$logger         = new Logger( $wpdb, $schema, 'debug' );
		$settings       = new Settings();
		$this->app      = new AppCredentials( $crypto );
		$this->channels = new ChannelRepository( $wpdb, $schema, $crypto );
		$this->oauth    = new Facebook( $this->app, new GraphClient( $settings, $logger ), $this->channels, $crypto, $settings, $logger );

		$this->app->save( '111222333', 'secreto-app' );
		$this->requests  = array();
		$this->responses = array();
		add_filter( 'pre_http_request', array( $this, 'capture' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'capture' ), 10 );
		parent::tear_down();
	}

	public function capture( $pre, array $args, string $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);

		return array_shift( $this->responses ) ?? GraphResponses::ok( array() );
	}

	public function test_redirect_uri_and_authorize_url(): void {
		$this->assertSame( admin_url( 'admin-post.php' ) . '?action=voceador_oauth_fb', $this->oauth->redirect_uri() );

		$url = $this->oauth->authorize_url( 'elestado' );

		$this->assertStringStartsWith( 'https://www.facebook.com/v26.0/dialog/oauth?', $url );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( '111222333', $query['client_id'] );
		$this->assertSame( $this->oauth->redirect_uri(), $query['redirect_uri'] );
		$this->assertSame( 'elestado', $query['state'] );
		$this->assertSame( 'code', $query['response_type'] );
		$this->assertSame( 'pages_show_list,pages_read_engagement,pages_manage_posts,pages_manage_engagement', $query['scope'] );
	}

	public function test_scopes_add_business_management_and_filter(): void {
		$this->app->set_business_management( true );
		$this->assertContains( 'business_management', $this->oauth->scopes() );

		add_filter( 'voceador_oauth_scopes', static fn( array $s ) => array_merge( $s, array( 'instagram_basic' ) ) );
		$this->assertContains( 'instagram_basic', $this->oauth->scopes() );
	}

	public function test_state_is_single_use_and_bound_to_the_user(): void {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );

		$state = $this->oauth->create_state();
		$this->assertNotEmpty( $state );

		$this->assertTrue( $this->oauth->consume_state( $state ) );
		$this->assertFalse( $this->oauth->consume_state( $state ), 'Solo se puede usar una vez.' );
		$this->assertFalse( $this->oauth->consume_state( 'inventado' ) );

		$state = $this->oauth->create_state();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertFalse( $this->oauth->consume_state( $state ), 'Otro usuario no puede consumirlo.' );
	}

	public function test_exchange_code_and_long_lived(): void {
		$this->responses[] = GraphResponses::ok(
			array(
				'access_token' => 'CORTO',
				'token_type'   => 'bearer',
				'expires_in'   => 3600,
			)
		);

		$this->assertSame( 'CORTO', $this->oauth->exchange_code( 'el-codigo' ) );
		parse_str( (string) wp_parse_url( $this->requests[0]['url'], PHP_URL_QUERY ), $query );
		$this->assertStringContainsString( '/v26.0/oauth/access_token', $this->requests[0]['url'] );
		$this->assertSame( '111222333', $query['client_id'] );
		$this->assertSame( 'secreto-app', $query['client_secret'] );
		$this->assertSame( 'el-codigo', $query['code'] );
		$this->assertSame( $this->oauth->redirect_uri(), $query['redirect_uri'] );
		$this->assertArrayNotHasKey( 'Authorization', $this->requests[0]['args']['headers'] );

		$this->responses[] = GraphResponses::ok(
			array(
				'access_token' => 'LARGO',
				'expires_in'   => 5184000,
			)
		);
		$this->assertSame( 'LARGO', $this->oauth->long_lived( 'CORTO' ) );
		parse_str( (string) wp_parse_url( $this->requests[1]['url'], PHP_URL_QUERY ), $query );
		$this->assertSame( 'fb_exchange_token', $query['grant_type'] );
		$this->assertSame( 'CORTO', $query['fb_exchange_token'] );
	}

	public function test_exchange_code_propagates_errors(): void {
		$this->responses[] = GraphResponses::error( 400, 100, 'This authorization code has expired.' );

		$result = $this->oauth->exchange_code( 'viejo' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'fatal', $result->get_error_code() );
	}

	public function test_pages_follows_paging(): void {
		$this->responses[] = GraphResponses::ok(
			array(
				'data'   => array(
					array(
						'id'           => '1',
						'name'         => 'Una',
						'access_token' => 'T1',
						'tasks'        => array( 'CREATE_CONTENT' ),
					),
				),
				'paging' => array( 'next' => 'https://graph.facebook.com/v26.0/me/accounts?after=CURSOR' ),
			)
		);
		$this->responses[] = GraphResponses::ok(
			array(
				'data' => array(
					array(
						'id'           => '2',
						'name'         => 'Dos',
						'access_token' => 'T2',
						'tasks'        => array( 'MANAGE' ),
					),
				),
			)
		);

		$pages = $this->oauth->pages( 'LARGO' );

		$this->assertCount( 2, $pages );
		$this->assertSame( array( '1', '2' ), array_column( $pages, 'id' ) );
		$this->assertSame( 'T2', $pages[1]['access_token'] );
		$this->assertSame( 'Bearer LARGO', $this->requests[0]['args']['headers']['Authorization'] );
		$this->assertStringContainsString( 'after=CURSOR', $this->requests[1]['url'] );
	}

	public function test_permissions_returns_only_granted(): void {
		$this->responses[] = GraphResponses::ok(
			array(
				'data' => array(
					array(
						'permission' => 'pages_show_list',
						'status'     => 'granted',
					),
					array(
						'permission' => 'pages_manage_posts',
						'status'     => 'declined',
					),
					array(
						'permission' => 'pages_read_engagement',
						'status'     => 'granted',
					),
				),
			)
		);

		$this->assertSame( array( 'pages_show_list', 'pages_read_engagement' ), $this->oauth->permissions( 'LARGO' ) );
		$this->assertStringContainsString( '/me/permissions', $this->requests[0]['url'] );
		$this->assertSame( 'Bearer LARGO', $this->requests[0]['args']['headers']['Authorization'] );

		$this->responses[] = GraphResponses::error( 400, 190, 'Invalid OAuth access token' );
		$this->assertSame( array(), $this->oauth->permissions( 'LARGO' ), 'Un error de Graph no debe romper la conexión.' );
	}

	public function test_candidates_are_stored_encrypted(): void {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );

		$this->oauth->store_candidates(
			array(
				array(
					'id'           => '1',
					'name'         => 'Una',
					'access_token' => 'SECRETO-DE-PAGINA',
				),
			)
		);

		$raw = get_transient( VOCEADOR_PREFIX . 'oauth_pages_' . $user );
		$this->assertIsString( $raw );
		$this->assertStringNotContainsString( 'SECRETO-DE-PAGINA', $raw );
		$this->assertSame( 'SECRETO-DE-PAGINA', $this->oauth->candidates()[0]['access_token'] );

		$this->oauth->forget_candidates();
		$this->assertSame( array(), $this->oauth->candidates() );
	}

	public function test_connect_inserts_and_updates_channels(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->oauth->store_candidates(
			array(
				array(
					'id'             => '1001',
					'name'           => 'Una',
					'access_token'   => 'T1',
					'tasks'          => array( 'CREATE_CONTENT' ),
					'picture'        => 'https://x/a.jpg',
					'granted_scopes' => array( 'pages_show_list', 'pages_manage_posts' ),
				),
				array(
					'id'           => '1002',
					'name'         => 'Dos',
					'access_token' => 'T2',
					'tasks'        => array( 'CREATE_CONTENT' ),
				),
			)
		);

		$result = $this->oauth->connect( array( '1001' => 'Principal' ) );

		$this->assertSame( 1, $result['connected'] );
		$this->assertSame( 0, $result['updated'] );
		$channel = $this->channels->find_by_remote( 'facebook_page', '1001' );
		$this->assertSame( 'Principal', $channel->alias );
		$this->assertSame( 'Una', $channel->remote_name );
		$this->assertSame( 'oauth', $channel->connection_method );
		$this->assertSame( 'T1', $channel->credential( 'access_token' ) );
		$this->assertSame( 'https://x/a.jpg', $channel->avatar_url );
		$this->assertSame( array( 'pages_show_list', 'pages_manage_posts' ), $channel->scopes );
		$this->assertSame( 'active', $channel->status );
		$this->assertSame( array(), $channel->health, 'No se inventa salud al conectar.' );
		$this->assertNull( $channel->health_checked_at );

		$this->oauth->store_candidates(
			array(
				array(
					'id'           => '1001',
					'name'         => 'Una renombrada',
					'access_token' => 'T1-NUEVO',
				),
			)
		);
		$result = $this->oauth->connect( array( '1001' => '   ' ) );

		$this->assertSame( 0, $result['connected'] );
		$this->assertSame( 1, $result['updated'] );
		$channel = $this->channels->find_by_remote( 'facebook_page', '1001' );
		$this->assertSame( 'T1-NUEVO', $channel->credential( 'access_token' ) );
		$this->assertSame( 'Una renombrada', $channel->remote_name );
		$this->assertSame( 'active', $channel->status, 'Reconectar reactiva el canal.' );
		$this->assertSame( 'Principal', $channel->alias, 'Un alias en blanco conserva el alias existente.' );
	}

	public function test_connect_clears_stale_health_on_reconnect(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->oauth->store_candidates(
			array(
				array(
					'id'           => '1001',
					'name'         => 'Una',
					'access_token' => 'T1',
				),
			)
		);
		$this->oauth->connect( array( '1001' => 'Principal' ) );

		$channel = $this->channels->find_by_remote( 'facebook_page', '1001' );
		$this->channels->set_status(
			$channel->id,
			'paused',
			array(
				'valid'   => false,
				'message' => 'Token rechazado por Facebook.',
			)
		);
		$stale = $this->channels->find( $channel->id );
		$this->assertNotSame( array(), $stale->health, 'El canal queda con salud de fallo antes de reconectar.' );
		$this->assertNotNull( $stale->health_checked_at );

		$this->oauth->store_candidates(
			array(
				array(
					'id'           => '1001',
					'name'         => 'Una',
					'access_token' => 'T1-NUEVO',
				),
			)
		);
		$this->oauth->connect( array( '1001' => 'Principal' ) );

		$reconnected = $this->channels->find( $channel->id );
		$this->assertSame( 'active', $reconnected->status );
		$this->assertSame( array(), $reconnected->health, 'Reconectar borra la salud vieja.' );
		$this->assertNull( $reconnected->health_checked_at, 'Reconectar borra la fecha de la salud vieja.' );
	}

	public function test_connect_rejects_pages_without_create_content(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->oauth->store_candidates(
			array(
				array(
					'id'           => '1001',
					'name'         => 'Una',
					'access_token' => 'T1',
					'tasks'        => array( 'ANALYZE' ),
				),
			)
		);

		$result = $this->oauth->connect( array( '1001' => 'Principal' ) );

		$this->assertSame( 0, $result['connected'] );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertNull( $this->channels->find_by_remote( 'facebook_page', '1001' ) );
	}

	public function test_connect_ignores_pages_outside_the_candidates(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->oauth->store_candidates(
			array(
				array(
					'id'           => '1001',
					'name'         => 'Una',
					'access_token' => 'T1',
				),
			)
		);

		$result = $this->oauth->connect( array( '9999' => 'Inventada' ) );

		$this->assertSame( 0, $result['connected'] );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertNull( $this->channels->find_by_remote( 'facebook_page', '9999' ) );
	}

	public function test_alias_falls_back_to_the_page_name(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->oauth->store_candidates(
			array(
				array(
					'id'           => '1001',
					'name'         => 'Una',
					'access_token' => 'T1',
				),
			)
		);

		$this->oauth->connect( array( '1001' => '   ' ) );

		$this->assertSame( 'Una', $this->channels->find_by_remote( 'facebook_page', '1001' )->alias );
	}

	public function test_handle_connect_only_uses_checked_pages(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->oauth->store_candidates(
			array(
				array(
					'id'           => '1001',
					'name'         => 'Marcada',
					'access_token' => 'T1',
				),
				array(
					'id'           => '1002',
					'name'         => 'Sin marcar',
					'access_token' => 'T2',
				),
			)
		);

		// El formulario envía pages[] con el alias de cada fila pero connect[] solo con las
		// marcadas; handle_connect() filtra por lo marcado antes de construir la selección
		// que llega aquí, así que una Página con alias pero sin marcar no debe darse de alta.
		$result = $this->oauth->connect( array( '1001' => 'Marcada' ) );

		$this->assertSame( 1, $result['connected'] );
		$this->assertNotNull( $this->channels->find_by_remote( 'facebook_page', '1001' ) );
		$this->assertNull( $this->channels->find_by_remote( 'facebook_page', '1002' ), 'Una Página sin marcar no se conecta.' );
	}

	public function test_hooks_are_registered(): void {
		$this->oauth->register_hooks();

		$this->assertNotFalse( has_action( 'admin_post_' . Facebook::ACTION_START, array( $this->oauth, 'handle_start' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_' . Facebook::ACTION_CALLBACK, array( $this->oauth, 'handle_callback' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_nopriv_' . Facebook::ACTION_CALLBACK, array( $this->oauth, 'handle_callback_nopriv' ) ), 'El callback sobrevive a una sesión perdida.' );
		$this->assertNotFalse( has_action( 'admin_post_' . Facebook::ACTION_CONNECT, array( $this->oauth, 'handle_connect' ) ) );
	}
}

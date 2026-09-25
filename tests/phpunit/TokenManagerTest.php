<?php
/**
 * @package Voceador
 */

use Voceador\AppCredentials;
use Voceador\ChannelRepository;
use Voceador\Channels\Channel;
use Voceador\Crypto;
use Voceador\GraphClient;
use Voceador\Logger;
use Voceador\Notices;
use Voceador\Schema;
use Voceador\Settings;
use Voceador\TokenManager;
use Voceador\Tests\Fixtures\GraphResponses;

class TokenManagerTest extends WP_UnitTestCase {

	private TokenManager $tokens;
	private ChannelRepository $channels;
	private array $requests  = array();
	private array $responses = array();

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		delete_option( VOCEADOR_PREFIX . Settings::OPTION );
		delete_option( VOCEADOR_PREFIX . AppCredentials::OPTION );
		delete_option( VOCEADOR_PREFIX . Notices::OPTION );
		_set_cron_array( array() );

		$crypto = new Crypto( str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );
		$schema = new Schema( $wpdb );
		$logger = new Logger( $wpdb, $schema, 'debug' );
		$app    = new AppCredentials( $crypto );
		$app->save( '111222333', 'secreto-app' );
		$this->channels = new ChannelRepository( $wpdb, $schema, $crypto );
		$this->tokens   = new TokenManager( $app, new GraphClient( new Settings(), $logger ), $this->channels, $logger );

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

	private function debug( array $overrides = array() ): array {
		return GraphResponses::ok(
			array(
				'data' => array_merge(
					array(
						'app_id'     => '111222333',
						'type'       => 'PAGE',
						'is_valid'   => true,
						'profile_id' => '1001',
						'expires_at' => 0,
						'scopes'     => TokenManager::REQUIRED_SCOPES,
					),
					$overrides
				),
			)
		);
	}

	private function channel( array $overrides = array() ): Channel {
		$id = $this->channels->insert(
			array_merge(
				array(
					'type'        => 'facebook_page',
					'alias'       => 'Prueba',
					'remote_id'   => '1001',
					'remote_name' => 'Página',
					'credentials' => array( 'access_token' => 'EAAtoken' ),
				),
				$overrides
			)
		);

		return $this->channels->find( $id );
	}

	public function test_check_valid_token(): void {
		$this->responses[] = $this->debug();

		$report = $this->tokens->check( $this->channel() );

		$this->assertTrue( $report->valid );
		$this->assertSame( TokenManager::REQUIRED_SCOPES, $report->scopes );
		$this->assertSame( array(), $report->missing_scopes );
		$this->assertNull( $report->expires_at, 'expires_at 0 significa que no caduca.' );
		$this->assertStringContainsString( '/debug_token', $this->requests[0]['url'] );
		$this->assertStringContainsString( 'input_token=EAAtoken', $this->requests[0]['url'] );
		$this->assertSame( 'Bearer 111222333|secreto-app', $this->requests[0]['args']['headers']['Authorization'] );
	}

	public function test_check_reports_expiry_and_missing_scopes(): void {
		$this->responses[] = $this->debug(
			array(
				'expires_at' => 1790000000,
				'scopes'     => array( 'pages_show_list', 'pages_manage_posts' ),
			)
		);

		$report = $this->tokens->check( $this->channel() );

		$this->assertTrue( $report->valid, 'Faltar permisos no invalida el token.' );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', 1790000000 ), $report->expires_at );
		$this->assertSame( array( 'pages_read_engagement', 'pages_manage_engagement' ), array_values( $report->missing_scopes ) );
	}

	public function test_check_detects_invalid_token(): void {
		$this->responses[] = $this->debug( array( 'is_valid' => false ) );
		$this->assertFalse( $this->tokens->check( $this->channel() )->valid );

		$this->responses[] = $this->debug(
			array(
				'error' => array(
					'code'    => 190,
					'message' => 'Error validating access token: the user has changed the password.',
					'subcode' => 460,
				),
			)
		);
		$report            = $this->tokens->check( $this->channel( array( 'remote_id' => '1002' ) ) );
		$this->assertFalse( $report->valid );
		$this->assertStringContainsString( 'changed the password', $report->message );
	}

	public function test_check_detects_another_page_or_another_app(): void {
		$this->responses[] = $this->debug( array( 'profile_id' => '9999' ) );
		$report            = $this->tokens->check( $this->channel() );
		$this->assertFalse( $report->valid );
		$this->assertStringContainsString( 'otra Página', $report->message );

		$this->responses[] = $this->debug( array( 'app_id' => '444555666' ) );
		$report            = $this->tokens->check( $this->channel( array( 'remote_id' => '1003' ) ) );
		$this->assertFalse( $report->valid );
		$this->assertStringContainsString( 'otra app', $report->message );
	}

	public function test_check_without_token_or_app(): void {
		$channel = $this->channel(
			array(
				'remote_id'   => '1004',
				'credentials' => array(),
			)
		);
		$report  = $this->tokens->check( $channel );

		$this->assertFalse( $report->valid );
		$this->assertCount( 0, $this->requests, 'Sin token no se llama a Graph.' );
	}

	public function test_check_propagates_graph_errors_redacted(): void {
		$this->responses[] = GraphResponses::error( 400, 190, 'Invalid OAuth access token EAA' . str_repeat( 'Ab1', 10 ) );

		$report = $this->tokens->check( $this->channel() );

		$this->assertFalse( $report->valid );
		$this->assertStringNotContainsString( 'EAAAb1', $report->message );
		$this->assertStringContainsString( '[redactado]', $report->message );
	}

	public function test_check_all_pauses_invalid_channels_and_records_a_notice(): void {
		$good = $this->channel();
		$bad  = $this->channel(
			array(
				'remote_id' => '2002',
				'alias'     => 'Rota',
			)
		);

		$this->responses[] = $this->debug();
		$this->responses[] = $this->debug(
			array(
				'profile_id' => '2002',
				'is_valid'   => false,
			)
		);

		$result = $this->tokens->check_all();

		$this->assertSame(
			array(
				'checked' => 2,
				'paused'  => 1,
			),
			$result
		);
		$this->assertSame( 'active', $this->channels->find( $good->id )->status );
		$this->assertNotNull( $this->channels->find( $good->id )->health_checked_at );
		$this->assertSame( 'paused', $this->channels->find( $bad->id )->status );
		$this->assertArrayHasKey( Notices::key_for_channel( $bad->id ), Notices::all() );
		$this->assertStringContainsString( 'Rota', Notices::all()[ Notices::key_for_channel( $bad->id ) ]['message'] );
	}

	public function test_check_all_never_reactivates_and_skips_disabled(): void {
		$paused   = $this->channel( array( 'status' => 'paused' ) );
		$disabled = $this->channel(
			array(
				'remote_id' => '3003',
				'status'    => 'disabled',
			)
		);

		$this->responses[] = $this->debug();

		$result = $this->tokens->check_all();

		$this->assertSame( 1, $result['checked'], 'El canal deshabilitado no se revisa.' );
		$this->assertSame( 'paused', $this->channels->find( $paused->id )->status, 'Un token válido no reactiva por sí solo.' );
		$this->assertSame( 'disabled', $this->channels->find( $disabled->id )->status );
	}

	public function test_register_hooks_schedules_the_daily_check(): void {
		$this->tokens->register_hooks();
		do_action( 'init' );

		$this->assertNotFalse( wp_next_scheduled( TokenManager::HOOK ) );
		$this->assertSame( 10, has_action( TokenManager::HOOK, array( $this->tokens, 'check_all' ) ) );
	}
}

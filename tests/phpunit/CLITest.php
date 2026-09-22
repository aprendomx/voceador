<?php
/**
 * @package Voceador
 */

use Voceador\ChannelRepository;
use Voceador\Channels\ChannelRegistry;
use Voceador\Channels\FacebookPageAdapter;
use Voceador\CLI;
use Voceador\Crypto;
use Voceador\GraphClient;
use Voceador\JobRepository;
use Voceador\Logger;
use Voceador\Publisher;
use Voceador\Queue;
use Voceador\Schema;
use Voceador\Settings;
use Voceador\Templates;
use Voceador\Tests\Fixtures\GraphResponses;

class CLITest extends WP_UnitTestCase {

	private CLI $cli;
	private ChannelRepository $channels;
	private array $responses = array();

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		delete_option( VOCEADOR_PREFIX . Settings::OPTION );
		$schema         = new Schema( $wpdb );
		$logger         = new Logger( $wpdb, $schema, 'debug' );
		$settings       = new Settings();
		$jobs           = new JobRepository( $wpdb, $schema );
		$this->channels = new ChannelRepository( $wpdb, $schema, new Crypto( str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ) );
		$graph          = new GraphClient( $settings, $logger );
		$registry       = new ChannelRegistry( array( 'facebook_page' => static fn() => new FacebookPageAdapter( $graph, $settings ) ) );
		$queue          = new Queue( $jobs, $logger );
		$publisher      = new Publisher( $jobs, $this->channels, $registry, new Templates( $settings ), $settings, $logger, $queue );
		$this->cli      = new CLI( $this->channels, $registry, $publisher, $jobs, $queue, $logger );

		add_filter( 'pre_http_request', array( $this, 'respond' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'respond' ), 10 );
		parent::tear_down();
	}

	public function respond( $pre, array $args, string $url ) {
		return array_shift( $this->responses ) ?? GraphResponses::ok( array( 'id' => '0' ) );
	}

	public function test_add_facebook_page_validates_and_stores(): void {
		$this->responses[] = GraphResponses::ok(
			array(
				'id'   => '1001',
				'name' => 'Mi Página',
			)
		);

		$id = $this->cli->add_facebook_page( '1001', 'EAAtoken', 'Principal' );

		$this->assertIsInt( $id );
		$channel = $this->channels->find( $id );
		$this->assertSame( 'Mi Página', $channel->remote_name );
		$this->assertSame( 'manual', $channel->connection_method );
		$this->assertSame( 'EAAtoken', $channel->credential( 'access_token' ) );
		$this->assertSame( 'Principal', $channel->alias );
	}

	public function test_add_facebook_page_rejects_invalid_token_and_duplicates(): void {
		$this->responses[] = GraphResponses::error( 400, 190, 'Invalid OAuth access token' );
		$error             = $this->cli->add_facebook_page( '1001', 'bad' );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'voceador_invalid_token', $error->get_error_code() );
		$this->assertCount( 0, $this->channels->all() );

		$this->responses[] = GraphResponses::ok(
			array(
				'id'   => '1001',
				'name' => 'Mi Página',
			)
		);
		$this->cli->add_facebook_page( '1001', 'EAAtoken' );
		$this->responses[] = GraphResponses::ok(
			array(
				'id'   => '1001',
				'name' => 'Mi Página',
			)
		);
		$this->assertSame( 'voceador_channel_exists', $this->cli->add_facebook_page( '1001', 'EAAtoken' )->get_error_code() );
	}

	public function test_list_delete_and_status(): void {
		$this->responses[] = GraphResponses::ok(
			array(
				'id'   => '1001',
				'name' => 'Mi Página',
			)
		);
		$id                = $this->cli->add_facebook_page( '1001', 'EAAtoken', 'Principal' );

		$rows = $this->cli->list_channels();
		$this->assertCount( 1, $rows );
		$this->assertSame( array( 'id', 'type', 'alias', 'remote_id', 'remote_name', 'status', 'health_checked_at' ), array_keys( $rows[0] ) );
		$this->assertArrayNotHasKey( 'credentials', $rows[0] );

		$status = $this->cli->status();
		$this->assertSame( 1, $status['channels']['active'] );
		$this->assertSame( 0, $status['jobs']['pending'] );
		$this->assertFalse( $status['action_scheduler'] );
		// El bootstrap de PHPUnit fuerza DISABLE_WP_CRON (ver QueueTest::test_cron_available_detects_recent_activity)
		// y aquí no se ha ejecutado ningún trabajo que dispare Queue::touch(), así que
		// cron_available() refleja fielmente que nada va a ejecutar la cola todavía.
		$this->assertFalse( $status['cron_available'] );
		$this->assertIsArray( $status['recent_log'] );

		$this->assertTrue( $this->cli->delete_channel( $id ) );
		$this->assertFalse( $this->cli->delete_channel( $id ) );
	}

	public function test_publish_runs_publisher(): void {
		$this->responses[] = GraphResponses::ok(
			array(
				'id'   => '1001',
				'name' => 'Mi Página',
			)
		);
		$id                = $this->cli->add_facebook_page( '1001', 'EAAtoken' );
		$post_id           = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		( new Settings() )->update( array( 'image' => array( 'no_image' => 'feed' ) ) );
		$this->responses[] = GraphResponses::ok( array( 'id' => '1001_5' ) );

		$this->assertSame( array( $id => 'published' ), $this->cli->publish( $post_id, null, 'cli' ) );
	}

	public function test_publish_passes_force_through_to_publisher(): void {
		$this->responses[] = GraphResponses::ok(
			array(
				'id'   => '1001',
				'name' => 'Mi Página',
			)
		);
		$id                = $this->cli->add_facebook_page( '1001', 'EAAtoken' );
		$post_id           = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$job_id = ( new JobRepository( $GLOBALS['wpdb'], new Schema( $GLOBALS['wpdb'] ) ) )->create_if_absent( $post_id, $id, 'cli' );
		( new JobRepository( $GLOBALS['wpdb'], new Schema( $GLOBALS['wpdb'] ) ) )->mark_unverified( $job_id, 'sin confirmar' );

		$this->assertSame( array( $id => 'unverified' ), $this->cli->publish( $post_id, null, 'cli' ) );

		( new Settings() )->update( array( 'image' => array( 'no_image' => 'feed' ) ) );
		$this->responses[] = GraphResponses::ok( array( 'id' => '1001_5' ) );
		$this->assertSame( array( $id => 'published' ), $this->cli->publish( $post_id, null, 'cli', true ) );
	}

	public function test_read_token_from_file_and_falls_back_to_token(): void {
		$file = wp_tempnam( 'voceador-token' );
		file_put_contents( $file, "EAAfromfile\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->assertSame( 'EAAfromfile', $this->cli->read_token( array( 'token-file' => $file ) ) );
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->assertSame( 'EAAdirect', $this->cli->read_token( array( 'token' => 'EAAdirect' ) ) );
	}

	public function test_read_token_errors_without_token_or_unreadable_file(): void {
		$missing = $this->cli->read_token( array() );
		$this->assertInstanceOf( WP_Error::class, $missing );
		$this->assertSame( 'voceador_token_missing', $missing->get_error_code() );

		$unreadable = $this->cli->read_token( array( 'token-file' => '/no/existe/token.txt' ) );
		$this->assertInstanceOf( WP_Error::class, $unreadable );
		$this->assertSame( 'voceador_token_file', $unreadable->get_error_code() );
	}

	public function test_resolve_channel_validates_numeric_and_existing(): void {
		$this->assertNull( $this->cli->resolve_channel( array() ), 'Sin --channel, null.' );

		$invalid = $this->cli->resolve_channel( array( 'channel' => 'abc' ) );
		$this->assertInstanceOf( WP_Error::class, $invalid );

		$missing = $this->cli->resolve_channel( array( 'channel' => '999999' ) );
		$this->assertInstanceOf( WP_Error::class, $missing );

		$this->responses[] = GraphResponses::ok(
			array(
				'id'   => '1001',
				'name' => 'Mi Página',
			)
		);
		$id                = $this->cli->add_facebook_page( '1001', 'EAAtoken' );
		$this->assertSame( $id, $this->cli->resolve_channel( array( 'channel' => (string) $id ) ) );
	}

	public function test_retry_releases_and_reruns_failed_jobs(): void {
		$this->responses[] = GraphResponses::ok(
			array(
				'id'   => '1001',
				'name' => 'Mi Página',
			)
		);
		$id                = $this->cli->add_facebook_page( '1001', 'EAAtoken' );
		$post_id           = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		( new Settings() )->update( array( 'image' => array( 'no_image' => 'feed' ) ) );

		$jobs   = new JobRepository( $GLOBALS['wpdb'], new Schema( $GLOBALS['wpdb'] ) );
		$job_id = $jobs->create_if_absent( $post_id, $id, 'cli' );
		$jobs->mark_failed( $job_id, 'transient', 'boom' );

		$this->responses[] = GraphResponses::ok( array( 'id' => '1001_9' ) );
		$this->assertSame( array( $id => 'published' ), $this->cli->retry( $post_id, null, false, false ) );
		$this->assertSame( 'published', $jobs->find( $job_id )->status );
	}

	public function test_retry_ignores_jobs_not_in_a_retryable_status(): void {
		$this->responses[] = GraphResponses::ok(
			array(
				'id'   => '1001',
				'name' => 'Mi Página',
			)
		);
		$id                = $this->cli->add_facebook_page( '1001', 'EAAtoken' );
		$post_id           = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$jobs = new JobRepository( $GLOBALS['wpdb'], new Schema( $GLOBALS['wpdb'] ) );
		$jobs->create_if_absent( $post_id, $id, 'cli' ); // pending: no es failed/skipped/rate_limited.

		$this->assertSame( array(), $this->cli->retry( $post_id, null, false, false ) );
	}

	public function test_retry_comment_retries_the_comment(): void {
		$this->responses[] = GraphResponses::ok(
			array(
				'id'   => '1001',
				'name' => 'Mi Página',
			)
		);
		$id                = $this->cli->add_facebook_page( '1001', 'EAAtoken' );
		$post_id           = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$jobs   = new JobRepository( $GLOBALS['wpdb'], new Schema( $GLOBALS['wpdb'] ) );
		$job_id = $jobs->create_if_absent( $post_id, $id, 'cli' );
		$jobs->mark_published( $job_id, 'r', 'https://x', true );
		$jobs->mark_comment_failed( $job_id, 'boom' );

		$this->responses[] = GraphResponses::ok( array( 'id' => 'c1' ) );
		$this->assertSame( array( $id => 'done' ), $this->cli->retry( $post_id, null, true, false ) );
		$this->assertSame( 'done', $jobs->find( $job_id )->comment_status );
	}
}

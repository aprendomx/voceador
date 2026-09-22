<?php
/**
 * @package Voceador
 */

use Voceador\ChannelRepository;
use Voceador\Channels\ChannelRegistry;
use Voceador\Channels\FacebookPageAdapter;
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

class PublisherTest extends WP_UnitTestCase {

	private Publisher $publisher;
	private JobRepository $jobs;
	private ChannelRepository $channels;
	private Settings $settings;
	private array $requests  = array();
	private array $responses = array();
	private int $channel_id;
	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		delete_option( VOCEADOR_PREFIX . Settings::OPTION );
		delete_option( VOCEADOR_PREFIX . 'notices' );
		_set_cron_array( array() );

		$schema         = new Schema( $wpdb );
		$logger         = new Logger( $wpdb, $schema, 'debug' );
		$this->settings = new Settings();
		$this->jobs     = new JobRepository( $wpdb, $schema );
		$this->channels = new ChannelRepository( $wpdb, $schema, new Crypto( str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ) );
		$graph          = new GraphClient( $this->settings, $logger );
		$registry       = new ChannelRegistry( array( 'facebook_page' => fn() => new FacebookPageAdapter( $graph, $this->settings ) ) );
		$queue          = new Queue( $this->jobs, $logger );

		$this->publisher = new Publisher( $this->jobs, $this->channels, $registry, new Templates( $this->settings ), $this->settings, $logger, $queue );

		$this->channel_id = $this->channels->insert(
			array(
				'type'        => 'facebook_page',
				'alias'       => 'P',
				'remote_id'   => '1001',
				'credentials' => array( 'access_token' => 'EAAt' ),
			)
		);
		$this->post_id    = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Nota',
				'post_excerpt' => 'Extracto',
			)
		);
		$attach           = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg', $this->post_id );
		set_post_thumbnail( $this->post_id, $attach );

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
		return array_shift( $this->responses ) ?? GraphResponses::ok( array( 'id' => '0' ) );
	}

	private function job(): int {
		return $this->jobs->create_if_absent( $this->post_id, $this->channel_id );
	}

	public function test_run_publishes_photo_and_schedules_comment(): void {
		$this->responses[] = GraphResponses::ok(
			array(
				'id'      => '7',
				'post_id' => '1001_7',
			)
		);
		$published         = array();
		add_action(
			'voceador_published',
			static function ( $job, $channel, $result ) use ( &$published ) {
				$published[] = $result->id;
			},
			10,
			3
		);

		$id = $this->job();
		$this->assertSame( 'published', $this->publisher->run( $id ) );

		$job = $this->jobs->find( $id );
		$this->assertSame( '1001_7', $job->remote_id );
		$this->assertSame( 'pending', $job->comment_status );
		$this->assertSame( 1, $job->attempts );
		$this->assertSame( array( '1001_7' ), $published );
		$this->assertStringEndsWith( '/1001/photos', $this->requests[0]['url'] );
		$this->assertStringContainsString( "Nota\n\nExtracto", $this->requests[0]['args']['body'], 'Caption por defecto.' );
		$this->assertEqualsWithDelta( time() + 60, wp_next_scheduled( Queue::HOOK_COMMENT, array( $id ) ), 5 );
		$this->assertFalse( get_transient( VOCEADOR_PREFIX . 'lock_job_' . $id ), 'El lock se libera.' );
	}

	public function test_run_is_idempotent_and_respects_lock(): void {
		$this->responses[] = GraphResponses::ok(
			array(
				'id'      => '7',
				'post_id' => '1001_7',
			)
		);
		$id                = $this->job();
		$this->publisher->run( $id );
		$this->requests = array();

		$this->assertSame( 'published', $this->publisher->run( $id ) );
		$this->assertCount( 0, $this->requests, 'No republica.' );

		$other = $this->jobs->create_if_absent( $this->post_id + 1000, $this->channel_id );
		set_transient( VOCEADOR_PREFIX . 'lock_job_' . $other, 1, 60 );
		$this->assertSame( 'locked', $this->publisher->run( $other ) );
	}

	public function test_run_comment_lifecycle(): void {
		$this->responses[] = GraphResponses::ok(
			array(
				'id'      => '7',
				'post_id' => '1001_7',
			)
		);
		$id                = $this->job();
		$this->publisher->run( $id );

		$this->responses[] = GraphResponses::ok( array( 'id' => '1001_7_1' ) );
		$this->assertSame( 'done', $this->publisher->run_comment( $id ) );
		$job = $this->jobs->find( $id );
		$this->assertSame( 'done', $job->comment_status );
		$this->assertSame( '1001_7_1', $job->remote_comment_id );
		$this->assertSame( array( 'message' => get_permalink( $this->post_id ) ), end( $this->requests )['args']['body'] );

		$this->assertSame( 'skipped', $this->publisher->run_comment( $id ), 'Ya comentado.' );
	}

	public function test_run_comment_transient_error_reschedules_and_spam_does_not(): void {
		$this->responses[] = GraphResponses::ok(
			array(
				'id'      => '7',
				'post_id' => '1001_7',
			)
		);
		$id                = $this->job();
		$this->publisher->run( $id );
		_set_cron_array( array() );

		$this->responses[] = GraphResponses::error( 500, 2, 'Service temporarily unavailable' );
		$this->assertSame( 'pending', $this->publisher->run_comment( $id ) );
		$this->assertSame( 'failed', $this->jobs->find( $id )->comment_status, 'Queda failed hasta el reintento.' );
		$this->assertSame( 1, $this->jobs->find( $id )->comment_attempts );
		$this->assertEqualsWithDelta( time() + 120, wp_next_scheduled( Queue::HOOK_COMMENT, array( $id ) ), 5, 'Backoff base 120 × 2^0.' );

		_set_cron_array( array() );
		$this->responses[] = GraphResponses::error( 400, 368, 'Blocked as spam' );
		$this->assertSame( 'failed', $this->publisher->run_comment( $id ) );
		$this->assertFalse( wp_next_scheduled( Queue::HOOK_COMMENT, array( $id ) ) );
		$this->assertSame( 'published', $this->jobs->find( $id )->status, 'El fallo del comentario no toca el estado principal.' );
	}

	public function test_transient_error_releases_with_backoff_until_retries_exhausted(): void {
		$this->settings->update(
			array(
				'execution' => array(
					'retries'      => 2,
					'backoff_base' => 10,
				),
			)
		);
		$id = $this->job();

		$this->responses[] = GraphResponses::transport();
		$this->assertSame( 'pending', $this->publisher->run( $id ) );
		$job = $this->jobs->find( $id );
		$this->assertSame( 'pending', $job->status );
		$this->assertSame( 1, $job->attempts );
		$this->assertSame( 'transient', $job->error_code );
		$this->assertEqualsWithDelta( time() + 10, wp_next_scheduled( Queue::HOOK_RUN, array( $id ) ), 5 );

		$this->responses[] = GraphResponses::error( 500, 1, 'Unknown' );
		$this->assertSame( 'failed', $this->publisher->run( $id ), 'Segundo intento agota retries=2.' );
		$this->assertSame( 2, $this->jobs->find( $id )->attempts );
	}

	public function test_auth_error_pauses_channel_and_records_notice(): void {
		$failed = array();
		add_action(
			'voceador_failed',
			static function ( $job, $channel, $error ) use ( &$failed ) {
				$failed[] = $error->get_error_code();
			},
			10,
			3
		);
		$this->responses[] = GraphResponses::error( 400, 190, 'Error validating access token' );

		$this->assertSame( 'failed', $this->publisher->run( $this->job() ) );

		$channel = $this->channels->find( $this->channel_id );
		$this->assertSame( 'paused', $channel->status );
		$this->assertStringContainsString( 'access token', $channel->health['message'] );
		$this->assertSame( array( 'auth' ), $failed );
		$notices = get_option( VOCEADOR_PREFIX . 'notices' );
		$this->assertArrayHasKey( 'channel_paused_' . $this->channel_id, $notices );
	}

	public function test_paused_channel_fails_without_calling_graph(): void {
		$this->channels->set_status( $this->channel_id, 'paused' );
		$id = $this->job();
		$this->assertSame( 'failed', $this->publisher->run( $id ) );
		$this->assertSame( 'channel_unavailable', $this->jobs->find( $id )->error_code );
		$this->assertCount( 0, $this->requests );
	}

	public function test_rate_limited_error_reschedules_with_retry_after(): void {
		$response            = GraphResponses::error( 400, 613, 'Calls to this api have exceeded the rate limit' );
		$response['headers'] = array( 'retry-after' => '90' );
		$this->responses[]   = $response;
		$id                  = $this->job();

		$this->assertSame( 'rate_limited', $this->publisher->run( $id ) );
		$this->assertEqualsWithDelta( time() + 90, wp_next_scheduled( Queue::HOOK_RUN, array( $id ) ), 5 );
	}

	public function test_no_image_skip_and_feed(): void {
		$plain = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Sin foto',
			)
		);
		$id    = $this->jobs->create_if_absent( $plain, $this->channel_id );

		$this->assertSame( 'skipped', $this->publisher->run( $id ) );
		$this->assertCount( 0, $this->requests );

		$this->settings->update( array( 'image' => array( 'no_image' => 'feed' ) ) );
		$this->responses[] = GraphResponses::ok( array( 'id' => '1001_9' ) );
		$this->jobs->release( $id, current_time( 'mysql', true ) );
		$this->assertSame( 'published', $this->publisher->run( $id ) );
		$this->assertStringEndsWith( '/1001/feed', $this->requests[0]['url'] );
		$this->assertSame( '1001_9', $this->jobs->find( $id )->remote_id );
	}

	public function test_unpublished_post_is_skipped_and_missing_job_reported(): void {
		wp_update_post(
			array(
				'ID'          => $this->post_id,
				'post_status' => 'draft',
			)
		);
		$id = $this->job();
		$this->assertSame( 'skipped', $this->publisher->run( $id ) );
		$this->assertSame( 'missing', $this->publisher->run( 999999 ) );
	}

	public function test_publish_now_creates_and_runs_jobs(): void {
		$this->responses[] = GraphResponses::ok(
			array(
				'id'      => '7',
				'post_id' => '1001_7',
			)
		);

		$result = $this->publisher->publish_now( $this->post_id );

		$this->assertSame( array( $this->channel_id => 'published' ), $result );
		$this->assertSame( 'manual', $this->jobs->find_for_post_and_channel( $this->post_id, $this->channel_id )->source );
		$this->assertSame( array( $this->channel_id => 'published' ), $this->publisher->publish_now( $this->post_id ), 'Segunda vez no republica.' );
		$this->assertCount( 1, $this->requests );
	}

	public function test_backoff(): void {
		$this->assertSame( 120, $this->publisher->backoff( 1 ) );
		$this->assertSame( 240, $this->publisher->backoff( 2 ) );
		$this->assertSame( 480, $this->publisher->backoff( 3 ) );
		$this->assertSame( Publisher::MAX_BACKOFF, $this->publisher->backoff( 20 ) );
	}

	public function test_unverified_job_is_not_rerun_without_force(): void {
		$id = $this->job();
		$this->jobs->claim( $id );
		$this->jobs->mark_unverified( $id, 'x' );

		$this->assertSame( 'unverified', $this->publisher->run( $id ) );
		$this->assertCount( 0, $this->requests, 'No debe llamar a Graph sin confirmación.' );
		$job = $this->jobs->find( $id );
		$this->assertSame( 'failed', $job->status );
		$this->assertSame( 'unverified', $job->error_code );

		$this->responses[] = GraphResponses::ok(
			array(
				'id'      => '7',
				'post_id' => '1001_7',
			)
		);
		$this->assertSame( 'published', $this->publisher->run( $id, true ) );
	}
}

<?php
/**
 * @package Voceador
 */

use Voceador\Channels\Channel;
use Voceador\Channels\FacebookPageAdapter;
use Voceador\Channels\Media;
use Voceador\Channels\Payload;
use Voceador\GraphClient;
use Voceador\Logger;
use Voceador\Schema;
use Voceador\Settings;
use Voceador\Tests\Fixtures\GraphResponses;

class FacebookPageAdapterTest extends WP_UnitTestCase {

	private FacebookPageAdapter $adapter;
	private array $requests  = array();
	private array $responses = array();

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		delete_option( VOCEADOR_PREFIX . Settings::OPTION );
		$settings        = new Settings();
		$this->adapter   = new FacebookPageAdapter( new GraphClient( $settings, new Logger( $wpdb, new Schema( $wpdb ), 'debug' ) ), $settings );
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

	private function channel( array $settings = array(), string $remote_id = '1001' ): Channel {
		return new Channel(
			array(
				'id'          => 1,
				'type'        => 'facebook_page',
				'remote_id'   => $remote_id,
				'credentials' => array( 'access_token' => 'EAAtoken' ),
				'settings'    => $settings,
			)
		);
	}

	private function post_with_image( string $file = 'test-image.jpg' ): WP_Post {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$attach  = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/' . $file, $post_id );
		set_post_thumbnail( $post_id, $attach );
		return get_post( $post_id );
	}

	public function test_type_and_label(): void {
		$this->assertSame( 'facebook_page', FacebookPageAdapter::type() );
		$this->assertSame( 'Página de Facebook', FacebookPageAdapter::label() );
		$this->assertNull( $this->adapter->usage_limits( $this->channel() ) );
		$this->assertArrayHasKey( 'image.fb_size', $this->adapter->settings_schema() );
	}

	public function test_validate_credentials_ok_and_mismatch(): void {
		$this->responses[] = GraphResponses::ok(
			array(
				'id'   => '1001',
				'name' => 'Mi Página',
			)
		);
		$report            = $this->adapter->validate_credentials( $this->channel() );
		$this->assertTrue( $report->valid );
		$this->assertSame( 'Mi Página', $report->raw['name'] );
		$this->assertStringContainsString( '/me?fields=id%2Cname', $this->requests[0]['url'] );
		$this->assertSame( 'Bearer EAAtoken', $this->requests[0]['args']['headers']['Authorization'] );

		$this->responses[] = GraphResponses::ok(
			array(
				'id'   => '2002',
				'name' => 'Otra',
			)
		);
		$report            = $this->adapter->validate_credentials( $this->channel() );
		$this->assertFalse( $report->valid );
		$this->assertStringContainsString( 'otra Página', $report->message );

		$this->responses[] = GraphResponses::error( 400, 190, 'Invalid OAuth access token' );
		$report            = $this->adapter->validate_credentials( $this->channel() );
		$this->assertFalse( $report->valid );
		$this->assertSame( 'Invalid OAuth access token', $report->message );
	}

	public function test_prepare_media_returns_featured_image_file_and_url(): void {
		$post  = $this->post_with_image();
		$media = $this->adapter->prepare_media( $this->channel(), $post );

		$this->assertInstanceOf( Media::class, $media );
		$this->assertFalse( $media->is_empty() );
		$this->assertFileExists( $media->path );
		$this->assertStringStartsWith( 'http', $media->url );
		$this->assertSame( 'image/jpeg', $media->mime );
		$this->assertGreaterThan( 0, $media->bytes );
	}

	public function test_prepare_media_without_thumbnail_is_empty(): void {
		$post  = get_post( self::factory()->post->create() );
		$media = $this->adapter->prepare_media( $this->channel(), $post );
		$this->assertTrue( $media->is_empty() );
	}

	public function test_prepare_media_rejects_unsupported_mime(): void {
		$post  = $this->post_with_image( 'test-image.webp' );
		$media = $this->adapter->prepare_media( $this->channel(), $post );
		$this->assertInstanceOf( WP_Error::class, $media );
		$this->assertSame( 'media', $media->get_error_code() );
	}

	public function test_prepare_media_falls_back_to_smaller_size_when_too_big(): void {
		add_filter( 'voceador_fb_max_bytes', static fn() => 1 );
		$post  = $this->post_with_image();
		$media = $this->adapter->prepare_media( $this->channel(), $post );
		$this->assertInstanceOf( WP_Error::class, $media, 'Con 1 byte de límite ningún tamaño cabe.' );
		$this->assertSame( 'media', $media->get_error_code() );
	}

	public function test_image_filter(): void {
		add_filter( 'voceador_image', static fn( Media $m ) => new Media( '', 'https://cdn.example/x.jpg' ), 10, 1 ); // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Firma del filtro voceador_image.
		$media = $this->adapter->prepare_media( $this->channel(), $this->post_with_image() );
		$this->assertSame( 'https://cdn.example/x.jpg', $media->url );
	}

	public function test_publish_photo_multipart_uses_post_id(): void {
		$media             = $this->adapter->prepare_media( $this->channel(), $this->post_with_image() );
		$this->requests    = array();
		$this->responses[] = GraphResponses::ok(
			array(
				'id'      => '777',
				'post_id' => '1001_777',
			)
		);

		$result = $this->adapter->publish( $this->channel(), new Payload( 'Hola', $media, 'https://x/nota' ) );

		$this->assertSame( '1001_777', $result->id );
		$this->assertSame( 'https://www.facebook.com/1001_777', $result->url );
		$this->assertStringEndsWith( '/1001/photos', $this->requests[0]['url'] );
		$this->assertStringStartsWith( 'multipart/form-data', $this->requests[0]['args']['headers']['Content-Type'] );
		$this->assertStringContainsString( 'name="caption"', $this->requests[0]['args']['body'] );
		$this->assertStringContainsString( 'name="source"', $this->requests[0]['args']['body'] );
	}

	public function test_publish_photo_by_url_when_configured(): void {
		$media             = new Media( '/no/importa.jpg', 'https://site/img.jpg' );
		$this->responses[] = GraphResponses::ok( array( 'id' => '778' ) );

		$result = $this->adapter->publish( $this->channel( array( 'image' => array( 'fb_mode' => 'url' ) ) ), new Payload( 'Hola', $media ) );

		$this->assertSame( '778', $result->id, 'Sin post_id usa id.' );
		$this->assertSame(
			array(
				'caption' => 'Hola',
				'url'     => 'https://site/img.jpg',
			),
			$this->requests[0]['args']['body']
		);
	}

	public function test_publish_link_to_feed_without_media(): void {
		$this->responses[] = GraphResponses::ok( array( 'id' => '1001_900' ) );

		$result = $this->adapter->publish( $this->channel(), new Payload( 'Hola', null, 'https://x/nota' ) );

		$this->assertSame( '1001_900', $result->id );
		$this->assertStringEndsWith( '/1001/feed', $this->requests[0]['url'] );
		$this->assertSame(
			array(
				'message' => 'Hola',
				'link'    => 'https://x/nota',
			),
			$this->requests[0]['args']['body']
		);
	}

	public function test_publish_propagates_graph_errors(): void {
		$this->responses[] = GraphResponses::error( 403, 200, 'Permissions error' );
		$result            = $this->adapter->publish( $this->channel(), new Payload( 'Hola', null, 'https://x' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'permission', $result->get_error_code() );
	}

	public function test_comment_and_status(): void {
		$this->responses[] = GraphResponses::ok( array( 'id' => '1001_777_1' ) );
		$result            = $this->adapter->comment( $this->channel(), '1001_777', 'Enlace: https://x' );
		$this->assertSame( '1001_777_1', $result->id );
		$this->assertStringEndsWith( '/1001_777/comments', $this->requests[0]['url'] );
		$this->assertSame( array( 'message' => 'Enlace: https://x' ), $this->requests[0]['args']['body'] );

		$this->responses[] = GraphResponses::ok(
			array(
				'id'            => '1001_777',
				'permalink_url' => 'https://www.facebook.com/p',
			)
		);
		$status            = $this->adapter->status( $this->channel(), '1001_777' );
		$this->assertTrue( $status->exists );
		$this->assertSame( 'https://www.facebook.com/p', $status->url );

		$this->responses[] = GraphResponses::error( 404, 100, 'Unsupported get request' );
		$this->assertFalse( $this->adapter->status( $this->channel(), 'gone' )->exists );
	}
}

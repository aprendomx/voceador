<?php
/**
 * @package Voceador
 */

use Voceador\GraphClient;
use Voceador\Logger;
use Voceador\Schema;
use Voceador\Settings;

class GraphClientTest extends WP_UnitTestCase {

	private GraphClient $client;
	private array $requests = array();
	private $response;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		delete_option( VOCEADOR_PREFIX . Settings::OPTION );
		$this->client   = new GraphClient( new Settings(), new Logger( $wpdb, new Schema( $wpdb ), 'debug' ) );
		$this->requests = array();
		$this->response = $this->json( 200, array( 'id' => '1' ) );

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
		return $this->response;
	}

	private function json( int $code, array $body, array $headers = array() ): array {
		return array(
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'headers'  => $headers,
			'body'     => wp_json_encode( $body ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function graph_error( int $http, int $code, string $message = 'boom', int $subcode = 0 ): array {
		return $this->json(
			$http,
			array(
				'error' => array(
					'message'       => $message,
					'type'          => 'OAuthException',
					'code'          => $code,
					'error_subcode' => $subcode,
					'fbtrace_id'    => 'trace',
				),
			)
		);
	}

	public function test_get_builds_url_and_sends_bearer_token(): void {
		$this->response = $this->json(
			200,
			array(
				'id'   => '42',
				'name' => 'Página',
			)
		);

		$result = $this->client->get( 'me', array( 'fields' => 'id,name' ), 'EAAtoken' );

		$this->assertSame(
			array(
				'id'   => '42',
				'name' => 'Página',
			),
			$result
		);
		$this->assertSame( 'https://graph.facebook.com/v26.0/me?fields=id%2Cname', $this->requests[0]['url'] );
		$this->assertSame( 'GET', $this->requests[0]['args']['method'] );
		$this->assertSame( 'Bearer EAAtoken', $this->requests[0]['args']['headers']['Authorization'] );
		$this->assertStringNotContainsString( 'EAAtoken', $this->requests[0]['url'] );
	}

	public function test_version_comes_from_settings(): void {
		( new Settings() )->update( array( 'graph_version' => 'v25.0' ) );
		$this->client->get( 'me' );
		$this->assertStringStartsWith( 'https://graph.facebook.com/v25.0/me', $this->requests[0]['url'] );
	}

	public function test_post_sends_form_body_without_token(): void {
		$this->response = $this->json(
			200,
			array(
				'id'      => '9',
				'post_id' => '1_9',
			)
		);

		$result = $this->client->post(
			'123/photos',
			array(
				'url'     => 'https://x/y.jpg',
				'caption' => 'Hola',
			),
			'EAAtoken'
		);

		$this->assertSame( '1_9', $result['post_id'] );
		$this->assertSame( 'POST', $this->requests[0]['args']['method'] );
		$this->assertSame(
			array(
				'url'     => 'https://x/y.jpg',
				'caption' => 'Hola',
			),
			$this->requests[0]['args']['body']
		);
		$this->assertSame( 'Bearer EAAtoken', $this->requests[0]['args']['headers']['Authorization'] );
	}

	public function test_post_multipart_embeds_file(): void {
		$file = wp_tempnam( 'voceador' );
		file_put_contents( $file, 'JPEGDATA' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->client->post_multipart( '123/photos', array( 'caption' => 'Hola' ), 'source', $file, 'EAAtoken' );
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$args = $this->requests[0]['args'];
		$this->assertStringStartsWith( 'multipart/form-data; boundary=', $args['headers']['Content-Type'] );
		$this->assertStringContainsString( 'name="caption"', $args['body'] );
		$this->assertStringContainsString( 'Hola', $args['body'] );
		$this->assertStringContainsString( 'name="source"; filename="', $args['body'] );
		$this->assertStringContainsString( 'JPEGDATA', $args['body'] );
		$this->assertStringNotContainsString( 'EAAtoken', $args['body'] );
	}

	public function test_post_multipart_with_missing_file_is_media_error(): void {
		$result = $this->client->post_multipart( '123/photos', array(), 'source', '/no/existe.jpg', 't' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'media', $result->get_error_code() );
		$this->assertCount( 0, $this->requests, 'No se hace la petición.' );
	}

	/**
	 * @dataProvider error_classes
	 */
	public function test_graph_errors_are_classified( int $http, int $code, string $expected ): void {
		$this->response = $this->graph_error( $http, $code );

		$result = $this->client->get( 'me', array(), 't' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $expected, $result->get_error_code() );
		$this->assertSame( 'boom', $result->get_error_message() );
		$this->assertSame( $code, $result->get_error_data()['graph_code'] );
		$this->assertSame( $http, $result->get_error_data()['http'] );
		$this->assertSame( 'trace', $result->get_error_data()['fbtrace_id'] );
	}

	public function error_classes(): array {
		return array(
			'190 auth'       => array( 400, 190, 'auth' ),
			'102 auth'       => array( 400, 102, 'auth' ),
			'10 permission'  => array( 403, 10, 'permission' ),
			'200 permission' => array( 403, 200, 'permission' ),
			'299 permission' => array( 403, 299, 'permission' ),
			'368 spam'       => array( 400, 368, 'spam' ),
			'4 transient'    => array( 400, 4, 'transient' ),
			'17 transient'   => array( 400, 17, 'transient' ),
			'341 transient'  => array( 400, 341, 'transient' ),
			'613 rate'       => array( 400, 613, 'rate_limited' ),
			'80001 rate'     => array( 400, 80001, 'rate_limited' ),
			'324 media'      => array( 400, 324, 'media' ),
			'100 fatal'      => array( 400, 100, 'fatal' ),
			'unknown fatal'  => array( 400, 999999, 'fatal' ),
			'500 with 1'     => array( 500, 1, 'transient' ),
			'500 with 190'   => array( 500, 190, 'auth' ),
		);
	}

	public function test_http_5xx_without_graph_error_is_transient(): void {
		$this->response = array(
			'response' => array(
				'code'    => 502,
				'message' => 'Bad Gateway',
			),
			'headers'  => array(),
			'body'     => '<html>bad gateway</html>',
			'cookies'  => array(),
			'filename' => null,
		);

		$result = $this->client->get( 'me' );

		$this->assertSame( 'transient', $result->get_error_code() );
		$this->assertSame( 502, $result->get_error_data()['http'] );
	}

	public function test_transport_error_is_transient(): void {
		$this->response = new WP_Error( 'http_request_failed', 'cURL error 28: timeout' );

		$result = $this->client->get( 'me' );

		$this->assertSame( 'transient', $result->get_error_code() );
		$this->assertSame( 'cURL error 28: timeout', $result->get_error_message() );
	}

	public function test_non_json_200_is_fatal(): void {
		$this->response = array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'headers'  => array(),
			'body'     => 'not json',
			'cookies'  => array(),
			'filename' => null,
		);

		$this->assertSame( 'fatal', $this->client->get( 'me' )->get_error_code() );
	}

	public function test_error_map_filter(): void {
		add_filter(
			'voceador_error_map',
			static function ( array $map ): array {
				$map['media'][] = 2207001;
				return $map;
			}
		);
		$this->response = $this->graph_error( 400, 2207001 );

		$this->assertSame( 'media', $this->client->get( 'me' )->get_error_code() );
	}

	public function test_retry_after_and_usage_headers(): void {
		$this->response            = $this->graph_error( 400, 4 );
		$this->response['headers'] = array(
			'retry-after' => '120',
			'x-app-usage' => wp_json_encode(
				array(
					'call_count'    => 97,
					'total_time'    => 10,
					'total_cputime' => 5,
				)
			),
		);

		$result = $this->client->get( 'me' );

		$this->assertSame( 120, $result->get_error_data()['retry_after'] );
		$this->assertSame( 97, $this->client->last_usage()['x-app-usage']['call_count'] );
	}

	public function test_errors_are_logged_without_secrets(): void {
		global $wpdb;
		$this->response = $this->graph_error( 400, 190, 'Invalid OAuth access token EAA' . str_repeat( 'Zz9', 12 ) );

		$this->client->get( 'me', array(), 'EAAtoken' );

		$row = $wpdb->get_row( 'SELECT * FROM ' . ( new Schema( $wpdb ) )->table( 'log' ) . " WHERE event = 'graph_error' ORDER BY id DESC LIMIT 1", ARRAY_A );
		$this->assertNotNull( $row );
		$this->assertSame( 'warning', $row['level'] );
		$this->assertStringNotContainsString( 'EAAtoken', $row['context'] . $row['message'] );
		$this->assertStringNotContainsString( 'Zz9Zz9', $row['context'] . $row['message'] );
	}

	public function test_timeout_is_filterable(): void {
		add_filter( 'voceador_http_timeout', static fn() => 7 );
		$this->client->get( 'me' );
		$this->assertSame( 7, $this->requests[0]['args']['timeout'] );
	}
}

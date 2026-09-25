<?php
/**
 * @package Voceador
 */

use Voceador\Logger;
use Voceador\Schema;

class LoggerTest extends WP_UnitTestCase {

	private Logger $logger;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->logger = new Logger( $wpdb, new Schema( $wpdb ), 'debug' );
	}

	public function test_redact_masks_sensitive_keys_recursively(): void {
		$redacted = Logger::redact(
			array(
				'access_token'  => 'EAAabc',
				'app_secret'    => 's',
				'Authorization' => 'Bearer x',
				'code'          => 'oauth-code',
				'Code'          => 'x',
				'nested'        => array(
					'page_token' => 't',
					'safe'       => 'ok',
					'password'   => 'p',
				),
				'graph_code'    => 190,
				'safe'          => 'visible',
			)
		);

		$this->assertSame( '[redactado]', $redacted['access_token'] );
		$this->assertSame( '[redactado]', $redacted['app_secret'] );
		$this->assertSame( '[redactado]', $redacted['Authorization'] );
		$this->assertSame( '[redactado]', $redacted['code'] );
		$this->assertSame( '[redactado]', $redacted['Code'], 'La clave "code" se compara sin distinguir mayúsculas.' );
		$this->assertSame( '[redactado]', $redacted['nested']['page_token'] );
		$this->assertSame( '[redactado]', $redacted['nested']['password'] );
		$this->assertSame( 'ok', $redacted['nested']['safe'] );
		$this->assertSame( 190, $redacted['graph_code'], 'graph_code no es un secreto.' );
		$this->assertSame( 'visible', $redacted['safe'] );
	}

	public function test_redact_masks_tokens_inside_strings(): void {
		$token    = 'EAA' . str_repeat( 'Ab1', 10 );
		$redacted = Logger::redact(
			array(
				'url'  => 'https://graph.facebook.com/v26.0/me?fields=id&access_token=' . $token . '&x=1',
				'body' => 'client_secret=abc123&grant_type=x',
				'msg'  => 'Token ' . $token . ' rechazado',
			)
		);

		$this->assertSame( 'https://graph.facebook.com/v26.0/me?fields=id&access_token=[redactado]&x=1', $redacted['url'] );
		$this->assertSame( 'client_secret=[redactado]&grant_type=x', $redacted['body'] );
		$this->assertSame( 'Token [redactado] rechazado', $redacted['msg'] );
	}

	public function test_log_writes_row_with_ids_and_redacted_context(): void {
		global $wpdb;
		$this->logger->error(
			'publish_failed',
			'Falló',
			array(
				'channel_id'   => 3,
				'post_id'      => 7,
				'job_id'       => 9,
				'access_token' => 'EAAsecreto',
				'graph_code'   => 190,
			)
		);

		$row = $wpdb->get_row( 'SELECT * FROM ' . ( new Schema( $wpdb ) )->table( 'log' ) . ' ORDER BY id DESC LIMIT 1', ARRAY_A );

		$this->assertSame( 'error', $row['level'] );
		$this->assertSame( 'publish_failed', $row['event'] );
		$this->assertSame( 'Falló', $row['message'] );
		$this->assertSame( '3', $row['channel_id'] );
		$this->assertSame( '7', $row['post_id'] );
		$this->assertSame( '9', $row['job_id'] );
		$this->assertStringNotContainsString( 'EAAsecreto', $row['context'] );
		$this->assertStringNotContainsString( 'channel_id', $row['context'], 'Los ids van en columnas, no en el JSON.' );
		$this->assertSame( 190, json_decode( $row['context'], true )['graph_code'] );
	}

	public function test_min_level_filters_out_lower_levels(): void {
		global $wpdb;
		$logger = new Logger( $wpdb, new Schema( $wpdb ), 'warning' );
		$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ( new Schema( $wpdb ) )->table( 'log' ) );

		$logger->debug( 'a', 'x' );
		$logger->info( 'b', 'x' );
		$logger->warning( 'c', 'x' );
		$logger->error( 'd', 'x' );

		$after = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ( new Schema( $wpdb ) )->table( 'log' ) );
		$this->assertSame( 2, $after - $before );
	}

	public function test_unknown_level_is_treated_as_error(): void {
		$this->logger->log( 'critical', 'e', 'x' );
		$rows = $this->logger->recent( array( 'limit' => 1 ) );
		$this->assertSame( 'error', $rows[0]['level'] );
	}

	public function test_recent_filters_and_decodes_context(): void {
		$this->logger->info(
			'one',
			'x',
			array(
				'post_id' => 1,
				'k'       => 'v',
			)
		);
		$this->logger->warning( 'two', 'x', array( 'post_id' => 2 ) );
		$this->logger->warning( 'three', 'x', array( 'post_id' => 1 ) );

		$rows = $this->logger->recent( array( 'post_id' => 1 ) );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'three', $rows[0]['event'], 'Más reciente primero.' );
		$this->assertSame( array( 'k' => 'v' ), $rows[1]['context'] );

		$this->assertCount( 2, $this->logger->recent( array( 'level' => 'warning' ) ) );
		$this->assertCount(
			1,
			$this->logger->recent(
				array(
					'level'   => 'warning',
					'post_id' => 2,
				)
			)
		);
	}

	public function test_message_is_redacted(): void {
		global $wpdb;
		$this->logger->error( 'e', 'Token EAA' . str_repeat( 'Ab1', 10 ) . ' rechazado en ?access_token=abc&code=xyz' );

		$row = $wpdb->get_row( 'SELECT * FROM ' . ( new Schema( $wpdb ) )->table( 'log' ) . ' ORDER BY id DESC LIMIT 1', ARRAY_A );

		$this->assertStringNotContainsString( 'EAAAb1', $row['message'] );
		$this->assertStringNotContainsString( 'access_token=abc', $row['message'] );
		$this->assertStringNotContainsString( 'code=xyz', $row['message'] );
		$this->assertStringContainsString( '[redactado]', $row['message'] );
	}

	public function test_code_in_query_string_is_redacted(): void {
		$redacted = Logger::redact( array( 'url' => 'https://x/cb?code=AQDsecret&state=s' ) );

		$this->assertSame( 'https://x/cb?code=[redactado]&state=s', $redacted['url'] );
	}

	public function test_redact_masks_app_and_instagram_tokens(): void {
		$app_token = '1234567890123456|' . str_repeat( 'a1b2', 8 );
		$ig_token  = 'IGAA' . str_repeat( 'Xy9', 12 );
		$ig_legacy = 'IGQVJ' . str_repeat( 'Zz8', 12 );

		$redacted = Logger::redact(
			array(
				'msg'  => 'app ' . $app_token . ' fin',
				'ig'   => 'token ' . $ig_token,
				'old'  => $ig_legacy,
				'safe' => '12345|corto',
			)
		);

		$this->assertSame( 'app [redactado] fin', $redacted['msg'] );
		$this->assertSame( 'token [redactado]', $redacted['ig'] );
		$this->assertSame( '[redactado]', $redacted['old'] );
		$this->assertSame( '12345|corto', $redacted['safe'], 'Un pipe suelto no es un token de app.' );
	}

	public function test_redact_string_masks_app_token_in_a_url(): void {
		$app_token = '1234567890123456|' . str_repeat( 'a1b2', 8 );

		$this->assertSame(
			'https://graph.facebook.com/v26.0/debug_token?access_token=[redactado]&input_token=[redactado]',
			Logger::redact_string( 'https://graph.facebook.com/v26.0/debug_token?access_token=' . $app_token . '&input_token=EAA' . str_repeat( 'Ab1', 10 ) )
		);
	}

	public function test_objects_in_context_are_normalized_and_redacted(): void {
		$redacted = Logger::redact(
			array(
				'error' => new WP_Error( 'auth', 'bad', array( 'access_token' => 'EAAx' ) ),
				'obj'   => (object) array(
					'client_secret' => 's',
					'ok'            => 1,
				),
			)
		);

		$this->assertSame( 'auth', $redacted['error']['error_code'] );
		$this->assertSame( '[redactado]', $redacted['error']['data']['access_token'] );
		$this->assertSame( '[redactado]', $redacted['obj']['client_secret'] );
		$this->assertSame( 1, $redacted['obj']['ok'] );
	}
}

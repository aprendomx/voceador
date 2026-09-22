<?php
/**
 * @package Voceador
 */

use Voceador\Schema;

class SchemaTest extends WP_UnitTestCase {

	private Schema $schema;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->schema = new Schema( $wpdb );
		$this->schema->install();
	}

	private function columns( string $table ): array {
		global $wpdb;
		return $wpdb->get_col( 'DESCRIBE ' . $this->schema->table( $table ), 0 );
	}

	public function test_table_uses_site_prefix_and_plugin_prefix(): void {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'voceador_jobs', $this->schema->table( 'jobs' ) );
	}

	public function test_table_rejects_unknown_names(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->schema->table( 'posts' );
	}

	public function test_channels_columns(): void {
		$this->assertSame(
			array(
				'id',
				'type',
				'alias',
				'remote_id',
				'remote_name',
				'avatar_url',
				'connection_method',
				'parent_channel_id',
				'credentials',
				'scopes',
				'token_expires_at',
				'status',
				'health',
				'health_checked_at',
				'settings',
				'created_at',
				'updated_at',
			),
			$this->columns( 'channels' )
		);
	}

	public function test_jobs_columns(): void {
		$this->assertSame(
			array(
				'id',
				'post_id',
				'channel_id',
				'status',
				'comment_status',
				'remote_id',
				'remote_url',
				'remote_comment_id',
				'container_id',
				'attempts',
				'comment_attempts',
				'scheduled_at',
				'published_at',
				'error_code',
				'error_message',
				'source',
				'created_at',
				'updated_at',
			),
			$this->columns( 'jobs' )
		);
	}

	public function test_log_columns(): void {
		$this->assertSame(
			array( 'id', 'created_at', 'level', 'channel_id', 'post_id', 'job_id', 'event', 'message', 'context' ),
			$this->columns( 'log' )
		);
	}

	public function test_jobs_reject_duplicate_post_and_channel(): void {
		global $wpdb;
		$row = array(
			'post_id'    => 10,
			'channel_id' => 3,
		);

		$this->assertSame( 1, $wpdb->insert( $this->schema->table( 'jobs' ), $row ) );

		$suppress = $wpdb->suppress_errors( true );
		$second   = $wpdb->insert( $this->schema->table( 'jobs' ), $row );
		$wpdb->suppress_errors( $suppress );

		$this->assertFalse( $second );
	}

	public function test_jobs_defaults(): void {
		global $wpdb;
		$wpdb->insert(
			$this->schema->table( 'jobs' ),
			array(
				'post_id'    => 11,
				'channel_id' => 3,
			)
		);
		$job = $wpdb->get_row( 'SELECT * FROM ' . $this->schema->table( 'jobs' ) . ' WHERE post_id = 11' );

		$this->assertSame( 'pending', $job->status );
		$this->assertSame( 'none', $job->comment_status );
		$this->assertSame( 'auto', $job->source );
		$this->assertSame( '0', (string) $job->attempts );
		$this->assertNull( $job->remote_id );
	}

	public function test_channels_reject_duplicate_type_and_remote_id(): void {
		global $wpdb;
		$row = array(
			'type'        => 'facebook_page',
			'alias'       => 'Principal',
			'remote_id'   => '1234567890',
			'credentials' => '',
			'scopes'      => '[]',
			'health'      => '{}',
			'settings'    => '{}',
		);

		$this->assertSame( 1, $wpdb->insert( $this->schema->table( 'channels' ), $row ) );

		$suppress = $wpdb->suppress_errors( true );
		$second   = $wpdb->insert( $this->schema->table( 'channels' ), $row );
		$wpdb->suppress_errors( $suppress );

		$this->assertFalse( $second );
	}

	public function test_install_is_idempotent(): void {
		$this->schema->install();
		$this->assertCount( 18, $this->columns( 'jobs' ) );
		$this->assertSame( array(), $this->schema->pending_changes() );
	}

	public function test_channels_omitted_json_columns_store_empty_string(): void {
		global $wpdb;
		$wpdb->insert(
			$this->schema->table( 'channels' ),
			array(
				'type'      => 'facebook_page',
				'alias'     => 'Principal',
				'remote_id' => '1234567890',
			)
		);

		$channel = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->schema->table( 'channels' ) . ' WHERE remote_id = %s',
				'1234567890'
			)
		);

		$this->assertSame( '', $channel->scopes );
		$this->assertSame( '', $channel->health );
		$this->assertSame( '', $channel->settings );
	}
}

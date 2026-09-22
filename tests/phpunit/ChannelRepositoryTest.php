<?php
/**
 * @package Voceador
 */

use Voceador\ChannelRepository;
use Voceador\Channels\Channel;
use Voceador\Crypto;
use Voceador\Schema;

class ChannelRepositoryTest extends WP_UnitTestCase {

	private ChannelRepository $repo;
	private Crypto $crypto;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->crypto = new Crypto( str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );
		$this->repo   = new ChannelRepository( $wpdb, new Schema( $wpdb ), $this->crypto );
	}

	private function page( array $overrides = array() ): array {
		return array_merge(
			array(
				'type'        => 'facebook_page',
				'alias'       => 'Principal',
				'remote_id'   => '1001',
				'remote_name' => 'Mi Página',
				'credentials' => array( 'access_token' => 'EAAsecreto' ),
				'scopes'      => array( 'pages_manage_posts' ),
			),
			$overrides
		);
	}

	public function test_insert_and_find_roundtrip(): void {
		$id = $this->repo->insert( $this->page() );
		$this->assertIsInt( $id );

		$channel = $this->repo->find( $id );
		$this->assertInstanceOf( Channel::class, $channel );
		$this->assertSame( 'Principal', $channel->alias );
		$this->assertSame( 'EAAsecreto', $channel->credential( 'access_token' ) );
		$this->assertSame( array( 'pages_manage_posts' ), $channel->scopes );
		$this->assertSame( 'active', $channel->status );
		$this->assertSame( 'manual', $channel->connection_method );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} /', $channel->created_at );
	}

	public function test_credentials_are_stored_encrypted(): void {
		global $wpdb;
		$id  = $this->repo->insert( $this->page() );
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT credentials FROM ' . ( new Schema( $wpdb ) )->table( 'channels' ) . ' WHERE id = %d', $id ) );

		$this->assertStringStartsWith( 'v1:', $raw );
		$this->assertStringNotContainsString( 'EAAsecreto', $raw );
	}

	public function test_insert_rejects_duplicates_and_missing_fields(): void {
		$this->repo->insert( $this->page() );

		$dup = $this->repo->insert( $this->page( array( 'alias' => 'Otra' ) ) );
		$this->assertInstanceOf( WP_Error::class, $dup );
		$this->assertSame( 'voceador_channel_exists', $dup->get_error_code() );

		$bad = $this->repo->insert( array( 'type' => 'facebook_page' ) );
		$this->assertSame( 'voceador_channel_invalid', $bad->get_error_code() );
	}

	public function test_find_by_remote_and_missing(): void {
		$id = $this->repo->insert( $this->page() );

		$this->assertSame( $id, $this->repo->find_by_remote( 'facebook_page', '1001' )->id );
		$this->assertNull( $this->repo->find_by_remote( 'facebook_page', '9999' ) );
		$this->assertNull( $this->repo->find( 424242 ) );
	}

	public function test_all_and_active_filters(): void {
		$a = $this->repo->insert( $this->page() );
		$b = $this->repo->insert(
			$this->page(
				array(
					'remote_id' => '1002',
					'alias'     => 'B',
				)
			)
		);
		$this->repo->insert(
			$this->page(
				array(
					'type'      => 'instagram_account',
					'remote_id' => '2001',
					'alias'     => 'IG',
				)
			)
		);
		$this->repo->set_status( $b, 'paused' );

		$this->assertCount( 3, $this->repo->all() );
		$this->assertCount( 2, $this->repo->all( array( 'type' => 'facebook_page' ) ) );
		$this->assertCount( 1, $this->repo->all( array( 'status' => 'paused' ) ) );

		$active_fb = $this->repo->active( 'facebook_page' );
		$this->assertCount( 1, $active_fb );
		$this->assertSame( $a, $active_fb[0]->id );
		$this->assertCount( 2, $this->repo->active() );
	}

	public function test_update_reencrypts_credentials_and_keeps_others(): void {
		$id = $this->repo->insert( $this->page() );

		$this->assertTrue(
			$this->repo->update(
				$id,
				array(
					'alias'       => 'Renombrada',
					'credentials' => array( 'access_token' => 'EAAnuevo' ),
				)
			)
		);

		$channel = $this->repo->find( $id );
		$this->assertSame( 'Renombrada', $channel->alias );
		$this->assertSame( 'EAAnuevo', $channel->credential( 'access_token' ) );
		$this->assertSame( '1001', $channel->remote_id );
	}

	public function test_set_status_records_health(): void {
		$id = $this->repo->insert( $this->page() );

		$this->assertTrue( $this->repo->set_status( $id, 'paused', array( 'message' => 'Token inválido' ) ) );

		$channel = $this->repo->find( $id );
		$this->assertSame( 'paused', $channel->status );
		$this->assertSame( 'Token inválido', $channel->health['message'] );
		$this->assertNotNull( $channel->health_checked_at );
	}

	public function test_undecryptable_credentials_mark_channel_in_error(): void {
		global $wpdb;
		$id = $this->repo->insert( $this->page() );

		$other   = new ChannelRepository( $wpdb, new Schema( $wpdb ), new Crypto( str_repeat( 'z', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ) );
		$channel = $other->find( $id );

		$this->assertSame( 'error', $channel->status );
		$this->assertSame( 'credentials', $channel->health['error'] );
		$this->assertSame( array(), $channel->credentials );
	}

	public function test_delete(): void {
		$id = $this->repo->insert( $this->page() );
		$this->assertTrue( $this->repo->delete( $id ) );
		$this->assertNull( $this->repo->find( $id ) );
		$this->assertFalse( $this->repo->delete( $id ) );
	}
}

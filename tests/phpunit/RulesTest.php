<?php
/**
 * @package Voceador
 */

use Voceador\ChannelRepository;
use Voceador\Channels\Channel;
use Voceador\Crypto;
use Voceador\Rules;
use Voceador\Schema;
use Voceador\Settings;

class RulesTest extends WP_UnitTestCase {

	private Rules $rules;
	private ChannelRepository $channels;
	private int $fb;
	private int $pages_only;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		delete_option( VOCEADOR_PREFIX . Settings::OPTION );
		$this->channels   = new ChannelRepository( $wpdb, new Schema( $wpdb ), new Crypto( str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ) );
		$this->rules      = new Rules( $this->channels, new Settings() );
		$this->fb         = $this->channels->insert(
			array(
				'type'      => 'facebook_page',
				'alias'     => 'A',
				'remote_id' => '1',
			)
		);
		$this->pages_only = $this->channels->insert(
			array(
				'type'      => 'facebook_page',
				'alias'     => 'B',
				'remote_id' => '2',
				'settings'  => array( 'rules' => array( 'post_types' => array( 'page' ) ) ),
			)
		);
		$this->channels->insert(
			array(
				'type'      => 'facebook_page',
				'alias'     => 'C',
				'remote_id' => '3',
				'status'    => 'paused',
			)
		);
	}

	private function ids( array $channels ): array {
		return array_map( static fn( Channel $c ) => $c->id, $channels );
	}

	public function test_post_type_rules_per_channel(): void {
		$post = get_post( self::factory()->post->create() );
		$page = get_post( self::factory()->post->create( array( 'post_type' => 'page' ) ) );

		$this->assertSame( array( $this->fb ), $this->ids( $this->rules->channels_for( $post ) ) );
		$this->assertSame( array( $this->pages_only ), $this->ids( $this->rules->channels_for( $page ) ) );
	}

	public function test_skip_meta_and_should_publish_filter(): void {
		$post = get_post( self::factory()->post->create() );
		update_post_meta( $post->ID, Rules::META_SKIP, '1' );
		$this->assertSame( array(), $this->rules->channels_for( $post ) );

		delete_post_meta( $post->ID, Rules::META_SKIP );
		add_filter( 'voceador_should_publish', '__return_false' );
		$this->assertSame( array(), $this->rules->channels_for( $post ) );
	}

	public function test_manual_channel_selection_overrides_rules(): void {
		$post = get_post( self::factory()->post->create() );
		update_post_meta( $post->ID, Rules::META_CHANNELS, array( $this->pages_only, 999 ) );

		$this->assertSame( array( $this->pages_only ), $this->ids( $this->rules->channels_for( $post ) ) );
	}

	public function test_target_channels_filter(): void {
		$post = get_post( self::factory()->post->create() );
		add_filter( 'voceador_target_channels', static fn() => array(), 10, 1 );
		$this->assertSame( array(), $this->rules->channels_for( $post ) );
	}
}

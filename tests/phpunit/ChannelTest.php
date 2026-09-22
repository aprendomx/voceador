<?php
/**
 * @package Voceador
 */

use Voceador\Channels\Channel;
use Voceador\Channels\Media;
use Voceador\Channels\UsageLimits;

class ChannelTest extends WP_UnitTestCase {

	public function test_hydrates_from_array_with_defaults(): void {
		$channel = new Channel(
			array(
				'id'          => '7',
				'type'        => 'facebook_page',
				'alias'       => 'Principal',
				'remote_id'   => '123',
				'credentials' => array( 'access_token' => 'EAAx' ),
				'scopes'      => array( 'pages_manage_posts' ),
			)
		);

		$this->assertSame( 7, $channel->id );
		$this->assertSame( 'active', $channel->status );
		$this->assertSame( 'manual', $channel->connection_method );
		$this->assertNull( $channel->parent_channel_id );
		$this->assertSame( array(), $channel->settings );
		$this->assertSame( 'EAAx', $channel->credential( 'access_token' ) );
		$this->assertNull( $channel->credential( 'nope' ) );
		$this->assertTrue( $channel->is_active() );
		$this->assertTrue( $channel->has_scope( 'pages_manage_posts' ) );
		$this->assertFalse( $channel->has_scope( 'pages_manage_engagement' ) );
	}

	public function test_paused_channel_is_not_active(): void {
		$channel = new Channel( array( 'status' => 'paused' ) );
		$this->assertFalse( $channel->is_active() );
	}

	public function test_media_and_usage_helpers(): void {
		$this->assertTrue( ( new Media() )->is_empty() );
		$this->assertFalse( ( new Media( '/tmp/a.jpg' ) )->is_empty() );
		$this->assertTrue( ( new UsageLimits( 10, 25 ) )->has_capacity() );
		$this->assertFalse( ( new UsageLimits( 25, 25 ) )->has_capacity() );
	}
}

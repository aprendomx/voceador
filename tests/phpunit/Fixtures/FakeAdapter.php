<?php
/**
 * @package Voceador
 */

namespace Voceador\Tests\Fixtures;

use Voceador\Channels\Channel;
use Voceador\Channels\ChannelAdapter;
use Voceador\Channels\HealthReport;
use Voceador\Channels\Media;
use Voceador\Channels\Payload;
use Voceador\Channels\RemoteResult;
use Voceador\Channels\RemoteStatus;
use Voceador\Channels\UsageLimits;

class FakeAdapter implements ChannelAdapter {

	public array $calls = array();

	public static function type(): string {
		return 'fake';
	}

	public static function label(): string {
		return 'Canal de prueba';
	}

	public function validate_credentials( Channel $c ): HealthReport {
		$this->calls[] = 'validate';
		return new HealthReport( true, 'ok', array( 'scope_a' ) );
	}

	public function prepare_media( Channel $c, \WP_Post $p ): Media|\WP_Error {
		$this->calls[] = 'prepare';
		return new Media( '', 'https://example.com/img.jpg' );
	}

	public function publish( Channel $c, Payload $payload ): RemoteResult|\WP_Error {
		$this->calls[] = 'publish';
		return new RemoteResult( 'remote-1', 'https://example.com/p/1' );
	}

	public function comment( Channel $c, string $remote_id, string $text ): RemoteResult|\WP_Error {
		$this->calls[] = 'comment';
		return new RemoteResult( 'comment-1' );
	}

	public function status( Channel $c, string $remote_id ): RemoteStatus {
		return new RemoteStatus( true );
	}

	public function usage_limits( Channel $c ): ?UsageLimits {
		return null;
	}

	public function settings_schema(): array {
		return array();
	}
}

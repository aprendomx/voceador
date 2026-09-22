<?php
/**
 * @package Voceador
 */

use Voceador\Channels\ChannelRegistry;
use Voceador\Tests\Fixtures\FakeAdapter;

class ChannelRegistryTest extends WP_UnitTestCase {

	public function test_register_and_resolve(): void {
		$registry = new ChannelRegistry();
		$registry->register( 'fake', static fn() => new FakeAdapter() );

		$this->assertTrue( $registry->has( 'fake' ) );
		$this->assertSame( array( 'fake' => 'Canal de prueba' ), $registry->types() );
		$this->assertInstanceOf( FakeAdapter::class, $registry->adapter( 'fake' ) );
		$this->assertSame( $registry->adapter( 'fake' ), $registry->adapter( 'fake' ), 'La instancia se cachea.' );
	}

	public function test_unknown_type_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		( new ChannelRegistry() )->adapter( 'nope' );
	}

	public function test_filter_can_add_types(): void {
		add_filter(
			'voceador_channel_types',
			static function ( array $types ): array {
				$types['fake'] = static fn() => new FakeAdapter();
				return $types;
			}
		);

		$registry = new ChannelRegistry();

		$this->assertTrue( $registry->has( 'fake' ) );
		$this->assertInstanceOf( FakeAdapter::class, $registry->adapter( 'fake' ) );
	}

	public function test_factory_must_return_an_adapter(): void {
		$registry = new ChannelRegistry( array( 'bad' => static fn() => new stdClass() ) );
		$this->expectException( UnexpectedValueException::class );
		$registry->adapter( 'bad' );
	}
}

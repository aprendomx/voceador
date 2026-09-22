<?php
/**
 * @package Voceador
 */

class BootstrapTest extends WP_UnitTestCase {

	public function test_constants_are_defined(): void {
		$this->assertSame( 'voceador_', VOCEADOR_PREFIX );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', VOCEADOR_VERSION );
		$this->assertFileExists( VOCEADOR_FILE );
		$this->assertSame( dirname( VOCEADOR_FILE ), VOCEADOR_DIR );
	}

	public function test_autoloader_ignores_unknown_classes(): void {
		$this->assertFalse( class_exists( 'Voceador\\NoExiste' ) );
		$this->assertFalse( class_exists( 'OtroNamespace\\Clase' ) );
	}

	public function test_autoloader_resolves_plugin_classes(): void {
		$this->assertTrue( class_exists( 'Voceador\\Schema' ) );
		$this->assertSame(
			VOCEADOR_DIR . '/src/Schema.php',
			( new ReflectionClass( \Voceador\Schema::class ) )->getFileName()
		);
	}

	public function test_global_trigger_is_unhooked_in_tests(): void {
		$trigger = \Voceador\Plugin::boot()->get( \Voceador\Trigger::class );

		$this->assertFalse( has_action( 'transition_post_status', array( $trigger, 'on_transition' ) ) );
	}
}

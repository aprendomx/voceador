<?php
/**
 * Arranque de PHPUnit: carga la suite de tests de WordPress y el plugin.
 *
 * @package Voceador
 */

$voceador_root = dirname( __DIR__ );

require_once $voceador_root . '/vendor/autoload.php';

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $voceador_root . '/vendor/yoast/phpunit-polyfills' );
}

$voceador_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $voceador_tests_dir ) {
	$voceador_tests_dir = '/wordpress-phpunit';
}

if ( ! file_exists( $voceador_tests_dir . '/includes/functions.php' ) ) {
	echo "No se encontró la suite de tests de WordPress en {$voceador_tests_dir}. Ejecuta los tests con `npm run test`." . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

require_once $voceador_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $voceador_root ): void {
		require $voceador_root . '/voceador.php';
		require $voceador_root . '/tests/phpunit/Fixtures/FakeAdapter.php';
	}
);

require $voceador_tests_dir . '/includes/bootstrap.php';

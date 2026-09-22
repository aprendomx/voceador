<?php
/**
 * Constantes y autoloader compartidos por voceador.php y uninstall.php.
 *
 * @package Voceador
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'VOCEADOR_VERSION' ) ) {
	define( 'VOCEADOR_VERSION', '0.1.0' );
	define( 'VOCEADOR_PREFIX', 'voceador_' );
	define( 'VOCEADOR_FILE', __DIR__ . '/voceador.php' );
	define( 'VOCEADOR_DIR', __DIR__ );

	spl_autoload_register(
		static function ( string $class_name ): void {
			$namespace = 'Voceador\\';
			if ( 0 !== strncmp( $class_name, $namespace, strlen( $namespace ) ) ) {
				return;
			}

			$relative = substr( $class_name, strlen( $namespace ) );
			$file     = VOCEADOR_DIR . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	);
}//end if

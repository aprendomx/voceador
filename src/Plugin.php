<?php
/**
 * Contenedor del plugin.
 *
 * @package Voceador
 */

namespace Voceador;

/**
 * Instancia los servicios una sola vez y registra los hooks.
 */
final class Plugin {

	/**
	 * Instancia única del contenedor.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Esquema de datos.
	 *
	 * @var Schema
	 */
	private Schema $schema;

	/**
	 * Instalador.
	 *
	 * @var Installer
	 */
	private Installer $installer;

	/**
	 * Constructor.
	 */
	private function __construct() {
		global $wpdb;

		$this->schema    = new Schema( $wpdb );
		$this->installer = new Installer( $this->schema );
	}

	/**
	 * Arranca el plugin. Llamarlo varias veces devuelve la misma instancia.
	 *
	 * @return Plugin
	 */
	public static function boot(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register_hooks();
		}

		return self::$instance;
	}

	/**
	 * Esquema de datos.
	 *
	 * @return Schema
	 */
	public function schema(): Schema {
		return $this->schema;
	}

	/**
	 * Instalador.
	 *
	 * @return Installer
	 */
	public function installer(): Installer {
		return $this->installer;
	}

	/**
	 * Registra los hooks del núcleo.
	 */
	private function register_hooks(): void {
		add_action( 'init', array( $this->installer, 'maybe_install' ), 0 );
		add_action( 'wp_initialize_site', array( $this->installer, 'on_new_site' ), 20 );
	}
}

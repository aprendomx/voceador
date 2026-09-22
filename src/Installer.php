<?php
/**
 * Instalación del plugin por sitio.
 *
 * @package Voceador
 */

namespace Voceador;

/**
 * Decide cuándo crear el esquema y conceder la capacidad.
 */
final class Installer {

	/**
	 * Capacidad necesaria para gestionar los ajustes del plugin.
	 */
	public const CAPABILITY = 'voceador_manage';

	/**
	 * Esquema de datos.
	 *
	 * @var Schema
	 */
	private Schema $schema;

	/**
	 * Constructor.
	 *
	 * @param Schema $schema Esquema de datos.
	 */
	public function __construct( Schema $schema ) {
		$this->schema = $schema;
	}

	/**
	 * Instala o actualiza el sitio actual si su versión de esquema no es la vigente.
	 */
	public function maybe_install(): void {
		if ( Schema::DB_VERSION === get_option( VOCEADOR_PREFIX . 'db_version' ) ) {
			return;
		}

		$this->schema->install();

		$role = get_role( 'administrator' );
		if ( null !== $role ) {
			$role->add_cap( self::CAPABILITY );
		}

		update_option( VOCEADOR_PREFIX . 'db_version', Schema::DB_VERSION, true );
	}

	/**
	 * Hook de activación.
	 *
	 * En una activación en red solo se instala el sitio actual; los demás
	 * se instalan de forma perezosa en su primer `init`.
	 *
	 * @param bool $network_wide Si la activación es en red.
	 */
	public function activate( bool $network_wide ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$this->maybe_install();
	}

	/**
	 * Instala un sitio recién creado cuando el plugin está activo en red.
	 *
	 * @param \WP_Site $site Sitio nuevo.
	 */
	public function on_new_site( \WP_Site $site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( plugin_basename( VOCEADOR_FILE ) ) ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );
		$this->maybe_install();
		restore_current_blog();
	}
}

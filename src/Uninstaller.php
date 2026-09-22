<?php
/**
 * Desinstalación del plugin.
 *
 * @package Voceador
 */

namespace Voceador;

/**
 * Elimina cuanto creó el plugin.
 */
final class Uninstaller {

	/**
	 * Nombres cortos de las opciones del plugin.
	 */
	public const OPTIONS = array( 'db_version', 'app', 'settings', 'link_in_bio', 'wizard', 'notices', 'crypto_seed', 'cron_last_run' );

	/**
	 * Metas de post del plugin.
	 */
	public const META_KEYS = array(
		'_voceador_message_fb',
		'_voceador_message_ig',
		'_voceador_skip',
		'_voceador_channels',
		'_voceador_ig_image',
		'_voceador_ig_cache',
		'_voceador_first_published',
	);

	/**
	 * Hooks de cron del plugin.
	 */
	public const CRON_HOOKS = array( 'check_tokens', 'refresh_ig_tokens', 'purge_log', 'sweep' );

	/**
	 * Subcarpeta de uploads donde se guardan las imágenes generadas.
	 */
	public const UPLOADS_SUBDIR = 'voceador';

	/**
	 * Limpia el sitio, o todos los sitios de la red en Multisite.
	 */
	public static function run(): void {
		if ( ! is_multisite() ) {
			self::clean_site();
			wp_cache_flush();
			return;
		}

		global $wpdb;

		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			try {
				self::clean_site();
			} finally {
				restore_current_blog();
			}
		}

		delete_site_option( VOCEADOR_PREFIX . 'network_defaults' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- No hay API para borrar site transients por prefijo.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
				$wpdb->esc_like( '_site_transient_' . VOCEADOR_PREFIX ) . '%',
				$wpdb->esc_like( '_site_transient_timeout_' . VOCEADOR_PREFIX ) . '%'
			)
		);

		wp_cache_flush();
	}

	/**
	 * Limpia el sitio actual.
	 */
	public static function clean_site(): void {
		global $wpdb;

		// Se lee antes de borrar las opciones.
		$settings    = get_option( VOCEADOR_PREFIX . 'settings', array() );
		$delete_meta = is_array( $settings ) && ! empty( $settings['uninstall']['delete_meta'] );

		( new Schema( $wpdb ) )->drop();

		foreach ( self::OPTIONS as $option ) {
			delete_option( VOCEADOR_PREFIX . $option );
		}

		// No forman parte de OPTIONS porque no son ajustes del usuario: 'installing' es un
		// lock interno de Installer y 'network_defaults' se escribe con update_site_option()
		// (en single site esa función escribe en wp_options, igual que un option normal).
		delete_option( VOCEADOR_PREFIX . 'installing' );
		delete_option( VOCEADOR_PREFIX . 'network_defaults' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- No hay API para borrar transients por prefijo.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . VOCEADOR_PREFIX ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . VOCEADOR_PREFIX ) . '%'
			)
		);

		foreach ( wp_roles()->role_objects as $role ) {
			$role->remove_cap( Installer::CAPABILITY );
		}

		foreach ( self::CRON_HOOKS as $hook ) {
			wp_unschedule_hook( VOCEADOR_PREFIX . $hook );
		}

		if ( $delete_meta ) {
			foreach ( self::META_KEYS as $meta_key ) {
				delete_post_meta_by_key( $meta_key );
			}
		}

		self::delete_generated_images();
	}

	/**
	 * Borra la carpeta de imágenes generadas.
	 */
	private static function delete_generated_images(): void {
		$dir = wp_upload_dir()['basedir'] . '/' . self::UPLOADS_SUBDIR;
		if ( ! is_dir( $dir ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();

		global $wp_filesystem;
		if ( $wp_filesystem instanceof \WP_Filesystem_Base ) {
			$wp_filesystem->delete( $dir, true );
		}
	}
}

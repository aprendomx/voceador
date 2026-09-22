<?php
/**
 * Esquema de base de datos del plugin.
 *
 * @package Voceador
 */

namespace Voceador;

/**
 * Conoce los nombres de las tablas y sabe crearlas y eliminarlas.
 */
final class Schema {

	/**
	 * Versión del esquema. Súbela cada vez que cambie el SQL de install().
	 */
	public const DB_VERSION = '1';

	/**
	 * Nombres cortos de las tablas del plugin.
	 */
	public const TABLES = array( 'channels', 'jobs', 'log' );

	/**
	 * Conexión a la base de datos.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $wpdb Conexión a la base de datos.
	 */
	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Nombre completo de una tabla para el sitio actual.
	 *
	 * @param string $name Nombre corto: channels, jobs o log.
	 * @return string
	 * @throws \InvalidArgumentException Si la tabla no pertenece al plugin.
	 */
	public function table( string $name ): string {
		if ( ! in_array( $name, self::TABLES, true ) ) {
			throw new \InvalidArgumentException( 'Tabla desconocida: ' . esc_html( $name ) );
		}

		return $this->wpdb->prefix . VOCEADOR_PREFIX . $name;
	}

	/**
	 * Crea o actualiza las tablas.
	 *
	 * `channels.credentials`, `channels.scopes`, `channels.health`, `channels.settings`
	 * y `log.message` son NOT NULL sin valor por defecto porque MySQL no permite DEFAULT
	 * en columnas TEXT/LONGTEXT. Con el sql_mode relajado de WordPress, omitir estas
	 * columnas en un INSERT guarda '' (cadena vacía), y `json_decode('')` devuelve null:
	 * los repositorios deben escribirlas siempre con un literal JSON válido y, al leer,
	 * normalizar '' a array().
	 */
	public function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( $this->statements() as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Sentencias que dbDelta() aplicaría sin ejecutarlas.
	 *
	 * Sirve para comprobar que install() es idempotente: tras instalar, no debe
	 * quedar ningún cambio pendiente.
	 *
	 * @return array Cambios pendientes por tabla, tal cual los devuelve dbDelta().
	 */
	public function pending_changes(): array {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$changes = array();
		foreach ( $this->statements() as $sql ) {
			$changes = array_merge( $changes, dbDelta( $sql, false ) );
		}

		return $changes;
	}

	/**
	 * Migra datos entre versiones del esquema.
	 *
	 * @param string $from Versión de origen.
	 * @param string $to   Versión de destino.
	 */
	public function migrate( string $from, string $to ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $from se usará cuando exista la primera migración.
		switch ( $to ) {
			default:
				// Las migraciones con datos se añaden aquí por versión; hoy no hay ninguna.
				break;
		}
	}

	/**
	 * Sentencias SQL de creación de las tablas del plugin.
	 *
	 * @return array Sentencias CREATE TABLE indexadas por nombre corto de tabla.
	 */
	private function statements(): array {
		$collate  = $this->wpdb->get_charset_collate();
		$channels = $this->table( 'channels' );
		$jobs     = $this->table( 'jobs' );
		$log      = $this->table( 'log' );

		return array(
			'channels' => "CREATE TABLE {$channels} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(40) NOT NULL DEFAULT '',
			alias varchar(190) NOT NULL DEFAULT '',
			remote_id varchar(64) NOT NULL DEFAULT '',
			remote_name varchar(190) NOT NULL DEFAULT '',
			avatar_url text NULL,
			connection_method varchar(20) NOT NULL DEFAULT '',
			parent_channel_id bigint(20) unsigned NULL,
			credentials longtext NOT NULL,
			scopes text NOT NULL,
			token_expires_at datetime NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			health text NOT NULL,
			health_checked_at datetime NULL,
			settings longtext NOT NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY type_remote (type,remote_id),
			KEY type (type),
			KEY status (status)
			) {$collate};",

			'jobs'     => "CREATE TABLE {$jobs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			channel_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			comment_status varchar(20) NOT NULL DEFAULT 'none',
			remote_id varchar(100) NULL,
			remote_url text NULL,
			remote_comment_id varchar(100) NULL,
			container_id varchar(100) NULL,
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			comment_attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			scheduled_at datetime NULL,
			published_at datetime NULL,
			error_code varchar(40) NULL,
			error_message text NULL,
			source varchar(20) NOT NULL DEFAULT 'auto',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY post_channel (post_id,channel_id),
			KEY status (status),
			KEY channel_status (channel_id,status),
			KEY scheduled_at (scheduled_at)
			) {$collate};",

			'log'      => "CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			level varchar(10) NOT NULL DEFAULT 'info',
			channel_id bigint(20) unsigned NULL,
			post_id bigint(20) unsigned NULL,
			job_id bigint(20) unsigned NULL,
			event varchar(60) NOT NULL DEFAULT '',
			message text NOT NULL,
			context longtext NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY channel_created (channel_id,created_at),
			KEY post_id (post_id),
			KEY level (level)
			) {$collate};",
		);
	}

	/**
	 * Elimina las tablas del plugin.
	 */
	public function drop(): void {
		foreach ( self::TABLES as $name ) {
			$table = $this->table( $name );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- El nombre de tabla viene de una lista cerrada.
			$this->wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}
}

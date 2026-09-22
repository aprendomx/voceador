<?php
/**
 * Acceso a la tabla de canales.
 *
 * @package Voceador
 */

namespace Voceador;

use Voceador\Channels\Channel;

/**
 * CRUD de canales. Cifra credentials al escribir y las descifra al leer.
 */
final class ChannelRepository {

	/**
	 * Columnas que se guardan como JSON.
	 */
	private const JSON_COLUMNS = array( 'scopes', 'health', 'settings' );

	/**
	 * Columnas escribibles.
	 */
	private const COLUMNS = array(
		'type',
		'alias',
		'remote_id',
		'remote_name',
		'avatar_url',
		'connection_method',
		'parent_channel_id',
		'credentials',
		'scopes',
		'token_expires_at',
		'status',
		'health',
		'health_checked_at',
		'settings',
	);

	/**
	 * Conexión a la base de datos.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Esquema.
	 *
	 * @var Schema
	 */
	private Schema $schema;

	/**
	 * Cifrado.
	 *
	 * @var Crypto
	 */
	private Crypto $crypto;

	/**
	 * Constructor.
	 *
	 * @param \wpdb  $wpdb   Conexión.
	 * @param Schema $schema Esquema.
	 * @param Crypto $crypto Cifrado.
	 */
	public function __construct( \wpdb $wpdb, Schema $schema, Crypto $crypto ) {
		$this->wpdb   = $wpdb;
		$this->schema = $schema;
		$this->crypto = $crypto;
	}

	/**
	 * Crea un canal.
	 *
	 * @param array $data Campos; credentials, scopes, health y settings como arrays.
	 * @return int|\WP_Error Id nuevo, o voceador_channel_invalid / voceador_channel_exists.
	 */
	public function insert( array $data ): int|\WP_Error {
		foreach ( array( 'type', 'alias', 'remote_id' ) as $required ) {
			if ( empty( $data[ $required ] ) ) {
				return new \WP_Error( 'voceador_channel_invalid', sprintf( /* translators: %s: nombre del campo */ __( 'Falta el campo %s del canal.', 'voceador' ), $required ) );
			}
		}

		if ( null !== $this->find_by_remote( (string) $data['type'], (string) $data['remote_id'] ) ) {
			return new \WP_Error( 'voceador_channel_exists', __( 'Ese canal ya está conectado.', 'voceador' ) );
		}

		$now = current_time( 'mysql', true );
		$row = $this->serialize(
			array_merge(
				array(
					'connection_method' => 'manual',
					'status'            => 'active',
					'credentials'       => array(),
					'scopes'            => array(),
					'health'            => array(),
					'settings'          => array(),
				),
				$data
			)
		);

		$row['created_at'] = $now;
		$row['updated_at'] = $now;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $this->wpdb->insert( $this->table(), $row );

		if ( false === $inserted ) {
			return new \WP_Error( 'voceador_channel_exists', __( 'No se pudo guardar el canal.', 'voceador' ) );
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Actualiza campos de un canal.
	 *
	 * @param int   $id   Id.
	 * @param array $data Campos a cambiar (misma forma que insert()).
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		$row = $this->serialize( $data );
		if ( empty( $row ) ) {
			return false;
		}
		$row['updated_at'] = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $this->wpdb->update( $this->table(), $row, array( 'id' => $id ) );

		return false !== $updated;
	}

	/**
	 * Busca por id.
	 *
	 * @param int $id Id.
	 * @return Channel|null
	 */
	public function find( int $id ): ?Channel {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- El nombre de tabla viene de Schema::table(), no de entrada de usuario.
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Busca por tipo e id remoto.
	 *
	 * @param string $type      Tipo.
	 * @param string $remote_id Id remoto.
	 * @return Channel|null
	 */
	public function find_by_remote( string $type, string $remote_id ): ?Channel {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- El nombre de tabla viene de Schema::table(), no de entrada de usuario.
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->table()} WHERE type = %s AND remote_id = %s", $type, $remote_id ), ARRAY_A );

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Lista canales.
	 *
	 * @param array $args Filtros: type, status.
	 * @return Channel[]
	 */
	public function all( array $args = array() ): array {
		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $args['type'] ) ) {
			$where[]  = 'type = %s';
			$values[] = (string) $args['type'];
		}
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = (string) $args['status'];
		}

		$sql = "SELECT * FROM {$this->table()} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id ASC';
		if ( $values ) {
			$sql = $this->wpdb->prepare( $sql, ...$values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		return array_map( array( $this, 'hydrate' ), $rows ? $rows : array() );
	}

	/**
	 * Canales activos.
	 *
	 * @param string|null $type Tipo, o null para todos.
	 * @return Channel[]
	 */
	public function active( ?string $type = null ): array {
		$args = array( 'status' => 'active' );
		if ( null !== $type ) {
			$args['type'] = $type;
		}

		return $this->all( $args );
	}

	/**
	 * Cambia el estado y registra el resultado de salud.
	 *
	 * @param int    $id     Id.
	 * @param string $status active, paused, error o disabled.
	 * @param array  $health Datos de salud a guardar.
	 * @return bool
	 */
	public function set_status( int $id, string $status, array $health = array() ): bool {
		return $this->update(
			$id,
			array(
				'status'            => $status,
				'health'            => $health,
				'health_checked_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Borra un canal.
	 *
	 * @param int $id Id.
	 * @return bool true si existía.
	 */
	public function delete( int $id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deleted = $this->wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );

		return 1 === $deleted;
	}

	/**
	 * Nombre de la tabla.
	 *
	 * @return string
	 */
	private function table(): string {
		return $this->schema->table( 'channels' );
	}

	/**
	 * Convierte una fila en entidad, descifrando credenciales.
	 *
	 * @param array $row Fila.
	 * @return Channel
	 */
	private function hydrate( array $row ): Channel {
		foreach ( self::JSON_COLUMNS as $column ) {
			$decoded        = json_decode( (string) ( $row[ $column ] ?? '' ), true );
			$row[ $column ] = is_array( $decoded ) ? $decoded : array();
		}

		$plain = '' === (string) $row['credentials'] ? '{}' : $this->crypto->decrypt( (string) $row['credentials'] );

		if ( is_wp_error( $plain ) ) {
			$row['credentials']     = array();
			$row['status']          = 'error';
			$row['health']['error'] = 'credentials';
		} else {
			$decoded            = json_decode( $plain, true );
			$row['credentials'] = is_array( $decoded ) ? $decoded : array();
		}

		return new Channel( $row );
	}

	/**
	 * Prepara un array de campos para escribirlo.
	 *
	 * @param array $data Campos.
	 * @return array Solo columnas conocidas, con JSON y cifrado aplicados.
	 */
	private function serialize( array $data ): array {
		$row = array();

		foreach ( self::COLUMNS as $column ) {
			if ( ! array_key_exists( $column, $data ) ) {
				continue;
			}

			$value = $data[ $column ];

			if ( 'credentials' === $column ) {
				$value = $this->crypto->encrypt( (string) wp_json_encode( is_array( $value ) ? $value : array() ) );
			} elseif ( in_array( $column, self::JSON_COLUMNS, true ) ) {
				$value = (string) wp_json_encode( is_array( $value ) ? $value : array() );
			}

			$row[ $column ] = $value;
		}

		return $row;
	}
}

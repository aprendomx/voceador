<?php
/**
 * Log del plugin en tabla propia.
 *
 * @package Voceador
 */

namespace Voceador;

/**
 * Escribe eventos en voceador_log. Nunca registra tokens ni secretos.
 */
final class Logger {

	/**
	 * Niveles y su severidad.
	 */
	public const LEVELS = array(
		'debug'   => 0,
		'info'    => 1,
		'warning' => 2,
		'error'   => 3,
	);

	/**
	 * Claves de contexto que van a columnas propias.
	 */
	private const ID_COLUMNS = array( 'channel_id', 'post_id', 'job_id' );

	/**
	 * Patrón de claves sensibles.
	 */
	private const SENSITIVE_KEY = '/token|secret|password|authorization|signature/i';

	/**
	 * Patrones sensibles dentro de cadenas.
	 */
	private const SENSITIVE_IN_STRING = array(
		'/((?:access_token|client_secret|app_secret|fb_exchange_token)=)[^&\s]+/i' => '$1[redactado]',
		'/EAA[A-Za-z0-9]{20,}/' => '[redactado]',
	);

	/**
	 * Severidad mínima que se escribe.
	 *
	 * @var int
	 */
	private int $min_severity;

	/**
	 * Constructor.
	 *
	 * @param \wpdb  $wpdb      Conexión.
	 * @param Schema $schema    Esquema.
	 * @param string $min_level Nivel mínimo a registrar.
	 */
	public function __construct(
		private \wpdb $wpdb,
		private Schema $schema,
		string $min_level = 'info'
	) {
		$this->min_severity = self::LEVELS[ $min_level ] ?? self::LEVELS['info'];
	}

	/**
	 * Registra un evento.
	 *
	 * @param string $level   debug, info, warning o error (desconocido = error).
	 * @param string $event   Identificador corto del evento.
	 * @param string $message Mensaje legible.
	 * @param array  $context Datos adicionales; channel_id, post_id y job_id van a columnas.
	 */
	public function log( string $level, string $event, string $message, array $context = array() ): void {
		if ( ! isset( self::LEVELS[ $level ] ) ) {
			$level = 'error';
		}
		if ( self::LEVELS[ $level ] < $this->min_severity ) {
			return;
		}

		$row = array(
			'created_at' => current_time( 'mysql', true ),
			'level'      => $level,
			'event'      => substr( $event, 0, 60 ),
			'message'    => $message,
		);

		foreach ( self::ID_COLUMNS as $column ) {
			if ( isset( $context[ $column ] ) ) {
				$row[ $column ] = (int) $context[ $column ];
			}
			unset( $context[ $column ] );
		}

		$row['context'] = (string) wp_json_encode( self::redact( $context ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->wpdb->insert( $this->table(), $row );
	}

	/**
	 * Evento de depuración.
	 *
	 * @param string $event   Evento.
	 * @param string $message Mensaje.
	 * @param array  $context Contexto.
	 */
	public function debug( string $event, string $message, array $context = array() ): void {
		$this->log( 'debug', $event, $message, $context );
	}

	/**
	 * Evento informativo.
	 *
	 * @param string $event   Evento.
	 * @param string $message Mensaje.
	 * @param array  $context Contexto.
	 */
	public function info( string $event, string $message, array $context = array() ): void {
		$this->log( 'info', $event, $message, $context );
	}

	/**
	 * Aviso.
	 *
	 * @param string $event   Evento.
	 * @param string $message Mensaje.
	 * @param array  $context Contexto.
	 */
	public function warning( string $event, string $message, array $context = array() ): void {
		$this->log( 'warning', $event, $message, $context );
	}

	/**
	 * Error.
	 *
	 * @param string $event   Evento.
	 * @param string $message Mensaje.
	 * @param array  $context Contexto.
	 */
	public function error( string $event, string $message, array $context = array() ): void {
		$this->log( 'error', $event, $message, $context );
	}

	/**
	 * Elimina secretos de un contexto, de forma recursiva.
	 *
	 * @param array $context Contexto.
	 * @return array
	 */
	public static function redact( array $context ): array {
		$clean = array();

		foreach ( $context as $key => $value ) {
			if ( is_string( $key ) && ( 'code' === $key || preg_match( self::SENSITIVE_KEY, $key ) ) ) {
				$clean[ $key ] = '[redactado]';
				continue;
			}

			if ( is_array( $value ) ) {
				$clean[ $key ] = self::redact( $value );
			} elseif ( is_string( $value ) ) {
				$clean[ $key ] = preg_replace( array_keys( self::SENSITIVE_IN_STRING ), array_values( self::SENSITIVE_IN_STRING ), $value );
			} else {
				$clean[ $key ] = $value;
			}
		}

		return $clean;
	}

	/**
	 * Últimos eventos, más recientes primero.
	 *
	 * @param array $args Filtros: level, channel_id, post_id, job_id, limit.
	 * @return array[] Filas con context decodificado.
	 */
	public function recent( array $args = array() ): array {
		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $args['level'] ) ) {
			$where[]  = 'level = %s';
			$values[] = (string) $args['level'];
		}
		foreach ( self::ID_COLUMNS as $column ) {
			if ( isset( $args[ $column ] ) ) {
				$where[]  = "{$column} = %d";
				$values[] = (int) $args[ $column ];
			}
		}
		$values[] = (int) ( $args['limit'] ?? 50 );

		$sql = "SELECT * FROM {$this->table()} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- El nombre de tabla viene de Schema::table(), no de entrada de usuario; el número de reemplazos depende de los filtros y el sniff no lo puede contar estáticamente.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$values ), ARRAY_A );
		$rows = $rows ? $rows : array();

		foreach ( $rows as &$row ) {
			$decoded        = json_decode( (string) $row['context'], true );
			$row['context'] = is_array( $decoded ) ? $decoded : array();
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Nombre de la tabla.
	 *
	 * @return string
	 */
	private function table(): string {
		return $this->schema->table( 'log' );
	}
}

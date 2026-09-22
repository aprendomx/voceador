<?php
/**
 * Acceso a la tabla de trabajos.
 *
 * @package Voceador
 */

namespace Voceador;

/**
 * Crea trabajos de forma idempotente y gestiona sus transiciones de estado.
 */
final class JobRepository {

	/**
	 * Estados posibles.
	 */
	public const STATUSES = array( 'pending', 'running', 'published', 'failed', 'rate_limited', 'skipped' );

	/**
	 * Estados desde los que se puede reclamar un trabajo.
	 */
	private const CLAIMABLE = array( 'pending', 'failed', 'rate_limited' );

	/**
	 * Constructor.
	 *
	 * @param \wpdb  $wpdb   Conexión.
	 * @param Schema $schema Esquema.
	 */
	public function __construct(
		private \wpdb $wpdb,
		private Schema $schema
	) {}

	/**
	 * Crea el trabajo post+canal si no existe.
	 *
	 * @param int         $post_id      Post.
	 * @param int         $channel_id   Canal.
	 * @param string      $source       auto, manual, cli o test.
	 * @param string|null $scheduled_at Fecha UTC (MySQL) de ejecución, o null para "cuanto antes".
	 * @return int|null Id nuevo, o null si ya existía.
	 */
	public function create_if_absent( int $post_id, int $channel_id, string $source = 'auto', ?string $scheduled_at = null ): ?int {
		$now = current_time( 'mysql', true );

		// prepare() convierte null en '' y la columna DATETIME NULL acabaría como
		// fecha cero: el NULL se escribe literal en el SQL.
		if ( null === $scheduled_at ) {
			$sql  = "INSERT IGNORE INTO {$this->table()} (post_id, channel_id, source, scheduled_at, created_at, updated_at) VALUES (%d, %d, %s, NULL, %s, %s)";
			$args = array( $post_id, $channel_id, $source, $now, $now );
		} else {
			$sql  = "INSERT IGNORE INTO {$this->table()} (post_id, channel_id, source, scheduled_at, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, %s)";
			$args = array( $post_id, $channel_id, $source, $scheduled_at, $now, $now );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- El nombre de tabla viene de Schema::table(), no de entrada de usuario.
		$this->wpdb->query( $this->wpdb->prepare( $sql, ...$args ) );

		return 1 === $this->wpdb->rows_affected ? (int) $this->wpdb->insert_id : null;
	}

	/**
	 * Busca por id.
	 *
	 * @param int $id Id.
	 * @return Job|null
	 */
	public function find( int $id ): ?Job {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- El nombre de tabla viene de Schema::table(), no de entrada de usuario.
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), ARRAY_A );

		return $row ? new Job( $row ) : null;
	}

	/**
	 * Trabajos de un post.
	 *
	 * @param int $post_id Post.
	 * @return Job[]
	 */
	public function find_for_post( int $post_id ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- El nombre de tabla viene de Schema::table(), no de entrada de usuario.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->table()} WHERE post_id = %d ORDER BY id ASC", $post_id ), ARRAY_A );

		return array_map( static fn( array $row ) => new Job( $row ), $rows ? $rows : array() );
	}

	/**
	 * Trabajo de un post en un canal.
	 *
	 * @param int $post_id    Post.
	 * @param int $channel_id Canal.
	 * @return Job|null
	 */
	public function find_for_post_and_channel( int $post_id, int $channel_id ): ?Job {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- El nombre de tabla viene de Schema::table(), no de entrada de usuario.
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->table()} WHERE post_id = %d AND channel_id = %d", $post_id, $channel_id ), ARRAY_A );

		return $row ? new Job( $row ) : null;
	}

	/**
	 * Reclama un trabajo para ejecutarlo. Solo un proceso puede ganar.
	 *
	 * @param int $id Id.
	 * @return bool true si este proceso lo reclamó.
	 */
	public function claim( int $id ): bool {
		$placeholders = implode( ',', array_fill( 0, count( self::CLAIMABLE ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- El nombre de tabla viene de Schema::table(); el número de reemplazos depende de CLAIMABLE y el sniff no lo puede contar estáticamente.
		$this->wpdb->query( $this->wpdb->prepare( "UPDATE {$this->table()} SET status = 'running', attempts = attempts + 1, updated_at = %s WHERE id = %d AND status IN ({$placeholders})", current_time( 'mysql', true ), $id, ...self::CLAIMABLE ) );

		return 1 === $this->wpdb->rows_affected;
	}

	/**
	 * Marca el trabajo como publicado.
	 *
	 * @param int    $id              Id.
	 * @param string $remote_id       Id remoto de la publicación.
	 * @param string $remote_url      URL pública.
	 * @param bool   $comment_pending Si queda pendiente el comentario.
	 * @return bool true salvo error de consulta.
	 */
	public function mark_published( int $id, string $remote_id, string $remote_url, bool $comment_pending ): bool {
		return $this->set(
			$id,
			array(
				'status'         => 'published',
				'remote_id'      => $remote_id,
				'remote_url'     => $remote_url,
				'published_at'   => current_time( 'mysql', true ),
				'comment_status' => $comment_pending ? 'pending' : 'none',
				'error_code'     => null,
				'error_message'  => null,
			)
		);
	}

	/**
	 * Marca el trabajo como fallido.
	 *
	 * @param int    $id            Id.
	 * @param string $error_code    Clase de error normalizada (más código Graph si se quiere).
	 * @param string $error_message Mensaje para la redacción.
	 * @return bool true salvo error de consulta.
	 */
	public function mark_failed( int $id, string $error_code, string $error_message ): bool {
		return $this->set(
			$id,
			array(
				'status'        => 'failed',
				'error_code'    => $error_code,
				'error_message' => $error_message,
			)
		);
	}

	/**
	 * Deja el trabajo en espera por límite de uso.
	 *
	 * @param int    $id            Id.
	 * @param string $scheduled_at  Cuándo reintentar (UTC, MySQL).
	 * @param string $error_message Explicación.
	 * @return bool true salvo error de consulta.
	 */
	public function mark_rate_limited( int $id, string $scheduled_at, string $error_message ): bool {
		return $this->set(
			$id,
			array(
				'status'        => 'rate_limited',
				'scheduled_at'  => $scheduled_at,
				'error_code'    => 'rate_limited',
				'error_message' => $error_message,
			)
		);
	}

	/**
	 * Devuelve el trabajo a pendiente para reintentarlo más tarde.
	 *
	 * @param int    $id           Id.
	 * @param string $scheduled_at Cuándo (UTC, MySQL).
	 * @return bool true salvo error de consulta.
	 */
	public function release( int $id, string $scheduled_at ): bool {
		return $this->set(
			$id,
			array(
				'status'       => 'pending',
				'scheduled_at' => $scheduled_at,
			)
		);
	}

	/**
	 * Marca el trabajo como omitido.
	 *
	 * @param int    $id     Id.
	 * @param string $reason Motivo.
	 * @return bool true salvo error de consulta.
	 */
	public function mark_skipped( int $id, string $reason ): bool {
		return $this->set(
			$id,
			array(
				'status'        => 'skipped',
				'error_code'    => null,
				'error_message' => $reason,
			)
		);
	}

	/**
	 * Registra el comentario publicado.
	 *
	 * @param int    $id                Id.
	 * @param string $remote_comment_id Id remoto del comentario.
	 * @return bool true salvo error de consulta.
	 */
	public function mark_comment_done( int $id, string $remote_comment_id ): bool {
		return $this->set(
			$id,
			array(
				'comment_status'    => 'done',
				'remote_comment_id' => $remote_comment_id,
			)
		);
	}

	/**
	 * Registra un fallo al comentar sin tocar el estado principal.
	 *
	 * @param int    $id            Id.
	 * @param string $error_message Mensaje.
	 * @return bool
	 */
	public function mark_comment_failed( int $id, string $error_message ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- El nombre de tabla viene de Schema::table(), no de entrada de usuario.
		$this->wpdb->query( $this->wpdb->prepare( "UPDATE {$this->table()} SET comment_status = 'failed', comment_attempts = comment_attempts + 1, error_message = %s, updated_at = %s WHERE id = %d", $error_message, current_time( 'mysql', true ), $id ) );

		return 1 === $this->wpdb->rows_affected;
	}

	/**
	 * Trabajos pendientes cuya hora ya llegó.
	 *
	 * @param int $limit Máximo.
	 * @return Job[]
	 */
	public function due( int $limit = 50 ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- El nombre de tabla viene de Schema::table(), no de entrada de usuario.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->table()} WHERE status = 'pending' AND (scheduled_at IS NULL OR scheduled_at <= %s) ORDER BY scheduled_at IS NULL DESC, scheduled_at ASC, id ASC LIMIT %d", current_time( 'mysql', true ), $limit ), ARRAY_A );

		return array_map( static fn( array $row ) => new Job( $row ), $rows ? $rows : array() );
	}

	/**
	 * Número de trabajos por estado (todos los estados presentes, aunque sea 0).
	 *
	 * @return array<string, int>
	 */
	public function count_by_status(): array {
		$counts = array_fill_keys( self::STATUSES, 0 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- El nombre de tabla viene de Schema::table(), no de entrada de usuario.
		$rows = $this->wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$this->table()} GROUP BY status", ARRAY_A );

		foreach ( $rows ? $rows : array() as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['n'];
		}

		return $counts;
	}

	/**
	 * Nombre de la tabla.
	 *
	 * @return string
	 */
	private function table(): string {
		return $this->schema->table( 'jobs' );
	}

	/**
	 * Actualiza columnas de un trabajo.
	 *
	 * Devuelve false solo si la consulta falló; una repetición idempotente devuelve true.
	 * Quien necesite saber si el trabajo existe debe usar find().
	 *
	 * @param int   $id   Id.
	 * @param array $data Columnas => valores (null permitido).
	 * @return bool
	 */
	private function set( int $id, array $data ): bool {
		$data['updated_at'] = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- El nombre de tabla viene de Schema::table(), no de entrada de usuario.
		$updated = $this->wpdb->update( $this->table(), $data, array( 'id' => $id ) );

		return false !== $updated;
	}
}

<?php
/**
 * Entidad trabajo (post + canal).
 *
 * @package Voceador
 */

namespace Voceador;

/**
 * Estado de la publicación de un post en un canal.
 */
final class Job {

	/**
	 * Id del trabajo.
	 *
	 * @var int
	 */
	public readonly int $id;

	/**
	 * Id del post de origen.
	 *
	 * @var int
	 */
	public readonly int $post_id;

	/**
	 * Id del canal destino.
	 *
	 * @var int
	 */
	public readonly int $channel_id;

	/**
	 * Estado principal (pending, running, published, failed, rate_limited, skipped).
	 *
	 * @var string
	 */
	public readonly string $status;

	/**
	 * Estado del comentario (none, pending, done, failed).
	 *
	 * @var string
	 */
	public readonly string $comment_status;

	/**
	 * Id remoto de la publicación, o null si aún no existe.
	 *
	 * @var string|null
	 */
	public readonly ?string $remote_id;

	/**
	 * URL pública de la publicación, o null si aún no existe.
	 *
	 * @var string|null
	 */
	public readonly ?string $remote_url;

	/**
	 * Id remoto del comentario, o null si aún no existe.
	 *
	 * @var string|null
	 */
	public readonly ?string $remote_comment_id;

	/**
	 * Id del contenedor intermedio (por ejemplo, media container), o null.
	 *
	 * @var string|null
	 */
	public readonly ?string $container_id;

	/**
	 * Intentos de publicación realizados.
	 *
	 * @var int
	 */
	public readonly int $attempts;

	/**
	 * Intentos de comentario realizados.
	 *
	 * @var int
	 */
	public readonly int $comment_attempts;

	/**
	 * Fecha programada de ejecución (UTC, MySQL), o null para "cuanto antes".
	 *
	 * @var string|null
	 */
	public readonly ?string $scheduled_at;

	/**
	 * Fecha de publicación (UTC, MySQL), o null si aún no se publicó.
	 *
	 * @var string|null
	 */
	public readonly ?string $published_at;

	/**
	 * Código de error normalizado del último fallo, o null.
	 *
	 * @var string|null
	 */
	public readonly ?string $error_code;

	/**
	 * Mensaje del último error, o null.
	 *
	 * @var string|null
	 */
	public readonly ?string $error_message;

	/**
	 * Origen del trabajo (auto, manual, cli o test).
	 *
	 * @var string
	 */
	public readonly string $source;

	/**
	 * Fecha de creación (UTC, MySQL).
	 *
	 * @var string
	 */
	public readonly string $created_at;

	/**
	 * Fecha de última actualización (UTC, MySQL).
	 *
	 * @var string
	 */
	public readonly string $updated_at;

	/**
	 * Constructor.
	 *
	 * @param array $row Fila de la tabla.
	 */
	public function __construct( array $row ) {
		$this->id                = (int) ( $row['id'] ?? 0 );
		$this->post_id           = (int) ( $row['post_id'] ?? 0 );
		$this->channel_id        = (int) ( $row['channel_id'] ?? 0 );
		$this->status            = (string) ( $row['status'] ?? 'pending' );
		$this->comment_status    = (string) ( $row['comment_status'] ?? 'none' );
		$this->remote_id         = isset( $row['remote_id'] ) ? (string) $row['remote_id'] : null;
		$this->remote_url        = isset( $row['remote_url'] ) ? (string) $row['remote_url'] : null;
		$this->remote_comment_id = isset( $row['remote_comment_id'] ) ? (string) $row['remote_comment_id'] : null;
		$this->container_id      = isset( $row['container_id'] ) ? (string) $row['container_id'] : null;
		$this->attempts          = (int) ( $row['attempts'] ?? 0 );
		$this->comment_attempts  = (int) ( $row['comment_attempts'] ?? 0 );
		$this->scheduled_at      = isset( $row['scheduled_at'] ) ? (string) $row['scheduled_at'] : null;
		$this->published_at      = isset( $row['published_at'] ) ? (string) $row['published_at'] : null;
		$this->error_code        = isset( $row['error_code'] ) ? (string) $row['error_code'] : null;
		$this->error_message     = isset( $row['error_message'] ) ? (string) $row['error_message'] : null;
		$this->source            = (string) ( $row['source'] ?? 'auto' );
		$this->created_at        = (string) ( $row['created_at'] ?? '' );
		$this->updated_at        = (string) ( $row['updated_at'] ?? '' );
	}

	/**
	 * Indica si ya se publicó.
	 *
	 * @return bool
	 */
	public function is_published(): bool {
		return 'published' === $this->status && null !== $this->remote_id;
	}
}

<?php
/**
 * Ejecución de trabajos de publicación.
 *
 * @package Voceador
 */

namespace Voceador;

use Voceador\Channels\Channel;
use Voceador\Channels\ChannelRegistry;
use Voceador\Channels\Media;
use Voceador\Channels\Payload;

/**
 * Publica un trabajo (post + canal) y su comentario, y aplica la política de errores.
 */
final class Publisher implements Registrable {

	/**
	 * Vida del lock por trabajo, en segundos.
	 */
	public const LOCK_TTL = 300;

	/**
	 * Tope del backoff, en segundos.
	 */
	public const MAX_BACKOFF = 86400;

	/**
	 * Espera por defecto cuando la red no dice cuándo habrá cupo.
	 */
	private const DEFAULT_RATE_LIMIT_WAIT = 3600;

	/**
	 * Constructor.
	 *
	 * @param JobRepository     $jobs      Trabajos.
	 * @param ChannelRepository $channels  Canales.
	 * @param ChannelRegistry   $registry  Adaptadores.
	 * @param Templates         $templates Plantillas.
	 * @param Settings          $settings  Ajustes.
	 * @param Logger            $logger    Log.
	 * @param Queue             $queue     Cola.
	 */
	public function __construct(
		private JobRepository $jobs,
		private ChannelRepository $channels,
		private ChannelRegistry $registry,
		private Templates $templates,
		private Settings $settings,
		private Logger $logger,
		private Queue $queue
	) {}

	/**
	 * Engancha la ejecución a los hooks de la cola.
	 */
	public function register_hooks(): void {
		add_action( Queue::HOOK_RUN, array( $this, 'run' ) );
		add_action( Queue::HOOK_COMMENT, array( $this, 'run_comment' ) );
	}

	/**
	 * Ejecuta un trabajo.
	 *
	 * @param int  $job_id Id.
	 * @param bool $force  Si true, permite reintentar un trabajo en estado "unverified"
	 *                     (publicación que pudo haber llegado a Meta sin confirmación).
	 * @return string Estado final del trabajo, o "locked" / "unclaimable" / "missing" / "unverified" si no se ejecutó.
	 */
	public function run( int $job_id, bool $force = false ): string {
		$lock = VOCEADOR_PREFIX . 'lock_job_' . $job_id;

		if ( false !== get_transient( $lock ) ) {
			return 'locked';
		}
		set_transient( $lock, time(), self::LOCK_TTL );

		try {
			try {
				return $this->execute( $job_id, $force );
			} catch ( \Throwable $e ) {
				$this->logger->error( 'exception', $e->getMessage(), array( 'job_id' => $job_id ) );

				$job = $this->jobs->find( $job_id );
				// 'running' solo es posible si claim() ganó en esta misma ejecución: el
				// trabajo quedó reclamado y la excepción interrumpió execute() antes de
				// que llegara a su propio mark_failed()/mark_published().
				if ( null !== $job && 'running' === $job->status ) {
					$channel = $this->channels->find( $job->channel_id );
					if ( null !== $channel ) {
						$this->handle_error( $job, $channel, new \WP_Error( 'fatal', $e->getMessage(), array( 'class' => 'fatal' ) ) );
					} else {
						$this->jobs->mark_failed( $job->id, 'fatal', $e->getMessage() );
					}
				}

				return 'failed';
			}
		} finally {
			delete_transient( $lock );
		}//end try
	}

	/**
	 * Publica el comentario de un trabajo ya publicado.
	 *
	 * @param int $job_id Id.
	 * @return string done, failed, pending (reprogramado), skipped o locked.
	 */
	public function run_comment( int $job_id ): string {
		$lock = VOCEADOR_PREFIX . 'lock_comment_' . $job_id;

		if ( false !== get_transient( $lock ) ) {
			return 'locked';
		}
		set_transient( $lock, time(), self::LOCK_TTL );

		try {
			try {
				return $this->execute_comment( $job_id );
			} catch ( \Throwable $e ) {
				$this->logger->error( 'exception', $e->getMessage(), array( 'job_id' => $job_id ) );

				$job = $this->jobs->find( $job_id );
				// Mismo razonamiento que en run(): 'running' solo es posible si claim_comment()
				// ganó en esta misma ejecución.
				if ( null !== $job && 'running' === $job->comment_status ) {
					$this->jobs->mark_comment_failed( $job->id, $e->getMessage() );
				}

				return 'failed';
			}
		} finally {
			delete_transient( $lock );
		}
	}

	/**
	 * Crea (si hace falta) y ejecuta de inmediato los trabajos de un post.
	 *
	 * @param int      $post_id    Post.
	 * @param int|null $channel_id Canal concreto, o null para todos los activos.
	 * @param string   $source     manual, cli o test.
	 * @param bool     $force      Si true, permite reintentar un trabajo en estado "unverified".
	 * @return array<int, string> Canal => estado.
	 */
	public function publish_now( int $post_id, ?int $channel_id = null, string $source = 'manual', bool $force = false ): array {
		// La ejecución manual también cuenta como actividad de la cola para cron_available().
		$this->queue->touch();

		$targets = null === $channel_id ? $this->channels->active() : array_filter( array( $this->channels->find( $channel_id ) ) );
		$result  = array();

		foreach ( $targets as $channel ) {
			$this->jobs->create_if_absent( $post_id, $channel->id, $source );
			$job = $this->jobs->find_for_post_and_channel( $post_id, $channel->id );

			$result[ $channel->id ] = null === $job ? 'missing' : $this->run( $job->id, $force );
		}

		return $result;
	}

	/**
	 * Segundos de espera antes del intento dado.
	 *
	 * @param int $attempt Número de intento ya realizado (1 = primero).
	 * @return int
	 */
	public function backoff( int $attempt ): int {
		$base = max( 1, (int) $this->settings->get( 'execution.backoff_base' ) );

		return (int) min( self::MAX_BACKOFF, $base * ( 2 ** max( 0, $attempt - 1 ) ) );
	}

	/**
	 * Aplica la política de errores a un trabajo reclamado.
	 *
	 * @param Job       $job     Trabajo (estado previo al error).
	 * @param Channel   $channel Canal.
	 * @param \WP_Error $error   Error normalizado.
	 * @return string Estado resultante.
	 */
	public function handle_error( Job $job, Channel $channel, \WP_Error $error ): string {
		$class = (string) $error->get_error_code();
		$data  = (array) $error->get_error_data();
		// Defensa en profundidad: GraphClient ya redacta en origen, pero handle_error()
		// también recibe errores que no vienen de Graph (excepciones, channel_unavailable).
		// Una sola redacción aquí cubre mark_failed(), set_status() y add_notice() de abajo.
		$message = Logger::redact_string( $error->get_error_message() );

		$attempts = $this->jobs->find( $job->id )?->attempts ?? $job->attempts;
		$retries  = (int) $this->settings->get( 'execution.retries', $channel->settings );
		$context  = array(
			'job_id'     => $job->id,
			'post_id'    => $job->post_id,
			'channel_id' => $channel->id,
			'class'      => $class,
			'graph_code' => $data['graph_code'] ?? 0,
		);

		switch ( $class ) {
			case 'transient':
				if ( $attempts < $retries ) {
					$delay = $this->backoff( $attempts );
					$this->jobs->mark_failed( $job->id, $class, $message );
					$this->jobs->release( $job->id, gmdate( 'Y-m-d H:i:s', time() + $delay ) );
					$this->queue->schedule_job( $job->id, $delay );
					$status = 'pending';
				} else {
					$this->jobs->mark_failed( $job->id, $class, $message );
					$status = 'failed';
				}
				break;

			case 'rate_limited':
				$wait = (int) ( $data['retry_after'] ?? 0 );
				$wait = $wait > 0 ? $wait : self::DEFAULT_RATE_LIMIT_WAIT;
				$this->jobs->mark_rate_limited( $job->id, gmdate( 'Y-m-d H:i:s', time() + $wait ), $message );
				$this->queue->schedule_job( $job->id, $wait );
				$status = 'rate_limited';
				break;

			case 'auth':
			case 'permission':
				$this->jobs->mark_failed( $job->id, $class, $message );
				$this->channels->set_status(
					$channel->id,
					'paused',
					array(
						'message'    => $message,
						'class'      => $class,
						'graph_code' => $data['graph_code'] ?? 0,
					)
				);
				self::add_notice(
					'channel_paused_' . $channel->id,
					sprintf( /* translators: 1: alias del canal, 2: mensaje de error */ __( 'Voceador pausó el canal "%1$s": %2$s. Vuelve a conectarlo desde los ajustes.', 'voceador' ), $channel->alias, $message )
				);
				$status = 'failed';
				break;

			default:
				$this->jobs->mark_failed( $job->id, $class, $message );
				$status = 'failed';
		}//end switch

		$this->logger->log( in_array( $class, array( 'auth', 'permission', 'fatal' ), true ) ? 'error' : 'warning', 'publish_failed', $message, $context );

		$fresh = $this->jobs->find( $job->id ) ?? $job;

		/**
		 * Se dispara cuando un trabajo falla (aunque vaya a reintentarse).
		 *
		 * @param Job       $job     Trabajo, ya con status/attempts/error_code guardados.
		 * @param Channel   $channel Canal.
		 * @param \WP_Error $error   Error normalizado.
		 */
		do_action( 'voceador_failed', $fresh, $channel, $error );

		return $status;
	}

	/**
	 * Guarda un aviso persistente para el admin.
	 *
	 * @param string $key     Clave única del aviso.
	 * @param string $message Texto.
	 */
	public static function add_notice( string $key, string $message ): void {
		$option  = VOCEADOR_PREFIX . 'notices';
		$notices = get_option( $option, array() );
		$notices = is_array( $notices ) ? $notices : array();

		$notices[ $key ] = array(
			'message' => $message,
			'time'    => time(),
		);

		if ( false === get_option( $option ) ) {
			add_option( $option, $notices, '', false );
			return;
		}
		update_option( $option, $notices, false );
	}

	/**
	 * Cuerpo de run() una vez tomado el lock.
	 *
	 * @param int  $job_id Id.
	 * @param bool $force  Si true, permite reintentar un trabajo en estado "unverified".
	 * @return string
	 */
	private function execute( int $job_id, bool $force = false ): string {
		$job = $this->jobs->find( $job_id );
		if ( null === $job ) {
			return 'missing';
		}
		if ( $job->is_published() || null !== $job->remote_id ) {
			return 'published';
		}
		if ( 'unverified' === $job->error_code && ! $force ) {
			return 'unverified';
		}

		$channel = $this->channels->find( $job->channel_id );
		if ( null === $channel || ! $channel->is_active() ) {
			$error = new \WP_Error( 'channel_unavailable', __( 'El canal no existe o está pausado.', 'voceador' ), array( 'class' => 'channel_unavailable' ) );
			$this->jobs->mark_failed( $job->id, 'channel_unavailable', $error->get_error_message() );
			$this->logger->warning(
				'publish_failed',
				$error->get_error_message(),
				array(
					'job_id'     => $job->id,
					'post_id'    => $job->post_id,
					'channel_id' => $job->channel_id,
					'class'      => 'channel_unavailable',
				)
			);

			$fresh = $this->jobs->find( $job->id ) ?? $job;

			/**
			 * Se dispara cuando un trabajo falla (aunque vaya a reintentarse).
			 *
			 * @param Job       $job     Trabajo.
			 * @param Channel   $channel Canal (con solo el id si no existe).
			 * @param \WP_Error $error   Error normalizado.
			 */
			do_action( 'voceador_failed', $fresh, $channel ?? new Channel( array( 'id' => $job->channel_id ) ), $error );

			return 'failed';
		}//end if

		$post = get_post( $job->post_id );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			$this->jobs->mark_skipped( $job->id, __( 'El post ya no está publicado.', 'voceador' ) );
			return 'skipped';
		}

		if ( ! $this->jobs->claim( $job->id ) ) {
			// El trabajo pudo perder la carrera por dos motivos distintos: otro proceso lo
			// tiene en running ahora mismo ("locked", reintentable más tarde), o ya no está
			// en un estado reclamable (por ejemplo, otro proceso lo publicó entre el find()
			// de arriba y este claim(); "unclaimable", no es un fallo de este job).
			$reclaimed = $this->jobs->find( $job->id );
			return null !== $reclaimed && 'running' === $reclaimed->status ? 'locked' : 'unclaimable';
		}

		$adapter = $this->registry->adapter( $channel->type );

		$usage = $adapter->usage_limits( $channel );
		if ( null !== $usage && ! $usage->has_capacity() ) {
			// La primera vez que un trabajo cae en rate_limited ya consumió un intento en
			// el claim() de arriba; los siguientes claim() desde rate_limited no cuentan
			// (ver JobRepository::claim()), así que no se pierde presupuesto de reintentos
			// por esperar cupo.
			$wait = null !== $usage->resets_at ? max( 60, $usage->resets_at - time() ) : self::DEFAULT_RATE_LIMIT_WAIT;
			$this->jobs->mark_rate_limited( $job->id, gmdate( 'Y-m-d H:i:s', time() + $wait ), __( 'Se alcanzó el límite de publicaciones del canal.', 'voceador' ) );
			$this->queue->schedule_job( $job->id, $wait );
			return 'rate_limited';
		}

		$media = $adapter->prepare_media( $channel, $post );
		if ( is_wp_error( $media ) ) {
			return $this->handle_error( $job, $channel, $media );
		}

		if ( $media->is_empty() ) {
			if ( 'feed' !== (string) $this->settings->get( 'image.no_image', $channel->settings ) ) {
				$this->jobs->mark_skipped( $job->id, __( 'El post no tiene imagen destacada.', 'voceador' ) );
				return 'skipped';
			}
			$media = null;
		}

		$caption = $this->templates->caption( $channel, $post );
		$comment = (bool) $this->settings->get( 'execution.comment_enabled', $channel->settings ) ? $this->templates->comment( $channel, $post ) : '';
		$payload = new Payload( $caption, $media instanceof Media ? $media : null, (string) get_permalink( $post ), $comment );

		$result = $adapter->publish( $channel, $payload );
		if ( is_wp_error( $result ) ) {
			return $this->handle_error( $job, $channel, $result );
		}

		$this->jobs->mark_published( $job->id, $result->id, $result->url, '' !== $comment );
		$this->logger->info(
			'published',
			'Publicado',
			array(
				'job_id'     => $job->id,
				'post_id'    => $job->post_id,
				'channel_id' => $channel->id,
				'remote_id'  => $result->id,
			)
		);

		$fresh = $this->jobs->find( $job->id ) ?? $job;

		/**
		 * Se dispara cuando un trabajo se publica.
		 *
		 * @param Job                              $job     Trabajo, ya con remote_id/status/attempts guardados.
		 * @param Channel                          $channel Canal.
		 * @param \Voceador\Channels\RemoteResult $result  Resultado remoto.
		 */
		do_action( 'voceador_published', $fresh, $channel, $result );

		if ( '' !== $comment ) {
			$this->queue->schedule_comment( $job->id, (int) $this->settings->get( 'execution.comment_delay', $channel->settings ) );
		}

		return 'published';
	}

	/**
	 * Cuerpo de run_comment() una vez tomado el lock.
	 *
	 * @param int $job_id Id.
	 * @return string
	 */
	private function execute_comment( int $job_id ): string {
		$job = $this->jobs->find( $job_id );
		if ( null === $job || ! $job->is_published() || ! in_array( $job->comment_status, array( 'pending', 'failed', 'running' ), true ) ) {
			return 'skipped';
		}

		$channel = $this->channels->find( $job->channel_id );
		$post    = get_post( $job->post_id );
		if ( null === $channel || ! $channel->is_active() || ! $post instanceof \WP_Post ) {
			return 'skipped';
		}

		$retries = (int) $this->settings->get( 'execution.retries', $channel->settings );
		if ( $job->comment_attempts >= $retries ) {
			// set_comment_status() y no mark_comment_failed(): los intentos ya están
			// agotados, incrementar de nuevo comment_attempts distorsionaría el contador.
			$this->jobs->set_comment_status( $job->id, 'failed' );
			return 'failed';
		}

		if ( ! $this->jobs->claim_comment( $job->id ) ) {
			return 'locked';
		}

		$text = $this->templates->comment( $channel, $post );
		if ( '' === $text ) {
			$this->jobs->mark_comment_done( $job->id, '' );
			return 'skipped';
		}

		$result = $this->registry->adapter( $channel->type )->comment( $channel, (string) $job->remote_id, $text );

		if ( ! is_wp_error( $result ) ) {
			$this->jobs->mark_comment_done( $job->id, $result->id );
			$this->logger->info(
				'comment_published',
				'Comentario publicado',
				array(
					'job_id'            => $job->id,
					'post_id'           => $job->post_id,
					'channel_id'        => $channel->id,
					'remote_comment_id' => $result->id,
				)
			);
			return 'done';
		}

		$this->jobs->mark_comment_failed( $job->id, $result->get_error_message() );
		$this->logger->warning(
			'comment_failed',
			$result->get_error_message(),
			array(
				'job_id'     => $job->id,
				'post_id'    => $job->post_id,
				'channel_id' => $channel->id,
				'class'      => $result->get_error_code(),
			)
		);

		if ( 'transient' === $result->get_error_code() && $job->comment_attempts + 1 < $retries ) {
			$this->queue->schedule_comment( $job->id, $this->backoff( $job->comment_attempts + 1 ) );
			return 'pending';
		}

		return 'failed';
	}
}

<?php
/**
 * Disparador: de la publicación del post a la cola.
 *
 * @package Voceador
 */

namespace Voceador;

/**
 * Escucha transition_post_status y encola un trabajo por canal. Nunca publica en la misma petición.
 */
final class Trigger implements Registrable {

	/**
	 * Meta: marca de tiempo de la primera publicación en redes.
	 */
	public const META_FIRST_PUBLISHED = '_voceador_first_published';

	/**
	 * Constructor.
	 *
	 * @param Rules         $rules    Reglas.
	 * @param JobRepository $jobs     Trabajos.
	 * @param Queue         $queue    Cola.
	 * @param Settings      $settings Ajustes.
	 * @param Logger        $logger   Log.
	 */
	public function __construct(
		private Rules $rules,
		private JobRepository $jobs,
		private Queue $queue,
		private Settings $settings,
		private Logger $logger
	) {}

	/**
	 * Engancha el disparador.
	 */
	public function register_hooks(): void {
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 20, 3 );
	}

	/**
	 * Reacciona a un cambio de estado.
	 *
	 * @param string   $new_status Estado nuevo.
	 * @param string   $old_status Estado anterior.
	 * @param \WP_Post $post       Post.
	 * @return int Trabajos encolados.
	 */
	public function on_transition( string $new_status, string $old_status, \WP_Post $post ): int {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return 0;
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return 0;
		}
		if ( 'future' === $old_status && ! (bool) $this->settings->get( 'execution.publish_scheduled' ) ) {
			return 0;
		}

		$already = get_post_meta( $post->ID, self::META_FIRST_PUBLISHED, true );
		if ( '' !== (string) $already && ! (bool) $this->settings->get( 'execution.publish_republished' ) ) {
			return 0;
		}

		return $this->enqueue( $post );
	}

	/**
	 * Crea y programa los trabajos de un post según las reglas.
	 *
	 * @param \WP_Post $post   Post.
	 * @param string   $source auto, manual, cli o test.
	 * @return int Trabajos nuevos encolados.
	 */
	public function enqueue( \WP_Post $post, string $source = 'auto' ): int {
		$channels = $this->rules->channels_for( $post );
		if ( ! $channels ) {
			return 0;
		}

		if ( '' === (string) get_post_meta( $post->ID, self::META_FIRST_PUBLISHED, true ) ) {
			update_post_meta( $post->ID, self::META_FIRST_PUBLISHED, time() );
		}

		$count = 0;
		foreach ( $channels as $channel ) {
			$delay  = (int) $this->settings->get( 'execution.delay', $channel->settings );
			$job_id = $this->jobs->create_if_absent( $post->ID, $channel->id, $source, gmdate( 'Y-m-d H:i:s', time() + $delay ) );
			if ( null === $job_id ) {
				continue;
			}
			$this->queue->schedule_job( $job_id, $delay );
			++$count;
		}

		if ( $count > 0 ) {
			$this->logger->info( 'enqueued', sprintf( '%d trabajo(s) encolado(s)', $count ), array( 'post_id' => $post->ID ) );
		}

		return $count;
	}
}

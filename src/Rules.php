<?php
/**
 * Reglas de enrutamiento.
 *
 * @package Voceador
 */

namespace Voceador;

use Voceador\Channels\Channel;

/**
 * Decide a qué canales va cada post.
 */
final class Rules {

	/**
	 * Meta: no publicar en redes.
	 */
	public const META_SKIP = '_voceador_skip';

	/**
	 * Meta: canales elegidos a mano en el editor (array de ids).
	 */
	public const META_CHANNELS = '_voceador_channels';

	/**
	 * Constructor.
	 *
	 * @param ChannelRepository $channels Canales.
	 * @param Settings          $settings Ajustes.
	 */
	public function __construct(
		private ChannelRepository $channels,
		private Settings $settings
	) {}

	/**
	 * Canales que deben recibir el post.
	 *
	 * @param \WP_Post $post Post.
	 * @return Channel[]
	 */
	public function channels_for( \WP_Post $post ): array {
		if ( (bool) get_post_meta( $post->ID, self::META_SKIP, true ) ) {
			return array();
		}

		/**
		 * Permite vetar la publicación de un post en redes.
		 *
		 * @param bool     $should Si debe publicarse.
		 * @param \WP_Post $post   Post.
		 */
		if ( ! apply_filters( 'voceador_should_publish', true, $post ) ) {
			return array();
		}

		$active = $this->channels->active();
		$manual = get_post_meta( $post->ID, self::META_CHANNELS, true );
		$manual = is_array( $manual ) ? array_map( 'intval', $manual ) : array();

		if ( $manual ) {
			$targets = array_values( array_filter( $active, static fn( Channel $c ) => in_array( $c->id, $manual, true ) ) );
		} else {
			$targets = array_values(
				array_filter(
					$active,
					fn( Channel $c ) => in_array( $post->post_type, (array) $this->settings->get( 'rules.post_types', $c->settings ), true )
				)
			);
		}

		/**
		 * Permite ajustar la lista de canales destino de un post.
		 *
		 * @param Channel[] $targets Canales.
		 * @param \WP_Post  $post    Post.
		 */
		$targets = apply_filters( 'voceador_target_channels', $targets, $post );

		return array_values( array_filter( (array) $targets, static fn( $c ) => $c instanceof Channel ) );
	}
}

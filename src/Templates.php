<?php
/**
 * Plantillas de caption y comentario.
 *
 * @package Voceador
 */

namespace Voceador;

use Voceador\Channels\Channel;

/**
 * Sustituye marcadores y limpia el texto para redes sociales.
 */
final class Templates {

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Ajustes.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * Renderiza una plantilla para un post.
	 *
	 * Solo calcula los marcadores presentes en la plantilla: cada closure de
	 * {@see vars()} se invoca como mucho una vez por marcador usado en esta
	 * llamada, y su resultado se cachea en $resolved mientras dura la llamada.
	 *
	 * @param string   $template Plantilla con marcadores {clave}.
	 * @param \WP_Post $post     Post.
	 * @param array    $extra    Marcadores adicionales clave => valor; tienen prioridad sobre los calculados.
	 * @return string
	 */
	public function render( string $template, \WP_Post $post, array $extra = array() ): string {
		preg_match_all( '/\{([a-z_]+)\}/', $template, $matches );
		$keys = array_unique( $matches[1] );

		$resolvers = $this->vars( $post );
		$resolved  = array();

		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $extra ) || ! array_key_exists( $key, $resolvers ) ) {
				continue;
			}
			$resolved[ $key ] = ( $resolvers[ $key ] )();
		}

		$vars = array_merge( $resolved, $extra );

		$out = preg_replace_callback(
			'/\{([a-z_]+)\}/',
			static function ( array $m ) use ( $vars ): string {
				return array_key_exists( $m[1], $vars ) ? (string) $vars[ $m[1] ] : $m[0];
			},
			$template
		);

		return trim( (string) $out );
	}

	/**
	 * Caption final de un post para un canal.
	 *
	 * @param Channel  $channel Canal.
	 * @param \WP_Post $post    Post.
	 * @return string
	 */
	public function caption( Channel $channel, \WP_Post $post ): string {
		$text = $this->render( (string) $this->settings->get( 'templates.caption', $channel->settings ), $post );

		/**
		 * Permite modificar el caption antes de publicarlo.
		 *
		 * @param string   $text    Caption.
		 * @param \WP_Post $post    Post.
		 * @param Channel  $channel Canal.
		 */
		$text = (string) apply_filters( 'voceador_caption', $text, $post, $channel );

		return $this->limit( $text, $channel );
	}

	/**
	 * Comentario final de un post para un canal.
	 *
	 * @param Channel  $channel Canal.
	 * @param \WP_Post $post    Post.
	 * @return string
	 */
	public function comment( Channel $channel, \WP_Post $post ): string {
		$text = $this->render( (string) $this->settings->get( 'templates.comment', $channel->settings ), $post );

		/**
		 * Permite modificar el comentario antes de publicarlo.
		 *
		 * @param string   $text    Comentario.
		 * @param \WP_Post $post    Post.
		 * @param Channel  $channel Canal.
		 */
		$text = (string) apply_filters( 'voceador_comment', $text, $post, $channel );

		return $this->limit( $text, $channel );
	}

	/**
	 * Recorta un texto en un límite de caracteres, en el último espacio si puede.
	 *
	 * @param string $text Texto.
	 * @param int    $max  Longitud máxima incluyendo la elipsis.
	 * @return string
	 */
	public static function truncate( string $text, int $max ): string {
		if ( $max <= 0 || mb_strlen( $text ) <= $max ) {
			return $text;
		}

		$cut   = mb_substr( $text, 0, $max - 1 );
		$space = mb_strrpos( $cut, ' ' );

		if ( false !== $space && $space > 0 ) {
			$cut = mb_substr( $cut, 0, $space );
		}

		return rtrim( $cut ) . '…';
	}

	/**
	 * Aplica el límite de longitud del canal.
	 *
	 * @param string  $text    Texto.
	 * @param Channel $channel Canal.
	 * @return string
	 */
	private function limit( string $text, Channel $channel ): string {
		return self::truncate( $text, (int) $this->settings->get( 'templates.max_length', $channel->settings ) );
	}

	/**
	 * Resolutores perezosos de los marcadores de un post.
	 *
	 * Cada valor es un closure sin argumentos que calcula el marcador solo
	 * cuando se invoca; {@see render()} solo invoca los que aparecen en la
	 * plantilla.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, callable(): string>
	 */
	private function vars( \WP_Post $post ): array {
		return array(
			'title'          => fn(): string => self::clean( get_the_title( $post ) ),
			'excerpt'        => fn(): string => self::clean( $this->excerpt( $post ) ),
			'permalink'      => fn(): string => (string) get_permalink( $post ),
			'shortlink'      => fn(): string => (string) wp_get_shortlink( $post->ID ),
			'site_name'      => fn(): string => self::clean( get_bloginfo( 'name' ) ),
			'author'         => fn(): string => self::clean( (string) get_the_author_meta( 'display_name', (int) $post->post_author ) ),
			'category'       => fn(): string => self::clean( (string) ( $this->categories( $post )[0] ?? '' ) ),
			'categories'     => fn(): string => self::clean( implode( ', ', $this->categories( $post ) ) ),
			'date'           => fn(): string => get_the_date( 'd/m/Y', $post ),
			'social_message' => fn(): string => $this->social_message( $post ),
		);
	}

	/**
	 * Nombres de las categorías de un post.
	 *
	 * @param \WP_Post $post Post.
	 * @return string[]
	 */
	private function categories( \WP_Post $post ): array {
		return array_values(
			array_map(
				static fn( \WP_Term $t ) => $t->name,
				array_filter( (array) get_the_category( $post->ID ), static fn( $t ) => $t instanceof \WP_Term )
			)
		);
	}

	/**
	 * Extracto del post sin pasar por los filtros de tema.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function excerpt( \WP_Post $post ): string {
		if ( '' !== trim( $post->post_excerpt ) ) {
			return $post->post_excerpt;
		}

		return wp_trim_words( $post->post_content, 40, '…' );
	}

	/**
	 * Primer valor no vacío (ya limpio) de la cadena de fallback del texto social.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function social_message( \WP_Post $post ): string {
		$chain = (array) $this->settings->get( 'templates.social_fallback' );

		foreach ( $chain as $source ) {
			$value = match ( (string) $source ) {
				'message' => (string) get_post_meta( $post->ID, '_voceador_message_fb', true ),
				'excerpt' => $this->excerpt( $post ),
				'title'   => get_the_title( $post ),
				default   => '',
			};

			$clean = self::clean( $value );
			if ( '' !== trim( $clean ) ) {
				return $clean;
			}
		}

		return '';
	}

	/**
	 * Limpia HTML, shortcodes y entidades.
	 *
	 * Decodifica las entidades ANTES de quitar shortcodes y etiquetas: un
	 * valor puede traer markup codificado como entidades (p. ej.
	 * `&lt;b&gt;x&lt;/b&gt;`), y si se decodificara después de
	 * `wp_strip_all_tags()` ese markup sobreviviría como texto literal en vez
	 * de limpiarse. La segunda decodificación al final cubre valores que
	 * venían doblemente codificados (p. ej. `&amp;amp;` → `&amp;` → `&`).
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	private static function clean( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// strip_shortcodes() solo quita los registrados; el regex cubre los que no lo estén.
		$text = (string) preg_replace( '/\[\/?[a-zA-Z0-9_-]+[^\]]*\]/', '', strip_shortcodes( $text ) );
		$text = wp_strip_all_tags( $text, true );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/[ \t]+/', ' ', $text );

		return trim( $text );
	}
}

<?php
/**
 * Adaptador de Páginas de Facebook.
 *
 * @package Voceador
 */

namespace Voceador\Channels;

use Voceador\GraphClient;
use Voceador\Settings;

/**
 * Publica fotos (o enlaces) y comentarios en una Página con su Page Access Token.
 */
final class FacebookPageAdapter implements ChannelAdapter {

	/**
	 * Peso máximo que acepta Graph para una foto.
	 */
	public const MAX_BYTES = 4 * 1024 * 1024;

	/**
	 * Formatos que acepta Graph.
	 */
	public const MIMES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/tiff' );

	/**
	 * Tamaños registrados que se prueban, en orden, cuando la imagen pesa demasiado.
	 */
	private const FALLBACK_SIZES = array( 'large', 'medium_large', 'medium' );

	/**
	 * Constructor.
	 *
	 * @param GraphClient $graph    Cliente de Graph.
	 * @param Settings    $settings Ajustes.
	 */
	public function __construct(
		private GraphClient $graph,
		private Settings $settings
	) {}

	/**
	 * Identificador del tipo.
	 *
	 * @return string
	 */
	public static function type(): string {
		return 'facebook_page';
	}

	/**
	 * Etiqueta para la interfaz.
	 *
	 * @return string
	 */
	public static function label(): string {
		return __( 'Página de Facebook', 'voceador' );
	}

	/**
	 * Comprueba el token contra /me.
	 *
	 * @param Channel $c Canal.
	 * @return HealthReport
	 */
	public function validate_credentials( Channel $c ): HealthReport {
		$me = $this->graph->get( 'me', array( 'fields' => 'id,name' ), (string) $c->credential( 'access_token' ) );

		if ( is_wp_error( $me ) ) {
			return new HealthReport( false, $me->get_error_message(), array(), array(), null, (array) $me->get_error_data() );
		}

		$id = (string) ( $me['id'] ?? '' );

		if ( '' === $id ) {
			return new HealthReport( false, __( 'La respuesta de Graph no incluye un id de Página.', 'voceador' ), array(), array(), null, $me );
		}

		if ( '' !== $c->remote_id && $id !== $c->remote_id ) {
			return new HealthReport( false, __( 'El token pertenece a otra Página.', 'voceador' ), array(), array(), null, $me );
		}

		return new HealthReport( true, (string) ( $me['name'] ?? '' ), array(), array(), null, $me );
	}

	/**
	 * Imagen destacada en el tamaño configurado, dentro del peso máximo.
	 *
	 * @param Channel  $c Canal.
	 * @param \WP_Post $p Post.
	 * @return Media|\WP_Error Media vacío si no hay destacada.
	 */
	public function prepare_media( Channel $c, \WP_Post $p ): Media|\WP_Error {
		$attachment_id = (int) get_post_thumbnail_id( $p );

		if ( $attachment_id <= 0 ) {
			return $this->filter_image( new Media(), $p, $c );
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, self::MIMES, true ) ) {
			return new \WP_Error( 'media', sprintf( /* translators: %s: tipo MIME */ __( 'Facebook no acepta imágenes %s; convierte la imagen destacada a JPEG o PNG.', 'voceador' ), $mime ), array( 'class' => 'media' ) );
		}

		/**
		 * Peso máximo de una foto para Facebook, en bytes.
		 *
		 * @param int $max_bytes Bytes.
		 */
		$max_bytes = (int) apply_filters( 'voceador_fb_max_bytes', self::MAX_BYTES );

		$preferred = (string) $this->settings->get( 'image.fb_size', $c->settings );
		$sizes     = array_values( array_unique( array_merge( array( $preferred ), self::FALLBACK_SIZES ) ) );

		foreach ( $sizes as $size ) {
			$media = $this->media_for_size( $attachment_id, $size, $mime );
			if ( null !== $media && $media->bytes <= $max_bytes ) {
				return $this->filter_image( $media, $p, $c );
			}
		}

		return new \WP_Error( 'media', __( 'La imagen destacada pesa más de lo que acepta Facebook en todos los tamaños disponibles.', 'voceador' ), array( 'class' => 'media' ) );
	}

	/**
	 * Publica foto con caption, o enlace en el feed si no hay imagen.
	 *
	 * @param Channel $c       Canal.
	 * @param Payload $payload Contenido.
	 * @return RemoteResult|\WP_Error
	 */
	public function publish( Channel $c, Payload $payload ): RemoteResult|\WP_Error {
		$token = (string) $c->credential( 'access_token' );
		$media = $payload->media;

		if ( null === $media || $media->is_empty() ) {
			$response = $this->graph->post(
				$c->remote_id . '/feed',
				array(
					'message' => $payload->caption,
					'link'    => $payload->link,
				),
				$token
			);
		} elseif ( 'url' === (string) $this->settings->get( 'image.fb_mode', $c->settings ) && '' !== $media->url ) {
			$response = $this->graph->post(
				$c->remote_id . '/photos',
				array(
					'caption' => $payload->caption,
					'url'     => $media->url,
				),
				$token
			);
		} elseif ( '' !== $media->path ) {
			$response = $this->graph->post_multipart( $c->remote_id . '/photos', array( 'caption' => $payload->caption ), 'source', $media->path, $token );
		} else {
			$response = $this->graph->post(
				$c->remote_id . '/photos',
				array(
					'caption' => $payload->caption,
					'url'     => $media->url,
				),
				$token
			);
		}//end if

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$id = (string) ( $response['post_id'] ?? $response['id'] ?? '' );

		if ( '' === $id ) {
			return new \WP_Error(
				'fatal',
				__( 'Graph no devolvió el id de la publicación.', 'voceador' ),
				array(
					'class' => 'fatal',
					'raw'   => $response,
				)
			);
		}

		return new RemoteResult( $id, 'https://www.facebook.com/' . rawurlencode( $id ), $response );
	}

	/**
	 * Comenta como la Página.
	 *
	 * @param Channel $c         Canal.
	 * @param string  $remote_id Id de la publicación.
	 * @param string  $text      Texto.
	 * @return RemoteResult|\WP_Error
	 */
	public function comment( Channel $c, string $remote_id, string $text ): RemoteResult|\WP_Error {
		$response = $this->graph->post( $remote_id . '/comments', array( 'message' => $text ), (string) $c->credential( 'access_token' ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return new RemoteResult( (string) ( $response['id'] ?? '' ), '', $response );
	}

	/**
	 * Consulta si la publicación sigue existiendo.
	 *
	 * @param Channel $c         Canal.
	 * @param string  $remote_id Id de la publicación.
	 * @return RemoteStatus
	 */
	public function status( Channel $c, string $remote_id ): RemoteStatus {
		$response = $this->graph->get( $remote_id, array( 'fields' => 'id,permalink_url' ), (string) $c->credential( 'access_token' ) );

		if ( is_wp_error( $response ) ) {
			return new RemoteStatus( false, '', (array) $response->get_error_data() );
		}

		return new RemoteStatus( true, (string) ( $response['permalink_url'] ?? '' ), $response );
	}

	/**
	 * Facebook no expone un límite de publicación consultable.
	 *
	 * @param Channel $c Canal.
	 * @return UsageLimits|null
	 */
	public function usage_limits( Channel $c ): ?UsageLimits { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Firma de la interfaz.
		return null;
	}

	/**
	 * Campos configurables del tipo.
	 *
	 * @return array
	 */
	public function settings_schema(): array {
		return array(
			'image.fb_size'  => array(
				'label' => __( 'Tamaño de imagen', 'voceador' ),
				'type'  => 'image_size',
			),
			'image.fb_mode'  => array(
				'label'   => __( 'Modo de envío', 'voceador' ),
				'type'    => 'select',
				'options' => array(
					'file' => __( 'Archivo (multipart)', 'voceador' ),
					'url'  => __( 'URL pública', 'voceador' ),
				),
			),
			'image.no_image' => array(
				'label'   => __( 'Sin imagen destacada', 'voceador' ),
				'type'    => 'select',
				'options' => array(
					'skip' => __( 'Omitir', 'voceador' ),
					'feed' => __( 'Publicar como enlace', 'voceador' ),
				),
			),
		);
	}

	/**
	 * Media de un adjunto en un tamaño registrado, o null si no existe ese tamaño.
	 *
	 * @param int    $attachment_id Adjunto.
	 * @param string $size          Tamaño registrado o "full".
	 * @param string $mime          MIME del adjunto.
	 * @return Media|null
	 */
	private function media_for_size( int $attachment_id, string $size, string $mime ): ?Media {
		$src = wp_get_attachment_image_src( $attachment_id, $size );
		if ( ! is_array( $src ) || empty( $src[0] ) ) {
			return null;
		}

		$original = (string) get_attached_file( $attachment_id );
		$path     = $original;

		if ( 'full' !== $size ) {
			$intermediate = image_get_intermediate_size( $attachment_id, $size );
			if ( is_array( $intermediate ) && ! empty( $intermediate['file'] ) ) {
				$path = trailingslashit( dirname( $original ) ) . wp_basename( $intermediate['file'] );
			}
		}

		if ( '' === $path || ! is_readable( $path ) ) {
			return null;
		}

		return new Media( $path, (string) $src[0], $mime, (int) filesize( $path ) );
	}

	/**
	 * Aplica el filtro voceador_image.
	 *
	 * @param Media    $media   Imagen.
	 * @param \WP_Post $post    Post.
	 * @param Channel  $channel Canal.
	 * @return Media|\WP_Error
	 */
	private function filter_image( Media $media, \WP_Post $post, Channel $channel ): Media|\WP_Error {
		/**
		 * Permite sustituir la imagen que se publica.
		 *
		 * @param Media    $media   Imagen preparada (vacía si no hay destacada).
		 * @param \WP_Post $post    Post.
		 * @param Channel  $channel Canal.
		 */
		$filtered = apply_filters( 'voceador_image', $media, $post, $channel );

		if ( $filtered instanceof Media || is_wp_error( $filtered ) ) {
			return $filtered;
		}

		return $media;
	}
}

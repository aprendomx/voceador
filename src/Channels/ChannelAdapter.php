<?php
/**
 * Contrato de un tipo de canal.
 *
 * @package Voceador
 */

namespace Voceador\Channels;

/**
 * Toda la lógica específica de una red vive detrás de esta interfaz.
 *
 * Los métodos que hablan con la red devuelven WP_Error con una de las clases
 * normalizadas (transient, rate_limited, auth, permission, media, spam, fatal)
 * como código de error.
 */
interface ChannelAdapter {

	/**
	 * Identificador del tipo ("facebook_page").
	 *
	 * @return string
	 */
	public static function type(): string;

	/**
	 * Nombre para la interfaz.
	 *
	 * @return string
	 */
	public static function label(): string;

	/**
	 * Comprueba que las credenciales del canal sirven.
	 *
	 * @param Channel $c Canal.
	 * @return HealthReport
	 */
	public function validate_credentials( Channel $c ): HealthReport;

	/**
	 * Prepara la imagen del post para este canal.
	 *
	 * @param Channel  $c Canal.
	 * @param \WP_Post $p Post.
	 * @return Media|\WP_Error Media vacío si el post no tiene imagen y el canal lo tolera.
	 */
	public function prepare_media( Channel $c, \WP_Post $p ): Media|\WP_Error;

	/**
	 * Publica el contenido.
	 *
	 * @param Channel $c       Canal.
	 * @param Payload $payload Contenido renderizado.
	 * @return RemoteResult|\WP_Error
	 */
	public function publish( Channel $c, Payload $payload ): RemoteResult|\WP_Error;

	/**
	 * Deja un comentario en una publicación.
	 *
	 * @param Channel $c         Canal.
	 * @param string  $remote_id Id remoto de la publicación.
	 * @param string  $text      Texto.
	 * @return RemoteResult|\WP_Error
	 */
	public function comment( Channel $c, string $remote_id, string $text ): RemoteResult|\WP_Error;

	/**
	 * Consulta si una publicación sigue existiendo.
	 *
	 * @param Channel $c         Canal.
	 * @param string  $remote_id Id remoto.
	 * @return RemoteStatus
	 */
	public function status( Channel $c, string $remote_id ): RemoteStatus;

	/**
	 * Uso actual del límite de publicación, o null si la red no lo expone.
	 *
	 * @param Channel $c Canal.
	 * @return UsageLimits|null
	 */
	public function usage_limits( Channel $c ): ?UsageLimits;

	/**
	 * Campos de configuración propios del tipo, para la interfaz.
	 *
	 * @return array
	 */
	public function settings_schema(): array;
}

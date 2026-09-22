<?php
/**
 * Contenido listo para publicar en un canal.
 *
 * @package Voceador
 */

namespace Voceador\Channels;

/**
 * Caption, imagen, enlace y comentario ya renderizados.
 */
final class Payload {

	/**
	 * Constructor.
	 *
	 * @param string     $caption Texto principal.
	 * @param Media|null $media   Imagen, o null si no hay.
	 * @param string     $link    Enlace a la nota.
	 * @param string     $comment Texto del comentario ('' si no hay).
	 * @param array      $extra   Datos adicionales específicos del canal.
	 */
	public function __construct(
		public readonly string $caption = '',
		public readonly ?Media $media = null,
		public readonly string $link = '',
		public readonly string $comment = '',
		public readonly array $extra = array()
	) {}
}

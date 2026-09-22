<?php
/**
 * Imagen preparada para un canal.
 *
 * @package Voceador
 */

namespace Voceador\Channels;

/**
 * Archivo local y/o URL pública de la imagen a publicar.
 */
final class Media {

	/**
	 * Constructor.
	 *
	 * @param string $path  Ruta local del archivo ('' si solo hay URL).
	 * @param string $url   URL pública ('' si solo hay archivo).
	 * @param string $mime  Tipo MIME.
	 * @param int    $bytes Tamaño en bytes (0 si se desconoce).
	 */
	public function __construct(
		public readonly string $path = '',
		public readonly string $url = '',
		public readonly string $mime = 'image/jpeg',
		public readonly int $bytes = 0
	) {}

	/**
	 * Indica si no hay imagen.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return '' === $this->path && '' === $this->url;
	}
}

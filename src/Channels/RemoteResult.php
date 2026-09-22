<?php
/**
 * Resultado de una publicación o comentario remoto.
 *
 * @package Voceador
 */

namespace Voceador\Channels;

/**
 * Id remoto, URL pública y respuesta cruda.
 */
final class RemoteResult {

	/**
	 * Constructor.
	 *
	 * @param string $id  Id remoto.
	 * @param string $url URL pública ('' si no se conoce).
	 * @param array  $raw Respuesta cruda de la API.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $url = '',
		public readonly array $raw = array()
	) {}
}

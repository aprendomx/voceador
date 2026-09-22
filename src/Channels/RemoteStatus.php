<?php
/**
 * Estado de una publicación remota.
 *
 * @package Voceador
 */

namespace Voceador\Channels;

/**
 * Indica si la publicación sigue existiendo y dónde.
 */
final class RemoteStatus {

	/**
	 * Constructor.
	 *
	 * @param bool   $exists Si la publicación existe.
	 * @param string $url    URL pública.
	 * @param array  $raw    Respuesta cruda.
	 */
	public function __construct(
		public readonly bool $exists,
		public readonly string $url = '',
		public readonly array $raw = array()
	) {}
}

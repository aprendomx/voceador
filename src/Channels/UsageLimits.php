<?php
/**
 * Uso del límite de publicación de un canal.
 *
 * @package Voceador
 */

namespace Voceador\Channels;

/**
 * Publicaciones usadas frente a la cuota del periodo.
 */
final class UsageLimits {

	/**
	 * Constructor.
	 *
	 * @param int      $used      Publicaciones usadas.
	 * @param int      $quota     Cuota del periodo.
	 * @param int|null $resets_at Timestamp en que se libera cupo, si se conoce.
	 */
	public function __construct(
		public readonly int $used,
		public readonly int $quota,
		public readonly ?int $resets_at = null
	) {}

	/**
	 * Indica si queda cupo.
	 *
	 * @return bool
	 */
	public function has_capacity(): bool {
		return $this->used < $this->quota;
	}
}

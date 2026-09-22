<?php
/**
 * Resultado de validar las credenciales de un canal.
 *
 * @package Voceador
 */

namespace Voceador\Channels;

/**
 * Validez, permisos concedidos y faltantes, expiración.
 */
final class HealthReport {

	/**
	 * Constructor.
	 *
	 * @param bool        $valid          Si el token sirve.
	 * @param string      $message        Explicación para el administrador.
	 * @param array       $scopes         Permisos concedidos.
	 * @param array       $missing_scopes Permisos requeridos que faltan.
	 * @param string|null $expires_at     Fecha de expiración (UTC, MySQL) o null.
	 * @param array       $raw            Respuesta cruda.
	 */
	public function __construct(
		public readonly bool $valid,
		public readonly string $message = '',
		public readonly array $scopes = array(),
		public readonly array $missing_scopes = array(),
		public readonly ?string $expires_at = null,
		public readonly array $raw = array()
	) {}
}

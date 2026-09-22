<?php
/**
 * Contrato para servicios que registran hooks de WordPress.
 *
 * @package Voceador
 */

namespace Voceador;

/**
 * Un servicio Registrable engancha sus propios hooks cuando el contenedor arranca.
 */
interface Registrable {

	/**
	 * Registra las acciones y filtros del servicio.
	 */
	public function register_hooks(): void;
}

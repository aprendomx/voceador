<?php
/**
 * Credenciales de la app de Meta.
 *
 * @package Voceador
 */

namespace Voceador;

/**
 * Guarda el App ID y el App Secret, este último cifrado, y compone el token de app.
 */
final class AppCredentials {

	/**
	 * Nombre corto de la opción.
	 */
	public const OPTION = 'app';

	/**
	 * Constructor.
	 *
	 * @param Crypto $crypto Cifrado.
	 */
	public function __construct( private Crypto $crypto ) {}

	/**
	 * App ID configurado.
	 *
	 * @return string Cadena vacía si no hay ninguno.
	 */
	public function app_id(): string {
		return (string) ( $this->data()['app_id'] ?? '' );
	}

	/**
	 * App Secret descifrado.
	 *
	 * @return string Cadena vacía si no hay secreto o si no se puede descifrar.
	 */
	public function app_secret(): string {
		$stored = (string) ( $this->data()['app_secret'] ?? '' );

		if ( '' === $stored ) {
			return '';
		}

		$plain = $this->crypto->decrypt( $stored );

		return is_wp_error( $plain ) ? '' : $plain;
	}

	/**
	 * Indica si hay un secreto guardado, aunque no se pueda descifrar.
	 *
	 * @return bool
	 */
	public function has_secret(): bool {
		return '' !== (string) ( $this->data()['app_secret'] ?? '' );
	}

	/**
	 * Indica si la app está lista para usarse.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== $this->app_id() && '' !== $this->app_secret();
	}

	/**
	 * Token de app, el que autentica las llamadas a debug_token.
	 *
	 * @return string Cadena vacía si la app no está configurada.
	 */
	public function app_token(): string {
		if ( ! $this->is_configured() ) {
			return '';
		}

		return $this->app_id() . '|' . $this->app_secret();
	}

	/**
	 * Indica si hay que pedir el permiso business_management.
	 *
	 * @return bool
	 */
	public function business_management(): bool {
		return (bool) ( $this->data()['business_management'] ?? false );
	}

	/**
	 * Guarda las credenciales.
	 *
	 * @param string      $app_id     App ID.
	 * @param string|null $app_secret App Secret; null o cadena vacía conserva el guardado.
	 */
	public function save( string $app_id, ?string $app_secret = null ): void {
		$data           = $this->data();
		$data['app_id'] = $app_id;

		if ( null !== $app_secret && '' !== $app_secret ) {
			$data['app_secret'] = $this->crypto->encrypt( $app_secret );
		}

		$this->write( $data );
	}

	/**
	 * Guarda si las Páginas están en un Business Manager.
	 *
	 * @param bool $enabled Si se pide business_management.
	 */
	public function set_business_management( bool $enabled ): void {
		$data                        = $this->data();
		$data['business_management'] = $enabled;

		$this->write( $data );
	}

	/**
	 * Borra las credenciales.
	 */
	public function forget(): void {
		delete_option( VOCEADOR_PREFIX . self::OPTION );
	}

	/**
	 * Contenido de la opción.
	 *
	 * @return array
	 */
	private function data(): array {
		$stored = get_option( VOCEADOR_PREFIX . self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Escribe la opción sin autoload.
	 *
	 * @param array $data Contenido.
	 */
	private function write( array $data ): void {
		$option = VOCEADOR_PREFIX . self::OPTION;

		if ( false === get_option( $option ) ) {
			add_option( $option, $data, '', false );
			return;
		}

		update_option( $option, $data, false );
	}
}

<?php
/**
 * Cifrado de secretos con libsodium.
 *
 * @package Voceador
 */

namespace Voceador;

/**
 * Cifra y descifra cadenas con sodium_crypto_secretbox.
 *
 * Formato almacenado: "v1:" + base64( nonce . texto_cifrado ).
 */
final class Crypto {

	/**
	 * Prefijo de versión del formato almacenado.
	 */
	private const PREFIX = 'v1:';

	/**
	 * Clave simétrica de 32 bytes.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Constructor.
	 *
	 * @param string|null $key Clave de SODIUM_CRYPTO_SECRETBOX_KEYBYTES bytes; null deriva la del sitio.
	 * @throws \InvalidArgumentException Si la clave no tiene la longitud correcta.
	 */
	public function __construct( ?string $key = null ) {
		$key = $key ?? self::derive_key();

		if ( SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $key ) ) {
			throw new \InvalidArgumentException( 'La clave de cifrado debe tener ' . esc_html( (string) SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) . ' bytes.' );
		}

		$this->key = $key;
	}

	/**
	 * Indica si libsodium está disponible.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'sodium_crypto_secretbox' );
	}

	/**
	 * Deriva la clave del sitio.
	 *
	 * Usa VOCEADOR_ENCRYPTION_KEY si está definida; si no, cae en el material
	 * elegido por key_material(). Cambiar el material invalida los valores
	 * previamente cifrados.
	 *
	 * @return string
	 */
	public static function derive_key(): string {
		return sodium_crypto_generichash( self::key_material(), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Elige el material con el que se deriva la clave.
	 *
	 * Deliberadamente no usa wp_salt(): si las constantes de wp-config.php
	 * siguen en su valor de plantilla ('put your unique phrase here', el que
	 * trae el instalador de WordPress cuando no se han generado salts
	 * reales), wp_salt() no usa esa constante sino que genera un valor
	 * aleatorio distinto en cada proceso PHP. Eso haría que la clave
	 * derivada cambiara en cada request y que lo cifrado previamente se
	 * volviera irrecuperable sin ningún aviso. Por eso aquí se comprueban las
	 * constantes de autenticación directamente, y solo se usan si de verdad
	 * fueron configuradas; si no, se recurre a una semilla propia del sitio,
	 * generada una vez y persistida en wp_options.
	 *
	 * @return string
	 */
	private static function key_material(): string {
		if ( defined( 'VOCEADOR_ENCRYPTION_KEY' ) ) {
			return (string) constant( 'VOCEADOR_ENCRYPTION_KEY' );
		}

		if ( self::has_configured_auth_keys() ) {
			return AUTH_KEY . SECURE_AUTH_KEY;
		}

		return self::seed_material();
	}

	/**
	 * Indica si AUTH_KEY y SECURE_AUTH_KEY están definidas con valores reales.
	 *
	 * @return bool
	 */
	private static function has_configured_auth_keys(): bool {
		if ( ! defined( 'AUTH_KEY' ) || ! defined( 'SECURE_AUTH_KEY' ) ) {
			return false;
		}

		$placeholder = 'put your unique phrase here';

		return '' !== AUTH_KEY && '' !== SECURE_AUTH_KEY
			&& AUTH_KEY !== $placeholder && SECURE_AUTH_KEY !== $placeholder;
	}

	/**
	 * Semilla propia del sitio, usada cuando no hay salts de autenticación
	 * configurados. Se genera una sola vez y se persiste en wp_options;
	 * add_option() no sobrescribe un valor existente, así que si dos
	 * requests concurrentes la generan a la vez, ambas terminan leyendo y
	 * usando la misma semilla ya guardada.
	 *
	 * @return string
	 */
	public static function seed_material(): string {
		$option = VOCEADOR_PREFIX . 'crypto_seed';
		$seed   = get_option( $option );

		if ( is_string( $seed ) && '' !== $seed ) {
			return $seed;
		}

		$seed = base64_encode( random_bytes( 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Codificación binaria, no ofuscación.
		add_option( $option, $seed, '', false );

		return (string) get_option( $option );
	}

	/**
	 * Cifra una cadena.
	 *
	 * @param string $plain Texto en claro.
	 * @return string
	 */
	public function encrypt( string $plain ): string {
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plain, $nonce, $this->key );

		return self::PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Codificación binaria, no ofuscación.
	}

	/**
	 * Descifra una cadena almacenada.
	 *
	 * @param string $stored Valor con el formato de encrypt().
	 * @return string|\WP_Error Texto en claro, o error voceador_crypto_format / voceador_crypto_key.
	 */
	public function decrypt( string $stored ): string|\WP_Error {
		if ( 0 !== strpos( $stored, self::PREFIX ) ) {
			return new \WP_Error( 'voceador_crypto_format', __( 'El valor cifrado no tiene un formato reconocido.', 'voceador' ) );
		}

		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Codificación binaria, no ofuscación.
		$min = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;

		if ( false === $raw || strlen( $raw ) < $min ) {
			return new \WP_Error( 'voceador_crypto_format', __( 'El valor cifrado está truncado o dañado.', 'voceador' ) );
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $this->key );

		if ( false === $plain ) {
			return new \WP_Error( 'voceador_crypto_key', __( 'No se pudo descifrar: la clave de cifrado del sitio cambió. Vuelve a conectar el canal.', 'voceador' ) );
		}

		return $plain;
	}
}

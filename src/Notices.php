<?php
/**
 * Avisos persistentes del escritorio.
 *
 * @package Voceador
 */

namespace Voceador;

/**
 * Guarda los avisos que la interfaz muestra hasta que se resuelven o se descartan.
 */
final class Notices {

	/**
	 * Nombre corto de la opción.
	 */
	public const OPTION = 'notices';

	/**
	 * Añade o reemplaza un aviso.
	 *
	 * @param string $key     Clave única; repetirla actualiza el aviso.
	 * @param string $message Texto sin formato.
	 */
	public static function add( string $key, string $message ): void {
		$notices = self::stored();

		$notices[ $key ] = array(
			'message' => $message,
			'time'    => time(),
		);

		self::write( $notices );
	}

	/**
	 * Retira un aviso.
	 *
	 * @param string $key Clave.
	 */
	public static function remove( string $key ): void {
		$notices = self::stored();

		if ( ! isset( $notices[ $key ] ) ) {
			return;
		}

		unset( $notices[ $key ] );
		self::write( $notices );
	}

	/**
	 * Avisos guardados, del más reciente al más antiguo.
	 *
	 * @return array<string, array{message:string, time:int}>
	 */
	public static function all(): array {
		// Se invierte antes de ordenar: time() solo tiene resolución de un segundo, así
		// que dos avisos añadidos en el mismo segundo empatan. uasort() es estable desde
		// PHP 8, así que partir del orden inverso de inserción hace que ese empate se
		// resuelva a favor del añadido más reciente, que es lo que promete el docblock.
		$notices = array_reverse( self::stored(), true );

		uasort(
			$notices,
			static function ( array $a, array $b ): int {
				return ( (int) $b['time'] ) <=> ( (int) $a['time'] );
			}
		);

		return $notices;
	}

	/**
	 * Borra todos los avisos.
	 */
	public static function clear(): void {
		delete_option( VOCEADOR_PREFIX . self::OPTION );
	}

	/**
	 * Clave del aviso de un canal pausado.
	 *
	 * @param int $channel_id Canal.
	 * @return string
	 */
	public static function key_for_channel( int $channel_id ): string {
		return 'channel_paused_' . $channel_id;
	}

	/**
	 * Contenido de la opción.
	 *
	 * @return array
	 */
	private static function stored(): array {
		$notices = get_option( VOCEADOR_PREFIX . self::OPTION, array() );

		return is_array( $notices ) ? $notices : array();
	}

	/**
	 * Escribe la opción sin autoload.
	 *
	 * @param array $notices Avisos.
	 */
	private static function write( array $notices ): void {
		$option = VOCEADOR_PREFIX . self::OPTION;

		if ( false === get_option( $option ) ) {
			add_option( $option, $notices, '', false );
			return;
		}

		update_option( $option, $notices, false );
	}
}

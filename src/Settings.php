<?php
/**
 * Ajustes del plugin.
 *
 * @package Voceador
 */

namespace Voceador;

/**
 * Resuelve cada ajuste en este orden: canal → sitio → red → default en código.
 */
final class Settings {

	/**
	 * Nombre corto de la opción del sitio.
	 */
	public const OPTION = 'settings';

	/**
	 * Nombre corto de la opción de red.
	 */
	public const NETWORK_OPTION = 'network_defaults';

	/**
	 * Valores por defecto.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		$defaults = array(
			'graph_version' => 'v26.0',
			'rules'         => array(
				'post_types' => array( 'post' ),
			),
			'templates'     => array(
				'caption' => "{title}\n\n{excerpt}",
				'comment' => '{permalink}',
			),
			'image'         => array(
				'fb_size'  => 'large',
				'fb_mode'  => 'file',
				'no_image' => 'skip',
			),
			'execution'     => array(
				'delay'               => 30,
				'comment_delay'       => 60,
				'comment_enabled'     => true,
				'retries'             => 5,
				'backoff_base'        => 120,
				'publish_scheduled'   => true,
				'publish_republished' => false,
			),
			'log'           => array(
				'level'          => 'info',
				'retention_days' => 30,
			),
			'uninstall'     => array(
				'delete_meta' => false,
			),
		);

		/**
		 * Permite cambiar los valores por defecto del plugin.
		 *
		 * @param array $defaults Árbol de ajustes.
		 */
		return (array) apply_filters( 'voceador_settings_defaults', $defaults );
	}

	/**
	 * Devuelve un ajuste por su ruta ("execution.delay").
	 *
	 * @param string $path              Ruta con puntos.
	 * @param array  $channel_overrides Ajustes propios del canal (misma forma que el árbol).
	 * @return mixed null si no existe en ningún nivel.
	 */
	public function get( string $path, array $channel_overrides = array() ): mixed {
		$keys = explode( '.', $path );

		foreach ( $this->layers( $channel_overrides ) as $layer ) {
			$value = self::dig( $layer, $keys );
			if ( null !== $value ) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * Árbol completo con todas las capas fusionadas (sin canal).
	 *
	 * @return array
	 */
	public function all(): array {
		$layers = array_reverse( $this->layers( array() ) );

		return array_replace_recursive( ...$layers );
	}

	/**
	 * Guarda ajustes del sitio fusionándolos con los existentes.
	 *
	 * @param array $values Árbol parcial.
	 */
	public function update( array $values ): void {
		$current = $this->site();
		$merged  = array_replace_recursive( $current, $values );

		if ( false === get_option( VOCEADOR_PREFIX . self::OPTION ) ) {
			add_option( VOCEADOR_PREFIX . self::OPTION, $merged, '', false );
			return;
		}

		update_option( VOCEADOR_PREFIX . self::OPTION, $merged, false );
	}

	/**
	 * Capas de mayor a menor prioridad.
	 *
	 * @param array $channel_overrides Ajustes del canal.
	 * @return array[]
	 */
	private function layers( array $channel_overrides ): array {
		$layers = array( $channel_overrides, $this->site() );

		if ( is_multisite() ) {
			$layers[] = (array) get_site_option( VOCEADOR_PREFIX . self::NETWORK_OPTION, array() );
		}

		$layers[] = self::defaults();

		return $layers;
	}

	/**
	 * Ajustes guardados en el sitio.
	 *
	 * @return array
	 */
	private function site(): array {
		$stored = get_option( VOCEADOR_PREFIX . self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Recorre un árbol por una lista de claves.
	 *
	 * @param array    $tree Árbol.
	 * @param string[] $keys Claves en orden.
	 * @return mixed null si falta alguna clave.
	 */
	private static function dig( array $tree, array $keys ): mixed {
		$node = $tree;

		foreach ( $keys as $key ) {
			if ( ! is_array( $node ) || ! array_key_exists( $key, $node ) ) {
				return null;
			}
			$node = $node[ $key ];
		}

		return $node;
	}
}

<?php
/**
 * Registro de tipos de canal.
 *
 * @package Voceador
 */

namespace Voceador\Channels;

/**
 * Conoce los tipos de canal disponibles y construye sus adaptadores.
 */
final class ChannelRegistry {

	/**
	 * Factories por tipo.
	 *
	 * @var array<string, callable>
	 */
	private array $factories;

	/**
	 * Adaptadores ya construidos.
	 *
	 * @var array<string, ChannelAdapter>
	 */
	private array $adapters = array();

	/**
	 * Si ya se aplicó el filtro de extensión.
	 *
	 * @var bool
	 */
	private bool $filtered = false;

	/**
	 * Constructor.
	 *
	 * @param array<string, callable> $factories Tipo => callable que devuelve un ChannelAdapter.
	 */
	public function __construct( array $factories = array() ) {
		$this->factories = $factories;
	}

	/**
	 * Registra un tipo.
	 *
	 * @param string   $type    Identificador.
	 * @param callable $factory Devuelve un ChannelAdapter.
	 */
	public function register( string $type, callable $factory ): void {
		$this->factories[ $type ] = $factory;

		// Invalida la instancia cacheada de este tipo: un registro con nueva factory
		// (p. ej. sobrescribir un tipo ya registrado) no debe seguir sirviendo el
		// adaptador construido con la factory anterior.
		unset( $this->adapters[ $type ] );
	}

	/**
	 * Tipos disponibles.
	 *
	 * @return array<string, string> Tipo => etiqueta.
	 */
	public function types(): array {
		$this->apply_filter();

		$types = array();
		foreach ( array_keys( $this->factories ) as $type ) {
			$types[ $type ] = $this->adapter( $type )::label();
		}

		return $types;
	}

	/**
	 * Indica si el tipo existe.
	 *
	 * @param string $type Identificador.
	 * @return bool
	 */
	public function has( string $type ): bool {
		$this->apply_filter();

		return isset( $this->factories[ $type ] );
	}

	/**
	 * Devuelve el adaptador de un tipo.
	 *
	 * @param string $type Identificador.
	 * @return ChannelAdapter
	 * @throws \InvalidArgumentException  Si el tipo no está registrado.
	 * @throws \UnexpectedValueException Si la factory no devuelve un ChannelAdapter.
	 */
	public function adapter( string $type ): ChannelAdapter {
		if ( isset( $this->adapters[ $type ] ) ) {
			return $this->adapters[ $type ];
		}

		if ( ! $this->has( $type ) ) {
			throw new \InvalidArgumentException( 'Tipo de canal desconocido: ' . esc_html( $type ) );
		}

		$adapter = ( $this->factories[ $type ] )();

		if ( ! $adapter instanceof ChannelAdapter ) {
			throw new \UnexpectedValueException( 'La factory del tipo ' . esc_html( $type ) . ' no devolvió un ChannelAdapter.' );
		}

		$this->adapters[ $type ] = $adapter;

		return $adapter;
	}

	/**
	 * Deja que otros plugins añadan tipos, una sola vez.
	 */
	private function apply_filter(): void {
		if ( $this->filtered ) {
			return;
		}
		$this->filtered = true;

		/**
		 * Permite registrar tipos de canal adicionales.
		 *
		 * @param array<string, callable> $factories Tipo => callable que devuelve un ChannelAdapter.
		 */
		$this->factories = (array) apply_filters( 'voceador_channel_types', $this->factories );
	}
}

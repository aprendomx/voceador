<?php
/**
 * Contenedor del plugin.
 *
 * @package Voceador
 */

namespace Voceador;

/**
 * Contenedor perezoso: instancia cada servicio la primera vez que se pide.
 *
 * Es el único punto del plugin con estado global. Los servicios reciben sus
 * dependencias por constructor y, si implementan Registrable, registran sus
 * propios hooks al arrancar.
 */
final class Plugin {

	/**
	 * Servicios que se instancian en boot() para registrar hooks.
	 */
	private const REGISTRABLES = array(
		Installer::class,
	);

	/**
	 * Instancia única del contenedor.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Factories por id de servicio.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = array();

	/**
	 * Servicios ya instanciados.
	 *
	 * @var array<string, object>
	 */
	private array $services = array();

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->register_factories();
	}

	/**
	 * Arranca el plugin. Llamarlo varias veces devuelve la misma instancia.
	 *
	 * @return Plugin
	 */
	public static function boot(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register_hooks();
		}

		return self::$instance;
	}

	/**
	 * Descarta la instancia. Solo para tests.
	 *
	 * No elimina los hooks registrados por la instancia anterior; en tests
	 * `WP_UnitTestCase` restaura `$wp_filter` al terminar cada test, y en
	 * producción no se invoca.
	 *
	 * @internal
	 */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * Devuelve un servicio, creándolo la primera vez.
	 *
	 * @param string $id Id del servicio (su nombre de clase).
	 * @return object
	 * @throws \InvalidArgumentException Si no hay factory registrada para el id.
	 */
	public function get( string $id ): object {
		if ( isset( $this->services[ $id ] ) ) {
			return $this->services[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \InvalidArgumentException( 'Servicio desconocido: ' . esc_html( $id ) );
		}

		$this->services[ $id ] = ( $this->factories[ $id ] )( $this );

		return $this->services[ $id ];
	}

	/**
	 * Indica si existe una factory para el id.
	 *
	 * @param string $id Id del servicio.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}

	/**
	 * Esquema de datos.
	 *
	 * @return Schema
	 */
	public function schema(): Schema {
		return $this->get( Schema::class );
	}

	/**
	 * Instalador.
	 *
	 * @return Installer
	 */
	public function installer(): Installer {
		return $this->get( Installer::class );
	}

	/**
	 * Declara cómo se construye cada servicio.
	 */
	private function register_factories(): void {
		$this->factories[ Schema::class ] = static function (): Schema {
			global $wpdb;
			return new Schema( $wpdb );
		};

		$this->factories[ Installer::class ] = static function ( Plugin $c ): Installer {
			return new Installer( $c->get( Schema::class ) );
		};

		$this->factories[ Crypto::class ] = static function (): Crypto {
			return new Crypto();
		};

		$this->factories[ Settings::class ] = static function (): Settings {
			return new Settings();
		};
	}

	/**
	 * Pide a cada servicio Registrable que enganche sus hooks.
	 */
	private function register_hooks(): void {
		foreach ( self::REGISTRABLES as $id ) {
			$service = $this->get( $id );
			if ( $service instanceof Registrable ) {
				$service->register_hooks();
			}
		}
	}
}

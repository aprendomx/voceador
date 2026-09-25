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
		Queue::class,
		Publisher::class,
		Trigger::class,
		OAuth\Facebook::class,
		TokenManager::class,
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

		$this->factories[ AppCredentials::class ] = static function ( Plugin $c ): AppCredentials {
			return new AppCredentials( $c->get( Crypto::class ) );
		};

		$this->factories[ Settings::class ] = static function (): Settings {
			return new Settings();
		};

		$this->factories[ Channels\ChannelRegistry::class ] = static function ( Plugin $c ): Channels\ChannelRegistry {
			return new Channels\ChannelRegistry(
				array(
					Channels\FacebookPageAdapter::type() => static fn() => $c->get( Channels\FacebookPageAdapter::class ),
				)
			);
		};

		$this->factories[ Channels\FacebookPageAdapter::class ] = static function ( Plugin $c ): Channels\FacebookPageAdapter {
			return new Channels\FacebookPageAdapter( $c->get( GraphClient::class ), $c->get( Settings::class ) );
		};

		$this->factories[ ChannelRepository::class ] = static function ( Plugin $c ): ChannelRepository {
			global $wpdb;
			return new ChannelRepository( $wpdb, $c->get( Schema::class ), $c->get( Crypto::class ) );
		};

		$this->factories[ JobRepository::class ] = static function ( Plugin $c ): JobRepository {
			global $wpdb;
			return new JobRepository( $wpdb, $c->get( Schema::class ) );
		};

		$this->factories[ Logger::class ] = static function ( Plugin $c ): Logger {
			global $wpdb;
			return new Logger( $wpdb, $c->get( Schema::class ), (string) $c->get( Settings::class )->get( 'log.level' ) );
		};

		$this->factories[ GraphClient::class ] = static function ( Plugin $c ): GraphClient {
			return new GraphClient( $c->get( Settings::class ), $c->get( Logger::class ) );
		};

		$this->factories[ Templates::class ] = static function ( Plugin $c ): Templates {
			return new Templates( $c->get( Settings::class ) );
		};

		$this->factories[ Queue::class ] = static function ( Plugin $c ): Queue {
			return new Queue( $c->get( JobRepository::class ), $c->get( Logger::class ) );
		};

		$this->factories[ Publisher::class ] = static function ( Plugin $c ): Publisher {
			return new Publisher(
				$c->get( JobRepository::class ),
				$c->get( ChannelRepository::class ),
				$c->get( Channels\ChannelRegistry::class ),
				$c->get( Templates::class ),
				$c->get( Settings::class ),
				$c->get( Logger::class ),
				$c->get( Queue::class )
			);
		};

		$this->factories[ Rules::class ] = static function ( Plugin $c ): Rules {
			return new Rules( $c->get( ChannelRepository::class ), $c->get( Settings::class ) );
		};

		$this->factories[ Trigger::class ] = static function ( Plugin $c ): Trigger {
			return new Trigger( $c->get( Rules::class ), $c->get( JobRepository::class ), $c->get( Queue::class ), $c->get( Settings::class ), $c->get( Logger::class ) );
		};

		$this->factories[ CLI::class ] = static function ( Plugin $c ): CLI {
			return new CLI( $c->get( ChannelRepository::class ), $c->get( Channels\ChannelRegistry::class ), $c->get( Publisher::class ), $c->get( JobRepository::class ), $c->get( Queue::class ), $c->get( Logger::class ) );
		};

		$this->factories[ OAuth\Facebook::class ] = static function ( Plugin $c ): OAuth\Facebook {
			return new OAuth\Facebook(
				$c->get( AppCredentials::class ),
				$c->get( GraphClient::class ),
				$c->get( ChannelRepository::class ),
				$c->get( Crypto::class ),
				$c->get( Settings::class ),
				$c->get( Logger::class )
			);
		};

		$this->factories[ TokenManager::class ] = static function ( Plugin $c ): TokenManager {
			return new TokenManager( $c->get( AppCredentials::class ), $c->get( GraphClient::class ), $c->get( ChannelRepository::class ), $c->get( Logger::class ) );
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

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$this->get( CLI::class )->register();
		}
	}
}

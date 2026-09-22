# Voceador · Fase 1a (núcleo: infraestructura) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dejar listas, probadas y registradas en el contenedor las piezas de infraestructura sobre las que la fase 1b construye la publicación en Facebook: contenedor perezoso, cifrado, ajustes, contratos de canal, repositorios, log y cliente de Graph API.

**Architecture:** `Plugin` pasa a ser un contenedor perezoso con factories; cada servicio recibe dependencias por constructor y, si implementa `Registrable`, registra sus propios hooks. Los únicos puntos con SQL son `Schema`, `ChannelRepository`, `JobRepository` y `Logger`. `GraphClient` es el único que conoce HTTP y códigos de error de Meta: los traduce a `WP_Error` con una clase normalizada. Ningún token se guarda ni se registra en claro: `Crypto` cifra `channels.credentials` y `Logger::redact()` limpia todo contexto.

**Tech Stack:** PHP 8.1+ (readonly properties), WordPress 6.4+, libsodium (`sodium_crypto_secretbox`), `wp_remote_request`, PHPUnit 9.6 con la suite de WordPress en wp-env, PHPCS/WPCS.

**Spec:** `docs/superpowers/specs/2026-09-21-voceador-design.md` (secciones 2 "Arranque", "Capas", "Contrato del adaptador"; 3 "Tablas", "Opciones", "Cifrado"; 4 "Errores y reintentos"; 7 fila 1a).

## Global Constraints

- PHP 8.1+ y WordPress 6.4+; single site y Multisite.
- Sin Composer en runtime; HTTP solo con `wp_remote_*`.
- Namespace `Voceador`; opciones, metas, hooks, transients y tablas con prefijo tomado de `VOCEADOR_PREFIX` (nunca el literal salvo en `bootstrap.php`).
- Text domain `voceador`; cadenas de interfaz en español.
- Opciones con `autoload = no` salvo `voceador_db_version`.
- Secretos: App Secret y tokens cifrados con libsodium; clave de `VOCEADOR_ENCRYPTION_KEY` si existe, si no derivada de `AUTH_KEY`/`SECURE_AUTH_KEY`; formato `v1:` + base64( nonce + cifrado ). Nunca se muestran ni se registran tokens.
- Clases de error normalizadas (`WP_Error->get_error_code()`): `transient`, `rate_limited`, `auth`, `permission`, `media`, `spam`, `fatal`. Mapa ajustable con el filtro `voceador_error_map`. Transient: Graph 1, 2, 4, 17, 32, 341, HTTP 5xx, timeouts. Auth: 190. Permission: 10, 200-299. Spam: 368. Fatal: 100 y resto.
- Filtro `voceador_channel_types` para registrar tipos de canal sin tocar el núcleo.
- Versión de Graph API configurable; default `v26.0` (verificada el 2026-09-22; v20.0 vence el 2026-09-24).
- Fechas en tablas: `DATETIME` UTC (`current_time( 'mysql', true )`).
- `dbDelta`: una columna por línea, dos espacios tras `PRIMARY KEY`, `KEY` no `INDEX`, sin comillas invertidas. `dbDelta` nunca borra índices: eso va en `Schema::migrate()`.
- Todo pasa `npm run lint` (WPCS): `array()`, tabs, Yoda, docblocks en clases y métodos (los tests están exentos de docblocks y de las reglas `WordPress.DB`). Si PHPCS exige docblock por propiedad (`Squiz.Commenting.VariableComment.Missing`) en `Channel`, `Job` o en constructores con promoción de propiedades, añade un `/** @var tipo Descripción. */` de una línea por propiedad; no desactives la regla.
- Tests: `WP_UnitTestCase` con tablas temporales; `@group ms-required` va en el docblock **de la clase**, justo encima de `class`.
- Commits en español, `tipo: descripción`, terminados en `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.
- Nunca `wp plugin uninstall voceador` sin `--skip-delete`.

## File Structure

| Archivo | Responsabilidad |
| --- | --- |
| `src/Registrable.php` | Contrato: el servicio registra sus propios hooks |
| `src/Plugin.php` (modificar) | Contenedor perezoso: factories, `get()`, `reset()`, recorre `Registrable` |
| `src/Installer.php` (modificar) | Pasa a implementar `Registrable` |
| `src/Schema.php` (modificar) | `DB_VERSION` 2: índice `(status, scheduled_at)`, quita `KEY type`; `migrate()` con la primera migración real |
| `src/Crypto.php` | Cifrado simétrico de secretos |
| `src/Settings.php` | Defaults y resolución canal → sitio → red → código |
| `src/Channels/Channel.php` | Entidad canal (credenciales ya descifradas) |
| `src/Channels/Media.php`, `Payload.php`, `RemoteResult.php`, `RemoteStatus.php`, `HealthReport.php`, `UsageLimits.php` | Objetos de valor del contrato |
| `src/Channels/ChannelAdapter.php` | Interfaz del adaptador |
| `src/Channels/ChannelRegistry.php` | Tipos de canal registrables |
| `src/ChannelRepository.php` | CRUD de canales con cifrado de credenciales |
| `src/Job.php`, `src/JobRepository.php` | Entidad trabajo y acceso a `voceador_jobs` con idempotencia y claim atómico |
| `src/Logger.php` | Escritura en `voceador_log` con redacción |
| `src/GraphClient.php` | HTTP contra Graph API y normalización de errores |
| `tests/phpunit/Fixtures/FakeAdapter.php` | Adaptador de prueba para el registry |

---

### Task 1: Contenedor perezoso y `Registrable`

**Files:**
- Create: `src/Registrable.php`
- Modify: `src/Plugin.php` (reescritura completa), `src/Installer.php` (añade `implements Registrable` y `register_hooks()`)
- Test: `tests/phpunit/PluginTest.php` (reescritura)

**Interfaces:**
- Consumes: `Voceador\Schema`, `Voceador\Installer` (fase 0).
- Produces:
  - `interface Voceador\Registrable { public function register_hooks(): void; }`
  - `Plugin::boot(): Plugin` (idempotente), `Plugin::reset(): void` (solo tests), `Plugin::get( string $id ): object` (lanza `\InvalidArgumentException` si no hay factory), `Plugin::has( string $id ): bool`, `Plugin::schema(): Schema`, `Plugin::installer(): Installer`.
  - Convención: el id de un servicio es su FQCN (`Schema::class`). Las tareas siguientes añaden una línea a `Plugin::register_factories()` por servicio nuevo.
  - `Plugin::REGISTRABLES`: lista de ids que se instancian en `boot()` para registrar hooks.

- [ ] **Step 1: Reescribir el test**

`tests/phpunit/PluginTest.php`:

```php
<?php
/**
 * @package Voceador
 */

use Voceador\Installer;
use Voceador\Plugin;
use Voceador\Schema;

class PluginTest extends WP_UnitTestCase {

	public function tear_down(): void {
		Plugin::reset();
		Plugin::boot();
		parent::tear_down();
	}

	public function test_boot_returns_the_same_instance(): void {
		$this->assertSame( Plugin::boot(), Plugin::boot() );
	}

	public function test_reset_discards_the_instance(): void {
		$first = Plugin::boot();
		Plugin::reset();
		$this->assertNotSame( $first, Plugin::boot() );
	}

	public function test_get_returns_a_shared_lazy_instance(): void {
		$plugin = Plugin::boot();

		$this->assertTrue( $plugin->has( Schema::class ) );
		$this->assertInstanceOf( Schema::class, $plugin->get( Schema::class ) );
		$this->assertSame( $plugin->get( Schema::class ), $plugin->get( Schema::class ) );
		$this->assertSame( $plugin->get( Schema::class ), $plugin->schema() );
		$this->assertInstanceOf( Installer::class, $plugin->installer() );
	}

	public function test_get_rejects_unknown_services(): void {
		$this->assertFalse( Plugin::boot()->has( 'Voceador\\NoExiste' ) );
		$this->expectException( InvalidArgumentException::class );
		Plugin::boot()->get( 'Voceador\\NoExiste' );
	}

	public function test_registrables_register_their_hooks_on_boot(): void {
		Plugin::reset();
		$installer = Plugin::boot()->installer();

		$this->assertSame( 0, has_action( 'init', array( $installer, 'maybe_install' ) ) );
		$this->assertSame( 20, has_action( 'wp_initialize_site', array( $installer, 'on_new_site' ) ) );
	}

	public function test_site_was_installed_during_bootstrap(): void {
		$this->assertSame( Schema::DB_VERSION, get_option( VOCEADOR_PREFIX . 'db_version' ) );
	}
}
```

- [ ] **Step 2: Ejecutar y verificar que falla**

Run: `npm run test -- --filter PluginTest`
Expected: FAIL con `Call to undefined method Voceador\Plugin::reset()`.

- [ ] **Step 3: Crear `src/Registrable.php`**

```php
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
```

- [ ] **Step 4: Reescribir `src/Plugin.php`**

```php
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
```

- [ ] **Step 5: Hacer `Installer` Registrable**

En `src/Installer.php` cambia la declaración de clase a `final class Installer implements Registrable {` y añade este método después del constructor:

```php
	/**
	 * Instalación perezosa en cada sitio y en los sitios nuevos de la red.
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'maybe_install' ), 0 );
		add_action( 'wp_initialize_site', array( $this, 'on_new_site' ), 20 );
	}
```

- [ ] **Step 6: Ejecutar toda la suite y el lint**

Run: `npm run test && npm run test:multisite && npm run lint`
Expected: todo `OK`; PHPCS sin errores.

- [ ] **Step 7: Commit**

```bash
git add src/Registrable.php src/Plugin.php src/Installer.php tests/phpunit/PluginTest.php
git commit -m "refactor: contenedor perezoso con factories y servicios Registrable"
```

---

### Task 2: Esquema versión 2 y primera migración real

**Files:**
- Modify: `src/Schema.php`, `.github/workflows/ci.yml` (el smoke espera `db_version` = `2`)
- Test: `tests/phpunit/SchemaTest.php` (añadir tests)

**Interfaces:**
- Consumes: `Schema` (fase 0).
- Produces: `Schema::DB_VERSION = '2'`; índice `status_scheduled (status, scheduled_at)` en `jobs`; `KEY type` de `channels` eliminado (era redundante con `UNIQUE type_remote`); `Schema::migrate( '1', '2' )` borra ese índice en instalaciones existentes; `Schema::has_index( string $table, string $index ): bool`.

- [ ] **Step 1: Añadir tests a `SchemaTest`**

Añade dentro de la clase `SchemaTest`:

```php
	public function test_db_version_is_2(): void {
		$this->assertSame( '2', Schema::DB_VERSION );
	}

	public function test_jobs_has_status_scheduled_index(): void {
		$this->assertTrue( $this->schema->has_index( 'jobs', 'status_scheduled' ) );
	}

	public function test_channels_has_no_redundant_type_index(): void {
		$this->assertTrue( $this->schema->has_index( 'channels', 'type_remote' ) );
		$this->assertFalse( $this->schema->has_index( 'channels', 'type' ) );
	}

	public function test_migrate_from_1_drops_type_index(): void {
		global $wpdb;
		$table = $this->schema->table( 'channels' );
		$wpdb->query( "ALTER TABLE {$table} ADD KEY type (type)" );
		$this->assertTrue( $this->schema->has_index( 'channels', 'type' ) );

		$this->schema->migrate( '1', '2' );

		$this->assertFalse( $this->schema->has_index( 'channels', 'type' ) );
	}

	public function test_migrate_is_a_noop_when_already_current(): void {
		$this->schema->migrate( '2', '2' );
		$this->assertSame( array(), $this->schema->pending_changes() );
	}

	public function test_has_index_rejects_unknown_tables(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->schema->has_index( 'posts', 'PRIMARY' );
	}
```

- [ ] **Step 2: Ejecutar y verificar que falla**

Run: `npm run test -- --filter SchemaTest`
Expected: FAIL (`DB_VERSION` es `'1'`, `has_index` no existe).

- [ ] **Step 3: Modificar `src/Schema.php`**

1. `public const DB_VERSION = '2';`
2. En la sentencia de `channels`, elimina la línea `KEY type (type),` (deja `UNIQUE KEY type_remote (type,remote_id),` y `KEY status (status)`).
3. En la sentencia de `jobs`, tras `KEY scheduled_at (scheduled_at)` añade una coma y la línea `KEY status_scheduled (status,scheduled_at)`.
4. Sustituye `migrate()` por:

```php
	/**
	 * Migraciones con datos entre versiones de esquema.
	 *
	 * dbDelta añade columnas e índices pero nunca los borra: lo que haya que
	 * eliminar o transformar se hace aquí, comparando versiones.
	 *
	 * @param string $from Versión instalada ('0' si es una instalación nueva).
	 * @param string $to   Versión destino.
	 */
	public function migrate( string $from, string $to ): void {
		if ( version_compare( $from, '2', '<' ) && version_compare( $to, '2', '>=' ) ) {
			// v2: KEY type era redundante con UNIQUE type_remote.
			$this->drop_index( 'channels', 'type' );
		}
	}

	/**
	 * Indica si una tabla tiene un índice.
	 *
	 * @param string $table Nombre corto de la tabla.
	 * @param string $index Nombre del índice.
	 * @return bool
	 */
	public function has_index( string $table, string $index ): bool {
		$name = $this->table( $table );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- El nombre de tabla viene de una lista cerrada.
		$found = $this->wpdb->get_var( $this->wpdb->prepare( "SHOW INDEX FROM {$name} WHERE Key_name = %s", $index ) );

		return null !== $found;
	}

	/**
	 * Borra un índice si existe.
	 *
	 * @param string $table Nombre corto de la tabla.
	 * @param string $index Nombre del índice.
	 */
	private function drop_index( string $table, string $index ): void {
		if ( ! $this->has_index( $table, $index ) ) {
			return;
		}

		$name = $this->table( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabla e índice vienen de listas cerradas del plugin.
		$this->wpdb->query( "ALTER TABLE {$name} DROP INDEX {$index}" );
	}
```

- [ ] **Step 4: Actualizar el smoke del CI**

En `.github/workflows/ci.yml`, en el paso "Smoke: activar y desinstalar", cambia `= "1"` por `= "2"` en la línea que comprueba `voceador_db_version`.

- [ ] **Step 5: Verificar la ruta de upgrade en el WordPress real**

```bash
npx wp-env run cli wp option update voceador_db_version 1
npx wp-env run cli wp db query "ALTER TABLE wp_voceador_channels ADD KEY type (type)"
npx wp-env run cli wp eval 'do_action("init");'
npx wp-env run cli wp option get voceador_db_version
npx wp-env run cli wp db query "SHOW INDEX FROM wp_voceador_channels WHERE Key_name='type'"
npx wp-env run cli wp db query "SHOW INDEX FROM wp_voceador_jobs WHERE Key_name='status_scheduled'"
```

Expected: la opción pasa a `2`; la primera consulta de índices no devuelve filas; la segunda devuelve dos filas (una por columna).

- [ ] **Step 6: Suite completa y lint**

Run: `npm run test && npm run test:multisite && npm run lint`
Expected: todo `OK`.

- [ ] **Step 7: Commit**

```bash
git add src/Schema.php tests/phpunit/SchemaTest.php .github/workflows/ci.yml
git commit -m "feat: esquema v2 con índice (status, scheduled_at) y primera migración"
```

---

### Task 3: `Crypto`

**Files:**
- Create: `src/Crypto.php`
- Modify: `src/Plugin.php` (factory)
- Test: `tests/phpunit/CryptoTest.php`

**Interfaces:**
- Consumes: nada del plugin.
- Produces: `new Crypto( ?string $key = null )` (32 bytes; `null` deriva la clave del sitio); `Crypto::is_available(): bool`; `encrypt( string $plain ): string` (formato `v1:` + base64); `decrypt( string $stored ): string|\WP_Error` (códigos `voceador_crypto_format`, `voceador_crypto_key`); `Crypto::derive_key(): string`.

- [ ] **Step 1: Escribir el test**

`tests/phpunit/CryptoTest.php`:

```php
<?php
/**
 * @package Voceador
 */

use Voceador\Crypto;

class CryptoTest extends WP_UnitTestCase {

	private function key( string $seed ): string {
		return str_pad( $seed, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'x' );
	}

	public function test_is_available(): void {
		$this->assertTrue( Crypto::is_available() );
	}

	public function test_roundtrip_with_explicit_key(): void {
		$crypto = new Crypto( $this->key( 'a' ) );
		$stored = $crypto->encrypt( 'EAAtoken-secreto' );

		$this->assertStringStartsWith( 'v1:', $stored );
		$this->assertStringNotContainsString( 'EAAtoken', $stored );
		$this->assertSame( 'EAAtoken-secreto', $crypto->decrypt( $stored ) );
	}

	public function test_each_encryption_uses_a_fresh_nonce(): void {
		$crypto = new Crypto( $this->key( 'a' ) );
		$this->assertNotSame( $crypto->encrypt( 'x' ), $crypto->encrypt( 'x' ) );
	}

	public function test_wrong_key_returns_error(): void {
		$stored = ( new Crypto( $this->key( 'a' ) ) )->encrypt( 'secreto' );
		$result = ( new Crypto( $this->key( 'b' ) ) )->decrypt( $stored );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'voceador_crypto_key', $result->get_error_code() );
	}

	public function test_malformed_input_returns_error(): void {
		$crypto = new Crypto( $this->key( 'a' ) );

		foreach ( array( '', 'sin-prefijo', 'v1:', 'v1:###', 'v1:' . base64_encode( 'corto' ), 'v2:' . base64_encode( str_repeat( 'x', 64 ) ) ) as $bad ) {
			$result = $crypto->decrypt( $bad );
			$this->assertInstanceOf( WP_Error::class, $result, $bad );
			$this->assertSame( 'voceador_crypto_format', $result->get_error_code(), $bad );
		}
	}

	public function test_derived_key_is_deterministic_and_32_bytes(): void {
		$this->assertSame( Crypto::derive_key(), Crypto::derive_key() );
		$this->assertSame( SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen( Crypto::derive_key() ) );
	}

	public function test_default_constructor_uses_derived_key(): void {
		$stored = ( new Crypto() )->encrypt( 'hola' );
		$this->assertSame( 'hola', ( new Crypto( Crypto::derive_key() ) )->decrypt( $stored ) );
	}

	public function test_empty_string_roundtrips(): void {
		$crypto = new Crypto( $this->key( 'a' ) );
		$this->assertSame( '', $crypto->decrypt( $crypto->encrypt( '' ) ) );
	}
}
```

- [ ] **Step 2: Ejecutar y verificar que falla**

Run: `npm run test -- --filter CryptoTest`
Expected: FAIL con `Class "Voceador\Crypto" not found`.

- [ ] **Step 3: Crear `src/Crypto.php`**

```php
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
			throw new \InvalidArgumentException( 'La clave de cifrado debe tener ' . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . ' bytes.' );
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
	 * Usa VOCEADOR_ENCRYPTION_KEY si está definida; si no, las salts de
	 * autenticación. Cambiarlas invalida todo lo cifrado.
	 *
	 * @return string
	 */
	public static function derive_key(): string {
		$material = defined( 'VOCEADOR_ENCRYPTION_KEY' )
			? (string) constant( 'VOCEADOR_ENCRYPTION_KEY' )
			: wp_salt( 'auth' ) . wp_salt( 'secure_auth' );

		return sodium_crypto_generichash( $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
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
```

- [ ] **Step 4: Registrar la factory**

En `Plugin::register_factories()` añade al final:

```php
		$this->factories[ Crypto::class ] = static function (): Crypto {
			return new Crypto();
		};
```

- [ ] **Step 5: Ejecutar tests y lint**

Run: `npm run test -- --filter CryptoTest && npm run lint`
Expected: `OK (8 tests, ...)`; PHPCS sin errores.

- [ ] **Step 6: Commit**

```bash
git add src/Crypto.php src/Plugin.php tests/phpunit/CryptoTest.php
git commit -m "feat: cifrado de secretos con libsodium"
```

---

### Task 4: `Settings` mínimo

**Files:**
- Create: `src/Settings.php`
- Modify: `src/Plugin.php` (factory)
- Test: `tests/phpunit/SettingsTest.php`, `tests/phpunit/SettingsMultisiteTest.php`

**Interfaces:**
- Consumes: `VOCEADOR_PREFIX`.
- Produces: `Settings::OPTION = 'settings'`; `Settings::defaults(): array` (filtro `voceador_settings_defaults`); `get( string $path, array $channel_overrides = array() ): mixed` con rutas `a.b.c` y resolución canal → sitio → red → defaults (`null` si no existe en ninguno); `all(): array`; `update( array $values ): void` (fusión recursiva, autoload no). Claves definidas en `defaults()` que usa la fase 1b: `graph_version`, `rules.post_types`, `templates.caption`, `templates.comment`, `image.fb_size`, `image.fb_mode`, `image.no_image`, `execution.delay`, `execution.comment_delay`, `execution.comment_enabled`, `execution.retries`, `execution.backoff_base`, `execution.publish_scheduled`, `execution.publish_republished`, `log.level`, `log.retention_days`, `uninstall.delete_meta`.

- [ ] **Step 1: Escribir los tests**

`tests/phpunit/SettingsTest.php`:

```php
<?php
/**
 * @package Voceador
 */

use Voceador\Settings;

class SettingsTest extends WP_UnitTestCase {

	private Settings $settings;

	public function set_up(): void {
		parent::set_up();
		$this->settings = new Settings();
		delete_option( VOCEADOR_PREFIX . Settings::OPTION );
	}

	public function test_defaults_when_nothing_is_stored(): void {
		$this->assertSame( 'v26.0', $this->settings->get( 'graph_version' ) );
		$this->assertSame( 30, $this->settings->get( 'execution.delay' ) );
		$this->assertSame( 60, $this->settings->get( 'execution.comment_delay' ) );
		$this->assertSame( array( 'post' ), $this->settings->get( 'rules.post_types' ) );
		$this->assertSame( 'file', $this->settings->get( 'image.fb_mode' ) );
		$this->assertSame( 'skip', $this->settings->get( 'image.no_image' ) );
		$this->assertFalse( $this->settings->get( 'uninstall.delete_meta' ) );
	}

	public function test_unknown_path_is_null(): void {
		$this->assertNull( $this->settings->get( 'no.existe' ) );
		$this->assertNull( $this->settings->get( 'execution.no_existe' ) );
	}

	public function test_site_option_overrides_defaults(): void {
		$this->settings->update( array( 'execution' => array( 'delay' => 5 ) ) );

		$this->assertSame( 5, $this->settings->get( 'execution.delay' ) );
		$this->assertSame( 60, $this->settings->get( 'execution.comment_delay' ), 'Las claves no tocadas conservan el default.' );
	}

	public function test_update_merges_and_does_not_autoload(): void {
		global $wpdb;
		$this->settings->update( array( 'execution' => array( 'delay' => 5 ) ) );
		$this->settings->update( array( 'image' => array( 'fb_mode' => 'url' ) ) );

		$this->assertSame( 5, $this->settings->get( 'execution.delay' ) );
		$this->assertSame( 'url', $this->settings->get( 'image.fb_mode' ) );

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", VOCEADOR_PREFIX . Settings::OPTION ) );
		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	public function test_channel_overrides_win(): void {
		$this->settings->update( array( 'execution' => array( 'delay' => 5 ) ) );

		$this->assertSame( 0, $this->settings->get( 'execution.delay', array( 'execution' => array( 'delay' => 0 ) ) ) );
		$this->assertSame( 5, $this->settings->get( 'execution.delay', array( 'execution' => array( 'delay' => null ) ) ), 'null en el canal significa "heredar".' );
	}

	public function test_all_returns_merged_tree(): void {
		$this->settings->update( array( 'execution' => array( 'delay' => 5 ) ) );
		$all = $this->settings->all();

		$this->assertSame( 5, $all['execution']['delay'] );
		$this->assertSame( 'v26.0', $all['graph_version'] );
	}

	public function test_defaults_filter(): void {
		add_filter(
			'voceador_settings_defaults',
			static function ( array $defaults ): array {
				$defaults['graph_version'] = 'v25.0';
				return $defaults;
			}
		);

		$this->assertSame( 'v25.0', $this->settings->get( 'graph_version' ) );
	}
}
```

`tests/phpunit/SettingsMultisiteTest.php`:

```php
<?php
/**
 * @package Voceador
 */

use Voceador\Settings;

/**
 * @package Voceador
 * @group ms-required
 */
class SettingsMultisiteTest extends WP_UnitTestCase {

	public function test_network_defaults_sit_between_site_and_code(): void {
		$settings = new Settings();
		delete_option( VOCEADOR_PREFIX . Settings::OPTION );
		update_site_option( VOCEADOR_PREFIX . 'network_defaults', array( 'execution' => array( 'delay' => 10 ) ) );

		$this->assertSame( 10, $settings->get( 'execution.delay' ) );

		$settings->update( array( 'execution' => array( 'delay' => 3 ) ) );
		$this->assertSame( 3, $settings->get( 'execution.delay' ) );

		delete_site_option( VOCEADOR_PREFIX . 'network_defaults' );
	}
}
```

- [ ] **Step 2: Ejecutar y verificar que falla**

Run: `npm run test -- --filter SettingsTest`
Expected: FAIL con `Class "Voceador\Settings" not found`.

- [ ] **Step 3: Crear `src/Settings.php`**

```php
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
```

- [ ] **Step 4: Registrar la factory**

En `Plugin::register_factories()` añade:

```php
		$this->factories[ Settings::class ] = static function (): Settings {
			return new Settings();
		};
```

- [ ] **Step 5: Tests y lint**

Run: `npm run test -- --filter SettingsTest && npm run test:multisite -- --filter SettingsMultisiteTest && npm run lint`
Expected: `OK (7 tests, ...)`, `OK (1 test, ...)`, PHPCS sin errores.

- [ ] **Step 6: Commit**

```bash
git add src/Settings.php src/Plugin.php tests/phpunit/SettingsTest.php tests/phpunit/SettingsMultisiteTest.php
git commit -m "feat: ajustes con resolución canal, sitio, red y defaults"
```

---

### Task 5: Contratos de canal y `ChannelRegistry`

**Files:**
- Create: `src/Channels/Channel.php`, `src/Channels/Media.php`, `src/Channels/Payload.php`, `src/Channels/RemoteResult.php`, `src/Channels/RemoteStatus.php`, `src/Channels/HealthReport.php`, `src/Channels/UsageLimits.php`, `src/Channels/ChannelAdapter.php`, `src/Channels/ChannelRegistry.php`, `tests/phpunit/Fixtures/FakeAdapter.php`
- Modify: `src/Plugin.php` (factory)
- Test: `tests/phpunit/ChannelRegistryTest.php`, `tests/phpunit/ChannelTest.php`

**Interfaces:**
- Consumes: nada.
- Produces (namespace `Voceador\Channels`):
  - `Channel` con propiedades `readonly` públicas: `int $id`, `string $type`, `string $alias`, `string $remote_id`, `string $remote_name`, `?string $avatar_url`, `string $connection_method`, `?int $parent_channel_id`, `array $credentials`, `array $scopes`, `?string $token_expires_at`, `string $status`, `array $health`, `?string $health_checked_at`, `array $settings`, `string $created_at`, `string $updated_at`; `__construct( array $data )`; `credential( string $key ): ?string`; `is_active(): bool`; `has_scope( string $scope ): bool`.
  - `Media( string $path = '', string $url = '', string $mime = 'image/jpeg', int $bytes = 0 )` + `is_empty(): bool`.
  - `Payload( string $caption = '', ?Media $media = null, string $link = '', string $comment = '', array $extra = array() )`.
  - `RemoteResult( string $id, string $url = '', array $raw = array() )`.
  - `RemoteStatus( bool $exists, string $url = '', array $raw = array() )`.
  - `HealthReport( bool $valid, string $message = '', array $scopes = array(), array $missing_scopes = array(), ?string $expires_at = null, array $raw = array() )`.
  - `UsageLimits( int $used, int $quota, ?int $resets_at = null )` + `has_capacity(): bool`.
  - `interface ChannelAdapter { public static function type(): string; public static function label(): string; public function validate_credentials( Channel $c ): HealthReport; public function prepare_media( Channel $c, \WP_Post $p ): Media|\WP_Error; public function publish( Channel $c, Payload $payload ): RemoteResult|\WP_Error; public function comment( Channel $c, string $remote_id, string $text ): RemoteResult|\WP_Error; public function status( Channel $c, string $remote_id ): RemoteStatus; public function usage_limits( Channel $c ): ?UsageLimits; public function settings_schema(): array; }`
  - `ChannelRegistry::__construct( array $factories = array() )` (tipo => callable que devuelve `ChannelAdapter`); `register( string $type, callable $factory ): void`; `types(): array` (tipo => label); `has( string $type ): bool`; `adapter( string $type ): ChannelAdapter` (lanza `\InvalidArgumentException` si no existe; cachea la instancia). El filtro `voceador_channel_types` recibe el array tipo => factory una sola vez, en el primer acceso.

- [ ] **Step 1: Crear el adaptador de prueba**

`tests/phpunit/Fixtures/FakeAdapter.php`:

```php
<?php
/**
 * @package Voceador
 */

namespace Voceador\Tests\Fixtures;

use Voceador\Channels\Channel;
use Voceador\Channels\ChannelAdapter;
use Voceador\Channels\HealthReport;
use Voceador\Channels\Media;
use Voceador\Channels\Payload;
use Voceador\Channels\RemoteResult;
use Voceador\Channels\RemoteStatus;
use Voceador\Channels\UsageLimits;

class FakeAdapter implements ChannelAdapter {

	public array $calls = array();

	public static function type(): string {
		return 'fake';
	}

	public static function label(): string {
		return 'Canal de prueba';
	}

	public function validate_credentials( Channel $c ): HealthReport {
		$this->calls[] = 'validate';
		return new HealthReport( true, 'ok', array( 'scope_a' ) );
	}

	public function prepare_media( Channel $c, \WP_Post $p ): Media|\WP_Error {
		$this->calls[] = 'prepare';
		return new Media( '', 'https://example.com/img.jpg' );
	}

	public function publish( Channel $c, Payload $payload ): RemoteResult|\WP_Error {
		$this->calls[] = 'publish';
		return new RemoteResult( 'remote-1', 'https://example.com/p/1' );
	}

	public function comment( Channel $c, string $remote_id, string $text ): RemoteResult|\WP_Error {
		$this->calls[] = 'comment';
		return new RemoteResult( 'comment-1' );
	}

	public function status( Channel $c, string $remote_id ): RemoteStatus {
		return new RemoteStatus( true );
	}

	public function usage_limits( Channel $c ): ?UsageLimits {
		return null;
	}

	public function settings_schema(): array {
		return array();
	}
}
```

Y en `tests/bootstrap.php`, dentro del closure de `muplugins_loaded`, justo después de la línea `require $voceador_root . '/voceador.php';`, añade (debe cargarse tras el plugin para que la interfaz `ChannelAdapter` exista):

```php
		require $voceador_root . '/tests/phpunit/Fixtures/FakeAdapter.php';
```

- [ ] **Step 2: Escribir los tests**

`tests/phpunit/ChannelTest.php`:

```php
<?php
/**
 * @package Voceador
 */

use Voceador\Channels\Channel;
use Voceador\Channels\Media;
use Voceador\Channels\UsageLimits;

class ChannelTest extends WP_UnitTestCase {

	public function test_hydrates_from_array_with_defaults(): void {
		$channel = new Channel(
			array(
				'id'          => '7',
				'type'        => 'facebook_page',
				'alias'       => 'Principal',
				'remote_id'   => '123',
				'credentials' => array( 'access_token' => 'EAAx' ),
				'scopes'      => array( 'pages_manage_posts' ),
			)
		);

		$this->assertSame( 7, $channel->id );
		$this->assertSame( 'active', $channel->status );
		$this->assertSame( 'manual', $channel->connection_method );
		$this->assertNull( $channel->parent_channel_id );
		$this->assertSame( array(), $channel->settings );
		$this->assertSame( 'EAAx', $channel->credential( 'access_token' ) );
		$this->assertNull( $channel->credential( 'nope' ) );
		$this->assertTrue( $channel->is_active() );
		$this->assertTrue( $channel->has_scope( 'pages_manage_posts' ) );
		$this->assertFalse( $channel->has_scope( 'pages_manage_engagement' ) );
	}

	public function test_paused_channel_is_not_active(): void {
		$channel = new Channel( array( 'status' => 'paused' ) );
		$this->assertFalse( $channel->is_active() );
	}

	public function test_media_and_usage_helpers(): void {
		$this->assertTrue( ( new Media() )->is_empty() );
		$this->assertFalse( ( new Media( '/tmp/a.jpg' ) )->is_empty() );
		$this->assertTrue( ( new UsageLimits( 10, 25 ) )->has_capacity() );
		$this->assertFalse( ( new UsageLimits( 25, 25 ) )->has_capacity() );
	}
}
```

`tests/phpunit/ChannelRegistryTest.php`:

```php
<?php
/**
 * @package Voceador
 */

use Voceador\Channels\ChannelRegistry;
use Voceador\Tests\Fixtures\FakeAdapter;

class ChannelRegistryTest extends WP_UnitTestCase {

	public function test_register_and_resolve(): void {
		$registry = new ChannelRegistry();
		$registry->register( 'fake', static fn() => new FakeAdapter() );

		$this->assertTrue( $registry->has( 'fake' ) );
		$this->assertSame( array( 'fake' => 'Canal de prueba' ), $registry->types() );
		$this->assertInstanceOf( FakeAdapter::class, $registry->adapter( 'fake' ) );
		$this->assertSame( $registry->adapter( 'fake' ), $registry->adapter( 'fake' ), 'La instancia se cachea.' );
	}

	public function test_unknown_type_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		( new ChannelRegistry() )->adapter( 'nope' );
	}

	public function test_filter_can_add_types(): void {
		add_filter(
			'voceador_channel_types',
			static function ( array $types ): array {
				$types['fake'] = static fn() => new FakeAdapter();
				return $types;
			}
		);

		$registry = new ChannelRegistry();

		$this->assertTrue( $registry->has( 'fake' ) );
		$this->assertInstanceOf( FakeAdapter::class, $registry->adapter( 'fake' ) );
	}

	public function test_factory_must_return_an_adapter(): void {
		$registry = new ChannelRegistry( array( 'bad' => static fn() => new stdClass() ) );
		$this->expectException( UnexpectedValueException::class );
		$registry->adapter( 'bad' );
	}
}
```

- [ ] **Step 3: Ejecutar y verificar que falla**

Run: `npm run test -- --filter 'ChannelTest|ChannelRegistryTest'`
Expected: FAIL (el bootstrap falla al cargar `FakeAdapter` porque `ChannelAdapter` no existe, o `Class not found`).

- [ ] **Step 4: Crear los objetos de valor**

`src/Channels/Media.php`:

```php
<?php
/**
 * Imagen preparada para un canal.
 *
 * @package Voceador
 */

namespace Voceador\Channels;

/**
 * Archivo local y/o URL pública de la imagen a publicar.
 */
final class Media {

	/**
	 * Constructor.
	 *
	 * @param string $path  Ruta local del archivo ('' si solo hay URL).
	 * @param string $url   URL pública ('' si solo hay archivo).
	 * @param string $mime  Tipo MIME.
	 * @param int    $bytes Tamaño en bytes (0 si se desconoce).
	 */
	public function __construct(
		public readonly string $path = '',
		public readonly string $url = '',
		public readonly string $mime = 'image/jpeg',
		public readonly int $bytes = 0
	) {}

	/**
	 * Indica si no hay imagen.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return '' === $this->path && '' === $this->url;
	}
}
```

`src/Channels/Payload.php`:

```php
<?php
/**
 * Contenido listo para publicar en un canal.
 *
 * @package Voceador
 */

namespace Voceador\Channels;

/**
 * Caption, imagen, enlace y comentario ya renderizados.
 */
final class Payload {

	/**
	 * Constructor.
	 *
	 * @param string     $caption Texto principal.
	 * @param Media|null $media   Imagen, o null si no hay.
	 * @param string     $link    Enlace a la nota.
	 * @param string     $comment Texto del comentario ('' si no hay).
	 * @param array      $extra   Datos adicionales específicos del canal.
	 */
	public function __construct(
		public readonly string $caption = '',
		public readonly ?Media $media = null,
		public readonly string $link = '',
		public readonly string $comment = '',
		public readonly array $extra = array()
	) {}
}
```

`src/Channels/RemoteResult.php`:

```php
<?php
/**
 * Resultado de una publicación o comentario remoto.
 *
 * @package Voceador
 */

namespace Voceador\Channels;

/**
 * Id remoto, URL pública y respuesta cruda.
 */
final class RemoteResult {

	/**
	 * Constructor.
	 *
	 * @param string $id  Id remoto.
	 * @param string $url URL pública ('' si no se conoce).
	 * @param array  $raw Respuesta cruda de la API.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $url = '',
		public readonly array $raw = array()
	) {}
}
```

`src/Channels/RemoteStatus.php`:

```php
<?php
/**
 * Estado de una publicación remota.
 *
 * @package Voceador
 */

namespace Voceador\Channels;

/**
 * Indica si la publicación sigue existiendo y dónde.
 */
final class RemoteStatus {

	/**
	 * Constructor.
	 *
	 * @param bool   $exists Si la publicación existe.
	 * @param string $url    URL pública.
	 * @param array  $raw    Respuesta cruda.
	 */
	public function __construct(
		public readonly bool $exists,
		public readonly string $url = '',
		public readonly array $raw = array()
	) {}
}
```

`src/Channels/HealthReport.php`:

```php
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
```

`src/Channels/UsageLimits.php`:

```php
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
```

- [ ] **Step 5: Crear `src/Channels/Channel.php`**

```php
<?php
/**
 * Entidad canal.
 *
 * @package Voceador
 */

namespace Voceador\Channels;

/**
 * Un destino de publicación con sus credenciales ya descifradas.
 */
final class Channel {

	public readonly int $id;
	public readonly string $type;
	public readonly string $alias;
	public readonly string $remote_id;
	public readonly string $remote_name;
	public readonly ?string $avatar_url;
	public readonly string $connection_method;
	public readonly ?int $parent_channel_id;
	public readonly array $credentials;
	public readonly array $scopes;
	public readonly ?string $token_expires_at;
	public readonly string $status;
	public readonly array $health;
	public readonly ?string $health_checked_at;
	public readonly array $settings;
	public readonly string $created_at;
	public readonly string $updated_at;

	/**
	 * Constructor.
	 *
	 * @param array $data Campos con los nombres de la tabla; los que falten toman su default.
	 */
	public function __construct( array $data ) {
		$this->id                = (int) ( $data['id'] ?? 0 );
		$this->type              = (string) ( $data['type'] ?? '' );
		$this->alias             = (string) ( $data['alias'] ?? '' );
		$this->remote_id         = (string) ( $data['remote_id'] ?? '' );
		$this->remote_name       = (string) ( $data['remote_name'] ?? '' );
		$this->avatar_url        = isset( $data['avatar_url'] ) ? (string) $data['avatar_url'] : null;
		$this->connection_method = (string) ( $data['connection_method'] ?? 'manual' );
		$this->parent_channel_id = isset( $data['parent_channel_id'] ) ? (int) $data['parent_channel_id'] : null;
		$this->credentials       = is_array( $data['credentials'] ?? null ) ? $data['credentials'] : array();
		$this->scopes            = is_array( $data['scopes'] ?? null ) ? array_values( $data['scopes'] ) : array();
		$this->token_expires_at  = isset( $data['token_expires_at'] ) ? (string) $data['token_expires_at'] : null;
		$this->status            = (string) ( $data['status'] ?? 'active' );
		$this->health            = is_array( $data['health'] ?? null ) ? $data['health'] : array();
		$this->health_checked_at = isset( $data['health_checked_at'] ) ? (string) $data['health_checked_at'] : null;
		$this->settings          = is_array( $data['settings'] ?? null ) ? $data['settings'] : array();
		$this->created_at        = (string) ( $data['created_at'] ?? '' );
		$this->updated_at        = (string) ( $data['updated_at'] ?? '' );
	}

	/**
	 * Devuelve una credencial por clave.
	 *
	 * @param string $key Clave ("access_token").
	 * @return string|null
	 */
	public function credential( string $key ): ?string {
		return isset( $this->credentials[ $key ] ) ? (string) $this->credentials[ $key ] : null;
	}

	/**
	 * Indica si el canal recibe publicaciones.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return 'active' === $this->status;
	}

	/**
	 * Indica si el canal tiene un permiso concedido.
	 *
	 * @param string $scope Permiso.
	 * @return bool
	 */
	public function has_scope( string $scope ): bool {
		return in_array( $scope, $this->scopes, true );
	}
}
```

- [ ] **Step 6: Crear `src/Channels/ChannelAdapter.php`**

```php
<?php
/**
 * Contrato de un tipo de canal.
 *
 * @package Voceador
 */

namespace Voceador\Channels;

/**
 * Todo lo específico de una red vive detrás de esta interfaz.
 *
 * Los métodos que hablan con la red devuelven WP_Error con una de las clases
 * normalizadas (transient, rate_limited, auth, permission, media, spam, fatal)
 * como código de error.
 */
interface ChannelAdapter {

	/**
	 * Identificador del tipo ("facebook_page").
	 *
	 * @return string
	 */
	public static function type(): string;

	/**
	 * Nombre para la interfaz.
	 *
	 * @return string
	 */
	public static function label(): string;

	/**
	 * Comprueba que las credenciales del canal sirven.
	 *
	 * @param Channel $c Canal.
	 * @return HealthReport
	 */
	public function validate_credentials( Channel $c ): HealthReport;

	/**
	 * Prepara la imagen del post para este canal.
	 *
	 * @param Channel  $c Canal.
	 * @param \WP_Post $p Post.
	 * @return Media|\WP_Error Media vacío si el post no tiene imagen y el canal lo tolera.
	 */
	public function prepare_media( Channel $c, \WP_Post $p ): Media|\WP_Error;

	/**
	 * Publica el contenido.
	 *
	 * @param Channel $c       Canal.
	 * @param Payload $payload Contenido renderizado.
	 * @return RemoteResult|\WP_Error
	 */
	public function publish( Channel $c, Payload $payload ): RemoteResult|\WP_Error;

	/**
	 * Deja un comentario en una publicación.
	 *
	 * @param Channel $c         Canal.
	 * @param string  $remote_id Id remoto de la publicación.
	 * @param string  $text      Texto.
	 * @return RemoteResult|\WP_Error
	 */
	public function comment( Channel $c, string $remote_id, string $text ): RemoteResult|\WP_Error;

	/**
	 * Consulta si una publicación sigue existiendo.
	 *
	 * @param Channel $c         Canal.
	 * @param string  $remote_id Id remoto.
	 * @return RemoteStatus
	 */
	public function status( Channel $c, string $remote_id ): RemoteStatus;

	/**
	 * Uso actual del límite de publicación, o null si la red no lo expone.
	 *
	 * @param Channel $c Canal.
	 * @return UsageLimits|null
	 */
	public function usage_limits( Channel $c ): ?UsageLimits;

	/**
	 * Campos de configuración propios del tipo, para la interfaz.
	 *
	 * @return array
	 */
	public function settings_schema(): array;
}
```

- [ ] **Step 7: Crear `src/Channels/ChannelRegistry.php`**

```php
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
```

- [ ] **Step 8: Registrar la factory**

En `Plugin::register_factories()` añade (la fase 1b registrará aquí `facebook_page`):

```php
		$this->factories[ Channels\ChannelRegistry::class ] = static function (): Channels\ChannelRegistry {
			return new Channels\ChannelRegistry();
		};
```

- [ ] **Step 9: Tests y lint**

Run: `npm run test -- --filter 'ChannelTest|ChannelRegistryTest' && npm run lint`
Expected: `OK (7 tests, ...)`; PHPCS sin errores. Si PHPCS exige docblocks por propiedad en `Channel`, añade un docblock de una línea a cada propiedad (`/** @var int */`, etc.) en vez de desactivar la regla.

- [ ] **Step 10: Commit**

```bash
git add src/Channels tests/phpunit/Fixtures tests/phpunit/ChannelTest.php tests/phpunit/ChannelRegistryTest.php tests/bootstrap.php src/Plugin.php
git commit -m "feat: contratos de canal, objetos de valor y registry extensible"
```

---

### Task 6: `ChannelRepository`

**Files:**
- Create: `src/ChannelRepository.php`
- Modify: `src/Plugin.php` (factory)
- Test: `tests/phpunit/ChannelRepositoryTest.php`

**Interfaces:**
- Consumes: `Schema::table()`, `Crypto::encrypt()/decrypt()`, `Channels\Channel`.
- Produces: `new ChannelRepository( \wpdb $wpdb, Schema $schema, Crypto $crypto )`; `insert( array $data ): int|\WP_Error` (`credentials`, `scopes`, `health`, `settings` se pasan como arrays; error `voceador_channel_exists` si ya existe `(type, remote_id)`, `voceador_channel_invalid` si faltan `type`, `alias` o `remote_id`); `update( int $id, array $data ): bool`; `find( int $id ): ?Channel`; `find_by_remote( string $type, string $remote_id ): ?Channel`; `all( array $args = array() ): Channel[]` (filtros `type`, `status`); `active( ?string $type = null ): Channel[]`; `set_status( int $id, string $status, array $health = array() ): bool` (también actualiza `health_checked_at`); `delete( int $id ): bool`. Un canal cuyas credenciales no se pueden descifrar se hidrata con `status = 'error'` y `health['error'] = 'credentials'` (en memoria; la persistencia la hace el cron de salud de la fase 2).

- [ ] **Step 1: Escribir el test**

`tests/phpunit/ChannelRepositoryTest.php`:

```php
<?php
/**
 * @package Voceador
 */

use Voceador\ChannelRepository;
use Voceador\Channels\Channel;
use Voceador\Crypto;
use Voceador\Schema;

class ChannelRepositoryTest extends WP_UnitTestCase {

	private ChannelRepository $repo;
	private Crypto $crypto;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->crypto = new Crypto( str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );
		$this->repo   = new ChannelRepository( $wpdb, new Schema( $wpdb ), $this->crypto );
	}

	private function page( array $overrides = array() ): array {
		return array_merge(
			array(
				'type'        => 'facebook_page',
				'alias'       => 'Principal',
				'remote_id'   => '1001',
				'remote_name' => 'Mi Página',
				'credentials' => array( 'access_token' => 'EAAsecreto' ),
				'scopes'      => array( 'pages_manage_posts' ),
			),
			$overrides
		);
	}

	public function test_insert_and_find_roundtrip(): void {
		$id = $this->repo->insert( $this->page() );
		$this->assertIsInt( $id );

		$channel = $this->repo->find( $id );
		$this->assertInstanceOf( Channel::class, $channel );
		$this->assertSame( 'Principal', $channel->alias );
		$this->assertSame( 'EAAsecreto', $channel->credential( 'access_token' ) );
		$this->assertSame( array( 'pages_manage_posts' ), $channel->scopes );
		$this->assertSame( 'active', $channel->status );
		$this->assertSame( 'manual', $channel->connection_method );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} /', $channel->created_at );
	}

	public function test_credentials_are_stored_encrypted(): void {
		global $wpdb;
		$id  = $this->repo->insert( $this->page() );
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT credentials FROM ' . ( new Schema( $wpdb ) )->table( 'channels' ) . ' WHERE id = %d', $id ) );

		$this->assertStringStartsWith( 'v1:', $raw );
		$this->assertStringNotContainsString( 'EAAsecreto', $raw );
	}

	public function test_insert_rejects_duplicates_and_missing_fields(): void {
		$this->repo->insert( $this->page() );

		$dup = $this->repo->insert( $this->page( array( 'alias' => 'Otra' ) ) );
		$this->assertInstanceOf( WP_Error::class, $dup );
		$this->assertSame( 'voceador_channel_exists', $dup->get_error_code() );

		$bad = $this->repo->insert( array( 'type' => 'facebook_page' ) );
		$this->assertSame( 'voceador_channel_invalid', $bad->get_error_code() );
	}

	public function test_find_by_remote_and_missing(): void {
		$id = $this->repo->insert( $this->page() );

		$this->assertSame( $id, $this->repo->find_by_remote( 'facebook_page', '1001' )->id );
		$this->assertNull( $this->repo->find_by_remote( 'facebook_page', '9999' ) );
		$this->assertNull( $this->repo->find( 424242 ) );
	}

	public function test_all_and_active_filters(): void {
		$a = $this->repo->insert( $this->page() );
		$b = $this->repo->insert( $this->page( array( 'remote_id' => '1002', 'alias' => 'B' ) ) );
		$this->repo->insert( $this->page( array( 'type' => 'instagram_account', 'remote_id' => '2001', 'alias' => 'IG' ) ) );
		$this->repo->set_status( $b, 'paused' );

		$this->assertCount( 3, $this->repo->all() );
		$this->assertCount( 2, $this->repo->all( array( 'type' => 'facebook_page' ) ) );
		$this->assertCount( 1, $this->repo->all( array( 'status' => 'paused' ) ) );

		$active_fb = $this->repo->active( 'facebook_page' );
		$this->assertCount( 1, $active_fb );
		$this->assertSame( $a, $active_fb[0]->id );
		$this->assertCount( 2, $this->repo->active() );
	}

	public function test_update_reencrypts_credentials_and_keeps_others(): void {
		$id = $this->repo->insert( $this->page() );

		$this->assertTrue( $this->repo->update( $id, array( 'alias' => 'Renombrada', 'credentials' => array( 'access_token' => 'EAAnuevo' ) ) ) );

		$channel = $this->repo->find( $id );
		$this->assertSame( 'Renombrada', $channel->alias );
		$this->assertSame( 'EAAnuevo', $channel->credential( 'access_token' ) );
		$this->assertSame( '1001', $channel->remote_id );
	}

	public function test_set_status_records_health(): void {
		$id = $this->repo->insert( $this->page() );

		$this->assertTrue( $this->repo->set_status( $id, 'paused', array( 'message' => 'Token inválido' ) ) );

		$channel = $this->repo->find( $id );
		$this->assertSame( 'paused', $channel->status );
		$this->assertSame( 'Token inválido', $channel->health['message'] );
		$this->assertNotNull( $channel->health_checked_at );
	}

	public function test_undecryptable_credentials_mark_channel_in_error(): void {
		global $wpdb;
		$id = $this->repo->insert( $this->page() );

		$other = new ChannelRepository( $wpdb, new Schema( $wpdb ), new Crypto( str_repeat( 'z', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ) );
		$channel = $other->find( $id );

		$this->assertSame( 'error', $channel->status );
		$this->assertSame( 'credentials', $channel->health['error'] );
		$this->assertSame( array(), $channel->credentials );
	}

	public function test_delete(): void {
		$id = $this->repo->insert( $this->page() );
		$this->assertTrue( $this->repo->delete( $id ) );
		$this->assertNull( $this->repo->find( $id ) );
		$this->assertFalse( $this->repo->delete( $id ) );
	}
}
```

- [ ] **Step 2: Ejecutar y verificar que falla**

Run: `npm run test -- --filter ChannelRepositoryTest`
Expected: FAIL con `Class "Voceador\ChannelRepository" not found`.

- [ ] **Step 3: Crear `src/ChannelRepository.php`**

```php
<?php
/**
 * Acceso a la tabla de canales.
 *
 * @package Voceador
 */

namespace Voceador;

use Voceador\Channels\Channel;

/**
 * CRUD de canales. Cifra credentials al escribir y las descifra al leer.
 */
final class ChannelRepository {

	/**
	 * Columnas que se guardan como JSON.
	 */
	private const JSON_COLUMNS = array( 'scopes', 'health', 'settings' );

	/**
	 * Columnas escribibles.
	 */
	private const COLUMNS = array(
		'type',
		'alias',
		'remote_id',
		'remote_name',
		'avatar_url',
		'connection_method',
		'parent_channel_id',
		'credentials',
		'scopes',
		'token_expires_at',
		'status',
		'health',
		'health_checked_at',
		'settings',
	);

	/**
	 * Constructor.
	 *
	 * @param \wpdb  $wpdb   Conexión.
	 * @param Schema $schema Esquema.
	 * @param Crypto $crypto Cifrado.
	 */
	public function __construct(
		private \wpdb $wpdb,
		private Schema $schema,
		private Crypto $crypto
	) {}

	/**
	 * Crea un canal.
	 *
	 * @param array $data Campos; credentials, scopes, health y settings como arrays.
	 * @return int|\WP_Error Id nuevo, o voceador_channel_invalid / voceador_channel_exists.
	 */
	public function insert( array $data ): int|\WP_Error {
		foreach ( array( 'type', 'alias', 'remote_id' ) as $required ) {
			if ( empty( $data[ $required ] ) ) {
				return new \WP_Error( 'voceador_channel_invalid', sprintf( /* translators: %s: nombre del campo */ __( 'Falta el campo %s del canal.', 'voceador' ), $required ) );
			}
		}

		if ( null !== $this->find_by_remote( (string) $data['type'], (string) $data['remote_id'] ) ) {
			return new \WP_Error( 'voceador_channel_exists', __( 'Ese canal ya está conectado.', 'voceador' ) );
		}

		$now = current_time( 'mysql', true );
		$row = $this->serialize(
			array_merge(
				array(
					'connection_method' => 'manual',
					'status'            => 'active',
					'credentials'       => array(),
					'scopes'            => array(),
					'health'            => array(),
					'settings'          => array(),
				),
				$data
			)
		);

		$row['created_at'] = $now;
		$row['updated_at'] = $now;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $this->wpdb->insert( $this->table(), $row );

		if ( false === $inserted ) {
			return new \WP_Error( 'voceador_channel_exists', __( 'No se pudo guardar el canal.', 'voceador' ) );
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Actualiza campos de un canal.
	 *
	 * @param int   $id   Id.
	 * @param array $data Campos a cambiar (misma forma que insert()).
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		$row = $this->serialize( $data );
		if ( empty( $row ) ) {
			return false;
		}
		$row['updated_at'] = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $this->wpdb->update( $this->table(), $row, array( 'id' => $id ) );

		return false !== $updated;
	}

	/**
	 * Busca por id.
	 *
	 * @param int $id Id.
	 * @return Channel|null
	 */
	public function find( int $id ): ?Channel {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Busca por tipo e id remoto.
	 *
	 * @param string $type      Tipo.
	 * @param string $remote_id Id remoto.
	 * @return Channel|null
	 */
	public function find_by_remote( string $type, string $remote_id ): ?Channel {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->table()} WHERE type = %s AND remote_id = %s", $type, $remote_id ), ARRAY_A );

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Lista canales.
	 *
	 * @param array $args Filtros: type, status.
	 * @return Channel[]
	 */
	public function all( array $args = array() ): array {
		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $args['type'] ) ) {
			$where[]  = 'type = %s';
			$values[] = (string) $args['type'];
		}
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = (string) $args['status'];
		}

		$sql = "SELECT * FROM {$this->table()} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id ASC';
		if ( $values ) {
			$sql = $this->wpdb->prepare( $sql, ...$values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		return array_map( array( $this, 'hydrate' ), $rows ?: array() );
	}

	/**
	 * Canales activos.
	 *
	 * @param string|null $type Tipo, o null para todos.
	 * @return Channel[]
	 */
	public function active( ?string $type = null ): array {
		$args = array( 'status' => 'active' );
		if ( null !== $type ) {
			$args['type'] = $type;
		}

		return $this->all( $args );
	}

	/**
	 * Cambia el estado y registra el resultado de salud.
	 *
	 * @param int    $id     Id.
	 * @param string $status active, paused, error o disabled.
	 * @param array  $health Datos de salud a guardar.
	 * @return bool
	 */
	public function set_status( int $id, string $status, array $health = array() ): bool {
		return $this->update(
			$id,
			array(
				'status'            => $status,
				'health'            => $health,
				'health_checked_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Borra un canal.
	 *
	 * @param int $id Id.
	 * @return bool true si existía.
	 */
	public function delete( int $id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deleted = $this->wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );

		return 1 === $deleted;
	}

	/**
	 * Nombre de la tabla.
	 *
	 * @return string
	 */
	private function table(): string {
		return $this->schema->table( 'channels' );
	}

	/**
	 * Convierte una fila en entidad, descifrando credenciales.
	 *
	 * @param array $row Fila.
	 * @return Channel
	 */
	private function hydrate( array $row ): Channel {
		foreach ( self::JSON_COLUMNS as $column ) {
			$decoded        = json_decode( (string) ( $row[ $column ] ?? '' ), true );
			$row[ $column ] = is_array( $decoded ) ? $decoded : array();
		}

		$plain = '' === (string) $row['credentials'] ? '{}' : $this->crypto->decrypt( (string) $row['credentials'] );

		if ( is_wp_error( $plain ) ) {
			$row['credentials']     = array();
			$row['status']          = 'error';
			$row['health']['error'] = 'credentials';
		} else {
			$decoded            = json_decode( $plain, true );
			$row['credentials'] = is_array( $decoded ) ? $decoded : array();
		}

		return new Channel( $row );
	}

	/**
	 * Prepara un array de campos para escribirlo.
	 *
	 * @param array $data Campos.
	 * @return array Solo columnas conocidas, con JSON y cifrado aplicados.
	 */
	private function serialize( array $data ): array {
		$row = array();

		foreach ( self::COLUMNS as $column ) {
			if ( ! array_key_exists( $column, $data ) ) {
				continue;
			}

			$value = $data[ $column ];

			if ( 'credentials' === $column ) {
				$value = $this->crypto->encrypt( (string) wp_json_encode( is_array( $value ) ? $value : array() ) );
			} elseif ( in_array( $column, self::JSON_COLUMNS, true ) ) {
				$value = (string) wp_json_encode( is_array( $value ) ? $value : array() );
			}

			$row[ $column ] = $value;
		}

		return $row;
	}
}
```

- [ ] **Step 4: Registrar la factory**

En `Plugin::register_factories()` añade:

```php
		$this->factories[ ChannelRepository::class ] = static function ( Plugin $c ): ChannelRepository {
			global $wpdb;
			return new ChannelRepository( $wpdb, $c->get( Schema::class ), $c->get( Crypto::class ) );
		};
```

- [ ] **Step 5: Tests y lint**

Run: `npm run test -- --filter ChannelRepositoryTest && npm run lint`
Expected: `OK (9 tests, ...)`; PHPCS sin errores. Si PHPCS rechaza la promoción de constructor con `private` sin docblock por propiedad, añade el docblock `@param` (ya está) y, si insiste, un `// phpcs:ignore Squiz.Commenting.VariableComment.Missing` en la línea de la clase con una razón.

- [ ] **Step 6: Commit**

```bash
git add src/ChannelRepository.php src/Plugin.php tests/phpunit/ChannelRepositoryTest.php
git commit -m "feat: repositorio de canales con credenciales cifradas"
```

---

### Task 7: `Job` y `JobRepository`

**Files:**
- Create: `src/Job.php`, `src/JobRepository.php`
- Modify: `src/Plugin.php` (factory)
- Test: `tests/phpunit/JobRepositoryTest.php`

**Interfaces:**
- Consumes: `Schema::table( 'jobs' )`.
- Produces:
  - `Job` (`Voceador\Job`) con `readonly` públicas: `int $id`, `int $post_id`, `int $channel_id`, `string $status`, `string $comment_status`, `?string $remote_id`, `?string $remote_url`, `?string $remote_comment_id`, `?string $container_id`, `int $attempts`, `int $comment_attempts`, `?string $scheduled_at`, `?string $published_at`, `?string $error_code`, `?string $error_message`, `string $source`, `string $created_at`, `string $updated_at`; `__construct( array $row )`; `is_published(): bool`.
  - `JobRepository::__construct( \wpdb $wpdb, Schema $schema )`; `create_if_absent( int $post_id, int $channel_id, string $source = 'auto', ?string $scheduled_at = null ): ?int` (null si ya existía); `find( int $id ): ?Job`; `find_for_post( int $post_id ): Job[]`; `find_for_post_and_channel( int $post_id, int $channel_id ): ?Job`; `claim( int $id ): bool` (UPDATE atómico a `running` desde `pending|failed|rate_limited`, `attempts + 1`); `mark_published( int $id, string $remote_id, string $remote_url, bool $comment_pending ): bool`; `mark_failed( int $id, string $error_code, string $error_message ): bool`; `mark_rate_limited( int $id, string $scheduled_at, string $error_message ): bool`; `release( int $id, string $scheduled_at ): bool` (vuelve a `pending` con nueva fecha, conserva el error); `mark_skipped( int $id, string $reason ): bool`; `mark_comment_done( int $id, string $remote_comment_id ): bool`; `mark_comment_failed( int $id, string $error_message ): bool` (`comment_attempts + 1`); `due( int $limit = 50 ): Job[]` (`pending` con `scheduled_at` nula o vencida, ordenados por `scheduled_at`); `count_by_status(): array<string,int>`.

- [ ] **Step 1: Escribir el test**

`tests/phpunit/JobRepositoryTest.php`:

```php
<?php
/**
 * @package Voceador
 */

use Voceador\Job;
use Voceador\JobRepository;
use Voceador\Schema;

class JobRepositoryTest extends WP_UnitTestCase {

	private JobRepository $repo;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->repo = new JobRepository( $wpdb, new Schema( $wpdb ) );
	}

	public function test_create_if_absent_is_idempotent(): void {
		$id = $this->repo->create_if_absent( 10, 1 );
		$this->assertIsInt( $id );
		$this->assertNull( $this->repo->create_if_absent( 10, 1 ) );
		$this->assertIsInt( $this->repo->create_if_absent( 10, 2 ) );

		$job = $this->repo->find( $id );
		$this->assertInstanceOf( Job::class, $job );
		$this->assertSame( 'pending', $job->status );
		$this->assertSame( 'none', $job->comment_status );
		$this->assertSame( 'auto', $job->source );
		$this->assertSame( 0, $job->attempts );
		$this->assertNull( $job->scheduled_at );
	}

	public function test_create_with_source_and_schedule(): void {
		$id  = $this->repo->create_if_absent( 11, 1, 'manual', '2026-01-01 00:00:00' );
		$job = $this->repo->find( $id );

		$this->assertSame( 'manual', $job->source );
		$this->assertSame( '2026-01-01 00:00:00', $job->scheduled_at );
	}

	public function test_find_helpers(): void {
		$a = $this->repo->create_if_absent( 12, 1 );
		$this->repo->create_if_absent( 12, 2 );
		$this->repo->create_if_absent( 13, 1 );

		$this->assertCount( 2, $this->repo->find_for_post( 12 ) );
		$this->assertSame( $a, $this->repo->find_for_post_and_channel( 12, 1 )->id );
		$this->assertNull( $this->repo->find_for_post_and_channel( 12, 9 ) );
		$this->assertNull( $this->repo->find( 999999 ) );
	}

	public function test_claim_is_atomic_and_counts_attempts(): void {
		$id = $this->repo->create_if_absent( 14, 1 );

		$this->assertTrue( $this->repo->claim( $id ) );
		$this->assertFalse( $this->repo->claim( $id ), 'Un trabajo en running no se puede reclamar.' );

		$job = $this->repo->find( $id );
		$this->assertSame( 'running', $job->status );
		$this->assertSame( 1, $job->attempts );

		$this->repo->mark_failed( $id, 'transient', 'timeout' );
		$this->assertTrue( $this->repo->claim( $id ), 'Un trabajo fallido se puede reclamar.' );
		$this->assertSame( 2, $this->repo->find( $id )->attempts );
	}

	public function test_mark_published_and_comment_lifecycle(): void {
		$id = $this->repo->create_if_absent( 15, 1 );
		$this->repo->claim( $id );

		$this->assertTrue( $this->repo->mark_published( $id, '1001_2002', 'https://facebook.com/1001_2002', true ) );
		$job = $this->repo->find( $id );
		$this->assertTrue( $job->is_published() );
		$this->assertSame( '1001_2002', $job->remote_id );
		$this->assertSame( 'pending', $job->comment_status );
		$this->assertNotNull( $job->published_at );
		$this->assertNull( $job->error_code );

		$this->assertTrue( $this->repo->mark_comment_failed( $id, 'spam' ) );
		$job = $this->repo->find( $id );
		$this->assertSame( 'failed', $job->comment_status );
		$this->assertSame( 1, $job->comment_attempts );
		$this->assertSame( 'spam', $job->error_message );
		$this->assertSame( 'published', $job->status, 'El fallo del comentario no toca el estado principal.' );

		$this->assertTrue( $this->repo->mark_comment_done( $id, '1001_2002_3003' ) );
		$job = $this->repo->find( $id );
		$this->assertSame( 'done', $job->comment_status );
		$this->assertSame( '1001_2002_3003', $job->remote_comment_id );
	}

	public function test_published_without_comment(): void {
		$id = $this->repo->create_if_absent( 16, 1 );
		$this->repo->mark_published( $id, 'r', '', false );
		$this->assertSame( 'none', $this->repo->find( $id )->comment_status );
	}

	public function test_failed_rate_limited_release_and_skipped(): void {
		$id = $this->repo->create_if_absent( 17, 1 );

		$this->assertTrue( $this->repo->mark_failed( $id, 'auth', 'Token expirado' ) );
		$job = $this->repo->find( $id );
		$this->assertSame( 'failed', $job->status );
		$this->assertSame( 'auth', $job->error_code );
		$this->assertSame( 'Token expirado', $job->error_message );

		$this->assertTrue( $this->repo->mark_rate_limited( $id, '2030-01-01 00:00:00', 'Sin cupo' ) );
		$job = $this->repo->find( $id );
		$this->assertSame( 'rate_limited', $job->status );
		$this->assertSame( '2030-01-01 00:00:00', $job->scheduled_at );

		$this->assertTrue( $this->repo->release( $id, '2030-02-01 00:00:00' ) );
		$job = $this->repo->find( $id );
		$this->assertSame( 'pending', $job->status );
		$this->assertSame( '2030-02-01 00:00:00', $job->scheduled_at );
		$this->assertSame( 'Sin cupo', $job->error_message, 'release conserva el último error para diagnóstico.' );

		$this->assertTrue( $this->repo->mark_skipped( $id, 'Sin imagen destacada' ) );
		$this->assertSame( 'skipped', $this->repo->find( $id )->status );
	}

	public function test_due_returns_pending_jobs_in_order(): void {
		$late  = $this->repo->create_if_absent( 18, 1, 'auto', '2020-01-02 00:00:00' );
		$early = $this->repo->create_if_absent( 18, 2, 'auto', '2020-01-01 00:00:00' );
		$now   = $this->repo->create_if_absent( 18, 3 );
		$this->repo->create_if_absent( 18, 4, 'auto', '2099-01-01 00:00:00' );
		$claimed = $this->repo->create_if_absent( 18, 5 );
		$this->repo->claim( $claimed );

		$ids = array_map( static fn( Job $j ) => $j->id, $this->repo->due() );

		$this->assertSame( array( $now, $early, $late ), $ids );
		$this->assertCount( 2, $this->repo->due( 2 ) );
	}

	public function test_count_by_status(): void {
		$a = $this->repo->create_if_absent( 19, 1 );
		$this->repo->create_if_absent( 19, 2 );
		$this->repo->mark_failed( $a, 'fatal', 'x' );

		$counts = $this->repo->count_by_status();

		$this->assertSame( 1, $counts['pending'] );
		$this->assertSame( 1, $counts['failed'] );
		$this->assertSame( 0, $counts['published'] );
	}
}
```

- [ ] **Step 2: Ejecutar y verificar que falla**

Run: `npm run test -- --filter JobRepositoryTest`
Expected: FAIL con `Class "Voceador\JobRepository" not found`.

- [ ] **Step 3: Crear `src/Job.php`**

```php
<?php
/**
 * Entidad trabajo (post + canal).
 *
 * @package Voceador
 */

namespace Voceador;

/**
 * Estado de la publicación de un post en un canal.
 */
final class Job {

	public readonly int $id;
	public readonly int $post_id;
	public readonly int $channel_id;
	public readonly string $status;
	public readonly string $comment_status;
	public readonly ?string $remote_id;
	public readonly ?string $remote_url;
	public readonly ?string $remote_comment_id;
	public readonly ?string $container_id;
	public readonly int $attempts;
	public readonly int $comment_attempts;
	public readonly ?string $scheduled_at;
	public readonly ?string $published_at;
	public readonly ?string $error_code;
	public readonly ?string $error_message;
	public readonly string $source;
	public readonly string $created_at;
	public readonly string $updated_at;

	/**
	 * Constructor.
	 *
	 * @param array $row Fila de la tabla.
	 */
	public function __construct( array $row ) {
		$this->id                = (int) ( $row['id'] ?? 0 );
		$this->post_id           = (int) ( $row['post_id'] ?? 0 );
		$this->channel_id        = (int) ( $row['channel_id'] ?? 0 );
		$this->status            = (string) ( $row['status'] ?? 'pending' );
		$this->comment_status    = (string) ( $row['comment_status'] ?? 'none' );
		$this->remote_id         = isset( $row['remote_id'] ) ? (string) $row['remote_id'] : null;
		$this->remote_url        = isset( $row['remote_url'] ) ? (string) $row['remote_url'] : null;
		$this->remote_comment_id = isset( $row['remote_comment_id'] ) ? (string) $row['remote_comment_id'] : null;
		$this->container_id      = isset( $row['container_id'] ) ? (string) $row['container_id'] : null;
		$this->attempts          = (int) ( $row['attempts'] ?? 0 );
		$this->comment_attempts  = (int) ( $row['comment_attempts'] ?? 0 );
		$this->scheduled_at      = isset( $row['scheduled_at'] ) ? (string) $row['scheduled_at'] : null;
		$this->published_at      = isset( $row['published_at'] ) ? (string) $row['published_at'] : null;
		$this->error_code        = isset( $row['error_code'] ) ? (string) $row['error_code'] : null;
		$this->error_message     = isset( $row['error_message'] ) ? (string) $row['error_message'] : null;
		$this->source            = (string) ( $row['source'] ?? 'auto' );
		$this->created_at        = (string) ( $row['created_at'] ?? '' );
		$this->updated_at        = (string) ( $row['updated_at'] ?? '' );
	}

	/**
	 * Indica si ya se publicó.
	 *
	 * @return bool
	 */
	public function is_published(): bool {
		return 'published' === $this->status && null !== $this->remote_id;
	}
}
```

- [ ] **Step 4: Crear `src/JobRepository.php`**

```php
<?php
/**
 * Acceso a la tabla de trabajos.
 *
 * @package Voceador
 */

namespace Voceador;

/**
 * Crea trabajos de forma idempotente y gestiona sus transiciones de estado.
 */
final class JobRepository {

	/**
	 * Estados posibles.
	 */
	public const STATUSES = array( 'pending', 'running', 'published', 'failed', 'rate_limited', 'skipped' );

	/**
	 * Estados desde los que se puede reclamar un trabajo.
	 */
	private const CLAIMABLE = array( 'pending', 'failed', 'rate_limited' );

	/**
	 * Constructor.
	 *
	 * @param \wpdb  $wpdb   Conexión.
	 * @param Schema $schema Esquema.
	 */
	public function __construct(
		private \wpdb $wpdb,
		private Schema $schema
	) {}

	/**
	 * Crea el trabajo post+canal si no existe.
	 *
	 * @param int         $post_id      Post.
	 * @param int         $channel_id   Canal.
	 * @param string      $source       auto, manual, cli o test.
	 * @param string|null $scheduled_at Fecha UTC (MySQL) de ejecución, o null para "cuanto antes".
	 * @return int|null Id nuevo, o null si ya existía.
	 */
	public function create_if_absent( int $post_id, int $channel_id, string $source = 'auto', ?string $scheduled_at = null ): ?int {
		$now = current_time( 'mysql', true );

		// prepare() convierte null en '' y la columna DATETIME NULL acabaría como
		// fecha cero: el NULL se escribe literal en el SQL.
		if ( null === $scheduled_at ) {
			$sql  = "INSERT IGNORE INTO {$this->table()} (post_id, channel_id, source, scheduled_at, created_at, updated_at) VALUES (%d, %d, %s, NULL, %s, %s)";
			$args = array( $post_id, $channel_id, $source, $now, $now );
		} else {
			$sql  = "INSERT IGNORE INTO {$this->table()} (post_id, channel_id, source, scheduled_at, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, %s)";
			$args = array( $post_id, $channel_id, $source, $scheduled_at, $now, $now );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$this->wpdb->query( $this->wpdb->prepare( $sql, ...$args ) );

		return 1 === $this->wpdb->rows_affected ? (int) $this->wpdb->insert_id : null;
	}

	/**
	 * Busca por id.
	 *
	 * @param int $id Id.
	 * @return Job|null
	 */
	public function find( int $id ): ?Job {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), ARRAY_A );

		return $row ? new Job( $row ) : null;
	}

	/**
	 * Trabajos de un post.
	 *
	 * @param int $post_id Post.
	 * @return Job[]
	 */
	public function find_for_post( int $post_id ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->table()} WHERE post_id = %d ORDER BY id ASC", $post_id ), ARRAY_A );

		return array_map( static fn( array $row ) => new Job( $row ), $rows ?: array() );
	}

	/**
	 * Trabajo de un post en un canal.
	 *
	 * @param int $post_id    Post.
	 * @param int $channel_id Canal.
	 * @return Job|null
	 */
	public function find_for_post_and_channel( int $post_id, int $channel_id ): ?Job {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->table()} WHERE post_id = %d AND channel_id = %d", $post_id, $channel_id ), ARRAY_A );

		return $row ? new Job( $row ) : null;
	}

	/**
	 * Reclama un trabajo para ejecutarlo. Solo un proceso puede ganar.
	 *
	 * @param int $id Id.
	 * @return bool true si este proceso lo reclamó.
	 */
	public function claim( int $id ): bool {
		$placeholders = implode( ',', array_fill( 0, count( self::CLAIMABLE ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$this->table()} SET status = 'running', attempts = attempts + 1, updated_at = %s WHERE id = %d AND status IN ({$placeholders})",
				current_time( 'mysql', true ),
				$id,
				...self::CLAIMABLE
			)
		);

		return 1 === $this->wpdb->rows_affected;
	}

	/**
	 * Marca el trabajo como publicado.
	 *
	 * @param int    $id              Id.
	 * @param string $remote_id       Id remoto de la publicación.
	 * @param string $remote_url      URL pública.
	 * @param bool   $comment_pending Si queda pendiente el comentario.
	 * @return bool
	 */
	public function mark_published( int $id, string $remote_id, string $remote_url, bool $comment_pending ): bool {
		return $this->set(
			$id,
			array(
				'status'         => 'published',
				'remote_id'      => $remote_id,
				'remote_url'     => $remote_url,
				'published_at'   => current_time( 'mysql', true ),
				'comment_status' => $comment_pending ? 'pending' : 'none',
				'error_code'     => null,
				'error_message'  => null,
			)
		);
	}

	/**
	 * Marca el trabajo como fallido.
	 *
	 * @param int    $id            Id.
	 * @param string $error_code    Clase de error normalizada (más código Graph si se quiere).
	 * @param string $error_message Mensaje para la redacción.
	 * @return bool
	 */
	public function mark_failed( int $id, string $error_code, string $error_message ): bool {
		return $this->set(
			$id,
			array(
				'status'        => 'failed',
				'error_code'    => $error_code,
				'error_message' => $error_message,
			)
		);
	}

	/**
	 * Deja el trabajo en espera por límite de uso.
	 *
	 * @param int    $id            Id.
	 * @param string $scheduled_at  Cuándo reintentar (UTC, MySQL).
	 * @param string $error_message Explicación.
	 * @return bool
	 */
	public function mark_rate_limited( int $id, string $scheduled_at, string $error_message ): bool {
		return $this->set(
			$id,
			array(
				'status'        => 'rate_limited',
				'scheduled_at'  => $scheduled_at,
				'error_code'    => 'rate_limited',
				'error_message' => $error_message,
			)
		);
	}

	/**
	 * Devuelve el trabajo a pendiente para reintentarlo más tarde.
	 *
	 * @param int    $id           Id.
	 * @param string $scheduled_at Cuándo (UTC, MySQL).
	 * @return bool
	 */
	public function release( int $id, string $scheduled_at ): bool {
		return $this->set(
			$id,
			array(
				'status'       => 'pending',
				'scheduled_at' => $scheduled_at,
			)
		);
	}

	/**
	 * Marca el trabajo como omitido.
	 *
	 * @param int    $id     Id.
	 * @param string $reason Motivo.
	 * @return bool
	 */
	public function mark_skipped( int $id, string $reason ): bool {
		return $this->set(
			$id,
			array(
				'status'        => 'skipped',
				'error_code'    => null,
				'error_message' => $reason,
			)
		);
	}

	/**
	 * Registra el comentario publicado.
	 *
	 * @param int    $id                Id.
	 * @param string $remote_comment_id Id remoto del comentario.
	 * @return bool
	 */
	public function mark_comment_done( int $id, string $remote_comment_id ): bool {
		return $this->set(
			$id,
			array(
				'comment_status'    => 'done',
				'remote_comment_id' => $remote_comment_id,
			)
		);
	}

	/**
	 * Registra un fallo al comentar sin tocar el estado principal.
	 *
	 * @param int    $id            Id.
	 * @param string $error_message Mensaje.
	 * @return bool
	 */
	public function mark_comment_failed( int $id, string $error_message ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$this->table()} SET comment_status = 'failed', comment_attempts = comment_attempts + 1, error_message = %s, updated_at = %s WHERE id = %d",
				$error_message,
				current_time( 'mysql', true ),
				$id
			)
		);

		return 1 === $this->wpdb->rows_affected;
	}

	/**
	 * Trabajos pendientes cuya hora ya llegó.
	 *
	 * @param int $limit Máximo.
	 * @return Job[]
	 */
	public function due( int $limit = 50 ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE status = 'pending' AND (scheduled_at IS NULL OR scheduled_at <= %s) ORDER BY scheduled_at IS NULL DESC, scheduled_at ASC, id ASC LIMIT %d",
				current_time( 'mysql', true ),
				$limit
			),
			ARRAY_A
		);

		return array_map( static fn( array $row ) => new Job( $row ), $rows ?: array() );
	}

	/**
	 * Número de trabajos por estado (todos los estados presentes, aunque sea 0).
	 *
	 * @return array<string, int>
	 */
	public function count_by_status(): array {
		$counts = array_fill_keys( self::STATUSES, 0 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$this->table()} GROUP BY status", ARRAY_A );

		foreach ( $rows ?: array() as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['n'];
		}

		return $counts;
	}

	/**
	 * Nombre de la tabla.
	 *
	 * @return string
	 */
	private function table(): string {
		return $this->schema->table( 'jobs' );
	}

	/**
	 * Actualiza columnas de un trabajo.
	 *
	 * @param int   $id   Id.
	 * @param array $data Columnas => valores (null permitido).
	 * @return bool
	 */
	private function set( int $id, array $data ): bool {
		$data['updated_at'] = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $this->wpdb->update( $this->table(), $data, array( 'id' => $id ) );

		return false !== $updated && $updated > 0;
	}
}
```

Nota sobre `due()`: el `ORDER BY scheduled_at IS NULL DESC` pone primero los trabajos sin fecha ("cuanto antes") y luego los fechados en orden; el test lo pin­ea.

- [ ] **Step 5: Registrar la factory**

En `Plugin::register_factories()` añade:

```php
		$this->factories[ JobRepository::class ] = static function ( Plugin $c ): JobRepository {
			global $wpdb;
			return new JobRepository( $wpdb, $c->get( Schema::class ) );
		};
```

- [ ] **Step 6: Tests y lint**

Run: `npm run test -- --filter JobRepositoryTest && npm run lint`
Expected: `OK (9 tests, ...)`; PHPCS sin errores.

- [ ] **Step 7: Commit**

```bash
git add src/Job.php src/JobRepository.php src/Plugin.php tests/phpunit/JobRepositoryTest.php
git commit -m "feat: repositorio de trabajos con idempotencia y claim atómico"
```

---

### Task 8: `Logger` mínimo con redacción

**Files:**
- Create: `src/Logger.php`
- Modify: `src/Plugin.php` (factory)
- Test: `tests/phpunit/LoggerTest.php`

**Interfaces:**
- Consumes: `Schema::table( 'log' )`, `Settings::get( 'log.level' )`.
- Produces: `Logger::LEVELS = array( 'debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3 )`; `new Logger( \wpdb $wpdb, Schema $schema, string $min_level = 'info' )`; `log( string $level, string $event, string $message, array $context = array() ): void` (las claves `channel_id`, `post_id`, `job_id` del contexto se copian a sus columnas; el resto se guarda como JSON redactado); atajos `debug()`, `info()`, `warning()`, `error()` con firma `( string $event, string $message, array $context = array() )`; `Logger::redact( array $context ): array` (estático, recursivo); `recent( array $args = array() ): array` (filtros `level`, `channel_id`, `post_id`, `job_id`, `limit` default 50; filas `ARRAY_A` con `context` ya decodificado).
- Redacción: cualquier clave que coincida con `/token|secret|password|authorization|signature/i`, o exactamente `code`, se sustituye por `[redactado]`; en cualquier cadena, `access_token=…` y `client_secret=…` en query strings se redactan, y los tokens de Meta (`EAA` seguido de 20+ caracteres alfanuméricos) también.

- [ ] **Step 1: Escribir el test**

`tests/phpunit/LoggerTest.php`:

```php
<?php
/**
 * @package Voceador
 */

use Voceador\Logger;
use Voceador\Schema;

class LoggerTest extends WP_UnitTestCase {

	private Logger $logger;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->logger = new Logger( $wpdb, new Schema( $wpdb ), 'debug' );
	}

	public function test_redact_masks_sensitive_keys_recursively(): void {
		$redacted = Logger::redact(
			array(
				'access_token' => 'EAAabc',
				'app_secret'   => 's',
				'Authorization' => 'Bearer x',
				'code'         => 'oauth-code',
				'nested'       => array( 'page_token' => 't', 'safe' => 'ok', 'password' => 'p' ),
				'graph_code'   => 190,
				'safe'         => 'visible',
			)
		);

		$this->assertSame( '[redactado]', $redacted['access_token'] );
		$this->assertSame( '[redactado]', $redacted['app_secret'] );
		$this->assertSame( '[redactado]', $redacted['Authorization'] );
		$this->assertSame( '[redactado]', $redacted['code'] );
		$this->assertSame( '[redactado]', $redacted['nested']['page_token'] );
		$this->assertSame( '[redactado]', $redacted['nested']['password'] );
		$this->assertSame( 'ok', $redacted['nested']['safe'] );
		$this->assertSame( 190, $redacted['graph_code'], 'graph_code no es un secreto.' );
		$this->assertSame( 'visible', $redacted['safe'] );
	}

	public function test_redact_masks_tokens_inside_strings(): void {
		$token    = 'EAA' . str_repeat( 'Ab1', 10 );
		$redacted = Logger::redact(
			array(
				'url'  => 'https://graph.facebook.com/v26.0/me?fields=id&access_token=' . $token . '&x=1',
				'body' => 'client_secret=abc123&grant_type=x',
				'msg'  => 'Token ' . $token . ' rechazado',
			)
		);

		$this->assertSame( 'https://graph.facebook.com/v26.0/me?fields=id&access_token=[redactado]&x=1', $redacted['url'] );
		$this->assertSame( 'client_secret=[redactado]&grant_type=x', $redacted['body'] );
		$this->assertSame( 'Token [redactado] rechazado', $redacted['msg'] );
	}

	public function test_log_writes_row_with_ids_and_redacted_context(): void {
		global $wpdb;
		$this->logger->error( 'publish_failed', 'Falló', array( 'channel_id' => 3, 'post_id' => 7, 'job_id' => 9, 'access_token' => 'EAAsecreto', 'graph_code' => 190 ) );

		$row = $wpdb->get_row( 'SELECT * FROM ' . ( new Schema( $wpdb ) )->table( 'log' ) . ' ORDER BY id DESC LIMIT 1', ARRAY_A );

		$this->assertSame( 'error', $row['level'] );
		$this->assertSame( 'publish_failed', $row['event'] );
		$this->assertSame( 'Falló', $row['message'] );
		$this->assertSame( '3', $row['channel_id'] );
		$this->assertSame( '7', $row['post_id'] );
		$this->assertSame( '9', $row['job_id'] );
		$this->assertStringNotContainsString( 'EAAsecreto', $row['context'] );
		$this->assertStringNotContainsString( 'channel_id', $row['context'], 'Los ids van en columnas, no en el JSON.' );
		$this->assertSame( 190, json_decode( $row['context'], true )['graph_code'] );
	}

	public function test_min_level_filters_out_lower_levels(): void {
		global $wpdb;
		$logger = new Logger( $wpdb, new Schema( $wpdb ), 'warning' );
		$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ( new Schema( $wpdb ) )->table( 'log' ) );

		$logger->debug( 'a', 'x' );
		$logger->info( 'b', 'x' );
		$logger->warning( 'c', 'x' );
		$logger->error( 'd', 'x' );

		$after = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ( new Schema( $wpdb ) )->table( 'log' ) );
		$this->assertSame( 2, $after - $before );
	}

	public function test_unknown_level_is_treated_as_error(): void {
		$this->logger->log( 'critical', 'e', 'x' );
		$rows = $this->logger->recent( array( 'limit' => 1 ) );
		$this->assertSame( 'error', $rows[0]['level'] );
	}

	public function test_recent_filters_and_decodes_context(): void {
		$this->logger->info( 'one', 'x', array( 'post_id' => 1, 'k' => 'v' ) );
		$this->logger->warning( 'two', 'x', array( 'post_id' => 2 ) );
		$this->logger->warning( 'three', 'x', array( 'post_id' => 1 ) );

		$rows = $this->logger->recent( array( 'post_id' => 1 ) );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'three', $rows[0]['event'], 'Más reciente primero.' );
		$this->assertSame( array( 'k' => 'v' ), $rows[1]['context'] );

		$this->assertCount( 2, $this->logger->recent( array( 'level' => 'warning' ) ) );
		$this->assertCount( 1, $this->logger->recent( array( 'level' => 'warning', 'post_id' => 2 ) ) );
	}
}
```

- [ ] **Step 2: Ejecutar y verificar que falla**

Run: `npm run test -- --filter LoggerTest`
Expected: FAIL con `Class "Voceador\Logger" not found`.

- [ ] **Step 3: Crear `src/Logger.php`**

```php
<?php
/**
 * Log del plugin en tabla propia.
 *
 * @package Voceador
 */

namespace Voceador;

/**
 * Escribe eventos en voceador_log. Nunca registra tokens ni secretos.
 */
final class Logger {

	/**
	 * Niveles y su severidad.
	 */
	public const LEVELS = array(
		'debug'   => 0,
		'info'    => 1,
		'warning' => 2,
		'error'   => 3,
	);

	/**
	 * Claves de contexto que van a columnas propias.
	 */
	private const ID_COLUMNS = array( 'channel_id', 'post_id', 'job_id' );

	/**
	 * Patrón de claves sensibles.
	 */
	private const SENSITIVE_KEY = '/token|secret|password|authorization|signature/i';

	/**
	 * Patrones sensibles dentro de cadenas.
	 */
	private const SENSITIVE_IN_STRING = array(
		'/((?:access_token|client_secret|app_secret|fb_exchange_token)=)[^&\s]+/i' => '$1[redactado]',
		'/EAA[A-Za-z0-9]{20,}/' => '[redactado]',
	);

	/**
	 * Severidad mínima que se escribe.
	 *
	 * @var int
	 */
	private int $min_severity;

	/**
	 * Constructor.
	 *
	 * @param \wpdb  $wpdb      Conexión.
	 * @param Schema $schema    Esquema.
	 * @param string $min_level Nivel mínimo a registrar.
	 */
	public function __construct(
		private \wpdb $wpdb,
		private Schema $schema,
		string $min_level = 'info'
	) {
		$this->min_severity = self::LEVELS[ $min_level ] ?? self::LEVELS['info'];
	}

	/**
	 * Registra un evento.
	 *
	 * @param string $level   debug, info, warning o error (desconocido = error).
	 * @param string $event   Identificador corto del evento.
	 * @param string $message Mensaje legible.
	 * @param array  $context Datos adicionales; channel_id, post_id y job_id van a columnas.
	 */
	public function log( string $level, string $event, string $message, array $context = array() ): void {
		if ( ! isset( self::LEVELS[ $level ] ) ) {
			$level = 'error';
		}
		if ( self::LEVELS[ $level ] < $this->min_severity ) {
			return;
		}

		$row = array(
			'created_at' => current_time( 'mysql', true ),
			'level'      => $level,
			'event'      => substr( $event, 0, 60 ),
			'message'    => $message,
		);

		foreach ( self::ID_COLUMNS as $column ) {
			if ( isset( $context[ $column ] ) ) {
				$row[ $column ] = (int) $context[ $column ];
			}
			unset( $context[ $column ] );
		}

		$row['context'] = (string) wp_json_encode( self::redact( $context ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->wpdb->insert( $this->schema->table( 'log' ), $row );
	}

	/**
	 * Evento de depuración.
	 *
	 * @param string $event   Evento.
	 * @param string $message Mensaje.
	 * @param array  $context Contexto.
	 */
	public function debug( string $event, string $message, array $context = array() ): void {
		$this->log( 'debug', $event, $message, $context );
	}

	/**
	 * Evento informativo.
	 *
	 * @param string $event   Evento.
	 * @param string $message Mensaje.
	 * @param array  $context Contexto.
	 */
	public function info( string $event, string $message, array $context = array() ): void {
		$this->log( 'info', $event, $message, $context );
	}

	/**
	 * Aviso.
	 *
	 * @param string $event   Evento.
	 * @param string $message Mensaje.
	 * @param array  $context Contexto.
	 */
	public function warning( string $event, string $message, array $context = array() ): void {
		$this->log( 'warning', $event, $message, $context );
	}

	/**
	 * Error.
	 *
	 * @param string $event   Evento.
	 * @param string $message Mensaje.
	 * @param array  $context Contexto.
	 */
	public function error( string $event, string $message, array $context = array() ): void {
		$this->log( 'error', $event, $message, $context );
	}

	/**
	 * Elimina secretos de un contexto, de forma recursiva.
	 *
	 * @param array $context Contexto.
	 * @return array
	 */
	public static function redact( array $context ): array {
		$clean = array();

		foreach ( $context as $key => $value ) {
			if ( is_string( $key ) && ( 'code' === $key || preg_match( self::SENSITIVE_KEY, $key ) ) ) {
				$clean[ $key ] = '[redactado]';
				continue;
			}

			if ( is_array( $value ) ) {
				$clean[ $key ] = self::redact( $value );
			} elseif ( is_string( $value ) ) {
				$clean[ $key ] = preg_replace( array_keys( self::SENSITIVE_IN_STRING ), array_values( self::SENSITIVE_IN_STRING ), $value );
			} else {
				$clean[ $key ] = $value;
			}
		}

		return $clean;
	}

	/**
	 * Últimos eventos, más recientes primero.
	 *
	 * @param array $args Filtros: level, channel_id, post_id, job_id, limit.
	 * @return array[] Filas con context decodificado.
	 */
	public function recent( array $args = array() ): array {
		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $args['level'] ) ) {
			$where[]  = 'level = %s';
			$values[] = (string) $args['level'];
		}
		foreach ( self::ID_COLUMNS as $column ) {
			if ( isset( $args[ $column ] ) ) {
				$where[]  = "{$column} = %d";
				$values[] = (int) $args[ $column ];
			}
		}
		$values[] = (int) ( $args['limit'] ?? 50 );

		$sql = "SELECT * FROM {$this->schema->table( 'log' )} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$values ), ARRAY_A );

		foreach ( $rows ?: array() as &$row ) {
			$decoded        = json_decode( (string) $row['context'], true );
			$row['context'] = is_array( $decoded ) ? $decoded : array();
		}

		return $rows ?: array();
	}
}
```

- [ ] **Step 4: Registrar la factory**

En `Plugin::register_factories()` añade:

```php
		$this->factories[ Logger::class ] = static function ( Plugin $c ): Logger {
			global $wpdb;
			return new Logger( $wpdb, $c->get( Schema::class ), (string) $c->get( Settings::class )->get( 'log.level' ) );
		};
```

- [ ] **Step 5: Tests y lint**

Run: `npm run test -- --filter LoggerTest && npm run lint`
Expected: `OK (6 tests, ...)`; PHPCS sin errores.

- [ ] **Step 6: Commit**

```bash
git add src/Logger.php src/Plugin.php tests/phpunit/LoggerTest.php
git commit -m "feat: log en tabla propia con redacción de secretos"
```

---

### Task 9: `GraphClient`

**Files:**
- Create: `src/GraphClient.php`
- Modify: `src/Plugin.php` (factory)
- Test: `tests/phpunit/GraphClientTest.php`

**Interfaces:**
- Consumes: `Settings::get( 'graph_version' )`, `Logger`.
- Produces: `new GraphClient( Settings $settings, Logger $logger, string $base_url = 'https://graph.facebook.com' )`; `get( string $path, array $query = array(), string $token = '' ): array|\WP_Error`; `post( string $path, array $body = array(), string $token = '' ): array|\WP_Error`; `post_multipart( string $path, array $fields, string $file_field, string $file_path, string $token = '' ): array|\WP_Error`; `last_usage(): array` (cabeceras `x-app-usage` / `x-business-use-case-usage` decodificadas de la última respuesta); `GraphClient::default_error_map(): array`; `normalize_error( int $http_code, array $error ): \WP_Error`.
- El token viaja siempre en la cabecera `Authorization: Bearer …`, nunca en la URL ni en el cuerpo. La URL es `{base}/{version}/{path}` (path sin barra inicial). Timeout 30 s, filtrable con `voceador_http_timeout`.
- `WP_Error`: código = clase normalizada; mensaje = mensaje de Graph (o del transporte); `get_error_data()` = `array( 'class', 'http', 'graph_code', 'subcode', 'type', 'fbtrace_id', 'user_message', 'retry_after' )`.
- Clasificación: transporte (`wp_remote_request` devuelve `WP_Error`) → `transient`; HTTP ≥ 500 sin código Graph clasificable → `transient`; luego el mapa (filtro `voceador_error_map`): `transient` = 1, 2, 4, 17, 32, 341; `rate_limited` = 613, 80001, 80002, 80003, 80004, 80005, 80006; `auth` = 102, 190; `permission` = 10 y 200-299; `media` = 324, 1366; `spam` = 368; resto → `fatal`.
- Log: `debug` en cada llamada (método, path, http) y `warning` en cada error (clase, código, fbtrace_id); nunca cabeceras ni token.

- [ ] **Step 1: Escribir el test**

`tests/phpunit/GraphClientTest.php`:

```php
<?php
/**
 * @package Voceador
 */

use Voceador\GraphClient;
use Voceador\Logger;
use Voceador\Schema;
use Voceador\Settings;

class GraphClientTest extends WP_UnitTestCase {

	private GraphClient $client;
	private array $requests = array();
	private $response;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		delete_option( VOCEADOR_PREFIX . Settings::OPTION );
		$this->client   = new GraphClient( new Settings(), new Logger( $wpdb, new Schema( $wpdb ), 'debug' ) );
		$this->requests = array();
		$this->response = $this->json( 200, array( 'id' => '1' ) );

		add_filter( 'pre_http_request', array( $this, 'capture' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'capture' ), 10 );
		parent::tear_down();
	}

	public function capture( $pre, array $args, string $url ) {
		$this->requests[] = array( 'url' => $url, 'args' => $args );
		return $this->response;
	}

	private function json( int $code, array $body, array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $code, 'message' => '' ),
			'headers'  => $headers,
			'body'     => wp_json_encode( $body ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function graph_error( int $http, int $code, string $message = 'boom', int $subcode = 0 ): array {
		return $this->json( $http, array( 'error' => array( 'message' => $message, 'type' => 'OAuthException', 'code' => $code, 'error_subcode' => $subcode, 'fbtrace_id' => 'trace' ) ) );
	}

	public function test_get_builds_url_and_sends_bearer_token(): void {
		$this->response = $this->json( 200, array( 'id' => '42', 'name' => 'Página' ) );

		$result = $this->client->get( 'me', array( 'fields' => 'id,name' ), 'EAAtoken' );

		$this->assertSame( array( 'id' => '42', 'name' => 'Página' ), $result );
		$this->assertSame( 'https://graph.facebook.com/v26.0/me?fields=id%2Cname', $this->requests[0]['url'] );
		$this->assertSame( 'GET', $this->requests[0]['args']['method'] );
		$this->assertSame( 'Bearer EAAtoken', $this->requests[0]['args']['headers']['Authorization'] );
		$this->assertStringNotContainsString( 'EAAtoken', $this->requests[0]['url'] );
	}

	public function test_version_comes_from_settings(): void {
		( new Settings() )->update( array( 'graph_version' => 'v25.0' ) );
		$this->client->get( 'me' );
		$this->assertStringStartsWith( 'https://graph.facebook.com/v25.0/me', $this->requests[0]['url'] );
	}

	public function test_post_sends_form_body_without_token(): void {
		$this->response = $this->json( 200, array( 'id' => '9', 'post_id' => '1_9' ) );

		$result = $this->client->post( '123/photos', array( 'url' => 'https://x/y.jpg', 'caption' => 'Hola' ), 'EAAtoken' );

		$this->assertSame( '1_9', $result['post_id'] );
		$this->assertSame( 'POST', $this->requests[0]['args']['method'] );
		$this->assertSame( array( 'url' => 'https://x/y.jpg', 'caption' => 'Hola' ), $this->requests[0]['args']['body'] );
		$this->assertSame( 'Bearer EAAtoken', $this->requests[0]['args']['headers']['Authorization'] );
	}

	public function test_post_multipart_embeds_file(): void {
		$file = wp_tempnam( 'voceador' );
		file_put_contents( $file, 'JPEGDATA' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->client->post_multipart( '123/photos', array( 'caption' => 'Hola' ), 'source', $file, 'EAAtoken' );
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$args = $this->requests[0]['args'];
		$this->assertStringStartsWith( 'multipart/form-data; boundary=', $args['headers']['Content-Type'] );
		$this->assertStringContainsString( 'name="caption"', $args['body'] );
		$this->assertStringContainsString( 'Hola', $args['body'] );
		$this->assertStringContainsString( 'name="source"; filename="', $args['body'] );
		$this->assertStringContainsString( 'JPEGDATA', $args['body'] );
		$this->assertStringNotContainsString( 'EAAtoken', $args['body'] );
	}

	public function test_post_multipart_with_missing_file_is_media_error(): void {
		$result = $this->client->post_multipart( '123/photos', array(), 'source', '/no/existe.jpg', 't' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'media', $result->get_error_code() );
		$this->assertCount( 0, $this->requests, 'No se hace la petición.' );
	}

	/**
	 * @dataProvider error_classes
	 */
	public function test_graph_errors_are_classified( int $http, int $code, string $expected ): void {
		$this->response = $this->graph_error( $http, $code );

		$result = $this->client->get( 'me', array(), 't' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $expected, $result->get_error_code() );
		$this->assertSame( 'boom', $result->get_error_message() );
		$this->assertSame( $code, $result->get_error_data()['graph_code'] );
		$this->assertSame( $http, $result->get_error_data()['http'] );
		$this->assertSame( 'trace', $result->get_error_data()['fbtrace_id'] );
	}

	public function error_classes(): array {
		return array(
			'190 auth'        => array( 400, 190, 'auth' ),
			'102 auth'        => array( 400, 102, 'auth' ),
			'10 permission'   => array( 403, 10, 'permission' ),
			'200 permission'  => array( 403, 200, 'permission' ),
			'299 permission'  => array( 403, 299, 'permission' ),
			'368 spam'        => array( 400, 368, 'spam' ),
			'4 transient'     => array( 400, 4, 'transient' ),
			'17 transient'    => array( 400, 17, 'transient' ),
			'341 transient'   => array( 400, 341, 'transient' ),
			'613 rate'        => array( 400, 613, 'rate_limited' ),
			'80001 rate'      => array( 400, 80001, 'rate_limited' ),
			'324 media'       => array( 400, 324, 'media' ),
			'100 fatal'       => array( 400, 100, 'fatal' ),
			'unknown fatal'   => array( 400, 999999, 'fatal' ),
			'500 with 1'      => array( 500, 1, 'transient' ),
			'500 with 190'    => array( 500, 190, 'auth' ),
		);
	}

	public function test_http_5xx_without_graph_error_is_transient(): void {
		$this->response = array( 'response' => array( 'code' => 502, 'message' => 'Bad Gateway' ), 'headers' => array(), 'body' => '<html>bad gateway</html>', 'cookies' => array(), 'filename' => null );

		$result = $this->client->get( 'me' );

		$this->assertSame( 'transient', $result->get_error_code() );
		$this->assertSame( 502, $result->get_error_data()['http'] );
	}

	public function test_transport_error_is_transient(): void {
		$this->response = new WP_Error( 'http_request_failed', 'cURL error 28: timeout' );

		$result = $this->client->get( 'me' );

		$this->assertSame( 'transient', $result->get_error_code() );
		$this->assertSame( 'cURL error 28: timeout', $result->get_error_message() );
	}

	public function test_non_json_200_is_fatal(): void {
		$this->response = array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'headers' => array(), 'body' => 'not json', 'cookies' => array(), 'filename' => null );

		$this->assertSame( 'fatal', $this->client->get( 'me' )->get_error_code() );
	}

	public function test_error_map_filter(): void {
		add_filter(
			'voceador_error_map',
			static function ( array $map ): array {
				$map['media'][] = 2207001;
				return $map;
			}
		);
		$this->response = $this->graph_error( 400, 2207001 );

		$this->assertSame( 'media', $this->client->get( 'me' )->get_error_code() );
	}

	public function test_retry_after_and_usage_headers(): void {
		$this->response = $this->graph_error( 400, 4 );
		$this->response['headers'] = array(
			'retry-after' => '120',
			'x-app-usage' => wp_json_encode( array( 'call_count' => 97, 'total_time' => 10, 'total_cputime' => 5 ) ),
		);

		$result = $this->client->get( 'me' );

		$this->assertSame( 120, $result->get_error_data()['retry_after'] );
		$this->assertSame( 97, $this->client->last_usage()['x-app-usage']['call_count'] );
	}

	public function test_errors_are_logged_without_secrets(): void {
		global $wpdb;
		$this->response = $this->graph_error( 400, 190, 'Invalid OAuth access token EAA' . str_repeat( 'Zz9', 12 ) );

		$this->client->get( 'me', array(), 'EAAtoken' );

		$row = $wpdb->get_row( 'SELECT * FROM ' . ( new Schema( $wpdb ) )->table( 'log' ) . " WHERE event = 'graph_error' ORDER BY id DESC LIMIT 1", ARRAY_A );
		$this->assertNotNull( $row );
		$this->assertSame( 'warning', $row['level'] );
		$this->assertStringNotContainsString( 'EAAtoken', $row['context'] . $row['message'] );
		$this->assertStringNotContainsString( 'Zz9Zz9', $row['context'] . $row['message'] );
	}

	public function test_timeout_is_filterable(): void {
		add_filter( 'voceador_http_timeout', static fn() => 7 );
		$this->client->get( 'me' );
		$this->assertSame( 7, $this->requests[0]['args']['timeout'] );
	}
}
```

- [ ] **Step 2: Ejecutar y verificar que falla**

Run: `npm run test -- --filter GraphClientTest`
Expected: FAIL con `Class "Voceador\GraphClient" not found`.

- [ ] **Step 3: Crear `src/GraphClient.php`**

```php
<?php
/**
 * Cliente HTTP de la Graph API de Meta.
 *
 * @package Voceador
 */

namespace Voceador;

/**
 * Único punto del plugin que habla HTTP con Meta y que conoce sus códigos de error.
 */
final class GraphClient {

	/**
	 * Clases de error normalizadas.
	 */
	public const ERROR_CLASSES = array( 'transient', 'rate_limited', 'auth', 'permission', 'media', 'spam', 'fatal' );

	/**
	 * Cabeceras de uso que Meta devuelve.
	 */
	private const USAGE_HEADERS = array( 'x-app-usage', 'x-business-use-case-usage', 'x-ad-account-usage' );

	/**
	 * Uso reportado por la última respuesta.
	 *
	 * @var array
	 */
	private array $last_usage = array();

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Ajustes (versión de Graph).
	 * @param Logger   $logger   Log.
	 * @param string   $base_url Host de la API, sin barra final.
	 */
	public function __construct(
		private Settings $settings,
		private Logger $logger,
		private string $base_url = 'https://graph.facebook.com'
	) {}

	/**
	 * Mapa por defecto de código Graph → clase.
	 *
	 * @return array<string, int[]>
	 */
	public static function default_error_map(): array {
		return array(
			'transient'    => array( 1, 2, 4, 17, 32, 341 ),
			'rate_limited' => array( 613, 80001, 80002, 80003, 80004, 80005, 80006 ),
			'auth'         => array( 102, 190 ),
			'permission'   => array_merge( array( 10 ), range( 200, 299 ) ),
			'media'        => array( 324, 1366 ),
			'spam'         => array( 368 ),
		);
	}

	/**
	 * Petición GET.
	 *
	 * @param string $path  Ruta sin versión ("me", "123/photos").
	 * @param array  $query Parámetros.
	 * @param string $token Token de acceso.
	 * @return array|\WP_Error
	 */
	public function get( string $path, array $query = array(), string $token = '' ): array|\WP_Error {
		return $this->request( 'GET', $path, $query, array(), $token );
	}

	/**
	 * Petición POST con cuerpo de formulario.
	 *
	 * @param string $path  Ruta.
	 * @param array  $body  Campos.
	 * @param string $token Token.
	 * @return array|\WP_Error
	 */
	public function post( string $path, array $body = array(), string $token = '' ): array|\WP_Error {
		return $this->request( 'POST', $path, array(), array( 'body' => $body ), $token );
	}

	/**
	 * Petición POST multipart con un archivo.
	 *
	 * @param string $path       Ruta.
	 * @param array  $fields     Campos de texto.
	 * @param string $file_field Nombre del campo de archivo ("source").
	 * @param string $file_path  Ruta local del archivo.
	 * @param string $token      Token.
	 * @return array|\WP_Error
	 */
	public function post_multipart( string $path, array $fields, string $file_field, string $file_path, string $token = '' ): array|\WP_Error {
		if ( ! is_readable( $file_path ) ) {
			return new \WP_Error( 'media', __( 'No se pudo leer el archivo de imagen.', 'voceador' ), array( 'class' => 'media', 'http' => 0, 'graph_code' => 0, 'subcode' => 0, 'type' => '', 'fbtrace_id' => '', 'user_message' => '', 'retry_after' => 0 ) );
		}

		$boundary = 'voceador' . wp_generate_password( 24, false );
		$mime     = wp_check_filetype( $file_path )['type'] ?: 'application/octet-stream';
		$body     = '';

		foreach ( $fields as $name => $value ) {
			$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
		}

		$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$file_field}\"; filename=\"" . basename( $file_path ) . "\"\r\n";
		$body .= "Content-Type: {$mime}\r\n\r\n";
		$body .= file_get_contents( $file_path ) . "\r\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Lectura local de un adjunto; WP_Filesystem es innecesario.
		$body .= "--{$boundary}--\r\n";

		return $this->request(
			'POST',
			$path,
			array(),
			array(
				'body'    => $body,
				'headers' => array( 'Content-Type' => "multipart/form-data; boundary={$boundary}" ),
			),
			$token
		);
	}

	/**
	 * Uso reportado en las cabeceras de la última respuesta.
	 *
	 * @return array Cabecera => array decodificado.
	 */
	public function last_usage(): array {
		return $this->last_usage;
	}

	/**
	 * Convierte un error de Graph en WP_Error con clase normalizada.
	 *
	 * @param int   $http_code   Código HTTP.
	 * @param array $error       Objeto "error" de la respuesta (puede estar vacío).
	 * @param int   $retry_after Segundos de la cabecera Retry-After, si la hubo.
	 * @return \WP_Error
	 */
	public function normalize_error( int $http_code, array $error, int $retry_after = 0 ): \WP_Error {
		$code    = (int) ( $error['code'] ?? 0 );
		$subcode = (int) ( $error['error_subcode'] ?? 0 );
		$class   = $this->classify( $http_code, $code );
		$message = (string) ( $error['message'] ?? sprintf( /* translators: %d: código HTTP */ __( 'Respuesta HTTP %d de la Graph API.', 'voceador' ), $http_code ) );

		return new \WP_Error(
			$class,
			$message,
			array(
				'class'        => $class,
				'http'         => $http_code,
				'graph_code'   => $code,
				'subcode'      => $subcode,
				'type'         => (string) ( $error['type'] ?? '' ),
				'fbtrace_id'   => (string) ( $error['fbtrace_id'] ?? '' ),
				'user_message' => (string) ( $error['error_user_msg'] ?? '' ),
				'retry_after'  => $retry_after,
			)
		);
	}

	/**
	 * Ejecuta la petición y normaliza la respuesta.
	 *
	 * @param string $method GET o POST.
	 * @param string $path   Ruta.
	 * @param array  $query  Parámetros de URL.
	 * @param array  $extra  body y/o headers adicionales.
	 * @param string $token  Token.
	 * @return array|\WP_Error
	 */
	private function request( string $method, string $path, array $query, array $extra, string $token ): array|\WP_Error {
		$url = $this->url( $path, $query );

		$headers = array_merge(
			array( 'Accept' => 'application/json' ),
			$extra['headers'] ?? array()
		);
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'body'    => $extra['body'] ?? null,
			/**
			 * Timeout de las peticiones a la Graph API, en segundos.
			 *
			 * @param int $timeout Segundos.
			 */
			'timeout' => (int) apply_filters( 'voceador_http_timeout', 30 ),
		);

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$error = new \WP_Error( 'transient', $response->get_error_message(), array( 'class' => 'transient', 'http' => 0, 'graph_code' => 0, 'subcode' => 0, 'type' => 'transport', 'fbtrace_id' => '', 'user_message' => '', 'retry_after' => 0 ) );
			$this->log_error( $method, $path, $error );
			return $error;
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$this->remember_usage( $response );

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$retry   = (int) wp_remote_retrieve_header( $response, 'retry-after' );

		if ( is_array( $decoded ) && isset( $decoded['error'] ) && is_array( $decoded['error'] ) ) {
			$error = $this->normalize_error( $http_code, $decoded['error'], $retry );
			$this->log_error( $method, $path, $error );
			return $error;
		}

		if ( $http_code >= 400 || ! is_array( $decoded ) ) {
			$error = $this->normalize_error( $http_code >= 400 ? $http_code : 0, array(), $retry );
			if ( $http_code < 400 ) {
				$error = new \WP_Error( 'fatal', __( 'La Graph API devolvió una respuesta que no es JSON.', 'voceador' ), array_merge( $error->get_error_data(), array( 'class' => 'fatal', 'http' => $http_code ) ) );
			}
			$this->log_error( $method, $path, $error );
			return $error;
		}

		$this->logger->debug( 'graph_request', $method . ' ' . $path, array( 'http' => $http_code ) );

		return $decoded;
	}

	/**
	 * URL completa con versión.
	 *
	 * @param string $path  Ruta.
	 * @param array  $query Parámetros.
	 * @return string
	 */
	private function url( string $path, array $query ): string {
		$version = (string) $this->settings->get( 'graph_version' );
		$url     = rtrim( $this->base_url, '/' ) . '/' . $version . '/' . ltrim( $path, '/' );

		return $query ? add_query_arg( array_map( 'rawurlencode', $query ), $url ) : $url;
	}

	/**
	 * Clasifica un error.
	 *
	 * @param int $http_code Código HTTP.
	 * @param int $code      Código Graph (0 si no hay).
	 * @return string
	 */
	private function classify( int $http_code, int $code ): string {
		/**
		 * Permite ajustar qué códigos de Graph caen en cada clase de error.
		 *
		 * @param array<string, int[]> $map Clase => códigos.
		 */
		$map = (array) apply_filters( 'voceador_error_map', self::default_error_map() );

		if ( 0 !== $code ) {
			foreach ( $map as $class => $codes ) {
				if ( in_array( $class, self::ERROR_CLASSES, true ) && in_array( $code, array_map( 'intval', (array) $codes ), true ) ) {
					return $class;
				}
			}
		}

		if ( $http_code >= 500 ) {
			return 'transient';
		}

		return 'fatal';
	}

	/**
	 * Guarda las cabeceras de uso de la respuesta.
	 *
	 * @param array $response Respuesta de wp_remote_request.
	 */
	private function remember_usage( array $response ): void {
		$this->last_usage = array();

		foreach ( self::USAGE_HEADERS as $header ) {
			$raw = wp_remote_retrieve_header( $response, $header );
			if ( '' === $raw || is_array( $raw ) ) {
				continue;
			}
			$decoded = json_decode( (string) $raw, true );
			if ( is_array( $decoded ) ) {
				$this->last_usage[ $header ] = $decoded;
			}
		}
	}

	/**
	 * Registra un error sin secretos.
	 *
	 * @param string    $method Método.
	 * @param string    $path   Ruta.
	 * @param \WP_Error $error  Error normalizado.
	 */
	private function log_error( string $method, string $path, \WP_Error $error ): void {
		$data = (array) $error->get_error_data();

		$this->logger->warning(
			'graph_error',
			$method . ' ' . $path . ': ' . Logger::redact( array( 'm' => $error->get_error_message() ) )['m'],
			array(
				'class'      => $data['class'] ?? '',
				'http'       => $data['http'] ?? 0,
				'graph_code' => $data['graph_code'] ?? 0,
				'subcode'    => $data['subcode'] ?? 0,
				'fbtrace_id' => $data['fbtrace_id'] ?? '',
			)
		);
	}
}
```

Notas para el implementador:
- `add_query_arg` no codifica valores; por eso `url()` aplica `rawurlencode` a cada valor (el test espera `fields=id%2Cname`).
- `wp_remote_retrieve_header` devuelve `''` si la cabecera no existe y puede devolver un array si viene repetida.
- En el test `test_http_5xx_without_graph_error_is_transient`, `normalize_error( 502, array() )` produce clase `transient` porque `classify( 502, 0 )` cae en la rama HTTP ≥ 500.

- [ ] **Step 4: Registrar la factory**

En `Plugin::register_factories()` añade:

```php
		$this->factories[ GraphClient::class ] = static function ( Plugin $c ): GraphClient {
			return new GraphClient( $c->get( Settings::class ), $c->get( Logger::class ) );
		};
```

- [ ] **Step 5: Tests y lint**

Run: `npm run test -- --filter GraphClientTest && npm run lint`
Expected: `OK (28 tests, ...)` (16 casos del data provider + 12); PHPCS sin errores. Si PHPCS marca `wp_remote_request` con `body` nulo o el `file_get_contents`, ajusta solo con `phpcs:ignore` razonado, nunca cambiando la semántica.

- [ ] **Step 6: Suite completa**

Run: `npm run test && npm run test:multisite && npm run lint`
Expected: todo `OK`.

- [ ] **Step 7: Commit**

```bash
git add src/GraphClient.php src/Plugin.php tests/phpunit/GraphClientTest.php
git commit -m "feat: cliente de Graph API con errores normalizados"
```

---

### Task 10: Contenedor completo, rama y PR

**Files:**
- Modify: `tests/phpunit/PluginTest.php` (añadir un test), `README.md` (línea de estado)

**Interfaces:**
- Consumes: todas las factories registradas en las tareas 3-9.
- Produces: rama `fase-1a/nucleo-infraestructura` empujada, PR contra `main` con CI verde.

- [ ] **Step 1: Test de que todos los servicios se resuelven**

Añade a `PluginTest`:

```php
	public function test_every_phase_1a_service_resolves(): void {
		$plugin = Plugin::boot();

		foreach ( array(
			\Voceador\Crypto::class,
			\Voceador\Settings::class,
			\Voceador\Channels\ChannelRegistry::class,
			\Voceador\ChannelRepository::class,
			\Voceador\JobRepository::class,
			\Voceador\Logger::class,
			\Voceador\GraphClient::class,
		) as $id ) {
			$this->assertInstanceOf( $id, $plugin->get( $id ) );
		}
	}
```

Run: `npm run test -- --filter PluginTest`
Expected: `OK`.

- [ ] **Step 2: Actualizar el estado en `README.md`**

Sustituye la línea que empieza por `> Estado:` por:

```markdown
> Estado: fase 1a completada (contenedor, cifrado, ajustes, contratos de canal, repositorios, log y cliente de Graph API). El plugin todavía no publica en redes; eso llega en la fase 1b.
```

- [ ] **Step 3: Commit, push y PR**

```bash
git add tests/phpunit/PluginTest.php README.md
git commit -m "test: todos los servicios de la fase 1a se resuelven desde el contenedor"
git push -u origin fase-1a/nucleo-infraestructura
gh pr create --base main --head fase-1a/nucleo-infraestructura --title "Fase 1a: núcleo, infraestructura" --body-file /dev/stdin <<'EOF'
## Qué entrega

- Contenedor perezoso (`Plugin::get`, factories, `Registrable`, `reset()` para tests).
- Esquema v2: índice `(status, scheduled_at)` en `jobs`, primera migración real (borra `KEY type`).
- `Crypto` (libsodium secretbox, clave derivada de las salts o de `VOCEADOR_ENCRYPTION_KEY`).
- `Settings` con resolución canal → sitio → red → defaults.
- Contratos de canal (`ChannelAdapter`, `Channel`, objetos de valor) y `ChannelRegistry` con `voceador_channel_types`.
- `ChannelRepository` (credenciales cifradas) y `JobRepository` (INSERT IGNORE, claim atómico).
- `Logger` con redacción de secretos y `GraphClient` con errores normalizados y `voceador_error_map`.

Plan: `docs/superpowers/plans/2026-09-22-fase-1a-nucleo-infraestructura.md`.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
```

- [ ] **Step 4: Verificar el CI**

Run: `gh pr checks --watch`
Expected: `lint-and-test` en verde. Si falla, `gh run view --log-failed`, corregir, commit y push a la misma rama.

---

## Criterio de cierre de la fase 1a

- `npm run test`, `npm run test:multisite` y `npm run lint` pasan en local y en CI (incluido el smoke, que ahora espera `db_version = 2`).
- `Plugin::boot()->get()` resuelve `Crypto`, `Settings`, `ChannelRegistry`, `ChannelRepository`, `JobRepository`, `Logger` y `GraphClient`.
- Un sitio con `db_version = 1` sube a 2 sin intervención y pierde el índice redundante.
- Ningún test ni fixture contiene un token real; los tokens de prueba empiezan por `EAA` solo para ejercitar la redacción.
- Fuera de alcance (fase 1b): `FacebookPageAdapter`, `Templates`, `Queue`, `Publisher`, disparador, CLI, admin.

# Voceador · Fase 0 (cimientos) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dejar un plugin activable que crea su esquema de datos y su capacidad, se desinstala limpio, y tiene entorno local, tests y lint funcionando en local y en CI.

**Architecture:** La raíz del repo es la carpeta del plugin. `bootstrap.php` define constantes y el autoloader PSR-4 (`Voceador\` → `src/`) y lo comparten `voceador.php` y `uninstall.php`. `Plugin` es el contenedor; `Schema` solo conoce tablas; `Installer` decide cuándo instalar (perezoso en `init`, por sitio); `Uninstaller` limpia por sitio.

**Tech Stack:** PHP 8.1+, WordPress 6.4+, `@wordpress/env` (Docker), PHPUnit 9.6 + suite de tests de WordPress, PHPCS con WordPress Coding Standards 3.x. Composer se usa **solo para desarrollo** y se ejecuta dentro del contenedor de wp-env (no hay Composer en el host).

**Spec:** `docs/superpowers/specs/2026-09-21-voceador-design.md` (secciones 1, 2 "Arranque" y "Multisite", 3 y 7 fase 0).

## Global Constraints

- PHP 8.1+ y WordPress 6.4+; compatible con single site y Multisite.
- Sin Composer en runtime: nada de `vendor/` se carga desde el plugin. `vendor/` y `node_modules/` están en `.gitignore` y `.distignore`.
- Namespace `Voceador`; prefijo `voceador_` para opciones, metas, hooks y tablas, tomado siempre de la constante `VOCEADOR_PREFIX` (nunca el literal, salvo en `bootstrap.php`).
- Text domain `voceador`; cadenas de interfaz en español.
- Capacidad propia: `voceador_manage`, asignada a `administrator`.
- Opciones con `autoload = no` salvo `voceador_db_version`.
- Todo el código pasa `npm run lint` (WPCS) sin errores. Usa `array()` (no `[]`), tabs, condiciones Yoda y docblocks.
- Fechas en tablas: `DATETIME` en UTC.
- Licencia GPL-2.0-or-later en la cabecera del plugin.
- Commits en español, formato `tipo: descripción`, terminados con `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.
- **Nunca ejecutes `wp plugin uninstall voceador` sin `--skip-delete`**: la carpeta del plugin en wp-env es este repositorio montado y WP-CLI borraría los archivos.

## File Structure

| Archivo | Responsabilidad |
| --- | --- |
| `package.json` | Dependencia `@wordpress/env` y scripts (`env:start`, `composer`, `test`, `test:multisite`, `lint`, `lint:fix`) |
| `.wp-env.json` | WordPress local con PHP 8.1 y el repo montado como plugin |
| `composer.json` | Solo `require-dev`: PHPUnit, polyfills, WPCS, PHPCompatibilityWP |
| `phpunit.xml.dist`, `tests/phpunit/multisite.xml` | Configuración de PHPUnit en single site y Multisite |
| `tests/bootstrap.php` | Carga la suite de WordPress y el plugin |
| `.phpcs.xml.dist` | Reglas de lint |
| `bootstrap.php` | Constantes `VOCEADOR_*` y autoloader |
| `voceador.php` | Cabecera del plugin, hook de activación, arranque |
| `src/Plugin.php` | Contenedor: instancia servicios y registra hooks |
| `src/Schema.php` | Nombres de tablas, `install()` con `dbDelta`, `drop()` |
| `src/Installer.php` | `maybe_install()`, `activate()`, `on_new_site()`, capacidad |
| `src/Uninstaller.php` | Limpieza por sitio y en red |
| `uninstall.php` | Punto de entrada de desinstalación |
| `.github/workflows/ci.yml` | Lint y tests en cada push y PR |

---

### Task 1: Entorno, tests y lint con un plugin mínimo

**Files:**
- Create: `package.json`, `.wp-env.json`, `composer.json`, `phpunit.xml.dist`, `tests/phpunit/multisite.xml`, `tests/bootstrap.php`, `.phpcs.xml.dist`, `bootstrap.php`, `voceador.php`
- Test: `tests/phpunit/BootstrapTest.php`

**Interfaces:**
- Consumes: nada.
- Produces: constantes `VOCEADOR_VERSION` (string), `VOCEADOR_PREFIX` (`'voceador_'`), `VOCEADOR_FILE` (ruta absoluta a `voceador.php`), `VOCEADOR_DIR` (ruta absoluta a la raíz del plugin); autoloader `Voceador\Foo\Bar` → `src/Foo/Bar.php`; comandos `npm run test`, `npm run test:multisite`, `npm run lint`, `npm run lint:fix`, `npm run composer -- <args>`.

- [ ] **Step 1: Comprobar Docker**

Run: `docker info --format '{{.ServerVersion}}'`
Expected: imprime un número de versión. Si da error de conexión, abre Docker Desktop y espera a que arranque antes de seguir.

- [ ] **Step 2: Crear `package.json`**

```json
{
  "name": "voceador",
  "version": "0.1.0",
  "private": true,
  "license": "GPL-2.0-or-later",
  "scripts": {
    "env:start": "wp-env start",
    "env:stop": "wp-env stop",
    "composer": "wp-env run tests-cli --env-cwd=wp-content/plugins/voceador composer",
    "test": "wp-env run tests-cli --env-cwd=wp-content/plugins/voceador vendor/bin/phpunit",
    "test:multisite": "wp-env run tests-cli --env-cwd=wp-content/plugins/voceador vendor/bin/phpunit -c tests/phpunit/multisite.xml",
    "lint": "wp-env run tests-cli --env-cwd=wp-content/plugins/voceador vendor/bin/phpcs",
    "lint:fix": "wp-env run tests-cli --env-cwd=wp-content/plugins/voceador vendor/bin/phpcbf"
  },
  "devDependencies": {
    "@wordpress/env": "^10.0.0"
  }
}
```

- [ ] **Step 3: Crear `.wp-env.json`**

```json
{
  "core": null,
  "phpVersion": "8.1",
  "plugins": ["."],
  "config": {
    "WP_DEBUG": true,
    "WP_DEBUG_LOG": true,
    "SCRIPT_DEBUG": true
  }
}
```

El directorio del repo se llama `voceador`, así que wp-env lo monta en `wp-content/plugins/voceador`. Si el repo se clona con otro nombre, los scripts de `package.json` dejan de encontrar la ruta.

- [ ] **Step 4: Crear `composer.json`**

```json
{
  "name": "aprendomx/voceador",
  "description": "Voceador – Autopublicación de notas en redes sociales",
  "type": "wordpress-plugin",
  "license": "GPL-2.0-or-later",
  "require": {
    "php": ">=8.1"
  },
  "require-dev": {
    "phpunit/phpunit": "^9.6",
    "yoast/phpunit-polyfills": "^2.0",
    "wp-coding-standards/wpcs": "^3.1",
    "phpcompatibility/phpcompatibility-wp": "^2.1",
    "dealerdirect/phpcodesniffer-composer-installer": "^1.0"
  },
  "config": {
    "allow-plugins": {
      "dealerdirect/phpcodesniffer-composer-installer": true
    },
    "platform": {
      "php": "8.1.0"
    }
  }
}
```

- [ ] **Step 5: Crear `phpunit.xml.dist`**

```xml
<?xml version="1.0"?>
<phpunit
	bootstrap="tests/bootstrap.php"
	backupGlobals="false"
	colors="true"
	convertErrorsToExceptions="true"
	convertNoticesToExceptions="true"
	convertWarningsToExceptions="true"
	convertDeprecationsToExceptions="true"
>
	<testsuites>
		<testsuite name="voceador">
			<directory suffix="Test.php">tests/phpunit</directory>
		</testsuite>
	</testsuites>
	<groups>
		<exclude>
			<group>ms-required</group>
		</exclude>
	</groups>
</phpunit>
```

- [ ] **Step 6: Crear `tests/phpunit/multisite.xml`**

```xml
<?xml version="1.0"?>
<phpunit
	bootstrap="../bootstrap.php"
	backupGlobals="false"
	colors="true"
	convertErrorsToExceptions="true"
	convertNoticesToExceptions="true"
	convertWarningsToExceptions="true"
	convertDeprecationsToExceptions="true"
>
	<php>
		<const name="WP_TESTS_MULTISITE" value="1" />
	</php>
	<testsuites>
		<testsuite name="voceador-multisite">
			<directory suffix="Test.php">.</directory>
		</testsuite>
	</testsuites>
	<groups>
		<exclude>
			<group>ms-excluded</group>
		</exclude>
	</groups>
</phpunit>
```

- [ ] **Step 7: Crear `tests/bootstrap.php`**

```php
<?php
/**
 * Arranque de PHPUnit: carga la suite de tests de WordPress y el plugin.
 *
 * @package Voceador
 */

$voceador_root = dirname( __DIR__ );

require_once $voceador_root . '/vendor/autoload.php';

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $voceador_root . '/vendor/yoast/phpunit-polyfills' );
}

$voceador_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $voceador_tests_dir ) {
	$voceador_tests_dir = '/wordpress-phpunit';
}

if ( ! file_exists( $voceador_tests_dir . '/includes/functions.php' ) ) {
	echo "No se encontró la suite de tests de WordPress en {$voceador_tests_dir}. Ejecuta los tests con `npm run test`." . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

require_once $voceador_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $voceador_root ): void {
		require $voceador_root . '/voceador.php';
	}
);

require $voceador_tests_dir . '/includes/bootstrap.php';
```

- [ ] **Step 8: Crear `.phpcs.xml.dist`**

```xml
<?xml version="1.0"?>
<ruleset name="Voceador">
	<description>Reglas de código de Voceador.</description>

	<file>.</file>
	<exclude-pattern>/vendor/*</exclude-pattern>
	<exclude-pattern>/node_modules/*</exclude-pattern>
	<exclude-pattern>/docs/*</exclude-pattern>

	<arg name="extensions" value="php" />
	<arg name="basepath" value="." />
	<arg value="sp" />

	<config name="minimum_wp_version" value="6.4" />
	<config name="testVersion" value="8.1-" />

	<rule ref="WordPress">
		<!-- Las clases siguen PSR-4: src/Foo/Bar.php, no class-bar.php. -->
		<exclude name="WordPress.Files.FileName" />
	</rule>
	<rule ref="PHPCompatibilityWP" />

	<rule ref="WordPress.WP.I18n">
		<properties>
			<property name="text_domain" type="array">
				<element value="voceador" />
			</property>
		</properties>
	</rule>
	<rule ref="WordPress.NamingConventions.PrefixAllGlobals">
		<properties>
			<property name="prefixes" type="array">
				<element value="voceador" />
				<element value="Voceador" />
			</property>
		</properties>
		<exclude-pattern>/tests/*</exclude-pattern>
	</rule>

	<!-- Los tests no necesitan docblocks por método ni consultas preparadas. -->
	<rule ref="Squiz.Commenting">
		<exclude-pattern>/tests/*</exclude-pattern>
	</rule>
	<rule ref="Generic.Commenting">
		<exclude-pattern>/tests/*</exclude-pattern>
	</rule>
	<rule ref="WordPress.DB">
		<exclude-pattern>/tests/*</exclude-pattern>
	</rule>
</ruleset>
```

- [ ] **Step 9: Arrancar el entorno e instalar dependencias de desarrollo**

Run: `npm install && npm run env:start && npm run composer -- install`
Expected: wp-env informa `WordPress development site started at http://localhost:8888` y Composer termina con `Generating autoload files`. Aparecen `vendor/` y `composer.lock`.

- [ ] **Step 10: Escribir el test que falla**

`tests/phpunit/BootstrapTest.php`:

```php
<?php
/**
 * @package Voceador
 */

class BootstrapTest extends WP_UnitTestCase {

	public function test_constants_are_defined(): void {
		$this->assertSame( 'voceador_', VOCEADOR_PREFIX );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', VOCEADOR_VERSION );
		$this->assertFileExists( VOCEADOR_FILE );
		$this->assertSame( dirname( VOCEADOR_FILE ), VOCEADOR_DIR );
	}

	public function test_autoloader_ignores_unknown_classes(): void {
		$this->assertFalse( class_exists( 'Voceador\\NoExiste' ) );
		$this->assertFalse( class_exists( 'OtroNamespace\\Clase' ) );
	}
}
```

- [ ] **Step 11: Ejecutar el test y verificar que falla**

Run: `npm run test`
Expected: FAIL. El bootstrap aborta porque `voceador.php` no existe (`Failed to open stream` / `failed to open stream`).

- [ ] **Step 12: Crear `bootstrap.php`**

```php
<?php
/**
 * Constantes y autoloader compartidos por voceador.php y uninstall.php.
 *
 * @package Voceador
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'VOCEADOR_VERSION' ) ) {
	define( 'VOCEADOR_VERSION', '0.1.0' );
	define( 'VOCEADOR_PREFIX', 'voceador_' );
	define( 'VOCEADOR_FILE', __DIR__ . '/voceador.php' );
	define( 'VOCEADOR_DIR', __DIR__ );

	spl_autoload_register(
		static function ( string $class_name ): void {
			$namespace = 'Voceador\\';
			if ( 0 !== strncmp( $class_name, $namespace, strlen( $namespace ) ) ) {
				return;
			}

			$relative = substr( $class_name, strlen( $namespace ) );
			$file     = VOCEADOR_DIR . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	);
}
```

- [ ] **Step 13: Crear `voceador.php` mínimo**

```php
<?php
/**
 * Plugin Name:       Voceador – Autopublicación de notas en redes sociales
 * Plugin URI:        https://github.com/aprendomx/voceador
 * Description:       Anuncia automáticamente cada nota nueva en tus Páginas de Facebook y cuentas de Instagram.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Aprendo MX
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       voceador
 * Domain Path:       /languages
 *
 * @package Voceador
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/bootstrap.php';
```

- [ ] **Step 14: Ejecutar tests y lint**

Run: `npm run test && npm run lint`
Expected: PHPUnit `OK (2 tests, 6 assertions)`; PHPCS sin errores. Si PHPCS reporta errores de formato, ejecuta `npm run lint:fix` y vuelve a lanzar `npm run lint`.

- [ ] **Step 15: Commit**

```bash
git add package.json package-lock.json .wp-env.json composer.json composer.lock phpunit.xml.dist tests .phpcs.xml.dist bootstrap.php voceador.php
git commit -m "chore: entorno wp-env, PHPUnit, PHPCS y plugin mínimo"
```

---

### Task 2: Esquema de datos (`Schema`)

**Files:**
- Create: `src/Schema.php`
- Test: `tests/phpunit/SchemaTest.php`

**Interfaces:**
- Consumes: `VOCEADOR_PREFIX`, autoloader (Task 1).
- Produces:
  - `Voceador\Schema::DB_VERSION` (string, `'1'`)
  - `Voceador\Schema::TABLES` (`array( 'channels', 'jobs', 'log' )`)
  - `new Schema( \wpdb $wpdb )`
  - `table( string $name ): string` — nombre completo con el prefijo del sitio actual; lanza `\InvalidArgumentException` si `$name` no está en `TABLES`
  - `install(): void` — crea o actualiza las tres tablas con `dbDelta`
  - `drop(): void` — elimina las tres tablas

Nota sobre los tests: `WP_UnitTestCase` reescribe `CREATE TABLE` a `CREATE TEMPORARY TABLE`. Las tablas temporales no salen en `SHOW TABLES`, así que la existencia se comprueba con `DESCRIBE`.

- [ ] **Step 1: Escribir los tests que fallan**

`tests/phpunit/SchemaTest.php`:

```php
<?php
/**
 * @package Voceador
 */

use Voceador\Schema;

class SchemaTest extends WP_UnitTestCase {

	private Schema $schema;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->schema = new Schema( $wpdb );
		$this->schema->install();
	}

	private function columns( string $table ): array {
		global $wpdb;
		return $wpdb->get_col( 'DESCRIBE ' . $this->schema->table( $table ), 0 );
	}

	public function test_table_uses_site_prefix_and_plugin_prefix(): void {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'voceador_jobs', $this->schema->table( 'jobs' ) );
	}

	public function test_table_rejects_unknown_names(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->schema->table( 'posts' );
	}

	public function test_channels_columns(): void {
		$this->assertSame(
			array(
				'id',
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
				'created_at',
				'updated_at',
			),
			$this->columns( 'channels' )
		);
	}

	public function test_jobs_columns(): void {
		$this->assertSame(
			array(
				'id',
				'post_id',
				'channel_id',
				'status',
				'comment_status',
				'remote_id',
				'remote_url',
				'remote_comment_id',
				'container_id',
				'attempts',
				'comment_attempts',
				'scheduled_at',
				'published_at',
				'error_code',
				'error_message',
				'source',
				'created_at',
				'updated_at',
			),
			$this->columns( 'jobs' )
		);
	}

	public function test_log_columns(): void {
		$this->assertSame(
			array( 'id', 'created_at', 'level', 'channel_id', 'post_id', 'job_id', 'event', 'message', 'context' ),
			$this->columns( 'log' )
		);
	}

	public function test_jobs_reject_duplicate_post_and_channel(): void {
		global $wpdb;
		$row = array(
			'post_id'    => 10,
			'channel_id' => 3,
		);

		$this->assertSame( 1, $wpdb->insert( $this->schema->table( 'jobs' ), $row ) );

		$suppress = $wpdb->suppress_errors( true );
		$second   = $wpdb->insert( $this->schema->table( 'jobs' ), $row );
		$wpdb->suppress_errors( $suppress );

		$this->assertFalse( $second );
	}

	public function test_jobs_defaults(): void {
		global $wpdb;
		$wpdb->insert(
			$this->schema->table( 'jobs' ),
			array(
				'post_id'    => 11,
				'channel_id' => 3,
			)
		);
		$job = $wpdb->get_row( 'SELECT * FROM ' . $this->schema->table( 'jobs' ) . ' WHERE post_id = 11' );

		$this->assertSame( 'pending', $job->status );
		$this->assertSame( 'none', $job->comment_status );
		$this->assertSame( 'auto', $job->source );
		$this->assertSame( '0', (string) $job->attempts );
		$this->assertNull( $job->remote_id );
	}

	public function test_channels_reject_duplicate_type_and_remote_id(): void {
		global $wpdb;
		$row = array(
			'type'        => 'facebook_page',
			'alias'       => 'Principal',
			'remote_id'   => '1234567890',
			'credentials' => '',
			'scopes'      => '[]',
			'health'      => '{}',
			'settings'    => '{}',
		);

		$this->assertSame( 1, $wpdb->insert( $this->schema->table( 'channels' ), $row ) );

		$suppress = $wpdb->suppress_errors( true );
		$second   = $wpdb->insert( $this->schema->table( 'channels' ), $row );
		$wpdb->suppress_errors( $suppress );

		$this->assertFalse( $second );
	}

	public function test_install_is_idempotent(): void {
		$this->schema->install();
		$this->assertCount( 18, $this->columns( 'jobs' ) );
	}
}
```

- [ ] **Step 2: Ejecutar y verificar que falla**

Run: `npm run test -- --filter SchemaTest`
Expected: FAIL con `Class "Voceador\Schema" not found`.

- [ ] **Step 3: Implementar `src/Schema.php`**

`dbDelta` es estricto con el formato: una columna por línea, dos espacios tras `PRIMARY KEY`, `KEY` en lugar de `INDEX`, sin comillas invertidas.

```php
<?php
/**
 * Esquema de base de datos del plugin.
 *
 * @package Voceador
 */

namespace Voceador;

/**
 * Conoce los nombres de las tablas y sabe crearlas y eliminarlas.
 */
final class Schema {

	/**
	 * Versión del esquema. Súbela cada vez que cambie el SQL de install().
	 */
	public const DB_VERSION = '1';

	/**
	 * Nombres cortos de las tablas del plugin.
	 */
	public const TABLES = array( 'channels', 'jobs', 'log' );

	/**
	 * Conexión a la base de datos.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $wpdb Conexión a la base de datos.
	 */
	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Nombre completo de una tabla para el sitio actual.
	 *
	 * @param string $name Nombre corto: channels, jobs o log.
	 * @return string
	 * @throws \InvalidArgumentException Si la tabla no pertenece al plugin.
	 */
	public function table( string $name ): string {
		if ( ! in_array( $name, self::TABLES, true ) ) {
			throw new \InvalidArgumentException( 'Tabla desconocida: ' . esc_html( $name ) );
		}

		return $this->wpdb->prefix . VOCEADOR_PREFIX . $name;
	}

	/**
	 * Crea o actualiza las tablas.
	 */
	public function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate  = $this->wpdb->get_charset_collate();
		$channels = $this->table( 'channels' );
		$jobs     = $this->table( 'jobs' );
		$log      = $this->table( 'log' );

		dbDelta(
			"CREATE TABLE {$channels} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(40) NOT NULL DEFAULT '',
			alias varchar(190) NOT NULL DEFAULT '',
			remote_id varchar(64) NOT NULL DEFAULT '',
			remote_name varchar(190) NOT NULL DEFAULT '',
			avatar_url text NULL,
			connection_method varchar(20) NOT NULL DEFAULT '',
			parent_channel_id bigint(20) unsigned NULL,
			credentials longtext NOT NULL,
			scopes text NOT NULL,
			token_expires_at datetime NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			health text NOT NULL,
			health_checked_at datetime NULL,
			settings longtext NOT NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY type_remote (type,remote_id),
			KEY type (type),
			KEY status (status)
			) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$jobs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			channel_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			comment_status varchar(20) NOT NULL DEFAULT 'none',
			remote_id varchar(100) NULL,
			remote_url text NULL,
			remote_comment_id varchar(100) NULL,
			container_id varchar(100) NULL,
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			comment_attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			scheduled_at datetime NULL,
			published_at datetime NULL,
			error_code varchar(40) NULL,
			error_message text NULL,
			source varchar(20) NOT NULL DEFAULT 'auto',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY post_channel (post_id,channel_id),
			KEY status (status),
			KEY channel_status (channel_id,status),
			KEY scheduled_at (scheduled_at)
			) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			level varchar(10) NOT NULL DEFAULT 'info',
			channel_id bigint(20) unsigned NULL,
			post_id bigint(20) unsigned NULL,
			job_id bigint(20) unsigned NULL,
			event varchar(60) NOT NULL DEFAULT '',
			message text NOT NULL,
			context longtext NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY channel_created (channel_id,created_at),
			KEY post_id (post_id),
			KEY level (level)
			) {$collate};"
		);
	}

	/**
	 * Elimina las tablas del plugin.
	 */
	public function drop(): void {
		foreach ( self::TABLES as $name ) {
			$table = $this->table( $name );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- El nombre de tabla viene de una lista cerrada.
			$this->wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}
}
```

- [ ] **Step 4: Ejecutar tests y lint**

Run: `npm run test -- --filter SchemaTest && npm run lint`
Expected: `OK (9 tests, ...)` y PHPCS sin errores. Si algún test de columnas falla por una columna que `dbDelta` no creó, revisa el formato de esa línea del SQL (es el fallo típico de `dbDelta`).

- [ ] **Step 5: Commit**

```bash
git add src/Schema.php tests/phpunit/SchemaTest.php
git commit -m "feat: esquema de datos con tablas channels, jobs y log"
```

---

### Task 3: Instalación perezosa, capacidad y arranque (`Installer`, `Plugin`)

**Files:**
- Create: `src/Installer.php`, `src/Plugin.php`
- Modify: `voceador.php` (añadir hook de activación y arranque al final)
- Test: `tests/phpunit/InstallerTest.php`, `tests/phpunit/PluginTest.php`, `tests/phpunit/InstallerMultisiteTest.php`

**Interfaces:**
- Consumes: `Voceador\Schema` (`DB_VERSION`, `install()`, `table()`), `VOCEADOR_PREFIX`, `VOCEADOR_FILE`.
- Produces:
  - `Voceador\Installer::CAPABILITY` (`'voceador_manage'`)
  - `new Installer( Schema $schema )`
  - `maybe_install(): void` — si `voceador_db_version` ≠ `Schema::DB_VERSION`: crea tablas, concede la capacidad a `administrator` y guarda la versión (autoload sí)
  - `activate( bool $network_wide ): void` — instala en el sitio actual; el resto de sitios se instalan de forma perezosa
  - `on_new_site( \WP_Site $site ): void` — instala en un sitio nuevo si el plugin está activo en red
  - `Voceador\Plugin::boot(): Plugin` (idempotente), `schema(): Schema`, `installer(): Installer`

- [ ] **Step 1: Escribir los tests que fallan**

`tests/phpunit/InstallerTest.php`:

```php
<?php
/**
 * @package Voceador
 */

use Voceador\Installer;
use Voceador\Schema;

class InstallerTest extends WP_UnitTestCase {

	private Installer $installer;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->installer = new Installer( new Schema( $wpdb ) );
	}

	public function tear_down(): void {
		// La BD se revierte sola, pero WP_Roles conserva los cambios en memoria.
		get_role( 'administrator' )->add_cap( Installer::CAPABILITY );
		parent::tear_down();
	}

	public function test_maybe_install_stores_version_and_grants_capability(): void {
		delete_option( VOCEADOR_PREFIX . 'db_version' );
		get_role( 'administrator' )->remove_cap( Installer::CAPABILITY );

		$this->installer->maybe_install();

		$this->assertSame( Schema::DB_VERSION, get_option( VOCEADOR_PREFIX . 'db_version' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Installer::CAPABILITY ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( Installer::CAPABILITY ) );
	}

	public function test_maybe_install_does_nothing_when_version_is_current(): void {
		update_option( VOCEADOR_PREFIX . 'db_version', Schema::DB_VERSION );
		get_role( 'administrator' )->remove_cap( Installer::CAPABILITY );

		$this->installer->maybe_install();

		$this->assertFalse( get_role( 'administrator' )->has_cap( Installer::CAPABILITY ) );
	}

	public function test_maybe_install_runs_again_when_version_changes(): void {
		update_option( VOCEADOR_PREFIX . 'db_version', '0' );
		get_role( 'administrator' )->remove_cap( Installer::CAPABILITY );

		$this->installer->maybe_install();

		$this->assertSame( Schema::DB_VERSION, get_option( VOCEADOR_PREFIX . 'db_version' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Installer::CAPABILITY ) );
	}

	public function test_activate_installs_current_site(): void {
		delete_option( VOCEADOR_PREFIX . 'db_version' );

		$this->installer->activate( false );

		$this->assertSame( Schema::DB_VERSION, get_option( VOCEADOR_PREFIX . 'db_version' ) );
	}
}
```

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

	public function test_boot_returns_the_same_instance(): void {
		$this->assertSame( Plugin::boot(), Plugin::boot() );
	}

	public function test_exposes_services(): void {
		$this->assertInstanceOf( Schema::class, Plugin::boot()->schema() );
		$this->assertInstanceOf( Installer::class, Plugin::boot()->installer() );
	}

	public function test_registers_lazy_install_on_init(): void {
		$this->assertSame(
			10,
			has_action( 'init', array( Plugin::boot()->installer(), 'maybe_install' ) )
		);
	}

	public function test_site_was_installed_during_bootstrap(): void {
		$this->assertSame( Schema::DB_VERSION, get_option( VOCEADOR_PREFIX . 'db_version' ) );
	}
}
```

`tests/phpunit/InstallerMultisiteTest.php`:

```php
<?php
/**
 * @package Voceador
 * @group ms-required
 */

use Voceador\Installer;
use Voceador\Schema;

class InstallerMultisiteTest extends WP_UnitTestCase {

	public function test_new_site_is_installed_when_network_active(): void {
		update_site_option(
			'active_sitewide_plugins',
			array( plugin_basename( VOCEADOR_FILE ) => time() )
		);

		$blog_id = self::factory()->blog->create();

		switch_to_blog( $blog_id );
		$version = get_option( VOCEADOR_PREFIX . 'db_version' );
		$has_cap = get_role( 'administrator' )->has_cap( Installer::CAPABILITY );
		restore_current_blog();

		$this->assertSame( Schema::DB_VERSION, $version );
		$this->assertTrue( $has_cap );
	}

	public function test_new_site_is_not_installed_when_not_network_active(): void {
		update_site_option( 'active_sitewide_plugins', array() );

		$blog_id = self::factory()->blog->create();

		switch_to_blog( $blog_id );
		$version = get_option( VOCEADOR_PREFIX . 'db_version' );
		restore_current_blog();

		$this->assertFalse( $version );
	}

	public function test_tables_use_each_site_prefix(): void {
		global $wpdb;
		$schema  = new Schema( $wpdb );
		$blog_id = self::factory()->blog->create();

		$main = $schema->table( 'jobs' );
		switch_to_blog( $blog_id );
		$other = $schema->table( 'jobs' );
		restore_current_blog();

		$this->assertNotSame( $main, $other );
		$this->assertStringContainsString( '_' . $blog_id . '_voceador_jobs', $other );
	}
}
```

- [ ] **Step 2: Ejecutar y verificar que fallan**

Run: `npm run test -- --filter 'InstallerTest|PluginTest'`
Expected: FAIL con `Class "Voceador\Installer" not found`.

- [ ] **Step 3: Implementar `src/Installer.php`**

```php
<?php
/**
 * Instalación del plugin por sitio.
 *
 * @package Voceador
 */

namespace Voceador;

/**
 * Decide cuándo crear el esquema y conceder la capacidad.
 */
final class Installer {

	/**
	 * Capacidad necesaria para gestionar los ajustes del plugin.
	 */
	public const CAPABILITY = 'voceador_manage';

	/**
	 * Esquema de datos.
	 *
	 * @var Schema
	 */
	private Schema $schema;

	/**
	 * Constructor.
	 *
	 * @param Schema $schema Esquema de datos.
	 */
	public function __construct( Schema $schema ) {
		$this->schema = $schema;
	}

	/**
	 * Instala o actualiza el sitio actual si su versión de esquema no es la vigente.
	 */
	public function maybe_install(): void {
		if ( Schema::DB_VERSION === get_option( VOCEADOR_PREFIX . 'db_version' ) ) {
			return;
		}

		$this->schema->install();

		$role = get_role( 'administrator' );
		if ( null !== $role ) {
			$role->add_cap( self::CAPABILITY );
		}

		update_option( VOCEADOR_PREFIX . 'db_version', Schema::DB_VERSION, true );
	}

	/**
	 * Hook de activación.
	 *
	 * En una activación en red solo se instala el sitio actual; los demás
	 * se instalan de forma perezosa en su primer `init`.
	 *
	 * @param bool $network_wide Si la activación es en red.
	 */
	public function activate( bool $network_wide ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$this->maybe_install();
	}

	/**
	 * Instala un sitio recién creado cuando el plugin está activo en red.
	 *
	 * @param \WP_Site $site Sitio nuevo.
	 */
	public function on_new_site( \WP_Site $site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( plugin_basename( VOCEADOR_FILE ) ) ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );
		$this->maybe_install();
		restore_current_blog();
	}
}
```

- [ ] **Step 4: Implementar `src/Plugin.php`**

```php
<?php
/**
 * Contenedor del plugin.
 *
 * @package Voceador
 */

namespace Voceador;

/**
 * Instancia los servicios una sola vez y registra los hooks.
 */
final class Plugin {

	/**
	 * Instancia única del contenedor.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Esquema de datos.
	 *
	 * @var Schema
	 */
	private Schema $schema;

	/**
	 * Instalador.
	 *
	 * @var Installer
	 */
	private Installer $installer;

	/**
	 * Constructor.
	 */
	private function __construct() {
		global $wpdb;

		$this->schema    = new Schema( $wpdb );
		$this->installer = new Installer( $this->schema );
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
	 * Esquema de datos.
	 *
	 * @return Schema
	 */
	public function schema(): Schema {
		return $this->schema;
	}

	/**
	 * Instalador.
	 *
	 * @return Installer
	 */
	public function installer(): Installer {
		return $this->installer;
	}

	/**
	 * Registra los hooks del núcleo.
	 */
	private function register_hooks(): void {
		add_action( 'init', array( $this->installer, 'maybe_install' ) );
		add_action( 'wp_initialize_site', array( $this->installer, 'on_new_site' ), 20 );
	}
}
```

- [ ] **Step 5: Conectar el arranque en `voceador.php`**

Añade al final del archivo, después de `require_once __DIR__ . '/bootstrap.php';`:

```php

register_activation_hook(
	__FILE__,
	static function ( $network_wide ): void {
		\Voceador\Plugin::boot()->installer()->activate( (bool) $network_wide );
	}
);

add_action( 'plugins_loaded', array( \Voceador\Plugin::class, 'boot' ) );
```

- [ ] **Step 6: Ejecutar toda la suite en single site y Multisite, y el lint**

Run: `npm run test && npm run test:multisite && npm run lint`
Expected: single site `OK` (los tests del grupo `ms-required` no se ejecutan); Multisite `OK` incluyendo `InstallerMultisiteTest`; PHPCS sin errores.

- [ ] **Step 7: Commit**

```bash
git add src/Installer.php src/Plugin.php voceador.php tests/phpunit/InstallerTest.php tests/phpunit/PluginTest.php tests/phpunit/InstallerMultisiteTest.php
git commit -m "feat: instalación perezosa por sitio, capacidad voceador_manage y contenedor"
```

---

### Task 4: Desinstalación (`Uninstaller`, `uninstall.php`)

**Files:**
- Create: `src/Uninstaller.php`, `uninstall.php`
- Test: `tests/phpunit/UninstallerTest.php`, `tests/phpunit/UninstallerMultisiteTest.php`

**Interfaces:**
- Consumes: `Voceador\Schema` (`drop()`, `install()`, `table()`), `Voceador\Installer::CAPABILITY`, `VOCEADOR_PREFIX`.
- Produces:
  - `Voceador\Uninstaller::OPTIONS` — nombres cortos de las opciones del plugin: `db_version`, `app`, `settings`, `link_in_bio`, `wizard`, `notices`
  - `Voceador\Uninstaller::META_KEYS` — los 7 metas `_voceador_*` del spec
  - `Voceador\Uninstaller::CRON_HOOKS` — `voceador_check_tokens`, `voceador_refresh_ig_tokens`, `voceador_purge_log`
  - `Voceador\Uninstaller::UPLOADS_SUBDIR` (`'voceador'`) — subcarpeta de uploads para imágenes generadas; la fase 5 debe escribir ahí
  - `Uninstaller::run(): void` — limpia el sitio, o todos los sitios y la opción de red en Multisite
  - `Uninstaller::clean_site(): void` — limpia el sitio actual

Nota sobre los tests: `WP_UnitTestCase` reescribe `DROP TABLE` a `DROP TEMPORARY TABLE`, que no elimina tablas reales. El test de tablas quita esos filtros y reinstala el esquema en `tear_down` para no romper a los demás tests.

- [ ] **Step 1: Escribir los tests que fallan**

`tests/phpunit/UninstallerTest.php`:

```php
<?php
/**
 * @package Voceador
 */

use Voceador\Installer;
use Voceador\Schema;
use Voceador\Uninstaller;

class UninstallerTest extends WP_UnitTestCase {

	public function tear_down(): void {
		global $wpdb;

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		delete_option( VOCEADOR_PREFIX . 'db_version' );
		( new Installer( new Schema( $wpdb ) ) )->maybe_install();

		parent::tear_down();
	}

	private function table_exists( string $name ): bool {
		global $wpdb;
		$table = ( new Schema( $wpdb ) )->table( $name );

		$suppress = $wpdb->suppress_errors( true );
		$columns  = $wpdb->get_col( 'DESCRIBE ' . $table, 0 );
		$wpdb->suppress_errors( $suppress );

		return ! empty( $columns );
	}

	public function test_removes_tables(): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->assertTrue( $this->table_exists( 'jobs' ) );

		Uninstaller::clean_site();

		foreach ( Schema::TABLES as $name ) {
			$this->assertFalse( $this->table_exists( $name ), "La tabla {$name} sigue existiendo." );
		}
	}

	public function test_removes_options_and_transients(): void {
		update_option( VOCEADOR_PREFIX . 'settings', array( 'rules' => array() ), false );
		update_option( VOCEADOR_PREFIX . 'app', array( 'app_id' => '1' ), false );
		set_transient( VOCEADOR_PREFIX . 'lock_job_5', 1, 60 );

		Uninstaller::clean_site();

		foreach ( Uninstaller::OPTIONS as $option ) {
			$this->assertFalse( get_option( VOCEADOR_PREFIX . $option ), "La opción {$option} sigue existiendo." );
		}
		$this->assertFalse( get_transient( VOCEADOR_PREFIX . 'lock_job_5' ) );
	}

	public function test_removes_capability_from_every_role(): void {
		get_role( 'editor' )->add_cap( Installer::CAPABILITY );

		Uninstaller::clean_site();

		$this->assertFalse( get_role( 'administrator' )->has_cap( Installer::CAPABILITY ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( Installer::CAPABILITY ) );
	}

	public function test_clears_cron_hooks(): void {
		wp_schedule_event( time() + 3600, 'daily', VOCEADOR_PREFIX . 'check_tokens' );

		Uninstaller::clean_site();

		$this->assertFalse( wp_next_scheduled( VOCEADOR_PREFIX . 'check_tokens' ) );
	}

	public function test_keeps_post_meta_by_default(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_voceador_message_fb', 'Hola' );

		Uninstaller::clean_site();

		$this->assertSame( 'Hola', get_post_meta( $post_id, '_voceador_message_fb', true ) );
	}

	public function test_removes_post_meta_when_configured(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_voceador_message_fb', 'Hola' );
		update_post_meta( $post_id, '_voceador_ig_cache', array( 'file' => 'x.jpg' ) );
		update_post_meta( $post_id, '_otro_meta', 'se queda' );
		update_option(
			VOCEADOR_PREFIX . 'settings',
			array( 'uninstall' => array( 'delete_meta' => true ) ),
			false
		);

		Uninstaller::clean_site();

		$this->assertSame( '', get_post_meta( $post_id, '_voceador_message_fb', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_voceador_ig_cache', true ) );
		$this->assertSame( 'se queda', get_post_meta( $post_id, '_otro_meta', true ) );
	}

	public function test_removes_generated_images_directory(): void {
		$dir = wp_upload_dir()['basedir'] . '/' . Uninstaller::UPLOADS_SUBDIR;
		wp_mkdir_p( $dir . '/2026/09' );
		file_put_contents( $dir . '/2026/09/nota.jpg', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		Uninstaller::clean_site();

		$this->assertDirectoryDoesNotExist( $dir );
	}
}
```

`tests/phpunit/UninstallerMultisiteTest.php`:

```php
<?php
/**
 * @package Voceador
 * @group ms-required
 */

use Voceador\Uninstaller;

class UninstallerMultisiteTest extends WP_UnitTestCase {

	public function test_run_cleans_every_site_and_network_defaults(): void {
		$blog_id = self::factory()->blog->create();

		update_option( VOCEADOR_PREFIX . 'wizard', array( 'step' => 2 ), false );
		switch_to_blog( $blog_id );
		update_option( VOCEADOR_PREFIX . 'wizard', array( 'step' => 5 ), false );
		restore_current_blog();
		update_site_option( VOCEADOR_PREFIX . 'network_defaults', array( 'rules' => array() ) );

		Uninstaller::run();

		$this->assertFalse( get_option( VOCEADOR_PREFIX . 'wizard' ) );
		switch_to_blog( $blog_id );
		$other = get_option( VOCEADOR_PREFIX . 'wizard' );
		restore_current_blog();
		$this->assertFalse( $other );
		$this->assertFalse( get_site_option( VOCEADOR_PREFIX . 'network_defaults' ) );
	}

	public function tear_down(): void {
		// La BD se revierte sola, pero WP_Roles conserva los cambios en memoria.
		get_role( 'administrator' )->add_cap( \Voceador\Installer::CAPABILITY );
		parent::tear_down();
	}
}
```

- [ ] **Step 2: Ejecutar y verificar que fallan**

Run: `npm run test -- --filter UninstallerTest`
Expected: FAIL con `Class "Voceador\Uninstaller" not found`.

- [ ] **Step 3: Implementar `src/Uninstaller.php`**

```php
<?php
/**
 * Desinstalación del plugin.
 *
 * @package Voceador
 */

namespace Voceador;

/**
 * Elimina todo lo que el plugin creó.
 */
final class Uninstaller {

	/**
	 * Nombres cortos de las opciones del plugin.
	 */
	public const OPTIONS = array( 'db_version', 'app', 'settings', 'link_in_bio', 'wizard', 'notices' );

	/**
	 * Metas de post del plugin.
	 */
	public const META_KEYS = array(
		'_voceador_message_fb',
		'_voceador_message_ig',
		'_voceador_skip',
		'_voceador_channels',
		'_voceador_ig_image',
		'_voceador_ig_cache',
		'_voceador_first_published',
	);

	/**
	 * Hooks de cron del plugin.
	 */
	public const CRON_HOOKS = array( 'check_tokens', 'refresh_ig_tokens', 'purge_log' );

	/**
	 * Subcarpeta de uploads donde se guardan las imágenes generadas.
	 */
	public const UPLOADS_SUBDIR = 'voceador';

	/**
	 * Limpia el sitio, o todos los sitios de la red en Multisite.
	 */
	public static function run(): void {
		if ( ! is_multisite() ) {
			self::clean_site();
			return;
		}

		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			self::clean_site();
			restore_current_blog();
		}

		delete_site_option( VOCEADOR_PREFIX . 'network_defaults' );
	}

	/**
	 * Limpia el sitio actual.
	 */
	public static function clean_site(): void {
		global $wpdb;

		// Se lee antes de borrar las opciones.
		$settings    = get_option( VOCEADOR_PREFIX . 'settings', array() );
		$delete_meta = is_array( $settings ) && ! empty( $settings['uninstall']['delete_meta'] );

		( new Schema( $wpdb ) )->drop();

		foreach ( self::OPTIONS as $option ) {
			delete_option( VOCEADOR_PREFIX . $option );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- No hay API para borrar transients por prefijo.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . VOCEADOR_PREFIX ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . VOCEADOR_PREFIX ) . '%'
			)
		);
		wp_cache_flush();

		foreach ( wp_roles()->role_objects as $role ) {
			$role->remove_cap( Installer::CAPABILITY );
		}

		foreach ( self::CRON_HOOKS as $hook ) {
			wp_unschedule_hook( VOCEADOR_PREFIX . $hook );
		}

		if ( $delete_meta ) {
			foreach ( self::META_KEYS as $meta_key ) {
				delete_post_meta_by_key( $meta_key );
			}
		}

		self::delete_generated_images();
	}

	/**
	 * Borra la carpeta de imágenes generadas.
	 */
	private static function delete_generated_images(): void {
		$dir = wp_upload_dir()['basedir'] . '/' . self::UPLOADS_SUBDIR;
		if ( ! is_dir( $dir ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();

		global $wp_filesystem;
		if ( $wp_filesystem instanceof \WP_Filesystem_Base ) {
			$wp_filesystem->delete( $dir, true );
		}
	}
}
```

- [ ] **Step 4: Crear `uninstall.php`**

```php
<?php
/**
 * Desinstalación de Voceador.
 *
 * @package Voceador
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/bootstrap.php';

\Voceador\Uninstaller::run();
```

- [ ] **Step 5: Ejecutar toda la suite y el lint**

Run: `npm run test && npm run test:multisite && npm run lint`
Expected: todo `OK`, PHPCS sin errores. Ejecuta la suite completa dos veces seguidas: la segunda pasada confirma que `tear_down` de `UninstallerTest` deja el esquema reinstalado.

- [ ] **Step 6: Verificar en el WordPress real de wp-env**

```bash
npx wp-env run cli wp plugin activate voceador
npx wp-env run cli wp db query "SHOW TABLES LIKE '%voceador%'"
npx wp-env run cli wp option get voceador_db_version
npx wp-env run cli wp cap list administrator --format=csv | grep voceador_manage
```

Expected: tres tablas (`wp_voceador_channels`, `wp_voceador_jobs`, `wp_voceador_log`), versión `1` y la capacidad `voceador_manage`. Las tablas se crean en la primera petición tras activar; si `SHOW TABLES` sale vacío, ejecuta antes `npx wp-env run cli wp eval 'do_action("init");'` o visita http://localhost:8888.

Después la desinstalación, **siempre con `--skip-delete`** (la carpeta del plugin es este repo):

```bash
npx wp-env run cli wp plugin deactivate voceador
npx wp-env run cli wp plugin uninstall voceador --skip-delete
npx wp-env run cli wp db query "SHOW TABLES LIKE '%voceador%'"
npx wp-env run cli wp option get voceador_db_version
git status --short
npx wp-env run cli wp plugin activate voceador
```

Expected: `SHOW TABLES` vacío, `wp option get` responde que la opción no existe, `git status` no muestra archivos borrados, y el plugin vuelve a quedar activo.

- [ ] **Step 7: Commit**

```bash
git add src/Uninstaller.php uninstall.php tests/phpunit/UninstallerTest.php tests/phpunit/UninstallerMultisiteTest.php
git commit -m "feat: desinstalación limpia por sitio y en red"
```

---

### Task 5: CI y documentación de desarrollo

**Files:**
- Create: `.github/workflows/ci.yml`
- Modify: `README.md` (sustituir la línea de estado y añadir la sección "Desarrollo" antes de "Licencia")

**Interfaces:**
- Consumes: scripts de `package.json` (Task 1).
- Produces: check de CI `lint-and-test` en cada push a `main` y en cada PR.

- [ ] **Step 1: Crear `.github/workflows/ci.yml`**

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:

jobs:
  lint-and-test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          path: voceador

      - uses: actions/setup-node@v4
        with:
          node-version: 22
          cache: npm
          cache-dependency-path: voceador/package-lock.json

      - name: Instalar dependencias de Node
        working-directory: voceador
        run: npm ci

      - name: Arrancar wp-env
        working-directory: voceador
        run: npm run env:start

      - name: Instalar dependencias de desarrollo de PHP
        working-directory: voceador
        run: npm run composer -- install --no-interaction

      - name: Lint
        working-directory: voceador
        run: npm run lint

      - name: Tests (single site)
        working-directory: voceador
        run: npm run test

      - name: Tests (Multisite)
        working-directory: voceador
        run: npm run test:multisite
```

El checkout va a la carpeta `voceador` porque los scripts asumen que el plugin se monta en `wp-content/plugins/voceador`, y wp-env usa el nombre del directorio.

- [ ] **Step 2: Actualizar `README.md`**

Sustituye la línea `> Estado: en diseño. Todavía no hay código del plugin.` por:

```markdown
> Estado: fase 0 completada (entorno, esquema de datos, instalación y desinstalación). El plugin todavía no publica en redes.
```

Añade antes de `## Licencia`:

````markdown
## Desarrollo

Requisitos: Docker y Node 20+. No hace falta PHP ni Composer en el host; Composer se ejecuta dentro del contenedor y solo instala herramientas de desarrollo.

El directorio del repositorio debe llamarse `voceador`.

```bash
npm install
npm run env:start              # WordPress en http://localhost:8888 (admin / password)
npm run composer -- install    # PHPUnit y PHPCS dentro del contenedor
npm run test                   # tests en single site
npm run test:multisite         # tests en Multisite
npm run lint                   # WordPress Coding Standards
npm run lint:fix               # corrige el formato automáticamente
```

Para probar la desinstalación usa siempre `wp plugin uninstall voceador --skip-delete`: la carpeta del plugin en wp-env es este repositorio y sin ese flag WP-CLI borraría los archivos.
````

- [ ] **Step 3: Commit y push**

```bash
git add .github/workflows/ci.yml README.md
git commit -m "ci: lint y tests en GitHub Actions; documentación de desarrollo"
git push origin main
```

- [ ] **Step 4: Verificar el CI**

Run: `gh run watch --repo aprendomx/voceador --exit-status $(gh run list --repo aprendomx/voceador --limit 1 --json databaseId --jq '.[0].databaseId')`
Expected: el workflow `CI` termina en verde. Si falla, lee el log con `gh run view --log-failed`, corrige y vuelve a hacer push.

---

## Criterio de cierre de la fase 0

- `npm run test`, `npm run test:multisite` y `npm run lint` pasan en local y en CI.
- En wp-env el plugin activa, crea las tres tablas, guarda `voceador_db_version = 1` y concede `voceador_manage` a los administradores.
- `wp plugin uninstall voceador --skip-delete` deja la base de datos sin rastro del plugin.
- Fuera de alcance (llegan en su fase): crons, redirección al wizard, carga de traducciones, registro de metas, cualquier pantalla de admin.

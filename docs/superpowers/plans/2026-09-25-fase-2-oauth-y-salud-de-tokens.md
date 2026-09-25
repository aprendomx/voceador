# Voceador · Fase 2 (OAuth y salud de tokens) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que un administrador conecte sus Páginas de Facebook desde el escritorio de WordPress con el botón "Conectar con Facebook", sin pegar tokens a mano, y que el plugin vigile a diario la validez de esos tokens, pausando y avisando cuando uno deja de servir.

**Architecture:** `AppCredentials` guarda el App ID y el App Secret cifrado. `OAuth\Facebook` construye el diálogo de autorización, valida el `state` y hace los tres intercambios (código → token de usuario → token de larga duración → `GET /me/accounts`); el token de usuario nunca se persiste: solo se guardan los Page tokens, cifrados, de las Páginas que el administrador elige. `TokenManager` consulta `GET /debug_token` con un token de app y traduce la respuesta a `HealthReport`; un cron diario lo ejecuta sobre cada canal y, si uno deja de ser válido, lo pausa y deja un aviso persistente. `Admin\ConnectionsPage` es la única pantalla de esta fase: credenciales de la app, conexión, elección de Páginas y estado de los canales. Los ajustes completos (reglas, plantillas, ejecución) siguen siendo fase 3.

**Tech Stack:** PHP 8.1+, WordPress 6.5+, Graph API v26.0 (`/dialog/oauth`, `GET /oauth/access_token`, `GET /me/accounts`, `GET /debug_token`), `admin-post.php`, transients, WP-Cron, PHPUnit con `pre_http_request`, PHPCS/WPCS.

**Spec:** `docs/superpowers/specs/2026-09-21-voceador-design.md` (secciones "Conexión con Facebook", "Almacenamiento seguro", "Método A: OAuth", "Salud de los tokens", 3 "Opciones", 7 fila 2) y `docs/prompt-original.md` (sección "Conexión con Facebook").

## Endpoints verificados contra la documentación de Meta el 2026-09-25

| Paso | Llamada |
| --- | --- |
| Diálogo | `https://www.facebook.com/{version}/dialog/oauth` con `client_id`, `redirect_uri`, `state`, `scope`, `response_type=code`. Cancelar devuelve `error=access_denied&error_reason=user_denied&error_description=…` |
| Código → token | `GET /{version}/oauth/access_token?client_id&redirect_uri&client_secret&code` → `{access_token, token_type, expires_in}`. `redirect_uri` debe ser idéntica a la del diálogo |
| Larga duración | `GET /{version}/oauth/access_token?grant_type=fb_exchange_token&client_id&client_secret&fb_exchange_token` → `{access_token, token_type, expires_in}` (~60 días) |
| Páginas | `GET /me/accounts` con el token de usuario de larga duración → `data[]` con `id`, `name`, `access_token`, `tasks`, y `paging.next`. Los Page tokens derivados de un user token de larga duración **no expiran** |
| Validez | `GET /debug_token?input_token=<token a inspeccionar>` autenticado con un **token de app** `{app-id}|{app-secret}` → `data` con `is_valid`, `scopes`, `expires_at` (0 = nunca), `data_access_expires_at`, `profile_id`, `app_id`, `type` y, si falla, `error{code,message,subcode}` |

Permisos que se piden: `pages_show_list`, `pages_read_engagement`, `pages_manage_posts`, `pages_manage_engagement`, y `business_management` solo si el ajuste `app.business_management` está activo (Páginas dentro de un Business Manager).

## Global Constraints

- PHP 8.1+ y WordPress 6.5+; single site y Multisite.
- Sin Composer en runtime; HTTP solo a través de `GraphClient`.
- Namespace `Voceador`; opciones, transients, hooks y acciones de `admin-post` con prefijo tomado de `VOCEADOR_PREFIX`; text domain `voceador`; interfaz en español.
- **El App Secret y los tokens se guardan cifrados con libsodium** (`Crypto`); nunca se muestran completos, nunca se escriben en logs ni en mensajes de error, y el token de usuario obtenido en el OAuth **no se conserva**.
- Capacidad `voceador_manage` (constante `Installer::CAPABILITY`) para toda la pantalla y todos los handlers; nonces en cada formulario y en cada enlace de acción; escape de toda salida.
- Opciones con `autoload = no`.
- El `state` del OAuth es un transient `voceador_oauth_state_{hash}` con vida de 10 minutos, ligado al usuario que lo inició.
- El cron de salud **solo pausa, nunca reactiva**: un canal vuelve a `active` únicamente al reconectarlo.
- Errores como `WP_Error` con las clases normalizadas de `GraphClient` (`transient`, `rate_limited`, `auth`, `permission`, `media`, `spam`, `fatal`).
- WPCS: `array()`, tabs, Yoda, docblocks (tres líneas por propiedad readonly), `phpcs:ignore` con `-- razón`, ternarios completos. Tests exentos de docblocks y de `WordPress.DB`. `@group ms-required` en el docblock de la clase.
- `npm run lint`, `npm run test`, `npm run test:multisite` y `npm run check:plugin` en verde antes de cada commit relevante.
- Commits en español `tipo: descripción`, terminados en `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>`.
- Nunca `wp plugin uninstall voceador` sin `--skip-delete`.

## File Structure

| Archivo | Responsabilidad |
| --- | --- |
| `src/Logger.php` (modificar) | Redactar también tokens de app `{id}|{secret}` y de Instagram |
| `src/AppCredentials.php` | App ID y App Secret cifrado; token de app para `debug_token` |
| `src/OAuth/Facebook.php` | Diálogo, `state`, los tres intercambios, listado de Páginas y alta de canales |
| `src/TokenManager.php` | `debug_token` → `HealthReport`; revisión de todos los canales; pausa y aviso |
| `src/Admin/ConnectionsPage.php` | Menú, formulario de la app, conexión, elección de Páginas, tabla de canales |
| `src/Admin/Notices.php` | Pinta `voceador_notices` escapado y permite descartarlos |
| `src/Plugin.php` (modificar) | Factories y `REGISTRABLES` de los servicios nuevos |
| `src/CLI.php` (modificar) | `wp voceador channels check` |
| `src/Uninstaller.php` (modificar) | Borrar los transients y la opción nuevos |

---

### Task 1: Redacción reforzada y `AppCredentials`

**Files:**
- Modify: `src/Logger.php`, `src/Plugin.php`, `src/Uninstaller.php`
- Create: `src/AppCredentials.php`
- Test: `tests/phpunit/LoggerTest.php` (añadir), `tests/phpunit/AppCredentialsTest.php`

**Interfaces:**
- Consumes: `Crypto` (`encrypt( string ): string`, `decrypt( string ): string|\WP_Error`), `VOCEADOR_PREFIX`.
- Produces:
  - `Logger::SENSITIVE_IN_STRING` cubre además: tokens de app `{dígitos}|{32+ alfanuméricos}` y tokens de Instagram `IGAA…`/`IGQ…` de 20+ caracteres.
  - `AppCredentials::OPTION = 'app'` (opción `voceador_app`, autoload no).
  - `new AppCredentials( Crypto $crypto )`.
  - `app_id(): string` — cadena vacía si no está configurada.
  - `app_secret(): string` — descifrado; cadena vacía si no hay o si el descifrado falla.
  - `has_secret(): bool` — hay un secreto guardado (sin descifrarlo).
  - `is_configured(): bool` — hay App ID y secreto.
  - `app_token(): string` — `"{app_id}|{app_secret}"`, o cadena vacía si no está configurada.
  - `save( string $app_id, ?string $app_secret = null ): void` — guarda el App ID; el secreto solo se reescribe si `$app_secret` no es `null` ni cadena vacía, de modo que el formulario pueda enviarse sin repetirlo. Cifra el secreto antes de guardarlo.
  - `forget(): void` — borra la opción entera.
  - `business_management(): bool` / `set_business_management( bool ): void` — guarda `business_management` en la misma opción.

- [ ] **Step 1: Escribir los tests**

Añade a `tests/phpunit/LoggerTest.php`:

```php
	public function test_redact_masks_app_and_instagram_tokens(): void {
		$app_token = '1234567890123456|' . str_repeat( 'a1b2', 8 );
		$ig_token  = 'IGAA' . str_repeat( 'Xy9', 12 );
		$ig_legacy = 'IGQVJ' . str_repeat( 'Zz8', 12 );

		$redacted = Logger::redact(
			array(
				'msg'  => 'app ' . $app_token . ' fin',
				'ig'   => 'token ' . $ig_token,
				'old'  => $ig_legacy,
				'safe' => '12345|corto',
			)
		);

		$this->assertSame( 'app [redactado] fin', $redacted['msg'] );
		$this->assertSame( 'token [redactado]', $redacted['ig'] );
		$this->assertSame( '[redactado]', $redacted['old'] );
		$this->assertSame( '12345|corto', $redacted['safe'], 'Un pipe suelto no es un token de app.' );
	}

	public function test_redact_string_masks_app_token_in_a_url(): void {
		$app_token = '1234567890123456|' . str_repeat( 'a1b2', 8 );

		$this->assertSame(
			'https://graph.facebook.com/v26.0/debug_token?access_token=[redactado]&input_token=[redactado]',
			Logger::redact_string( 'https://graph.facebook.com/v26.0/debug_token?access_token=' . $app_token . '&input_token=EAA' . str_repeat( 'Ab1', 10 ) )
		);
	}
```

`tests/phpunit/AppCredentialsTest.php`:

```php
<?php
/**
 * @package Voceador
 */

use Voceador\AppCredentials;
use Voceador\Crypto;

class AppCredentialsTest extends WP_UnitTestCase {

	private AppCredentials $app;

	public function set_up(): void {
		parent::set_up();
		delete_option( VOCEADOR_PREFIX . AppCredentials::OPTION );
		$this->app = new AppCredentials( new Crypto( str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ) );
	}

	public function test_empty_by_default(): void {
		$this->assertSame( '', $this->app->app_id() );
		$this->assertSame( '', $this->app->app_secret() );
		$this->assertSame( '', $this->app->app_token() );
		$this->assertFalse( $this->app->has_secret() );
		$this->assertFalse( $this->app->is_configured() );
		$this->assertFalse( $this->app->business_management() );
	}

	public function test_save_and_read(): void {
		$this->app->save( '1234567890', 'secreto-de-la-app' );

		$this->assertSame( '1234567890', $this->app->app_id() );
		$this->assertSame( 'secreto-de-la-app', $this->app->app_secret() );
		$this->assertTrue( $this->app->has_secret() );
		$this->assertTrue( $this->app->is_configured() );
		$this->assertSame( '1234567890|secreto-de-la-app', $this->app->app_token() );
	}

	public function test_secret_is_stored_encrypted_and_not_autoloaded(): void {
		global $wpdb;
		$this->app->save( '1234567890', 'secreto-de-la-app' );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", VOCEADOR_PREFIX . AppCredentials::OPTION ), ARRAY_A );

		$this->assertStringNotContainsString( 'secreto-de-la-app', $row['option_value'] );
		$this->assertStringContainsString( 'v1:', $row['option_value'] );
		$this->assertContains( $row['autoload'], array( 'no', 'off' ) );
	}

	public function test_saving_without_secret_keeps_the_previous_one(): void {
		$this->app->save( '1234567890', 'secreto-de-la-app' );

		$this->app->save( '9999999999' );
		$this->assertSame( '9999999999', $this->app->app_id() );
		$this->assertSame( 'secreto-de-la-app', $this->app->app_secret() );

		$this->app->save( '9999999999', '' );
		$this->assertSame( 'secreto-de-la-app', $this->app->app_secret(), 'Una cadena vacía tampoco borra el secreto.' );
	}

	public function test_undecryptable_secret_reads_as_empty(): void {
		global $wpdb;
		$this->app->save( '1234567890', 'secreto-de-la-app' );

		$other = new AppCredentials( new Crypto( str_repeat( 'z', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ) );

		$this->assertSame( '', $other->app_secret() );
		$this->assertTrue( $other->has_secret(), 'El secreto existe aunque no se pueda descifrar.' );
		$this->assertFalse( $other->is_configured() );
		$this->assertSame( '', $other->app_token() );
	}

	public function test_business_management_flag(): void {
		$this->app->save( '1234567890', 'secreto' );
		$this->app->set_business_management( true );

		$this->assertTrue( $this->app->business_management() );
		$this->assertSame( '1234567890', $this->app->app_id(), 'No pisa las credenciales.' );
	}

	public function test_forget(): void {
		$this->app->save( '1234567890', 'secreto' );
		$this->app->forget();

		$this->assertFalse( get_option( VOCEADOR_PREFIX . AppCredentials::OPTION ) );
		$this->assertFalse( $this->app->is_configured() );
	}
}
```

- [ ] **Step 2: Ejecutar y verificar que fallan**

Run: `npm run test -- --filter 'AppCredentialsTest|LoggerTest'`
Expected: FAIL con `Class "Voceador\AppCredentials" not found` y los dos tests nuevos de `LoggerTest` en rojo.

- [ ] **Step 3: Reforzar la redacción en `src/Logger.php`**

Sustituye la constante `SENSITIVE_IN_STRING` por:

```php
	/**
	 * Patrones sensibles dentro de cadenas.
	 *
	 * Cubre los tres formatos de token de Meta: los de usuario y Página (EAA…),
	 * los de Instagram (IGAA…/IGQ…) y los de app ({app-id}|{app-secret}), que
	 * `debug_token` recibe como parámetro.
	 */
	private const SENSITIVE_IN_STRING = array(
		'/((?:access_token|client_secret|app_secret|fb_exchange_token|input_token|code)=)[^&\s]+/i' => '$1[redactado]',
		'/EAA[A-Za-z0-9]{20,}/'        => '[redactado]',
		'/IG[A-Za-z0-9]{20,}/'         => '[redactado]',
		'/\b\d{8,}\|[A-Za-z0-9]{20,}/' => '[redactado]',
	);
```

- [ ] **Step 4: Crear `src/AppCredentials.php`**

```php
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
```

- [ ] **Step 5: Registrar la factory**

En `Plugin::register_factories()`, tras la de `Crypto`:

```php
		$this->factories[ AppCredentials::class ] = static function ( Plugin $c ): AppCredentials {
			return new AppCredentials( $c->get( Crypto::class ) );
		};
```

`Uninstaller::OPTIONS` ya incluye `'app'`, así que no hay que tocarlo; comprueba que sigue ahí.

- [ ] **Step 6: Tests y lint**

Run: `npm run test -- --filter 'AppCredentialsTest|LoggerTest' && npm run lint`
Expected: `OK`; PHPCS sin errores. Si el patrón del token de app marca falsos positivos en algún test existente del log, ajusta el patrón (no el test) y documéntalo.

- [ ] **Step 7: Commit**

```bash
git add src/Logger.php src/AppCredentials.php src/Plugin.php tests/phpunit/LoggerTest.php tests/phpunit/AppCredentialsTest.php
git commit -m "feat: credenciales de la app cifradas y redacción de tokens de app e Instagram"
```

---

### Task 2: `OAuth\Facebook`

**Files:**
- Create: `src/OAuth/Facebook.php`
- Modify: `src/Plugin.php` (factory + `REGISTRABLES`), `src/Uninstaller.php` (transients nuevos)
- Test: `tests/phpunit/OAuthFacebookTest.php`

**Interfaces:**
- Consumes: `AppCredentials` (`app_id()`, `app_secret()`, `is_configured()`, `business_management()`), `GraphClient` (`get( string $path, array $query = array(), string $token = '' ): array|\WP_Error`), `ChannelRepository` (`insert( array ): int|\WP_Error`, `find_by_remote( string, string ): ?Channel`, `update( int, array ): bool`), `Crypto`, `Settings::get( 'graph_version' )`, `Logger`, `Channels\FacebookPageAdapter::type()`.
- Produces:
  - `Facebook implements Registrable`; `new Facebook( AppCredentials $app, GraphClient $graph, ChannelRepository $channels, Crypto $crypto, Settings $settings, Logger $logger )`.
  - `Facebook::ACTION_START = 'voceador_oauth_fb_start'`, `ACTION_CALLBACK = 'voceador_oauth_fb'`, `ACTION_CONNECT = 'voceador_oauth_fb_connect'` (acciones de `admin-post.php`).
  - `Facebook::SCOPES = array( 'pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'pages_manage_engagement' )`.
  - `register_hooks(): void` — engancha los tres handlers a `admin_post_{acción}`.
  - `redirect_uri(): string` — `admin_url( 'admin-post.php' ) . '?action=voceador_oauth_fb'`. Es la URI que hay que registrar en la app de Meta.
  - `scopes(): array` — `SCOPES` más `business_management` si el ajuste está activo; filtro `voceador_oauth_scopes`.
  - `authorize_url( string $state ): string` — `https://www.facebook.com/{version}/dialog/oauth?...`.
  - `create_state(): string` — transient `voceador_oauth_state_{hash}` (10 min) con el id del usuario actual; devuelve el valor del `state`.
  - `consume_state( string $state ): bool` — válido una sola vez y solo para el usuario que lo creó.
  - `exchange_code( string $code ): string|\WP_Error` — token de usuario de corta duración.
  - `long_lived( string $user_token ): string|\WP_Error`.
  - `pages( string $user_token ): array|\WP_Error` — lista de `array{id,name,access_token,tasks,picture}` siguiendo `paging.next` hasta 5 páginas de resultados.
  - `store_candidates( array $pages ): void` / `candidates(): array` / `forget_candidates(): void` — transient `voceador_oauth_pages_{user_id}` (10 min) con la lista **cifrada**.
  - `connect( array $selection ): array` — `$selection` es `page_id => alias`; da de alta o actualiza los canales y devuelve `array{connected:int, updated:int, errors:string[]}`.
  - Handlers: `handle_start(): void`, `handle_callback(): void`, `handle_connect(): void`. Todos comprueban `Installer::CAPABILITY` y nonce, y terminan en `wp_safe_redirect` a la pantalla de conexiones con `voceador_msg` / `voceador_error`.

- [ ] **Step 1: Escribir el test**

`tests/phpunit/OAuthFacebookTest.php`:

```php
<?php
/**
 * @package Voceador
 */

use Voceador\AppCredentials;
use Voceador\ChannelRepository;
use Voceador\Crypto;
use Voceador\GraphClient;
use Voceador\Logger;
use Voceador\OAuth\Facebook;
use Voceador\Schema;
use Voceador\Settings;
use Voceador\Tests\Fixtures\GraphResponses;

class OAuthFacebookTest extends WP_UnitTestCase {

	private Facebook $oauth;
	private AppCredentials $app;
	private ChannelRepository $channels;
	private array $requests  = array();
	private array $responses = array();

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		delete_option( VOCEADOR_PREFIX . Settings::OPTION );
		delete_option( VOCEADOR_PREFIX . AppCredentials::OPTION );

		$crypto         = new Crypto( str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );
		$schema         = new Schema( $wpdb );
		$logger         = new Logger( $wpdb, $schema, 'debug' );
		$settings       = new Settings();
		$this->app      = new AppCredentials( $crypto );
		$this->channels = new ChannelRepository( $wpdb, $schema, $crypto );
		$this->oauth    = new Facebook( $this->app, new GraphClient( $settings, $logger ), $this->channels, $crypto, $settings, $logger );

		$this->app->save( '111222333', 'secreto-app' );
		$this->requests  = array();
		$this->responses = array();
		add_filter( 'pre_http_request', array( $this, 'capture' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'capture' ), 10 );
		parent::tear_down();
	}

	public function capture( $pre, array $args, string $url ) {
		$this->requests[] = array( 'url' => $url, 'args' => $args );

		return array_shift( $this->responses ) ?? GraphResponses::ok( array() );
	}

	public function test_redirect_uri_and_authorize_url(): void {
		$this->assertSame( admin_url( 'admin-post.php' ) . '?action=voceador_oauth_fb', $this->oauth->redirect_uri() );

		$url = $this->oauth->authorize_url( 'elestado' );

		$this->assertStringStartsWith( 'https://www.facebook.com/v26.0/dialog/oauth?', $url );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( '111222333', $query['client_id'] );
		$this->assertSame( $this->oauth->redirect_uri(), $query['redirect_uri'] );
		$this->assertSame( 'elestado', $query['state'] );
		$this->assertSame( 'code', $query['response_type'] );
		$this->assertSame( 'pages_show_list,pages_read_engagement,pages_manage_posts,pages_manage_engagement', $query['scope'] );
	}

	public function test_scopes_add_business_management_and_filter(): void {
		$this->app->set_business_management( true );
		$this->assertContains( 'business_management', $this->oauth->scopes() );

		add_filter( 'voceador_oauth_scopes', static fn( array $s ) => array_merge( $s, array( 'instagram_basic' ) ) );
		$this->assertContains( 'instagram_basic', $this->oauth->scopes() );
	}

	public function test_state_is_single_use_and_bound_to_the_user(): void {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );

		$state = $this->oauth->create_state();
		$this->assertNotEmpty( $state );

		$this->assertTrue( $this->oauth->consume_state( $state ) );
		$this->assertFalse( $this->oauth->consume_state( $state ), 'Solo se puede usar una vez.' );
		$this->assertFalse( $this->oauth->consume_state( 'inventado' ) );

		$state = $this->oauth->create_state();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertFalse( $this->oauth->consume_state( $state ), 'Otro usuario no puede consumirlo.' );
	}

	public function test_exchange_code_and_long_lived(): void {
		$this->responses[] = GraphResponses::ok( array( 'access_token' => 'CORTO', 'token_type' => 'bearer', 'expires_in' => 3600 ) );

		$this->assertSame( 'CORTO', $this->oauth->exchange_code( 'el-codigo' ) );
		parse_str( (string) wp_parse_url( $this->requests[0]['url'], PHP_URL_QUERY ), $query );
		$this->assertStringContainsString( '/v26.0/oauth/access_token', $this->requests[0]['url'] );
		$this->assertSame( '111222333', $query['client_id'] );
		$this->assertSame( 'secreto-app', $query['client_secret'] );
		$this->assertSame( 'el-codigo', $query['code'] );
		$this->assertSame( $this->oauth->redirect_uri(), $query['redirect_uri'] );
		$this->assertArrayNotHasKey( 'Authorization', $this->requests[0]['args']['headers'] );

		$this->responses[] = GraphResponses::ok( array( 'access_token' => 'LARGO', 'expires_in' => 5184000 ) );
		$this->assertSame( 'LARGO', $this->oauth->long_lived( 'CORTO' ) );
		parse_str( (string) wp_parse_url( $this->requests[1]['url'], PHP_URL_QUERY ), $query );
		$this->assertSame( 'fb_exchange_token', $query['grant_type'] );
		$this->assertSame( 'CORTO', $query['fb_exchange_token'] );
	}

	public function test_exchange_code_propagates_errors(): void {
		$this->responses[] = GraphResponses::error( 400, 100, 'This authorization code has expired.' );

		$result = $this->oauth->exchange_code( 'viejo' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'fatal', $result->get_error_code() );
	}

	public function test_pages_follows_paging(): void {
		$this->responses[] = GraphResponses::ok(
			array(
				'data'   => array( array( 'id' => '1', 'name' => 'Una', 'access_token' => 'T1', 'tasks' => array( 'CREATE_CONTENT' ) ) ),
				'paging' => array( 'next' => 'https://graph.facebook.com/v26.0/me/accounts?after=CURSOR' ),
			)
		);
		$this->responses[] = GraphResponses::ok(
			array( 'data' => array( array( 'id' => '2', 'name' => 'Dos', 'access_token' => 'T2', 'tasks' => array( 'MANAGE' ) ) ) )
		);

		$pages = $this->oauth->pages( 'LARGO' );

		$this->assertCount( 2, $pages );
		$this->assertSame( array( '1', '2' ), array_column( $pages, 'id' ) );
		$this->assertSame( 'T2', $pages[1]['access_token'] );
		$this->assertSame( 'Bearer LARGO', $this->requests[0]['args']['headers']['Authorization'] );
		$this->assertStringContainsString( 'after=CURSOR', $this->requests[1]['url'] );
	}

	public function test_candidates_are_stored_encrypted(): void {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );

		$this->oauth->store_candidates( array( array( 'id' => '1', 'name' => 'Una', 'access_token' => 'SECRETO-DE-PAGINA' ) ) );

		$raw = get_transient( VOCEADOR_PREFIX . 'oauth_pages_' . $user );
		$this->assertIsString( $raw );
		$this->assertStringNotContainsString( 'SECRETO-DE-PAGINA', $raw );
		$this->assertSame( 'SECRETO-DE-PAGINA', $this->oauth->candidates()[0]['access_token'] );

		$this->oauth->forget_candidates();
		$this->assertSame( array(), $this->oauth->candidates() );
	}

	public function test_connect_inserts_and_updates_channels(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->oauth->store_candidates(
			array(
				array( 'id' => '1001', 'name' => 'Una', 'access_token' => 'T1', 'tasks' => array( 'CREATE_CONTENT' ), 'picture' => 'https://x/a.jpg' ),
				array( 'id' => '1002', 'name' => 'Dos', 'access_token' => 'T2', 'tasks' => array( 'CREATE_CONTENT' ) ),
			)
		);

		$result = $this->oauth->connect( array( '1001' => 'Principal' ) );

		$this->assertSame( 1, $result['connected'] );
		$this->assertSame( 0, $result['updated'] );
		$channel = $this->channels->find_by_remote( 'facebook_page', '1001' );
		$this->assertSame( 'Principal', $channel->alias );
		$this->assertSame( 'Una', $channel->remote_name );
		$this->assertSame( 'oauth', $channel->connection_method );
		$this->assertSame( 'T1', $channel->credential( 'access_token' ) );
		$this->assertSame( 'https://x/a.jpg', $channel->avatar_url );
		$this->assertContains( 'pages_manage_posts', $channel->scopes );
		$this->assertSame( 'active', $channel->status );

		$this->oauth->store_candidates( array( array( 'id' => '1001', 'name' => 'Una renombrada', 'access_token' => 'T1-NUEVO' ) ) );
		$result = $this->oauth->connect( array( '1001' => 'Principal' ) );

		$this->assertSame( 0, $result['connected'] );
		$this->assertSame( 1, $result['updated'] );
		$channel = $this->channels->find_by_remote( 'facebook_page', '1001' );
		$this->assertSame( 'T1-NUEVO', $channel->credential( 'access_token' ) );
		$this->assertSame( 'Una renombrada', $channel->remote_name );
		$this->assertSame( 'active', $channel->status, 'Reconectar reactiva el canal.' );
	}

	public function test_connect_ignores_pages_outside_the_candidates(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->oauth->store_candidates( array( array( 'id' => '1001', 'name' => 'Una', 'access_token' => 'T1' ) ) );

		$result = $this->oauth->connect( array( '9999' => 'Inventada' ) );

		$this->assertSame( 0, $result['connected'] );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertNull( $this->channels->find_by_remote( 'facebook_page', '9999' ) );
	}

	public function test_alias_falls_back_to_the_page_name(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->oauth->store_candidates( array( array( 'id' => '1001', 'name' => 'Una', 'access_token' => 'T1' ) ) );

		$this->oauth->connect( array( '1001' => '   ' ) );

		$this->assertSame( 'Una', $this->channels->find_by_remote( 'facebook_page', '1001' )->alias );
	}

	public function test_hooks_are_registered(): void {
		$this->oauth->register_hooks();

		$this->assertNotFalse( has_action( 'admin_post_' . Facebook::ACTION_START, array( $this->oauth, 'handle_start' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_' . Facebook::ACTION_CALLBACK, array( $this->oauth, 'handle_callback' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_' . Facebook::ACTION_CONNECT, array( $this->oauth, 'handle_connect' ) ) );
	}
}
```

- [ ] **Step 2: Ejecutar y verificar que falla**

Run: `npm run test -- --filter OAuthFacebookTest`
Expected: FAIL con `Class "Voceador\OAuth\Facebook" not found`.

- [ ] **Step 3: Crear `src/OAuth/Facebook.php`**

```php
<?php
/**
 * Conexión con Facebook por OAuth.
 *
 * @package Voceador
 */

namespace Voceador\OAuth;

use Voceador\AppCredentials;
use Voceador\ChannelRepository;
use Voceador\Channels\FacebookPageAdapter;
use Voceador\Crypto;
use Voceador\GraphClient;
use Voceador\Installer;
use Voceador\Logger;
use Voceador\Registrable;
use Voceador\Settings;

/**
 * Lleva al administrador por el diálogo de Facebook y da de alta las Páginas elegidas.
 *
 * El token de usuario nunca se guarda: solo se conservan los Page tokens, que
 * derivados de un token de usuario de larga duración no expiran.
 */
final class Facebook implements Registrable {

	/**
	 * Acción de admin-post que abre el diálogo.
	 */
	public const ACTION_START = 'voceador_oauth_fb_start';

	/**
	 * Acción de admin-post a la que vuelve Facebook.
	 */
	public const ACTION_CALLBACK = 'voceador_oauth_fb';

	/**
	 * Acción de admin-post que da de alta las Páginas elegidas.
	 */
	public const ACTION_CONNECT = 'voceador_oauth_fb_connect';

	/**
	 * Permisos que se piden siempre.
	 */
	public const SCOPES = array( 'pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'pages_manage_engagement' );

	/**
	 * Vida de los transients de state y de candidatas, en segundos.
	 */
	private const TTL = 600;

	/**
	 * Páginas de resultados que se siguen como mucho al listar Páginas.
	 */
	private const MAX_PAGES = 5;

	/**
	 * Constructor.
	 *
	 * @param AppCredentials    $app      Credenciales de la app.
	 * @param GraphClient       $graph    Cliente de Graph.
	 * @param ChannelRepository $channels Canales.
	 * @param Crypto            $crypto   Cifrado.
	 * @param Settings          $settings Ajustes.
	 * @param Logger            $logger   Log.
	 */
	public function __construct(
		private AppCredentials $app,
		private GraphClient $graph,
		private ChannelRepository $channels,
		private Crypto $crypto,
		private Settings $settings,
		private Logger $logger
	) {}

	/**
	 * Engancha los tres handlers de admin-post.
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION_START, array( $this, 'handle_start' ) );
		add_action( 'admin_post_' . self::ACTION_CALLBACK, array( $this, 'handle_callback' ) );
		add_action( 'admin_post_' . self::ACTION_CONNECT, array( $this, 'handle_connect' ) );
	}

	/**
	 * URI de redirección que hay que registrar en la app de Meta.
	 *
	 * @return string
	 */
	public function redirect_uri(): string {
		return admin_url( 'admin-post.php' ) . '?action=' . self::ACTION_CALLBACK;
	}

	/**
	 * Permisos que se piden en el diálogo.
	 *
	 * @return string[]
	 */
	public function scopes(): array {
		$scopes = self::SCOPES;

		if ( $this->app->business_management() ) {
			$scopes[] = 'business_management';
		}

		/**
		 * Permite ajustar los permisos que se piden a Facebook.
		 *
		 * @param string[] $scopes Permisos.
		 */
		return array_values( array_unique( (array) apply_filters( 'voceador_oauth_scopes', $scopes ) ) );
	}

	/**
	 * URL del diálogo de autorización.
	 *
	 * @param string $state Valor del parámetro state.
	 * @return string
	 */
	public function authorize_url( string $state ): string {
		$version = (string) $this->settings->get( 'graph_version' );

		return add_query_arg(
			array(
				'client_id'     => rawurlencode( $this->app->app_id() ),
				'redirect_uri'  => rawurlencode( $this->redirect_uri() ),
				'state'         => rawurlencode( $state ),
				'response_type' => 'code',
				'scope'         => rawurlencode( implode( ',', $this->scopes() ) ),
			),
			'https://www.facebook.com/' . $version . '/dialog/oauth'
		);
	}

	/**
	 * Crea un state de un solo uso ligado al usuario actual.
	 *
	 * @return string
	 */
	public function create_state(): string {
		$state = wp_generate_password( 32, false );

		set_transient( $this->state_key( $state ), get_current_user_id(), self::TTL );

		return $state;
	}

	/**
	 * Comprueba y consume un state.
	 *
	 * @param string $state Valor recibido de Facebook.
	 * @return bool
	 */
	public function consume_state( string $state ): bool {
		if ( '' === $state ) {
			return false;
		}

		$key   = $this->state_key( $state );
		$owner = get_transient( $key );

		if ( false === $owner || (int) $owner !== get_current_user_id() ) {
			return false;
		}

		delete_transient( $key );

		return true;
	}

	/**
	 * Intercambia el código por un token de usuario de corta duración.
	 *
	 * @param string $code Código devuelto por Facebook.
	 * @return string|\WP_Error
	 */
	public function exchange_code( string $code ): string|\WP_Error {
		$response = $this->graph->get(
			'oauth/access_token',
			array(
				'client_id'     => $this->app->app_id(),
				'client_secret' => $this->app->app_secret(),
				'redirect_uri'  => $this->redirect_uri(),
				'code'          => $code,
			)
		);

		return is_wp_error( $response ) ? $response : $this->token_from( $response );
	}

	/**
	 * Convierte un token de usuario en uno de larga duración.
	 *
	 * @param string $user_token Token de corta duración.
	 * @return string|\WP_Error
	 */
	public function long_lived( string $user_token ): string|\WP_Error {
		$response = $this->graph->get(
			'oauth/access_token',
			array(
				'grant_type'        => 'fb_exchange_token',
				'client_id'         => $this->app->app_id(),
				'client_secret'     => $this->app->app_secret(),
				'fb_exchange_token' => $user_token,
			)
		);

		return is_wp_error( $response ) ? $response : $this->token_from( $response );
	}

	/**
	 * Páginas que administra el usuario del token.
	 *
	 * @param string $user_token Token de usuario de larga duración.
	 * @return array|\WP_Error Lista de array{id,name,access_token,tasks,picture}.
	 */
	public function pages( string $user_token ): array|\WP_Error {
		$found = array();
		$query = array(
			'fields' => 'id,name,access_token,tasks,picture{url}',
			'limit'  => 100,
		);
		$path  = 'me/accounts';

		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$response = $this->graph->get( $path, $query, $user_token );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			foreach ( (array) ( $response['data'] ?? array() ) as $row ) {
				$found[] = array(
					'id'           => (string) ( $row['id'] ?? '' ),
					'name'         => (string) ( $row['name'] ?? '' ),
					'access_token' => (string) ( $row['access_token'] ?? '' ),
					'tasks'        => array_values( (array) ( $row['tasks'] ?? array() ) ),
					'picture'      => (string) ( $row['picture']['data']['url'] ?? '' ),
				);
			}

			$next = (string) ( $response['paging']['next'] ?? '' );

			if ( '' === $next ) {
				break;
			}

			// GraphClient antepone host y versión a la ruta, así que de la URL de
			// paginación solo se aprovecha el cursor.
			parse_str( (string) wp_parse_url( $next, PHP_URL_QUERY ), $next_query );
			$after = (string) ( $next_query['after'] ?? '' );

			if ( '' === $after ) {
				break;
			}

			$query['after'] = $after;
		}

		return $found;
	}

	/**
	 * Guarda cifradas las Páginas candidatas del usuario actual.
	 *
	 * @param array $pages Lista de Páginas.
	 */
	public function store_candidates( array $pages ): void {
		set_transient( $this->candidates_key(), $this->crypto->encrypt( (string) wp_json_encode( $pages ) ), self::TTL );
	}

	/**
	 * Páginas candidatas del usuario actual.
	 *
	 * @return array
	 */
	public function candidates(): array {
		$stored = get_transient( $this->candidates_key() );

		if ( ! is_string( $stored ) || '' === $stored ) {
			return array();
		}

		$plain = $this->crypto->decrypt( $stored );

		if ( is_wp_error( $plain ) ) {
			return array();
		}

		$decoded = json_decode( $plain, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Olvida las Páginas candidatas del usuario actual.
	 */
	public function forget_candidates(): void {
		delete_transient( $this->candidates_key() );
	}

	/**
	 * Da de alta o actualiza los canales de las Páginas elegidas.
	 *
	 * @param array<string, string> $selection Id de Página => alias.
	 * @return array{connected:int, updated:int, errors:string[]}
	 */
	public function connect( array $selection ): array {
		$candidates = array();
		foreach ( $this->candidates() as $page ) {
			$candidates[ (string) $page['id'] ] = $page;
		}

		$result = array(
			'connected' => 0,
			'updated'   => 0,
			'errors'    => array(),
		);

		foreach ( $selection as $page_id => $alias ) {
			$page_id = (string) $page_id;

			if ( ! isset( $candidates[ $page_id ] ) ) {
				$result['errors'][] = sprintf( /* translators: %s: id de la Página */ __( 'La Página %s ya no está en la lista de la conexión; vuelve a conectar.', 'voceador' ), $page_id );
				continue;
			}

			$page  = $candidates[ $page_id ];
			$alias = trim( (string) $alias );
			$alias = '' !== $alias ? $alias : (string) $page['name'];

			$data = array(
				'alias'             => $alias,
				'remote_name'       => (string) $page['name'],
				'avatar_url'        => (string) ( $page['picture'] ?? '' ),
				'connection_method' => 'oauth',
				'credentials'       => array( 'access_token' => (string) $page['access_token'] ),
				'scopes'            => $this->scopes(),
				'status'            => 'active',
				'health'            => array( 'message' => (string) $page['name'] ),
				'health_checked_at' => current_time( 'mysql', true ),
			);

			$existing = $this->channels->find_by_remote( FacebookPageAdapter::type(), $page_id );

			if ( null !== $existing ) {
				$this->channels->update( $existing->id, $data );
				++$result['updated'];
				$this->logger->info( 'channel_reconnected', 'Canal reconectado', array( 'channel_id' => $existing->id ) );
				continue;
			}

			$inserted = $this->channels->insert(
				array_merge(
					$data,
					array(
						'type'      => FacebookPageAdapter::type(),
						'remote_id' => $page_id,
					)
				)
			);

			if ( is_wp_error( $inserted ) ) {
				$result['errors'][] = $inserted->get_error_message();
				continue;
			}

			++$result['connected'];
			$this->logger->info( 'channel_connected', 'Canal conectado', array( 'channel_id' => $inserted ) );
		}

		return $result;
	}

	/**
	 * Abre el diálogo de autorización.
	 */
	public function handle_start(): void {
		$this->authorize();
		check_admin_referer( self::ACTION_START );

		if ( ! $this->app->is_configured() ) {
			$this->back( '', __( 'Configura primero el App ID y el App Secret.', 'voceador' ) );
		}

		$url = $this->authorize_url( $this->create_state() );

		// wp_safe_redirect() solo permite el propio host: aquí el destino es Facebook.
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Destino externo conocido: el diálogo de Facebook.
		exit;
	}

	/**
	 * Recibe la vuelta de Facebook y guarda las Páginas candidatas.
	 */
	public function handle_callback(): void {
		$this->authorize();

		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';

		if ( ! $this->consume_state( $state ) ) {
			$this->back( '', __( 'La conexión caducó o no se pudo verificar. Inténtalo otra vez.', 'voceador' ) );
		}

		if ( isset( $_GET['error'] ) ) {
			$description = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : '';
			$this->back( '', '' !== $description ? $description : __( 'Facebook no autorizó la conexión.', 'voceador' ) );
		}

		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';

		if ( '' === $code ) {
			$this->back( '', __( 'Facebook no devolvió el código de autorización.', 'voceador' ) );
		}

		$short = $this->exchange_code( $code );
		if ( is_wp_error( $short ) ) {
			$this->fail( $short );
		}

		$long = $this->long_lived( $short );
		if ( is_wp_error( $long ) ) {
			$this->fail( $long );
		}

		$pages = $this->pages( $long );
		if ( is_wp_error( $pages ) ) {
			$this->fail( $pages );
		}

		if ( ! $pages ) {
			$this->back( '', __( 'Tu cuenta no administra ninguna Página. Si las Páginas están en un Business Manager, activa esa casilla y vuelve a conectar.', 'voceador' ) );
		}

		$this->store_candidates( $pages );
		$this->back( __( 'Elige las Páginas que quieres conectar.', 'voceador' ), '', array( 'voceador_step' => 'pages' ) );
	}

	/**
	 * Da de alta las Páginas marcadas en el formulario.
	 */
	public function handle_connect(): void {
		$this->authorize();
		check_admin_referer( self::ACTION_CONNECT );

		$selection = array();
		$raw       = isset( $_POST['pages'] ) ? wp_unslash( $_POST['pages'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cada elemento se sanea justo debajo.

		foreach ( (array) $raw as $page_id => $alias ) {
			$selection[ sanitize_text_field( (string) $page_id ) ] = sanitize_text_field( (string) $alias );
		}

		if ( ! $selection ) {
			$this->back( '', __( 'No marcaste ninguna Página.', 'voceador' ) );
		}

		$result = $this->connect( $selection );
		$this->forget_candidates();

		if ( $result['errors'] ) {
			$this->back( '', implode( ' ', $result['errors'] ) );
		}

		$this->back(
			sprintf(
				/* translators: 1: Páginas conectadas, 2: Páginas actualizadas */
				__( 'Listo: %1$d Página(s) conectada(s) y %2$d actualizada(s).', 'voceador' ),
				$result['connected'],
				$result['updated']
			)
		);
	}

	/**
	 * Corta la ejecución si el usuario no puede gestionar el plugin.
	 */
	private function authorize(): void {
		if ( ! current_user_can( Installer::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permiso para gestionar las conexiones de Voceador.', 'voceador' ), 403 );
		}
	}

	/**
	 * Vuelve a la pantalla de conexiones con un mensaje.
	 *
	 * @param string $message Mensaje de éxito.
	 * @param string $error   Mensaje de error.
	 * @param array  $extra   Parámetros adicionales de la URL.
	 */
	private function back( string $message = '', string $error = '', array $extra = array() ): void {
		$args = $extra;

		if ( '' !== $message ) {
			$args['voceador_msg'] = rawurlencode( $message );
		}
		if ( '' !== $error ) {
			$args['voceador_error'] = rawurlencode( $error );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=voceador' ) ) );
		exit;
	}

	/**
	 * Registra un error de Graph y vuelve a la pantalla.
	 *
	 * @param \WP_Error $error Error normalizado.
	 */
	private function fail( \WP_Error $error ): void {
		$message = Logger::redact_string( $error->get_error_message() );
		$data    = (array) $error->get_error_data();

		$this->logger->error(
			'oauth_failed',
			$message,
			array(
				'class'      => $error->get_error_code(),
				'graph_code' => $data['graph_code'] ?? 0,
			)
		);

		$this->back( '', $message );
	}

	/**
	 * Extrae el token de una respuesta de oauth/access_token.
	 *
	 * @param array $response Respuesta.
	 * @return string|\WP_Error
	 */
	private function token_from( array $response ): string|\WP_Error {
		$token = (string) ( $response['access_token'] ?? '' );

		if ( '' === $token ) {
			return new \WP_Error( 'fatal', __( 'Facebook no devolvió ningún token de acceso.', 'voceador' ), array( 'class' => 'fatal' ) );
		}

		return $token;
	}

	/**
	 * Clave del transient de state.
	 *
	 * @param string $state Valor del state.
	 * @return string
	 */
	private function state_key( string $state ): string {
		return VOCEADOR_PREFIX . 'oauth_state_' . md5( $state );
	}

	/**
	 * Clave del transient de Páginas candidatas.
	 *
	 * @return string
	 */
	private function candidates_key(): string {
		return VOCEADOR_PREFIX . 'oauth_pages_' . get_current_user_id();
	}
}
```

- [ ] **Step 4: Contenedor y desinstalación**

En `Plugin`: añade `OAuth\Facebook::class` a `REGISTRABLES` y la factory:

```php
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
```

`Uninstaller::clean_site()` ya borra todos los transients con el prefijo `voceador_`, así que `oauth_state_*` y `oauth_pages_*` quedan cubiertos; comprueba que la consulta sigue siendo por `LIKE`.

- [ ] **Step 5: Tests y lint**

Run: `npm run test -- --filter 'OAuthFacebookTest|PluginTest' && npm run lint`
Expected: `OK (11 tests, ...)` en `OAuthFacebookTest`; PHPCS sin errores. Si PHPCS exige sanear `$_GET` con `wp_unslash` antes de `sanitize_text_field` (ya está) o marca `WordPress.Security.NonceVerification.Recommended` en `handle_callback`, añade un `phpcs:ignore` explicando que el `state` es el nonce del flujo OAuth.

- [ ] **Step 6: Commit**

```bash
git add src/OAuth/Facebook.php src/Plugin.php tests/phpunit/OAuthFacebookTest.php
git commit -m "feat: conexión con Facebook por OAuth y alta de Páginas"
```

---

### Task 3: `Notices` y `TokenManager`

**Files:**
- Create: `src/Notices.php`, `src/TokenManager.php`
- Modify: `src/Publisher.php` (`add_notice` delega), `src/OAuth/Facebook.php` (al reconectar se retira el aviso), `src/Plugin.php` (factory + `REGISTRABLES`)
- Test: `tests/phpunit/NoticesTest.php`, `tests/phpunit/TokenManagerTest.php`

**Interfaces:**
- Consumes: `AppCredentials` (`app_token()`, `app_id()`, `is_configured()`), `GraphClient::get()`, `ChannelRepository` (`all()`, `find()`, `set_status()`), `Channels\{Channel,HealthReport}`, `Logger`, `Installer::CAPABILITY`.
- Produces:
  - `Notices::OPTION = 'notices'`; estáticos `add( string $key, string $message ): void`, `remove( string $key ): void`, `all(): array` (clave => `array{message,time}`, ordenados del más reciente al más antiguo), `clear(): void`, `key_for_channel( int $channel_id ): string` (devuelve `channel_paused_{id}`).
  - `Publisher::add_notice()` conserva su firma y delega en `Notices::add()`.
  - `TokenManager implements Registrable`; `new TokenManager( AppCredentials $app, GraphClient $graph, ChannelRepository $channels, Logger $logger )`.
  - `TokenManager::HOOK = 'voceador_check_tokens'`; `REQUIRED_SCOPES = array( 'pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'pages_manage_engagement' )`.
  - `register_hooks(): void` — engancha `check_all` a `HOOK` y programa el evento diario en `init` si falta.
  - `check( Channel $channel ): HealthReport` — consulta `GET /debug_token`.
  - `check_all(): array{checked:int, paused:int}` — revisa todos los canales que no estén `disabled`; pausa y avisa los inválidos; **nunca reactiva**.

- [ ] **Step 1: Escribir los tests**

`tests/phpunit/NoticesTest.php`:

```php
<?php
/**
 * @package Voceador
 */

use Voceador\Notices;
use Voceador\Publisher;

class NoticesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( VOCEADOR_PREFIX . Notices::OPTION );
	}

	public function test_add_remove_and_all(): void {
		$this->assertSame( array(), Notices::all() );

		Notices::add( 'uno', 'Primero' );
		Notices::add( 'dos', 'Segundo' );

		$all = Notices::all();
		$this->assertSame( array( 'dos', 'uno' ), array_keys( $all ), 'El más reciente primero.' );
		$this->assertSame( 'Primero', $all['uno']['message'] );
		$this->assertIsInt( $all['uno']['time'] );

		Notices::remove( 'uno' );
		$this->assertSame( array( 'dos' ), array_keys( Notices::all() ) );

		Notices::clear();
		$this->assertSame( array(), Notices::all() );
	}

	public function test_option_is_not_autoloaded(): void {
		global $wpdb;
		Notices::add( 'uno', 'Primero' );

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", VOCEADOR_PREFIX . Notices::OPTION ) );

		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	public function test_key_for_channel(): void {
		$this->assertSame( 'channel_paused_7', Notices::key_for_channel( 7 ) );
	}

	public function test_publisher_add_notice_delegates(): void {
		Publisher::add_notice( 'desde-publisher', 'Mensaje' );

		$this->assertSame( 'Mensaje', Notices::all()['desde-publisher']['message'] );
	}
}
```

`tests/phpunit/TokenManagerTest.php`:

```php
<?php
/**
 * @package Voceador
 */

use Voceador\AppCredentials;
use Voceador\ChannelRepository;
use Voceador\Channels\Channel;
use Voceador\Crypto;
use Voceador\GraphClient;
use Voceador\Logger;
use Voceador\Notices;
use Voceador\Schema;
use Voceador\Settings;
use Voceador\TokenManager;
use Voceador\Tests\Fixtures\GraphResponses;

class TokenManagerTest extends WP_UnitTestCase {

	private TokenManager $tokens;
	private ChannelRepository $channels;
	private array $requests  = array();
	private array $responses = array();

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		delete_option( VOCEADOR_PREFIX . Settings::OPTION );
		delete_option( VOCEADOR_PREFIX . AppCredentials::OPTION );
		delete_option( VOCEADOR_PREFIX . Notices::OPTION );
		_set_cron_array( array() );

		$crypto         = new Crypto( str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );
		$schema         = new Schema( $wpdb );
		$logger         = new Logger( $wpdb, $schema, 'debug' );
		$app            = new AppCredentials( $crypto );
		$app->save( '111222333', 'secreto-app' );
		$this->channels = new ChannelRepository( $wpdb, $schema, $crypto );
		$this->tokens   = new TokenManager( $app, new GraphClient( new Settings(), $logger ), $this->channels, $logger );

		$this->requests  = array();
		$this->responses = array();
		add_filter( 'pre_http_request', array( $this, 'capture' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'capture' ), 10 );
		parent::tear_down();
	}

	public function capture( $pre, array $args, string $url ) {
		$this->requests[] = array( 'url' => $url, 'args' => $args );

		return array_shift( $this->responses ) ?? GraphResponses::ok( array() );
	}

	private function debug( array $overrides = array() ): array {
		return GraphResponses::ok(
			array(
				'data' => array_merge(
					array(
						'app_id'     => '111222333',
						'type'       => 'PAGE',
						'is_valid'   => true,
						'profile_id' => '1001',
						'expires_at' => 0,
						'scopes'     => TokenManager::REQUIRED_SCOPES,
					),
					$overrides
				),
			)
		);
	}

	private function channel( array $overrides = array() ): Channel {
		$id = $this->channels->insert(
			array_merge(
				array(
					'type'        => 'facebook_page',
					'alias'       => 'Prueba',
					'remote_id'   => '1001',
					'remote_name' => 'Página',
					'credentials' => array( 'access_token' => 'EAAtoken' ),
				),
				$overrides
			)
		);

		return $this->channels->find( $id );
	}

	public function test_check_valid_token(): void {
		$this->responses[] = $this->debug();

		$report = $this->tokens->check( $this->channel() );

		$this->assertTrue( $report->valid );
		$this->assertSame( TokenManager::REQUIRED_SCOPES, $report->scopes );
		$this->assertSame( array(), $report->missing_scopes );
		$this->assertNull( $report->expires_at, 'expires_at 0 significa que no caduca.' );
		$this->assertStringContainsString( '/debug_token', $this->requests[0]['url'] );
		$this->assertStringContainsString( 'input_token=EAAtoken', $this->requests[0]['url'] );
		$this->assertSame( 'Bearer 111222333|secreto-app', $this->requests[0]['args']['headers']['Authorization'] );
	}

	public function test_check_reports_expiry_and_missing_scopes(): void {
		$this->responses[] = $this->debug(
			array(
				'expires_at' => 1790000000,
				'scopes'     => array( 'pages_show_list', 'pages_manage_posts' ),
			)
		);

		$report = $this->tokens->check( $this->channel() );

		$this->assertTrue( $report->valid, 'Faltar permisos no invalida el token.' );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', 1790000000 ), $report->expires_at );
		$this->assertSame( array( 'pages_read_engagement', 'pages_manage_engagement' ), array_values( $report->missing_scopes ) );
	}

	public function test_check_detects_invalid_token(): void {
		$this->responses[] = $this->debug( array( 'is_valid' => false ) );
		$this->assertFalse( $this->tokens->check( $this->channel() )->valid );

		$this->responses[] = $this->debug( array( 'error' => array( 'code' => 190, 'message' => 'Error validating access token: the user has changed the password.', 'subcode' => 460 ) ) );
		$report = $this->tokens->check( $this->channel( array( 'remote_id' => '1002' ) ) );
		$this->assertFalse( $report->valid );
		$this->assertStringContainsString( 'changed the password', $report->message );
	}

	public function test_check_detects_another_page_or_another_app(): void {
		$this->responses[] = $this->debug( array( 'profile_id' => '9999' ) );
		$report = $this->tokens->check( $this->channel() );
		$this->assertFalse( $report->valid );
		$this->assertStringContainsString( 'otra Página', $report->message );

		$this->responses[] = $this->debug( array( 'app_id' => '444555666' ) );
		$report = $this->tokens->check( $this->channel( array( 'remote_id' => '1003' ) ) );
		$this->assertFalse( $report->valid );
		$this->assertStringContainsString( 'otra app', $report->message );
	}

	public function test_check_without_token_or_app(): void {
		$channel = $this->channel( array( 'remote_id' => '1004', 'credentials' => array() ) );
		$report  = $this->tokens->check( $channel );

		$this->assertFalse( $report->valid );
		$this->assertCount( 0, $this->requests, 'Sin token no se llama a Graph.' );
	}

	public function test_check_propagates_graph_errors_redacted(): void {
		$this->responses[] = GraphResponses::error( 400, 190, 'Invalid OAuth access token EAA' . str_repeat( 'Ab1', 10 ) );

		$report = $this->tokens->check( $this->channel() );

		$this->assertFalse( $report->valid );
		$this->assertStringNotContainsString( 'EAAAb1', $report->message );
		$this->assertStringContainsString( '[redactado]', $report->message );
	}

	public function test_check_all_pauses_invalid_channels_and_records_a_notice(): void {
		$good = $this->channel();
		$bad  = $this->channel( array( 'remote_id' => '2002', 'alias' => 'Rota' ) );

		$this->responses[] = $this->debug();
		$this->responses[] = $this->debug( array( 'profile_id' => '2002', 'is_valid' => false ) );

		$result = $this->tokens->check_all();

		$this->assertSame( array( 'checked' => 2, 'paused' => 1 ), $result );
		$this->assertSame( 'active', $this->channels->find( $good->id )->status );
		$this->assertNotNull( $this->channels->find( $good->id )->health_checked_at );
		$this->assertSame( 'paused', $this->channels->find( $bad->id )->status );
		$this->assertArrayHasKey( Notices::key_for_channel( $bad->id ), Notices::all() );
		$this->assertStringContainsString( 'Rota', Notices::all()[ Notices::key_for_channel( $bad->id ) ]['message'] );
	}

	public function test_check_all_never_reactivates_and_skips_disabled(): void {
		$paused   = $this->channel( array( 'status' => 'paused' ) );
		$disabled = $this->channel( array( 'remote_id' => '3003', 'status' => 'disabled' ) );

		$this->responses[] = $this->debug();

		$result = $this->tokens->check_all();

		$this->assertSame( 1, $result['checked'], 'El canal deshabilitado no se revisa.' );
		$this->assertSame( 'paused', $this->channels->find( $paused->id )->status, 'Un token válido no reactiva por sí solo.' );
		$this->assertSame( 'disabled', $this->channels->find( $disabled->id )->status );
	}

	public function test_register_hooks_schedules_the_daily_check(): void {
		$this->tokens->register_hooks();
		do_action( 'init' );

		$this->assertNotFalse( wp_next_scheduled( TokenManager::HOOK ) );
		$this->assertSame( 10, has_action( TokenManager::HOOK, array( $this->tokens, 'check_all' ) ) );
	}
}
```

- [ ] **Step 2: Ejecutar y verificar que fallan**

Run: `npm run test -- --filter 'NoticesTest|TokenManagerTest'`
Expected: FAIL con `Class "Voceador\Notices" not found`.

- [ ] **Step 3: Crear `src/Notices.php`**

```php
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
		$notices = self::stored();

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
```

- [ ] **Step 4: Hacer que `Publisher` delegue**

En `src/Publisher.php`, sustituye el cuerpo de `add_notice()` por una delegación, conservando la firma y el docblock:

```php
	public static function add_notice( string $key, string $message ): void {
		Notices::add( $key, $message );
	}
```

Y en `handle_error()`, donde hoy construye la clave del aviso a mano, usa `Notices::key_for_channel( $channel->id )` para que `TokenManager` y el reconectado compartan exactamente la misma clave.

- [ ] **Step 5: Retirar el aviso al reconectar**

En `src/OAuth/Facebook.php::connect()`, dentro de la rama que actualiza un canal existente, justo después de `$this->channels->update( $existing->id, $data );`, añade:

```php
				Notices::remove( Notices::key_for_channel( $existing->id ) );
```

y el `use Voceador\Notices;` correspondiente.

- [ ] **Step 6: Crear `src/TokenManager.php`**

```php
<?php
/**
 * Salud de los tokens de los canales.
 *
 * @package Voceador
 */

namespace Voceador;

use Voceador\Channels\Channel;
use Voceador\Channels\HealthReport;

/**
 * Consulta debug_token y pausa los canales cuyo token ha dejado de servir.
 *
 * Nunca reactiva un canal: eso solo ocurre al reconectarlo, porque un token
 * válido no garantiza que el administrador siga queriendo publicar ahí.
 */
final class TokenManager implements Registrable {

	/**
	 * Hook del cron diario.
	 */
	public const HOOK = 'voceador_check_tokens';

	/**
	 * Permisos que necesita un canal de Página para funcionar.
	 */
	public const REQUIRED_SCOPES = array( 'pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'pages_manage_engagement' );

	/**
	 * Estados que no se revisan.
	 */
	private const SKIPPED_STATUSES = array( 'disabled' );

	/**
	 * Constructor.
	 *
	 * @param AppCredentials    $app      Credenciales de la app.
	 * @param GraphClient       $graph    Cliente de Graph.
	 * @param ChannelRepository $channels Canales.
	 * @param Logger            $logger   Log.
	 */
	public function __construct(
		private AppCredentials $app,
		private GraphClient $graph,
		private ChannelRepository $channels,
		private Logger $logger
	) {}

	/**
	 * Engancha la revisión diaria.
	 */
	public function register_hooks(): void {
		add_action( self::HOOK, array( $this, 'check_all' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	/**
	 * Programa el cron diario si no existe.
	 */
	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Comprueba el token de un canal contra debug_token.
	 *
	 * @param Channel $channel Canal.
	 * @return HealthReport
	 */
	public function check( Channel $channel ): HealthReport {
		$token = (string) $channel->credential( 'access_token' );

		if ( '' === $token ) {
			return new HealthReport( false, __( 'El canal no tiene un token utilizable. Vuelve a conectarlo.', 'voceador' ) );
		}

		if ( ! $this->app->is_configured() ) {
			return new HealthReport( false, __( 'Configura el App ID y el App Secret para poder revisar los tokens.', 'voceador' ) );
		}

		$response = $this->graph->get( 'debug_token', array( 'input_token' => $token ), $this->app->app_token() );

		if ( is_wp_error( $response ) ) {
			return new HealthReport( false, Logger::redact_string( $response->get_error_message() ), array(), array(), null, (array) $response->get_error_data() );
		}

		$data = (array) ( $response['data'] ?? array() );

		return $this->report_from( $channel, $data );
	}

	/**
	 * Revisa todos los canales y pausa los que ya no sirven.
	 *
	 * @return array{checked:int, paused:int}
	 */
	public function check_all(): array {
		$result = array(
			'checked' => 0,
			'paused'  => 0,
		);

		foreach ( $this->channels->all() as $channel ) {
			if ( in_array( $channel->status, self::SKIPPED_STATUSES, true ) ) {
				continue;
			}

			++$result['checked'];
			$report = $this->check( $channel );
			$health = $this->health_from( $report );

			if ( $report->valid ) {
				// Se actualiza la salud sin tocar el estado: reactivar es cosa del reconectado.
				$this->channels->set_status( $channel->id, $channel->status, $health );
				continue;
			}

			$this->channels->set_status( $channel->id, 'paused', $health );
			Notices::add(
				Notices::key_for_channel( $channel->id ),
				sprintf(
					/* translators: 1: alias del canal, 2: motivo */
					__( 'Voceador pausó el canal "%1$s": %2$s Vuelve a conectarlo desde Voceador → Conexiones.', 'voceador' ),
					$channel->alias,
					$report->message
				)
			);
			$this->logger->error( 'token_invalid', $report->message, array( 'channel_id' => $channel->id ) );
			++$result['paused'];
		}

		if ( $result['checked'] > 0 ) {
			$this->logger->info( 'tokens_checked', 'Revisión de tokens', $result );
		}

		return $result;
	}

	/**
	 * Traduce la respuesta de debug_token a un informe de salud.
	 *
	 * @param Channel $channel Canal.
	 * @param array   $data    Objeto data de la respuesta.
	 * @return HealthReport
	 */
	private function report_from( Channel $channel, array $data ): HealthReport {
		$scopes     = array_values( (array) ( $data['scopes'] ?? array() ) );
		$missing    = array_values( array_diff( self::REQUIRED_SCOPES, $scopes ) );
		$expires_at = (int) ( $data['expires_at'] ?? 0 );
		$expires    = $expires_at > 0 ? gmdate( 'Y-m-d H:i:s', $expires_at ) : null;

		if ( isset( $data['error'] ) && is_array( $data['error'] ) ) {
			$message = Logger::redact_string( (string) ( $data['error']['message'] ?? __( 'Token rechazado por Facebook.', 'voceador' ) ) );

			return new HealthReport( false, $message, $scopes, $missing, $expires, $data );
		}

		if ( empty( $data['is_valid'] ) ) {
			return new HealthReport( false, __( 'Facebook marca el token como no válido.', 'voceador' ), $scopes, $missing, $expires, $data );
		}

		$app_id = (string) ( $data['app_id'] ?? '' );
		if ( '' !== $app_id && $app_id !== $this->app->app_id() ) {
			return new HealthReport( false, __( 'El token pertenece a otra app de Meta.', 'voceador' ), $scopes, $missing, $expires, $data );
		}

		$profile_id = (string) ( $data['profile_id'] ?? '' );
		if ( '' !== $profile_id && '' !== $channel->remote_id && $profile_id !== $channel->remote_id ) {
			return new HealthReport( false, __( 'El token pertenece a otra Página.', 'voceador' ), $scopes, $missing, $expires, $data );
		}

		$message = $missing
			? sprintf(
				/* translators: %s: lista de permisos */
				__( 'Token válido, pero faltan permisos: %s', 'voceador' ),
				implode( ', ', $missing )
			)
			: __( 'Token válido.', 'voceador' );

		return new HealthReport( true, $message, $scopes, $missing, $expires, $data );
	}

	/**
	 * Convierte el informe en el array que se guarda en channels.health.
	 *
	 * @param HealthReport $report Informe.
	 * @return array
	 */
	private function health_from( HealthReport $report ): array {
		return array(
			'valid'          => $report->valid,
			'message'        => $report->message,
			'scopes'         => $report->scopes,
			'missing_scopes' => $report->missing_scopes,
			'expires_at'     => $report->expires_at,
		);
	}
}
```

- [ ] **Step 7: Contenedor**

En `Plugin`: añade `TokenManager::class` a `REGISTRABLES` y la factory:

```php
		$this->factories[ TokenManager::class ] = static function ( Plugin $c ): TokenManager {
			return new TokenManager( $c->get( AppCredentials::class ), $c->get( GraphClient::class ), $c->get( ChannelRepository::class ), $c->get( Logger::class ) );
		};
```

- [ ] **Step 8: Tests y lint**

Run: `npm run test -- --filter 'NoticesTest|TokenManagerTest|PublisherTest' && npm run lint`
Expected: `OK`; PHPCS sin errores. `PublisherTest` debe seguir en verde: la delegación no cambia el comportamiento.

- [ ] **Step 9: Commit**

```bash
git add src/Notices.php src/TokenManager.php src/Publisher.php src/OAuth/Facebook.php src/Plugin.php tests/phpunit/NoticesTest.php tests/phpunit/TokenManagerTest.php
git commit -m "feat: revisión diaria de la salud de los tokens con avisos"
```

---

### Task 4: Pantalla de conexiones

**Files:**
- Create: `src/Admin/ConnectionsPage.php`
- Modify: `src/Plugin.php` (factory + `REGISTRABLES`)
- Test: `tests/phpunit/ConnectionsPageTest.php`

**Interfaces:**
- Consumes: `AppCredentials`, `OAuth\Facebook` (`ACTION_START`, `ACTION_CONNECT`, `redirect_uri()`, `candidates()`), `ChannelRepository` (`all()`, `find()`, `set_status()`, `delete()`), `TokenManager::check()`, `Notices`, `Installer::CAPABILITY`, `Channels\ChannelRegistry::types()`.
- Produces:
  - `ConnectionsPage implements Registrable`; `new ConnectionsPage( AppCredentials $app, OAuth\Facebook $oauth, ChannelRepository $channels, TokenManager $tokens )`.
  - `ConnectionsPage::SLUG = 'voceador'`; `ACTION_SAVE_APP = 'voceador_save_app'`; `ACTION_CHANNEL = 'voceador_channel_action'`.
  - `register_hooks(): void` — `admin_menu`, `admin_notices`, y los dos handlers de `admin-post`.
  - `add_menu(): void` — menú de primer nivel "Voceador" (`manage` = `Installer::CAPABILITY`, icono `dashicons-megaphone`, posición 76).
  - `render(): void` — pinta la pantalla: mensajes, formulario de la app, botón de conexión, selector de Páginas cuando hay candidatas, y la tabla de canales.
  - `render_notices(): void` — en `admin_notices`, pinta `Notices::all()` **escapado**, cada uno con un enlace para descartarlo.
  - `handle_save_app(): void` — nonce + capacidad; guarda App ID, App Secret (solo si viene) y la casilla de Business Manager.
  - `handle_channel_action(): void` — nonce + capacidad; acciones `check` (revisa ahora y guarda la salud), `disconnect` (borra el canal y su aviso) y `dismiss_notice`.
  - Todas las salidas pasan por `esc_html`, `esc_attr` o `esc_url`; ningún token se imprime.

- [ ] **Step 1: Escribir el test**

`tests/phpunit/ConnectionsPageTest.php`:

```php
<?php
/**
 * @package Voceador
 */

use Voceador\Admin\ConnectionsPage;
use Voceador\AppCredentials;
use Voceador\ChannelRepository;
use Voceador\Crypto;
use Voceador\GraphClient;
use Voceador\Installer;
use Voceador\Logger;
use Voceador\Notices;
use Voceador\OAuth\Facebook;
use Voceador\Schema;
use Voceador\Settings;
use Voceador\TokenManager;

class ConnectionsPageTest extends WP_UnitTestCase {

	private ConnectionsPage $page;
	private AppCredentials $app;
	private ChannelRepository $channels;
	private Facebook $oauth;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		delete_option( VOCEADOR_PREFIX . AppCredentials::OPTION );
		delete_option( VOCEADOR_PREFIX . Notices::OPTION );

		$crypto         = new Crypto( str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );
		$schema         = new Schema( $wpdb );
		$logger         = new Logger( $wpdb, $schema, 'debug' );
		$settings       = new Settings();
		$graph          = new GraphClient( $settings, $logger );
		$this->app      = new AppCredentials( $crypto );
		$this->channels = new ChannelRepository( $wpdb, $schema, $crypto );
		$this->oauth    = new Facebook( $this->app, $graph, $this->channels, $crypto, $settings, $logger );
		$this->page     = new ConnectionsPage( $this->app, $this->oauth, $this->channels, new TokenManager( $this->app, $graph, $this->channels, $logger ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		get_role( 'administrator' )->add_cap( Installer::CAPABILITY );
	}

	private function render(): string {
		ob_start();
		$this->page->render();

		return (string) ob_get_clean();
	}

	public function test_menu_is_registered_with_the_plugin_capability(): void {
		global $menu, $submenu;
		$menu    = array();
		$submenu = array();

		$this->page->register_hooks();
		do_action( 'admin_menu' );

		$slugs = array_column( $menu, 2 );
		$this->assertContains( ConnectionsPage::SLUG, $slugs );
		$entry = $menu[ array_search( ConnectionsPage::SLUG, $slugs, true ) ];
		$this->assertSame( Installer::CAPABILITY, $entry[1] );
	}

	public function test_render_asks_for_the_app_before_offering_to_connect(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'App ID', $html );
		$this->assertStringContainsString( $this->oauth->redirect_uri(), $html, 'Muestra la URI que hay que registrar en Meta.' );
		$this->assertStringNotContainsString( Facebook::ACTION_START, $html, 'Sin app configurada no se ofrece conectar.' );

		$this->app->save( '111222333', 'secreto' );
		$html = $this->render();
		$this->assertStringContainsString( Facebook::ACTION_START, $html );
	}

	public function test_channel_row_offers_reconnect_and_shows_expiry(): void {
		$this->app->save( '111222333', 'secreto' );
		$id = $this->channels->insert(
			array(
				'type'        => 'facebook_page',
				'alias'       => 'Prueba',
				'remote_id'   => '1001',
				'credentials' => array( 'access_token' => 'T' ),
				'health'      => array( 'message' => 'Token válido.', 'expires_at' => '2026-12-31 00:00:00' ),
			)
		);

		$html = $this->render();

		$this->assertStringContainsString( 'Reconectar', $html );
		$this->assertStringContainsString( '2026-12-31 00:00:00', $html );
		$this->assertStringContainsString( (string) $id, $html );
	}

	public function test_render_never_prints_the_secret_or_a_token(): void {
		$this->app->save( '111222333', 'secreto-de-la-app' );
		$this->channels->insert(
			array(
				'type'        => 'facebook_page',
				'alias'       => 'Prueba',
				'remote_id'   => '1001',
				'remote_name' => 'Página',
				'credentials' => array( 'access_token' => 'EAAtoken-secretisimo' ),
			)
		);

		$html = $this->render();

		$this->assertStringNotContainsString( 'secreto-de-la-app', $html );
		$this->assertStringNotContainsString( 'EAAtoken-secretisimo', $html );
		$this->assertStringContainsString( 'Prueba', $html );
		$this->assertStringContainsString( '1001', $html );
	}

	public function test_render_lists_candidate_pages_after_the_callback(): void {
		$this->app->save( '111222333', 'secreto' );
		$this->oauth->store_candidates( array( array( 'id' => '1001', 'name' => 'Una <b>rara</b>', 'access_token' => 'T1' ) ) );

		$html = $this->render();

		$this->assertStringContainsString( 'name="pages[1001]"', $html );
		$this->assertStringContainsString( 'Una &lt;b&gt;rara&lt;/b&gt;', $html, 'El nombre se escapa.' );
		$this->assertStringNotContainsString( 'T1', $html );
	}

	public function test_notices_are_rendered_escaped_and_can_be_dismissed(): void {
		Notices::add( 'roto', 'Canal "X" <script>alert(1)</script>' );

		ob_start();
		$this->page->render_notices();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( ConnectionsPage::ACTION_CHANNEL, $html );
	}

	public function test_notices_are_hidden_from_users_without_the_capability(): void {
		Notices::add( 'roto', 'Canal pausado' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		ob_start();
		$this->page->render_notices();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_save_app_keeps_the_secret_when_the_field_is_empty(): void {
		$this->app->save( '111222333', 'secreto' );

		$this->page->save_app_from( array( 'app_id' => '999', 'app_secret' => '', 'business_management' => '1' ) );

		$this->assertSame( '999', $this->app->app_id() );
		$this->assertSame( 'secreto', $this->app->app_secret() );
		$this->assertTrue( $this->app->business_management() );

		$this->page->save_app_from( array( 'app_id' => '999', 'app_secret' => 'nuevo' ) );
		$this->assertSame( 'nuevo', $this->app->app_secret() );
		$this->assertFalse( $this->app->business_management(), 'La casilla desmarcada se guarda como falsa.' );
	}

	public function test_channel_action_disconnect_removes_channel_and_notice(): void {
		$id = $this->channels->insert(
			array(
				'type'        => 'facebook_page',
				'alias'       => 'Prueba',
				'remote_id'   => '1001',
				'credentials' => array( 'access_token' => 'T' ),
			)
		);
		Notices::add( Notices::key_for_channel( $id ), 'Pausado' );

		$this->assertSame( 'disconnected', $this->page->run_channel_action( 'disconnect', $id ) );
		$this->assertNull( $this->channels->find( $id ) );
		$this->assertArrayNotHasKey( Notices::key_for_channel( $id ), Notices::all() );

		$this->assertSame( 'missing', $this->page->run_channel_action( 'disconnect', 999999 ) );
	}
}
```

- [ ] **Step 2: Ejecutar y verificar que falla**

Run: `npm run test -- --filter ConnectionsPageTest`
Expected: FAIL con `Class "Voceador\Admin\ConnectionsPage" not found`.

- [ ] **Step 3: Crear `src/Admin/ConnectionsPage.php`**

```php
<?php
/**
 * Pantalla de conexiones.
 *
 * @package Voceador
 */

namespace Voceador\Admin;

use Voceador\AppCredentials;
use Voceador\ChannelRepository;
use Voceador\Installer;
use Voceador\Notices;
use Voceador\OAuth\Facebook;
use Voceador\Registrable;
use Voceador\TokenManager;

/**
 * Única pantalla de la fase 2: credenciales de la app, conexión con Facebook,
 * elección de Páginas y estado de los canales.
 */
final class ConnectionsPage implements Registrable {

	/**
	 * Slug del menú.
	 */
	public const SLUG = 'voceador';

	/**
	 * Acción de admin-post que guarda las credenciales.
	 */
	public const ACTION_SAVE_APP = 'voceador_save_app';

	/**
	 * Acción de admin-post de las acciones sobre un canal.
	 */
	public const ACTION_CHANNEL = 'voceador_channel_action';

	/**
	 * Constructor.
	 *
	 * @param AppCredentials    $app      Credenciales de la app.
	 * @param Facebook          $oauth    Conexión con Facebook.
	 * @param ChannelRepository $channels Canales.
	 * @param TokenManager      $tokens   Salud de los tokens.
	 */
	public function __construct(
		private AppCredentials $app,
		private Facebook $oauth,
		private ChannelRepository $channels,
		private TokenManager $tokens
	) {}

	/**
	 * Engancha el menú, los avisos y los handlers.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE_APP, array( $this, 'handle_save_app' ) );
		add_action( 'admin_post_' . self::ACTION_CHANNEL, array( $this, 'handle_channel_action' ) );
	}

	/**
	 * Añade el menú de primer nivel.
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'Voceador', 'voceador' ),
			__( 'Voceador', 'voceador' ),
			Installer::CAPABILITY,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-megaphone',
			76
		);
	}

	/**
	 * Pinta la pantalla.
	 */
	public function render(): void {
		$candidates = $this->oauth->candidates();

		echo '<div class="wrap"><h1>' . esc_html__( 'Voceador · Conexiones', 'voceador' ) . '</h1>';

		$this->render_messages();
		$this->render_app_form();

		if ( $candidates ) {
			$this->render_candidates( $candidates );
		}

		$this->render_channels();

		echo '</div>';
	}

	/**
	 * Pinta los avisos persistentes, escapados.
	 */
	public function render_notices(): void {
		if ( ! current_user_can( Installer::CAPABILITY ) ) {
			return;
		}

		foreach ( Notices::all() as $key => $notice ) {
			printf(
				'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html( (string) $notice['message'] ),
				esc_url( $this->channel_action_url( 'dismiss_notice', 0, $key ) ),
				esc_html__( 'Descartar', 'voceador' )
			);
		}
	}

	/**
	 * Guarda las credenciales enviadas por el formulario.
	 *
	 * @param array $input Datos ya sin barras invertidas.
	 */
	public function save_app_from( array $input ): void {
		$app_id     = sanitize_text_field( (string) ( $input['app_id'] ?? '' ) );
		$app_secret = trim( (string) ( $input['app_secret'] ?? '' ) );

		$this->app->save( $app_id, '' !== $app_secret ? $app_secret : null );
		$this->app->set_business_management( ! empty( $input['business_management'] ) );
	}

	/**
	 * Ejecuta una acción sobre un canal.
	 *
	 * @param string $action     check, disconnect o dismiss_notice.
	 * @param int    $channel_id Canal.
	 * @param string $notice_key Clave del aviso, solo para dismiss_notice.
	 * @return string Resultado: checked, paused, disconnected, dismissed, missing o unknown.
	 */
	public function run_channel_action( string $action, int $channel_id, string $notice_key = '' ): string {
		if ( 'dismiss_notice' === $action ) {
			Notices::remove( $notice_key );

			return 'dismissed';
		}

		$channel = $this->channels->find( $channel_id );

		if ( null === $channel ) {
			return 'missing';
		}

		if ( 'disconnect' === $action ) {
			$this->channels->delete( $channel->id );
			Notices::remove( Notices::key_for_channel( $channel->id ) );

			return 'disconnected';
		}

		if ( 'check' === $action ) {
			$report = $this->tokens->check( $channel );
			$health = array(
				'valid'          => $report->valid,
				'message'        => $report->message,
				'scopes'         => $report->scopes,
				'missing_scopes' => $report->missing_scopes,
				'expires_at'     => $report->expires_at,
			);

			if ( $report->valid ) {
				$this->channels->set_status( $channel->id, $channel->status, $health );

				return 'checked';
			}

			$this->channels->set_status( $channel->id, 'paused', $health );
			Notices::add(
				Notices::key_for_channel( $channel->id ),
				sprintf(
					/* translators: 1: alias del canal, 2: motivo */
					__( 'Voceador pausó el canal "%1$s": %2$s Vuelve a conectarlo desde Voceador → Conexiones.', 'voceador' ),
					$channel->alias,
					$report->message
				)
			);

			return 'paused';
		}

		return 'unknown';
	}

	/**
	 * Handler de admin-post que guarda las credenciales.
	 */
	public function handle_save_app(): void {
		$this->authorize();
		check_admin_referer( self::ACTION_SAVE_APP );

		$this->save_app_from( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- save_app_from() sanea cada campo.

		$this->back( __( 'Credenciales guardadas.', 'voceador' ) );
	}

	/**
	 * Handler de admin-post de las acciones sobre canales.
	 */
	public function handle_channel_action(): void {
		$this->authorize();

		$action     = isset( $_GET['voceador_action'] ) ? sanitize_key( wp_unslash( $_GET['voceador_action'] ) ) : '';
		$channel_id = isset( $_GET['channel'] ) ? absint( wp_unslash( $_GET['channel'] ) ) : 0;
		$notice_key = isset( $_GET['notice'] ) ? sanitize_text_field( wp_unslash( $_GET['notice'] ) ) : '';

		check_admin_referer( self::ACTION_CHANNEL . '_' . $action . '_' . $channel_id . $notice_key );

		$result   = $this->run_channel_action( $action, $channel_id, $notice_key );
		$messages = array(
			'checked'      => __( 'El token sigue siendo válido.', 'voceador' ),
			'paused'       => __( 'El token ya no sirve: el canal quedó pausado.', 'voceador' ),
			'disconnected' => __( 'Canal desconectado.', 'voceador' ),
			'dismissed'    => __( 'Aviso descartado.', 'voceador' ),
			'missing'      => __( 'Ese canal ya no existe.', 'voceador' ),
		);

		$this->back( $messages[ $result ] ?? __( 'Acción desconocida.', 'voceador' ) );
	}

	/**
	 * Pinta los mensajes que llegan por la URL.
	 */
	private function render_messages(): void {
		// Mensajes propios tras una redirección; el nonce lo valida quien ejecuta la acción.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['voceador_msg'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['voceador_msg'] ) ) ) . '</p></div>';
		}
		if ( isset( $_GET['voceador_error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['voceador_error'] ) ) ) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Pinta el formulario de la app de Meta.
	 */
	private function render_app_form(): void {
		echo '<h2>' . esc_html__( 'App de Meta', 'voceador' ) . '</h2>';
		echo '<p>' . esc_html__( 'Registra esta URI de redirección en tu app (Facebook Login → Settings → Valid OAuth Redirect URIs):', 'voceador' ) . '</p>';
		echo '<p><input type="text" class="large-text code" readonly value="' . esc_attr( $this->oauth->redirect_uri() ) . '" onfocus="this.select()" /></p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION_SAVE_APP );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SAVE_APP ) . '" />';
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row"><label for="voceador-app-id">' . esc_html__( 'App ID', 'voceador' ) . '</label></th>';
		echo '<td><input type="text" id="voceador-app-id" name="app_id" class="regular-text" value="' . esc_attr( $this->app->app_id() ) . '" /></td></tr>';

		echo '<tr><th scope="row"><label for="voceador-app-secret">' . esc_html__( 'App Secret', 'voceador' ) . '</label></th>';
		echo '<td><input type="password" id="voceador-app-secret" name="app_secret" class="regular-text" autocomplete="new-password" value="" /><p class="description">';
		echo $this->app->has_secret()
			? esc_html__( 'Ya hay un secreto guardado. Déjalo en blanco para conservarlo.', 'voceador' )
			: esc_html__( 'Se guarda cifrado y no vuelve a mostrarse.', 'voceador' );
		echo '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Business Manager', 'voceador' ) . '</th>';
		echo '<td><label><input type="checkbox" name="business_management" value="1" ' . checked( $this->app->business_management(), true, false ) . ' /> ';
		echo esc_html__( 'Mis Páginas están en un Business Manager', 'voceador' ) . '</label></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Guardar credenciales', 'voceador' ) );
		echo '</form>';

		if ( $this->app->is_configured() ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=' . Facebook::ACTION_START ), Facebook::ACTION_START );
			echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Conectar con Facebook', 'voceador' ) . '</a></p>';
		}
	}

	/**
	 * Pinta el selector de Páginas tras volver de Facebook.
	 *
	 * @param array $candidates Páginas candidatas.
	 */
	private function render_candidates( array $candidates ): void {
		echo '<h2>' . esc_html__( 'Páginas disponibles', 'voceador' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( Facebook::ACTION_CONNECT );
		echo '<input type="hidden" name="action" value="' . esc_attr( Facebook::ACTION_CONNECT ) . '" />';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Conectar', 'voceador' ) . '</th><th>' . esc_html__( 'Página', 'voceador' ) . '</th><th>' . esc_html__( 'Alias interno', 'voceador' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $candidates as $page ) {
			$id   = (string) ( $page['id'] ?? '' );
			$name = (string) ( $page['name'] ?? '' );

			echo '<tr><td><input type="checkbox" name="connect[]" value="' . esc_attr( $id ) . '" checked /></td>';
			echo '<td>' . esc_html( $name ) . '<br /><code>' . esc_html( $id ) . '</code></td>';
			echo '<td><input type="text" name="pages[' . esc_attr( $id ) . ']" class="regular-text" value="' . esc_attr( $name ) . '" /></td></tr>';
		}

		echo '</tbody></table>';
		submit_button( __( 'Conectar las Páginas marcadas', 'voceador' ) );
		echo '</form>';
	}

	/**
	 * Pinta la tabla de canales conectados.
	 */
	private function render_channels(): void {
		$channels = $this->channels->all();

		echo '<h2>' . esc_html__( 'Canales conectados', 'voceador' ) . '</h2>';

		if ( ! $channels ) {
			echo '<p>' . esc_html__( 'Todavía no hay ningún canal conectado.', 'voceador' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Alias', 'voceador' ) . '</th><th>' . esc_html__( 'Página', 'voceador' ) . '</th><th>' . esc_html__( 'Estado', 'voceador' ) . '</th>';
		echo '<th>' . esc_html__( 'Última revisión', 'voceador' ) . '</th><th>' . esc_html__( 'Acciones', 'voceador' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $channels as $channel ) {
			$health = isset( $channel->health['message'] ) ? (string) $channel->health['message'] : '';

			echo '<tr>';
			echo '<td>' . esc_html( $channel->alias ) . '</td>';
			echo '<td>' . esc_html( $channel->remote_name ) . '<br /><code>' . esc_html( $channel->remote_id ) . '</code></td>';
			echo '<td>' . esc_html( $channel->status );
			if ( '' !== $health ) {
				echo '<br /><span class="description">' . esc_html( $health ) . '</span>';
			}
			echo '</td>';
			$expires = isset( $channel->health['expires_at'] ) ? (string) $channel->health['expires_at'] : '';
			echo '<td>' . esc_html( (string) ( $channel->health_checked_at ?? '—' ) );
			if ( '' !== $expires ) {
				echo '<br /><span class="description">' . esc_html( sprintf( /* translators: %s: fecha */ __( 'Caduca: %s', 'voceador' ), $expires ) ) . '</span>';
			}
			echo '</td>';
			echo '<td><a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . Facebook::ACTION_START ), Facebook::ACTION_START ) ) . '">' . esc_html__( 'Reconectar', 'voceador' ) . '</a> | ';
			echo '<a href="' . esc_url( $this->channel_action_url( 'check', $channel->id ) ) . '">' . esc_html__( 'Revisar ahora', 'voceador' ) . '</a> | ';
			echo '<a href="' . esc_url( $this->channel_action_url( 'disconnect', $channel->id ) ) . '" onclick="return confirm(' . esc_attr( wp_json_encode( __( '¿Desconectar este canal?', 'voceador' ) ) ) . ');">' . esc_html__( 'Desconectar', 'voceador' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Para reconectar un canal pausado, vuelve a pulsar "Conectar con Facebook": se actualizará su token sin perder su configuración.', 'voceador' ) . '</p>';
	}

	/**
	 * URL firmada de una acción sobre un canal.
	 *
	 * @param string $action     Acción.
	 * @param int    $channel_id Canal.
	 * @param string $notice_key Clave del aviso.
	 * @return string
	 */
	private function channel_action_url( string $action, int $channel_id, string $notice_key = '' ): string {
		$url = add_query_arg(
			array(
				'action'          => self::ACTION_CHANNEL,
				'voceador_action' => $action,
				'channel'         => $channel_id,
				'notice'          => rawurlencode( $notice_key ),
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, self::ACTION_CHANNEL . '_' . $action . '_' . $channel_id . $notice_key );
	}

	/**
	 * Corta la ejecución si el usuario no puede gestionar el plugin.
	 */
	private function authorize(): void {
		if ( ! current_user_can( Installer::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permiso para gestionar las conexiones de Voceador.', 'voceador' ), 403 );
		}
	}

	/**
	 * Vuelve a la pantalla con un mensaje.
	 *
	 * @param string $message Mensaje.
	 */
	private function back( string $message ): void {
		wp_safe_redirect( add_query_arg( array( 'voceador_msg' => rawurlencode( $message ) ), admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}
}
```

- [ ] **Step 4: Contenedor**

En `Plugin`: añade `Admin\ConnectionsPage::class` a `REGISTRABLES` y la factory:

```php
		$this->factories[ Admin\ConnectionsPage::class ] = static function ( Plugin $c ): Admin\ConnectionsPage {
			return new Admin\ConnectionsPage( $c->get( AppCredentials::class ), $c->get( OAuth\Facebook::class ), $c->get( ChannelRepository::class ), $c->get( TokenManager::class ) );
		};
```

- [ ] **Step 5: Ajustar `handle_connect` a la casilla del formulario**

El formulario envía `connect[]` con los ids marcados y `pages[<id>]` con el alias de todas las filas. En `OAuth\Facebook::handle_connect()`, filtra por lo marcado antes de construir `$selection`:

```php
		$checked = isset( $_POST['connect'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['connect'] ) ) : array();
```

y omite las filas cuyo `page_id` no esté en `$checked`. Añade a `OAuthFacebookTest` un test `test_handle_connect_only_uses_checked_pages()` que llame a `connect()` con una selección ya filtrada y compruebe que una Página no marcada no se da de alta (la lógica de filtrado del handler queda cubierta por el test de la pantalla).

- [ ] **Step 6: Tests y lint**

Run: `npm run test -- --filter 'ConnectionsPageTest|OAuthFacebookTest|PluginTest' && npm run lint`
Expected: `OK`; PHPCS sin errores. Si PHPCS pide escapar antes del `echo` en algún punto, corrige el escape (no desactives la regla); la única excepción permitida es el bloque `phpcs:disable WordPress.Security.NonceVerification.Recommended` ya documentado en `render_messages()`.

- [ ] **Step 7: Commit**

```bash
git add src/Admin/ConnectionsPage.php src/Plugin.php src/OAuth/Facebook.php tests/phpunit/ConnectionsPageTest.php tests/phpunit/OAuthFacebookTest.php
git commit -m "feat: pantalla de conexiones con app, OAuth, Páginas y estado de canales"
```

---

### Task 5: CLI, documentación y readme

**Files:**
- Modify: `src/CLI.php`, `tests/phpunit/CLITest.php`, `README.md`, `readme.txt`, `docs/superpowers/specs/2026-09-21-voceador-design.md`, `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: `TokenManager` (`check()`, `check_all()`), `ChannelRepository`, `AppCredentials`, `OAuth\Facebook::redirect_uri()`.
- Produces:
  - `CLI::__construct()` recibe además `TokenManager $tokens` y `AppCredentials $app` como últimos parámetros.
  - `CLI::check_channels( ?int $channel_id = null ): array` — devuelve filas `array{channel,alias,valid,message,expires_at,missing_scopes}` para un canal o para todos; **no pausa nada** cuando se pide un canal concreto salvo que el token sea inválido, en cuyo caso reutiliza `TokenManager::check_all()` solo si se pidió `--all`.
  - Subcomando `wp voceador channels check [<id>] [--all]`: sin argumentos revisa todos sin pausar (solo informa); con `--all` ejecuta la revisión completa (la misma del cron, que sí pausa).
  - `CLI::status()` añade la clave `app` con `array{configured:bool, app_id:string, redirect_uri:string}`; `cmd_status` la imprime.

- [ ] **Step 1: Añadir los tests a `CLITest`**

Ajusta `set_up()` para construir el CLI con los dos servicios nuevos y añade:

```php
	public function test_status_reports_the_app(): void {
		$status = $this->cli->status();

		$this->assertFalse( $status['app']['configured'] );
		$this->assertSame( '', $status['app']['app_id'] );
		$this->assertStringContainsString( 'action=voceador_oauth_fb', $status['app']['redirect_uri'] );
	}

	public function test_check_channels_reports_without_pausing(): void {
		$this->responses[] = GraphResponses::ok( array( 'id' => '1001', 'name' => 'Mi Página' ) );
		$id = $this->cli->add_facebook_page( '1001', 'EAAtoken' );

		$this->responses[] = GraphResponses::ok(
			array(
				'data' => array(
					'app_id'     => '111222333',
					'is_valid'   => false,
					'profile_id' => '1001',
					'expires_at' => 0,
					'scopes'     => array(),
				),
			)
		);

		$rows = $this->cli->check_channels( $id );

		$this->assertCount( 1, $rows );
		$this->assertSame( $id, $rows[0]['channel'] );
		$this->assertFalse( $rows[0]['valid'] );
		$this->assertSame( 'active', $this->channels->find( $id )->status, 'Informar no pausa.' );
	}

	public function test_check_channels_of_a_missing_channel_is_empty(): void {
		$this->assertSame( array(), $this->cli->check_channels( 999999 ) );
	}
```

En `set_up()`, tras crear `$graph` y `$this->channels`, añade `$app = new AppCredentials( $crypto ); $app->save( '111222333', 'secreto' );` y pasa `new TokenManager( $app, $graph, $this->channels, $logger )` y `$app` al constructor del CLI.

- [ ] **Step 2: Ejecutar y verificar que falla**

Run: `npm run test -- --filter CLITest`
Expected: FAIL por el número de argumentos del constructor y por `check_channels()` indefinido.

- [ ] **Step 3: Ampliar `src/CLI.php`**

Añade los dos servicios al constructor (al final, para no romper el orden existente), registra el subcomando en `register()`:

```php
		\WP_CLI::add_command( 'voceador channels check', array( $this, 'cmd_channels_check' ) );
```

y añade los métodos:

```php
	/**
	 * Revisa la salud de uno o de todos los canales sin cambiar su estado.
	 *
	 * @param int|null $channel_id Canal concreto, o null para todos.
	 * @return array[] Filas con el resultado de cada canal.
	 */
	public function check_channels( ?int $channel_id = null ): array {
		$channels = null === $channel_id ? $this->channels->all() : array_filter( array( $this->channels->find( $channel_id ) ) );
		$rows     = array();

		foreach ( $channels as $channel ) {
			$report = $this->tokens->check( $channel );

			$rows[] = array(
				'channel'        => $channel->id,
				'alias'          => $channel->alias,
				'valid'          => $report->valid,
				'message'        => $report->message,
				'expires_at'     => (string) ( $report->expires_at ?? '' ),
				'missing_scopes' => implode( ',', $report->missing_scopes ),
			);
		}

		return $rows;
	}

	/**
	 * `wp voceador channels check [<id>] [--all]`
	 *
	 * @param array $args       Posicionales.
	 * @param array $assoc_args Opciones.
	 */
	public function cmd_channels_check( array $args, array $assoc_args ): void {
		if ( ! empty( $assoc_args['all'] ) ) {
			$result = $this->tokens->check_all();
			\WP_CLI::success( sprintf( '%d canal(es) revisado(s), %d pausado(s).', $result['checked'], $result['paused'] ) );

			return;
		}

		$channel_id = isset( $args[0] ) ? absint( $args[0] ) : null;
		$rows       = $this->check_channels( $channel_id );

		if ( ! $rows ) {
			\WP_CLI::warning( 'No hay canales que revisar.' );

			return;
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'channel', 'alias', 'valid', 'message', 'expires_at', 'missing_scopes' ) );
	}
```

En `status()`, añade al array devuelto:

```php
			'app'              => array(
				'configured'   => $this->app->is_configured(),
				'app_id'       => $this->app->app_id(),
				'redirect_uri' => admin_url( 'admin-post.php' ) . '?action=voceador_oauth_fb',
			),
```

y en `cmd_status()`, tras la línea de "Cron disponible":

```php
		\WP_CLI::line( 'App de Meta: ' . ( $status['app']['configured'] ? 'configurada (' . $status['app']['app_id'] . ')' : 'sin configurar' ) );
		\WP_CLI::line( 'Redirect URI: ' . $status['app']['redirect_uri'] );
```

Actualiza la factory de `CLI` en `Plugin` para pasar `$c->get( TokenManager::class )` y `$c->get( AppCredentials::class )`.

- [ ] **Step 4: Documentación**

- `README.md`: sustituye la línea `> Estado:` por `> Estado: fase 2 completada: conexión con Facebook por OAuth desde el escritorio, credenciales cifradas y revisión diaria de la salud de los tokens con avisos. Falta Instagram, panel del editor, wizard y ajustes completos.` y añade `wp voceador channels check` a la lista de comandos.
- `readme.txt`: en `== Description ==`, mueve "Facebook Login (OAuth) instead of manual tokens, token health monitoring" de la hoja de ruta a la lista de lo incluido, y añade dos puntos: `Connect your Pages from the WordPress dashboard with a "Connect with Facebook" button; no tokens to copy by hand.` y `A daily health check that pauses a channel and shows a notice when its token stops working.`. En `== Installation ==`, sustituye los pasos 3 y 4 por: registrar la URI de redirección que muestra la pantalla, guardar App ID y App Secret en Voceador → Conexiones, y pulsar "Conectar con Facebook". Añade al `== Changelog ==` la entrada `= 0.2.0 =` describiendo OAuth y la revisión de tokens, y sube `Stable tag` a `0.2.0` **solo** si también subes la cabecera `Version` de `voceador.php` a `0.2.0`; hazlo, porque la fase entrega funcionalidad nueva.
- Spec `docs/superpowers/specs/2026-09-21-voceador-design.md`: en la tabla de opciones, deja `voceador_app` como "App ID y App Secret cifrado, y si las Páginas están en un Business Manager" y añade una nota de que la versión de Graph API vive en `voceador_settings.graph_version`. En la fila 2 de la tabla de fases, marca que incluye la pantalla de conexiones.
- `.github/workflows/ci.yml`: en el smoke, tras `wp voceador status`, añade `npx wp-env run cli wp voceador channels check` y comprueba que el aviso de "No hay canales que revisar" no rompe el script (el comando devuelve 0).

- [ ] **Step 5: Verificación completa**

Run: `npm run test && npm run test:multisite && npm run lint && npm run check:plugin`
Expected: todo en verde y Plugin Check sin errores. Ejecuta también el smoke local:

```bash
npx wp-env run cli wp voceador status
npx wp-env run cli wp voceador channels check
```

Expected: `status` muestra "App de Meta: sin configurar" y la redirect URI; `channels check` avisa de que no hay canales.

- [ ] **Step 6: Commit**

```bash
git add src/CLI.php src/Plugin.php tests/phpunit/CLITest.php README.md readme.txt voceador.php docs/superpowers/specs/2026-09-21-voceador-design.md .github/workflows/ci.yml
git commit -m "feat: wp voceador channels check y documentación de la fase 2"
```

---

### Task 6: Rama, PR y prueba en staging

Esta tarea la ejecuta el controlador con el usuario: requiere la app de Meta real y credenciales que no deben pasar por ningún reporte.

**Prerrequisitos que aporta el usuario:**
- La app de Meta con el permiso `pages_manage_engagement` habilitado (Casos de uso → Personalizar → Permisos, o App Review → Permissions and Features → acceso estándar).
- App ID y App Secret de esa app.
- La URI de redirección registrada en Facebook Login → Settings → Valid OAuth Redirect URIs. La pantalla la muestra lista para copiar; en staging será `https://microfono.mx/wp-admin/admin-post.php?action=voceador_oauth_fb`.

- [ ] **Step 1: Push, PR y CI**

```bash
git push -u origin fase-2/oauth-y-salud-de-tokens
gh pr create --base main --head fase-2/oauth-y-salud-de-tokens --title "Fase 2: OAuth y salud de tokens" --body-file <resumen, terminado en la línea de atribución>
gh pr checks <n> --repo aprendomx/voceador --watch
```

Expected: `lint-and-test` en verde.

- [ ] **Step 2: Desplegar en staging**

```bash
npm run build:zip
scp build-zip/voceador.zip USUARIO@SERVIDOR_STAGING:/tmp/voceador.zip
ssh USUARIO@SERVIDOR_STAGING 'cd /ruta/al/wordpress && sudo -u www-data wp plugin install /tmp/voceador.zip --force && rm -f /tmp/voceador.zip && sudo -u www-data wp voceador status'
```

Expected: el plugin sigue activo, el canal existente se conserva y `status` muestra "App de Meta: sin configurar" con la redirect URI.

- [ ] **Step 3: Configurar la app y conectar (lo hace el usuario en el navegador)**

1. Entrar en Voceador → Conexiones.
2. Copiar la redirect URI y registrarla en la app de Meta.
3. Pegar App ID y App Secret, marcar Business Manager si procede, guardar.
4. Pulsar "Conectar con Facebook", aceptar los permisos y elegir la Página "Micrófono".

Expected: vuelve a la pantalla con "Listo: 1 Página(s) conectada(s) y 0 actualizada(s)" o, si el canal 2 ya existe, "0 conectada(s) y 1 actualizada(s)", con el token renovado y el canal en `active`.

- [ ] **Step 4: Comprobar que el comentario ya funciona**

```bash
ssh USUARIO@SERVIDOR_STAGING 'cd /ruta/al/wordpress && sudo -u www-data wp voceador channels check && sudo -u www-data wp voceador retry <post_id> --comment && sudo -u www-data wp cron event run --due-now'
```

Expected: `channels check` muestra `valid` en `1` y `missing_scopes` vacío; el reintento publica el comentario de la Página con el enlace. Es la comprobación que quedó pendiente de la fase 1b.

- [ ] **Step 5: Comprobar la pausa por token revocado**

En Facebook: Configuración → Apps y sitios web → quitar la app (o restablecer el App Secret). Después:

```bash
ssh USUARIO@SERVIDOR_STAGING 'cd /ruta/al/wordpress && sudo -u www-data wp voceador channels check --all && sudo -u www-data wp voceador channels list'
```

Expected: el canal queda `paused`, aparece el aviso en el escritorio y el log registra `token_invalid`. Es el criterio de cierre de la fase. Luego se vuelve a conectar desde la pantalla para dejarlo operativo.

- [ ] **Step 6: Cierre**

Fusionar según decida el usuario y actualizar la memoria del proyecto.

---

## Criterio de cierre de la fase 2

- `npm run test`, `npm run test:multisite`, `npm run lint` y `npm run check:plugin` en verde en local y en CI.
- Desde el escritorio: guardar App ID y App Secret, pulsar "Conectar con Facebook", elegir Páginas y verlas aparecer como canales activos, sin pegar ningún token a mano.
- El App Secret y los Page tokens quedan cifrados; el token de usuario no se guarda en ningún sitio.
- Revocar el acceso en Facebook y ejecutar la revisión pausa el canal, deja un aviso en el escritorio y lo registra en el log; volver a conectar lo reactiva y retira el aviso.
- Reconectar no duplica canales: actualiza el existente conservando su alias y su configuración.
- Fuera de alcance (fases siguientes): el correo de aviso cuando se pausa un canal y el resto de notificaciones (7); la pestaña de "token manual" en la interfaz, que vive en el wizard (6) —por WP-CLI ya existe desde la fase 1b con `channels add-facebook`—; reglas y plantillas en la interfaz, panel del editor y metas registrados (3), Instagram y sus dos métodos de conexión (4), procesamiento de imagen y link en bio (5), wizard y ayuda (6), columna de estado en el listado, notificaciones por correo, import/export y Site Health (7).

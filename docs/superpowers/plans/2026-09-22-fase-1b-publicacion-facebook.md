# Voceador · Fase 1b (núcleo: publicación en Facebook) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que un post recién publicado en WordPress aparezca como foto con caption en una Página de Facebook conectada con token manual, seguido de un comentario con el enlace, con reintentos, límites y estado por trabajo; y que la publicación real se compruebe en staging (microfono.mx).

**Architecture:** El disparador (`Trigger`) solo encola: `Rules` decide los canales y `Queue` programa un trabajo por canal (Action Scheduler si existe, si no WP-Cron). `Publisher` ejecuta cada trabajo con lock + claim atómico, prepara la imagen y el texto (`Templates`), llama al adaptador (`FacebookPageAdapter` sobre `GraphClient`) y traduce cada clase de error en reintento, reprogramación, pausa del canal o fallo. Un `CLI` mínimo permite conectar una Página y publicar desde WP-CLI para la prueba en staging; la interfaz de administración llega en fases posteriores.

**Tech Stack:** PHP 8.1+, WordPress 6.4+, Graph API v26.0 (`POST /{page-id}/photos`, `POST /{page-id}/feed`, `POST /{post-id}/comments`, `GET /me`), Action Scheduler opcional, WP-CLI, PHPUnit con `pre_http_request`, PHPCS/WPCS.

**Spec:** `docs/superpowers/specs/2026-09-21-voceador-design.md` (secciones 2 "Flujo de un trabajo", 4 "Errores y reintentos", 7 fila 1b) y `docs/prompt-original.md` (secciones "Publicación en Facebook", "Plantillas", "Ejecución", "Disparador y ejecución").

## Global Constraints

- PHP 8.1+ y WordPress 6.4+; single site y Multisite.
- Sin Composer en runtime; HTTP solo vía `GraphClient` (token solo en cabecera). Action Scheduler no se empaqueta: se usa si otro plugin lo provee.
- Namespace `Voceador`; nombres con `VOCEADOR_PREFIX`; text domain `voceador`; interfaz en español.
- La publicación nunca ocurre en la petición que guarda el post: el disparador solo encola.
- Idempotencia: nunca republicar si ya existe `remote_id`; lock por trabajo con transient `voceador_lock_job_{id}` más `JobRepository::claim()`.
- Errores por clase (`WP_Error->get_error_code()`): `transient` reintenta con backoff `base × 2^(intento-1)` hasta `execution.retries`; `rate_limited` reprograma sin consumir intentos; `auth`/`permission` no reintentan, pausan el canal y avisan; `media`, `spam`, `fatal` fallan.
- El comentario es un paso aparte con su propio contador; su fallo nunca cambia `status = published`.
- Facebook: `POST /{page-id}/photos` con `caption` y `source` (multipart, default) o `url`; formatos jpeg/bmp/png/gif/tiff; máximo 4 MB (si excede, usar un tamaño registrado menor); guardar `post_id` de la respuesta (no `id`); comentario `POST /{post_id}/comments` tras `execution.comment_delay` (default 60 s); sin imagen destacada: `image.no_image` = `skip` (omitir) o `feed` (`POST /{page-id}/feed` con `message` y `link`).
- Retraso antes de publicar `execution.delay` (default 30 s). Programados (`future` → `publish`) según `execution.publish_scheduled`; republicados según `execution.publish_republished` con la marca `_voceador_first_published`.
- Filtros y acciones: `voceador_caption`, `voceador_comment`, `voceador_image`, `voceador_should_publish`, `voceador_target_channels`, `voceador_published`, `voceador_failed`.
- Nunca se registran tokens (usar `Logger`; los mensajes de error de trabajo ya pasan por `Logger::redact_string()` en el repositorio).
- WPCS: `array()`, tabs, Yoda, docblocks (`/** @var */` de tres líneas por propiedad readonly; `phpcs:ignore` con `-- razón`; ternarios completos). Tests exentos de docblocks y de `WordPress.DB`. `@group ms-required` en el docblock de la clase.
- Commits en español `tipo: descripción`, terminados en `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`. Nunca `wp plugin uninstall voceador` sin `--skip-delete`.

## File Structure

| Archivo | Responsabilidad |
| --- | --- |
| `src/JobRepository.php` (modificar) | `claim()` sin consumir intento desde `rate_limited`; `due_rate_limited()`, `stale_running()`, `retry_comment()`, `mark_unverified()` |
| `src/Templates.php` | Render de plantillas con marcadores y limpieza de texto; caption y comentario por canal |
| `src/Channels/FacebookPageAdapter.php` | Todo lo específico de Páginas de Facebook |
| `src/Queue.php` | Programación de trabajos y comentarios; barrido de trabajos perdidos; aviso si no hay cron |
| `src/Publisher.php` | Ejecución de un trabajo y de su comentario; política de errores; `publish_now()` |
| `src/Rules.php` | Qué canales recibe un post |
| `src/Trigger.php` | `transition_post_status` → encolar |
| `src/CLI.php` | Comandos `wp voceador …` mínimos |
| `src/Plugin.php` (modificar) | Factories, `REGISTRABLES`, registro del tipo `facebook_page`, carga de CLI |
| `src/Uninstaller.php` (modificar) | Cron `voceador_sweep` |
| `tests/phpunit/Fixtures/GraphResponses.php` | Respuestas simuladas reutilizables de Graph |
| `bin/build-zip.sh` | ZIP distribuible según `.distignore` (para staging) |

---

### Task 1: Completar `JobRepository`

**Files:**
- Modify: `src/JobRepository.php`
- Test: `tests/phpunit/JobRepositoryTest.php` (añadir tests)

**Interfaces:**
- Consumes: `JobRepository` de la fase 1a (`claim()`, `set()`, `due()`, `CLAIMABLE`).
- Produces:
  - `claim( int $id ): bool` — igual que antes, pero **no** incrementa `attempts` cuando el estado previo era `rate_limited`.
  - `due_rate_limited( int $limit = 50 ): Job[]` — `rate_limited` con `scheduled_at <= now`.
  - `stale_running( int $older_than_seconds = 900, int $limit = 50 ): Job[]` — `running` con `updated_at` anterior a `now - N s`.
  - `retry_comment( int $id ): bool` — `comment_status` `failed` → `pending` solo si `status = published`; `true` si cambió una fila.
  - `mark_unverified( int $id, string $message ): bool` — `status = failed`, `error_code = unverified`, mensaje redactado. Es la regla del spec §4: si `publish` pudo llegar a Meta pero se perdió la respuesta, no se republica a ciegas.

- [ ] **Step 1: Añadir tests a `JobRepositoryTest`**

```php
	public function test_claim_from_rate_limited_does_not_consume_attempt(): void {
		$id = $this->repo->create_if_absent( 30, 1 );
		$this->repo->claim( $id );
		$this->assertSame( 1, $this->repo->find( $id )->attempts );

		$this->repo->mark_rate_limited( $id, '2020-01-01 00:00:00', 'Sin cupo' );
		$this->assertTrue( $this->repo->claim( $id ) );
		$this->assertSame( 1, $this->repo->find( $id )->attempts, 'Reanudar tras límite de uso no cuenta como intento.' );

		$this->repo->mark_failed( $id, 'transient', 'x' );
		$this->assertTrue( $this->repo->claim( $id ) );
		$this->assertSame( 2, $this->repo->find( $id )->attempts );
	}

	public function test_due_rate_limited(): void {
		$past   = $this->repo->create_if_absent( 31, 1 );
		$future = $this->repo->create_if_absent( 31, 2 );
		$this->repo->mark_rate_limited( $past, '2020-01-01 00:00:00', 'x' );
		$this->repo->mark_rate_limited( $future, '2099-01-01 00:00:00', 'x' );

		$ids = array_map( static fn( Job $j ) => $j->id, $this->repo->due_rate_limited() );

		$this->assertSame( array( $past ), $ids );
	}

	public function test_stale_running(): void {
		global $wpdb;
		$old = $this->repo->create_if_absent( 32, 1 );
		$new = $this->repo->create_if_absent( 32, 2 );
		$this->repo->claim( $old );
		$this->repo->claim( $new );
		$wpdb->update( ( new Schema( $wpdb ) )->table( 'jobs' ), array( 'updated_at' => '2020-01-01 00:00:00' ), array( 'id' => $old ) );

		$ids = array_map( static fn( Job $j ) => $j->id, $this->repo->stale_running( 900 ) );

		$this->assertSame( array( $old ), $ids );
	}

	public function test_retry_comment_only_from_failed_on_published_jobs(): void {
		$id = $this->repo->create_if_absent( 33, 1 );
		$this->repo->mark_published( $id, 'r', '', true );
		$this->repo->mark_comment_failed( $id, 'spam' );

		$this->assertTrue( $this->repo->retry_comment( $id ) );
		$this->assertSame( 'pending', $this->repo->find( $id )->comment_status );
		$this->assertFalse( $this->repo->retry_comment( $id ), 'Ya está pendiente.' );

		$other = $this->repo->create_if_absent( 33, 2 );
		$this->repo->mark_comment_failed( $other, 'x' );
		$this->assertFalse( $this->repo->retry_comment( $other ), 'No está publicado.' );
	}

	public function test_mark_unverified(): void {
		$id = $this->repo->create_if_absent( 34, 1 );
		$this->repo->claim( $id );

		$this->assertTrue( $this->repo->mark_unverified( $id, 'Timeout tras enviar EAA' . str_repeat( 'Ab1', 10 ) ) );
		$job = $this->repo->find( $id );
		$this->assertSame( 'failed', $job->status );
		$this->assertSame( 'unverified', $job->error_code );
		$this->assertStringNotContainsString( 'EAAAb1', $job->error_message );
	}
```

- [ ] **Step 2: Ejecutar y verificar que fallan**

Run: `npm run test -- --filter JobRepositoryTest`
Expected: FAIL (`attempts` es 2 en el primer test; métodos indefinidos en los demás).

- [ ] **Step 3: Implementar**

En `claim()`, sustituye `SET status = 'running', attempts = attempts + 1, updated_at = %s` por `SET attempts = attempts + IF(status = 'rate_limited', 0, 1), status = 'running', updated_at = %s`. **El orden importa**: MySQL evalúa las asignaciones de `SET` de izquierda a derecha con los valores ya actualizados, así que `attempts` debe calcularse antes de cambiar `status`. Actualiza el docblock: "Incrementa `attempts` salvo cuando reanuda un trabajo en espera por límite de uso (`rate_limited`), que no cuenta como intento."

Añade estos métodos (antes de `count_by_status()`):

```php
	/**
	 * Trabajos en espera por límite de uso cuya hora de reintento ya llegó.
	 *
	 * @param int $limit Máximo.
	 * @return Job[]
	 */
	public function due_rate_limited( int $limit = 50 ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- El nombre de tabla viene de Schema::table(), no de entrada de usuario.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->table()} WHERE status = 'rate_limited' AND scheduled_at IS NOT NULL AND scheduled_at <= %s ORDER BY scheduled_at ASC, id ASC LIMIT %d", current_time( 'mysql', true ), $limit ), ARRAY_A );

		return array_map( static fn( array $row ) => new Job( $row ), $rows ? $rows : array() );
	}

	/**
	 * Trabajos que llevan demasiado tiempo en running (el proceso murió a mitad).
	 *
	 * @param int $older_than_seconds Antigüedad mínima de updated_at.
	 * @param int $limit              Máximo.
	 * @return Job[]
	 */
	public function stale_running( int $older_than_seconds = 900, int $limit = 50 ): array {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $older_than_seconds );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- El nombre de tabla viene de Schema::table(), no de entrada de usuario.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->table()} WHERE status = 'running' AND updated_at < %s ORDER BY updated_at ASC, id ASC LIMIT %d", $cutoff, $limit ), ARRAY_A );

		return array_map( static fn( array $row ) => new Job( $row ), $rows ? $rows : array() );
	}

	/**
	 * Vuelve a dejar pendiente el comentario de un trabajo publicado.
	 *
	 * @param int $id Id.
	 * @return bool true si había un comentario fallido que reintentar.
	 */
	public function retry_comment( int $id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- El nombre de tabla viene de Schema::table(), no de entrada de usuario.
		$this->wpdb->query( $this->wpdb->prepare( "UPDATE {$this->table()} SET comment_status = 'pending', updated_at = %s WHERE id = %d AND status = 'published' AND comment_status = 'failed'", current_time( 'mysql', true ), $id ) );

		return 1 === $this->wpdb->rows_affected;
	}

	/**
	 * Marca un trabajo cuyo envío pudo llegar a la red sin respuesta.
	 *
	 * No se republica a ciegas: queda en failed con código unverified para que
	 * la redacción verifique en la red antes de reintentar.
	 *
	 * @param int    $id      Id.
	 * @param string $message Explicación.
	 * @return bool true salvo error de consulta.
	 */
	public function mark_unverified( int $id, string $message ): bool {
		return $this->set(
			$id,
			array(
				'status'        => 'failed',
				'error_code'    => 'unverified',
				'error_message' => Logger::redact_string( $message ),
			)
		);
	}
```

- [ ] **Step 4: Tests y lint**

Run: `npm run test -- --filter JobRepositoryTest && npm run lint`
Expected: `OK (16 tests, ...)`; PHPCS sin errores.

- [ ] **Step 5: Commit**

```bash
git add src/JobRepository.php tests/phpunit/JobRepositoryTest.php
git commit -m "feat: reanudación sin consumir intentos, barridos y reintento de comentario en trabajos"
```

---

### Task 2: `Templates`

**Files:**
- Create: `src/Templates.php`
- Modify: `src/Plugin.php` (factory), `src/Settings.php` (defaults nuevos)
- Test: `tests/phpunit/TemplatesTest.php`

**Interfaces:**
- Consumes: `Settings::get( $path, $channel->settings )`, `Channels\Channel`.
- Produces:
  - `new Templates( Settings $settings )`.
  - `render( string $template, \WP_Post $post, array $extra = array() ): string` — marcadores `{title}`, `{excerpt}`, `{permalink}`, `{shortlink}`, `{site_name}`, `{author}`, `{category}`, `{categories}`, `{date}`, `{social_message}` y cualquier clave de `$extra` como `{clave}`. Limpieza: `wp_strip_all_tags`, `strip_shortcodes`, `html_entity_decode`, colapso de espacios; el resultado se recorta con `trim`.
  - `caption( Channel $channel, \WP_Post $post ): string` — plantilla `templates.caption` (con overrides del canal), filtro `voceador_caption( string $text, \WP_Post $post, Channel $channel )`, recorte a `templates.max_length` si > 0.
  - `comment( Channel $channel, \WP_Post $post ): string` — igual con `templates.comment` y filtro `voceador_comment`.
  - `Templates::truncate( string $text, int $max ): string` — recorta en el último espacio antes de `$max - 1` y añade `…`.
  - `{social_message}`: primer valor no vacío de la cadena `templates.social_fallback` (default `array( 'message', 'excerpt', 'title' )`), donde `message` es el meta `_voceador_message_fb` (fase 3 lo hará dependiente del canal); `excerpt` es `get_the_excerpt()` limpio; `title` es el título.
  - Nuevos defaults en `Settings::defaults()['templates']`: `'max_length' => 0`, `'social_fallback' => array( 'message', 'excerpt', 'title' )`.

- [ ] **Step 1: Escribir el test**

`tests/phpunit/TemplatesTest.php`:

```php
<?php
/**
 * @package Voceador
 */

use Voceador\Channels\Channel;
use Voceador\Settings;
use Voceador\Templates;

class TemplatesTest extends WP_UnitTestCase {

	private Templates $templates;
	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		delete_option( VOCEADOR_PREFIX . Settings::OPTION );
		$this->templates = new Templates( new Settings() );

		$author        = self::factory()->user->create( array( 'display_name' => 'Ana Autora' ) );
		$cat_a         = self::factory()->category->create( array( 'name' => 'Política' ) );
		$cat_b         = self::factory()->category->create( array( 'name' => 'Local' ) );
		$this->post_id = self::factory()->post->create(
			array(
				'post_title'    => 'Título con &amp; entidad',
				'post_excerpt'  => 'Extracto <strong>rico</strong> [shortcode]',
				'post_content'  => 'Contenido',
				'post_author'   => $author,
				'post_category' => array( $cat_a, $cat_b ),
				'post_date'     => '2026-09-22 10:00:00',
			)
		);
	}

	private function channel( array $settings = array() ): Channel {
		return new Channel( array( 'id' => 1, 'type' => 'facebook_page', 'settings' => $settings ) );
	}

	public function test_renders_basic_placeholders_clean(): void {
		$post = get_post( $this->post_id );
		$out  = $this->templates->render( "{title}\n{excerpt}\n{permalink}\n{site_name}\n{author}", $post );

		$this->assertStringContainsString( 'Título con & entidad', $out );
		$this->assertStringContainsString( 'Extracto rico', $out );
		$this->assertStringNotContainsString( '<strong>', $out );
		$this->assertStringNotContainsString( '[shortcode]', $out );
		$this->assertStringContainsString( get_permalink( $post ), $out );
		$this->assertStringContainsString( get_bloginfo( 'name' ), $out );
		$this->assertStringContainsString( 'Ana Autora', $out );
	}

	public function test_category_placeholders_and_date(): void {
		$post = get_post( $this->post_id );

		$this->assertSame( 'Local', $this->templates->render( '{category}', $post ), 'Primera categoría por orden alfabético de WP.' );
		$this->assertSame( 'Local, Política', $this->templates->render( '{categories}', $post ) );
		$this->assertSame( '22/09/2026', $this->templates->render( '{date}', $post ) );
	}

	public function test_unknown_placeholders_and_extra(): void {
		$post = get_post( $this->post_id );

		$this->assertSame( '{nope} X', $this->templates->render( '{nope} {custom}', $post, array( 'custom' => 'X' ) ) );
	}

	public function test_social_message_fallback_chain(): void {
		$post = get_post( $this->post_id );
		$this->assertSame( 'Extracto rico', $this->templates->render( '{social_message}', $post ) );

		update_post_meta( $this->post_id, '_voceador_message_fb', 'Texto social' );
		$this->assertSame( 'Texto social', $this->templates->render( '{social_message}', get_post( $this->post_id ) ) );

		( new Settings() )->update( array( 'templates' => array( 'social_fallback' => array( 'title' ) ) ) );
		$this->assertSame( 'Título con & entidad', $this->templates->render( '{social_message}', get_post( $this->post_id ) ) );
	}

	public function test_caption_uses_settings_channel_override_and_filter(): void {
		$post = get_post( $this->post_id );

		$this->assertSame( "Título con & entidad\n\nExtracto rico", $this->templates->caption( $this->channel(), $post ) );

		$channel = $this->channel( array( 'templates' => array( 'caption' => 'FB: {title}' ) ) );
		$this->assertSame( 'FB: Título con & entidad', $this->templates->caption( $channel, $post ) );

		add_filter( 'voceador_caption', static fn( string $text, WP_Post $p, Channel $c ) => $text . ' #f', 10, 3 );
		$this->assertSame( 'FB: Título con & entidad #f', $this->templates->caption( $channel, $post ) );
	}

	public function test_comment_and_max_length(): void {
		$post = get_post( $this->post_id );

		$this->assertSame( get_permalink( $post ), $this->templates->comment( $this->channel(), $post ) );

		$channel = $this->channel( array( 'templates' => array( 'caption' => str_repeat( 'palabra ', 20 ), 'max_length' => 30 ) ) );
		$caption = $this->templates->caption( $channel, $post );
		$this->assertLessThanOrEqual( 30, mb_strlen( $caption ) );
		$this->assertStringEndsWith( '…', $caption );
		$this->assertStringNotContainsString( 'palabr…', $caption, 'Recorta en un espacio, no a mitad de palabra.' );
	}

	public function test_truncate(): void {
		$this->assertSame( 'corto', Templates::truncate( 'corto', 10 ) );
		$this->assertSame( 'uno dos…', Templates::truncate( 'uno dos tres cuatro', 9 ) );
		$this->assertSame( 'abcdefgh…', Templates::truncate( 'abcdefghijkl', 9 ), 'Sin espacios recorta duro.' );
	}
}
```

- [ ] **Step 2: Ejecutar y verificar que falla**

Run: `npm run test -- --filter TemplatesTest`
Expected: FAIL con `Class "Voceador\Templates" not found`.

- [ ] **Step 3: Añadir defaults en `Settings`**

En `Settings::defaults()`, dentro de `'templates'`, añade:

```php
				'max_length'      => 0,
				'social_fallback' => array( 'message', 'excerpt', 'title' ),
```

- [ ] **Step 4: Crear `src/Templates.php`**

```php
<?php
/**
 * Plantillas de caption y comentario.
 *
 * @package Voceador
 */

namespace Voceador;

use Voceador\Channels\Channel;

/**
 * Sustituye marcadores y limpia el texto para redes sociales.
 */
final class Templates {

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Ajustes.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * Renderiza una plantilla para un post.
	 *
	 * @param string   $template Plantilla con marcadores {clave}.
	 * @param \WP_Post $post     Post.
	 * @param array    $extra    Marcadores adicionales clave => valor.
	 * @return string
	 */
	public function render( string $template, \WP_Post $post, array $extra = array() ): string {
		$vars = array_merge( $this->vars( $post ), $extra );

		$out = preg_replace_callback(
			'/\{([a-z_]+)\}/',
			static function ( array $m ) use ( $vars ): string {
				return array_key_exists( $m[1], $vars ) ? (string) $vars[ $m[1] ] : $m[0];
			},
			$template
		);

		return trim( (string) $out );
	}

	/**
	 * Caption final de un post para un canal.
	 *
	 * @param Channel  $channel Canal.
	 * @param \WP_Post $post    Post.
	 * @return string
	 */
	public function caption( Channel $channel, \WP_Post $post ): string {
		$text = $this->render( (string) $this->settings->get( 'templates.caption', $channel->settings ), $post );

		/**
		 * Permite modificar el caption antes de publicarlo.
		 *
		 * @param string   $text    Caption.
		 * @param \WP_Post $post    Post.
		 * @param Channel  $channel Canal.
		 */
		$text = (string) apply_filters( 'voceador_caption', $text, $post, $channel );

		return $this->limit( $text, $channel );
	}

	/**
	 * Comentario final de un post para un canal.
	 *
	 * @param Channel  $channel Canal.
	 * @param \WP_Post $post    Post.
	 * @return string
	 */
	public function comment( Channel $channel, \WP_Post $post ): string {
		$text = $this->render( (string) $this->settings->get( 'templates.comment', $channel->settings ), $post );

		/**
		 * Permite modificar el comentario antes de publicarlo.
		 *
		 * @param string   $text    Comentario.
		 * @param \WP_Post $post    Post.
		 * @param Channel  $channel Canal.
		 */
		$text = (string) apply_filters( 'voceador_comment', $text, $post, $channel );

		return $this->limit( $text, $channel );
	}

	/**
	 * Recorta un texto en un límite de caracteres, en el último espacio si puede.
	 *
	 * @param string $text Texto.
	 * @param int    $max  Longitud máxima incluyendo la elipsis.
	 * @return string
	 */
	public static function truncate( string $text, int $max ): string {
		if ( $max <= 0 || mb_strlen( $text ) <= $max ) {
			return $text;
		}

		$cut   = mb_substr( $text, 0, $max - 1 );
		$space = mb_strrpos( $cut, ' ' );

		if ( false !== $space && $space > 0 ) {
			$cut = mb_substr( $cut, 0, $space );
		}

		return rtrim( $cut ) . '…';
	}

	/**
	 * Aplica el límite de longitud del canal.
	 *
	 * @param string  $text    Texto.
	 * @param Channel $channel Canal.
	 * @return string
	 */
	private function limit( string $text, Channel $channel ): string {
		return self::truncate( $text, (int) $this->settings->get( 'templates.max_length', $channel->settings ) );
	}

	/**
	 * Valores de los marcadores de un post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, string>
	 */
	private function vars( \WP_Post $post ): array {
		$categories = array_map(
			static fn( \WP_Term $t ) => $t->name,
			array_filter( (array) get_the_category( $post->ID ), static fn( $t ) => $t instanceof \WP_Term )
		);

		return array(
			'title'          => self::clean( get_the_title( $post ) ),
			'excerpt'        => self::clean( $this->excerpt( $post ) ),
			'permalink'      => (string) get_permalink( $post ),
			'shortlink'      => (string) wp_get_shortlink( $post->ID ),
			'site_name'      => self::clean( get_bloginfo( 'name' ) ),
			'author'         => self::clean( (string) get_the_author_meta( 'display_name', (int) $post->post_author ) ),
			'category'       => self::clean( (string) ( $categories[0] ?? '' ) ),
			'categories'     => self::clean( implode( ', ', $categories ) ),
			'date'           => get_the_date( 'd/m/Y', $post ),
			'social_message' => self::clean( $this->social_message( $post ) ),
		);
	}

	/**
	 * Extracto del post sin pasar por los filtros de tema.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function excerpt( \WP_Post $post ): string {
		if ( '' !== trim( $post->post_excerpt ) ) {
			return $post->post_excerpt;
		}

		return wp_trim_words( $post->post_content, 40, '…' );
	}

	/**
	 * Primer valor no vacío de la cadena de fallback del texto social.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function social_message( \WP_Post $post ): string {
		$chain = (array) $this->settings->get( 'templates.social_fallback' );

		foreach ( $chain as $source ) {
			$value = match ( (string) $source ) {
				'message' => (string) get_post_meta( $post->ID, '_voceador_message_fb', true ),
				'excerpt' => $this->excerpt( $post ),
				'title'   => get_the_title( $post ),
				default   => '',
			};

			if ( '' !== trim( self::clean( $value ) ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Limpia HTML, shortcodes y entidades.
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	private static function clean( string $text ): string {
		// strip_shortcodes() solo quita los registrados; el regex cubre los que no lo estén.
		$text = (string) preg_replace( '/\[\/?[a-zA-Z0-9_-]+[^\]]*\]/', '', strip_shortcodes( $text ) );
		$text = wp_strip_all_tags( $text, true );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/[ \t]+/', ' ', $text );

		return trim( $text );
	}
}
```

Nota: `wp_strip_all_tags( $text, true )` colapsa saltos de línea; la plantilla de caption por defecto conserva sus `\n` porque la sustitución ocurre después de limpiar cada valor, no sobre la plantilla.

- [ ] **Step 5: Registrar la factory**

En `Plugin::register_factories()`:

```php
		$this->factories[ Templates::class ] = static function ( Plugin $c ): Templates {
			return new Templates( $c->get( Settings::class ) );
		};
```

- [ ] **Step 6: Tests y lint**

Run: `npm run test -- --filter 'TemplatesTest|SettingsTest' && npm run lint`
Expected: `OK`; PHPCS sin errores. Si `{category}` no coincide por el orden que devuelve `get_the_category()` en tu WordPress, ajusta la aserción al orden real observado y documenta el cambio.

- [ ] **Step 7: Commit**

```bash
git add src/Templates.php src/Settings.php src/Plugin.php tests/phpunit/TemplatesTest.php
git commit -m "feat: plantillas de caption y comentario con marcadores y limpieza"
```

---

### Task 3: `FacebookPageAdapter`

**Files:**
- Create: `src/Channels/FacebookPageAdapter.php`, `tests/phpunit/Fixtures/GraphResponses.php`
- Modify: `src/Plugin.php` (registrar el tipo en la factory de `ChannelRegistry`)
- Test: `tests/phpunit/FacebookPageAdapterTest.php`

**Interfaces:**
- Consumes: `GraphClient` (`get`, `post`, `post_multipart`), `Settings::get( path, $channel->settings )`, `Channels\*` (fase 1a), filtro `voceador_image`.
- Produces:
  - `new FacebookPageAdapter( GraphClient $graph, Settings $settings )`; `type()` = `'facebook_page'`; `label()` = `'Página de Facebook'`.
  - `validate_credentials( Channel $c ): HealthReport` — `GET me?fields=id,name` con el token del canal. Válido si responde `id`; si el canal tiene `remote_id` y no coincide, inválido con mensaje "El token pertenece a otra Página". `raw` lleva la respuesta. `scopes` vacío (los permisos reales llegan con `debug_token` en la fase 2).
  - `prepare_media( Channel $c, \WP_Post $p ): Media|\WP_Error` — imagen destacada del post en el tamaño `image.fb_size` (default `large`); si el archivo pesa más de `FacebookPageAdapter::MAX_BYTES` (4 MB) prueba, en orden, `large`, `medium_large`, `medium`; si ninguno cabe, `WP_Error( 'media' )`. Formatos aceptados `image/jpeg`, `image/png`, `image/gif`, `image/bmp`, `image/tiff`; otro → `WP_Error( 'media' )`. Sin destacada → `new Media()` (vacío). Filtro `voceador_image( Media $media, \WP_Post $post, Channel $channel )`.
  - `publish( Channel $c, Payload $payload ): RemoteResult|\WP_Error` — con imagen: `POST {page}/photos` con `caption` y `source` (multipart, si `image.fb_mode` = `file` y hay `path`) o `url`; `RemoteResult( id = post_id, url = https://www.facebook.com/{post_id} )`; si la respuesta no trae `post_id` usa `id`. Sin imagen: `POST {page}/feed` con `message` y `link`.
  - `comment( Channel $c, string $remote_id, string $text ): RemoteResult|\WP_Error` — `POST {remote_id}/comments` con `message`.
  - `status( Channel $c, string $remote_id ): RemoteStatus` — `GET {remote_id}?fields=id,permalink_url`.
  - `usage_limits()` → `null`. `settings_schema()` → campos `image.fb_size`, `image.fb_mode`, `image.no_image`.
  - Fixture `Voceador\Tests\Fixtures\GraphResponses` con métodos estáticos `ok( array $body, array $headers = array() )`, `error( int $http, int $code, string $message = 'boom' )`, `transport( string $message )` que devuelven lo que espera `pre_http_request`.

- [ ] **Step 1: Crear la fixture**

`tests/phpunit/Fixtures/GraphResponses.php`:

```php
<?php
/**
 * @package Voceador
 */

namespace Voceador\Tests\Fixtures;

class GraphResponses {

	public static function ok( array $body, array $headers = array() ): array {
		return array(
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'headers'  => $headers,
			'body'     => wp_json_encode( $body ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	public static function error( int $http, int $code, string $message = 'boom', int $subcode = 0 ): array {
		return array(
			'response' => array( 'code' => $http, 'message' => '' ),
			'headers'  => array(),
			'body'     => wp_json_encode( array( 'error' => array( 'message' => $message, 'type' => 'OAuthException', 'code' => $code, 'error_subcode' => $subcode, 'fbtrace_id' => 'trace' ) ) ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	public static function transport( string $message = 'cURL error 28: timeout' ): \WP_Error {
		return new \WP_Error( 'http_request_failed', $message );
	}
}
```

Y en `tests/bootstrap.php`, junto al `require` de `FakeAdapter.php`, añade `require $voceador_root . '/tests/phpunit/Fixtures/GraphResponses.php';`.

- [ ] **Step 2: Escribir el test**

`tests/phpunit/FacebookPageAdapterTest.php`:

```php
<?php
/**
 * @package Voceador
 */

use Voceador\Channels\Channel;
use Voceador\Channels\FacebookPageAdapter;
use Voceador\Channels\Media;
use Voceador\Channels\Payload;
use Voceador\GraphClient;
use Voceador\Logger;
use Voceador\Schema;
use Voceador\Settings;
use Voceador\Tests\Fixtures\GraphResponses;

class FacebookPageAdapterTest extends WP_UnitTestCase {

	private FacebookPageAdapter $adapter;
	private array $requests = array();
	private array $responses = array();

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		delete_option( VOCEADOR_PREFIX . Settings::OPTION );
		$settings      = new Settings();
		$this->adapter = new FacebookPageAdapter( new GraphClient( $settings, new Logger( $wpdb, new Schema( $wpdb ), 'debug' ) ), $settings );
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
		return array_shift( $this->responses ) ?? GraphResponses::ok( array( 'id' => '0' ) );
	}

	private function channel( array $settings = array(), string $remote_id = '1001' ): Channel {
		return new Channel( array( 'id' => 1, 'type' => 'facebook_page', 'remote_id' => $remote_id, 'credentials' => array( 'access_token' => 'EAAtoken' ), 'settings' => $settings ) );
	}

	private function post_with_image( string $file = 'test-image.jpg' ): WP_Post {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$attach  = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/' . $file, $post_id );
		set_post_thumbnail( $post_id, $attach );
		return get_post( $post_id );
	}

	public function test_type_and_label(): void {
		$this->assertSame( 'facebook_page', FacebookPageAdapter::type() );
		$this->assertSame( 'Página de Facebook', FacebookPageAdapter::label() );
		$this->assertNull( $this->adapter->usage_limits( $this->channel() ) );
		$this->assertArrayHasKey( 'image.fb_size', $this->adapter->settings_schema() );
	}

	public function test_validate_credentials_ok_and_mismatch(): void {
		$this->responses[] = GraphResponses::ok( array( 'id' => '1001', 'name' => 'Mi Página' ) );
		$report = $this->adapter->validate_credentials( $this->channel() );
		$this->assertTrue( $report->valid );
		$this->assertSame( 'Mi Página', $report->raw['name'] );
		$this->assertStringContainsString( '/me?fields=id%2Cname', $this->requests[0]['url'] );
		$this->assertSame( 'Bearer EAAtoken', $this->requests[0]['args']['headers']['Authorization'] );

		$this->responses[] = GraphResponses::ok( array( 'id' => '2002', 'name' => 'Otra' ) );
		$report = $this->adapter->validate_credentials( $this->channel() );
		$this->assertFalse( $report->valid );
		$this->assertStringContainsString( 'otra Página', $report->message );

		$this->responses[] = GraphResponses::error( 400, 190, 'Invalid OAuth access token' );
		$report = $this->adapter->validate_credentials( $this->channel() );
		$this->assertFalse( $report->valid );
		$this->assertSame( 'Invalid OAuth access token', $report->message );
	}

	public function test_prepare_media_returns_featured_image_file_and_url(): void {
		$post  = $this->post_with_image();
		$media = $this->adapter->prepare_media( $this->channel(), $post );

		$this->assertInstanceOf( Media::class, $media );
		$this->assertFalse( $media->is_empty() );
		$this->assertFileExists( $media->path );
		$this->assertStringStartsWith( 'http', $media->url );
		$this->assertSame( 'image/jpeg', $media->mime );
		$this->assertGreaterThan( 0, $media->bytes );
	}

	public function test_prepare_media_without_thumbnail_is_empty(): void {
		$post  = get_post( self::factory()->post->create() );
		$media = $this->adapter->prepare_media( $this->channel(), $post );
		$this->assertTrue( $media->is_empty() );
	}

	public function test_prepare_media_rejects_unsupported_mime(): void {
		$post  = $this->post_with_image( 'test-image.webp' );
		$media = $this->adapter->prepare_media( $this->channel(), $post );
		$this->assertInstanceOf( WP_Error::class, $media );
		$this->assertSame( 'media', $media->get_error_code() );
	}

	public function test_prepare_media_falls_back_to_smaller_size_when_too_big(): void {
		add_filter( 'voceador_fb_max_bytes', static fn() => 1 );
		$post  = $this->post_with_image();
		$media = $this->adapter->prepare_media( $this->channel(), $post );
		$this->assertInstanceOf( WP_Error::class, $media, 'Con 1 byte de límite ningún tamaño cabe.' );
		$this->assertSame( 'media', $media->get_error_code() );
	}

	public function test_image_filter(): void {
		add_filter( 'voceador_image', static fn( Media $m ) => new Media( '', 'https://cdn.example/x.jpg' ), 10, 1 );
		$media = $this->adapter->prepare_media( $this->channel(), $this->post_with_image() );
		$this->assertSame( 'https://cdn.example/x.jpg', $media->url );
	}

	public function test_publish_photo_multipart_uses_post_id(): void {
		$media = $this->adapter->prepare_media( $this->channel(), $this->post_with_image() );
		$this->requests = array();
		$this->responses[] = GraphResponses::ok( array( 'id' => '777', 'post_id' => '1001_777' ) );

		$result = $this->adapter->publish( $this->channel(), new Payload( 'Hola', $media, 'https://x/nota' ) );

		$this->assertSame( '1001_777', $result->id );
		$this->assertSame( 'https://www.facebook.com/1001_777', $result->url );
		$this->assertStringEndsWith( '/1001/photos', $this->requests[0]['url'] );
		$this->assertStringStartsWith( 'multipart/form-data', $this->requests[0]['args']['headers']['Content-Type'] );
		$this->assertStringContainsString( 'name="caption"', $this->requests[0]['args']['body'] );
		$this->assertStringContainsString( 'name="source"', $this->requests[0]['args']['body'] );
	}

	public function test_publish_photo_by_url_when_configured(): void {
		$media = new Media( '/no/importa.jpg', 'https://site/img.jpg' );
		$this->responses[] = GraphResponses::ok( array( 'id' => '778' ) );

		$result = $this->adapter->publish( $this->channel( array( 'image' => array( 'fb_mode' => 'url' ) ) ), new Payload( 'Hola', $media ) );

		$this->assertSame( '778', $result->id, 'Sin post_id usa id.' );
		$this->assertSame( array( 'caption' => 'Hola', 'url' => 'https://site/img.jpg' ), $this->requests[0]['args']['body'] );
	}

	public function test_publish_link_to_feed_without_media(): void {
		$this->responses[] = GraphResponses::ok( array( 'id' => '1001_900' ) );

		$result = $this->adapter->publish( $this->channel(), new Payload( 'Hola', null, 'https://x/nota' ) );

		$this->assertSame( '1001_900', $result->id );
		$this->assertStringEndsWith( '/1001/feed', $this->requests[0]['url'] );
		$this->assertSame( array( 'message' => 'Hola', 'link' => 'https://x/nota' ), $this->requests[0]['args']['body'] );
	}

	public function test_publish_propagates_graph_errors(): void {
		$this->responses[] = GraphResponses::error( 403, 200, 'Permissions error' );
		$result = $this->adapter->publish( $this->channel(), new Payload( 'Hola', null, 'https://x' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'permission', $result->get_error_code() );
	}

	public function test_comment_and_status(): void {
		$this->responses[] = GraphResponses::ok( array( 'id' => '1001_777_1' ) );
		$result = $this->adapter->comment( $this->channel(), '1001_777', 'Enlace: https://x' );
		$this->assertSame( '1001_777_1', $result->id );
		$this->assertStringEndsWith( '/1001_777/comments', $this->requests[0]['url'] );
		$this->assertSame( array( 'message' => 'Enlace: https://x' ), $this->requests[0]['args']['body'] );

		$this->responses[] = GraphResponses::ok( array( 'id' => '1001_777', 'permalink_url' => 'https://www.facebook.com/p' ) );
		$status = $this->adapter->status( $this->channel(), '1001_777' );
		$this->assertTrue( $status->exists );
		$this->assertSame( 'https://www.facebook.com/p', $status->url );

		$this->responses[] = GraphResponses::error( 404, 100, 'Unsupported get request' );
		$this->assertFalse( $this->adapter->status( $this->channel(), 'gone' )->exists );
	}
}
```

Nota: `DIR_TESTDATA` apunta a `/wordpress-phpunit/data` en wp-env e incluye `images/test-image.jpg` y `images/test-image.webp`. `create_upload_object()` copia el archivo a uploads y genera los tamaños intermedios.

- [ ] **Step 3: Ejecutar y verificar que falla**

Run: `npm run test -- --filter FacebookPageAdapterTest`
Expected: FAIL con `Class "Voceador\Channels\FacebookPageAdapter" not found`.

- [ ] **Step 4: Crear `src/Channels/FacebookPageAdapter.php`**

```php
<?php
/**
 * Adaptador de Páginas de Facebook.
 *
 * @package Voceador
 */

namespace Voceador\Channels;

use Voceador\GraphClient;
use Voceador\Settings;

/**
 * Publica fotos (o enlaces) y comentarios en una Página con su Page Access Token.
 */
final class FacebookPageAdapter implements ChannelAdapter {

	/**
	 * Peso máximo que acepta Graph para una foto.
	 */
	public const MAX_BYTES = 4 * 1024 * 1024;

	/**
	 * Formatos que acepta Graph.
	 */
	public const MIMES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/tiff' );

	/**
	 * Tamaños registrados que se prueban, en orden, cuando la imagen pesa demasiado.
	 */
	private const FALLBACK_SIZES = array( 'large', 'medium_large', 'medium' );

	/**
	 * Constructor.
	 *
	 * @param GraphClient $graph    Cliente de Graph.
	 * @param Settings    $settings Ajustes.
	 */
	public function __construct(
		private GraphClient $graph,
		private Settings $settings
	) {}

	/**
	 * Identificador del tipo.
	 *
	 * @return string
	 */
	public static function type(): string {
		return 'facebook_page';
	}

	/**
	 * Etiqueta para la interfaz.
	 *
	 * @return string
	 */
	public static function label(): string {
		return __( 'Página de Facebook', 'voceador' );
	}

	/**
	 * Comprueba el token contra /me.
	 *
	 * @param Channel $c Canal.
	 * @return HealthReport
	 */
	public function validate_credentials( Channel $c ): HealthReport {
		$me = $this->graph->get( 'me', array( 'fields' => 'id,name' ), (string) $c->credential( 'access_token' ) );

		if ( is_wp_error( $me ) ) {
			return new HealthReport( false, $me->get_error_message(), array(), array(), null, (array) $me->get_error_data() );
		}

		$id = (string) ( $me['id'] ?? '' );

		if ( '' === $id ) {
			return new HealthReport( false, __( 'La respuesta de Graph no incluye un id de Página.', 'voceador' ), array(), array(), null, $me );
		}

		if ( '' !== $c->remote_id && $id !== $c->remote_id ) {
			return new HealthReport( false, __( 'El token pertenece a otra Página.', 'voceador' ), array(), array(), null, $me );
		}

		return new HealthReport( true, (string) ( $me['name'] ?? '' ), array(), array(), null, $me );
	}

	/**
	 * Imagen destacada en el tamaño configurado, dentro del peso máximo.
	 *
	 * @param Channel  $c Canal.
	 * @param \WP_Post $p Post.
	 * @return Media|\WP_Error Media vacío si no hay destacada.
	 */
	public function prepare_media( Channel $c, \WP_Post $p ): Media|\WP_Error {
		$attachment_id = (int) get_post_thumbnail_id( $p );

		if ( $attachment_id <= 0 ) {
			return $this->filter_image( new Media(), $p, $c );
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, self::MIMES, true ) ) {
			return new \WP_Error( 'media', sprintf( /* translators: %s: tipo MIME */ __( 'Facebook no acepta imágenes %s; convierte la imagen destacada a JPEG o PNG.', 'voceador' ), $mime ), array( 'class' => 'media' ) );
		}

		/**
		 * Peso máximo de una foto para Facebook, en bytes.
		 *
		 * @param int $max_bytes Bytes.
		 */
		$max_bytes = (int) apply_filters( 'voceador_fb_max_bytes', self::MAX_BYTES );

		$preferred = (string) $this->settings->get( 'image.fb_size', $c->settings );
		$sizes     = array_values( array_unique( array_merge( array( $preferred ), self::FALLBACK_SIZES ) ) );

		foreach ( $sizes as $size ) {
			$media = $this->media_for_size( $attachment_id, $size, $mime );
			if ( null !== $media && $media->bytes <= $max_bytes ) {
				return $this->filter_image( $media, $p, $c );
			}
		}

		return new \WP_Error( 'media', __( 'La imagen destacada pesa más de lo que acepta Facebook en todos los tamaños disponibles.', 'voceador' ), array( 'class' => 'media' ) );
	}

	/**
	 * Publica foto con caption, o enlace en el feed si no hay imagen.
	 *
	 * @param Channel $c       Canal.
	 * @param Payload $payload Contenido.
	 * @return RemoteResult|\WP_Error
	 */
	public function publish( Channel $c, Payload $payload ): RemoteResult|\WP_Error {
		$token = (string) $c->credential( 'access_token' );
		$media = $payload->media;

		if ( null === $media || $media->is_empty() ) {
			$response = $this->graph->post( $c->remote_id . '/feed', array( 'message' => $payload->caption, 'link' => $payload->link ), $token );
		} elseif ( 'url' === (string) $this->settings->get( 'image.fb_mode', $c->settings ) && '' !== $media->url ) {
			$response = $this->graph->post( $c->remote_id . '/photos', array( 'caption' => $payload->caption, 'url' => $media->url ), $token );
		} elseif ( '' !== $media->path ) {
			$response = $this->graph->post_multipart( $c->remote_id . '/photos', array( 'caption' => $payload->caption ), 'source', $media->path, $token );
		} else {
			$response = $this->graph->post( $c->remote_id . '/photos', array( 'caption' => $payload->caption, 'url' => $media->url ), $token );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$id = (string) ( $response['post_id'] ?? $response['id'] ?? '' );

		if ( '' === $id ) {
			return new \WP_Error( 'fatal', __( 'Graph no devolvió el id de la publicación.', 'voceador' ), array( 'class' => 'fatal', 'raw' => $response ) );
		}

		return new RemoteResult( $id, 'https://www.facebook.com/' . rawurlencode( $id ), $response );
	}

	/**
	 * Comenta como la Página.
	 *
	 * @param Channel $c         Canal.
	 * @param string  $remote_id Id de la publicación.
	 * @param string  $text      Texto.
	 * @return RemoteResult|\WP_Error
	 */
	public function comment( Channel $c, string $remote_id, string $text ): RemoteResult|\WP_Error {
		$response = $this->graph->post( $remote_id . '/comments', array( 'message' => $text ), (string) $c->credential( 'access_token' ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return new RemoteResult( (string) ( $response['id'] ?? '' ), '', $response );
	}

	/**
	 * Consulta si la publicación sigue existiendo.
	 *
	 * @param Channel $c         Canal.
	 * @param string  $remote_id Id de la publicación.
	 * @return RemoteStatus
	 */
	public function status( Channel $c, string $remote_id ): RemoteStatus {
		$response = $this->graph->get( $remote_id, array( 'fields' => 'id,permalink_url' ), (string) $c->credential( 'access_token' ) );

		if ( is_wp_error( $response ) ) {
			return new RemoteStatus( false, '', (array) $response->get_error_data() );
		}

		return new RemoteStatus( true, (string) ( $response['permalink_url'] ?? '' ), $response );
	}

	/**
	 * Facebook no expone un límite de publicación consultable.
	 *
	 * @param Channel $c Canal.
	 * @return UsageLimits|null
	 */
	public function usage_limits( Channel $c ): ?UsageLimits { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Firma de la interfaz.
		return null;
	}

	/**
	 * Campos configurables del tipo.
	 *
	 * @return array
	 */
	public function settings_schema(): array {
		return array(
			'image.fb_size'  => array( 'label' => __( 'Tamaño de imagen', 'voceador' ), 'type' => 'image_size' ),
			'image.fb_mode'  => array( 'label' => __( 'Modo de envío', 'voceador' ), 'type' => 'select', 'options' => array( 'file' => __( 'Archivo (multipart)', 'voceador' ), 'url' => __( 'URL pública', 'voceador' ) ) ),
			'image.no_image' => array( 'label' => __( 'Sin imagen destacada', 'voceador' ), 'type' => 'select', 'options' => array( 'skip' => __( 'Omitir', 'voceador' ), 'feed' => __( 'Publicar como enlace', 'voceador' ) ) ),
		);
	}

	/**
	 * Media de un adjunto en un tamaño registrado, o null si no existe ese tamaño.
	 *
	 * @param int    $attachment_id Adjunto.
	 * @param string $size          Tamaño registrado o "full".
	 * @param string $mime          MIME del adjunto.
	 * @return Media|null
	 */
	private function media_for_size( int $attachment_id, string $size, string $mime ): ?Media {
		$src = wp_get_attachment_image_src( $attachment_id, $size );
		if ( ! is_array( $src ) || empty( $src[0] ) ) {
			return null;
		}

		$original = (string) get_attached_file( $attachment_id );
		$path     = $original;

		if ( 'full' !== $size ) {
			$intermediate = image_get_intermediate_size( $attachment_id, $size );
			if ( is_array( $intermediate ) && ! empty( $intermediate['file'] ) ) {
				$path = trailingslashit( dirname( $original ) ) . wp_basename( $intermediate['file'] );
			}
		}

		if ( '' === $path || ! is_readable( $path ) ) {
			return null;
		}

		return new Media( $path, (string) $src[0], $mime, (int) filesize( $path ) );
	}

	/**
	 * Aplica el filtro voceador_image.
	 *
	 * @param Media    $media   Imagen.
	 * @param \WP_Post $post    Post.
	 * @param Channel  $channel Canal.
	 * @return Media|\WP_Error
	 */
	private function filter_image( Media $media, \WP_Post $post, Channel $channel ): Media|\WP_Error {
		/**
		 * Permite sustituir la imagen que se publica.
		 *
		 * @param Media    $media   Imagen preparada (vacía si no hay destacada).
		 * @param \WP_Post $post    Post.
		 * @param Channel  $channel Canal.
		 */
		$filtered = apply_filters( 'voceador_image', $media, $post, $channel );

		if ( $filtered instanceof Media || is_wp_error( $filtered ) ) {
			return $filtered;
		}

		return $media;
	}
}
```

- [ ] **Step 5: Registrar el tipo en el contenedor**

En `Plugin::register_factories()` sustituye la factory de `Channels\ChannelRegistry` por:

```php
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
```

Y en `PluginTest::test_every_phase_1a_service_resolves` añade `\Voceador\Channels\FacebookPageAdapter::class` a la lista, más una aserción: `$this->assertTrue( $plugin->get( \Voceador\Channels\ChannelRegistry::class )->has( 'facebook_page' ) );`.

- [ ] **Step 6: Tests y lint**

Run: `npm run test -- --filter 'FacebookPageAdapterTest|PluginTest' && npm run lint`
Expected: `OK (13 tests, ...)` para el adaptador; PHPCS sin errores. Si `test-image.webp` no existe en tu suite de WordPress, sustitúyelo por cualquier archivo no imagen de `DIR_TESTDATA` (p. ej. `images/test-image.psd` o un `.pdf` en `DIR_TESTDATA`) y documenta el cambio.

- [ ] **Step 7: Commit**

```bash
git add src/Channels/FacebookPageAdapter.php src/Plugin.php tests/phpunit/Fixtures/GraphResponses.php tests/phpunit/FacebookPageAdapterTest.php tests/phpunit/PluginTest.php tests/bootstrap.php
git commit -m "feat: adaptador de Páginas de Facebook con fotos, enlaces y comentarios"
```

---

### Task 4: `Queue`

**Files:**
- Create: `src/Queue.php`
- Modify: `src/Plugin.php` (factory + `REGISTRABLES`), `src/Uninstaller.php` (`CRON_HOOKS` += `sweep`), `docs/superpowers/specs/2026-09-21-voceador-design.md` (cron `voceador_sweep` en "Capacidad, cron y desinstalación")
- Test: `tests/phpunit/QueueTest.php`

**Interfaces:**
- Consumes: `JobRepository::due()`, `due_rate_limited()`, `stale_running()`, `mark_unverified()`; `Logger`.
- Produces:
  - `Queue implements Registrable`; `new Queue( JobRepository $jobs, Logger $logger )`.
  - Hooks de ejecución: `Queue::HOOK_RUN = 'voceador_run_job'`, `Queue::HOOK_COMMENT = 'voceador_run_comment'`, `Queue::HOOK_SWEEP = 'voceador_sweep'`; el `Publisher` (Task 5) engancha `run()`/`run_comment()` a los dos primeros.
  - `schedule_job( int $job_id, int $delay_seconds ): void` y `schedule_comment( int $job_id, int $delay_seconds ): void` — Action Scheduler (`as_schedule_single_action`, grupo `voceador`) si existe; si no, `wp_schedule_single_event`. Antes de programar, cancela una programación previa del mismo trabajo (`as_unschedule_action` / `wp_clear_scheduled_hook` con los mismos args).
  - `uses_action_scheduler(): bool`; `cron_available(): bool` (AS presente, o WP-Cron no deshabilitado por `DISABLE_WP_CRON`, o `ALTERNATE_WP_CRON`).
  - `sweep(): array` — reprograma `due()` y `due_rate_limited()` con retraso 0; los `stale_running( 900 )` pasan a `mark_unverified()`; devuelve conteos `array( 'pending' => n, 'rate_limited' => n, 'stale' => n )`. Se engancha a `HOOK_SWEEP` y este se programa `hourly` en `init` si no está programado.
  - `register_hooks()`: engancha `sweep` a `HOOK_SWEEP`, programa el evento horario, y muestra un `admin_notice` (solo a usuarios con `voceador_manage`) si `! cron_available()`.
  - Filtro `voceador_queue_delay( int $delay, int $job_id, string $hook )`.

- [ ] **Step 1: Escribir el test**

`tests/phpunit/QueueTest.php`:

```php
<?php
/**
 * @package Voceador
 */

use Voceador\JobRepository;
use Voceador\Logger;
use Voceador\Queue;
use Voceador\Schema;

class QueueTest extends WP_UnitTestCase {

	private Queue $queue;
	private JobRepository $jobs;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->jobs  = new JobRepository( $wpdb, new Schema( $wpdb ) );
		$this->queue = new Queue( $this->jobs, new Logger( $wpdb, new Schema( $wpdb ), 'debug' ) );
		_set_cron_array( array() );
	}

	public function test_hooks_constants(): void {
		$this->assertSame( 'voceador_run_job', Queue::HOOK_RUN );
		$this->assertSame( 'voceador_run_comment', Queue::HOOK_COMMENT );
		$this->assertSame( 'voceador_sweep', Queue::HOOK_SWEEP );
	}

	public function test_schedule_job_with_wp_cron(): void {
		$this->assertFalse( $this->queue->uses_action_scheduler(), 'wp-env no trae Action Scheduler.' );

		$this->queue->schedule_job( 42, 30 );

		$ts = wp_next_scheduled( Queue::HOOK_RUN, array( 42 ) );
		$this->assertNotFalse( $ts );
		$this->assertEqualsWithDelta( time() + 30, $ts, 5 );
	}

	public function test_reschedule_replaces_previous_event(): void {
		$this->queue->schedule_job( 42, 300 );
		$this->queue->schedule_job( 42, 10 );

		$this->assertEqualsWithDelta( time() + 10, wp_next_scheduled( Queue::HOOK_RUN, array( 42 ) ), 5 );
		$this->assertCount( 1, array_filter( array_map( static fn( $events ) => isset( $events[ Queue::HOOK_RUN ] ), _get_cron_array() ) ), 'Un solo evento para el trabajo.' );
	}

	public function test_schedule_comment_and_delay_filter(): void {
		add_filter( 'voceador_queue_delay', static fn( int $delay, int $job_id, string $hook ) => Queue::HOOK_COMMENT === $hook ? 5 : $delay, 10, 3 );

		$this->queue->schedule_comment( 7, 60 );

		$this->assertEqualsWithDelta( time() + 5, wp_next_scheduled( Queue::HOOK_COMMENT, array( 7 ) ), 5 );
	}

	public function test_cron_available_by_default(): void {
		$this->assertTrue( $this->queue->cron_available() );
	}

	public function test_register_hooks_schedules_sweep(): void {
		$this->queue->register_hooks();
		do_action( 'init' );

		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK_SWEEP ) );
		$this->assertSame( 10, has_action( Queue::HOOK_SWEEP, array( $this->queue, 'sweep' ) ) );
	}

	public function test_sweep_reschedules_due_and_marks_stale(): void {
		global $wpdb;
		$pending = $this->jobs->create_if_absent( 50, 1, 'auto', '2020-01-01 00:00:00' );
		$limited = $this->jobs->create_if_absent( 50, 2 );
		$this->jobs->mark_rate_limited( $limited, '2020-01-01 00:00:00', 'x' );
		$stale = $this->jobs->create_if_absent( 50, 3 );
		$this->jobs->claim( $stale );
		$wpdb->update( ( new Schema( $wpdb ) )->table( 'jobs' ), array( 'updated_at' => '2020-01-01 00:00:00' ), array( 'id' => $stale ) );
		$fresh = $this->jobs->create_if_absent( 50, 4 );
		$this->jobs->claim( $fresh );

		$counts = $this->queue->sweep();

		$this->assertSame( array( 'pending' => 1, 'rate_limited' => 1, 'stale' => 1 ), $counts );
		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK_RUN, array( $pending ) ) );
		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK_RUN, array( $limited ) ) );
		$this->assertSame( 'unverified', $this->jobs->find( $stale )->error_code );
		$this->assertSame( 'running', $this->jobs->find( $fresh )->status );
	}
}
```

- [ ] **Step 2: Ejecutar y verificar que falla**

Run: `npm run test -- --filter QueueTest`
Expected: FAIL con `Class "Voceador\Queue" not found`.

- [ ] **Step 3: Crear `src/Queue.php`**

```php
<?php
/**
 * Cola de trabajos.
 *
 * @package Voceador
 */

namespace Voceador;

/**
 * Programa la ejecución de trabajos y comentarios, y barre los perdidos.
 *
 * Usa Action Scheduler si otro plugin lo provee; si no, WP-Cron.
 */
final class Queue implements Registrable {

	/**
	 * Hook que ejecuta un trabajo. Recibe el id del trabajo.
	 */
	public const HOOK_RUN = 'voceador_run_job';

	/**
	 * Hook que publica el comentario de un trabajo. Recibe el id del trabajo.
	 */
	public const HOOK_COMMENT = 'voceador_run_comment';

	/**
	 * Hook del barrido periódico.
	 */
	public const HOOK_SWEEP = 'voceador_sweep';

	/**
	 * Grupo de Action Scheduler.
	 */
	private const AS_GROUP = 'voceador';

	/**
	 * Segundos en running tras los que un trabajo se considera perdido.
	 */
	private const STALE_AFTER = 900;

	/**
	 * Constructor.
	 *
	 * @param JobRepository $jobs   Trabajos.
	 * @param Logger        $logger Log.
	 */
	public function __construct(
		private JobRepository $jobs,
		private Logger $logger
	) {}

	/**
	 * Engancha el barrido y avisa si no hay cron.
	 */
	public function register_hooks(): void {
		add_action( self::HOOK_SWEEP, array( $this, 'sweep' ) );
		add_action( 'init', array( $this, 'ensure_sweep_scheduled' ) );
		add_action( 'admin_notices', array( $this, 'maybe_notice_no_cron' ) );
	}

	/**
	 * Programa el barrido horario si no existe.
	 */
	public function ensure_sweep_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK_SWEEP ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK_SWEEP );
		}
	}

	/**
	 * Aviso en el admin cuando ni WP-Cron ni Action Scheduler pueden ejecutar la cola.
	 */
	public function maybe_notice_no_cron(): void {
		if ( $this->cron_available() || ! current_user_can( Installer::CAPABILITY ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Voceador: WP-Cron está deshabilitado (DISABLE_WP_CRON) y no hay Action Scheduler. Configura un cron del sistema que llame a wp-cron.php o instala un plugin que provea Action Scheduler; si no, las publicaciones no se ejecutarán.', 'voceador' ) . '</p></div>';
	}

	/**
	 * Indica si Action Scheduler está disponible.
	 *
	 * @return bool
	 */
	public function uses_action_scheduler(): bool {
		return function_exists( 'as_schedule_single_action' ) && function_exists( 'as_unschedule_action' );
	}

	/**
	 * Indica si algo va a ejecutar la cola.
	 *
	 * @return bool
	 */
	public function cron_available(): bool {
		if ( $this->uses_action_scheduler() ) {
			return true;
		}
		if ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) {
			return true;
		}

		return ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
	}

	/**
	 * Programa la ejecución de un trabajo.
	 *
	 * @param int $job_id        Id.
	 * @param int $delay_seconds Retraso.
	 */
	public function schedule_job( int $job_id, int $delay_seconds ): void {
		$this->schedule( self::HOOK_RUN, $job_id, $delay_seconds );
	}

	/**
	 * Programa el comentario de un trabajo.
	 *
	 * @param int $job_id        Id.
	 * @param int $delay_seconds Retraso.
	 */
	public function schedule_comment( int $job_id, int $delay_seconds ): void {
		$this->schedule( self::HOOK_COMMENT, $job_id, $delay_seconds );
	}

	/**
	 * Reprograma trabajos vencidos y marca como no verificados los atascados.
	 *
	 * @return array{pending:int,rate_limited:int,stale:int}
	 */
	public function sweep(): array {
		$counts = array( 'pending' => 0, 'rate_limited' => 0, 'stale' => 0 );

		foreach ( $this->jobs->due() as $job ) {
			$this->schedule_job( $job->id, 0 );
			++$counts['pending'];
		}

		foreach ( $this->jobs->due_rate_limited() as $job ) {
			$this->schedule_job( $job->id, 0 );
			++$counts['rate_limited'];
		}

		foreach ( $this->jobs->stale_running( self::STALE_AFTER ) as $job ) {
			$this->jobs->mark_unverified( $job->id, __( 'El proceso se interrumpió durante la publicación. Verifica en la red antes de reintentar.', 'voceador' ) );
			$this->logger->warning( 'job_stale', 'Trabajo atascado en running marcado como no verificado', array( 'job_id' => $job->id, 'post_id' => $job->post_id, 'channel_id' => $job->channel_id ) );
			++$counts['stale'];
		}

		if ( array_sum( $counts ) > 0 ) {
			$this->logger->info( 'sweep', 'Barrido de la cola', $counts );
		}

		return $counts;
	}

	/**
	 * Programa (o reprograma) un hook para un trabajo.
	 *
	 * @param string $hook          Hook.
	 * @param int    $job_id        Id.
	 * @param int    $delay_seconds Retraso.
	 */
	private function schedule( string $hook, int $job_id, int $delay_seconds ): void {
		/**
		 * Permite ajustar el retraso de un trabajo en la cola.
		 *
		 * @param int    $delay_seconds Retraso.
		 * @param int    $job_id        Id del trabajo.
		 * @param string $hook          Hook a ejecutar.
		 */
		$delay = max( 0, (int) apply_filters( 'voceador_queue_delay', $delay_seconds, $job_id, $hook ) );
		$when  = time() + $delay;
		$args  = array( $job_id );

		if ( $this->uses_action_scheduler() ) {
			as_unschedule_action( $hook, $args, self::AS_GROUP );
			as_schedule_single_action( $when, $hook, $args, self::AS_GROUP );
			return;
		}

		wp_clear_scheduled_hook( $hook, $args );
		wp_schedule_single_event( $when, $hook, $args );
	}
}
```

- [ ] **Step 4: Contenedor, desinstalación y spec**

En `Plugin`: añade `Queue::class` a `REGISTRABLES` y la factory:

```php
		$this->factories[ Queue::class ] = static function ( Plugin $c ): Queue {
			return new Queue( $c->get( JobRepository::class ), $c->get( Logger::class ) );
		};
```

En `Uninstaller::CRON_HOOKS` añade `'sweep'`. En el spec, sección "Capacidad, cron y desinstalación", añade `voceador_sweep` (horario: reprograma trabajos vencidos y marca como `unverified` los atascados en `running`).

- [ ] **Step 5: Tests y lint**

Run: `npm run test -- --filter 'QueueTest|UninstallerTest|PluginTest' && npm run lint`
Expected: `OK`; PHPCS sin errores.

- [ ] **Step 6: Commit**

```bash
git add src/Queue.php src/Plugin.php src/Uninstaller.php docs/superpowers/specs/2026-09-21-voceador-design.md tests/phpunit/QueueTest.php
git commit -m "feat: cola con Action Scheduler o WP-Cron, barrido y aviso sin cron"
```

---

### Task 5: `Publisher`

**Files:**
- Create: `src/Publisher.php`
- Modify: `src/Plugin.php` (factory + `REGISTRABLES`)
- Test: `tests/phpunit/PublisherTest.php`

**Interfaces:**
- Consumes: `JobRepository` (todo), `ChannelRepository::find()/set_status()`, `ChannelRegistry::adapter()`, `Templates::caption()/comment()`, `Settings::get( path, $channel->settings )`, `Logger`, `Queue::schedule_job()/schedule_comment()/HOOK_RUN/HOOK_COMMENT`.
- Produces:
  - `Publisher implements Registrable`; `new Publisher( JobRepository $jobs, ChannelRepository $channels, ChannelRegistry $registry, Templates $templates, Settings $settings, Logger $logger, Queue $queue )`.
  - `register_hooks()`: `add_action( Queue::HOOK_RUN, array( $this, 'run' ) )` y `add_action( Queue::HOOK_COMMENT, array( $this, 'run_comment' ) )`.
  - `run( int $job_id ): string` — devuelve el estado final del trabajo (`published`, `failed`, `rate_limited`, `skipped`, `pending`) o `'locked'`/`'missing'` si no se ejecutó. Secuencia: lock transient `voceador_lock_job_{id}` (TTL `Publisher::LOCK_TTL` = 300) → cargar trabajo, canal y post → abortar si `is_published()` → canal inexistente/no activo → `mark_failed( 'channel_unavailable' )` → post inexistente o no `publish` → `mark_skipped` → `claim()` (si falla, `'locked'`) → `usage_limits()` sin cupo → `mark_rate_limited` + `schedule_job` → `prepare_media()` → sin imagen: `image.no_image` `skip` → `mark_skipped`; `feed` → sigue con `media = null` → `caption`/`comment` → `publish()` → `mark_published( ..., $comment_pending = comment_enabled && comment !== '' )` → `do_action( 'voceador_published', Job, Channel, RemoteResult )` → si hay comentario, `schedule_comment( id, execution.comment_delay )`. Cualquier `WP_Error` pasa por `handle_error()`. El lock se libera siempre (`finally`).
  - `run_comment( int $job_id ): string` — devuelve `done`, `failed`, `pending` (reprogramado) o `skipped`. Requiere `status = published`, `comment_status` `pending` o `failed`; `comment_attempts < execution.retries`; texto de `Templates::comment()`; `comment()` → `mark_comment_done` o `mark_comment_failed`; si el error es `transient`, reprograma con backoff; `spam`/otros no reprograman.
  - `handle_error( Job $job, Channel $channel, \WP_Error $error ): string` — por clase: `transient` → si `attempts < retries`, `release( id, now + backoff )` + `schedule_job` (devuelve `pending`), si no `mark_failed`; `rate_limited` → `mark_rate_limited( id, now + retry_after|3600 )` + `schedule_job`; `auth`/`permission` → `mark_failed` + `channels->set_status( id, 'paused', health )` + log `error` + aviso persistente en la opción `voceador_notices`; `media`/`spam`/`fatal` → `mark_failed`. Siempre `do_action( 'voceador_failed', Job, Channel, \WP_Error )` y log `warning`/`error`.
  - `backoff( int $attempt ): int` — `execution.backoff_base × 2^(attempt-1)`, tope `Publisher::MAX_BACKOFF` = 86400.
  - `publish_now( int $post_id, ?int $channel_id = null, string $source = 'manual' ): array<int,string>` — crea (si no existe) el trabajo de cada canal activo (o del indicado) y llama a `run()` de inmediato; devuelve `channel_id => estado`. Lo usa el CLI (Task 7). Los trabajos ya publicados devuelven `published` sin republicar.
  - Avisos: `Publisher::add_notice( string $key, string $message )` guarda en `voceador_notices` (array `key => array( message, time )`, autoload no); la interfaz de la fase 3 los mostrará.

- [ ] **Step 1: Escribir el test**

`tests/phpunit/PublisherTest.php`:

```php
<?php
/**
 * @package Voceador
 */

use Voceador\ChannelRepository;
use Voceador\Channels\ChannelRegistry;
use Voceador\Channels\FacebookPageAdapter;
use Voceador\Crypto;
use Voceador\GraphClient;
use Voceador\JobRepository;
use Voceador\Logger;
use Voceador\Publisher;
use Voceador\Queue;
use Voceador\Schema;
use Voceador\Settings;
use Voceador\Templates;
use Voceador\Tests\Fixtures\GraphResponses;

class PublisherTest extends WP_UnitTestCase {

	private Publisher $publisher;
	private JobRepository $jobs;
	private ChannelRepository $channels;
	private Settings $settings;
	private array $requests = array();
	private array $responses = array();
	private int $channel_id;
	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		delete_option( VOCEADOR_PREFIX . Settings::OPTION );
		delete_option( VOCEADOR_PREFIX . 'notices' );
		_set_cron_array( array() );

		$schema         = new Schema( $wpdb );
		$logger         = new Logger( $wpdb, $schema, 'debug' );
		$this->settings = new Settings();
		$this->jobs     = new JobRepository( $wpdb, $schema );
		$this->channels = new ChannelRepository( $wpdb, $schema, new Crypto( str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ) );
		$graph          = new GraphClient( $this->settings, $logger );
		$registry       = new ChannelRegistry( array( 'facebook_page' => static fn() => new FacebookPageAdapter( $graph, $this->settings ) ) );
		$queue          = new Queue( $this->jobs, $logger );

		$this->publisher = new Publisher( $this->jobs, $this->channels, $registry, new Templates( $this->settings ), $this->settings, $logger, $queue );

		$this->channel_id = $this->channels->insert( array( 'type' => 'facebook_page', 'alias' => 'P', 'remote_id' => '1001', 'credentials' => array( 'access_token' => 'EAAt' ) ) );
		$this->post_id    = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'Nota', 'post_excerpt' => 'Extracto' ) );
		$attach           = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg', $this->post_id );
		set_post_thumbnail( $this->post_id, $attach );

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
		return array_shift( $this->responses ) ?? GraphResponses::ok( array( 'id' => '0' ) );
	}

	private function job(): int {
		return $this->jobs->create_if_absent( $this->post_id, $this->channel_id );
	}

	public function test_run_publishes_photo_and_schedules_comment(): void {
		$this->responses[] = GraphResponses::ok( array( 'id' => '7', 'post_id' => '1001_7' ) );
		$published = array();
		add_action( 'voceador_published', static function ( $job, $channel, $result ) use ( &$published ) { $published[] = $result->id; }, 10, 3 );

		$id = $this->job();
		$this->assertSame( 'published', $this->publisher->run( $id ) );

		$job = $this->jobs->find( $id );
		$this->assertSame( '1001_7', $job->remote_id );
		$this->assertSame( 'pending', $job->comment_status );
		$this->assertSame( 1, $job->attempts );
		$this->assertSame( array( '1001_7' ), $published );
		$this->assertStringEndsWith( '/1001/photos', $this->requests[0]['url'] );
		$this->assertStringContainsString( "Nota\n\nExtracto", $this->requests[0]['args']['body'], 'Caption por defecto.' );
		$this->assertEqualsWithDelta( time() + 60, wp_next_scheduled( Queue::HOOK_COMMENT, array( $id ) ), 5 );
		$this->assertFalse( get_transient( VOCEADOR_PREFIX . 'lock_job_' . $id ), 'El lock se libera.' );
	}

	public function test_run_is_idempotent_and_respects_lock(): void {
		$this->responses[] = GraphResponses::ok( array( 'id' => '7', 'post_id' => '1001_7' ) );
		$id = $this->job();
		$this->publisher->run( $id );
		$this->requests = array();

		$this->assertSame( 'published', $this->publisher->run( $id ) );
		$this->assertCount( 0, $this->requests, 'No republica.' );

		$other = $this->jobs->create_if_absent( $this->post_id + 1000, $this->channel_id );
		set_transient( VOCEADOR_PREFIX . 'lock_job_' . $other, 1, 60 );
		$this->assertSame( 'locked', $this->publisher->run( $other ) );
	}

	public function test_run_comment_lifecycle(): void {
		$this->responses[] = GraphResponses::ok( array( 'id' => '7', 'post_id' => '1001_7' ) );
		$id = $this->job();
		$this->publisher->run( $id );

		$this->responses[] = GraphResponses::ok( array( 'id' => '1001_7_1' ) );
		$this->assertSame( 'done', $this->publisher->run_comment( $id ) );
		$job = $this->jobs->find( $id );
		$this->assertSame( 'done', $job->comment_status );
		$this->assertSame( '1001_7_1', $job->remote_comment_id );
		$this->assertSame( array( 'message' => get_permalink( $this->post_id ) ), end( $this->requests )['args']['body'] );

		$this->assertSame( 'skipped', $this->publisher->run_comment( $id ), 'Ya comentado.' );
	}

	public function test_run_comment_transient_error_reschedules_and_spam_does_not(): void {
		$this->responses[] = GraphResponses::ok( array( 'id' => '7', 'post_id' => '1001_7' ) );
		$id = $this->job();
		$this->publisher->run( $id );
		_set_cron_array( array() );

		$this->responses[] = GraphResponses::error( 500, 2, 'Service temporarily unavailable' );
		$this->assertSame( 'pending', $this->publisher->run_comment( $id ) );
		$this->assertSame( 'failed', $this->jobs->find( $id )->comment_status, 'Queda failed hasta el reintento.' );
		$this->assertSame( 1, $this->jobs->find( $id )->comment_attempts );
		$this->assertEqualsWithDelta( time() + 120, wp_next_scheduled( Queue::HOOK_COMMENT, array( $id ) ), 5, 'Backoff base 120 × 2^0.' );

		_set_cron_array( array() );
		$this->responses[] = GraphResponses::error( 400, 368, 'Blocked as spam' );
		$this->assertSame( 'failed', $this->publisher->run_comment( $id ) );
		$this->assertFalse( wp_next_scheduled( Queue::HOOK_COMMENT, array( $id ) ) );
		$this->assertSame( 'published', $this->jobs->find( $id )->status, 'El fallo del comentario no toca el estado principal.' );
	}

	public function test_transient_error_releases_with_backoff_until_retries_exhausted(): void {
		$this->settings->update( array( 'execution' => array( 'retries' => 2, 'backoff_base' => 10 ) ) );
		$id = $this->job();

		$this->responses[] = GraphResponses::transport();
		$this->assertSame( 'pending', $this->publisher->run( $id ) );
		$job = $this->jobs->find( $id );
		$this->assertSame( 'pending', $job->status );
		$this->assertSame( 1, $job->attempts );
		$this->assertSame( 'transient', $job->error_code );
		$this->assertEqualsWithDelta( time() + 10, wp_next_scheduled( Queue::HOOK_RUN, array( $id ) ), 5 );

		$this->responses[] = GraphResponses::error( 500, 1, 'Unknown' );
		$this->assertSame( 'failed', $this->publisher->run( $id ), 'Segundo intento agota retries=2.' );
		$this->assertSame( 2, $this->jobs->find( $id )->attempts );
	}

	public function test_auth_error_pauses_channel_and_records_notice(): void {
		$failed = array();
		add_action( 'voceador_failed', static function ( $job, $channel, $error ) use ( &$failed ) { $failed[] = $error->get_error_code(); }, 10, 3 );
		$this->responses[] = GraphResponses::error( 400, 190, 'Error validating access token' );

		$this->assertSame( 'failed', $this->publisher->run( $this->job() ) );

		$channel = $this->channels->find( $this->channel_id );
		$this->assertSame( 'paused', $channel->status );
		$this->assertStringContainsString( 'access token', $channel->health['message'] );
		$this->assertSame( array( 'auth' ), $failed );
		$notices = get_option( VOCEADOR_PREFIX . 'notices' );
		$this->assertArrayHasKey( 'channel_paused_' . $this->channel_id, $notices );
	}

	public function test_paused_channel_fails_without_calling_graph(): void {
		$this->channels->set_status( $this->channel_id, 'paused' );
		$id = $this->job();
		$this->assertSame( 'failed', $this->publisher->run( $id ) );
		$this->assertSame( 'channel_unavailable', $this->jobs->find( $id )->error_code );
		$this->assertCount( 0, $this->requests );
	}

	public function test_rate_limited_error_reschedules_with_retry_after(): void {
		$response = GraphResponses::error( 400, 613, 'Calls to this api have exceeded the rate limit' );
		$response['headers'] = array( 'retry-after' => '90' );
		$this->responses[] = $response;
		$id = $this->job();

		$this->assertSame( 'rate_limited', $this->publisher->run( $id ) );
		$this->assertEqualsWithDelta( time() + 90, wp_next_scheduled( Queue::HOOK_RUN, array( $id ) ), 5 );
	}

	public function test_no_image_skip_and_feed(): void {
		$plain = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'Sin foto' ) );
		$id    = $this->jobs->create_if_absent( $plain, $this->channel_id );

		$this->assertSame( 'skipped', $this->publisher->run( $id ) );
		$this->assertCount( 0, $this->requests );

		$this->settings->update( array( 'image' => array( 'no_image' => 'feed' ) ) );
		$this->responses[] = GraphResponses::ok( array( 'id' => '1001_9' ) );
		$this->jobs->release( $id, current_time( 'mysql', true ) );
		$this->assertSame( 'published', $this->publisher->run( $id ) );
		$this->assertStringEndsWith( '/1001/feed', $this->requests[0]['url'] );
		$this->assertSame( '1001_9', $this->jobs->find( $id )->remote_id );
	}

	public function test_unpublished_post_is_skipped_and_missing_job_reported(): void {
		wp_update_post( array( 'ID' => $this->post_id, 'post_status' => 'draft' ) );
		$id = $this->job();
		$this->assertSame( 'skipped', $this->publisher->run( $id ) );
		$this->assertSame( 'missing', $this->publisher->run( 999999 ) );
	}

	public function test_publish_now_creates_and_runs_jobs(): void {
		$this->responses[] = GraphResponses::ok( array( 'id' => '7', 'post_id' => '1001_7' ) );

		$result = $this->publisher->publish_now( $this->post_id );

		$this->assertSame( array( $this->channel_id => 'published' ), $result );
		$this->assertSame( 'manual', $this->jobs->find_for_post_and_channel( $this->post_id, $this->channel_id )->source );
		$this->assertSame( array( $this->channel_id => 'published' ), $this->publisher->publish_now( $this->post_id ), 'Segunda vez no republica.' );
		$this->assertCount( 1, $this->requests );
	}

	public function test_backoff(): void {
		$this->assertSame( 120, $this->publisher->backoff( 1 ) );
		$this->assertSame( 240, $this->publisher->backoff( 2 ) );
		$this->assertSame( 480, $this->publisher->backoff( 3 ) );
		$this->assertSame( Publisher::MAX_BACKOFF, $this->publisher->backoff( 20 ) );
	}
}
```

Nota sobre `test_no_image_skip_and_feed`: tras `mark_skipped` el trabajo no es reclamable, por eso el test lo devuelve a `pending` con `release()` antes de la segunda ejecución.

- [ ] **Step 2: Ejecutar y verificar que falla**

Run: `npm run test -- --filter PublisherTest`
Expected: FAIL con `Class "Voceador\Publisher" not found`.

- [ ] **Step 3: Crear `src/Publisher.php`**

```php
<?php
/**
 * Ejecución de trabajos de publicación.
 *
 * @package Voceador
 */

namespace Voceador;

use Voceador\Channels\Channel;
use Voceador\Channels\ChannelRegistry;
use Voceador\Channels\Media;
use Voceador\Channels\Payload;

/**
 * Publica un trabajo (post + canal) y su comentario, y aplica la política de errores.
 */
final class Publisher implements Registrable {

	/**
	 * Vida del lock por trabajo, en segundos.
	 */
	public const LOCK_TTL = 300;

	/**
	 * Tope del backoff, en segundos.
	 */
	public const MAX_BACKOFF = 86400;

	/**
	 * Espera por defecto cuando la red no dice cuándo habrá cupo.
	 */
	private const DEFAULT_RATE_LIMIT_WAIT = 3600;

	/**
	 * Constructor.
	 *
	 * @param JobRepository     $jobs      Trabajos.
	 * @param ChannelRepository $channels  Canales.
	 * @param ChannelRegistry   $registry  Adaptadores.
	 * @param Templates         $templates Plantillas.
	 * @param Settings          $settings  Ajustes.
	 * @param Logger            $logger    Log.
	 * @param Queue             $queue     Cola.
	 */
	public function __construct(
		private JobRepository $jobs,
		private ChannelRepository $channels,
		private ChannelRegistry $registry,
		private Templates $templates,
		private Settings $settings,
		private Logger $logger,
		private Queue $queue
	) {}

	/**
	 * Engancha la ejecución a los hooks de la cola.
	 */
	public function register_hooks(): void {
		add_action( Queue::HOOK_RUN, array( $this, 'run' ) );
		add_action( Queue::HOOK_COMMENT, array( $this, 'run_comment' ) );
	}

	/**
	 * Ejecuta un trabajo.
	 *
	 * @param int $job_id Id.
	 * @return string Estado final del trabajo, o "locked" / "missing" si no se ejecutó.
	 */
	public function run( int $job_id ): string {
		$lock = VOCEADOR_PREFIX . 'lock_job_' . $job_id;

		if ( false !== get_transient( $lock ) ) {
			return 'locked';
		}
		set_transient( $lock, time(), self::LOCK_TTL );

		try {
			return $this->execute( $job_id );
		} finally {
			delete_transient( $lock );
		}
	}

	/**
	 * Publica el comentario de un trabajo ya publicado.
	 *
	 * @param int $job_id Id.
	 * @return string done, failed, pending (reprogramado) o skipped.
	 */
	public function run_comment( int $job_id ): string {
		$job = $this->jobs->find( $job_id );
		if ( null === $job || ! $job->is_published() || ! in_array( $job->comment_status, array( 'pending', 'failed' ), true ) ) {
			return 'skipped';
		}

		$channel = $this->channels->find( $job->channel_id );
		$post    = get_post( $job->post_id );
		if ( null === $channel || ! $channel->is_active() || ! $post instanceof \WP_Post ) {
			return 'skipped';
		}

		$retries = (int) $this->settings->get( 'execution.retries', $channel->settings );
		if ( $job->comment_attempts >= $retries ) {
			return 'failed';
		}

		$text = $this->templates->comment( $channel, $post );
		if ( '' === $text ) {
			$this->jobs->mark_comment_done( $job->id, '' );
			return 'skipped';
		}

		$result = $this->registry->adapter( $channel->type )->comment( $channel, (string) $job->remote_id, $text );

		if ( ! is_wp_error( $result ) ) {
			$this->jobs->mark_comment_done( $job->id, $result->id );
			$this->logger->info( 'comment_published', 'Comentario publicado', array( 'job_id' => $job->id, 'post_id' => $job->post_id, 'channel_id' => $channel->id, 'remote_comment_id' => $result->id ) );
			return 'done';
		}

		$this->jobs->mark_comment_failed( $job->id, $result->get_error_message() );
		$this->logger->warning( 'comment_failed', $result->get_error_message(), array( 'job_id' => $job->id, 'post_id' => $job->post_id, 'channel_id' => $channel->id, 'class' => $result->get_error_code() ) );

		if ( 'transient' === $result->get_error_code() && $job->comment_attempts + 1 < $retries ) {
			$this->queue->schedule_comment( $job->id, $this->backoff( $job->comment_attempts + 1 ) );
			return 'pending';
		}

		return 'failed';
	}

	/**
	 * Crea (si hace falta) y ejecuta de inmediato los trabajos de un post.
	 *
	 * @param int      $post_id    Post.
	 * @param int|null $channel_id Canal concreto, o null para todos los activos.
	 * @param string   $source     manual, cli o test.
	 * @return array<int, string> Canal => estado.
	 */
	public function publish_now( int $post_id, ?int $channel_id = null, string $source = 'manual' ): array {
		$targets = null === $channel_id ? $this->channels->active() : array_filter( array( $this->channels->find( $channel_id ) ) );
		$result  = array();

		foreach ( $targets as $channel ) {
			$this->jobs->create_if_absent( $post_id, $channel->id, $source );
			$job = $this->jobs->find_for_post_and_channel( $post_id, $channel->id );

			$result[ $channel->id ] = null === $job ? 'missing' : $this->run( $job->id );
		}

		return $result;
	}

	/**
	 * Segundos de espera antes del intento dado.
	 *
	 * @param int $attempt Número de intento ya realizado (1 = primero).
	 * @return int
	 */
	public function backoff( int $attempt ): int {
		$base = max( 1, (int) $this->settings->get( 'execution.backoff_base' ) );

		return (int) min( self::MAX_BACKOFF, $base * ( 2 ** max( 0, $attempt - 1 ) ) );
	}

	/**
	 * Aplica la política de errores a un trabajo reclamado.
	 *
	 * @param Job       $job     Trabajo (estado previo al error).
	 * @param Channel   $channel Canal.
	 * @param \WP_Error $error   Error normalizado.
	 * @return string Estado resultante.
	 */
	public function handle_error( Job $job, Channel $channel, \WP_Error $error ): string {
		$class    = (string) $error->get_error_code();
		$data     = (array) $error->get_error_data();
		$message  = $error->get_error_message();
		$attempts = $this->jobs->find( $job->id )?->attempts ?? $job->attempts;
		$retries  = (int) $this->settings->get( 'execution.retries', $channel->settings );
		$context  = array( 'job_id' => $job->id, 'post_id' => $job->post_id, 'channel_id' => $channel->id, 'class' => $class, 'graph_code' => $data['graph_code'] ?? 0 );

		switch ( $class ) {
			case 'transient':
				if ( $attempts < $retries ) {
					$delay = $this->backoff( $attempts );
					$this->jobs->mark_failed( $job->id, $class, $message );
					$this->jobs->release( $job->id, gmdate( 'Y-m-d H:i:s', time() + $delay ) );
					$this->queue->schedule_job( $job->id, $delay );
					$status = 'pending';
				} else {
					$this->jobs->mark_failed( $job->id, $class, $message );
					$status = 'failed';
				}
				break;

			case 'rate_limited':
				$wait = (int) ( $data['retry_after'] ?? 0 );
				$wait = $wait > 0 ? $wait : self::DEFAULT_RATE_LIMIT_WAIT;
				$this->jobs->mark_rate_limited( $job->id, gmdate( 'Y-m-d H:i:s', time() + $wait ), $message );
				$this->queue->schedule_job( $job->id, $wait );
				$status = 'rate_limited';
				break;

			case 'auth':
			case 'permission':
				$this->jobs->mark_failed( $job->id, $class, $message );
				$this->channels->set_status( $channel->id, 'paused', array( 'message' => $message, 'class' => $class, 'graph_code' => $data['graph_code'] ?? 0 ) );
				self::add_notice(
					'channel_paused_' . $channel->id,
					sprintf( /* translators: 1: alias del canal, 2: mensaje de error */ __( 'Voceador pausó el canal "%1$s": %2$s. Vuelve a conectarlo desde los ajustes.', 'voceador' ), $channel->alias, $message )
				);
				$status = 'failed';
				break;

			default:
				$this->jobs->mark_failed( $job->id, $class, $message );
				$status = 'failed';
		}

		$this->logger->log( in_array( $class, array( 'auth', 'permission', 'fatal' ), true ) ? 'error' : 'warning', 'publish_failed', $message, $context );

		/**
		 * Se dispara cuando un trabajo falla (aunque vaya a reintentarse).
		 *
		 * @param Job       $job     Trabajo.
		 * @param Channel   $channel Canal.
		 * @param \WP_Error $error   Error normalizado.
		 */
		do_action( 'voceador_failed', $job, $channel, $error );

		return $status;
	}

	/**
	 * Guarda un aviso persistente para el admin.
	 *
	 * @param string $key     Clave única del aviso.
	 * @param string $message Texto.
	 */
	public static function add_notice( string $key, string $message ): void {
		$option  = VOCEADOR_PREFIX . 'notices';
		$notices = get_option( $option, array() );
		$notices = is_array( $notices ) ? $notices : array();

		$notices[ $key ] = array( 'message' => $message, 'time' => time() );

		if ( false === get_option( $option ) ) {
			add_option( $option, $notices, '', false );
			return;
		}
		update_option( $option, $notices, false );
	}

	/**
	 * Cuerpo de run() una vez tomado el lock.
	 *
	 * @param int $job_id Id.
	 * @return string
	 */
	private function execute( int $job_id ): string {
		$job = $this->jobs->find( $job_id );
		if ( null === $job ) {
			return 'missing';
		}
		if ( $job->is_published() ) {
			return 'published';
		}

		$channel = $this->channels->find( $job->channel_id );
		if ( null === $channel || ! $channel->is_active() ) {
			$this->jobs->mark_failed( $job->id, 'channel_unavailable', __( 'El canal no existe o está pausado.', 'voceador' ) );
			return 'failed';
		}

		$post = get_post( $job->post_id );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			$this->jobs->mark_skipped( $job->id, __( 'El post ya no está publicado.', 'voceador' ) );
			return 'skipped';
		}

		if ( ! $this->jobs->claim( $job->id ) ) {
			return 'locked';
		}

		$adapter = $this->registry->adapter( $channel->type );

		$usage = $adapter->usage_limits( $channel );
		if ( null !== $usage && ! $usage->has_capacity() ) {
			$wait = null !== $usage->resets_at ? max( 60, $usage->resets_at - time() ) : self::DEFAULT_RATE_LIMIT_WAIT;
			$this->jobs->mark_rate_limited( $job->id, gmdate( 'Y-m-d H:i:s', time() + $wait ), __( 'Se alcanzó el límite de publicaciones del canal.', 'voceador' ) );
			$this->queue->schedule_job( $job->id, $wait );
			return 'rate_limited';
		}

		$media = $adapter->prepare_media( $channel, $post );
		if ( is_wp_error( $media ) ) {
			return $this->handle_error( $job, $channel, $media );
		}

		if ( $media->is_empty() ) {
			if ( 'feed' !== (string) $this->settings->get( 'image.no_image', $channel->settings ) ) {
				$this->jobs->mark_skipped( $job->id, __( 'El post no tiene imagen destacada.', 'voceador' ) );
				return 'skipped';
			}
			$media = null;
		}

		$caption = $this->templates->caption( $channel, $post );
		$comment = (bool) $this->settings->get( 'execution.comment_enabled', $channel->settings ) ? $this->templates->comment( $channel, $post ) : '';
		$payload = new Payload( $caption, $media instanceof Media ? $media : null, (string) get_permalink( $post ), $comment );

		$result = $adapter->publish( $channel, $payload );
		if ( is_wp_error( $result ) ) {
			return $this->handle_error( $job, $channel, $result );
		}

		$this->jobs->mark_published( $job->id, $result->id, $result->url, '' !== $comment );
		$this->logger->info( 'published', 'Publicado', array( 'job_id' => $job->id, 'post_id' => $job->post_id, 'channel_id' => $channel->id, 'remote_id' => $result->id ) );

		/**
		 * Se dispara cuando un trabajo se publica.
		 *
		 * @param Job                          $job     Trabajo (estado previo a la publicación).
		 * @param Channel                      $channel Canal.
		 * @param \Voceador\Channels\RemoteResult $result Resultado remoto.
		 */
		do_action( 'voceador_published', $job, $channel, $result );

		if ( '' !== $comment ) {
			$this->queue->schedule_comment( $job->id, (int) $this->settings->get( 'execution.comment_delay', $channel->settings ) );
		}

		return 'published';
	}
}
```

Notas para el implementador:
- En la rama `transient`, `mark_failed` + `release` deja el trabajo en `pending` con `error_code = transient` y `scheduled_at` en el futuro; `claim()` volverá a contarlo como intento. El test `test_transient_error_releases_with_backoff_until_retries_exhausted` fija esa secuencia con `retries = 2`.
- `$this->jobs->find( $job->id )?->attempts ?? $job->attempts` relee `attempts` porque `$job` es el estado previo al `claim()`.
- `add_notice()` es estática para que la fase 2 (`TokenManager`) la reutilice sin depender del `Publisher`.

- [ ] **Step 4: Contenedor**

En `Plugin`: añade `Publisher::class` a `REGISTRABLES` (después de `Queue::class`) y la factory:

```php
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
```

- [ ] **Step 5: Tests y lint**

Run: `npm run test -- --filter 'PublisherTest|PluginTest' && npm run lint`
Expected: `OK (13 tests, ...)` para `PublisherTest`; PHPCS sin errores. Si el `match`/`?->` de PHP 8 dispara `PHPCompatibility`, la configuración ya fija `testVersion 8.1-`; no bajes la versión.

- [ ] **Step 6: Commit**

```bash
git add src/Publisher.php src/Plugin.php tests/phpunit/PublisherTest.php
git commit -m "feat: ejecución de trabajos con lock, reintentos, límites y pausa de canal"
```

---

### Task 6: `Rules` y `Trigger`

**Files:**
- Create: `src/Rules.php`, `src/Trigger.php`
- Modify: `src/Plugin.php` (factories + `REGISTRABLES`)
- Test: `tests/phpunit/RulesTest.php`, `tests/phpunit/TriggerTest.php`

**Interfaces:**
- Consumes: `ChannelRepository::active()`, `Settings::get( 'rules.post_types', $channel->settings )`, `JobRepository::create_if_absent()`, `Queue::schedule_job()`, `Settings::get( 'execution.delay' | 'execution.publish_scheduled' | 'execution.publish_republished' )`.
- Produces:
  - `new Rules( ChannelRepository $channels, Settings $settings )`; `channels_for( \WP_Post $post ): Channel[]` — vacío si el meta `_voceador_skip` es verdadero o si el filtro `voceador_should_publish( bool $should, \WP_Post $post )` devuelve `false`; si el meta `_voceador_channels` (array de ids) no está vacío, solo esos canales (siempre que estén activos); si no, los canales activos cuyo `rules.post_types` (con overrides del canal) incluya el post type; al final, filtro `voceador_target_channels( Channel[] $channels, \WP_Post $post )`.
  - `Trigger implements Registrable`; `new Trigger( Rules $rules, JobRepository $jobs, Queue $queue, Settings $settings, Logger $logger )`; `register_hooks()` engancha `on_transition( string $new, string $old, \WP_Post $post )` a `transition_post_status` con prioridad 20 y 3 argumentos. `on_transition()`: solo si `$new === 'publish'` y `$old !== 'publish'`; ignora revisiones y autosaves; si `$old === 'future'` y `execution.publish_scheduled` es falso, no hace nada; si el post ya tiene el meta `_voceador_first_published` y `execution.publish_republished` es falso, no hace nada; si no, guarda `_voceador_first_published = time()` (solo la primera vez), pide los canales a `Rules`, crea un trabajo por canal (`create_if_absent`, `source = auto`) y programa cada uno con `execution.delay` (overrides del canal). Devuelve el número de trabajos encolados (para tests). `enqueue( \WP_Post $post, string $source = 'auto' ): int` hace la parte de crear y programar; `on_transition` la llama.
  - `Trigger::META_FIRST_PUBLISHED = '_voceador_first_published'`, `Rules::META_SKIP = '_voceador_skip'`, `Rules::META_CHANNELS = '_voceador_channels'`.

- [ ] **Step 1: Escribir los tests**

`tests/phpunit/RulesTest.php`:

```php
<?php
/**
 * @package Voceador
 */

use Voceador\ChannelRepository;
use Voceador\Channels\Channel;
use Voceador\Crypto;
use Voceador\Rules;
use Voceador\Schema;
use Voceador\Settings;

class RulesTest extends WP_UnitTestCase {

	private Rules $rules;
	private ChannelRepository $channels;
	private int $fb;
	private int $pages_only;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		delete_option( VOCEADOR_PREFIX . Settings::OPTION );
		$this->channels   = new ChannelRepository( $wpdb, new Schema( $wpdb ), new Crypto( str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ) );
		$this->rules      = new Rules( $this->channels, new Settings() );
		$this->fb         = $this->channels->insert( array( 'type' => 'facebook_page', 'alias' => 'A', 'remote_id' => '1' ) );
		$this->pages_only = $this->channels->insert( array( 'type' => 'facebook_page', 'alias' => 'B', 'remote_id' => '2', 'settings' => array( 'rules' => array( 'post_types' => array( 'page' ) ) ) ) );
		$this->channels->insert( array( 'type' => 'facebook_page', 'alias' => 'C', 'remote_id' => '3', 'status' => 'paused' ) );
	}

	private function ids( array $channels ): array {
		return array_map( static fn( Channel $c ) => $c->id, $channels );
	}

	public function test_post_type_rules_per_channel(): void {
		$post = get_post( self::factory()->post->create() );
		$page = get_post( self::factory()->post->create( array( 'post_type' => 'page' ) ) );

		$this->assertSame( array( $this->fb ), $this->ids( $this->rules->channels_for( $post ) ) );
		$this->assertSame( array( $this->pages_only ), $this->ids( $this->rules->channels_for( $page ) ) );
	}

	public function test_skip_meta_and_should_publish_filter(): void {
		$post = get_post( self::factory()->post->create() );
		update_post_meta( $post->ID, Rules::META_SKIP, '1' );
		$this->assertSame( array(), $this->rules->channels_for( $post ) );

		delete_post_meta( $post->ID, Rules::META_SKIP );
		add_filter( 'voceador_should_publish', '__return_false' );
		$this->assertSame( array(), $this->rules->channels_for( $post ) );
	}

	public function test_manual_channel_selection_overrides_rules(): void {
		$post = get_post( self::factory()->post->create() );
		update_post_meta( $post->ID, Rules::META_CHANNELS, array( $this->pages_only, 999 ) );

		$this->assertSame( array( $this->pages_only ), $this->ids( $this->rules->channels_for( $post ) ) );
	}

	public function test_target_channels_filter(): void {
		$post = get_post( self::factory()->post->create() );
		add_filter( 'voceador_target_channels', static fn( array $channels ) => array(), 10, 1 );
		$this->assertSame( array(), $this->rules->channels_for( $post ) );
	}
}
```

`tests/phpunit/TriggerTest.php`:

```php
<?php
/**
 * @package Voceador
 */

use Voceador\ChannelRepository;
use Voceador\Crypto;
use Voceador\JobRepository;
use Voceador\Logger;
use Voceador\Queue;
use Voceador\Rules;
use Voceador\Schema;
use Voceador\Settings;
use Voceador\Trigger;

class TriggerTest extends WP_UnitTestCase {

	private Trigger $trigger;
	private JobRepository $jobs;
	private Settings $settings;
	private int $channel_id;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		delete_option( VOCEADOR_PREFIX . Settings::OPTION );
		_set_cron_array( array() );
		$schema         = new Schema( $wpdb );
		$logger         = new Logger( $wpdb, $schema, 'debug' );
		$this->settings = new Settings();
		$this->jobs     = new JobRepository( $wpdb, $schema );
		$channels       = new ChannelRepository( $wpdb, $schema, new Crypto( str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ) );
		$this->trigger  = new Trigger( new Rules( $channels, $this->settings ), $this->jobs, new Queue( $this->jobs, $logger ), $this->settings, $logger );
		$this->channel_id = $channels->insert( array( 'type' => 'facebook_page', 'alias' => 'A', 'remote_id' => '1' ) );
		$this->trigger->register_hooks();
	}

	public function test_first_publish_enqueues_one_job_per_channel_with_delay(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		wp_publish_post( $post_id );

		$job = $this->jobs->find_for_post_and_channel( $post_id, $this->channel_id );
		$this->assertNotNull( $job );
		$this->assertSame( 'auto', $job->source );
		$this->assertEqualsWithDelta( time() + 30, wp_next_scheduled( Queue::HOOK_RUN, array( $job->id ) ), 5 );
		$this->assertNotEmpty( get_post_meta( $post_id, Trigger::META_FIRST_PUBLISHED, true ) );
	}

	public function test_republish_follows_setting_but_never_duplicates_jobs(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		update_post_meta( $post_id, Trigger::META_FIRST_PUBLISHED, time() - 100 );

		$this->assertSame( 0, $this->trigger->on_transition( 'publish', 'draft', get_post( $post_id ) ), 'Por defecto una republicación no encola.' );

		$this->settings->update( array( 'execution' => array( 'publish_republished' => true ) ) );
		$this->assertSame( 1, $this->trigger->on_transition( 'publish', 'draft', get_post( $post_id ) ) );
		$this->assertSame( 0, $this->trigger->on_transition( 'publish', 'draft', get_post( $post_id ) ), 'El trabajo ya existe: nunca se duplica.' );
	}

	public function test_scheduled_posts_follow_setting(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'future', 'post_date' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ) );

		$this->assertSame( 1, $this->trigger->on_transition( 'publish', 'future', get_post( $post_id ) ) );

		$this->settings->update( array( 'execution' => array( 'publish_scheduled' => false ) ) );
		$other = self::factory()->post->create( array( 'post_status' => 'future', 'post_date' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ) );
		$this->assertSame( 0, $this->trigger->on_transition( 'publish', 'future', get_post( $other ) ) );
	}

	public function test_ignores_non_publish_transitions_revisions_and_skipped_posts(): void {
		$post = get_post( self::factory()->post->create( array( 'post_status' => 'draft' ) ) );
		$this->assertSame( 0, $this->trigger->on_transition( 'draft', 'auto-draft', $post ) );
		$this->assertSame( 0, $this->trigger->on_transition( 'publish', 'publish', $post ) );

		$revision = get_post( wp_save_post_revision( $post->ID ) ?: self::factory()->post->create( array( 'post_type' => 'revision', 'post_parent' => $post->ID ) ) );
		$this->assertSame( 0, $this->trigger->on_transition( 'publish', 'draft', $revision ) );

		update_post_meta( $post->ID, Rules::META_SKIP, '1' );
		$this->assertSame( 0, $this->trigger->on_transition( 'publish', 'draft', $post ) );
		$this->assertEmpty( get_post_meta( $post->ID, Trigger::META_FIRST_PUBLISHED, true ), 'Si no hay canales no se marca como publicado en redes.' );
	}

	public function test_enqueue_is_idempotent(): void {
		$post = get_post( self::factory()->post->create( array( 'post_status' => 'publish' ) ) );
		$this->assertSame( 1, $this->trigger->enqueue( $post ) );
		$this->assertSame( 0, $this->trigger->enqueue( $post ), 'El trabajo ya existía.' );
	}
}
```

- [ ] **Step 2: Ejecutar y verificar que fallan**

Run: `npm run test -- --filter 'RulesTest|TriggerTest'`
Expected: FAIL con `Class "Voceador\Rules" not found`.

- [ ] **Step 3: Crear `src/Rules.php`**

```php
<?php
/**
 * Reglas de enrutamiento.
 *
 * @package Voceador
 */

namespace Voceador;

use Voceador\Channels\Channel;

/**
 * Decide a qué canales va cada post.
 */
final class Rules {

	/**
	 * Meta: no publicar en redes.
	 */
	public const META_SKIP = '_voceador_skip';

	/**
	 * Meta: canales elegidos a mano en el editor (array de ids).
	 */
	public const META_CHANNELS = '_voceador_channels';

	/**
	 * Constructor.
	 *
	 * @param ChannelRepository $channels Canales.
	 * @param Settings          $settings Ajustes.
	 */
	public function __construct(
		private ChannelRepository $channels,
		private Settings $settings
	) {}

	/**
	 * Canales que deben recibir el post.
	 *
	 * @param \WP_Post $post Post.
	 * @return Channel[]
	 */
	public function channels_for( \WP_Post $post ): array {
		if ( (bool) get_post_meta( $post->ID, self::META_SKIP, true ) ) {
			return array();
		}

		/**
		 * Permite vetar la publicación de un post en redes.
		 *
		 * @param bool     $should Si debe publicarse.
		 * @param \WP_Post $post   Post.
		 */
		if ( ! apply_filters( 'voceador_should_publish', true, $post ) ) {
			return array();
		}

		$active = $this->channels->active();
		$manual = get_post_meta( $post->ID, self::META_CHANNELS, true );
		$manual = is_array( $manual ) ? array_map( 'intval', $manual ) : array();

		if ( $manual ) {
			$targets = array_values( array_filter( $active, static fn( Channel $c ) => in_array( $c->id, $manual, true ) ) );
		} else {
			$targets = array_values(
				array_filter(
					$active,
					fn( Channel $c ) => in_array( $post->post_type, (array) $this->settings->get( 'rules.post_types', $c->settings ), true )
				)
			);
		}

		/**
		 * Permite ajustar la lista de canales destino de un post.
		 *
		 * @param Channel[] $targets Canales.
		 * @param \WP_Post  $post    Post.
		 */
		$targets = apply_filters( 'voceador_target_channels', $targets, $post );

		return array_values( array_filter( (array) $targets, static fn( $c ) => $c instanceof Channel ) );
	}
}
```

- [ ] **Step 4: Crear `src/Trigger.php`**

```php
<?php
/**
 * Disparador: de la publicación del post a la cola.
 *
 * @package Voceador
 */

namespace Voceador;

/**
 * Escucha transition_post_status y encola un trabajo por canal. Nunca publica en la misma petición.
 */
final class Trigger implements Registrable {

	/**
	 * Meta: marca de tiempo de la primera publicación en redes.
	 */
	public const META_FIRST_PUBLISHED = '_voceador_first_published';

	/**
	 * Constructor.
	 *
	 * @param Rules         $rules    Reglas.
	 * @param JobRepository $jobs     Trabajos.
	 * @param Queue         $queue    Cola.
	 * @param Settings      $settings Ajustes.
	 * @param Logger        $logger   Log.
	 */
	public function __construct(
		private Rules $rules,
		private JobRepository $jobs,
		private Queue $queue,
		private Settings $settings,
		private Logger $logger
	) {}

	/**
	 * Engancha el disparador.
	 */
	public function register_hooks(): void {
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 20, 3 );
	}

	/**
	 * Reacciona a un cambio de estado.
	 *
	 * @param string   $new_status Estado nuevo.
	 * @param string   $old_status Estado anterior.
	 * @param \WP_Post $post       Post.
	 * @return int Trabajos encolados.
	 */
	public function on_transition( string $new_status, string $old_status, \WP_Post $post ): int {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return 0;
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return 0;
		}
		if ( 'future' === $old_status && ! (bool) $this->settings->get( 'execution.publish_scheduled' ) ) {
			return 0;
		}

		$already = get_post_meta( $post->ID, self::META_FIRST_PUBLISHED, true );
		if ( '' !== (string) $already && ! (bool) $this->settings->get( 'execution.publish_republished' ) ) {
			return 0;
		}

		return $this->enqueue( $post );
	}

	/**
	 * Crea y programa los trabajos de un post según las reglas.
	 *
	 * @param \WP_Post $post   Post.
	 * @param string   $source auto, manual, cli o test.
	 * @return int Trabajos nuevos encolados.
	 */
	public function enqueue( \WP_Post $post, string $source = 'auto' ): int {
		$channels = $this->rules->channels_for( $post );
		if ( ! $channels ) {
			return 0;
		}

		if ( '' === (string) get_post_meta( $post->ID, self::META_FIRST_PUBLISHED, true ) ) {
			update_post_meta( $post->ID, self::META_FIRST_PUBLISHED, time() );
		}

		$count = 0;
		foreach ( $channels as $channel ) {
			$delay  = (int) $this->settings->get( 'execution.delay', $channel->settings );
			$job_id = $this->jobs->create_if_absent( $post->ID, $channel->id, $source, gmdate( 'Y-m-d H:i:s', time() + $delay ) );
			if ( null === $job_id ) {
				continue;
			}
			$this->queue->schedule_job( $job_id, $delay );
			++$count;
		}

		if ( $count > 0 ) {
			$this->logger->info( 'enqueued', sprintf( '%d trabajo(s) encolado(s)', $count ), array( 'post_id' => $post->ID ) );
		}

		return $count;
	}
}
```

- [ ] **Step 5: Contenedor**

En `Plugin`: añade `Trigger::class` a `REGISTRABLES` y las factories:

```php
		$this->factories[ Rules::class ] = static function ( Plugin $c ): Rules {
			return new Rules( $c->get( ChannelRepository::class ), $c->get( Settings::class ) );
		};

		$this->factories[ Trigger::class ] = static function ( Plugin $c ): Trigger {
			return new Trigger( $c->get( Rules::class ), $c->get( JobRepository::class ), $c->get( Queue::class ), $c->get( Settings::class ), $c->get( Logger::class ) );
		};
```

- [ ] **Step 6: Tests y lint**

Run: `npm run test -- --filter 'RulesTest|TriggerTest|PluginTest' && npm run lint`
Expected: `OK`; PHPCS sin errores. Si `wp_save_post_revision()` devuelve `null` en tu entorno (sin cambios que versionar), el test ya crea una revisión manual como alternativa.

- [ ] **Step 7: Commit**

```bash
git add src/Rules.php src/Trigger.php src/Plugin.php tests/phpunit/RulesTest.php tests/phpunit/TriggerTest.php
git commit -m "feat: reglas de enrutamiento y disparador que encola al publicar"
```

---

### Task 7: CLI mínimo y ZIP distribuible

**Files:**
- Create: `src/CLI.php`, `bin/build-zip.sh`
- Modify: `src/Plugin.php` (registro del comando cuando corre WP-CLI), `package.json` (script `build:zip`), `.github/workflows/ci.yml` (smoke: `wp voceador status` y `channels list`), `README.md` (comandos)
- Test: `tests/phpunit/CLITest.php` (solo la lógica sin `WP_CLI`, ver abajo)

**Interfaces:**
- Consumes: `ChannelRepository`, `ChannelRegistry::adapter( 'facebook_page' )->validate_credentials()`, `Publisher::publish_now()`, `JobRepository::count_by_status()`, `Queue::uses_action_scheduler()/cron_available()`, `Logger::recent()`.
- Produces comandos WP-CLI (subcomandos del comando `voceador`):
  - `wp voceador channels add-facebook --page-id=<id> --token=<page-token> [--alias=<alias>]` — valida el token con el adaptador (`/me` debe devolver ese `page-id`), inserta el canal con `connection_method = manual` y `remote_name` del `/me`, imprime el id. El token nunca se imprime ni se registra.
  - `wp voceador channels list [--format=<table|json|csv>]` — id, tipo, alias, remote_id, remote_name, status, health_checked_at.
  - `wp voceador channels delete <id>` — borra el canal.
  - `wp voceador publish <post_id> [--channel=<id>] [--source=<cli>]` — `Publisher::publish_now()` y tabla canal => estado; sale con código 1 si algún estado es `failed`.
  - `wp voceador status` — número de canales por estado, trabajos por estado, si usa Action Scheduler, si hay cron disponible, y los últimos 10 eventos del log (nivel, evento, mensaje).
  - Para poder probarlo sin `WP_CLI`, la lógica vive en `CLI` como métodos que devuelven arrays/`WP_Error`, y los subcomandos son wrappers finos: `add_facebook_page( string $page_id, string $token, string $alias = '' ): int|\WP_Error`, `list_channels(): array`, `delete_channel( int $id ): bool`, `publish( int $post_id, ?int $channel_id, string $source ): array`, `status(): array`.
  - `bin/build-zip.sh`: construye `build-zip/voceador.zip` con `rsync -a --exclude-from=.distignore` a `build-zip/voceador/` y `zip -r`; `npm run build:zip` lo ejecuta. Requiere `rsync` y `zip` en el host (macOS los trae).

- [ ] **Step 1: Escribir el test**

`tests/phpunit/CLITest.php`:

```php
<?php
/**
 * @package Voceador
 */

use Voceador\ChannelRepository;
use Voceador\Channels\ChannelRegistry;
use Voceador\Channels\FacebookPageAdapter;
use Voceador\CLI;
use Voceador\Crypto;
use Voceador\GraphClient;
use Voceador\JobRepository;
use Voceador\Logger;
use Voceador\Publisher;
use Voceador\Queue;
use Voceador\Schema;
use Voceador\Settings;
use Voceador\Templates;
use Voceador\Tests\Fixtures\GraphResponses;

class CLITest extends WP_UnitTestCase {

	private CLI $cli;
	private ChannelRepository $channels;
	private array $responses = array();

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		delete_option( VOCEADOR_PREFIX . Settings::OPTION );
		$schema         = new Schema( $wpdb );
		$logger         = new Logger( $wpdb, $schema, 'debug' );
		$settings       = new Settings();
		$jobs           = new JobRepository( $wpdb, $schema );
		$this->channels = new ChannelRepository( $wpdb, $schema, new Crypto( str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ) );
		$graph          = new GraphClient( $settings, $logger );
		$registry       = new ChannelRegistry( array( 'facebook_page' => static fn() => new FacebookPageAdapter( $graph, $settings ) ) );
		$queue          = new Queue( $jobs, $logger );
		$publisher      = new Publisher( $jobs, $this->channels, $registry, new Templates( $settings ), $settings, $logger, $queue );
		$this->cli      = new CLI( $this->channels, $registry, $publisher, $jobs, $queue, $logger );

		add_filter( 'pre_http_request', array( $this, 'respond' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'respond' ), 10 );
		parent::tear_down();
	}

	public function respond( $pre, array $args, string $url ) {
		return array_shift( $this->responses ) ?? GraphResponses::ok( array( 'id' => '0' ) );
	}

	public function test_add_facebook_page_validates_and_stores(): void {
		$this->responses[] = GraphResponses::ok( array( 'id' => '1001', 'name' => 'Mi Página' ) );

		$id = $this->cli->add_facebook_page( '1001', 'EAAtoken', 'Principal' );

		$this->assertIsInt( $id );
		$channel = $this->channels->find( $id );
		$this->assertSame( 'Mi Página', $channel->remote_name );
		$this->assertSame( 'manual', $channel->connection_method );
		$this->assertSame( 'EAAtoken', $channel->credential( 'access_token' ) );
		$this->assertSame( 'Principal', $channel->alias );
	}

	public function test_add_facebook_page_rejects_invalid_token_and_duplicates(): void {
		$this->responses[] = GraphResponses::error( 400, 190, 'Invalid OAuth access token' );
		$error = $this->cli->add_facebook_page( '1001', 'bad' );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'voceador_invalid_token', $error->get_error_code() );
		$this->assertCount( 0, $this->channels->all() );

		$this->responses[] = GraphResponses::ok( array( 'id' => '1001', 'name' => 'Mi Página' ) );
		$this->cli->add_facebook_page( '1001', 'EAAtoken' );
		$this->responses[] = GraphResponses::ok( array( 'id' => '1001', 'name' => 'Mi Página' ) );
		$this->assertSame( 'voceador_channel_exists', $this->cli->add_facebook_page( '1001', 'EAAtoken' )->get_error_code() );
	}

	public function test_list_delete_and_status(): void {
		$this->responses[] = GraphResponses::ok( array( 'id' => '1001', 'name' => 'Mi Página' ) );
		$id = $this->cli->add_facebook_page( '1001', 'EAAtoken', 'Principal' );

		$rows = $this->cli->list_channels();
		$this->assertCount( 1, $rows );
		$this->assertSame( array( 'id', 'type', 'alias', 'remote_id', 'remote_name', 'status', 'health_checked_at' ), array_keys( $rows[0] ) );
		$this->assertArrayNotHasKey( 'credentials', $rows[0] );

		$status = $this->cli->status();
		$this->assertSame( 1, $status['channels']['active'] );
		$this->assertSame( 0, $status['jobs']['pending'] );
		$this->assertFalse( $status['action_scheduler'] );
		$this->assertTrue( $status['cron_available'] );
		$this->assertIsArray( $status['recent_log'] );

		$this->assertTrue( $this->cli->delete_channel( $id ) );
		$this->assertFalse( $this->cli->delete_channel( $id ) );
	}

	public function test_publish_runs_publisher(): void {
		$this->responses[] = GraphResponses::ok( array( 'id' => '1001', 'name' => 'Mi Página' ) );
		$id = $this->cli->add_facebook_page( '1001', 'EAAtoken' );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		( new Settings() )->update( array( 'image' => array( 'no_image' => 'feed' ) ) );
		$this->responses[] = GraphResponses::ok( array( 'id' => '1001_5' ) );

		$this->assertSame( array( $id => 'published' ), $this->cli->publish( $post_id, null, 'cli' ) );
	}
}
```

- [ ] **Step 2: Ejecutar y verificar que falla**

Run: `npm run test -- --filter CLITest`
Expected: FAIL con `Class "Voceador\CLI" not found`.

- [ ] **Step 3: Crear `src/CLI.php`**

```php
<?php
/**
 * Comandos WP-CLI.
 *
 * @package Voceador
 */

namespace Voceador;

use Voceador\Channels\ChannelRegistry;
use Voceador\Channels\FacebookPageAdapter;

/**
 * Lógica de los comandos `wp voceador …`. Los subcomandos son envoltorios finos
 * sobre métodos que devuelven arrays o WP_Error, para poder probarlos sin WP_CLI.
 */
final class CLI {

	/**
	 * Constructor.
	 *
	 * @param ChannelRepository $channels  Canales.
	 * @param ChannelRegistry   $registry  Adaptadores.
	 * @param Publisher         $publisher Publicador.
	 * @param JobRepository     $jobs      Trabajos.
	 * @param Queue             $queue     Cola.
	 * @param Logger            $logger    Log.
	 */
	public function __construct(
		private ChannelRepository $channels,
		private ChannelRegistry $registry,
		private Publisher $publisher,
		private JobRepository $jobs,
		private Queue $queue,
		private Logger $logger
	) {}

	/**
	 * Registra el comando en WP-CLI.
	 */
	public function register(): void {
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'voceador channels add-facebook', array( $this, 'cmd_channels_add_facebook' ) );
		\WP_CLI::add_command( 'voceador channels list', array( $this, 'cmd_channels_list' ) );
		\WP_CLI::add_command( 'voceador channels delete', array( $this, 'cmd_channels_delete' ) );
		\WP_CLI::add_command( 'voceador publish', array( $this, 'cmd_publish' ) );
		\WP_CLI::add_command( 'voceador status', array( $this, 'cmd_status' ) );
	}

	/**
	 * Conecta una Página de Facebook con un Page Access Token.
	 *
	 * @param string $page_id Id de la Página.
	 * @param string $token   Page Access Token.
	 * @param string $alias   Alias interno (por defecto el nombre de la Página).
	 * @return int|\WP_Error Id del canal.
	 */
	public function add_facebook_page( string $page_id, string $token, string $alias = '' ): int|\WP_Error {
		$probe  = new Channels\Channel( array( 'type' => FacebookPageAdapter::type(), 'remote_id' => $page_id, 'credentials' => array( 'access_token' => $token ) ) );
		$report = $this->registry->adapter( FacebookPageAdapter::type() )->validate_credentials( $probe );

		if ( ! $report->valid ) {
			return new \WP_Error( 'voceador_invalid_token', $report->message );
		}

		$name = (string) ( $report->raw['name'] ?? '' );

		return $this->channels->insert(
			array(
				'type'              => FacebookPageAdapter::type(),
				'alias'             => '' !== $alias ? $alias : $name,
				'remote_id'         => $page_id,
				'remote_name'       => $name,
				'connection_method' => 'manual',
				'credentials'       => array( 'access_token' => $token ),
				'health'            => array( 'message' => $name ),
				'health_checked_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Canales sin secretos, listos para tabular.
	 *
	 * @return array[]
	 */
	public function list_channels(): array {
		return array_map(
			static fn( Channels\Channel $c ) => array(
				'id'                => $c->id,
				'type'              => $c->type,
				'alias'             => $c->alias,
				'remote_id'         => $c->remote_id,
				'remote_name'       => $c->remote_name,
				'status'            => $c->status,
				'health_checked_at' => (string) $c->health_checked_at,
			),
			$this->channels->all()
		);
	}

	/**
	 * Borra un canal.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public function delete_channel( int $id ): bool {
		return $this->channels->delete( $id );
	}

	/**
	 * Publica un post ahora.
	 *
	 * @param int      $post_id    Post.
	 * @param int|null $channel_id Canal o null para todos.
	 * @param string   $source     Origen.
	 * @return array<int, string>
	 */
	public function publish( int $post_id, ?int $channel_id, string $source = 'cli' ): array {
		return $this->publisher->publish_now( $post_id, $channel_id, $source );
	}

	/**
	 * Resumen del estado del plugin.
	 *
	 * @return array
	 */
	public function status(): array {
		$channels = array( 'active' => 0, 'paused' => 0, 'error' => 0, 'disabled' => 0 );
		foreach ( $this->channels->all() as $c ) {
			$channels[ $c->status ] = ( $channels[ $c->status ] ?? 0 ) + 1;
		}

		return array(
			'channels'         => $channels,
			'jobs'             => $this->jobs->count_by_status(),
			'action_scheduler' => $this->queue->uses_action_scheduler(),
			'cron_available'   => $this->queue->cron_available(),
			'recent_log'       => array_map(
				static fn( array $row ) => array( 'created_at' => $row['created_at'], 'level' => $row['level'], 'event' => $row['event'], 'message' => $row['message'] ),
				$this->logger->recent( array( 'limit' => 10 ) )
			),
		);
	}

	/**
	 * `wp voceador channels add-facebook --page-id=<id> --token=<token> [--alias=<alias>]`
	 *
	 * @param array $args       Posicionales.
	 * @param array $assoc_args Opciones.
	 */
	public function cmd_channels_add_facebook( array $args, array $assoc_args ): void {
		$page_id = (string) ( $assoc_args['page-id'] ?? '' );
		$token   = (string) ( $assoc_args['token'] ?? '' );
		if ( '' === $page_id || '' === $token ) {
			\WP_CLI::error( 'Faltan --page-id o --token.' );
		}

		$result = $this->add_facebook_page( $page_id, $token, (string) ( $assoc_args['alias'] ?? '' ) );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( sprintf( 'Canal %d conectado.', $result ) );
	}

	/**
	 * `wp voceador channels list [--format=<format>]`
	 *
	 * @param array $args       Posicionales.
	 * @param array $assoc_args Opciones.
	 */
	public function cmd_channels_list( array $args, array $assoc_args ): void {
		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $this->list_channels(), array( 'id', 'type', 'alias', 'remote_id', 'remote_name', 'status', 'health_checked_at' ) );
	}

	/**
	 * `wp voceador channels delete <id>`
	 *
	 * @param array $args Posicionales.
	 */
	public function cmd_channels_delete( array $args ): void {
		$id = (int) ( $args[0] ?? 0 );
		if ( $id <= 0 || ! $this->delete_channel( $id ) ) {
			\WP_CLI::error( 'No existe ese canal.' );
		}
		\WP_CLI::success( sprintf( 'Canal %d eliminado.', $id ) );
	}

	/**
	 * `wp voceador publish <post_id> [--channel=<id>] [--source=<source>]`
	 *
	 * @param array $args       Posicionales.
	 * @param array $assoc_args Opciones.
	 */
	public function cmd_publish( array $args, array $assoc_args ): void {
		$post_id = (int) ( $args[0] ?? 0 );
		if ( $post_id <= 0 || ! get_post( $post_id ) instanceof \WP_Post ) {
			\WP_CLI::error( 'Post inexistente.' );
		}

		$channel = isset( $assoc_args['channel'] ) ? (int) $assoc_args['channel'] : null;
		$result  = $this->publish( $post_id, $channel, (string) ( $assoc_args['source'] ?? 'cli' ) );

		if ( ! $result ) {
			\WP_CLI::warning( 'No hay canales activos para este post.' );
			return;
		}

		$rows = array();
		foreach ( $result as $channel_id => $status ) {
			$job    = $this->jobs->find_for_post_and_channel( $post_id, (int) $channel_id );
			$rows[] = array( 'channel' => $channel_id, 'status' => $status, 'remote_url' => (string) ( $job->remote_url ?? '' ), 'error' => (string) ( $job->error_message ?? '' ) );
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'channel', 'status', 'remote_url', 'error' ) );

		if ( in_array( 'failed', $result, true ) ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * `wp voceador status`
	 */
	public function cmd_status(): void {
		$status = $this->status();

		\WP_CLI::line( 'Canales: ' . wp_json_encode( $status['channels'] ) );
		\WP_CLI::line( 'Trabajos: ' . wp_json_encode( $status['jobs'] ) );
		\WP_CLI::line( 'Action Scheduler: ' . ( $status['action_scheduler'] ? 'sí' : 'no' ) );
		\WP_CLI::line( 'Cron disponible: ' . ( $status['cron_available'] ? 'sí' : 'no' ) );

		if ( $status['recent_log'] ) {
			\WP_CLI\Utils\format_items( 'table', $status['recent_log'], array( 'created_at', 'level', 'event', 'message' ) );
		}
	}
}
```

- [ ] **Step 4: Contenedor, script de ZIP, CI y README**

En `Plugin::register_factories()`:

```php
		$this->factories[ CLI::class ] = static function ( Plugin $c ): CLI {
			return new CLI( $c->get( ChannelRepository::class ), $c->get( Channels\ChannelRegistry::class ), $c->get( Publisher::class ), $c->get( JobRepository::class ), $c->get( Queue::class ), $c->get( Logger::class ) );
		};
```

Y al final de `Plugin::register_hooks()`:

```php
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$this->get( CLI::class )->register();
		}
```

`bin/build-zip.sh` (ejecutable, `chmod +x`):

```bash
#!/usr/bin/env bash
# Construye build-zip/voceador.zip con el contenido distribuible según .distignore.
set -euo pipefail
cd "$(dirname "$0")/.."
rm -rf build-zip
mkdir -p build-zip/voceador
rsync -a --exclude-from=.distignore --exclude=build-zip ./ build-zip/voceador/
( cd build-zip && zip -qr voceador.zip voceador )
echo "build-zip/voceador.zip ($(du -h build-zip/voceador.zip | cut -f1))"
```

En `package.json` añade el script `"build:zip": "bash bin/build-zip.sh"`. En `.distignore` añade `bin` y `build-zip` si no están.

En `.github/workflows/ci.yml`, dentro del smoke, justo después de la comprobación de la capacidad y antes del bloque de upgrade, añade:

```
npx wp-env run cli wp voceador status
test "$(npx wp-env run cli wp voceador channels list --format=json)" = "[]"
```

En `README.md`, sección Desarrollo, añade una subsección "WP-CLI" con los cinco comandos y una línea: "`npm run build:zip` genera el ZIP distribuible en `build-zip/`".

En `tests/phpunit/PluginTest.php::test_every_phase_1a_service_resolves` (renómbralo a `test_every_service_resolves`) añade a la lista `\Voceador\Templates::class`, `\Voceador\Queue::class`, `\Voceador\Publisher::class`, `\Voceador\Rules::class`, `\Voceador\Trigger::class` y `\Voceador\CLI::class`, y comprueba que `has_action( Queue::HOOK_RUN, array( $plugin->get( \Voceador\Publisher::class ), 'run' ) )` y `has_action( 'transition_post_status', array( $plugin->get( \Voceador\Trigger::class ), 'on_transition' ) )` no son `false`.

- [ ] **Step 5: Tests, lint y comprobación manual del comando**

Run: `npm run test -- --filter 'CLITest|PluginTest' && npm run lint && npx wp-env run cli wp voceador status && npx wp-env run cli wp voceador channels list && npm run build:zip && unzip -l build-zip/voceador.zip | grep -c "tests/" `
Expected: tests `OK`; PHPCS sin errores; `status` imprime canales/trabajos/cron; `channels list` imprime una tabla vacía; el ZIP se crea y el último comando imprime `0` (no incluye tests).

- [ ] **Step 6: Commit**

```bash
git add src/CLI.php src/Plugin.php bin/build-zip.sh package.json .distignore .github/workflows/ci.yml README.md tests/phpunit/CLITest.php
git commit -m "feat: comandos WP-CLI mínimos y ZIP distribuible"
```

---

### Task 8: Rama, PR y prueba real en staging

Esta tarea la ejecuta el controlador con el usuario, no un subagente: necesita un Page Access Token real que no debe pasar por ningún reporte ni log.

**Files:**
- Modify: `README.md` (línea de estado), `docs/superpowers/specs/2026-09-21-voceador-design.md` (fila 1b marcada como hecha si procede)

**Prerrequisitos que aporta el usuario:**
- Una app de Meta (App ID) en modo desarrollo con el producto Facebook Login.
- Una Página de Facebook de prueba de la que el usuario sea administrador y esté añadida a la app (o el usuario sea administrador de la app).
- Un **Page Access Token** generado en el Graph API Explorer para esa Página con los permisos `pages_show_list`, `pages_read_engagement`, `pages_manage_posts` y `pages_manage_engagement`. Se pega directamente en el comando SSH; no se guarda en ningún archivo del repo ni de la conversación.

- [ ] **Step 1: Push, PR y CI**

```bash
git push -u origin fase-1b/publicacion-facebook
gh pr create --base main --head fase-1b/publicacion-facebook --title "Fase 1b: publicación en Facebook" --body-file <archivo con el resumen y la línea final "🤖 Generated with [Claude Code](https://claude.com/claude-code)">
gh pr checks <n> --repo aprendomx/voceador --watch
```

Expected: `lint-and-test` en verde.

- [ ] **Step 2: Desplegar en staging**

```bash
npm run build:zip
scp build-zip/voceador.zip USUARIO@SERVIDOR_STAGING:/tmp/voceador.zip
ssh USUARIO@SERVIDOR_STAGING 'cd /ruta/al/wordpress && sudo -u www-data wp plugin install /tmp/voceador.zip --force --activate && sudo -u www-data wp plugin list --name=voceador --field=status && sudo -u www-data wp voceador status'
```

Expected: `active`; `status` muestra 0 canales y cron disponible. Si la ruta de WordOps difiere, `ssh USUARIO@SERVIDOR_STAGING 'ls /var/www/*/htdocs/wp-config.php'` la revela. Si `wp` no está en el PATH de `www-data`, usa `sudo -u www-data /usr/local/bin/wp`.

- [ ] **Step 3: Conectar la Página (el usuario pega el token)**

```bash
ssh USUARIO@SERVIDOR_STAGING 'cd /ruta/al/wordpress && sudo -u www-data wp voceador channels add-facebook --page-id=<PAGE_ID> --token=<PAGE_TOKEN> --alias="Prueba"'
ssh USUARIO@SERVIDOR_STAGING 'cd /ruta/al/wordpress && sudo -u www-data wp voceador channels list'
```

Expected: `Canal N conectado.` y la tabla con `remote_name` igual al nombre de la Página. Después del comando, limpia el historial de shell local si el token quedó en él (`history -d` o equivalente).

- [ ] **Step 4: Publicar una nota de prueba**

```bash
ssh USUARIO@SERVIDOR_STAGING 'cd /ruta/al/wordpress && sudo -u www-data wp post list --post_status=publish --posts_per_page=3 --fields=ID,post_title'
ssh USUARIO@SERVIDOR_STAGING 'cd /ruta/al/wordpress && sudo -u www-data wp voceador publish <POST_ID>'
```

Elige un post que tenga imagen destacada. Expected: tabla con `status = published` y `remote_url` `https://www.facebook.com/<page>_<post>`. Abre la URL: debe verse la foto con el caption (título + extracto). Espera ~60 s (o fuerza el cron: `sudo -u www-data wp cron event run voceador_run_comment` no funciona con args; usa `sudo -u www-data wp cron event run --due-now`) y comprueba que aparece el comentario de la Página con el enlace.

- [ ] **Step 5: Probar el disparador real**

Publica una nota nueva desde el editor de WordPress en microfono.mx (o `wp post create --post_status=publish --post_title="Prueba Voceador" ...` con `--post_thumbnail` de un adjunto existente). A los ~30 s (tras un `wp cron event run --due-now` si el cron del sistema no corre solo) debe aparecer en la Página; comprueba `wp voceador status` y el log.

- [ ] **Step 6: Limpiar**

- Borra las publicaciones de prueba desde Facebook.
- `sudo -u www-data wp voceador channels delete <id>` si no quieres dejar la Página conectada en staging.
- Revisa que `wp voceador status` no muestre errores `auth`/`permission`.

- [ ] **Step 7: Cierre**

Actualiza la línea `> Estado:` de `README.md` a "fase 1b completada: publica en Páginas de Facebook con token manual (foto + comentario), con cola, reintentos y WP-CLI. Falta OAuth, Instagram, editor, wizard y ajustes." Commit `docs: estado tras la fase 1b`, push, y fusiona según decida el usuario.

---

## Criterio de cierre de la fase 1b

- `npm run test`, `npm run test:multisite` y `npm run lint` en verde en local y en CI (el smoke ejecuta `wp voceador status` y `channels list`).
- Con un canal conectado por token manual, publicar un post con imagen destacada produce en la Página una foto con caption y, tras el retraso, un comentario de la Página con el enlace; el trabajo queda `published` con `remote_id` y `remote_comment_id`.
- Publicar el mismo post otra vez no crea una segunda publicación.
- Un token inválido pausa el canal, deja el trabajo `failed` con clase `auth` y registra un aviso; un error 5xx reprograma con backoff; un post sin imagen se omite o va al feed según `image.no_image`.
- Fuera de alcance (fases siguientes): OAuth y `debug_token` (2), reglas por taxonomía, hashtags, UTM, panel del editor y metas registrados (3), Instagram (4), procesamiento de imagen y link en bio (5), wizard y ayuda (6), columna de estado, notificaciones por correo, CLI completo, Site Health, import/export (7).

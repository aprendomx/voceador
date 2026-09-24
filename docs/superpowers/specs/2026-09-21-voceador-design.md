# Voceador — Diseño de arquitectura y esquema de datos

Fecha: 2026-09-21 · Actualizado: 2026-09-22 (tras la fase 0) · Estado: aprobado

Fuente de requisitos: `docs/prompt-original.md` (prompt de desarrollo del plugin). Este documento fija las decisiones de arquitectura y datos; los detalles funcionales no repetidos aquí se toman del prompt.

## 1. Decisiones de contexto

| Tema | Decisión |
| --- | --- |
| Entorno local | `@wordpress/env` (Docker) dentro del repo, con PHPUnit y WP-CLI |
| Pruebas contra Graph API real | Staging público con HTTPS (microfono.mx, WordOps); en local solo mocks vía `pre_http_request` |
| Distribución | Privada por ZIP/Git hoy; el repo se mantiene listo para enviarse a WordPress.org |
| Almacenamiento | Tablas propias para canales, trabajos y log; opciones para ajustes; post meta solo para datos editoriales |
| Stack | PHP 8.1+, WordPress 6.5+, single site y Multisite, sin Composer en runtime |

### Preparación para WordPress.org

- La raíz del repositorio es la carpeta del plugin (`voceador/`); slug y text domain `voceador`.
- Licencia GPL-2.0-or-later; `readme.txt` en formato del directorio, con sección que declara el servicio externo (Meta Graph API: qué datos se envían, cuándo, enlaces a términos y privacidad).
- Nombre público "Voceador – Autopublicación de notas en redes sociales": sin marcas de Meta al inicio del nombre ni del slug.
- Sin actualizador propio, sin código ofuscado, sin llamadas externas fuera de Meta, sin telemetría.
- JS sin build obligatorio; si se añade build, el fuente legible va en el repo.
- Action Scheduler no se empaqueta: se usa si otro plugin lo provee, con fallback a WP-Cron.
- `.distignore` excluye `docs/`, `tests/`, `.wp-env.json`, configuración de herramientas y `.wordpress-org/` (banners, iconos, capturas) del ZIP distribuible.
- Todo el código pasa PHPCS con WordPress Coding Standards y Plugin Check antes de cada release.

## 2. Arquitectura

### Arranque

`bootstrap.php` define `VOCEADOR_VERSION`, `VOCEADOR_PREFIX = 'voceador_'`, `VOCEADOR_FILE`, `VOCEADOR_DIR` y registra un autoloader PSR-4 propio (`Voceador\` → `src/`); lo comparten `voceador.php` (que llama a `Plugin::boot()`) y `uninstall.php`.

`Plugin` es el único punto con estado global: un contenedor perezoso con un mapa de factories (`Plugin::get( string $id ): object`) que instancia cada servicio la primera vez que se pide, y que tras construirse recorre los servicios que implementan `Registrable` (`register_hooks(): void`) para que cada uno registre sus propios hooks. Expone `reset()` (solo para tests) que descarta la instancia. Los demás servicios no usan singletons ni estado global y reciben sus dependencias por constructor.

`Installer::maybe_install()` (en `init`, prioridad 0) crea el esquema cuando `voceador_db_version` difiere de `Schema::DB_VERSION`, con un lock en `voceador_installing` contra ejecuciones concurrentes, y llama a `Schema::migrate( $from, $to )` para migraciones con datos.

### Capas (dependencias solo hacia abajo)

| Capa | Clases | Responsabilidad |
| --- | --- | --- |
| Admin/UI | `Settings`, `Wizard`, `Help`, `EditorIntegration`, `SiteHealth`, `CLI` | Pantallas, REST interno, meta box/panel, comandos |
| Orquestación | `Rules`, `Queue`, `Publisher`, `TokenManager`, `Notifications` | Decide canales, encola, ejecuta trabajos, vigila salud |
| Canales | `Channels\ChannelAdapter`, `Channels\ChannelRegistry`, `Channels\FacebookPageAdapter`, `Channels\InstagramAdapter`, `OAuth\Facebook`, `OAuth\Instagram` | Todo lo específico de cada red |
| Infraestructura | `GraphClient`, `Crypto`, `ImageProcessor`, `Templates`, `Logger`, `LinkInBio`, `Schema`, `ChannelRepository`, `JobRepository` | HTTP, cifrado, imagen, render, acceso a tablas |

Clases añadidas respecto al prompt: `ChannelRegistry` (tipos de canal registrables con el filtro `voceador_channel_types`, para que otra red se añada desde otro plugin sin tocar el núcleo), `ChannelRepository`/`JobRepository` (único punto con SQL) y `Schema` (`dbDelta` + migraciones por versión).

### Contrato del adaptador

```php
interface ChannelAdapter {
    public static function type(): string;
    public function validate_credentials( Channel $c ): HealthReport;
    public function prepare_media( Channel $c, \WP_Post $p ): Media|\WP_Error;
    public function publish( Channel $c, Payload $payload ): RemoteResult|\WP_Error;
    public function comment( Channel $c, string $remote_id, string $text ): RemoteResult|\WP_Error;
    public function status( Channel $c, string $remote_id ): RemoteStatus;
    public function usage_limits( Channel $c ): ?UsageLimits; // null si no aplica
    public function settings_schema(): array;                 // campos propios del tipo para la UI
}
```

Los errores salen como `WP_Error` con clase normalizada (sección 4). `Publisher` decide reintento o pausa según la clase; la traducción de códigos numéricos de Graph vive solo en `GraphClient`.

### Flujo de un trabajo

1. `transition_post_status` (cualquier estado → `publish`, incluidos programados) → `Rules::channels_for( $post )` (aplica `voceador_should_publish`, `voceador_target_channels`, `_voceador_skip`, `_voceador_channels`) → `JobRepository::create_if_absent()` (`INSERT IGNORE` sobre la clave única) → `Queue::schedule( job_id, delay )`. Nada más ocurre en esa petición.
2. `Queue`: Action Scheduler si existe; si no, `wp_schedule_single_event`. Hook de ejecución `voceador_run_job`.
3. `Publisher::run( job_id )`: lock → relee post y canal → aborta si ya hay `remote_id` → `usage_limits` (reprograma si no hay cupo) → `prepare_media` → `Templates` → `publish` → guarda `remote_id` → encola el paso `comment` como acción separada con su retraso.
4. Resultado: `voceador_published` / `voceador_failed`, `Logger`, `Notifications`.

### Multisite

Tablas y opciones por sitio. Defaults de red en `site_option` `voceador_network_defaults`. `Settings::get()` resuelve canal → sitio → red → default en código. En activación en red las tablas se crean de forma perezosa (`Installer::maybe_install()` en cada sitio) y en `wp_initialize_site` (prioridad 20, tras el `populate_roles` del core).

## 3. Esquema de datos

### Tablas (por sitio, `{$wpdb->prefix}voceador_*`)

**`voceador_channels`**

| Columna | Tipo | Notas |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED PK AI | |
| `type` | VARCHAR(40) | `facebook_page`, `instagram_account` |
| `alias` | VARCHAR(190) | Nombre interno |
| `remote_id` | VARCHAR(64) | Page ID / IG user ID |
| `remote_name` | VARCHAR(190) | Nombre de Página / username |
| `avatar_url` | TEXT NULL | |
| `connection_method` | VARCHAR(20) | `oauth`, `manual`, `ig_linked`, `ig_login` |
| `parent_channel_id` | BIGINT UNSIGNED NULL | IG vinculado → canal de la Página cuyo token usa |
| `credentials` | LONGTEXT | JSON cifrado (token y metadatos); nunca en claro |
| `scopes` | TEXT | JSON de permisos concedidos |
| `token_expires_at` | DATETIME NULL | Solo Instagram Login |
| `status` | VARCHAR(20) | `active`, `paused`, `error`, `disabled` |
| `health` | TEXT | JSON: último `debug_token`, permisos faltantes, mensaje |
| `health_checked_at` | DATETIME NULL | |
| `settings` | LONGTEXT | JSON con overrides del canal (reglas, plantillas, imagen, ejecución, UTM) |
| `created_at`, `updated_at` | DATETIME | UTC |

Índices: `status`, UNIQUE `(type, remote_id)` (el índice `type` se eliminó en el esquema v2 por redundante).

**`voceador_jobs`** (una fila por post + canal)

| Columna | Tipo | Notas |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED PK AI | |
| `post_id`, `channel_id` | BIGINT UNSIGNED | UNIQUE `(post_id, channel_id)`: idempotencia a nivel de BD |
| `status` | VARCHAR(20) | `pending`, `running`, `published`, `failed`, `rate_limited`, `skipped` |
| `comment_status` | VARCHAR(20) | `none`, `pending`, `running`, `done`, `failed` |
| `remote_id` | VARCHAR(100) NULL | `post_id` de Facebook / `ig_media_id` |
| `remote_url` | TEXT NULL | Permalink de la publicación |
| `remote_comment_id` | VARCHAR(100) NULL | |
| `container_id` | VARCHAR(100) NULL | Contenedor de Instagram en curso |
| `attempts`, `comment_attempts` | SMALLINT UNSIGNED | |
| `scheduled_at`, `published_at` | DATETIME NULL | |
| `error_code` | VARCHAR(40) NULL | Clase normalizada + código Graph |
| `error_message` | TEXT NULL | |
| `source` | VARCHAR(20) | `auto`, `manual`, `cli`, `test` |
| `created_at`, `updated_at` | DATETIME | UTC |

Índices: `status`, `(channel_id, status)`, `scheduled_at`, `(status, scheduled_at)`.

**`voceador_log`**

`id`, `created_at`, `level` (`debug`, `info`, `warning`, `error`), `channel_id` NULL, `post_id` NULL, `job_id` NULL, `event` VARCHAR(60), `message` TEXT, `context` LONGTEXT (JSON saneado: se redactan `access_token`, `client_secret`, `code` y cualquier clave que coincida con `/token|secret/i`). Índices: `created_at`, `(channel_id, created_at)`, `post_id`, `level`. Purga diaria según retención.

### Opciones (`autoload = no` salvo indicación)

| Opción | Contenido |
| --- | --- |
| `voceador_db_version` | Versión del esquema (autoload sí) |
| `voceador_app` | App ID, App Secret cifrado, versión de Graph API; ID/secret de la app de Instagram Login si es distinta |
| `voceador_settings` | Defaults globales: `rules`, `templates`, `image`, `execution`, `editor`, `notifications`, `log`, `uninstall` (misma forma que `channels.settings`) |
| `voceador_link_in_bio` | Activación, slug(s), modo (por cuenta/compartida), UTM, nº de tarjetas, usar imagen de Instagram |
| `voceador_wizard` | Paso actual, pasos completados, `dismissed`, `completed_at` |
| `voceador_notices` | Avisos persistentes del admin |
| `voceador_crypto_seed` | Semilla de la clave de cifrado, solo si no hay salts utilizables (ver Cifrado) |
| `voceador_cron_last_run` | Marca de la última ejecución de la cola, para detectar cron del sistema |
| `voceador_network_defaults` (`site_option`) | Defaults de red |

Transients: `voceador_lock_job_{id}`, `voceador_lock_comment_{id}`, `voceador_oauth_state_{hash}` (10 min, ligado al usuario), `voceador_activation_redirect`, `voceador_ig_usage_{channel_id}`.

### Post meta

Registrados con `register_post_meta` (`show_in_rest`, `single`, `auth_callback` con `edit_post`): `_voceador_message_fb` (string), `_voceador_message_ig` (string), `_voceador_skip` (boolean), `_voceador_channels` (array de IDs; vacío = aplicar reglas), `_voceador_ig_image` (ID de adjunto).

Internos, no expuestos en REST: `_voceador_ig_cache` (`{attachment_id, source_hash, settings_hash, file, url}`) y `_voceador_first_published` (marca de primera publicación; evita redisparar en `publish → draft → publish` salvo que la opción de republicados esté activa).

El estado por canal vive en `voceador_jobs`, no en post meta.

### Capacidad, cron y desinstalación

- `voceador_manage` se añade a `administrator` al activar.
- Cron diario: `voceador_check_tokens`, `voceador_refresh_ig_tokens` (renueva tokens con menos de 10 días de vida), `voceador_purge_log`.
- Cron horario: `voceador_sweep` (reprograma trabajos vencidos y marca como `unverified` los atascados en `running`).
- `uninstall.php`: borra opciones, transients, las tres tablas, la capacidad, los crons y las imágenes generadas; los metas `_voceador_*` solo si `settings.uninstall.delete_meta` está activo. En Multisite recorre todos los sitios.

### Cifrado

`sodium_crypto_secretbox` con nonce aleatorio por valor; formato `v1:` + base64( nonce + cifrado ). La clave de 32 bytes es `sodium_crypto_generichash( material )`, donde `material` es, por orden: `VOCEADOR_ENCRYPTION_KEY` si está definida (cualquier longitud); si no, las constantes `AUTH_KEY . SECURE_AUTH_KEY` cuando ambas existen y no son el marcador por defecto de `wp-config-sample.php`; si no, una semilla aleatoria persistida una sola vez en la opción `voceador_crypto_seed` (autoload no). No se usa `wp_salt()` porque con salts de marcador devuelve un valor aleatorio por proceso. Si el descifrado falla (salts cambiadas), el canal pasa a `error` con aviso "Reconectar"; nunca un fatal.

## 4. Errores y reintentos

`GraphClient` normaliza cada respuesta a una clase (mapa ajustable con el filtro `voceador_error_map`):

| Clase | Origen | Acción de `Publisher` |
| --- | --- | --- |
| `transient` | Graph 1, 2, 4, 17, 32, 341; HTTP 5xx; timeouts | Reintento con backoff exponencial (default 5 intentos, base 2 min, configurable) |
| `rate_limited` | Límite de publicación agotado; cabeceras de uso altas | Reprograma para cuando haya cupo; no consume intentos |
| `auth` | 190 y subcódigos | No reintenta; pausa el canal, aviso persistente, correo |
| `permission` | 10, 200 | Igual que `auth` |
| `media` | Imagen inaccesible, aspecto inválido, contenedor `ERROR`/`EXPIRED` | No reintenta; error visible en el editor con sugerencia |
| `spam` | 368 | No reintenta; la ayuda recomienda subir el retraso del comentario |
| `fatal` | 100 y resto | Falla y registra |

Reglas de consistencia:

- El comentario es un paso aparte con su propio contador; su fallo nunca cambia `status = published`.
- El sondeo del contenedor de Instagram no usa `sleep`: cada sondeo es una acción reprogramada (default cada 10 s, hasta 5 min) y el `container_id` se guarda para retomarlo.
- Si `publish` pudo llegar a Meta pero la respuesta se perdió, no se republica a ciegas: el trabajo queda `failed` con aviso de verificar en la red, y "Reintentar" pide confirmación.
- Lock: transient con TTL más transición atómica `UPDATE … SET status='running' WHERE id=? AND status IN ('pending','failed','rate_limited')`; cero filas afectadas significa que otro proceso ya lo tomó.

## 5. REST interno (`voceador/v1`)

| Ruta | Capacidad | Uso |
| --- | --- | --- |
| `GET posts/{id}/status` | `edit_post` | Estado por canal para el editor |
| `POST posts/{id}/preview` | `edit_post` | Caption final, avisos de límites, miniatura del recorte |
| `POST posts/{id}/channels/{cid}/publish` · `retry` · `retry-comment` | `edit_post` | Acciones manuales |
| `GET channels`, `POST channels/{cid}/check` · `pause` · `resume` | `voceador_manage` | Ajustes |
| `GET media/{token}` | Pública, URL firmada con HMAC y expirable | Sirve la imagen a Meta cuando uploads no es accesible |

Los callbacks OAuth van por `admin-post.php?action=voceador_oauth_{fb|ig}` con verificación de `state` y de `voceador_manage`.

## 6. Pruebas

- PHPUnit con la suite de WordPress dentro de wp-env; desarrollo con TDD.
- Graph API simulada con `pre_http_request` y fixtures de respuestas reales.
- Validación real en staging al cerrar las fases 1, 2, 4 y 5. Requiere una app de Meta, una Página de prueba y una cuenta de Instagram profesional de prueba.
- Al inicio de las fases 1, 2 y 4 se verifican endpoints, permisos, límites y versión de Graph API contra la documentación vigente de Meta; todo ello queda configurable.

## 7. Fases

Cada fase tiene su propio plan de implementación y se revisa antes de pasar a la siguiente.

| Fase | Entrega | Criterio de cierre |
| --- | --- | --- |
| 0 | wp-env, PHPUnit, PHPCS (WPCS), esqueleto con autoloader, `Schema`, `uninstall.php` | El plugin activa, crea tablas y los tests pasan |
| 1a | Contenedor perezoso, `Crypto` (adelantado: ningún token se guarda en claro ni en staging), contratos de canal y registry, repositorios, `GraphClient`, `Logger` mínimo, `Settings` mínimo | Tests con mocks; `DB_VERSION` 2 (índice `(status, scheduled_at)` en `jobs`) ejercita la ruta de upgrade |
| 1b | `Templates` mínimo, `FacebookPageAdapter` con token manual, `Queue`, `Publisher`, disparador, CLI mínimo (`channels add/list`, `publish`, `status`) | En staging un post publica foto y comentario en una Página de prueba |
| 2 | `OAuth\Facebook`, `TokenManager`, cron de salud, avisos, "Reconectar" | OAuth completo en staging; revocar el token pausa el canal y avisa |
| 3 | `Rules`, `Templates`, `EditorIntegration` (bloques y clásico), REST de preview y estado | La vista previa coincide con lo publicado; la selección manual sobrescribe reglas |
| 4 | `OAuth\Instagram` (ambos métodos), `InstagramAdapter`, contenedores, límites, renovación de token | Publicación en una cuenta de Instagram de prueba por ambos métodos |
| 5 | `ImageProcessor` (JPEG, recorte/relleno, caché), `LinkInBio` | Imágenes fuera de rango salen válidas; `/ig` renderiza en móvil y modo oscuro |
| 6 | `Wizard` (11 pasos), `Help` (Markdown) | Wizard completo en sitio limpio, reanudable por paso |
| 7 | Columna y filtro de estado, log con CSV, `Notifications`, `CLI` completo, `SiteHealth`, import/export, README | Los comandos WP-CLI funcionan; export → import replica la configuración sin secretos |
| Opcional | Carrusel e historias de Instagram | — |

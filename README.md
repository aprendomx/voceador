# Voceador

**Voceador – Autopublicación de notas en redes sociales** es un plugin de WordPress que anuncia automáticamente en Páginas de Facebook y cuentas de Instagram cada nota que se publica por primera vez en un sitio de noticias.

> Estado: fase 2 completada: conexión con Facebook por OAuth desde el escritorio, credenciales cifradas y revisión diaria de la salud de los tokens con avisos. Falta Instagram, panel del editor, wizard y ajustes completos.

## Documentación

- [Diseño de arquitectura y esquema de datos](docs/superpowers/specs/2026-09-21-voceador-design.md)
- [Prompt original de requisitos](docs/prompt-original.md)

## Requisitos

PHP 8.1+, WordPress 6.5+ (single site o Multisite).

## Estructura del repositorio

La raíz del repositorio es la carpeta del plugin. `readme.txt` sigue el formato de WordPress.org y `.distignore` define qué queda fuera del ZIP distribuible.

## Desarrollo

Requisitos: Docker y Node 22+. No hace falta PHP ni Composer en el host; Composer se ejecuta dentro del contenedor y solo instala herramientas de desarrollo.

El directorio del repositorio debe llamarse `voceador`.

```bash
npm install
npm run env:start              # WordPress en http://localhost:8888 (admin / password)
npm run env:stop               # detiene el entorno
npm run composer -- install    # PHPUnit y PHPCS dentro del contenedor
npm run test                   # tests en single site
npm run test:multisite         # tests en Multisite
npm run lint                   # WordPress Coding Standards
npm run lint:fix               # corrige el formato automáticamente
```

`npm run test` excluye el grupo `ms-required`; esos tests solo corren con `npm run test:multisite`.

### WP-CLI

Con el plugin activo, `wp voceador` expone estos comandos:

```bash
wp voceador channels add-facebook --page-id=<id> [--token=<page-token>] [--token-file=<ruta|->] [--alias=<alias>]
wp voceador channels list [--format=<table|json|csv>]
wp voceador channels delete <id>
wp voceador channels check [<id>] [--all]
wp voceador publish <post_id> [--channel=<id>] [--source=<source>] [--force]
wp voceador retry <post_id> [--channel=<id>] [--comment] [--force]
wp voceador status
```

`channels add-facebook` valida el Page Access Token contra `/me` antes de guardar el canal; el token nunca se imprime ni se registra. Pásalo con `--token-file`, no con `--token`: la forma recomendada es leerlo por STDIN con `--token-file=-` para que no quede en el historial de shell ni en el log de sudo:

```bash
printf '%s' "$TOKEN" | wp voceador channels add-facebook --page-id=<id> --token-file=-
```

`--token-file=<ruta>` lee el token de un archivo; `--token` (menos recomendable) lo acepta en la propia línea de comandos.

`channels check [<id>]` revisa la salud del token de un canal o de todos, sin pausar nada: es solo informativo. Con `--all` ejecuta la misma revisión completa que el cron diario (`TokenManager::check_all()`), que sí pausa los canales cuyo token ya no sirve y añade el aviso correspondiente.

`publish` reintenta un post en los canales activos (o en uno concreto con `--channel`) y sale con código 1 si algún canal queda en `failed`; `--force` reintenta también trabajos marcados como no verificados (`unverified`). `retry` reintenta los trabajos ya existentes de un post que estén en `failed`, `skipped` o `rate_limited` (o, con `--comment`, el comentario de un trabajo ya publicado); acepta `--channel` y `--force` igual que `publish`. `status` resume canales por estado, trabajos por estado, si hay Action Scheduler o cron disponible, si la app de Meta está configurada (con su redirect URI) y los últimos eventos del log.

`npm run build:zip` genera el ZIP distribuible en `build-zip/`.

Para probar la desinstalación usa siempre `wp plugin uninstall voceador --skip-delete`: la carpeta del plugin en wp-env es este repositorio y sin ese flag WP-CLI borraría los archivos.

En la suite de tests el `Trigger` global está desenganchado; los tests que lo necesiten registran su propia instancia.

## Cifrado de credenciales

Los tokens de los canales se cifran con una clave derivada de `AUTH_KEY`/`SECURE_AUTH_KEY` (o de la constante `VOCEADOR_ENCRYPTION_KEY`, si se define; es la opción recomendada). Rotar esas salts invalida todos los canales conectados, que habrá que reconectar.

## Publicar en WordPress.org

El repositorio está preparado para enviarlo al directorio oficial: `readme.txt` en el formato requerido, `languages/voceador.pot`, Plugin Check en el CI y despliegue a SVN por etiqueta. Los pasos completos están en [docs/publicar-en-wordpress-org.md](docs/publicar-en-wordpress-org.md).

```bash
npm run check:plugin   # Plugin Check contra el paquete distribuible
npm run i18n           # regenera languages/voceador.pot
```

## Documentos legales

Meta exige que la app que conecta el plugin tenga URL públicas de política de privacidad, condiciones de servicio e instrucciones de eliminación de datos. Los textos están en `docs/legal/` y pueden publicarse tal cual (por ejemplo en GitHub Pages o en una página del sitio):

- [Política de privacidad](docs/legal/politica-de-privacidad.md)
- [Condiciones de servicio](docs/legal/condiciones-de-servicio.md)
- [Eliminación de datos](docs/legal/eliminacion-de-datos.md)

## Licencia

GPL-2.0-or-later. Ver [LICENSE](LICENSE).

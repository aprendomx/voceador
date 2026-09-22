# Voceador

**Voceador – Autopublicación de notas en redes sociales** es un plugin de WordPress que anuncia automáticamente en Páginas de Facebook y cuentas de Instagram cada nota que se publica por primera vez en un sitio de noticias.

> Estado: fase 1a completada (contenedor, cifrado, ajustes, contratos de canal, repositorios, log y cliente de Graph API). El plugin todavía no publica en redes; eso llega en la fase 1b.

## Documentación

- [Diseño de arquitectura y esquema de datos](docs/superpowers/specs/2026-09-21-voceador-design.md)
- [Prompt original de requisitos](docs/prompt-original.md)

## Requisitos

PHP 8.1+, WordPress 6.4+ (single site o Multisite).

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

Para probar la desinstalación usa siempre `wp plugin uninstall voceador --skip-delete`: la carpeta del plugin en wp-env es este repositorio y sin ese flag WP-CLI borraría los archivos.

En la suite de tests el `Trigger` global está desenganchado; los tests que lo necesiten registran su propia instancia.

## Cifrado de credenciales

Los tokens de los canales se cifran con una clave derivada de `AUTH_KEY`/`SECURE_AUTH_KEY` (o de la constante `VOCEADOR_ENCRYPTION_KEY`, si se define; es la opción recomendada). Rotar esas salts invalida todos los canales conectados, que habrá que reconectar.

## Documentos legales

Meta exige que la app que conecta el plugin tenga URL públicas de política de privacidad, condiciones de servicio e instrucciones de eliminación de datos. Los textos están en `docs/legal/` y pueden publicarse tal cual (por ejemplo en GitHub Pages o en una página del sitio):

- [Política de privacidad](docs/legal/politica-de-privacidad.md)
- [Condiciones de servicio](docs/legal/condiciones-de-servicio.md)
- [Eliminación de datos](docs/legal/eliminacion-de-datos.md)

## Licencia

GPL-2.0-or-later. Ver [LICENSE](LICENSE).

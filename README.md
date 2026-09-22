# Voceador

**Voceador – Autopublicación de notas en redes sociales** es un plugin de WordPress que anuncia automáticamente en Páginas de Facebook y cuentas de Instagram cada nota que se publica por primera vez en un sitio de noticias.

> Estado: fase 0 completada (entorno, esquema de datos, instalación y desinstalación). El plugin todavía no publica en redes.

## Documentación

- [Diseño de arquitectura y esquema de datos](docs/superpowers/specs/2026-09-21-voceador-design.md)
- [Prompt original de requisitos](docs/prompt-original.md)

## Requisitos

PHP 8.1+, WordPress 6.4+ (single site o Multisite), extensión libsodium.

## Estructura del repositorio

La raíz del repositorio es la carpeta del plugin. `readme.txt` sigue el formato de WordPress.org y `.distignore` define qué queda fuera del ZIP distribuible.

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

## Licencia

GPL-2.0-or-later. Ver [LICENSE](LICENSE).

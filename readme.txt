=== Voceador – Autopublicación de notas en redes sociales ===
Contributors: aprendomx
Tags: social media, auto publish, news, facebook, instagram
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Anuncia automáticamente cada nota nueva en tus Páginas de Facebook y cuentas de Instagram, con plantillas, reglas por canal y página de link en bio.

== Description ==

Voceador anuncia en redes sociales cada nota que se publica por primera vez en tu sitio de noticias.

* **Páginas de Facebook**: publica la imagen destacada como foto con un caption generado desde plantilla y deja un comentario de la Página con el enlace a la nota.
* **Cuentas de Instagram profesionales**: publica la imagen destacada adaptada a los requisitos de Instagram, con caption y primer comentario opcional, y genera una página de "link en bio".
* **Canales**: cada destino tiene sus propias credenciales, reglas, plantillas y estado de salud. Un fallo en un canal no bloquea a los demás.
* **Pensado para redacciones**: panel "Redes" en el editor, vista previa del caption, estado por canal y reintentos manuales.
* **Para redes de medios**: compatible con Multisite, import/export de configuración y comandos WP-CLI.

Este plugin está en desarrollo activo; todavía no hay una versión estable.

== External services ==

Este plugin se conecta a la Graph API de Meta (graph.facebook.com y graph.instagram.com) para publicar contenido en las Páginas de Facebook y cuentas de Instagram que el administrador conecte de forma explícita.

* Qué se envía: el texto del caption y del comentario, la imagen destacada (o su URL pública) y los tokens de acceso de los canales conectados.
* Cuándo: al publicarse un post que cumpla las reglas configuradas, al ejecutar acciones manuales de publicación y en la revisión diaria de salud de los tokens.
* No se envía ningún dato hasta que un administrador conecta un canal.

Servicio provisto por Meta Platforms, Inc.: [Términos de la plataforma](https://developers.facebook.com/terms/) · [Política de privacidad](https://www.facebook.com/privacy/policy/).

== Installation ==

1. Sube la carpeta `voceador` a `/wp-content/plugins/` o instala el ZIP desde Plugins → Añadir nuevo.
2. Activa el plugin.
3. Sigue el asistente de configuración para conectar tu app de Meta y tus canales.

== Frequently Asked Questions ==

= ¿Necesito una app de Meta? =

Sí. Cada sitio se conecta con una app de Meta propia o compartida. La ayuda integrada explica cómo crearla paso a paso.

= ¿Funciona con cuentas personales de Instagram? =

No. Instagram solo permite publicar por API en cuentas profesionales (Business o Creator).

== Changelog ==

= 0.1.0 =
* Versión inicial en desarrollo.

# Instrucciones para la eliminación de datos

**Última actualización:** 22 de septiembre de 2026

Voceador – Autopublicación de notas en redes sociales es un plugin de WordPress que se ejecuta en el servidor del sitio donde se instala. **Aprendo MX no almacena ningún dato de los sitios que usan el plugin ni de las personas que interactúan con sus publicaciones**, así que no hay nada que podamos borrar por ti: los datos existen únicamente en tu WordPress y en tu cuenta de Meta.

A continuación se explica cómo eliminar cada tipo de dato según quién seas.

## Si eres el operador de un sitio con Voceador instalado

### Desconectar un canal (Página de Facebook o cuenta de Instagram)

1. Entra en tu WordPress como administrador.
2. Ve a **Ajustes → Voceador → Canales**.
3. En el canal que quieras eliminar, pulsa **Desconectar**.

Al desconectar un canal se borran de tu base de datos su token de acceso cifrado, sus permisos, su nombre, su foto de perfil y su configuración. Las publicaciones ya realizadas en la red social no se borran: sigue el apartado "Eliminar publicaciones" más abajo.

Con WP-CLI: `wp voceador channels delete <id>`.

### Borrar el registro de actividad

1. Ve a **Ajustes → Voceador → Registro**.
2. Pulsa **Vaciar registro**, o ajusta la retención en días para que se purgue automáticamente.

### Eliminar todos los datos del plugin

1. Ve a **Plugins**, desactiva **Voceador** y pulsa **Borrar**.

La desinstalación elimina de forma permanente:

- Todas las credenciales y canales conectados (App ID, App Secret y tokens, todos cifrados).
- Las tablas del plugin: canales, trabajos de publicación y registro de actividad.
- Todas sus opciones, transients, tareas programadas y la semilla de cifrado.
- Las imágenes generadas para Instagram (`uploads/voceador/`).
- Los metadatos añadidos a las notas (`_voceador_*`) si antes activaste **Ajustes → Voceador → Desinstalación → Borrar también los metadatos de las notas**. Si no, se conservan por si reinstalas el plugin y puedes borrarlos con `wp post meta delete` o con cualquier plugin de limpieza.

Con WP-CLI: `wp plugin deactivate voceador && wp plugin uninstall voceador`.

### Revocar el acceso desde Meta

Además de desconectar el canal en WordPress, puedes revocar el acceso desde tu cuenta de Meta para que el token deje de ser válido aunque alguien conserve una copia:

- **Facebook**: Configuración y privacidad → Configuración → Apps y sitios web → selecciona la app → Eliminar.
- **Instagram**: Configuración → Seguridad → Apps y sitios web → Activas → Eliminar.
- Si la app de Meta es tuya, también puedes restablecer el App Secret o eliminar la app en <https://developers.facebook.com/apps/>.

### Eliminar publicaciones creadas por el plugin

Las publicaciones y comentarios que el plugin creó pertenecen a tu Página o cuenta y se borran desde Facebook o Instagram como cualquier otra publicación. El plugin guarda el identificador de cada publicación en la nota correspondiente (panel "Redes" del editor) para que puedas localizarla.

## Si eres usuario de Facebook o Instagram

Voceador no recoge datos tuyos. Las publicaciones que ves las realiza el medio de comunicación titular de la Página o cuenta. Para solicitar la retirada de una publicación o ejercer tus derechos sobre tus datos, contacta con ese medio; sus datos de contacto suelen estar en su sitio web o en la información de su Página.

Si en algún momento autorizaste una app de Meta relacionada con un medio y quieres revocar ese permiso, hazlo desde **Apps y sitios web** en la configuración de tu cuenta de Facebook o Instagram.

## Si eres desarrollador y usas la app de Meta de un tercero

Si conectaste tus Páginas usando una app de Meta que no es tuya (por ejemplo, la de la red de medios a la que perteneces), el administrador de esa app puede ver qué Páginas la autorizaron pero no tiene acceso a tus tokens, que están cifrados en tu WordPress. Para revocar la autorización usa el apartado "Revocar el acceso desde Meta".

## Plazo

Todas las eliminaciones descritas son inmediatas y las realiza el operador del sitio. Aprendo MX no interviene ni puede intervenir, porque no tiene acceso a las instalaciones.

## Contacto

Si tienes dudas sobre estas instrucciones o sobre el funcionamiento del plugin: Aprendo MX, [correo de contacto]. Para solicitudes sobre los datos de un sitio concreto, dirígete al operador de ese sitio.

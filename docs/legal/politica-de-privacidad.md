# Política de privacidad de Voceador

**Última actualización:** 22 de septiembre de 2026

Voceador – Autopublicación de notas en redes sociales ("Voceador" o "el plugin") es un plugin de WordPress desarrollado por Aprendo MX ("nosotros"). Se instala en sitios de noticias y anuncia automáticamente en Facebook e Instagram las notas que esos sitios publican.

Esta política explica qué datos trata el plugin, con qué fin, dónde se guardan y qué derechos tienes. Aplica a dos tipos de personas:

- **Operadores del sitio**: quienes instalan y configuran el plugin en su WordPress y conectan sus Páginas de Facebook o cuentas de Instagram.
- **Lectores y usuarios de redes sociales**: quienes ven las publicaciones que el plugin genera.

## 1. Quién es responsable de los datos

El plugin se ejecuta íntegramente en el servidor de WordPress donde se instala. **El operador del sitio es el responsable del tratamiento** de los datos que el plugin maneja. Aprendo MX desarrolla y distribuye el software, pero no tiene acceso a las instalaciones ni a los datos que procesan.

El plugin **no envía ninguna información a Aprendo MX ni a terceros distintos de Meta**. No contiene telemetría, analítica, identificadores de instalación ni llamadas de "teléfono a casa".

## 2. Qué datos trata el plugin

### 2.1 Credenciales de conexión con Meta

Para publicar en nombre de una Página de Facebook o una cuenta de Instagram profesional, el operador conecta su propia app de Meta y autoriza al plugin. El plugin guarda:

- El identificador de la app de Meta (App ID) y su clave secreta (App Secret).
- Los tokens de acceso de cada Página o cuenta conectada, los permisos concedidos, el nombre público, la foto de perfil y la fecha de conexión.

Estos datos se almacenan en la base de datos del sitio **cifrados con libsodium**. El plugin nunca los muestra completos en la interfaz ni los escribe en sus registros. Los tokens de usuario obtenidos durante la autorización se usan solo para listar las Páginas disponibles y se descartan; únicamente se conservan los tokens de Página o de cuenta.

### 2.2 Contenido editorial

Al publicarse una nota, el plugin toma del propio WordPress el título, el extracto, el enlace, la imagen destacada, el autor, las categorías y los textos que la redacción escribe en el panel "Redes" del editor. Con ellos genera el texto y la imagen de la publicación y los envía a la API de Meta.

### 2.3 Registro de actividad

El plugin guarda en una tabla propia un registro de cada intento de publicación: fecha, canal, nota, resultado y, en caso de error, el mensaje devuelto por Meta. Este registro sirve para diagnosticar problemas. **Nunca contiene tokens ni secretos**: el plugin los elimina automáticamente de cualquier mensaje antes de guardarlo. El operador configura cuánto tiempo se conserva (30 días por defecto).

### 2.4 Datos de identificación de publicaciones

Por cada nota y canal, el plugin guarda el identificador de la publicación creada en Facebook o Instagram y el del comentario, para no publicar dos veces y para mostrar el estado en el editor.

### 2.5 Datos que el plugin no trata

El plugin **no recoge datos de los lectores** del sitio ni de las personas que ven las publicaciones en redes sociales. No lee mensajes, comentarios de terceros, seguidores ni estadísticas de audiencia. No usa cookies propias.

La página opcional de "link en bio" que el plugin genera es una página pública normal del sitio de WordPress; su visita se rige por la política de privacidad del sitio.

## 3. Con qué finalidad y base legal

Los datos descritos se tratan con la única finalidad de publicar automáticamente las notas del sitio en los canales que el operador ha conectado y de mostrarle el resultado. La base legal es el interés legítimo del operador en difundir su contenido y la ejecución de la relación que el operador tiene con Meta al usar su plataforma.

## 4. Con quién se comparten

Los datos se envían exclusivamente a **Meta Platforms, Inc.** a través de su Graph API (`graph.facebook.com` y `graph.instagram.com`) cuando se publica una nota, se deja un comentario o se revisa la validez de un token. Meta trata esos datos según sus propios términos:

- Términos de la plataforma de Meta: <https://developers.facebook.com/terms/>
- Política de privacidad de Meta: <https://www.facebook.com/privacy/policy/>

No se comparten datos con nadie más.

## 5. Dónde se guardan y por cuánto tiempo

Todo se guarda en la base de datos y en la carpeta de archivos del propio sitio de WordPress, en el servidor que el operador controla. Las imágenes adaptadas para Instagram se guardan en la carpeta `uploads/voceador/` del sitio.

- Credenciales y datos de canales: mientras el canal siga conectado.
- Identificadores de publicaciones: mientras exista la nota.
- Registro de actividad: según la retención configurada (30 días por defecto).
- Al desinstalar el plugin se eliminan todas sus tablas, opciones, credenciales, registros e imágenes generadas. Los metadatos añadidos a las notas se conservan salvo que el operador active su borrado.

## 6. Seguridad

- Cifrado de credenciales con libsodium y clave derivada de las claves secretas del sitio o de una constante propia definida por el operador.
- Los tokens viajan a Meta únicamente en la cabecera de autorización HTTP, nunca en URLs.
- Acceso a la configuración restringido a usuarios con la capacidad `voceador_manage` (administradores por defecto) y protección con nonces.
- Redacción automática de secretos en todos los registros.

## 7. Tus derechos

- **Operadores**: pueden ver, exportar (sin secretos), modificar y borrar toda la configuración desde el panel del plugin, desconectar cualquier canal en cualquier momento y desinstalar el plugin. Consulta las [instrucciones para la eliminación de datos](eliminacion-de-datos.md).
- **Usuarios de Facebook o Instagram**: las publicaciones creadas pertenecen a la Página o cuenta del operador; para solicitar su retirada, contacta con el medio que las publica. Puedes revocar el acceso de la app del operador desde la configuración de tu cuenta de Meta ("Apps y sitios web").
- Si resides en una jurisdicción con derechos de acceso, rectificación, supresión, oposición o portabilidad (por ejemplo, la Unión Europea o México), puedes ejercerlos ante el operador del sitio, que es el responsable del tratamiento.

## 8. Menores

El plugin no está dirigido a menores y no trata datos de menores de forma intencionada.

## 9. Cambios en esta política

Publicaremos cualquier cambio en este mismo documento, dentro del repositorio del plugin, con la fecha de actualización. Los cambios relevantes se anunciarán en las notas de cada versión.

## 10. Contacto

Para preguntas sobre el software y esta política: Aprendo MX, [correo de contacto]. Para preguntas sobre los datos de un sitio concreto, contacta con el operador de ese sitio.

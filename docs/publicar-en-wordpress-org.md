# Publicar Voceador en WordPress.org

Guía de los pasos para enviar el plugin al directorio oficial y, una vez aprobado, desplegar cada versión.

## 1. Antes de enviar

El equipo de revisión prueba **una versión funcional**. Mientras el plugin solo se configure por WP-CLI, un revisor no podrá usarlo. El momento razonable para enviarlo es cuando exista la pantalla de ajustes y la conexión por OAuth (fases 2 y 3 del plan), idealmente con el wizard (fase 6). Hasta entonces, la distribución es por ZIP o Git desde este repositorio.

### Comprobaciones automáticas

```bash
npm run lint          # WordPress Coding Standards
npm run test          # suite single site
npm run test:multisite
npm run check:plugin  # Plugin Check, la misma herramienta que usa la revisión
npm run build:zip     # genera build-zip/voceador.zip
```

`npm run check:plugin` construye el paquete distribuible y ejecuta Plugin Check **contra él**, no contra la raíz del repositorio: el ZIP no lleva `.github`, `tests/`, `phpunit.xml.dist` ni los demás archivos de desarrollo que la herramienta marca como no permitidos. Falla si aparece cualquier error; los avisos se muestran sin detener el build.

Avisos que hoy quedan abiertos y son esperados: cinco `PluginCheck.Security.DirectDB.UnescapedDBParameter` en `Schema`, `ChannelRepository` y `Logger`, donde se interpola el nombre de una tabla o de un índice procedente de una lista cerrada del propio plugin. Cada uno lleva su `phpcs:ignore` con la razón; si la revisión pregunta, esa es la respuesta.

### Contenido obligatorio

- **`readme.txt` en inglés.** El directorio es un sitio en inglés y Plugin Check rechaza descripciones en otro idioma. La interfaz del plugin sigue en español mediante i18n; las traducciones del propio readme se gestionan después en translate.wordpress.org.
- **`Tested up to`** igual a la versión actual de WordPress. Si se queda atrás, el plugin deja de aparecer en las búsquedas.
- **`Stable tag`** igual a la versión de la cabecera de `voceador.php`. El workflow de despliegue comprueba que ambos coinciden con la etiqueta de Git.
- **Sección `== External services ==`** declarando la Graph API de Meta: qué se envía, cuándo y enlaces a los términos y la privacidad de Meta. Es obligatoria para cualquier plugin que llame a un servicio externo.
- **`languages/voceador.pot`** generado con `npm run i18n` después de tocar cadenas traducibles.

### Recursos gráficos

Van en `.wordpress-org/` (excluido del ZIP, lo sube el workflow a `assets/` en SVN):

| Archivo | Tamaño | Uso |
| --- | --- | --- |
| `icon-128x128.png` | 128×128 | Icono en los listados |
| `icon-256x256.png` | 256×256 | Icono en pantallas de alta densidad |
| `banner-772x250.png` | 772×250 | Cabecera de la ficha |
| `banner-1544x500.png` | 1544×500 | Cabecera en alta densidad |
| `screenshot-1.png`, `screenshot-2.png`… | libre | Capturas, numeradas en el orden de `== Screenshots ==` del readme |

Sin marcas de Meta en el icono ni en el banner: las directrices de Meta no permiten usar su logotipo como identidad de un producto de terceros.

## 2. Enviar

1. Crear una cuenta en <https://login.wordpress.org/register>. El nombre de usuario debe coincidir con la línea `Contributors:` del readme (hoy `aprendomx`).
2. Generar el ZIP con `npm run build:zip`.
3. Subirlo en <https://wordpress.org/plugins/developers/add/>. El slug se asigna a partir del nombre del plugin; aquí sería `voceador`.
4. Esperar la revisión manual. Puede tardar de unos días a varias semanas. El equipo responde por correo con una lista de correcciones; se contesta en el mismo hilo adjuntando un ZIP corregido.
5. Al aprobarlo llega el acceso al repositorio SVN `https://plugins.svn.wordpress.org/voceador`.

Motivos de rechazo frecuentes que este plugin ya cubre: falta de sanitización o de escapes, ausencia de nonces, funciones sin prefijo, servicios externos no declarados, código ofuscado, y usar una marca ajena al principio del nombre o del slug.

## 3. Desplegar cada versión

El despliegue lo hace `.github/workflows/deploy.yml` al empujar una etiqueta `vX.Y.Z`:

```bash
# 1. Subir la versión en los dos sitios donde vive
#    - voceador.php  ->  * Version: 0.2.0
#    - readme.txt    ->  Stable tag: 0.2.0
# 2. Añadir la entrada al == Changelog == del readme
npm run i18n && npm run check:plugin
git commit -am "chore: versión 0.2.0"
git tag v0.2.0
git push origin main v0.2.0
```

El workflow comprueba que la etiqueta coincide con la cabecera y con `Stable tag`, y sube el código a `trunk/` y a `tags/0.2.0/`, más los recursos de `.wordpress-org/` a `assets/`.

Antes del primer despliegue hay que añadir en GitHub (Settings → Secrets and variables → Actions) los secrets `SVN_USERNAME` y `SVN_PASSWORD` con las credenciales de WordPress.org. Mientras no existan, el workflow se ejecuta pero omite el despliegue con un aviso.

## 4. Lo que depende de Meta, en paralelo

- Publicar la [política de privacidad](legal/politica-de-privacidad.md), las [condiciones de servicio](legal/condiciones-de-servicio.md) y las [instrucciones de eliminación de datos](legal/eliminacion-de-datos.md) en URL públicas, y registrarlas en la configuración de la app. Falta rellenar el correo de contacto y la ciudad.
- App Review de Meta con `pages_show_list`, `pages_read_engagement`, `pages_manage_posts` y `pages_manage_engagement` (más los de Instagram cuando llegue la fase 4) y verificación de negocio, solo si se quiere que medios ajenos a la app conecten sus Páginas. Sin App Review, cada medio necesita crear su propia app, que es lo que guiará el wizard.

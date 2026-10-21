# Guía de mantenimiento

Cómo está armado el plugin y cómo modificarlo sin romperlo.

## 1. Mapa de archivos

| Archivo | Qué hace | Tócalo cuando… |
|---|---|---|
| `yt-to-streamit.php` | Arranque: cabecera, versión, carga los demás archivos | Subes de versión |
| `includes/settings.php` | Lee ajustes (`yts_settings()`), API key, validación de IDs | Agregas un ajuste nuevo |
| `includes/streamit.php` | **Único lugar que habla con Streamit** (funciones y tablas internas) | Streamit cambia de versión |
| `includes/youtube.php` | Lee playlists: API oficial + RSS con reintentos | YouTube cambia algo |
| `includes/thumbnails.php` | Descarga la miniatura y la convierte a WebP | Cambias calidad o formato |
| `includes/notifications.php` | Correo al llegar al capítulo N | Cambias el aviso |
| `includes/importer.php` | Sincroniza, crea episodios, enlaza a temporada, repara, bloqueo | Cambias cómo se importa |
| `includes/cron.php` | Frecuencias disponibles y programación de la tarea | Agregas una frecuencia nueva |
| `includes/diagnostics.php` | Comprobaciones de la «Verificación del sistema» | Agregas una comprobación |
| `includes/admin/menu.php` | Menú, pestañas, encabezado común y carga de CSS/JS | Agregas una sección nueva |
| `includes/admin/page-programs.php` | **Programas sincronizados** (página principal): tabla, buscador, paginación, importación | Cambias la tabla de programas |
| `includes/admin/page-settings.php` | **Ajustes**: frecuencia, correo, video, modelo, autor, WebP, API key | Agregas un ajuste |
| `includes/admin/page-tools.php` | **Herramientas**: acciones manuales y visor de campos | Agregas una herramienta |
| `includes/admin/page-imported.php` | **Episodios importados** | Cambias esa lista |
| `includes/admin/page-verify.php` | **Verificación del sistema** (botones + informe) | Cambias su presentación |
| `includes/admin/components.php` | Piezas compartidas: botón Cerrar, informe, registro | Cambias el informe o el registro |
| `includes/admin/actions.php` | Guarda por secciones y atiende los botones | Agregas un botón o un ajuste |
| `assets/admin.js` | Selector con buscador, paginación y filtro, agregar/quitar filas | Cambias el comportamiento del panel |
| `assets/admin.css` | Estilos del panel | Cambias el diseño |
| `uninstall.php` | Limpieza al borrar el plugin | Agregas opciones nuevas |
| `tests/smoke-test.php` | Prueba rápida de la lógica PHP, sin WordPress | Agregas lógica nueva |
| `tests/panel-test.js` | Prueba del JavaScript del panel (opcional, usa jsdom) | Cambias `assets/admin.js` |

Dependencias entre archivos: `importer` usa `youtube`, `streamit`, `thumbnails` y `notifications`; el panel (`admin-*`, `diagnostics`) usa al resto. Los archivos del panel solo se cargan en el escritorio de WordPress; el cron carga solo el núcleo.

## 2. Reglas de oro

1. **Nunca edites en el sitio en producción.** Cambia, prueba en un entorno de pruebas y recién entonces sube.
2. **Haz copia de seguridad** antes de actualizar (al menos de las tablas `*_streamit_*`).
3. Todo lo que toque Streamit va en `includes/streamit.php` (o usa sus funciones desde `importer.php`). No escribas SQL contra las tablas de Streamit en otros archivos sin necesidad.
4. Lo que viene de fuera (YouTube, formularios) se **valida al entrar** y se **escapa al mostrar**.

## 3. Flujo para hacer un cambio

1. Edita el archivo que corresponde (tabla de arriba).
2. Revisa la sintaxis: `for f in $(find . -name '*.php'); do php -l "$f"; done`
3. Corre `php tests/smoke-test.php`. Debe terminar en `TODO OK`. Si agregaste lógica, agrega su comprobación al final del archivo de pruebas. Si tocaste `assets/admin.js`, corre también `node tests/panel-test.js` (ver README).
4. En el entorno de pruebas: **Ajustes → YouTube a Streamit → Verificar + prueba de creación**. Todo en ✅ (o ⚠️ explicables).
5. Sincroniza con el estado en *Borrador* y revisa un episodio completo en Streamit.
6. Sube la versión en **dos** lugares: la cabecera de `yt-to-streamit.php` (`Version:`) y la constante `YTS_VERSION`. Anota el cambio en `CHANGELOG.md`.
7. Commit, etiqueta (`git tag v3.1.1`) y crea el ZIP de instalación (ver sección 7).

## 4. Recetas

### Agregar un ajuste nuevo
1. `settings.php` → agrega su valor por defecto en `yts_settings()`.
2. `admin/page-settings.php` → agrega el campo en la tabla que corresponda (`name="mi_ajuste"`). Si es de importación, va en `admin/page-programs.php`.
3. `admin/actions.php` → en `yts_handle_save`, en la sección `settings` (o `programs`), guárdalo **sanitizado** en `$s['mi_ajuste']`. Cada formulario solo cambia los campos de su sección.
4. Úsalo con `yts_settings()['mi_ajuste']`.
5. Si es una opción nueva distinta de `yts_settings`, agrégala también a `uninstall.php`.

### Agregar un botón
1. En la página donde va (`admin/page-*.php`) → `<button type="submit" form="yts-actions" name="yts_do" value="mi_accion" class="button">Texto</button>`
2. `admin/actions.php` → en `yts_handle_action()`, agrega `case 'mi_accion':` con tu lógica y termina con `yts_back( 'Mensaje', $ret );`. El botón vuelve solo a la página desde la que se pulsó.
3. No necesitas nonce ni permisos nuevos: el despachador ya los exige.

### Agregar una sección (pestaña) nueva al panel
1. Crea `includes/admin/page-mi-seccion.php` con una función `yts_page_mi_seccion()` que abra con `yts_admin_header( 'yts-mi-seccion' );` y cierre con `yts_admin_footer();` (copia `page-tools.php` como base).
2. `admin/menu.php` → agrégala a `yts_pages()` (slug => [ título, función ]). El menú y las pestañas se arman solos.
3. `yt-to-streamit.php` → agrega su `require_once` en el bloque del panel.

### Cambiar la frecuencia de sincronización
Se elige en el panel (**Frecuencia de sincronización**); al guardar, la tarea se reprograma sola. Por defecto es cada 12 horas.

Para agregar una frecuencia nueva (por ejemplo, cada 2 horas): en `cron.php` registra el intervalo en el filtro `cron_schedules` (`$s['yts_2h'] = [ 'interval' => 7200, 'display' => 'Cada 2 horas' ];`) y agrégalo a la lista de `yts_frequencies()`.

### Cambiar el máximo de episodios por pasada
Se configura en **Ajustes → Máximo por pasada** (por defecto 10 por programa). Si quedan videos pendientes, el plugin programa solo una continuación a los 2 minutos (evento `yts_sync_followup`). Para fijar el valor por código, que tiene prioridad sobre el ajuste, en el `functions.php` de tu tema hijo:
```php
add_filter( 'yts_max_imports_per_run', function () { return 25; } );
```

### Agregar una comprobación a la verificación
`diagnostics.php` → dentro de `yts_checks()`: `$add( 'ok' | 'warn' | 'fail' | 'info', 'Título', 'Detalle' );`

## 5. Si Streamit cambia de versión

Lo único que depende de Streamit está en `includes/streamit.php` y en `yts_import_video()` / `yts_repair()` de `importer.php`.

Funciones de Streamit que usa el plugin: `streamit_add_episode`, `streamit_add_episode_meta`, `streamit_update_episode_meta`, `streamit_delete_episode`, `streamit_get_tvshow_meta`, `streamit_add_tvshow_meta`, `streamit_update_tvshow_meta`, y la clase `Streamit_Tvshow` / `Streamit_Episode` (solo para limpiar caché).

Campos que escribe en cada episodio: `_episode_choice` (`episode_embed` o `episode_url`), `_episode_url_link`, `_episode_embed_content`, `_episode_number`, `_episode_release_date`, `thumbnail_id`, `tvshow_id`, y uno propio, `_yts_video_id`. En el programa escribe `_seasons` (lista de temporadas con los IDs de sus episodios).

Para revisar qué cambió, abre el plugin de Streamit nuevo y compara estos archivos con la versión anterior:
- `includes/functions/episode/streamit-episode-function.php` (funciones)
- `admin/controllers/class.streamit-episodes-controller.php` (cómo guarda un episodio)
- `admin/controllers/class.streamit-tvshow-controller.php` (cómo guarda `_seasons`)
- `includes/class-streamit-activator.php` (tablas)

Después corre «Verificar + prueba de creación»: si Streamit rechaza el episodio de prueba, el error exacto aparece en el informe.

## 6. Problemas conocidos

| Mensaje | Causa | Qué hacer |
|---|---|---|
| `HTTP 404 tras 3 intentos` | El feed RSS de YouTube falla de forma intermitente | Esperar, o configurar la API key |
| `exceeded your quota` | Cuota diaria de la API agotada | Esperar al reinicio (medianoche, hora del Pacífico) o revisar quién la consume en Google Cloud |
| `Omitido: … antes de la fecha de inicio` | La fecha es la de publicación del video, no la de agregarlo a la playlist | Ajustar *Importar videos desde* |
| `Pendiente: se alcanzó el límite…` | Más videos nuevos que el máximo por pasada | Normal; el plugin continúa solo a los 2 minutos (con WP-Cron, cuando haya una visita). También puedes presionar de nuevo **Sincronizar ahora** |
| `Ya hay una sincronización en curso` | Otra sincronización está corriendo | Esperar unos minutos (un bloqueo de más de 10 min se retoma solo) |
| Episodios importados que no se ven en el programa | No quedaron enlazados a una temporada | Botón **Reparar enlaces** |
| El cron no corre, o corre más tarde de lo esperado | WP-Cron solo se dispara con visitas, o está desactivado | Usar «Sincronizar ahora», o programar un cron real del hosting que llame a `wp-cron.php` |

## 7. Crear el ZIP de instalación

Desde la carpeta que contiene `yt-to-streamit/`:
```bash
zip -r yt-to-streamit-3.3.0.zip yt-to-streamit -x 'yt-to-streamit/tests/*' 'yt-to-streamit/.gitignore' 'yt-to-streamit/.git/*'
```
La carpeta dentro del ZIP debe llamarse `yt-to-streamit`. Súbelo como *Release* en GitHub; el ZIP automático de GitHub trae otro nombre de carpeta y no sirve para instalar directamente.

## 8. Ideas pendientes

- Traducciones (`__()` + archivos `.po`) con los textos base en inglés.
- Pasar las funciones a clases con namespace.
- Soporte de temporadas por playlist (hoy: una temporada elegida por programa).
- Notificaciones adicionales (Slack, WhatsApp) además del correo.

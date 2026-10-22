# YouTube a Streamit

Plugin de WordPress que **importa automáticamente los videos de tus playlists de YouTube como episodios de [Streamit](https://iqonic.design/)**, los deja dentro de su programa y temporada, y te **avisa por correo** cuando un programa llega al capítulo que definas (por ejemplo, el 13).

> Proyecto independiente. **No está afiliado ni respaldado por Iqonic ni por Streamit.** Streamit es un producto comercial de sus autores; este repositorio no incluye su código.

## Qué hace

- Un programa de Streamit = una playlist de YouTube. Cada video nuevo se crea como episodio, con título, descripción y miniatura (convertida a WebP si el servidor puede).
- Activa o desactiva programas con una palomita, o sincroniza uno solo a mano.
- Lee YouTube por su **API oficial** (recomendado) o por el **feed RSS** como respaldo, con reintentos.
- Menú propio **YouTube a Streamit** con seis secciones: *Programas sincronizados*, *Videos de la playlist*, *Ajustes*, *Herramientas*, *Episodios importados* y *Verificación del sistema*.
- **Elige qué videos importar** (video por video) y **detecta los episodios que ya tenías cargados**, aunque tengan otro título que en YouTube.
- Selector de programa **con buscador** y lista de programas **paginada** (pensado para muchos programas).
- Herramienta para **reparar** episodios que no quedaron enlazados a su programa.

## Requisitos

- WordPress 5.8 o superior y PHP 7.4 o superior.
- Plugin **Streamit 1.3.x** activo (probado con la 1.3.9).

## Instalación

1. Descarga el ZIP `yt-to-streamit-x.y.z.zip` (sección *Releases*).
2. En WordPress: **Plugins → Añadir nuevo → Subir plugin** y activa «YouTube a Streamit».
3. Abre **YouTube a Streamit** en el menú lateral de WordPress.

## Configuración rápida

1. **Ajustes:** elige cómo cargas el video hoy (código embed o URL, igual que tus episodios actuales), tu correo de aviso y la frecuencia. Guarda.
2. **Programas sincronizados → + Agregar programa:** busca y elige el programa de Streamit, pega la URL o el ID de su playlist y define el capítulo en que quieres el aviso.
3. En la misma página, define **Importar videos desde** (evita traer todo el historial) y **Estado al importar**. Empieza en *Borrador*. **Guardar cambios**.
4. **Verificación del sistema → Verificar + prueba de creación:** todo debería salir en verde.
5. Presiona **Sincronizar ahora**, revisa un episodio en Streamit y, cuando se vea bien, cambia a *Publicado*.

La sincronización automática corre **cada 12 horas** por defecto; puedes cambiarla en *Frecuencia de sincronización* (30 min, 1 h, 6 h, 12 h o 24 h). Usa WP-Cron, que solo se dispara cuando alguien visita el sitio. Para importar al instante, usa **Sincronizar ahora**.

## Si ya tienes episodios cargados

Antes de sincronizar por primera vez, el plugin necesita saber qué videos ya están en tu sitio para no duplicarlos:

- **Detección automática:** el plugin lee el enlace o el código embed de cada episodio de Streamit y reconoce los videos de YouTube que ya están cargados, **aunque el título sea distinto**. Esos videos se omiten solos y se muestran con un enlace al episodio.
- **Selección a mano:** en *Programas sincronizados*, cambia la columna **Videos a importar** a «Solo los que elija» y presiona **Elegir videos**. Verás la playlist con una casilla por video: marca los que quieras y usa **Guardar e importar los seleccionados ahora**. En modo «Todos los nuevos» se importa todo lo nuevo, salvo lo que desmarques.
- Si algún episodio antiguo tiene el video **subido como archivo** (no como enlace de YouTube), no se puede reconocer; en ese caso usa el modo manual y deja sin marcar ese video.
- Lo que marcas a mano manda sobre la fecha *Importar videos desde*.

## API key de YouTube (recomendada)

El feed RSS de YouTube falla de forma intermitente (responde 404 durante horas). Con una API key gratuita el plugin lee las playlists por la API oficial y deja el RSS de respaldo.

1. En [Google Cloud](https://console.cloud.google.com/) crea un proyecto y habilita **YouTube Data API v3**.
2. Crea una **clave de API** y restríngela a esa API.
3. Pégala en el panel, o mejor, defínela en `wp-config.php` para que no quede en la base de datos:

```php
define( 'YTS_API_KEY', 'tu-clave' );
```

## Si algo falla

| Síntoma | Qué significa |
|---|---|
| `HTTP 404` al leer la playlist | Falla temporal del feed RSS de YouTube. Se reintenta solo; usa la API key para evitarlo. |
| `quota` en el informe | Se agotó la cuota diaria de la API (se reinicia a medianoche, hora del Pacífico de EE. UU.). |
| Video omitido por «fecha de inicio» | YouTube cuenta la fecha de publicación del video, no la de agregarlo a la playlist. Ajusta *Importar videos desde*. |
| Episodios que no aparecen en el programa | Botón **Reparar enlaces**. |

Más detalle en [MANTENIMIENTO.md](MANTENIMIENTO.md).

## Seguridad

- Todas las acciones del panel exigen permiso de administrador y nonce (formularios POST).
- La API key no se muestra en el panel una vez guardada y puede vivir en `wp-config.php`.
- Los datos que vienen de YouTube (ID de video, títulos, descripciones) se validan y escapan antes de usarse.
- Al **borrar** el plugin se eliminan sus ajustes; los episodios importados se conservan.

## Desarrollo

```bash
php tests/smoke-test.php                                   # lógica del plugin, sin WordPress (113 comprobaciones)
npm install jsdom --no-save && node tests/panel-test.js    # JavaScript del panel (29 comprobaciones, opcional)
```

Estructura y recetas para modificar el plugin: [MANTENIMIENTO.md](MANTENIMIENTO.md). Historial de cambios: [CHANGELOG.md](CHANGELOG.md).

## Licencia

GPL-2.0-or-later. Ver [LICENSE](LICENSE).

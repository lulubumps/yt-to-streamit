# YouTube a Streamit

Plugin de WordPress que **importa automáticamente los videos de tus playlists de YouTube como episodios de [Streamit](https://iqonic.design/)**, los deja dentro de su programa y temporada, y te **avisa por correo** cuando un programa llega al capítulo que definas (por ejemplo, el 13).

> Proyecto independiente. **No está afiliado ni respaldado por Iqonic ni por Streamit.** Streamit es un producto comercial de sus autores; este repositorio no incluye su código.

## Qué hace

- Un programa de Streamit = una playlist de YouTube. Cada video nuevo se crea como episodio, con título, descripción y miniatura (convertida a WebP si el servidor puede).
- Activa o desactiva programas con una palomita, o sincroniza uno solo a mano.
- Lee YouTube por su **API oficial** (recomendado) o por el **feed RSS** como respaldo, con reintentos.
- Panel con **verificación del sistema**, registro de cada sincronización y lista de episodios importados.
- Herramienta para **reparar** episodios que no quedaron enlazados a su programa.

## Requisitos

- WordPress 5.8 o superior y PHP 7.4 o superior.
- Plugin **Streamit 1.3.x** activo (probado con la 1.3.9).

## Instalación

1. Descarga el ZIP `yt-to-streamit-x.y.z.zip` (sección *Releases*).
2. En WordPress: **Plugins → Añadir nuevo → Subir plugin** y activa «YouTube a Streamit».
3. Ve a **Ajustes → YouTube a Streamit**.

## Configuración rápida

1. **Cómo cargas el video hoy:** código embed o URL (usa la misma opción que tus episodios actuales).
2. **Importar videos desde:** fecha desde la cual se importan videos (evita traer todo el historial).
3. **Agrega tus programas:** pega la URL o el ID de la playlist, elige el programa de Streamit y el capítulo en que quieres el aviso.
4. **Correo de aviso** y **Guardar cambios**.
5. Presiona **Verificar + prueba de creación** y revisa que todo salga en verde.
6. Empieza con *Estado al importar = Borrador*; cuando confirmes que se ve bien, cambia a *Publicado*.

La sincronización automática corre **cada 12 horas** por defecto; puedes cambiarla en *Frecuencia de sincronización* (30 min, 1 h, 6 h, 12 h o 24 h). Usa WP-Cron, que solo se dispara cuando alguien visita el sitio. Para importar al instante, usa **Sincronizar ahora**.

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
php tests/smoke-test.php   # prueba rápida sin WordPress (44 comprobaciones)
```

Estructura y recetas para modificar el plugin: [MANTENIMIENTO.md](MANTENIMIENTO.md). Historial de cambios: [CHANGELOG.md](CHANGELOG.md).

## Licencia

GPL-2.0-or-later. Ver [LICENSE](LICENSE).

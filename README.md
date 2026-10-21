# YouTube a Streamit

Plugin para WordPress que importa automáticamente videos de playlists de YouTube como episodios de programas y temporadas en [Streamit](https://iqonic.design/). También puede enviar avisos por correo cuando un programa alcanza el número de episodio configurado.

> [!IMPORTANT]
> Proyecto independiente, no afiliado ni respaldado por Iqonic o Streamit. Streamit es un producto comercial de sus respectivos autores; este repositorio no incluye su código.

## Funcionalidades

- Relaciona una playlist de YouTube con un programa de Streamit.
- Importa videos como episodios con título, descripción y miniatura (WebP cuando el servidor lo permite).
- Permite activar/desactivar programas y sincronizar uno manualmente.
- Consulta playlists mediante YouTube Data API v3 o utiliza el feed RSS como alternativa.
- Incluye panel con secciones para programas, ajustes, herramientas, episodios importados y diagnóstico del sistema.
- Permite elegir desde qué fecha importar, seleccionar el estado inicial de los episodios y reparar enlaces a programas.
- Envía notificaciones por correo al alcanzar el número de episodio configurado.
- Ejecuta sincronizaciones automáticas mediante WP-Cron.

## Requisitos

- WordPress 5.8 o superior.
- PHP 7.4 o superior.
- Plugin Streamit 1.3.x activo (probado con la versión 1.3.9).
- Para usar la integración recomendada, una clave de YouTube Data API v3.

## Instalación

1. Descarga el ZIP desde la sección [Releases](https://github.com/lulubumps/yt-to-streamit/releases).
2. En WordPress, entra a **Plugins → Añadir nuevo → Subir plugin**.
3. Sube el ZIP, instala y activa el plugin.
4. Abre **YouTube a Streamit** en el menú de administración.

## Configuración inicial

1. En **Ajustes**, configura el formato de los videos, el correo de notificación y la frecuencia de sincronización.
2. En **Programas sincronizados**, agrega un programa de Streamit y pega la URL o el ID de su playlist.
3. Define desde qué fecha importar y el estado inicial de los episodios. Se recomienda comenzar con **Borrador**.
4. En **Verificación del sistema**, ejecuta el diagnóstico y la prueba de creación.
5. Pulsa **Sincronizar ahora**, revisa los episodios creados y publícalos solo cuando confirmes que se muestran correctamente.

La frecuencia predeterminada es de **12 horas**. Puede ajustarse a 30 minutos, 1, 6, 12 o 24 horas. WP-Cron depende de las visitas al sitio; para ejecutar una importación inmediatamente, utiliza la sincronización manual.

## Configurar YouTube Data API v3

El feed RSS puede fallar temporalmente. Se recomienda utilizar la API oficial:

1. En [Google Cloud Console](https://console.cloud.google.com/), crea un proyecto y habilita **YouTube Data API v3**.
2. Crea una clave de API y restríngela a esa API.
3. Introduce la clave en la configuración del plugin o, preferiblemente, define YTS_API_KEY en wp-config.php:

    define( 'YTS_API_KEY', 'tu-clave' );

No publiques la clave ni la guardes en el control de versiones.

## Diagnóstico de problemas

| Síntoma | Posible causa o solución |
|---|---|
| Error HTTP 404 al leer una playlist | El feed RSS de YouTube puede fallar temporalmente. Usa la API oficial o vuelve a intentar. |
| Aviso de cuota agotada | Se alcanzó la cuota diaria de la API; revisa el informe y la cuota del proyecto en Google Cloud. |
| Un video no se importa por fecha | Se considera la fecha de publicación del video, no cuándo se añadió a la playlist. Revisa el límite de importación. |
| Episodios sin programa asociado | Utiliza la herramienta **Reparar enlaces**. |

Consulta [MANTENIMIENTO.md](MANTENIMIENTO.md) para información técnica adicional y [CHANGELOG.md](CHANGELOG.md) para el historial de cambios.

## Seguridad y privacidad

- Las acciones del panel requieren permisos de administración y verificación nonce.
- La clave de API no se muestra en el panel después de guardarse; puede definirse en wp-config.php.
- Los datos obtenidos desde YouTube se validan y escapan antes de utilizarse.
- Al desinstalar el plugin se eliminan sus ajustes; los episodios importados se conservan.

## Desarrollo y pruebas

Las pruebas disponibles pueden ejecutarse desde la raíz del repositorio:

    php tests/smoke-test.php
    npm install jsdom --no-save && node tests/panel-test.js

La prueba de JavaScript es opcional y requiere Node.js/npm. Para conocer la estructura interna y las instrucciones de mantenimiento, consulta [MANTENIMIENTO.md](MANTENIMIENTO.md).

## Licencia

GPL-2.0-or-later. Consulta [LICENSE](LICENSE).

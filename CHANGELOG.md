# Changelog

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/).

## [3.3.0]

### Panel
- El plugin sale de *Ajustes* y tiene **menú propio** «YouTube a Streamit» con cinco secciones: Programas sincronizados (la primera), Ajustes, Herramientas, Episodios importados y Verificación del sistema.
- **Programas sincronizados** reúne la tabla de programas con las opciones de importación (*Importar videos desde* y *Estado al importar*).
- El selector de programa de Streamit ahora es un **selector con buscador** (sin acentos, por nombre o ID, con teclado).
- La lista de programas y la de episodios importados están **paginadas**; la de programas tiene además un filtro.
- Guardar es por secciones: cada formulario solo cambia sus propios ajustes.
- Los botones vuelven a la página desde la que se pulsaron.
- El código del panel se separa en `includes/admin/` y el JavaScript/CSS en `assets/`.
- Nueva prueba del JavaScript del panel: `node tests/panel-test.js` (opcional).

## [3.2.0]

- Nuevo ajuste **Frecuencia de sincronización** (30 min, 1 h, 6 h, 12 h o 24 h). Por defecto, cada 12 horas.
- Al cambiar la frecuencia, o al actualizar desde una versión anterior, la tarea programada se reprograma sola.

## [3.1.0]

### Seguridad y robustez
- La API key ya no se muestra en el HTML del panel; vacío al guardar = conservar la actual, con casilla para borrarla.
- La API key puede definirse en `wp-config.php` con `YTS_API_KEY` (no queda en la base de datos).
- Los ajustes se guardan sin `autoload` (no se cargan en cada visita).
- Los ID de video se validan (11 caracteres `A-Za-z0-9_-`) antes de usarse en URLs o en el iframe.
- Bloqueo para que no corran dos sincronizaciones a la vez, y límite de 10 importaciones por programa en cada pasada (filtro `yts_max_imports_per_run`).
- Todas las acciones del panel pasan a ser formularios POST con nonce, con un único despachador (`yts_do`).
- Se valida el formato de la fecha "Importar videos desde".
- Se quita la cabecera `Requires Plugins`, que podía bloquear la activación si la carpeta de Streamit tenía otro nombre.
- Nuevo `uninstall.php`: al borrar el plugin se limpian sus ajustes (los episodios importados se conservan).

### Orden del código
- El archivo único se separa en `includes/` por responsabilidad (ver `MANTENIMIENTO.md`).
- Todo lo que toca funciones internas de Streamit queda en `includes/streamit.php`.
- Nueva prueba rápida sin WordPress: `php tests/smoke-test.php`.

## [3.0.0]

- Importación de playlists de YouTube como episodios de Streamit (tablas propias de Streamit 1.3.x), enlazados a su programa y temporada.
- Panel de ajustes: programas con playlist, temporada y capítulo de aviso; activar/desactivar por programa; sincronizar uno solo.
- Aviso por correo al llegar al capítulo N.
- Miniaturas convertidas a WebP.
- Lectura por API de YouTube (opcional) con respaldo al feed RSS, con reintentos.
- Verificación del sistema, registro de sincronización y lista de episodios importados.
- Reparación de episodios sin enlazar a su programa.

<?php
/**
 * Textos de la cabecera del plugin.
 *
 * Este archivo NO se carga al ejecutar el plugin. Existe solo para que las herramientas de traducción
 * (xgettext / bin/update-translations.sh) encuentren los textos que WordPress traduce desde la cabecera
 * de yt-to-streamit.php (nombre y descripción). Si cambias esos textos en la cabecera, cámbialos aquí también.
 *
 * @package YT_To_Streamit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

__( 'Automatically imports the videos of YouTube playlists as Streamit episodes and sends an email notice when a program reaches an episode number. Independent project, not affiliated with Iqonic or Streamit.', 'yt-to-streamit' );

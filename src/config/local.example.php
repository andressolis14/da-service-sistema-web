<?php
/**
 * Plantilla de configuración local. Copie este archivo como 'local.php'
 * (en la misma carpeta) y coloque ahí las credenciales reales de su máquina.
 * 'local.php' nunca se sube al repositorio (ver .gitignore).
 */

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'da_service');
define('DB_USER', 'da_app');
define('DB_PASS', 'CAMBIAR_POR_LA_CLAVE_REAL');

// Solo para limpieza manual de datos de prueba fuera de la aplicación
// (algunos scripts de tests/ los usan cuando da_app no tiene permiso
// para borrar). La aplicación nunca usa estas credenciales.
define('DB_ADMIN_USER', 'da_admin');
define('DB_ADMIN_PASS', 'CAMBIAR_POR_LA_CLAVE_REAL');

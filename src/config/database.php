<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * Configuración de conexión a la base de datos.
 *
 * Las credenciales reales viven en 'local.php' (gitignored, nunca en el
 * repositorio). Ver 'local.example.php' para crear el archivo local.
 *
 * La aplicación se conecta siempre con el perfil de solo lectura/escritura
 * 'da_app' (nunca con root). Ver da_service_bd.sql para los perfiles.
 */

$archivoLocal = __DIR__ . '/local.php';
if (!file_exists($archivoLocal)) {
    throw new Exception(
        "Falta src/config/local.php. Copie local.example.php como local.php " .
        "y coloque ahí las credenciales de su máquina."
    );
}
require_once $archivoLocal;

define('DB_CHARSET', 'utf8mb4');

/**
 * Obtiene la conexión PDO a la base de datos (una sola conexión por petición).
 */
function obtenerConexion(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $opciones = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $pdo = new PDO($dsn, DB_USER, DB_PASS, $opciones);
        // Modo estricto: MySQL rechaza datos inválidos en vez de truncarlos o redondearlos en silencio.
        $pdo->exec("SET sql_mode='STRICT_TRANS_TABLES'");
    }

    return $pdo;
}

/**
 * HU-14: conexión de solo lectura para reportes, con el perfil
 * 'da_reportes' (GRANT SELECT ON da_service.* TO 'da_reportes'; ver
 * da_service_bd.sql). Nunca se usa para páginas que escriben datos.
 */
function obtenerConexionReportes(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $opciones = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $pdo = new PDO($dsn, DB_REPORTES_USER, DB_REPORTES_PASS, $opciones);
        $pdo->exec("SET sql_mode='STRICT_TRANS_TABLES'");
    }

    return $pdo;
}

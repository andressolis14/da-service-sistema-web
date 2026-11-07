<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-02: entrega el PDF de un desprendible solo a su dueño (autoservicio)
 * o a Contabilidad/Administrador, que son quienes los cargan.
 * Nunca se sirve el archivo directamente desde storage/ (bloqueado por
 * .htaccess); siempre pasa por aquí, que valida la sesión primero.
 */
require_once __DIR__ . '/../src/config/app.php';
exigirSesion();

$id = (int) ($_GET['id'] ?? 0);
$desprendible = obtenerDesprendible($id);
if (!$desprendible) {
    http_response_code(404);
    echo 'Desprendible no encontrado.';
    exit;
}

$usuario = usuarioActual();
$autorizado = esDueno((int) $desprendible['empleado_id'])
    || in_array((int) $usuario['rol_id'], [ROL_CONTABILIDAD, ROL_ADMINISTRADOR], true);

if (!$autorizado) {
    http_response_code(403);
    echo 'Acceso denegado: este desprendible no pertenece a tu cuenta.';
    exit;
}

$rutaFisica = __DIR__ . '/../' . $desprendible['archivo_ruta'];
if (!is_file($rutaFisica)) {
    http_response_code(404);
    echo 'El archivo ya no está disponible.';
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="desprendible_' . $desprendible['periodo'] . '.pdf"');
header('Content-Length: ' . filesize($rutaFisica));
header('X-Content-Type-Options: nosniff');
readfile($rutaFisica);
exit;

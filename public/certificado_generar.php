<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-03: genera el certificado laboral en PDF y lo entrega como
 * descarga, sin guardarlo en disco. El empleado solo puede generar el
 * suyo; Contabilidad y Administrador pueden generarlo para cualquiera.
 */
require_once __DIR__ . '/../src/config/app.php';
exigirSesion();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Método no permitido.';
    exit;
}
if (!validarCSRF($_POST['csrf_token'] ?? null)) {
    echo 'La sesión del formulario expiró. Vuelve a intentarlo.';
    exit;
}

$usuario = usuarioActual();
$empleadoId = (int) ($_POST['empleado_id'] ?? 0);
$esGestionNomina = in_array((int) $usuario['rol_id'], [ROL_CONTABILIDAD, ROL_ADMINISTRADOR], true);

if ($empleadoId !== (int) $usuario['empleado_id'] && !$esGestionNomina) {
    http_response_code(403);
    echo 'Acceso denegado: solo puedes generar tu propio certificado.';
    exit;
}

$resultado = generarCertificadoEmpleado($empleadoId);
if (!$resultado['exito']) {
    echo $resultado['mensaje'];
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $resultado['nombreArchivo'] . '"');
header('Content-Length: ' . strlen($resultado['pdf']));
header('X-Content-Type-Options: nosniff');
echo $resultado['pdf'];
exit;

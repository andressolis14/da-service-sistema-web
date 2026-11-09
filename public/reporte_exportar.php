<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-14: exporta un reporte a Excel (PhpSpreadsheet).
 * Reportes 1-3 (soporte): Coordinador, Gerente, Administrador.
 * Reportes 4-5 (comerciales): Gerente, Administrador.
 */
require_once __DIR__ . '/../src/config/app.php';
exigirSesion([ROL_COORDINADOR, ROL_GERENTE, ROL_ADMINISTRADOR]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Método no permitido.';
    exit;
}
if (!validarCSRF($_POST['csrf_token'] ?? null)) {
    echo 'La sesión del formulario expiró. Vuelve a intentarlo.';
    exit;
}

$numero = (int) ($_POST['numero_reporte'] ?? 0);
if (!in_array($numero, [1, 2, 3, 4, 5], true)) {
    echo 'Reporte no válido.';
    exit;
}

$usuario = usuarioActual();
$esGerenciaOAdmin = in_array((int) $usuario['rol_id'], [ROL_GERENTE, ROL_ADMINISTRADOR], true);
if (in_array($numero, [4, 5], true) && !$esGerenciaOAdmin) {
    http_response_code(403);
    echo 'Acceso denegado: los reportes comerciales son solo para Gerente y Administrador.';
    exit;
}

$desde = trim($_POST['desde'] ?? '');
$hasta = trim($_POST['hasta'] ?? '');
$validacion = validarRangoPeriodo($desde, $hasta);
if (!$validacion['exito']) {
    echo $validacion['mensaje'];
    exit;
}

$libro = construirExcelReporte($numero, $desde !== '' ? $desde : null, $hasta !== '' ? $hasta : null);
$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="reporte_' . $numero . '_' . date('Ymd_His') . '.xlsx"');
header('Cache-Control: max-age=0');
$writer->save('php://output');
exit;

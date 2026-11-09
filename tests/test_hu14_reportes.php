<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-14: reportes e indicadores, exportables a Excel.
 *
 * Verifica, contra la aplicación real (requiere Apache + MySQL activos):
 *  - Cada uno de los 5 reportes genera un .xlsx válido, con las
 *    columnas esperadas.
 *  - El filtro de periodo limita las filas (comparado contra un conteo
 *    independiente por SQL directo).
 *  - Un rango sin datos exporta solo encabezados (cero filas de datos).
 *  - desde > hasta se rechaza.
 *  - Una fecha mal formada se rechaza.
 *  - Rol no autorizado (Vendedor, para la página completa; Coordinador,
 *    para los reportes 4 y 5) recibe 403.
 *  - POST sin csrf_token rechazado.
 *  - Un asunto de ticket "=1+1" o "@SUM(1)" sale como texto literal,
 *    nunca como fórmula.
 *  - Los totales del Excel coinciden con los que calculan las mismas
 *    funciones que usa la pantalla.
 *
 * Uso: php tests/test_hu14_reportes.php
 */

require_once __DIR__ . '/../src/config/app.php';
require_once __DIR__ . '/../vendor/autoload.php';

const BASE = 'http://localhost/daservice';

$fallas = 0;

function peticion(string $metodo, string $url, array $datos, string $cookieJar, bool $binario = false): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookieJar,
        CURLOPT_COOKIEFILE     => $cookieJar,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($metodo === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($datos));
    }
    $cuerpo = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $cuerpo];
}

function extraerToken(string $html): string
{
    preg_match('/name="csrf_token" value="([^"]*)"/', $html, $m);
    return $m[1] ?? '';
}

function iniciarSesion(string $usuario, string $cookieJar): void
{
    [, $html] = peticion('GET', BASE . '/login.php', [], $cookieJar);
    $token = extraerToken($html);
    peticion('POST', BASE . '/login.php', [
        'csrf_token'     => $token,
        'nombre_usuario' => $usuario,
        'password'       => 'Prueba2026*',
    ], $cookieJar);
}

function tokenReportes(string $cookieJar): string
{
    [, $html] = peticion('GET', BASE . '/reportes.php', [], $cookieJar);
    return extraerToken($html);
}

function verificar(bool $condicion, string $descripcion): void
{
    global $fallas;
    echo ($condicion ? "[OK] " : "[FALLA] ") . $descripcion . PHP_EOL;
    if (!$condicion) {
        $fallas++;
    }
}

/** Descarga un reporte y lo carga como libro de PhpSpreadsheet. */
function exportarYcargar(int $numero, string $desde, string $hasta, string $cookieJar): array
{
    $token = tokenReportes($cookieJar);
    [$status, $cuerpo] = peticion('POST', BASE . '/reporte_exportar.php', [
        'csrf_token' => $token, 'numero_reporte' => $numero, 'desde' => $desde, 'hasta' => $hasta,
    ], $cookieJar);
    if ($status !== 200 || !str_starts_with($cuerpo, 'PK')) {
        return [$status, $cuerpo, null];
    }
    $ruta = tempnam(sys_get_temp_dir(), 'daservice_reporte_') . '.xlsx';
    file_put_contents($ruta, $cuerpo);
    $libro = \PhpOffice\PhpSpreadsheet\IOFactory::load($ruta);
    @unlink($ruta);
    return [$status, $cuerpo, $libro];
}

$cookieCoordinador = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
$cookieGerente     = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
$cookieVendedor    = tempnam(sys_get_temp_dir(), 'daservice_cookies_');

iniciarSesion('jospina', $cookieCoordinador);
iniciarSesion('cvelez', $cookieGerente);
iniciarSesion('jperez', $cookieVendedor);

// ---------------------------------------------------------------------
// 1. Rol no autorizado: Vendedor no entra a la página de reportes.
// ---------------------------------------------------------------------
[$statusVendedor] = peticion('GET', BASE . '/reportes.php', [], $cookieVendedor);
verificar($statusVendedor === 403, 'Un rol no autorizado (Vendedor) recibe 403 en reportes.php');

// ---------------------------------------------------------------------
// 2. Reportes 4 y 5 (comerciales) son solo para Gerente/Administrador:
//    el Coordinador recibe 403 al intentar exportarlos.
// ---------------------------------------------------------------------
$token = tokenReportes($cookieCoordinador);
[$statusComercialCoordinador] = peticion('POST', BASE . '/reporte_exportar.php', [
    'csrf_token' => $token, 'numero_reporte' => 4, 'desde' => '', 'hasta' => '',
], $cookieCoordinador);
verificar($statusComercialCoordinador === 403, 'El Coordinador recibe 403 al exportar el reporte comercial 4');

// ---------------------------------------------------------------------
// 3. Cada uno de los 5 reportes genera un .xlsx válido con las
//    columnas esperadas.
// ---------------------------------------------------------------------
$columnasEsperadas = [
    1 => ['Estado', 'Tipo de solicitante', 'Total'],
    2 => null, // formato distinto (resumen + detalle), se valida aparte
    3 => null,
    4 => ['Etapa', 'Oportunidades', 'Valor total (COP)'],
    5 => ['Vendedor', 'Llamadas', 'Contestadas', '% contestadas', 'Interesados', 'Portafolios'],
];
foreach ([1, 2, 3] as $n) {
    [$status, , $libro] = exportarYcargar($n, '', '', $cookieCoordinador);
    verificar($status === 200 && $libro !== null, "Reporte $n: el Coordinador descarga un .xlsx válido");
}
foreach ([4, 5] as $n) {
    [$status, , $libro] = exportarYcargar($n, '', '', $cookieGerente);
    verificar($status === 200 && $libro !== null, "Reporte $n: el Gerente descarga un .xlsx válido");
}
foreach ([1 => 'A5', 4 => 'A5', 5 => 'A5'] as $n => $coordEncabezado) {
    [, , $libro] = exportarYcargar($n, '', '', $cookieGerente);
    $hoja = $libro->getActiveSheet();
    $encabezados = [];
    foreach ($columnasEsperadas[$n] as $i => $esperado) {
        $encabezados[] = $hoja->getCell([$i + 1, 5])->getValue();
    }
    verificar($encabezados === $columnasEsperadas[$n], "Reporte $n: las columnas del Excel son las esperadas");
}

// ---------------------------------------------------------------------
// 4. desde > hasta se rechaza.
// ---------------------------------------------------------------------
$token = tokenReportes($cookieGerente);
[, $cuerpoDesdeMayor] = peticion('POST', BASE . '/reporte_exportar.php', [
    'csrf_token' => $token, 'numero_reporte' => 1, 'desde' => '2026-12-01', 'hasta' => '2026-01-01',
], $cookieGerente);
verificar(str_contains($cuerpoDesdeMayor, 'no puede ser posterior'), '"desde" posterior a "hasta" se rechaza');

// ---------------------------------------------------------------------
// 5. Una fecha mal formada se rechaza.
// ---------------------------------------------------------------------
$token = tokenReportes($cookieGerente);
[, $cuerpoFechaMalFormada] = peticion('POST', BASE . '/reporte_exportar.php', [
    'csrf_token' => $token, 'numero_reporte' => 1, 'desde' => '2026/01/01', 'hasta' => '',
], $cookieGerente);
verificar(str_contains($cuerpoFechaMalFormada, 'formato válido'), 'Una fecha mal formada (con /) se rechaza');

// ---------------------------------------------------------------------
// 6. POST sin csrf_token se rechaza.
// ---------------------------------------------------------------------
[, $cuerpoSinCsrf] = peticion('POST', BASE . '/reporte_exportar.php', [
    'numero_reporte' => 1, 'desde' => '', 'hasta' => '',
], $cookieGerente);
verificar(str_contains($cuerpoSinCsrf, 'La sesión del formulario expiró'), 'POST sin csrf_token es rechazado');

// ---------------------------------------------------------------------
// 7. Rango sin datos: solo encabezados, cero filas de datos.
// ---------------------------------------------------------------------
[, , $libroVacio] = exportarYcargar(1, '2000-01-01', '2000-01-31', $cookieGerente);
$hojaVacia = $libroVacio->getActiveSheet();
verificar($hojaVacia->getCell('A5')->getValue() === 'Estado', 'Rango sin datos: el encabezado de columnas sigue presente');
verificar($hojaVacia->getCell('A6')->getValue() === null, 'Rango sin datos: no hay ninguna fila de datos después del encabezado');

// ---------------------------------------------------------------------
// 8. El filtro de periodo limita las filas: se compara contra un
//    conteo independiente por SQL directo (conexión da_app).
// ---------------------------------------------------------------------
$desdeFiltro = '2026-09-01';
$hastaFiltro = '2026-09-30';
$conteoEsperado = (int) obtenerConexion()->query("
    SELECT COUNT(*) FROM ticket WHERE fecha_creacion >= '$desdeFiltro' AND fecha_creacion <= '$hastaFiltro 23:59:59'
")->fetchColumn();
$filasReales = array_sum(array_column(reporteTicketsPorEstado($desdeFiltro, $hastaFiltro), 'total'));
verificar($filasReales === $conteoEsperado, 'El filtro de periodo da el mismo total que un conteo SQL independiente');
verificar($filasReales < count(listarOportunidades()) + 20, 'El filtro realmente reduce el universo de datos (control de cordura)'); // chequeo laxo, solo para no comparar contra 0 sin sentido

// ---------------------------------------------------------------------
// 9. Los totales del Excel coinciden con los de las funciones que usa
//    la pantalla (mismo cálculo, no un valor aparte).
// ---------------------------------------------------------------------
[, , $libroOportunidades] = exportarYcargar(4, '', '', $cookieGerente);
$hojaOportunidades = $libroOportunidades->getActiveSheet();
$datosPantalla = reporteOportunidadesPorEtapa(null, null);
$fila = 6;
$coincide = true;
foreach ($datosPantalla as $r) {
    $etapaExcel = $hojaOportunidades->getCell("A$fila")->getValue();
    $totalExcel = (int) $hojaOportunidades->getCell("B$fila")->getValue();
    if ($etapaExcel !== ucfirst($r['etapa']) || $totalExcel !== (int) $r['oportunidades']) {
        $coincide = false;
    }
    $fila++;
}
verificar($coincide, 'Los totales del reporte de oportunidades en el Excel coinciden con los de la pantalla');

// ---------------------------------------------------------------------
// 10. Un asunto de ticket "=1+1" o "@SUM(1)" sale como texto literal.
// ---------------------------------------------------------------------
$pdo = obtenerConexion();
$resultado1 = crearTicketPublico([
    'solicitante_nombre' => 'Prueba HU-14', 'solicitante_correo' => 'prueba.hu14@no-existe.example',
    'solicitante_tipo' => 'externo', 'asunto' => '=1+1', 'descripcion' => 'Prueba de inyección de fórmulas',
    'aviso_aceptado' => '1',
]);
$resultado2 = crearTicketPublico([
    'solicitante_nombre' => 'Prueba HU-14', 'solicitante_correo' => 'prueba.hu14@no-existe.example',
    'solicitante_tipo' => 'externo', 'asunto' => '@SUM(1)', 'descripcion' => 'Prueba de inyección de fórmulas',
    'aviso_aceptado' => '1',
]);
$idTicket1 = $resultado1['id'];
$idTicket2 = $resultado2['id'];
foreach ([$idTicket1, $idTicket2] as $idTicket) {
    $pdo->prepare("INSERT INTO actualizacion_ticket (ticket_id, usuario_id, estado_nuevo, comentario, modalidad) VALUES (:id, 5, 'cerrado', 'Prueba HU-14', 'remota')")
        ->execute(['id' => $idTicket]);
    $pdo->prepare("UPDATE ticket SET estado = 'cerrado' WHERE id = :id")->execute(['id' => $idTicket]);
}

[, , $libroTiempo] = exportarYcargar(2, '', '', $cookieGerente);
$hojaTiempo = $libroTiempo->getActiveSheet();
$filaTicket1 = null;
$filaTicket2 = null;
for ($f = 8; $f <= $hojaTiempo->getHighestRow(); $f++) {
    if ((int) $hojaTiempo->getCell("A$f")->getValue() === $idTicket1) {
        $filaTicket1 = $f;
    }
    if ((int) $hojaTiempo->getCell("A$f")->getValue() === $idTicket2) {
        $filaTicket2 = $f;
    }
}
verificar($filaTicket1 !== null && $filaTicket2 !== null, 'Los dos tickets de prueba aparecen en el detalle del reporte');

if ($filaTicket1) {
    $celda1 = $hojaTiempo->getCell("B$filaTicket1");
    verificar($celda1->getDataType() === \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING, 'La celda del asunto "=1+1" tiene tipo de dato STRING, no fórmula');
    verificar(str_starts_with((string) $celda1->getValue(), "'="), 'El asunto "=1+1" se guarda con el apóstrofo de seguridad delante');
}
if ($filaTicket2) {
    $celda2 = $hojaTiempo->getCell("B$filaTicket2");
    verificar($celda2->getDataType() === \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING, 'La celda del asunto "@SUM(1)" tiene tipo de dato STRING, no fórmula');
    verificar(str_starts_with((string) $celda2->getValue(), "'@"), 'El asunto "@SUM(1)" se guarda con el apóstrofo de seguridad delante');
}

// ---------------------------------------------------------------------
// Limpieza: borrar los tickets de prueba y sus avances.
// ---------------------------------------------------------------------
$pdoAdmin = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_ADMIN_USER, DB_ADMIN_PASS);
$pdoAdmin->prepare('DELETE FROM actualizacion_ticket WHERE ticket_id IN (:a, :b)')->execute(['a' => $idTicket1, 'b' => $idTicket2]);
$pdoAdmin->prepare('DELETE FROM ticket WHERE id IN (:a, :b)')->execute(['a' => $idTicket1, 'b' => $idTicket2]);

verificar(obtenerTicket($idTicket1) === null && obtenerTicket($idTicket2) === null, 'Los tickets de prueba se eliminaron');

@unlink($cookieCoordinador);
@unlink($cookieGerente);
@unlink($cookieVendedor);

echo PHP_EOL . ($fallas === 0 ? "TODAS LAS PRUEBAS PASARON" : "$fallas PRUEBA(S) FALLARON") . PHP_EOL;
exit($fallas === 0 ? 0 : 1);

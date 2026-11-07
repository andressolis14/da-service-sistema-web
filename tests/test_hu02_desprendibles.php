<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-02: carga y consulta de desprendibles de nómina.
 *
 * Verifica, contra la aplicación real (requiere Apache + MySQL activos):
 *  - Carga válida de un PDF.
 *  - Un archivo que no es PDF (por contenido, no por extensión) se rechaza.
 *  - Un archivo demasiado grande se rechaza.
 *  - Un rol no autorizado (Vendedor) no puede cargar (403).
 *  - Un periodo repetido para el mismo empleado reemplaza el archivo
 *    anterior (decisión de diseño acordada: "reemplaza", no "rechaza").
 *  - Un empleado descarga su propio desprendible.
 *  - Un empleado NO puede descargar el desprendible de otro (403), aunque
 *    cambie el id en la URL.
 *  - Al cargar el 7.º desprendible de un empleado, se borra el registro y
 *    el PDF más antiguo (dentro de una transacción de BD).
 *  - Un POST sin token CSRF es rechazado.
 *
 * Usa los empleados de datos de prueba id=10 (Laura Gómez, ya tiene sus 6
 * desprendibles de siembra) y id=12 (Andrea Torres, tiene 2). Todos los
 * periodos y archivos que crea este script son ficticios y se eliminan al
 * terminar; el único cambio que puede quedar es la fecha_carga del periodo
 * reemplazado de Andrea, que este script también restaura.
 *
 * Uso: php tests/test_hu02_desprendibles.php
 */

require_once __DIR__ . '/../src/config/app.php';

const BASE = 'http://localhost/daservice';
const EMPLEADO_LAURA  = 10; // ya tiene 6 (el máximo)
const EMPLEADO_ANDREA = 12; // tiene 2

const PDF_VALIDO   = __DIR__ . '/fixtures/desprendible_valido.pdf';
const PDF_INVALIDO = __DIR__ . '/fixtures/no_es_pdf.pdf';

$fallas = 0;

// El archivo "demasiado grande" se genera en el momento (6 MB) en vez de
// guardarse en el repositorio, para no versionar un binario de ese tamaño.
$pdfGrande = tempnam(sys_get_temp_dir(), 'daservice_grande_') . '.pdf';
file_put_contents($pdfGrande, '%PDF-1.4' . str_repeat('A', 6 * 1024 * 1024));

function peticion(string $metodo, string $url, array $datos, string $cookieJar): array
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

/** POST multipart/form-data, para subir un archivo igual que haría el navegador. */
function peticionConArchivo(string $url, array $campos, string $rutaArchivo, string $cookieJar): array
{
    $ch = curl_init($url);
    $campos['archivo'] = new CURLFile($rutaArchivo, 'application/pdf', 'original_del_navegador.pdf');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookieJar,
        CURLOPT_COOKIEFILE     => $cookieJar,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $campos,
    ]);
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

function tokenDesprendibles(string $cookieJar, string $queryExtra = ''): string
{
    [, $html] = peticion('GET', BASE . '/desprendibles.php' . $queryExtra, [], $cookieJar);
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

$cookieContabilidad = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
$cookieVendedor     = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
$cookieLaura        = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
$cookieAndrea       = tempnam(sys_get_temp_dir(), 'daservice_cookies_');

iniciarSesion('lcastro', $cookieContabilidad); // Contabilidad
iniciarSesion('jperez', $cookieVendedor);      // Vendedor (no autorizado para cargar)
iniciarSesion('lgomez', $cookieLaura);         // Empleado, dueño de los desprendibles de Laura
iniciarSesion('atorres', $cookieAndrea);       // Empleado, dueño de los desprendibles de Andrea

// ---------------------------------------------------------------------
// 1. Rol no autorizado (Vendedor) no puede cargar.
// ---------------------------------------------------------------------
[$statusNoAutorizado] = peticionConArchivo(BASE . '/desprendibles.php', [
    'csrf_token'   => 'no-importa',
    'accion'       => 'cargar',
    'empleado_id'  => EMPLEADO_ANDREA,
    'periodo'      => '2025-01',
], PDF_VALIDO, $cookieVendedor);
verificar($statusNoAutorizado === 403, 'Un rol no autorizado (Vendedor) recibe 403 al intentar cargar');

// ---------------------------------------------------------------------
// 2. Archivo que no es PDF (por contenido) se rechaza.
// ---------------------------------------------------------------------
$token = tokenDesprendibles($cookieContabilidad, '?empleado=' . EMPLEADO_ANDREA);
[, $htmlNoPdf] = peticionConArchivo(BASE . '/desprendibles.php', [
    'csrf_token'   => $token,
    'accion'       => 'cargar',
    'empleado_id'  => EMPLEADO_ANDREA,
    'periodo'      => '2025-01',
], PDF_INVALIDO, $cookieContabilidad);
verificar(str_contains($htmlNoPdf, 'debe ser un PDF válido'), 'Un archivo que no es PDF por contenido se rechaza');

// ---------------------------------------------------------------------
// 3. Archivo demasiado grande se rechaza.
// ---------------------------------------------------------------------
$token = tokenDesprendibles($cookieContabilidad, '?empleado=' . EMPLEADO_ANDREA);
[, $htmlGrande] = peticionConArchivo(BASE . '/desprendibles.php', [
    'csrf_token'   => $token,
    'accion'       => 'cargar',
    'empleado_id'  => EMPLEADO_ANDREA,
    'periodo'      => '2025-02',
], $pdfGrande, $cookieContabilidad);
verificar(str_contains($htmlGrande, 'supera el tamaño máximo'), 'Un archivo demasiado grande (6 MB) se rechaza');

verificar(count(listarDesprendiblesDeEmpleado(EMPLEADO_ANDREA)) === 2, 'Ninguno de los intentos rechazados creó un registro (Andrea sigue con 2)');

// ---------------------------------------------------------------------
// 4. Carga válida de un periodo nuevo.
// ---------------------------------------------------------------------
$token = tokenDesprendibles($cookieContabilidad, '?empleado=' . EMPLEADO_ANDREA);
[, $htmlOk] = peticionConArchivo(BASE . '/desprendibles.php', [
    'csrf_token'   => $token,
    'accion'       => 'cargar',
    'empleado_id'  => EMPLEADO_ANDREA,
    'periodo'      => '2026-01',
], PDF_VALIDO, $cookieContabilidad);
verificar(str_contains($htmlOk, 'Desprendible cargado correctamente'), 'Carga válida de un PDF para un periodo nuevo');
verificar(count(listarDesprendiblesDeEmpleado(EMPLEADO_ANDREA)) === 3, 'El registro nuevo quedó guardado (Andrea pasa a 3)');

// ---------------------------------------------------------------------
// 5. POST sin token CSRF se rechaza.
// ---------------------------------------------------------------------
[, $htmlSinCsrf] = peticionConArchivo(BASE . '/desprendibles.php', [
    'accion'      => 'cargar',
    'empleado_id' => EMPLEADO_ANDREA,
    'periodo'     => '2026-02',
], PDF_VALIDO, $cookieContabilidad);
verificar(str_contains($htmlSinCsrf, 'La sesión del formulario expiró'), 'POST sin csrf_token es rechazado');
verificar(count(listarDesprendiblesDeEmpleado(EMPLEADO_ANDREA)) === 3, 'El POST sin CSRF no creó ningún registro (Andrea sigue con 3)');

// ---------------------------------------------------------------------
// 6. Completar hasta 6 con periodos anteriores a los de siembra de Andrea,
//    para que al llegar al 7.º se borre uno de los de esta prueba y no
//    uno de los datos originales (2026-08 y 2026-09).
// ---------------------------------------------------------------------
foreach (['2026-02', '2026-03', '2026-04'] as $periodo) {
    $token = tokenDesprendibles($cookieContabilidad, '?empleado=' . EMPLEADO_ANDREA);
    peticionConArchivo(BASE . '/desprendibles.php', [
        'csrf_token'  => $token,
        'accion'      => 'cargar',
        'empleado_id' => EMPLEADO_ANDREA,
        'periodo'     => $periodo,
    ], PDF_VALIDO, $cookieContabilidad);
}
$antesDelSeptimo = listarDesprendiblesDeEmpleado(EMPLEADO_ANDREA);
verificar(count($antesDelSeptimo) === 6, 'Andrea llega a 6 desprendibles (2026-01 a 2026-04, más 2026-08 y 2026-09)');

$masAntiguoAntes = obtenerConexion()
    ->query("SELECT id, archivo_ruta FROM desprendible WHERE empleado_id = " . EMPLEADO_ANDREA . " ORDER BY periodo ASC LIMIT 1")
    ->fetch();
$rutaFisicaMasAntiguo = __DIR__ . '/../' . $masAntiguoAntes['archivo_ruta'];
verificar(is_file($rutaFisicaMasAntiguo), 'El PDF del periodo más antiguo (2026-01) existe físicamente antes de la 7.ª carga');

// ---------------------------------------------------------------------
// 7. La 7.ª carga borra el registro y el PDF más antiguos.
// ---------------------------------------------------------------------
$token = tokenDesprendibles($cookieContabilidad, '?empleado=' . EMPLEADO_ANDREA);
[, $html7mo] = peticionConArchivo(BASE . '/desprendibles.php', [
    'csrf_token'  => $token,
    'accion'      => 'cargar',
    'empleado_id' => EMPLEADO_ANDREA,
    'periodo'     => '2026-05',
], PDF_VALIDO, $cookieContabilidad);
verificar(str_contains($html7mo, 'se eliminó permanentemente el más antiguo'), 'La 7.ª carga avisa que se eliminó el más antiguo');

$periodosTrasSeptimo = array_column(listarDesprendiblesDeEmpleado(EMPLEADO_ANDREA), 'periodo');
sort($periodosTrasSeptimo);
verificar(count($periodosTrasSeptimo) === 6, 'Andrea sigue teniendo 6 desprendibles (no 7) tras la 7.ª carga');
verificar(!in_array('2026-01', $periodosTrasSeptimo, true), 'El registro del periodo más antiguo (2026-01) ya no está en la base de datos');
clearstatcache(true, $rutaFisicaMasAntiguo); // is_file() ya había cacheado esta ruta antes de la 7.ª carga
verificar(!is_file($rutaFisicaMasAntiguo), 'El archivo físico del periodo más antiguo (2026-01) ya no existe en disco');
verificar(
    $periodosTrasSeptimo === ['2026-02', '2026-03', '2026-04', '2026-05', '2026-08', '2026-09'],
    'Los periodos originales de Andrea (2026-08 y 2026-09) siguen intactos'
);

// ---------------------------------------------------------------------
// 8. Periodo repetido para el mismo empleado: reemplaza (no rechaza).
// ---------------------------------------------------------------------
$filaOriginal2026_08 = array_values(array_filter(
    listarDesprendiblesDeEmpleado(EMPLEADO_ANDREA),
    fn($d) => $d['periodo'] === '2026-08'
))[0];
$fechaOriginal2026_08 = $filaOriginal2026_08['fecha_carga'];
$rutaFisica2026_08 = __DIR__ . '/../storage/desprendibles/' . EMPLEADO_ANDREA . '/2026-08.pdf';
verificar(!is_file($rutaFisica2026_08), 'El PDF de 2026-08 (dato de siembra) no existía físicamente antes de reemplazarlo');

$token = tokenDesprendibles($cookieContabilidad, '?empleado=' . EMPLEADO_ANDREA);
[, $htmlReemplazo] = peticionConArchivo(BASE . '/desprendibles.php', [
    'csrf_token'  => $token,
    'accion'      => 'cargar',
    'empleado_id' => EMPLEADO_ANDREA,
    'periodo'     => '2026-08',
], PDF_VALIDO, $cookieContabilidad);
verificar(str_contains($htmlReemplazo, 'se reemplazó por el nuevo archivo'), 'Cargar un periodo ya existente avisa que se reemplazó');
verificar(count(listarDesprendiblesDeEmpleado(EMPLEADO_ANDREA)) === 6, 'El reemplazo no cambia el total de registros (sigue en 6)');
clearstatcache(true, $rutaFisica2026_08);
verificar(is_file($rutaFisica2026_08), 'El PDF de 2026-08 ahora sí existe físicamente, con el contenido nuevo');

// ---------------------------------------------------------------------
// 9. El dueño descarga su propio desprendible recién reemplazado.
// ---------------------------------------------------------------------
$filaReemplazada = array_values(array_filter(
    listarDesprendiblesDeEmpleado(EMPLEADO_ANDREA),
    fn($d) => $d['periodo'] === '2026-08'
))[0];
[$statusDescargaPropia, $cuerpoDescarga] = peticion('GET', BASE . '/desprendible_descargar.php?id=' . $filaReemplazada['id'], [], $cookieAndrea);
verificar($statusDescargaPropia === 200, 'Andrea descarga su propio desprendible (200)');
verificar(str_starts_with($cuerpoDescarga, '%PDF-'), 'El contenido descargado es un PDF válido');

// ---------------------------------------------------------------------
// 10. Un empleado NO puede descargar el desprendible de otro, cambiando
//     el id en la URL.
// ---------------------------------------------------------------------
$desprendibleDeLaura = listarDesprendiblesDeEmpleado(EMPLEADO_LAURA)[0];
[$statusAjeno] = peticion('GET', BASE . '/desprendible_descargar.php?id=' . $desprendibleDeLaura['id'], [], $cookieAndrea);
verificar($statusAjeno === 403, 'Andrea NO puede descargar un desprendible de Laura cambiando el id en la URL (403)');

// ---------------------------------------------------------------------
// Limpieza: dejar a Andrea exactamente como al principio (2026-08 y
// 2026-09, con la fecha_carga original; sin los periodos de prueba).
// ---------------------------------------------------------------------
$pdo = obtenerConexion();
foreach (['2026-02', '2026-03', '2026-04', '2026-05'] as $periodo) {
    $fila = $pdo->prepare('SELECT id, archivo_ruta FROM desprendible WHERE empleado_id = :emp AND periodo = :periodo');
    $fila->execute(['emp' => EMPLEADO_ANDREA, 'periodo' => $periodo]);
    $registro = $fila->fetch();
    if ($registro) {
        @unlink(__DIR__ . '/../' . $registro['archivo_ruta']);
        $pdo->prepare('DELETE FROM desprendible WHERE id = :id')->execute(['id' => $registro['id']]);
    }
}
@unlink($rutaFisica2026_08);
$pdo->prepare('UPDATE desprendible SET fecha_carga = :fecha WHERE id = :id')
    ->execute(['fecha' => $fechaOriginal2026_08, 'id' => $filaReemplazada['id']]);

$estadoFinalAndrea = array_column(listarDesprendiblesDeEmpleado(EMPLEADO_ANDREA), 'periodo');
sort($estadoFinalAndrea);
verificar($estadoFinalAndrea === ['2026-08', '2026-09'], 'Andrea queda igual que al inicio: solo 2026-08 y 2026-09');
clearstatcache(true, $rutaFisica2026_08);
verificar(!is_file($rutaFisica2026_08), 'El archivo de prueba de 2026-08 se eliminó en la limpieza final');
verificar(count(listarDesprendiblesDeEmpleado(EMPLEADO_LAURA)) === 6, 'Los 6 desprendibles de siembra de Laura no se tocaron en ningún momento');

@unlink($cookieContabilidad);
@unlink($cookieVendedor);
@unlink($cookieLaura);
@unlink($cookieAndrea);
@unlink($pdfGrande);

echo PHP_EOL . ($fallas === 0 ? "TODAS LAS PRUEBAS PASARON" : "$fallas PRUEBA(S) FALLARON") . PHP_EOL;
exit($fallas === 0 ? 0 : 1);

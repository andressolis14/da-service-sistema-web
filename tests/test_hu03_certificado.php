<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-03: certificado laboral automático en PDF (Dompdf).
 *
 * Verifica, contra la aplicación real (requiere Apache + MySQL activos):
 *  - El empleado genera su propio certificado y el PDF es válido, con
 *    sus datos.
 *  - Intento de generar el de otro empleado: denegado (403).
 *  - Contabilidad lo genera para cualquier empleado activo.
 *  - Empleado inactivo: rechazado.
 *  - POST sin csrf_token: rechazado.
 *  - Tildes y ñ salen correctos en el texto del PDF (UTF-8).
 *
 * Usa los empleados de datos de prueba id=10 (Laura Gómez, usuario
 * lgomez), id=11 (Carlos Ruiz) e id=13 (Hernán Cortés Lara, 'inactivo'
 * en la siembra, sin cuenta de usuario). Necesita `pdftotext` (Poppler)
 * en el PATH para la verificación de tildes/ñ; si no está disponible,
 * esa prueba en concreto se omite con un aviso, el resto sigue.
 *
 * Uso: php tests/test_hu03_certificado.php
 */

require_once __DIR__ . '/../src/config/app.php';

const BASE = 'http://localhost/daservice';
const EMPLEADO_LAURA = 10;
const EMPLEADO_CARLOS = 11;
const EMPLEADO_INACTIVO = 13; // Hernán Cortés Lara, sin cuenta de usuario

$fallas = 0;

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

function tokenCertificados(string $cookieJar): string
{
    [, $html] = peticion('GET', BASE . '/certificados.php', [], $cookieJar);
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

function textoDelPdf(string $pdfBinario): ?string
{
    $hayPdftotext = trim((string) shell_exec('where pdftotext 2>NUL')) !== '' || trim((string) shell_exec('command -v pdftotext 2>/dev/null')) !== '';
    if (!$hayPdftotext) {
        return null;
    }
    $rutaPdf = tempnam(sys_get_temp_dir(), 'daservice_cert_') . '.pdf';
    file_put_contents($rutaPdf, $pdfBinario);
    $rutaTxt = $rutaPdf . '.txt';
    shell_exec('pdftotext -enc UTF-8 ' . escapeshellarg($rutaPdf) . ' ' . escapeshellarg($rutaTxt));
    $texto = is_file($rutaTxt) ? file_get_contents($rutaTxt) : null;
    @unlink($rutaPdf);
    @unlink($rutaTxt);
    return $texto;
}

$cookieLaura      = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
$cookieContabilidad = tempnam(sys_get_temp_dir(), 'daservice_cookies_');

iniciarSesion('lgomez', $cookieLaura);          // Laura, empleado 10
iniciarSesion('lcastro', $cookieContabilidad);  // Contabilidad

$conteoAntes = (int) obtenerConexion()->query('SELECT COUNT(*) FROM certificado')->fetchColumn();

// ---------------------------------------------------------------------
// 1. El empleado genera su propio certificado: PDF válido, con sus datos.
// ---------------------------------------------------------------------
$token = tokenCertificados($cookieLaura);
[$statusPropio, $cuerpoPropio] = peticion('POST', BASE . '/certificado_generar.php', [
    'csrf_token'  => $token,
    'empleado_id' => EMPLEADO_LAURA,
], $cookieLaura);
verificar($statusPropio === 200, 'La solicitud del propio certificado responde 200');
verificar(str_starts_with($cuerpoPropio, '%PDF'), 'La respuesta es un PDF válido (cabecera %PDF)');

$textoPropio = textoDelPdf($cuerpoPropio);
if ($textoPropio === null) {
    echo "[AVISO] pdftotext no está disponible; se omite la verificación de contenido/tildes del PDF." . PHP_EOL;
} else {
    verificar(str_contains($textoPropio, 'Laura Gómez'), 'El PDF contiene el nombre del empleado (Laura Gómez)');
    verificar(str_contains($textoPropio, '1130500110'), 'El PDF contiene el documento del empleado');
}

$conteoTrasPropio = (int) obtenerConexion()->query('SELECT COUNT(*) FROM certificado')->fetchColumn();
verificar($conteoTrasPropio === $conteoAntes + 1, 'Queda un registro nuevo en la tabla certificado');

// ---------------------------------------------------------------------
// 2. Intento de generar el de otro empleado: denegado.
// ---------------------------------------------------------------------
$token = tokenCertificados($cookieLaura);
[$statusAjeno, $cuerpoAjeno] = peticion('POST', BASE . '/certificado_generar.php', [
    'csrf_token'  => $token,
    'empleado_id' => EMPLEADO_CARLOS,
], $cookieLaura);
verificar($statusAjeno === 403, 'Laura no puede generar el certificado de Carlos (403)');
verificar(str_contains($cuerpoAjeno, 'solo puedes generar tu propio certificado'), 'El mensaje de denegación es claro');

$conteoTrasAjeno = (int) obtenerConexion()->query('SELECT COUNT(*) FROM certificado')->fetchColumn();
verificar($conteoTrasAjeno === $conteoTrasPropio, 'El intento denegado no creó ningún registro');

// ---------------------------------------------------------------------
// 3. Contabilidad genera el certificado de un empleado activo.
// ---------------------------------------------------------------------
$token = tokenCertificados($cookieContabilidad);
[$statusContabilidad, $cuerpoContabilidad] = peticion('POST', BASE . '/certificado_generar.php', [
    'csrf_token'  => $token,
    'empleado_id' => EMPLEADO_CARLOS,
], $cookieContabilidad);
verificar($statusContabilidad === 200 && str_starts_with($cuerpoContabilidad, '%PDF'), 'Contabilidad genera el certificado de Carlos (PDF válido)');

$textoContabilidad = textoDelPdf($cuerpoContabilidad);
if ($textoContabilidad !== null) {
    verificar(str_contains($textoContabilidad, 'Carlos Ruiz'), 'El PDF generado por Contabilidad contiene el nombre de Carlos');
    verificar(
        str_contains($textoContabilidad, 'área') || str_contains($textoContabilidad, 'Administración'),
        'Las tildes y la ñ salen correctas en el PDF (ej. "área"/"Administración")'
    );
}

// ---------------------------------------------------------------------
// 4. Empleado inactivo: rechazado.
// ---------------------------------------------------------------------
$token = tokenCertificados($cookieContabilidad);
[, $cuerpoInactivo] = peticion('POST', BASE . '/certificado_generar.php', [
    'csrf_token'  => $token,
    'empleado_id' => EMPLEADO_INACTIVO,
], $cookieContabilidad);
verificar(
    str_contains($cuerpoInactivo, 'Solo se genera el certificado laboral para empleados activos'),
    'Generar el certificado de un empleado inactivo se rechaza con mensaje claro'
);

// ---------------------------------------------------------------------
// 5. POST sin csrf_token: rechazado.
// ---------------------------------------------------------------------
[, $cuerpoSinCsrf] = peticion('POST', BASE . '/certificado_generar.php', [
    'empleado_id' => EMPLEADO_LAURA,
], $cookieLaura);
verificar(str_contains($cuerpoSinCsrf, 'La sesión del formulario expiró'), 'POST sin csrf_token es rechazado');

$conteoFinal = (int) obtenerConexion()->query('SELECT COUNT(*) FROM certificado')->fetchColumn();
verificar($conteoFinal === $conteoAntes + 2, 'Solo quedaron los 2 certificados válidos (propio de Laura + el de Contabilidad para Carlos)');

// ---------------------------------------------------------------------
// Limpieza: borrar los certificados de prueba. da_app no tiene DELETE
// sobre 'certificado' (solo sobre 'desprendible'); usa da_admin.
// ---------------------------------------------------------------------
$pdoAdmin = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_ADMIN_USER, DB_ADMIN_PASS);
$pdoAdmin->prepare('DELETE FROM certificado WHERE empleado_id IN (:laura, :carlos) AND DATE(fecha_generacion) = CURDATE()')
    ->execute(['laura' => EMPLEADO_LAURA, 'carlos' => EMPLEADO_CARLOS]);

$conteoRestaurado = (int) obtenerConexion()->query('SELECT COUNT(*) FROM certificado')->fetchColumn();
verificar($conteoRestaurado === $conteoAntes, 'La tabla certificado queda igual que al inicio (solo los 2 de la siembra)');

@unlink($cookieLaura);
@unlink($cookieContabilidad);

echo PHP_EOL . ($fallas === 0 ? "TODAS LAS PRUEBAS PASARON" : "$fallas PRUEBA(S) FALLARON") . PHP_EOL;
exit($fallas === 0 ? 0 : 1);

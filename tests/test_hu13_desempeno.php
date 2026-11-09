<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-13: registro de desempeño comercial.
 *
 * Verifica, contra la aplicación real (requiere Apache + MySQL activos):
 *  - Registro de desempeño válido.
 *  - Contestadas mayores que llamadas se rechaza.
 *  - Valores negativos se rechazan.
 *  - Fecha futura se rechaza.
 *  - Duplicado del mismo vendedor y día se rechaza.
 *  - Un vendedor no ve el registro de otro; el gerente sí ve el de todos.
 *  - Recordatorio válido; sin nota, o con fecha pasada, se rechazan.
 *  - Rol no autorizado (Técnico) recibe 403.
 *  - POST sin csrf_token rechazado.
 *
 * Usa el vendedor jperez (periodo 2026-11-01, libre en la siembra) y
 * el cliente de prueba id=1. Al terminar, borra el registro de
 * desempeño y el recordatorio de prueba (da_app no tiene DELETE sobre
 * 'desempeno_diario' ni 'recordatorio'; usa da_admin, igual que en
 * HU-04/05/07/08/09/11/12).
 *
 * Uso: php tests/test_hu13_desempeno.php
 */

require_once __DIR__ . '/../src/config/app.php';

const BASE = 'http://localhost/daservice';
const FECHA_PRUEBA = '2026-11-01'; // libre en la siembra para jperez
const CLIENTE_PRUEBA = 1;

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

function tokenDesempeno(string $cookieJar): string
{
    [, $html] = peticion('GET', BASE . '/desempeno.php', [], $cookieJar);
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

$cookieJperez  = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
$cookiePrios   = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
$cookieGerente = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
$cookieTecnico = tempnam(sys_get_temp_dir(), 'daservice_cookies_');

iniciarSesion('jperez', $cookieJperez);   // Vendedor
iniciarSesion('prios', $cookiePrios);     // Otro vendedor
iniciarSesion('cvelez', $cookieGerente);  // Gerente
iniciarSesion('amora', $cookieTecnico);   // Técnico, no autorizado

$totalInicial = count(listarDesempenoDeVendedor(8)); // 8 = usuario_id de jperez

// ---------------------------------------------------------------------
// 1. Rol no autorizado (Técnico): 403.
// ---------------------------------------------------------------------
[$statusTecnico] = peticion('GET', BASE . '/desempeno.php', [], $cookieTecnico);
verificar($statusTecnico === 403, 'Un rol no autorizado (Técnico) recibe 403 en desempeno.php');

// ---------------------------------------------------------------------
// 2. Contestadas mayores que llamadas se rechaza.
// ---------------------------------------------------------------------
$token = tokenDesempeno($cookieJperez);
[, $htmlContestadasMayor] = peticion('POST', BASE . '/desempeno.php', [
    'csrf_token' => $token, 'accion' => 'desempeno', 'fecha' => FECHA_PRUEBA,
    'llamadas_totales' => 10, 'llamadas_contestadas' => 15, 'buzon' => 0,
    'interesados' => 0, 'no_interesados' => 0, 'pendientes_volver_llamar' => 0, 'portafolios_enviados' => 0,
], $cookieJperez);
verificar(str_contains($htmlContestadasMayor, 'no pueden ser más que el total'), 'Contestadas mayores que llamadas se rechaza');

// ---------------------------------------------------------------------
// 3. Valores negativos se rechazan.
// ---------------------------------------------------------------------
$token = tokenDesempeno($cookieJperez);
[, $htmlNegativo] = peticion('POST', BASE . '/desempeno.php', [
    'csrf_token' => $token, 'accion' => 'desempeno', 'fecha' => FECHA_PRUEBA,
    'llamadas_totales' => -5, 'llamadas_contestadas' => 0, 'buzon' => 0,
    'interesados' => 0, 'no_interesados' => 0, 'pendientes_volver_llamar' => 0, 'portafolios_enviados' => 0,
], $cookieJperez);
verificar(str_contains($htmlNegativo, 'no negativos'), 'Un valor negativo se rechaza');

// ---------------------------------------------------------------------
// 4. Fecha futura se rechaza.
// ---------------------------------------------------------------------
$manana = (new DateTime('tomorrow'))->format('Y-m-d');
$token = tokenDesempeno($cookieJperez);
[, $htmlFechaFutura] = peticion('POST', BASE . '/desempeno.php', [
    'csrf_token' => $token, 'accion' => 'desempeno', 'fecha' => $manana,
    'llamadas_totales' => 10, 'llamadas_contestadas' => 5, 'buzon' => 0,
    'interesados' => 0, 'no_interesados' => 0, 'pendientes_volver_llamar' => 0, 'portafolios_enviados' => 0,
], $cookieJperez);
verificar(str_contains($htmlFechaFutura, 'no puede ser futura'), 'Una fecha futura se rechaza');

verificar(count(listarDesempenoDeVendedor(8)) === $totalInicial, 'Ninguno de los intentos inválidos creó un registro');

// ---------------------------------------------------------------------
// 5. POST sin csrf_token se rechaza.
// ---------------------------------------------------------------------
[, $htmlSinCsrf] = peticion('POST', BASE . '/desempeno.php', [
    'accion' => 'desempeno', 'fecha' => FECHA_PRUEBA,
    'llamadas_totales' => 10, 'llamadas_contestadas' => 5, 'buzon' => 0,
    'interesados' => 0, 'no_interesados' => 0, 'pendientes_volver_llamar' => 0, 'portafolios_enviados' => 0,
], $cookieJperez);
verificar(str_contains($htmlSinCsrf, 'La sesión del formulario expiró'), 'POST sin csrf_token es rechazado');

// ---------------------------------------------------------------------
// 6. Registro válido.
// ---------------------------------------------------------------------
$token = tokenDesempeno($cookieJperez);
[, $htmlOk] = peticion('POST', BASE . '/desempeno.php', [
    'csrf_token' => $token, 'accion' => 'desempeno', 'fecha' => FECHA_PRUEBA,
    'llamadas_totales' => 30, 'llamadas_contestadas' => 20, 'buzon' => 5,
    'interesados' => 8, 'no_interesados' => 7, 'pendientes_volver_llamar' => 5, 'portafolios_enviados' => 3,
], $cookieJperez);
verificar(str_contains($htmlOk, 'Desempeño del día registrado'), 'Registro de desempeño válido funciona');
verificar(count(listarDesempenoDeVendedor(8)) === $totalInicial + 1, 'El registro nuevo quedó guardado');

// ---------------------------------------------------------------------
// 7. Duplicado del mismo vendedor y día se rechaza.
// ---------------------------------------------------------------------
$token = tokenDesempeno($cookieJperez);
[, $htmlDuplicado] = peticion('POST', BASE . '/desempeno.php', [
    'csrf_token' => $token, 'accion' => 'desempeno', 'fecha' => FECHA_PRUEBA,
    'llamadas_totales' => 10, 'llamadas_contestadas' => 5, 'buzon' => 0,
    'interesados' => 0, 'no_interesados' => 0, 'pendientes_volver_llamar' => 0, 'portafolios_enviados' => 0,
], $cookieJperez);
verificar(str_contains($htmlDuplicado, 'No se puede duplicar'), 'Un segundo registro el mismo día se rechaza');
verificar(count(listarDesempenoDeVendedor(8)) === $totalInicial + 1, 'El intento duplicado no agregó un registro nuevo');

// ---------------------------------------------------------------------
// 8. Un vendedor no ve el registro de otro; el gerente ve el de todos.
// ---------------------------------------------------------------------
[, $htmlOtroVendedor] = peticion('GET', BASE . '/desempeno.php', [], $cookiePrios);
verificar(!str_contains($htmlOtroVendedor, FECHA_PRUEBA), 'Otro vendedor (Prios) no ve el registro de Jperez en su propia pantalla');

[, $htmlGerente] = peticion('GET', BASE . '/desempeno.php', [], $cookieGerente);
verificar(str_contains($htmlGerente, FECHA_PRUEBA) && str_contains($htmlGerente, 'Juan'), 'El gerente ve el registro de Jperez entre los de todos');

// ---------------------------------------------------------------------
// 9. Recordatorio sin nota se rechaza.
// ---------------------------------------------------------------------
$manana = (new DateTime('tomorrow'))->format('Y-m-d');
$token = tokenDesempeno($cookieJperez);
[, $htmlSinNota] = peticion('POST', BASE . '/desempeno.php', [
    'csrf_token' => $token, 'accion' => 'recordatorio',
    'cliente_id' => CLIENTE_PRUEBA, 'fecha_recordatorio' => $manana, 'nota' => '',
], $cookieJperez);
verificar(str_contains($htmlSinNota, 'La nota del recordatorio es obligatoria'), 'Un recordatorio sin nota se rechaza');

// ---------------------------------------------------------------------
// 10. Recordatorio con fecha pasada se rechaza.
// ---------------------------------------------------------------------
$ayer = (new DateTime('yesterday'))->format('Y-m-d');
$token = tokenDesempeno($cookieJperez);
[, $htmlFechaPasada] = peticion('POST', BASE . '/desempeno.php', [
    'csrf_token' => $token, 'accion' => 'recordatorio',
    'cliente_id' => CLIENTE_PRUEBA, 'fecha_recordatorio' => $ayer, 'nota' => 'Prueba HU-13 fecha pasada',
], $cookieJperez);
verificar(str_contains($htmlFechaPasada, 'no puede estar en el pasado'), 'Un recordatorio con fecha pasada se rechaza');

// ---------------------------------------------------------------------
// 11. Recordatorio válido.
// ---------------------------------------------------------------------
$token = tokenDesempeno($cookieJperez);
[, $htmlRecordatorioOk] = peticion('POST', BASE . '/desempeno.php', [
    'csrf_token' => $token, 'accion' => 'recordatorio',
    'cliente_id' => CLIENTE_PRUEBA, 'fecha_recordatorio' => $manana, 'nota' => 'Prueba HU-13 recordatorio válido',
], $cookieJperez);
verificar(str_contains($htmlRecordatorioOk, 'Recordatorio registrado'), 'Un recordatorio válido funciona');

$recordatorios = listarRecordatoriosDeVendedor(8);
$recordatorioPrueba = array_values(array_filter($recordatorios, fn($r) => $r['nota'] === 'Prueba HU-13 recordatorio válido'))[0] ?? null;
verificar($recordatorioPrueba !== null, 'El recordatorio de prueba quedó guardado');

// ---------------------------------------------------------------------
// Limpieza: borrar el registro de desempeño y el recordatorio de
// prueba.
// ---------------------------------------------------------------------
$pdoAdmin = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_ADMIN_USER, DB_ADMIN_PASS);
$pdoAdmin->prepare('DELETE FROM desempeno_diario WHERE vendedor_id = 8 AND fecha = :fecha')->execute(['fecha' => FECHA_PRUEBA]);
if ($recordatorioPrueba) {
    $pdoAdmin->prepare('DELETE FROM recordatorio WHERE id = :id')->execute(['id' => $recordatorioPrueba['id']]);
}

verificar(count(listarDesempenoDeVendedor(8)) === $totalInicial, 'El registro de desempeño de prueba se eliminó');
verificar(
    !in_array('Prueba HU-13 recordatorio válido', array_column(listarRecordatoriosDeVendedor(8), 'nota'), true),
    'El recordatorio de prueba se eliminó'
);

@unlink($cookieJperez);
@unlink($cookiePrios);
@unlink($cookieGerente);
@unlink($cookieTecnico);

echo PHP_EOL . ($fallas === 0 ? "TODAS LAS PRUEBAS PASARON" : "$fallas PRUEBA(S) FALLARON") . PHP_EOL;
exit($fallas === 0 ? 0 : 1);

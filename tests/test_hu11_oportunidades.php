<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-11: seguimiento de oportunidades comerciales.
 *
 * Verifica, contra la aplicación real (requiere Apache + MySQL activos):
 *  - Crear con datos válidos.
 *  - Valor negativo o no numérico se rechaza.
 *  - Moneda distinta de COP se rechaza (DT-20).
 *  - Cliente inexistente se rechaza.
 *  - Pasar a "perdido" sin motivo se rechaza.
 *  - Pasar a "perdido" con motivo: ok.
 *  - Rol no autorizado (Técnico) recibe 403.
 *  - POST sin csrf_token rechazado.
 *
 * Usa el cliente de datos de prueba id=1 (Plásticos del Valle) y el
 * vendedor jperez. La oportunidad que crea este script se borra al
 * terminar (da_app no tiene DELETE sobre 'oportunidad'; usa da_admin,
 * igual que en HU-04/05/07/08).
 *
 * Uso: php tests/test_hu11_oportunidades.php
 */

require_once __DIR__ . '/../src/config/app.php';

const BASE = 'http://localhost/daservice';
const CLIENTE_PRUEBA = 1; // Plásticos del Valle

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

function tokenOportunidades(string $cookieJar): string
{
    [, $html] = peticion('GET', BASE . '/oportunidades.php', [], $cookieJar);
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

$cookieVendedor = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
$cookieTecnico  = tempnam(sys_get_temp_dir(), 'daservice_cookies_');

iniciarSesion('jperez', $cookieVendedor); // Vendedor
iniciarSesion('amora', $cookieTecnico);   // Técnico, no autorizado

$totalInicial = count(listarOportunidades());

// ---------------------------------------------------------------------
// 1. Rol no autorizado (Técnico): ni ve ni puede crear.
// ---------------------------------------------------------------------
[$statusVerTecnico] = peticion('GET', BASE . '/oportunidades.php', [], $cookieTecnico);
verificar($statusVerTecnico === 403, 'Un rol no comercial (Técnico) recibe 403 en oportunidades.php');

// ---------------------------------------------------------------------
// 2. Valor negativo se rechaza.
// ---------------------------------------------------------------------
$token = tokenOportunidades($cookieVendedor);
[, $htmlValorNegativo] = peticion('POST', BASE . '/oportunidades.php', [
    'csrf_token'      => $token,
    'accion'          => 'crear',
    'cliente_id'      => CLIENTE_PRUEBA,
    'descripcion'     => 'Prueba HU-11 valor negativo',
    'valor_estimado'  => '-100',
    'moneda'          => 'COP',
], $cookieVendedor);
verificar(str_contains($htmlValorNegativo, 'mayor o igual a cero'), 'Un valor negativo se rechaza');

// ---------------------------------------------------------------------
// 3. Valor no numérico se rechaza.
// ---------------------------------------------------------------------
$token = tokenOportunidades($cookieVendedor);
[, $htmlValorNoNumerico] = peticion('POST', BASE . '/oportunidades.php', [
    'csrf_token'      => $token,
    'accion'          => 'crear',
    'cliente_id'      => CLIENTE_PRUEBA,
    'descripcion'     => 'Prueba HU-11 valor no numérico',
    'valor_estimado'  => 'quince millones',
    'moneda'          => 'COP',
], $cookieVendedor);
verificar(str_contains($htmlValorNoNumerico, 'mayor o igual a cero'), 'Un valor no numérico se rechaza');

// ---------------------------------------------------------------------
// 4. Moneda distinta de COP se rechaza.
// ---------------------------------------------------------------------
$token = tokenOportunidades($cookieVendedor);
[, $htmlMonedaInvalida] = peticion('POST', BASE . '/oportunidades.php', [
    'csrf_token'      => $token,
    'accion'          => 'crear',
    'cliente_id'      => CLIENTE_PRUEBA,
    'descripcion'     => 'Prueba HU-11 moneda inválida',
    'valor_estimado'  => '1000000',
    'moneda'          => 'USD',
], $cookieVendedor);
verificar(str_contains($htmlMonedaInvalida, 'solo se admite la moneda COP'), 'Una moneda distinta de COP se rechaza');

// ---------------------------------------------------------------------
// 5. Cliente inexistente se rechaza.
// ---------------------------------------------------------------------
$token = tokenOportunidades($cookieVendedor);
[, $htmlClienteInexistente] = peticion('POST', BASE . '/oportunidades.php', [
    'csrf_token'      => $token,
    'accion'          => 'crear',
    'cliente_id'      => 999999,
    'descripcion'     => 'Prueba HU-11 cliente inexistente',
    'valor_estimado'  => '1000000',
    'moneda'          => 'COP',
], $cookieVendedor);
verificar(str_contains($htmlClienteInexistente, 'El cliente no existe'), 'Un cliente inexistente se rechaza');

verificar(count(listarOportunidades()) === $totalInicial, 'Ninguno de los intentos inválidos creó una oportunidad');

// ---------------------------------------------------------------------
// 6. POST sin csrf_token se rechaza.
// ---------------------------------------------------------------------
[, $htmlSinCsrf] = peticion('POST', BASE . '/oportunidades.php', [
    'accion'          => 'crear',
    'cliente_id'      => CLIENTE_PRUEBA,
    'descripcion'     => 'Prueba HU-11 sin CSRF',
    'valor_estimado'  => '1000000',
    'moneda'          => 'COP',
], $cookieVendedor);
verificar(str_contains($htmlSinCsrf, 'La sesión del formulario expiró'), 'POST sin csrf_token es rechazado');
verificar(count(listarOportunidades()) === $totalInicial, 'El POST sin CSRF no creó ninguna oportunidad');

// ---------------------------------------------------------------------
// 7. Crear con datos válidos.
// ---------------------------------------------------------------------
$token = tokenOportunidades($cookieVendedor);
[, $htmlCrearOk] = peticion('POST', BASE . '/oportunidades.php', [
    'csrf_token'      => $token,
    'accion'          => 'crear',
    'cliente_id'      => CLIENTE_PRUEBA,
    'descripcion'     => 'Mantenimiento de equipos (prueba HU-11)',
    'valor_estimado'  => '3500000',
    'moneda'          => 'COP',
], $cookieVendedor);
verificar(str_contains($htmlCrearOk, 'Oportunidad creada'), 'Crear una oportunidad con datos válidos funciona');
verificar(count(listarOportunidades()) === $totalInicial + 1, 'La oportunidad nueva quedó guardada');

$oportunidades = listarOportunidades();
$nueva = $oportunidades[0]; // ORDER BY fecha_creacion DESC
verificar($nueva['etapa'] === 'calificado', 'La oportunidad nueva nace en etapa "calificado"');
$idOportunidadPrueba = (int) $nueva['id'];

// ---------------------------------------------------------------------
// 8. Pasar a "perdido" sin motivo se rechaza.
// ---------------------------------------------------------------------
$token = tokenOportunidades($cookieVendedor);
[, $htmlPerdidoSinMotivo] = peticion('POST', BASE . '/oportunidades.php', [
    'csrf_token'     => $token,
    'accion'         => 'cambiar_etapa',
    'id'             => $idOportunidadPrueba,
    'etapa'          => 'perdido',
    'motivo_perdida' => '',
], $cookieVendedor);
verificar(str_contains($htmlPerdidoSinMotivo, 'Indica el motivo de la pérdida'), 'Pasar a "perdido" sin motivo se rechaza');
verificar(obtenerOportunidad($idOportunidadPrueba)['etapa'] === 'calificado', 'La etapa sigue en "calificado" tras el intento sin motivo');

// ---------------------------------------------------------------------
// 9. Pasar a "perdido" CON motivo: ok.
// ---------------------------------------------------------------------
$token = tokenOportunidades($cookieVendedor);
[, $htmlPerdidoConMotivo] = peticion('POST', BASE . '/oportunidades.php', [
    'csrf_token'     => $token,
    'accion'         => 'cambiar_etapa',
    'id'             => $idOportunidadPrueba,
    'etapa'          => 'perdido',
    'motivo_perdida' => 'El cliente pospuso el proyecto (prueba HU-11)',
], $cookieVendedor);
verificar(str_contains($htmlPerdidoConMotivo, 'Etapa actualizada'), 'Pasar a "perdido" con motivo funciona');

$oportunidadPerdida = obtenerOportunidad($idOportunidadPrueba);
verificar($oportunidadPerdida['etapa'] === 'perdido', 'La etapa queda en "perdido"');
verificar($oportunidadPerdida['motivo_perdida'] === 'El cliente pospuso el proyecto (prueba HU-11)', 'El motivo de la pérdida queda guardado');

// ---------------------------------------------------------------------
// Limpieza: borrar la oportunidad de prueba. da_app no tiene DELETE
// sobre 'oportunidad' (solo sobre 'desprendible'); usa da_admin.
// ---------------------------------------------------------------------
$pdoAdmin = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_ADMIN_USER, DB_ADMIN_PASS);
$pdoAdmin->prepare('DELETE FROM oportunidad WHERE id = :id')->execute(['id' => $idOportunidadPrueba]);
verificar(count(listarOportunidades()) === $totalInicial, 'La oportunidad de prueba se eliminó; queda el mismo total que al inicio');

@unlink($cookieVendedor);
@unlink($cookieTecnico);

echo PHP_EOL . ($fallas === 0 ? "TODAS LAS PRUEBAS PASARON" : "$fallas PRUEBA(S) FALLARON") . PHP_EOL;
exit($fallas === 0 ? 0 : 1);

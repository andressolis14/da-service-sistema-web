<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-10: historial y estados de clientes.
 *
 * Verifica, contra la aplicación real (requiere Apache + MySQL activos):
 *  - Cambio de estado sin motivo es rechazado.
 *  - Cambio de estado con motivo queda registrado en el historial.
 *  - El historial se muestra en orden cronológico.
 *  - Un rol no autorizado (Técnico) recibe 403.
 *  - Un POST sin token CSRF es rechazado.
 *
 * Usa el cliente de datos de prueba id=6 (Industrias Alfa). Lee su estado
 * actual al iniciar (puede variar entre corridas, es normal) y al terminar
 * lo deja otra vez en ese mismo estado (puede quedar un registro adicional
 * en el historial, que es el comportamiento esperado: el historial nunca
 * se borra).
 *
 * Uso: php tests/test_hu10_clientes.php
 */

require_once __DIR__ . '/../src/config/app.php';

const BASE = 'http://localhost/daservice';
const CLIENTE_ID = 6;

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

// 0. Estado inicial del cliente de prueba (puede variar entre corridas:
//    el estado de un cliente cambia con el uso real, y eso es lo esperado).
$estadoInicial = obtenerCliente(CLIENTE_ID)['estado'];
verificar(in_array($estadoInicial, ['prospecto', 'activo', 'inactivo'], true), "Cliente " . CLIENTE_ID . " tiene un estado válido ('$estadoInicial')");
$historialInicial = count(listarHistorialCliente(CLIENTE_ID));

// Estado de prueba: cualquiera distinto al inicial.
$estadoPrueba = $estadoInicial === 'activo' ? 'inactivo' : 'activo';

// 1. Rol no autorizado (Técnico) -> 403.
iniciarSesion('amora', $cookieTecnico);
[$statusTecnico] = peticion('GET', BASE . '/clientes.php', [], $cookieTecnico);
verificar($statusTecnico === 403, 'Un rol no comercial (Técnico) recibe 403 en clientes.php');

// 2. Login como Vendedor (rol comercial autorizado).
iniciarSesion('jperez', $cookieVendedor);
[$statusLista] = peticion('GET', BASE . '/clientes.php', [], $cookieVendedor);
verificar($statusLista === 200, 'El Vendedor sí accede a clientes.php (200)');

// 3. Cambio de estado SIN motivo: debe rechazarse y no alterar el estado.
[, $htmlVer] = peticion('GET', BASE . '/clientes.php?ver=' . CLIENTE_ID, [], $cookieVendedor);
$token1 = extraerToken($htmlVer);
[, $htmlSinMotivo] = peticion('POST', BASE . '/clientes.php', [
    'csrf_token'   => $token1,
    'accion'       => 'cambiar_estado',
    'id'           => CLIENTE_ID,
    'nuevo_estado' => $estadoPrueba,
    'motivo'       => '',
], $cookieVendedor);
verificar(
    str_contains($htmlSinMotivo, 'El motivo del cambio es obligatorio'),
    'Cambio de estado sin motivo es rechazado'
);
verificar(obtenerCliente(CLIENTE_ID)['estado'] === $estadoInicial, 'El estado no cambió tras el intento sin motivo');

// 4. Cambio de estado CON motivo: debe aplicarse y quedar en el historial.
[, $htmlVer2] = peticion('GET', BASE . '/clientes.php?ver=' . CLIENTE_ID, [], $cookieVendedor);
$token2 = extraerToken($htmlVer2);
peticion('POST', BASE . '/clientes.php', [
    'csrf_token'   => $token2,
    'accion'       => 'cambiar_estado',
    'id'           => CLIENTE_ID,
    'nuevo_estado' => $estadoPrueba,
    'motivo'       => 'Cambio de prueba HU-10 (' . $estadoInicial . ' -> ' . $estadoPrueba . ')',
], $cookieVendedor);
$clienteTrasCambio = obtenerCliente(CLIENTE_ID);
verificar($clienteTrasCambio['estado'] === $estadoPrueba, "El cliente queda en estado '$estadoPrueba' tras el cambio con motivo");

$historial = listarHistorialCliente(CLIENTE_ID);
verificar(count($historial) === $historialInicial + 1, 'El cambio quedó registrado en el historial (un registro nuevo)');
$ultimo = end($historial);
verificar(
    $ultimo['estado_anterior'] === $estadoInicial && $ultimo['estado_nuevo'] === $estadoPrueba
        && $ultimo['motivo'] === 'Cambio de prueba HU-10 (' . $estadoInicial . ' -> ' . $estadoPrueba . ')',
    'El último registro del historial tiene el estado anterior, el nuevo y el motivo correctos'
);

// 5. El historial se ve en orden cronológico (cada fecha >= la anterior).
$fechas = array_column($historial, 'fecha');
$ordenado = $fechas === (function () use ($fechas) {
    $copia = $fechas;
    sort($copia);
    return $copia;
})();
verificar($ordenado, 'El historial se devuelve en orden cronológico ascendente');

// 6. POST sin token CSRF: debe rechazarse y no cambiar el estado.
[, $htmlSinCsrf] = peticion('POST', BASE . '/clientes.php', [
    'accion'       => 'cambiar_estado',
    'id'           => CLIENTE_ID,
    'nuevo_estado' => $estadoInicial,
    'motivo'       => 'Intento sin token',
], $cookieVendedor);
verificar(
    str_contains($htmlSinCsrf, 'La sesión del formulario expiró'),
    'POST sin csrf_token es rechazado con el mensaje de sesión expirada'
);
verificar(obtenerCliente(CLIENTE_ID)['estado'] === $estadoPrueba, 'El estado no cambió tras el POST sin CSRF');

// 7. Restaurar el estado original, con motivo; queda un registro adicional
//    en el historial, que nunca se borra.
[, $htmlVer3] = peticion('GET', BASE . '/clientes.php?ver=' . CLIENTE_ID, [], $cookieVendedor);
$token3 = extraerToken($htmlVer3);
peticion('POST', BASE . '/clientes.php', [
    'csrf_token'   => $token3,
    'accion'       => 'cambiar_estado',
    'id'           => CLIENTE_ID,
    'nuevo_estado' => $estadoInicial,
    'motivo'       => 'Reversión de datos de prueba HU-10',
], $cookieVendedor);
verificar(obtenerCliente(CLIENTE_ID)['estado'] === $estadoInicial, "Cliente devuelto a su estado original ('$estadoInicial')");

@unlink($cookieVendedor);
@unlink($cookieTecnico);

echo PHP_EOL . ($fallas === 0 ? "TODAS LAS PRUEBAS PASARON" : "$fallas PRUEBA(S) FALLARON") . PHP_EOL;
exit($fallas === 0 ? 0 : 1);

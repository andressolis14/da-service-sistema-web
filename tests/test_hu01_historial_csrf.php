<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-01: pruebas de historial y CSRF.
 *
 * Verifica, contra la aplicación real (requiere Apache + MySQL activos):
 *  (a) al desactivar un empleado, sus registros vinculados (desprendible, permiso)
 *      siguen intactos y el registro del empleado nunca se borra.
 *  (b) un POST a empleados.php sin token CSRF es rechazado y no cambia el estado.
 *
 * Usa el empleado de datos de prueba id=10 (Laura Gómez) y la cuenta de prueba
 * dsalazar / Prueba2026* (ver kit_implementacion/da_service_bd.sql). Al terminar,
 * deja el estado del empleado igual a como estaba al iniciar.
 *
 * Uso: php tests/test_hu01_historial_csrf.php
 */

require_once __DIR__ . '/../src/config/app.php';

const BASE = 'http://localhost/daservice';
const EMPLEADO_ID = 10;

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

function verificar(bool $condicion, string $descripcion): void
{
    global $fallas;
    echo ($condicion ? "[OK] " : "[FALLA] ") . $descripcion . PHP_EOL;
    if (!$condicion) {
        $fallas++;
    }
}

function contarVinculados(int $empleadoId): array
{
    $pdo = obtenerConexion();
    $desp = $pdo->prepare('SELECT COUNT(*) FROM desprendible WHERE empleado_id = :id');
    $desp->execute(['id' => $empleadoId]);
    $perm = $pdo->prepare('SELECT COUNT(*) FROM permiso WHERE empleado_id = :id');
    $perm->execute(['id' => $empleadoId]);
    $existe = $pdo->prepare('SELECT estado FROM empleado WHERE id = :id');
    $existe->execute(['id' => $empleadoId]);
    $estado = $existe->fetchColumn();
    return [(int) $desp->fetchColumn(), (int) $perm->fetchColumn(), $estado];
}

$cookieJar = tempnam(sys_get_temp_dir(), 'daservice_cookies_');

// 1. Login como Administrador (dsalazar).
[, $htmlLogin] = peticion('GET', BASE . '/login.php', [], $cookieJar);
$tokenLogin = extraerToken($htmlLogin);
[$status] = peticion('POST', BASE . '/login.php', [
    'csrf_token'     => $tokenLogin,
    'nombre_usuario' => 'dsalazar',
    'password'       => 'Prueba2026*',
], $cookieJar);
verificar($status === 302, 'Login de dsalazar redirige correctamente (302)');

// 2. Estado inicial del empleado de prueba.
[$despAntes, $permAntes, $estadoInicial] = contarVinculados(EMPLEADO_ID);
verificar($estadoInicial === 'activo', "Empleado " . EMPLEADO_ID . " inicia en estado 'activo'");

// 3. Desactivar con CSRF válido.
[, $htmlEmpleados] = peticion('GET', BASE . '/empleados.php', [], $cookieJar);
$token1 = extraerToken($htmlEmpleados);
peticion('POST', BASE . '/empleados.php', [
    'csrf_token'   => $token1,
    'accion'       => 'cambiar_estado',
    'id'           => EMPLEADO_ID,
    'nuevo_estado' => 'inactivo',
], $cookieJar);

[$despDespues, $permDespues, $estadoTrasDesactivar] = contarVinculados(EMPLEADO_ID);
verificar($estadoTrasDesactivar === 'inactivo', '(a) El empleado queda inactivo tras desactivarlo');
verificar($despDespues === $despAntes, "(a) Sus desprendibles siguen intactos ($despAntes = $despDespues)");
verificar($permDespues === $permAntes, "(a) Sus permisos siguen intactos ($permAntes = $permDespues)");

// 4. POST sin token CSRF: debe rechazarse y no reactivar.
[, $htmlSinCsrf] = peticion('POST', BASE . '/empleados.php', [
    'accion'       => 'cambiar_estado',
    'id'           => EMPLEADO_ID,
    'nuevo_estado' => 'activo',
], $cookieJar);
verificar(
    str_contains($htmlSinCsrf, 'La sesión del formulario expiró'),
    '(b) POST sin csrf_token es rechazado con el mensaje de sesión expirada'
);
[, , $estadoTrasPostSinCsrf] = contarVinculados(EMPLEADO_ID);
verificar($estadoTrasPostSinCsrf === 'inactivo', '(b) El POST sin CSRF no cambió el estado (sigue inactivo)');

// 5. Restaurar el estado original (dejar la BD como al iniciar).
[, $htmlEmpleados2] = peticion('GET', BASE . '/empleados.php', [], $cookieJar);
$token2 = extraerToken($htmlEmpleados2);
peticion('POST', BASE . '/empleados.php', [
    'csrf_token'   => $token2,
    'accion'       => 'cambiar_estado',
    'id'           => EMPLEADO_ID,
    'nuevo_estado' => $estadoInicial,
], $cookieJar);
[, , $estadoFinal] = contarVinculados(EMPLEADO_ID);
verificar($estadoFinal === $estadoInicial, 'Estado del empleado restaurado al valor inicial');

@unlink($cookieJar);

echo PHP_EOL . ($fallas === 0 ? "TODAS LAS PRUEBAS PASARON" : "$fallas PRUEBA(S) FALLARON") . PHP_EOL;
exit($fallas === 0 ? 0 : 1);

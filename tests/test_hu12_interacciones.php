<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-12: registro de interacciones con clientes.
 *
 * Verifica, contra la aplicación real (requiere Apache + MySQL activos):
 *  - Crear con datos válidos.
 *  - Descripción vacía se rechaza.
 *  - Fecha futura se rechaza.
 *  - Cliente inexistente se rechaza.
 *  - Tipo no válido se rechaza.
 *  - El listado se muestra en orden cronológico.
 *  - Rol no autorizado (Técnico) recibe 403.
 *  - POST sin csrf_token rechazado.
 *  - Una descripción con HTML se muestra escapada.
 *
 * Usa el cliente de datos de prueba id=1 (Plásticos del Valle) y el
 * vendedor jperez. Las interacciones que crea este script se borran al
 * terminar (da_app no tiene DELETE sobre 'interaccion'; usa da_admin,
 * igual que en HU-04/05/07/08/09/11).
 *
 * Uso: php tests/test_hu12_interacciones.php
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

function tokenVerCliente(int $id, string $cookieJar): string
{
    [, $html] = peticion('GET', BASE . '/clientes.php?ver=' . $id, [], $cookieJar);
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

iniciarSesion('jperez', $cookieVendedor);
iniciarSesion('amora', $cookieTecnico);

$totalInicial = count(listarInteraccionesDeCliente(CLIENTE_PRUEBA));
$hoy = new DateTime();
$manana = (clone $hoy)->modify('+1 day')->format('Y-m-d\TH:i');

// ---------------------------------------------------------------------
// 1. Rol no autorizado (Técnico): no ve ni puede crear.
// ---------------------------------------------------------------------
[$statusTecnico] = peticion('GET', BASE . '/clientes.php?ver=' . CLIENTE_PRUEBA, [], $cookieTecnico);
verificar($statusTecnico === 403, 'Un rol no autorizado (Técnico) recibe 403 en clientes.php');

// ---------------------------------------------------------------------
// 2. Descripción vacía se rechaza.
// ---------------------------------------------------------------------
$token = tokenVerCliente(CLIENTE_PRUEBA, $cookieVendedor);
[, $htmlSinDescripcion] = peticion('POST', BASE . '/clientes.php', [
    'csrf_token'  => $token,
    'accion'      => 'interaccion',
    'id'          => CLIENTE_PRUEBA,
    'fecha'       => $hoy->format('Y-m-d\TH:i'),
    'tipo'        => 'llamada',
    'descripcion' => '',
], $cookieVendedor);
verificar(str_contains($htmlSinDescripcion, 'La descripción es obligatoria'), 'Una descripción vacía se rechaza');

// ---------------------------------------------------------------------
// 3. Fecha futura se rechaza.
// ---------------------------------------------------------------------
$token = tokenVerCliente(CLIENTE_PRUEBA, $cookieVendedor);
[, $htmlFechaFutura] = peticion('POST', BASE . '/clientes.php', [
    'csrf_token'  => $token,
    'accion'      => 'interaccion',
    'id'          => CLIENTE_PRUEBA,
    'fecha'       => $manana,
    'tipo'        => 'llamada',
    'descripcion' => 'Prueba HU-12 fecha futura',
], $cookieVendedor);
verificar(str_contains($htmlFechaFutura, 'no puede ser futura'), 'Una fecha futura se rechaza');

// ---------------------------------------------------------------------
// 4. Cliente inexistente se rechaza.
// ---------------------------------------------------------------------
$token = tokenVerCliente(CLIENTE_PRUEBA, $cookieVendedor);
[, $htmlClienteInexistente] = peticion('POST', BASE . '/clientes.php', [
    'csrf_token'  => $token,
    'accion'      => 'interaccion',
    'id'          => 999999,
    'fecha'       => $hoy->format('Y-m-d\TH:i'),
    'tipo'        => 'llamada',
    'descripcion' => 'Prueba HU-12 cliente inexistente',
], $cookieVendedor);
verificar(str_contains($htmlClienteInexistente, 'El cliente no existe'), 'Un cliente inexistente se rechaza');

// ---------------------------------------------------------------------
// 5. Tipo no válido se rechaza.
// ---------------------------------------------------------------------
$token = tokenVerCliente(CLIENTE_PRUEBA, $cookieVendedor);
[, $htmlTipoInvalido] = peticion('POST', BASE . '/clientes.php', [
    'csrf_token'  => $token,
    'accion'      => 'interaccion',
    'id'          => CLIENTE_PRUEBA,
    'fecha'       => $hoy->format('Y-m-d\TH:i'),
    'tipo'        => 'telepatia',
    'descripcion' => 'Prueba HU-12 tipo inválido',
], $cookieVendedor);
verificar(str_contains($htmlTipoInvalido, 'El tipo de interacción no es válido'), 'Un tipo no válido se rechaza');

verificar(count(listarInteraccionesDeCliente(CLIENTE_PRUEBA)) === $totalInicial, 'Ninguno de los intentos inválidos creó una interacción');

// ---------------------------------------------------------------------
// 6. POST sin csrf_token se rechaza.
// ---------------------------------------------------------------------
[, $htmlSinCsrf] = peticion('POST', BASE . '/clientes.php', [
    'accion'      => 'interaccion',
    'id'          => CLIENTE_PRUEBA,
    'fecha'       => $hoy->format('Y-m-d\TH:i'),
    'tipo'        => 'llamada',
    'descripcion' => 'Prueba HU-12 sin CSRF',
], $cookieVendedor);
verificar(str_contains($htmlSinCsrf, 'La sesión del formulario expiró'), 'POST sin csrf_token es rechazado');
verificar(count(listarInteraccionesDeCliente(CLIENTE_PRUEBA)) === $totalInicial, 'El POST sin CSRF no creó ninguna interacción');

// ---------------------------------------------------------------------
// 7. Crear con datos válidos, con descripción que incluye HTML (para
//    probar después que se muestra escapada).
// ---------------------------------------------------------------------
$descripcionConHtml = 'Se acordó enviar el portafolio <b>esta semana</b> <script>alert(1)</script>';
$token = tokenVerCliente(CLIENTE_PRUEBA, $cookieVendedor);
[, $htmlOk] = peticion('POST', BASE . '/clientes.php', [
    'csrf_token'  => $token,
    'accion'      => 'interaccion',
    'id'          => CLIENTE_PRUEBA,
    'fecha'       => $hoy->format('Y-m-d\TH:i'),
    'tipo'        => 'llamada',
    'descripcion' => $descripcionConHtml,
], $cookieVendedor);
verificar(str_contains($htmlOk, 'Interacción registrada'), 'Crear una interacción con datos válidos funciona');
verificar(count(listarInteraccionesDeCliente(CLIENTE_PRUEBA)) === $totalInicial + 1, 'La interacción nueva quedó guardada');

// ---------------------------------------------------------------------
// 8. La descripción con HTML se muestra escapada, no cruda.
// ---------------------------------------------------------------------
[, $htmlDetalle] = peticion('GET', BASE . '/clientes.php?ver=' . CLIENTE_PRUEBA, [], $cookieVendedor);
verificar(
    str_contains($htmlDetalle, 'Se acordó enviar el portafolio &lt;b&gt;esta semana&lt;/b&gt; &lt;script&gt;alert(1)&lt;/script&gt;'),
    'La descripción con HTML se muestra escapada'
);
verificar(!str_contains($htmlDetalle, '<script>alert(1)</script>'), 'La descripción NO aparece como script ejecutable');

// ---------------------------------------------------------------------
// 9. El listado se muestra en orden cronológico: se agrega una segunda
//    interacción con fecha anterior a la primera y se confirma el orden.
// ---------------------------------------------------------------------
$ayer = (clone $hoy)->modify('-1 day')->format('Y-m-d\TH:i');
$token = tokenVerCliente(CLIENTE_PRUEBA, $cookieVendedor);
peticion('POST', BASE . '/clientes.php', [
    'csrf_token'  => $token,
    'accion'      => 'interaccion',
    'id'          => CLIENTE_PRUEBA,
    'fecha'       => $ayer,
    'tipo'        => 'correo',
    'descripcion' => 'Prueba HU-12 orden cronológico (anterior)',
], $cookieVendedor);

$interacciones = listarInteraccionesDeCliente(CLIENTE_PRUEBA);
$fechas = array_column($interacciones, 'fecha');
$fechasOrdenadas = $fechas;
sort($fechasOrdenadas);
verificar($fechas === $fechasOrdenadas, 'El listado de interacciones está en orden cronológico ascendente');

// ---------------------------------------------------------------------
// Limpieza: borrar las interacciones de prueba de este cliente.
// ---------------------------------------------------------------------
$pdoAdmin = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_ADMIN_USER, DB_ADMIN_PASS);
$pdoAdmin->prepare("
    DELETE FROM interaccion
    WHERE cliente_id = :id
      AND (descripcion LIKE 'Prueba HU-12%' OR descripcion LIKE 'Se acordó enviar el portafolio%')
")->execute(['id' => CLIENTE_PRUEBA]);

verificar(count(listarInteraccionesDeCliente(CLIENTE_PRUEBA)) === $totalInicial, 'Las interacciones de prueba se eliminaron; queda el mismo total que al inicio');

@unlink($cookieVendedor);
@unlink($cookieTecnico);

echo PHP_EOL . ($fallas === 0 ? "TODAS LAS PRUEBAS PASARON" : "$fallas PRUEBA(S) FALLARON") . PHP_EOL;
exit($fallas === 0 ? 0 : 1);

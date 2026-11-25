<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * RNF-01 (cuentas y sesión), RNF-02 (control de acceso por rol) y
 * RNF-03 + HU-06 (aviso de datos y formulario público de tickets).
 *
 * Verifica, contra la aplicación real (requiere Apache + MySQL activos):
 *  - Login válido en al menos 3 roles distintos.
 *  - Clave incorrecta se rechaza.
 *  - Logout destruye la sesión (la pantalla protegida vuelve a pedir login).
 *  - Pantalla protegida sin sesión redirige a login.php.
 *  - Rol no autorizado para una pantalla -> 403, verificado en servidor.
 *  - Formulario público de tickets sin aceptar el aviso -> rechazado.
 *  - Con aviso aceptado y datos válidos -> ticket creado en estado pendiente.
 *  - Correo inválido -> rechazado.
 *  - POST sin csrf_token -> rechazado (login y formulario público).
 *
 * Usa cuentas de la siembra (contraseña de prueba 'Prueba2026*' para
 * todas): dsalazar (Administrador), mdiaz (Jefe), jperez (Vendedor). El
 * ticket que crea este script se borra al terminar con da_admin (da_app
 * no tiene DELETE sobre 'ticket'), igual que en otras suites del proyecto.
 *
 * Uso: php tests/test_rnf01_02_03_hu06.php
 */

require_once __DIR__ . '/../src/config/app.php';

const BASE = 'http://localhost/daservice';
const CLAVE_PRUEBA = 'Prueba2026*';
const CORREO_PRUEBA_TICKET = 'cliente.prueba@example.com';

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
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirectUrl = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return [$status, $cuerpo, $redirectUrl];
}

function extraerToken(string $html): string
{
    preg_match('/name="csrf_token" value="([^"]*)"/', $html, $m);
    return $m[1] ?? '';
}

function tokenDe(string $url, string $cookieJar): string
{
    [, $html] = peticion('GET', $url, [], $cookieJar);
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

// ---------------------------------------------------------------------
// 1. Login válido en 3 roles distintos (Administrador, Jefe, Vendedor).
// ---------------------------------------------------------------------
$cookieAdmin = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
$cookieJefe = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
$cookieVendedor = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
$cookieClaveMala = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
$cookieSinSesion = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
$cookieSinCsrf = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
$cookieTicket = tempnam(sys_get_temp_dir(), 'daservice_cookies_');

foreach ([
    ['dsalazar', $cookieAdmin, 'Administrador'],
    ['mdiaz', $cookieJefe, 'Jefe'],
    ['jperez', $cookieVendedor, 'Vendedor'],
] as [$usuario, $cookieJar, $rol]) {
    $token = tokenDe(BASE . '/login.php', $cookieJar);
    [$status, , $redirectUrl] = peticion('POST', BASE . '/login.php', [
        'csrf_token'     => $token,
        'nombre_usuario' => $usuario,
        'password'       => CLAVE_PRUEBA,
    ], $cookieJar);
    verificar(
        $status === 302 && str_contains($redirectUrl, '/bienvenida.php'),
        "Login válido del rol $rol ($usuario) redirige a bienvenida"
    );
}

// ---------------------------------------------------------------------
// 2. Clave incorrecta: se rechaza, sin crear sesión.
// ---------------------------------------------------------------------
$token = tokenDe(BASE . '/login.php', $cookieClaveMala);
[, $htmlClaveMala] = peticion('POST', BASE . '/login.php', [
    'csrf_token'     => $token,
    'nombre_usuario' => 'dsalazar',
    'password'       => 'ClaveIncorrectaDePrueba*',
], $cookieClaveMala);
verificar(str_contains($htmlClaveMala, 'Usuario o contraseña incorrectos'), 'Clave incorrecta se rechaza');

[$statusTrasClaveMala, , $redirectTrasClaveMala] = peticion('GET', BASE . '/empleados.php', [], $cookieClaveMala);
verificar(
    $statusTrasClaveMala === 302 && str_contains($redirectTrasClaveMala, '/login.php'),
    'Tras clave incorrecta no quedó sesión iniciada (pantalla protegida redirige a login)'
);

// ---------------------------------------------------------------------
// 3. POST de login sin csrf_token: se rechaza.
// ---------------------------------------------------------------------
[, $htmlLoginSinCsrf] = peticion('POST', BASE . '/login.php', [
    'nombre_usuario' => 'dsalazar',
    'password'       => CLAVE_PRUEBA,
], $cookieSinCsrf);
verificar(str_contains($htmlLoginSinCsrf, 'La sesión del formulario expiró'), 'POST de login sin csrf_token es rechazado');

// ---------------------------------------------------------------------
// 4. Pantalla protegida sin sesión redirige a login.php.
// ---------------------------------------------------------------------
[$statusSinSesion, , $redirectSinSesion] = peticion('GET', BASE . '/empleados.php', [], $cookieSinSesion);
verificar(
    $statusSinSesion === 302 && str_contains($redirectSinSesion, '/login.php'),
    'Pantalla protegida sin sesión redirige a login.php'
);

// ---------------------------------------------------------------------
// 5. Rol no autorizado -> 403 (Vendedor contra empleados.php, solo para
//    Administrador y Contabilidad).
// ---------------------------------------------------------------------
[$statusNoAutorizado, $htmlNoAutorizado] = peticion('GET', BASE . '/empleados.php', [], $cookieVendedor);
verificar(
    $statusNoAutorizado === 403 && str_contains($htmlNoAutorizado, 'Acceso denegado'),
    'Rol Vendedor sin permiso sobre empleados.php -> 403'
);

// Confirma que el rol correcto (Administrador) sí entra a la misma pantalla.
[$statusAutorizado] = peticion('GET', BASE . '/empleados.php', [], $cookieAdmin);
verificar($statusAutorizado === 200, 'El rol Administrador sí entra a empleados.php');

// ---------------------------------------------------------------------
// 6. Logout destruye la sesión.
// ---------------------------------------------------------------------
peticion('GET', BASE . '/logout.php', [], $cookieAdmin);
[$statusTrasLogout, , $redirectTrasLogout] = peticion('GET', BASE . '/empleados.php', [], $cookieAdmin);
verificar(
    $statusTrasLogout === 302 && str_contains($redirectTrasLogout, '/login.php'),
    'Tras logout, la misma cookie ya no tiene acceso: redirige a login.php'
);

// ---------------------------------------------------------------------
// 7. Formulario público de tickets sin aceptar el aviso -> rechazado.
// ---------------------------------------------------------------------
$token = tokenDe(BASE . '/soporte.php', $cookieTicket);
[, $htmlSinAviso] = peticion('POST', BASE . '/soporte.php', [
    'csrf_token'           => $token,
    'solicitante_nombre'   => 'Empresa Prueba RNF-03',
    'solicitante_correo'   => CORREO_PRUEBA_TICKET,
    'solicitante_telefono' => '',
    'solicitante_tipo'     => 'externo',
    'asunto'               => 'Prueba automatizada RNF-03/HU-06',
    'descripcion'          => 'Descripción de prueba, sin aceptar el aviso.',
    // 'aviso_aceptado' se omite a propósito.
], $cookieTicket);
verificar(
    str_contains($htmlSinAviso, 'Debes aceptar el aviso de tratamiento de datos'),
    'Formulario público sin aceptar el aviso se rechaza'
);

// ---------------------------------------------------------------------
// 8. Correo inválido -> rechazado (con el aviso sí aceptado).
// ---------------------------------------------------------------------
$token = tokenDe(BASE . '/soporte.php', $cookieTicket);
[, $htmlCorreoInvalido] = peticion('POST', BASE . '/soporte.php', [
    'csrf_token'           => $token,
    'solicitante_nombre'   => 'Empresa Prueba RNF-03',
    'solicitante_correo'   => 'no-es-un-correo',
    'solicitante_telefono' => '',
    'solicitante_tipo'     => 'externo',
    'asunto'               => 'Prueba automatizada RNF-03/HU-06',
    'descripcion'          => 'Descripción de prueba, con correo inválido.',
    'aviso_aceptado'       => '1',
], $cookieTicket);
verificar(str_contains($htmlCorreoInvalido, 'El correo no es válido'), 'Correo inválido se rechaza');

// ---------------------------------------------------------------------
// 9. POST sin csrf_token -> rechazado.
// ---------------------------------------------------------------------
[, $htmlSinCsrfTicket] = peticion('POST', BASE . '/soporte.php', [
    'solicitante_nombre'   => 'Empresa Prueba RNF-03',
    'solicitante_correo'   => CORREO_PRUEBA_TICKET,
    'solicitante_telefono' => '',
    'solicitante_tipo'     => 'externo',
    'asunto'               => 'Prueba automatizada RNF-03/HU-06',
    'descripcion'          => 'Descripción de prueba, sin csrf_token.',
    'aviso_aceptado'       => '1',
], $cookieTicket);
verificar(str_contains($htmlSinCsrfTicket, 'La sesión del formulario expiró'), 'POST del formulario público sin csrf_token es rechazado');

// ---------------------------------------------------------------------
// 10. Con aviso aceptado y datos válidos -> ticket creado en pendiente.
// ---------------------------------------------------------------------
$token = tokenDe(BASE . '/soporte.php', $cookieTicket);
[, $htmlValido] = peticion('POST', BASE . '/soporte.php', [
    'csrf_token'           => $token,
    'solicitante_nombre'   => 'Empresa Prueba RNF-03',
    'solicitante_correo'   => CORREO_PRUEBA_TICKET,
    'solicitante_telefono' => '',
    'solicitante_tipo'     => 'externo',
    'asunto'               => 'Prueba automatizada RNF-03/HU-06',
    'descripcion'          => 'Descripción de prueba, datos válidos.',
    'aviso_aceptado'       => '1',
], $cookieTicket);
verificar(str_contains($htmlValido, 'Solicitud recibida'), 'Con aviso aceptado y datos válidos, la solicitud se envía');

preg_match('/Tu número de ticket es <strong>#(\d+)/', $htmlValido, $m);
$idTicketPrueba = (int) ($m[1] ?? 0);
verificar($idTicketPrueba > 0, 'La respuesta trae el número del ticket creado');

$ticketCreado = obtenerTicket($idTicketPrueba);
verificar($ticketCreado !== null && $ticketCreado['estado'] === 'pendiente', 'El ticket queda guardado en estado pendiente');
verificar((int) $ticketCreado['aviso_aceptado'] === 1, 'El ticket queda con el aviso de datos marcado como aceptado');

// ---------------------------------------------------------------------
// Limpieza: borrar el ticket de prueba. da_app no tiene DELETE sobre
// 'ticket', así que se usa da_admin (sus credenciales viven solo en
// config/local.php, nunca en este archivo).
// ---------------------------------------------------------------------
$pdoAdmin = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_ADMIN_USER, DB_ADMIN_PASS);
$pdoAdmin->prepare('DELETE FROM ticket WHERE id = :id')->execute(['id' => $idTicketPrueba]);
verificar(obtenerTicket($idTicketPrueba) === null, 'El ticket de prueba se eliminó en la limpieza final');

@unlink($cookieAdmin);
@unlink($cookieJefe);
@unlink($cookieVendedor);
@unlink($cookieClaveMala);
@unlink($cookieSinSesion);
@unlink($cookieSinCsrf);
@unlink($cookieTicket);

echo PHP_EOL . ($fallas === 0 ? "TODAS LAS PRUEBAS PASARON" : "$fallas PRUEBA(S) FALLARON") . PHP_EOL;
exit($fallas === 0 ? 0 : 1);

<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-08: actualizar y cerrar tickets.
 *
 * Verifica, contra la aplicación real (requiere Apache + MySQL activos):
 *  - Avance válido registrado por el técnico asignado.
 *  - Un técnico NO asignado al ticket no puede registrar avances (403).
 *  - Cerrar un ticket sin avances previos se rechaza.
 *  - Cerrar con un avance previo sí funciona y queda la fecha de cierre.
 *  - Un avance sobre un ticket ya cerrado se rechaza (no se reabre).
 *  - El cliente consulta su propio ticket con número + correo, y NO el
 *    de otro (ni con el correo equivocado).
 *  - POST sin csrf_token rechazado.
 *
 * Usa los tickets de datos de prueba id=1 (Industrias Alfa, sin asignar
 * en la siembra) e id=8 (Empaques del Pacífico, también sin asignar), y
 * los técnicos id=6 (amora) e id=7 (spatino, usuario_id). Al terminar,
 * borra las asignaciones y avances de prueba (da_app no tiene DELETE
 * ahí; usa da_admin, igual que en HU-04/05/07) y deja ambos tickets de
 * nuevo en 'pendiente', sin asignar.
 *
 * Uso: php tests/test_hu08_actualizar_tickets.php
 */

require_once __DIR__ . '/../src/config/app.php';

const BASE = 'http://localhost/daservice';
const TICKET_UNO = 1;  // Industrias Alfa, correo soporte@industriasalfa.example
const TICKET_OCHO = 8; // Empaques del Pacífico
const TECNICO_SPATINO = 7;
const TECNICO_AMORA = 6;

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

$cookieCoordinador = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
$cookieSpatino     = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
$cookieAmora       = tempnam(sys_get_temp_dir(), 'daservice_cookies_');

iniciarSesion('jospina', $cookieCoordinador);
iniciarSesion('spatino', $cookieSpatino);
iniciarSesion('amora', $cookieAmora);

verificar(obtenerTicket(TICKET_UNO)['estado'] === 'pendiente', 'El ticket 1 empieza pendiente');
verificar(obtenerTicket(TICKET_OCHO)['estado'] === 'pendiente', 'El ticket 8 empieza pendiente');

// ---------------------------------------------------------------------
// Preparación: asignar ambos tickets a Spatino.
// ---------------------------------------------------------------------
asignarTicket(TICKET_UNO, TECNICO_SPATINO, 5);  // 5 = jospina, usuario_id de Coordinador
asignarTicket(TICKET_OCHO, TECNICO_SPATINO, 5);

function tokenVerTicket(int $id, string $cookieJar): string
{
    [, $html] = peticion('GET', BASE . '/tickets.php?ver=' . $id, [], $cookieJar);
    return extraerToken($html);
}

// ---------------------------------------------------------------------
// 1. Un técnico NO asignado (Amora) no puede registrar avances.
// ---------------------------------------------------------------------
$token = tokenVerTicket(TICKET_UNO, $cookieAmora); // Amora no tiene acceso a ?ver, pero el form igual puede forjarse
[$statusNoAsignado, $cuerpoNoAsignado] = peticion('POST', BASE . '/tickets.php', [
    'csrf_token'    => 'no-importa',
    'accion'        => 'avance',
    'ticket_id'     => TICKET_UNO,
    'estado_nuevo'  => 'en_proceso',
    'comentario'    => 'Intento no autorizado',
    'modalidad'     => 'remota',
], $cookieAmora);
verificar($statusNoAsignado === 403, 'Un técnico no asignado al ticket recibe 403 al intentar registrar un avance');
verificar(obtenerTicket(TICKET_UNO)['estado'] === 'pendiente', 'El ticket 1 sigue pendiente tras el intento no autorizado');

// ---------------------------------------------------------------------
// 2. Cerrar sin avances previos se rechaza (ticket 8).
// ---------------------------------------------------------------------
$token = tokenVerTicket(TICKET_OCHO, $cookieSpatino);
[, $cuerpoCierreSinAvance] = peticion('POST', BASE . '/tickets.php', [
    'csrf_token'   => $token,
    'accion'       => 'avance',
    'ticket_id'    => TICKET_OCHO,
    'estado_nuevo' => 'cerrado',
    'comentario'   => 'Intento de cierre directo',
    'modalidad'    => 'remota',
], $cookieSpatino);
verificar(
    str_contains($cuerpoCierreSinAvance, 'sin al menos un avance previo'),
    'Cerrar un ticket sin avances previos se rechaza'
);
verificar(obtenerTicket(TICKET_OCHO)['estado'] === 'pendiente', 'El ticket 8 sigue pendiente tras el intento de cierre sin avance');

// ---------------------------------------------------------------------
// 3. Avance válido por el técnico asignado (ticket 1).
// ---------------------------------------------------------------------
$token = tokenVerTicket(TICKET_UNO, $cookieSpatino);
[, $cuerpoAvanceValido] = peticion('POST', BASE . '/tickets.php', [
    'csrf_token'   => $token,
    'accion'       => 'avance',
    'ticket_id'    => TICKET_UNO,
    'estado_nuevo' => 'en_proceso',
    'comentario'   => 'Se revisó el equipo, pendiente de repuesto (prueba HU-08)',
    'modalidad'    => 'presencial',
], $cookieSpatino);
verificar(str_contains($cuerpoAvanceValido, 'Avance registrado'), 'El técnico asignado registra un avance válido');
verificar(obtenerTicket(TICKET_UNO)['estado'] === 'en_proceso', 'El ticket 1 pasa a en_proceso');
verificar(count(listarAvancesDeTicket(TICKET_UNO)) === 1, 'Queda un avance en el historial del ticket 1');

// ---------------------------------------------------------------------
// 3b. Un rol de gestión (Coordinador) también puede registrar avances,
//     aunque no sea el técnico asignado.
// ---------------------------------------------------------------------
$token = tokenVerTicket(TICKET_UNO, $cookieCoordinador);
[, $cuerpoAvanceGestion] = peticion('POST', BASE . '/tickets.php', [
    'csrf_token'   => $token,
    'accion'       => 'avance',
    'ticket_id'    => TICKET_UNO,
    'estado_nuevo' => 'en_proceso',
    'comentario'   => 'Seguimiento del coordinador (prueba HU-08)',
    'modalidad'    => 'remota',
], $cookieCoordinador);
verificar(str_contains($cuerpoAvanceGestion, 'Avance registrado'), 'Un rol de gestión (Coordinador) también puede registrar avances');
verificar(count(listarAvancesDeTicket(TICKET_UNO)) === 2, 'El historial del ticket 1 ahora tiene 2 avances');

// ---------------------------------------------------------------------
// 4. POST sin csrf_token se rechaza.
// ---------------------------------------------------------------------
[, $cuerpoSinCsrf] = peticion('POST', BASE . '/tickets.php', [
    'accion'       => 'avance',
    'ticket_id'    => TICKET_UNO,
    'estado_nuevo' => 'cerrado',
    'comentario'   => 'Intento sin token',
    'modalidad'    => 'remota',
], $cookieSpatino);
verificar(str_contains($cuerpoSinCsrf, 'La sesión del formulario expiró'), 'POST sin csrf_token es rechazado');
verificar(obtenerTicket(TICKET_UNO)['estado'] === 'en_proceso', 'El ticket 1 no cambió tras el POST sin CSRF');

// ---------------------------------------------------------------------
// 5. Cerrar CON un avance previo: ok, y con fecha de cierre.
// ---------------------------------------------------------------------
$token = tokenVerTicket(TICKET_OCHO, $cookieSpatino);
peticion('POST', BASE . '/tickets.php', [
    'csrf_token'   => $token,
    'accion'       => 'avance',
    'ticket_id'    => TICKET_OCHO,
    'estado_nuevo' => 'en_proceso',
    'comentario'   => 'Se revisó la red del cliente (prueba HU-08)',
    'modalidad'    => 'presencial',
], $cookieSpatino);

$tokenAntesDeCerrar = tokenVerTicket(TICKET_OCHO, $cookieSpatino);
[, $cuerpoCierreOk] = peticion('POST', BASE . '/tickets.php', [
    'csrf_token'   => $tokenAntesDeCerrar,
    'accion'       => 'avance',
    'ticket_id'    => TICKET_OCHO,
    'estado_nuevo' => 'cerrado',
    'comentario'   => 'Red reparada y verificada (prueba HU-08)',
    'modalidad'    => 'presencial',
], $cookieSpatino);
verificar(str_contains($cuerpoCierreOk, 'El ticket quedó cerrado'), 'Cerrar con un avance previo funciona');
verificar(obtenerTicket(TICKET_OCHO)['estado'] === 'cerrado', 'El ticket 8 queda cerrado');
verificar(obtenerFechaCierre(TICKET_OCHO) !== null, 'Queda registrada la fecha de cierre');

// ---------------------------------------------------------------------
// 6. Un avance sobre un ticket ya cerrado se rechaza (no se reabre).
// Una vez cerrado, la pantalla de detalle ya no muestra el formulario
// de avance (ni su token): se reutiliza el token de sesión, que sigue
// siendo válido, para forjar el intento igual que lo haría alguien
// insistiendo con el mismo formulario ya cargado en el navegador.
// ---------------------------------------------------------------------
[, $cuerpoSobreCerrado] = peticion('POST', BASE . '/tickets.php', [
    'csrf_token'   => $tokenAntesDeCerrar,
    'accion'       => 'avance',
    'ticket_id'    => TICKET_OCHO,
    'estado_nuevo' => 'en_proceso',
    'comentario'   => 'Intento sobre ticket cerrado',
    'modalidad'    => 'remota',
], $cookieSpatino);
verificar(
    str_contains($cuerpoSobreCerrado, 'ya está cerrado'),
    'Un avance sobre un ticket cerrado se rechaza'
);
verificar(obtenerTicket(TICKET_OCHO)['estado'] === 'cerrado', 'El ticket 8 sigue cerrado (no se reabrió)');
verificar(count(listarAvancesDeTicket(TICKET_OCHO)) === 2, 'El historial del ticket 8 sigue con solo los 2 avances válidos');

// ---------------------------------------------------------------------
// 7. El cliente consulta su propio ticket con número + correo.
// ---------------------------------------------------------------------
$ticketUno = obtenerTicket(TICKET_UNO);
$cookieCliente = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
[, $htmlConsultaOk] = peticion('POST', BASE . '/consultar_ticket.php', [
    'numero_ticket' => TICKET_UNO,
    'correo'        => $ticketUno['solicitante_correo'],
], $cookieCliente);
verificar(str_contains($htmlConsultaOk, 'En proceso'), 'El cliente consulta su propio ticket y ve el estado actualizado');

[, $htmlCorreoEquivocado] = peticion('POST', BASE . '/consultar_ticket.php', [
    'numero_ticket' => TICKET_UNO,
    'correo'        => 'otro.correo@no-coincide.example',
], $cookieCliente);
verificar(
    str_contains($htmlCorreoEquivocado, 'No se encontró ninguna solicitud'),
    'Con el correo equivocado, no se muestra el ticket de otro cliente'
);

$ticketOcho = obtenerTicket(TICKET_OCHO);
[, $htmlOtroTicketCorreoAjeno] = peticion('POST', BASE . '/consultar_ticket.php', [
    'numero_ticket' => TICKET_OCHO,
    'correo'        => $ticketUno['solicitante_correo'], // correo del ticket 1, no del 8
], $cookieCliente);
verificar(
    str_contains($htmlOtroTicketCorreoAjeno, 'No se encontró ninguna solicitud'),
    'Un cliente no puede ver el ticket de otro usando un correo que no le corresponde'
);
@unlink($cookieCliente);

// ---------------------------------------------------------------------
// 8. Sin enumeración: mismo mensaje exacto para un ticket que no existe
//    y para uno que existe pero con el correo equivocado.
// ---------------------------------------------------------------------
function extraerMensajeError(string $html): ?string
{
    preg_match('/<div class="msg err"[^>]*>(.*?)<\/div>/s', $html, $m);
    return isset($m[1]) ? trim($m[1]) : null;
}

$cookieEnumeracion = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
[, $htmlTicketInexistente] = peticion('POST', BASE . '/consultar_ticket.php', [
    'numero_ticket' => 999999,
    'correo'        => 'cualquiera@no-existe.example',
], $cookieEnumeracion);
$mensajeTicketInexistente = extraerMensajeError($htmlTicketInexistente);

[, $htmlCorreoEquivocado2] = peticion('POST', BASE . '/consultar_ticket.php', [
    'numero_ticket' => TICKET_UNO, // sí existe
    'correo'        => 'tampoco-coincide@no-existe.example',
], $cookieEnumeracion);
$mensajeCorreoEquivocado = extraerMensajeError($htmlCorreoEquivocado2);

verificar($mensajeTicketInexistente !== null, 'El ticket inexistente devuelve un mensaje de error');
verificar(
    $mensajeTicketInexistente === $mensajeCorreoEquivocado,
    'El mensaje es exactamente el mismo para un ticket inexistente y para uno con el correo equivocado (sin enumeración)'
);

// ---------------------------------------------------------------------
// 9. Límite de intentos fallidos por sesión (5 en 10 minutos).
// ---------------------------------------------------------------------
$cookieLimite = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
for ($i = 1; $i <= 5; $i++) {
    [, $htmlIntento] = peticion('POST', BASE . '/consultar_ticket.php', [
        'numero_ticket' => 999999,
        'correo'        => 'intento' . $i . '@no-existe.example',
    ], $cookieLimite);
    verificar(
        str_contains($htmlIntento, 'No se encontró ninguna solicitud'),
        "Intento fallido $i de 5 todavía se procesa normalmente"
    );
}

// El 6.º intento, aunque los datos sean correctos, queda bloqueado.
$ticketUnoActual = obtenerTicket(TICKET_UNO);
[, $htmlSextoIntento] = peticion('POST', BASE . '/consultar_ticket.php', [
    'numero_ticket' => TICKET_UNO,
    'correo'        => $ticketUnoActual['solicitante_correo'],
], $cookieLimite);
verificar(
    str_contains($htmlSextoIntento, 'demasiados intentos') || str_contains($htmlSextoIntento, 'Hiciste demasiados intentos'),
    'El 6.º intento en la misma sesión queda bloqueado, aunque esta vez los datos sean correctos'
);
verificar(!str_contains($htmlSextoIntento, 'class="msg ok"'), 'El bloqueo no muestra el detalle del ticket aunque los datos fueran correctos');

@unlink($cookieEnumeracion);
@unlink($cookieLimite);

// ---------------------------------------------------------------------
// Limpieza: borrar los avances y asignaciones de prueba, y devolver
// ambos tickets a 'pendiente'.
// ---------------------------------------------------------------------
$pdoAdmin = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_ADMIN_USER, DB_ADMIN_PASS);
$pdoAdmin->prepare('DELETE FROM actualizacion_ticket WHERE ticket_id IN (:uno, :ocho)')
    ->execute(['uno' => TICKET_UNO, 'ocho' => TICKET_OCHO]);
$pdoAdmin->prepare('DELETE FROM asignacion WHERE ticket_id IN (:uno, :ocho)')
    ->execute(['uno' => TICKET_UNO, 'ocho' => TICKET_OCHO]);

$pdo = obtenerConexion();
$pdo->prepare("UPDATE ticket SET estado = 'pendiente' WHERE id IN (" . TICKET_UNO . ',' . TICKET_OCHO . ')')->execute();

verificar(obtenerTicket(TICKET_UNO)['estado'] === 'pendiente', 'El ticket 1 queda otra vez pendiente, sin asignar, como al inicio');
verificar(obtenerTicket(TICKET_OCHO)['estado'] === 'pendiente', 'El ticket 8 queda otra vez pendiente, sin asignar, como al inicio');
verificar(count(listarAvancesDeTicket(TICKET_UNO)) === 0, 'No quedan avances de prueba en el ticket 1');
verificar(count(listarAvancesDeTicket(TICKET_OCHO)) === 0, 'No quedan avances de prueba en el ticket 8');

@unlink($cookieCoordinador);
@unlink($cookieSpatino);
@unlink($cookieAmora);

echo PHP_EOL . ($fallas === 0 ? "TODAS LAS PRUEBAS PASARON" : "$fallas PRUEBA(S) FALLARON") . PHP_EOL;
exit($fallas === 0 ? 0 : 1);

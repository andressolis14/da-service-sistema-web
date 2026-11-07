<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-09: calificación del servicio.
 *
 * Verifica, contra la aplicación real (requiere Apache + MySQL activos):
 *  - Calificar un ticket cerrado con datos válidos.
 *  - Un ticket no cerrado (pendiente/en_proceso) se rechaza.
 *  - Una segunda calificación sobre el mismo ticket se rechaza (con un
 *    ticket ya calificado en la siembra, y también de punta a punta
 *    sobre un ticket calificado por esta misma prueba).
 *  - Una calificación fuera de rango (1-5) se rechaza.
 *  - Un correo que no corresponde al ticket: mismo mensaje uniforme que
 *    un ticket inexistente (sin enumeración, comparado byte a byte).
 *  - Un comentario con HTML se muestra escapado en la pantalla interna
 *    de staff (tickets.php?ver=).
 *  - POST sin csrf_token rechazado.
 *
 * Usa el ticket de datos de prueba id=8 (Empaques del Pacífico,
 * pendiente en la siembra), al que este script cambia el estado a
 * 'cerrado' temporalmente (UPDATE directo) para poder calificarlo, y
 * el ticket id=3 (ya calificado en la siembra) para la prueba rápida
 * de "segunda calificación". Al terminar, borra la calificación de
 * prueba (da_app no tiene DELETE sobre 'calificacion'; usa da_admin,
 * igual que en HU-04/05/07/08/11) y deja el ticket 8 otra vez
 * 'pendiente', como al inicio.
 *
 * Uso: php tests/test_hu09_calificacion.php
 */

require_once __DIR__ . '/../src/config/app.php';

const BASE = 'http://localhost/daservice';
const TICKET_PENDIENTE = 1; // Industrias Alfa, sigue pendiente en la siembra
const TICKET_YA_CALIFICADO = 3; // Laura Gómez, correo laura.gomez@correo.example, ya calificado en la siembra
const TICKET_PRUEBA = 8; // Empaques del Pacífico, se cierra temporalmente para esta prueba

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

function extraerMensaje(string $html): ?string
{
    preg_match('/<div class="msg (?:ok|err)"[^>]*>\s*(.*?)\s*<\/div>/s', $html, $m);
    return isset($m[1]) ? trim($m[1]) : null;
}

function tokenCalificar(string $cookieJar): string
{
    [, $html] = peticion('GET', BASE . '/calificar_ticket.php', [], $cookieJar);
    return extraerToken($html);
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

$cookiePublico = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
$cookieEnumeracion = tempnam(sys_get_temp_dir(), 'daservice_cookies_');

$pdo = obtenerConexion();
$pdo->prepare("UPDATE ticket SET estado = 'cerrado' WHERE id = :id")->execute(['id' => TICKET_PRUEBA]);
$ticketPrueba = obtenerTicket(TICKET_PRUEBA);
$ticketPendiente = obtenerTicket(TICKET_PENDIENTE);
$ticketYaCalificado = obtenerTicket(TICKET_YA_CALIFICADO);

// ---------------------------------------------------------------------
// 1. Un ticket no cerrado se rechaza.
// ---------------------------------------------------------------------
$token = tokenCalificar($cookiePublico);
[, $htmlNoCerrado] = peticion('POST', BASE . '/calificar_ticket.php', [
    'csrf_token'     => $token,
    'numero_ticket'  => TICKET_PENDIENTE,
    'correo'         => $ticketPendiente['solicitante_correo'],
    'puntaje'        => 5,
    'comentario'     => '',
], $cookiePublico);
verificar(str_contains($htmlNoCerrado, 'Solo se puede calificar un ticket cerrado'), 'Un ticket no cerrado (pendiente) se rechaza');

// ---------------------------------------------------------------------
// 2. Segunda calificación (ticket ya calificado en la siembra).
// ---------------------------------------------------------------------
$token = tokenCalificar($cookiePublico);
[, $htmlYaCalificado] = peticion('POST', BASE . '/calificar_ticket.php', [
    'csrf_token'     => $token,
    'numero_ticket'  => TICKET_YA_CALIFICADO,
    'correo'         => $ticketYaCalificado['solicitante_correo'],
    'puntaje'        => 4,
    'comentario'     => '',
], $cookiePublico);
verificar(str_contains($htmlYaCalificado, 'Este ticket ya fue calificado'), 'Un ticket ya calificado (de la siembra) no se puede calificar de nuevo');

// ---------------------------------------------------------------------
// 3. Calificación fuera de rango.
// ---------------------------------------------------------------------
$token = tokenCalificar($cookiePublico);
[, $htmlFueraDeRango] = peticion('POST', BASE . '/calificar_ticket.php', [
    'csrf_token'     => $token,
    'numero_ticket'  => TICKET_PRUEBA,
    'correo'         => $ticketPrueba['solicitante_correo'],
    'puntaje'        => 6,
    'comentario'     => '',
], $cookiePublico);
verificar(str_contains($htmlFueraDeRango, 'debe estar entre 1 y 5'), 'Una calificación fuera de rango (6) se rechaza');
verificar(obtenerCalificacion(TICKET_PRUEBA) === null, 'El intento fuera de rango no creó ninguna calificación');

// ---------------------------------------------------------------------
// 4 y 5. Sin enumeración: mismo mensaje para correo equivocado y para
// ticket inexistente.
// ---------------------------------------------------------------------
$token = tokenCalificar($cookieEnumeracion);
[, $htmlCorreoEquivocado] = peticion('POST', BASE . '/calificar_ticket.php', [
    'csrf_token'     => $token,
    'numero_ticket'  => TICKET_PRUEBA,
    'correo'         => 'no-coincide@no-existe.example',
    'puntaje'        => 5,
    'comentario'     => '',
], $cookieEnumeracion);
$mensajeCorreoEquivocado = extraerMensaje($htmlCorreoEquivocado);

$token = tokenCalificar($cookieEnumeracion);
[, $htmlTicketInexistente] = peticion('POST', BASE . '/calificar_ticket.php', [
    'csrf_token'     => $token,
    'numero_ticket'  => 999999,
    'correo'         => 'cualquiera@no-existe.example',
    'puntaje'        => 5,
    'comentario'     => '',
], $cookieEnumeracion);
$mensajeTicketInexistente = extraerMensaje($htmlTicketInexistente);

verificar($mensajeCorreoEquivocado !== null, 'El correo equivocado devuelve un mensaje de error');
verificar(
    $mensajeCorreoEquivocado === $mensajeTicketInexistente,
    'El mensaje es exactamente el mismo para un correo equivocado y para un ticket inexistente (sin enumeración)'
);

// ---------------------------------------------------------------------
// 6. POST sin csrf_token se rechaza.
// ---------------------------------------------------------------------
[, $htmlSinCsrf] = peticion('POST', BASE . '/calificar_ticket.php', [
    'numero_ticket'  => TICKET_PRUEBA,
    'correo'         => $ticketPrueba['solicitante_correo'],
    'puntaje'        => 5,
    'comentario'     => '',
], $cookiePublico);
verificar(str_contains($htmlSinCsrf, 'La sesión del formulario expiró'), 'POST sin csrf_token es rechazado');
verificar(obtenerCalificacion(TICKET_PRUEBA) === null, 'El POST sin CSRF no creó ninguna calificación');

// ---------------------------------------------------------------------
// 7. Calificar un ticket cerrado con datos válidos (comentario con HTML,
//    para probar después que se muestra escapado).
// ---------------------------------------------------------------------
$comentarioConHtml = 'Excelente <b>atención</b> <script>alert(1)</script>';
$token = tokenCalificar($cookiePublico);
[, $htmlOk] = peticion('POST', BASE . '/calificar_ticket.php', [
    'csrf_token'     => $token,
    'numero_ticket'  => TICKET_PRUEBA,
    'correo'         => $ticketPrueba['solicitante_correo'],
    'puntaje'        => 5,
    'comentario'     => $comentarioConHtml,
], $cookiePublico);
verificar(str_contains($htmlOk, '¡Gracias por tu calificación!'), 'Calificar un ticket cerrado con datos válidos funciona');

$calificacionCreada = obtenerCalificacion(TICKET_PRUEBA);
verificar($calificacionCreada !== null && (int) $calificacionCreada['puntaje'] === 5, 'La calificación queda guardada con el puntaje correcto');
verificar($calificacionCreada['comentario'] === $comentarioConHtml, 'El comentario se guarda tal cual en la base de datos (el escapado es solo al mostrarlo)');

// ---------------------------------------------------------------------
// 8. Una segunda calificación sobre el mismo ticket (esta vez creado
//    por la propia prueba, de punta a punta) también se rechaza.
// ---------------------------------------------------------------------
$token = tokenCalificar($cookiePublico);
[, $htmlSegundaVez] = peticion('POST', BASE . '/calificar_ticket.php', [
    'csrf_token'     => $token,
    'numero_ticket'  => TICKET_PRUEBA,
    'correo'         => $ticketPrueba['solicitante_correo'],
    'puntaje'        => 3,
    'comentario'     => 'Intento de recalificar',
], $cookiePublico);
verificar(str_contains($htmlSegundaVez, 'Este ticket ya fue calificado'), 'Una segunda calificación sobre el mismo ticket se rechaza');
verificar((int) obtenerCalificacion(TICKET_PRUEBA)['puntaje'] === 5, 'El puntaje original (5) no cambió con el intento de recalificar');

// ---------------------------------------------------------------------
// 9. El comentario con HTML se muestra escapado en la pantalla interna
//    de staff.
// ---------------------------------------------------------------------
$cookieStaff = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
iniciarSesion('jospina', $cookieStaff); // Coordinador
[, $htmlDetalleStaff] = peticion('GET', BASE . '/tickets.php?ver=' . TICKET_PRUEBA, [], $cookieStaff);
verificar(
    str_contains($htmlDetalleStaff, 'Excelente &lt;b&gt;atención&lt;/b&gt; &lt;script&gt;alert(1)&lt;/script&gt;'),
    'El comentario con HTML se muestra escapado en la pantalla interna de staff'
);
verificar(!str_contains($htmlDetalleStaff, '<script>alert(1)</script>'), 'El comentario NO aparece como HTML/script ejecutable');
@unlink($cookieStaff);

// ---------------------------------------------------------------------
// Limpieza: borrar la calificación de prueba y devolver el ticket 8 a
// 'pendiente' (su estado original en la siembra).
// ---------------------------------------------------------------------
$pdoAdmin = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_ADMIN_USER, DB_ADMIN_PASS);
$pdoAdmin->prepare('DELETE FROM calificacion WHERE ticket_id = :id')->execute(['id' => TICKET_PRUEBA]);
$pdo->prepare("UPDATE ticket SET estado = 'pendiente' WHERE id = :id")->execute(['id' => TICKET_PRUEBA]);

verificar(obtenerCalificacion(TICKET_PRUEBA) === null, 'La calificación de prueba se eliminó');
verificar(obtenerTicket(TICKET_PRUEBA)['estado'] === 'pendiente', 'El ticket 8 vuelve a quedar pendiente, como al inicio');

@unlink($cookiePublico);
@unlink($cookieEnumeracion);

echo PHP_EOL . ($fallas === 0 ? "TODAS LAS PRUEBAS PASARON" : "$fallas PRUEBA(S) FALLARON") . PHP_EOL;
exit($fallas === 0 ? 0 : 1);

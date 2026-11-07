<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-07: asignación de tickets a técnicos.
 *
 * Verifica, contra la aplicación real (requiere Apache + MySQL activos):
 *  - Un rol no autorizado (Vendedor) no puede ver ni asignar (403).
 *  - Asignar a alguien que no es técnico se rechaza.
 *  - Asignar a un técnico inactivo se rechaza.
 *  - Asignación válida: el ticket pasa a 'asignado' (estado visual; en
 *    la BD sigue 'pendiente' hasta el primer avance, HU-08).
 *  - El técnico asignado ve el ticket en su listado.
 *  - Otro técnico no lo ve en el suyo.
 *  - La reasignación crea una fila nueva en 'asignacion' (historial:
 *    quién, cuándo, a quién), no sobrescribe la anterior.
 *  - POST sin csrf_token se rechaza.
 *
 * Usa el ticket de datos de prueba id=1 (sin asignar en la siembra) y
 * los técnicos id=6 (amora) e id=7 (spatino). Al terminar, borra las
 * asignaciones de prueba del ticket 1 (da_app no tiene DELETE sobre
 * 'asignacion', así que usa da_admin, igual que en HU-04) y dejó a
 * amora activa de nuevo si la prueba la desactivó temporalmente.
 *
 * Uso: php tests/test_hu07_asignar_tickets.php
 */

require_once __DIR__ . '/../src/config/app.php';

const BASE = 'http://localhost/daservice';
const TICKET_SIN_ASIGNAR = 1;
const TECNICO_AMORA = 6;   // usuario_id
const TECNICO_SPATINO = 7; // usuario_id
const VENDEDOR_JPEREZ = 8; // usuario_id, no es técnico

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

function tokenTickets(string $cookieJar): string
{
    [, $html] = peticion('GET', BASE . '/tickets.php', [], $cookieJar);
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

function tecnicoActualDe(int $ticketId): ?int
{
    $t = obtenerTicket($ticketId);
    $fila = array_values(array_filter(listarTickets(), fn($x) => (int) $x['id'] === $ticketId))[0] ?? null;
    return $fila && $fila['tecnico_actual_id'] !== null ? (int) $fila['tecnico_actual_id'] : null;
}

$cookieCoordinador = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
$cookieVendedor    = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
$cookieAmora       = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
$cookieSpatino     = tempnam(sys_get_temp_dir(), 'daservice_cookies_');

iniciarSesion('jospina', $cookieCoordinador); // Coordinador
iniciarSesion('jperez', $cookieVendedor);     // Vendedor, no autorizado
iniciarSesion('amora', $cookieAmora);         // Técnica
iniciarSesion('spatino', $cookieSpatino);     // Técnico

verificar(tecnicoActualDe(TICKET_SIN_ASIGNAR) === null, 'El ticket de prueba empieza sin técnico asignado');

// ---------------------------------------------------------------------
// 1. Rol no autorizado (Vendedor): ni ve ni puede asignar.
// ---------------------------------------------------------------------
[$statusVerVendedor] = peticion('GET', BASE . '/tickets.php', [], $cookieVendedor);
verificar($statusVerVendedor === 403, 'Un rol no autorizado (Vendedor) recibe 403 al entrar a tickets.php');

// ---------------------------------------------------------------------
// 2. Asignar a alguien que no es técnico: se rechaza.
// ---------------------------------------------------------------------
$token = tokenTickets($cookieCoordinador);
[, $htmlNoTecnico] = peticion('POST', BASE . '/tickets.php', [
    'csrf_token' => $token,
    'accion'     => 'asignar',
    'ticket_id'  => TICKET_SIN_ASIGNAR,
    'tecnico_id' => VENDEDOR_JPEREZ,
], $cookieCoordinador);
verificar(str_contains($htmlNoTecnico, 'Solo se puede asignar a un técnico activo'), 'Asignar a alguien que no es técnico se rechaza');
verificar(tecnicoActualDe(TICKET_SIN_ASIGNAR) === null, 'El ticket sigue sin técnico tras el intento inválido');

// ---------------------------------------------------------------------
// 3. Asignar a un técnico inactivo: se rechaza. Se desactiva a amora
//    temporalmente (usuario.activo=0) y se restaura después.
// ---------------------------------------------------------------------
$pdo = obtenerConexion();
$pdo->prepare('UPDATE usuario SET activo = 0 WHERE id = :id')->execute(['id' => TECNICO_AMORA]);

$token = tokenTickets($cookieCoordinador);
[, $htmlTecnicoInactivo] = peticion('POST', BASE . '/tickets.php', [
    'csrf_token' => $token,
    'accion'     => 'asignar',
    'ticket_id'  => TICKET_SIN_ASIGNAR,
    'tecnico_id' => TECNICO_AMORA,
], $cookieCoordinador);
verificar(str_contains($htmlTecnicoInactivo, 'Solo se puede asignar a un técnico activo'), 'Asignar a un técnico inactivo se rechaza');
verificar(tecnicoActualDe(TICKET_SIN_ASIGNAR) === null, 'El ticket sigue sin técnico tras el intento con técnico inactivo');

$pdo->prepare('UPDATE usuario SET activo = 1 WHERE id = :id')->execute(['id' => TECNICO_AMORA]);

// ---------------------------------------------------------------------
// 4. POST sin csrf_token: se rechaza.
// ---------------------------------------------------------------------
[, $htmlSinCsrf] = peticion('POST', BASE . '/tickets.php', [
    'accion'     => 'asignar',
    'ticket_id'  => TICKET_SIN_ASIGNAR,
    'tecnico_id' => TECNICO_SPATINO,
], $cookieCoordinador);
verificar(str_contains($htmlSinCsrf, 'La sesión del formulario expiró'), 'POST sin csrf_token es rechazado');
verificar(tecnicoActualDe(TICKET_SIN_ASIGNAR) === null, 'El ticket sigue sin técnico tras el POST sin CSRF');

// ---------------------------------------------------------------------
// 5. Asignación válida a un técnico activo.
// ---------------------------------------------------------------------
$token = tokenTickets($cookieCoordinador);
[, $htmlOk] = peticion('POST', BASE . '/tickets.php', [
    'csrf_token' => $token,
    'accion'     => 'asignar',
    'ticket_id'  => TICKET_SIN_ASIGNAR,
    'tecnico_id' => TECNICO_SPATINO,
], $cookieCoordinador);
verificar(str_contains($htmlOk, 'Ticket asignado correctamente'), 'Asignación válida a un técnico activo se confirma');
verificar(tecnicoActualDe(TICKET_SIN_ASIGNAR) === TECNICO_SPATINO, 'El técnico actual del ticket es ahora Spatino');

$ticketActualizado = obtenerTicket(TICKET_SIN_ASIGNAR);
verificar($ticketActualizado['estado'] === 'pendiente', "El estado en la BD sigue 'pendiente' (el esquema no tiene 'asignado')");
$filaListado = array_values(array_filter(listarTickets(), fn($x) => (int) $x['id'] === TICKET_SIN_ASIGNAR))[0];
verificar(estadoVisualTicket($filaListado) === 'asignado', "El estado VISUAL sí muestra 'asignado'");

// ---------------------------------------------------------------------
// 6. El técnico asignado lo ve en su listado; otro técnico no.
// ---------------------------------------------------------------------
[, $htmlSpatino] = peticion('GET', BASE . '/tickets.php', [], $cookieSpatino);
verificar(str_contains($htmlSpatino, 'La impresora de planta no imprime'), 'Spatino (el técnico asignado) ve el ticket en su listado');

[, $htmlAmora] = peticion('GET', BASE . '/tickets.php', [], $cookieAmora);
verificar(!str_contains($htmlAmora, 'La impresora de planta no imprime'), 'Amora (otro técnico) NO ve ese ticket en el suyo');

// ---------------------------------------------------------------------
// 7. Reasignar: crea una fila nueva en 'asignacion' (historial), no
//    sobrescribe la anterior.
// ---------------------------------------------------------------------
$conteoAntes = (int) $pdo->query('SELECT COUNT(*) FROM asignacion WHERE ticket_id = ' . TICKET_SIN_ASIGNAR)->fetchColumn();

$token = tokenTickets($cookieCoordinador);
peticion('POST', BASE . '/tickets.php', [
    'csrf_token' => $token,
    'accion'     => 'asignar',
    'ticket_id'  => TICKET_SIN_ASIGNAR,
    'tecnico_id' => TECNICO_AMORA,
], $cookieCoordinador);

$conteoDespues = (int) $pdo->query('SELECT COUNT(*) FROM asignacion WHERE ticket_id = ' . TICKET_SIN_ASIGNAR)->fetchColumn();
verificar($conteoDespues === $conteoAntes + 1, 'La reasignación agrega una fila nueva (no reemplaza la anterior)');
verificar(tecnicoActualDe(TICKET_SIN_ASIGNAR) === TECNICO_AMORA, 'El técnico actual ahora es Amora');

$filasHistorial = $pdo->prepare('SELECT tecnico_id FROM asignacion WHERE ticket_id = :id ORDER BY id ASC');
$filasHistorial->execute(['id' => TICKET_SIN_ASIGNAR]);
$tecnicosHistorial = array_map('intval', $filasHistorial->fetchAll(PDO::FETCH_COLUMN));
verificar($tecnicosHistorial === [TECNICO_SPATINO, TECNICO_AMORA], 'El historial conserva ambas asignaciones en orden (Spatino, luego Amora)');

// ---------------------------------------------------------------------
// Limpieza: borrar las asignaciones de prueba del ticket 1. da_app no
// tiene DELETE sobre 'asignacion' (solo sobre 'desprendible').
// ---------------------------------------------------------------------
$pdoAdmin = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_ADMIN_USER, DB_ADMIN_PASS);
$pdoAdmin->prepare('DELETE FROM asignacion WHERE ticket_id = :id')->execute(['id' => TICKET_SIN_ASIGNAR]);
verificar(tecnicoActualDe(TICKET_SIN_ASIGNAR) === null, 'El ticket de prueba queda sin asignar otra vez, como al inicio');

@unlink($cookieCoordinador);
@unlink($cookieVendedor);
@unlink($cookieAmora);
@unlink($cookieSpatino);

echo PHP_EOL . ($fallas === 0 ? "TODAS LAS PRUEBAS PASARON" : "$fallas PRUEBA(S) FALLARON") . PHP_EOL;
exit($fallas === 0 ? 0 : 1);

<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-16: seguimiento de proyectos en ejecución.
 *
 * Verifica, contra la aplicación real (requiere Apache + MySQL activos):
 *  - Crear desde una oportunidad aprobada.
 *  - Crear desde una no aprobada se rechaza.
 *  - Un segundo proyecto para la misma oportunidad se rechaza.
 *  - Responsable inexistente o inactivo se rechaza.
 *  - Fin antes de inicio se rechaza.
 *  - Atraso correcto en tres casos (sin atraso, con pendiente vencido
 *    sin completar, con pendientes completados).
 *  - Terminar pasa la oportunidad a ganado.
 *  - Terminar una oportunidad que no está en aprobada se rechaza.
 *  - Un tercero no relacionado con el proyecto no puede actualizarlo.
 *  - Rol no autorizado (Técnico) recibe 403.
 *  - POST sin csrf_token rechazado.
 *
 * Crea una oportunidad de prueba temporal (cliente id=1, vendedor
 * jperez) y la lleva a 'aprobada' para poder crear un proyecto. Al
 * terminar, borra el pendiente, el proyecto y la oportunidad de
 * prueba (da_app no tiene DELETE sobre esas tablas; usa da_admin,
 * igual que en HU-04/05/07/08/09/11/12/13).
 *
 * Uso: php tests/test_hu16_proyectos.php
 */

require_once __DIR__ . '/../src/config/app.php';

const BASE = 'http://localhost/daservice';
const CLIENTE_PRUEBA = 1;
const VENDEDOR_RESPONSABLE = 8; // jperez, usuario_id
const VENDEDOR_AJENO = 9;       // prios, usuario_id (tercero no relacionado)
const GERENTE_INACTIVO_TEMPORAL = 1; // cvelez, se desactiva temporalmente para una prueba (gerente, para aislar "inactivo" del rechazo por rol)

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

function tokenProyectos(string $cookieJar, string $queryExtra = ''): string
{
    [, $html] = peticion('GET', BASE . '/proyectos.php' . $queryExtra, [], $cookieJar);
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
$cookieTecnico = tempnam(sys_get_temp_dir(), 'daservice_cookies_');

iniciarSesion('jperez', $cookieJperez);
iniciarSesion('prios', $cookiePrios);
iniciarSesion('amora', $cookieTecnico);

// ---------------------------------------------------------------------
// Preparación: dos oportunidades de prueba, una llevada a 'aprobada'.
// ---------------------------------------------------------------------
crearOportunidad(CLIENTE_PRUEBA, VENDEDOR_RESPONSABLE, 'Prueba HU-16: proyecto de prueba', '5000000', 'COP');
crearOportunidad(CLIENTE_PRUEBA, VENDEDOR_RESPONSABLE, 'Prueba HU-16: oportunidad no aprobada', '2000000', 'COP');
$todas = listarOportunidades();
$oportunidadAprobar = array_values(array_filter($todas, fn($o) => $o['descripcion'] === 'Prueba HU-16: proyecto de prueba'))[0];
$oportunidadNoAprobada = array_values(array_filter($todas, fn($o) => $o['descripcion'] === 'Prueba HU-16: oportunidad no aprobada'))[0];
cambiarEtapaOportunidad((int) $oportunidadAprobar['id'], 'aprobada', '');

// ---------------------------------------------------------------------
// 1. Rol no autorizado (Técnico): 403.
// ---------------------------------------------------------------------
[$statusTecnico] = peticion('GET', BASE . '/proyectos.php', [], $cookieTecnico);
verificar($statusTecnico === 403, 'Un rol no autorizado (Técnico) recibe 403 en proyectos.php');

// ---------------------------------------------------------------------
// 2. Crear desde una oportunidad NO aprobada se rechaza.
// ---------------------------------------------------------------------
$token = tokenProyectos($cookieJperez);
[, $htmlNoAprobada] = peticion('POST', BASE . '/proyectos.php', [
    'csrf_token' => $token, 'accion' => 'crear',
    'oportunidad_id' => $oportunidadNoAprobada['id'], 'responsable_id' => VENDEDOR_RESPONSABLE,
    'fecha_inicio' => '2026-11-01', 'fecha_compromiso' => '2026-12-01',
], $cookieJperez);
verificar(str_contains($htmlNoAprobada, 'etapa aprobada'), 'Crear desde una oportunidad no aprobada se rechaza');

// ---------------------------------------------------------------------
// 3. Responsable inexistente se rechaza.
// ---------------------------------------------------------------------
$token = tokenProyectos($cookieJperez);
[, $htmlResponsableInexistente] = peticion('POST', BASE . '/proyectos.php', [
    'csrf_token' => $token, 'accion' => 'crear',
    'oportunidad_id' => $oportunidadAprobar['id'], 'responsable_id' => 999999,
    'fecha_inicio' => '2026-11-01', 'fecha_compromiso' => '2026-12-01',
], $cookieJperez);
verificar(str_contains($htmlResponsableInexistente, 'empleado activo'), 'Un responsable inexistente se rechaza');

// ---------------------------------------------------------------------
// 4. Responsable inactivo se rechaza (se desactiva un técnico temporalmente).
// ---------------------------------------------------------------------
$pdo = obtenerConexion();
$pdo->prepare('UPDATE usuario SET activo = 0 WHERE id = :id')->execute(['id' => GERENTE_INACTIVO_TEMPORAL]);
$token = tokenProyectos($cookieJperez);
[, $htmlResponsableInactivo] = peticion('POST', BASE . '/proyectos.php', [
    'csrf_token' => $token, 'accion' => 'crear',
    'oportunidad_id' => $oportunidadAprobar['id'], 'responsable_id' => GERENTE_INACTIVO_TEMPORAL,
    'fecha_inicio' => '2026-11-01', 'fecha_compromiso' => '2026-12-01',
], $cookieJperez);
verificar(str_contains($htmlResponsableInactivo, 'empleado activo'), 'Un responsable inactivo se rechaza');
$pdo->prepare('UPDATE usuario SET activo = 1 WHERE id = :id')->execute(['id' => GERENTE_INACTIVO_TEMPORAL]);

// ---------------------------------------------------------------------
// 5. Fin antes de inicio se rechaza.
// ---------------------------------------------------------------------
$token = tokenProyectos($cookieJperez);
[, $htmlFinAntesDeInicio] = peticion('POST', BASE . '/proyectos.php', [
    'csrf_token' => $token, 'accion' => 'crear',
    'oportunidad_id' => $oportunidadAprobar['id'], 'responsable_id' => VENDEDOR_RESPONSABLE,
    'fecha_inicio' => '2026-12-01', 'fecha_compromiso' => '2026-11-01',
], $cookieJperez);
verificar(str_contains($htmlFinAntesDeInicio, 'no puede ser anterior a la fecha de inicio'), 'La entrega comprometida antes del inicio se rechaza');

verificar(obtenerProyectoDeOportunidad((int) $oportunidadAprobar['id']) === null, 'Ninguno de los intentos inválidos creó un proyecto');

// ---------------------------------------------------------------------
// 6. POST sin csrf_token se rechaza.
// ---------------------------------------------------------------------
[, $htmlSinCsrf] = peticion('POST', BASE . '/proyectos.php', [
    'accion' => 'crear',
    'oportunidad_id' => $oportunidadAprobar['id'], 'responsable_id' => VENDEDOR_RESPONSABLE,
    'fecha_inicio' => '2026-11-01', 'fecha_compromiso' => '2026-12-01',
], $cookieJperez);
verificar(str_contains($htmlSinCsrf, 'La sesión del formulario expiró'), 'POST sin csrf_token es rechazado');

// ---------------------------------------------------------------------
// 7. Crear desde una oportunidad aprobada funciona.
// ---------------------------------------------------------------------
$token = tokenProyectos($cookieJperez);
[, $htmlCrearOk] = peticion('POST', BASE . '/proyectos.php', [
    'csrf_token' => $token, 'accion' => 'crear',
    'oportunidad_id' => $oportunidadAprobar['id'], 'responsable_id' => VENDEDOR_RESPONSABLE,
    'fecha_inicio' => '2026-11-01', 'fecha_compromiso' => '2026-12-01',
], $cookieJperez);
verificar(str_contains($htmlCrearOk, 'Proyecto creado'), 'Crear desde una oportunidad aprobada funciona');

$proyectoFila = obtenerProyectoDeOportunidad((int) $oportunidadAprobar['id']);
verificar($proyectoFila !== null, 'El proyecto quedó guardado');
$proyectoId = (int) $proyectoFila['id'];

// ---------------------------------------------------------------------
// 8. Un segundo proyecto para la misma oportunidad se rechaza. La
// oportunidad ya no aparece en el desplegable (ya tiene proyecto), así
// que el formulario de creación (y su token) ya no está en la página;
// se reutiliza el token válido obtenido antes de crear el primero.
// ---------------------------------------------------------------------
[, $htmlSegundoProyecto] = peticion('POST', BASE . '/proyectos.php', [
    'csrf_token' => $token, 'accion' => 'crear',
    'oportunidad_id' => $oportunidadAprobar['id'], 'responsable_id' => VENDEDOR_RESPONSABLE,
    'fecha_inicio' => '2026-11-01', 'fecha_compromiso' => '2026-12-01',
], $cookieJperez);
verificar(str_contains($htmlSegundoProyecto, 'ya tiene un proyecto'), 'Un segundo proyecto para la misma oportunidad se rechaza');

// ---------------------------------------------------------------------
// 9. Un tercero no relacionado (Prios) no puede actualizar el proyecto
//    (ni agregar pendientes ni terminarlo).
// ---------------------------------------------------------------------
$token = tokenProyectos($cookiePrios, '?ver=' . $proyectoId);
[$statusTercero] = peticion('POST', BASE . '/proyectos.php', [
    'csrf_token' => $token, 'accion' => 'pendiente', 'proyecto_id' => $proyectoId,
    'descripcion' => 'Intento de un tercero', 'fecha_compromiso_pendiente' => '2026-12-15',
], $cookiePrios);
verificar($statusTercero === 403, 'Un vendedor que no es el responsable del proyecto recibe 403 al intentar actualizarlo');

// ---------------------------------------------------------------------
// 10. Atraso: sin atraso (pendiente con fecha futura).
// ---------------------------------------------------------------------
$token = tokenProyectos($cookieJperez, '?ver=' . $proyectoId);
peticion('POST', BASE . '/proyectos.php', [
    'csrf_token' => $token, 'accion' => 'pendiente', 'proyecto_id' => $proyectoId,
    'descripcion' => 'Pendiente futuro (prueba HU-16)', 'fecha_compromiso_pendiente' => '2026-12-20',
], $cookieJperez);
verificar(obtenerProyecto($proyectoId)['atrasado'] == 0, 'Sin pendientes vencidos, el proyecto no está atrasado');

// ---------------------------------------------------------------------
// 11. Atraso: con pendiente vencido sin completar.
// ---------------------------------------------------------------------
$token = tokenProyectos($cookieJperez, '?ver=' . $proyectoId);
peticion('POST', BASE . '/proyectos.php', [
    'csrf_token' => $token, 'accion' => 'pendiente', 'proyecto_id' => $proyectoId,
    'descripcion' => 'Pendiente vencido (prueba HU-16)', 'fecha_compromiso_pendiente' => '2026-01-01',
], $cookieJperez);
verificar(obtenerProyecto($proyectoId)['atrasado'] == 1, 'Con un pendiente vencido sin completar, el proyecto está atrasado');

// ---------------------------------------------------------------------
// 12. Atraso: ya no atrasado al completar el pendiente vencido.
// ---------------------------------------------------------------------
$pendientes = listarPendientesDeProyecto($proyectoId);
$pendienteVencido = array_values(array_filter($pendientes, fn($p) => $p['descripcion'] === 'Pendiente vencido (prueba HU-16)'))[0];
$token = tokenProyectos($cookieJperez, '?ver=' . $proyectoId);
peticion('POST', BASE . '/proyectos.php', [
    'csrf_token' => $token, 'accion' => 'completar_pendiente', 'proyecto_id' => $proyectoId,
    'pendiente_id' => $pendienteVencido['id'],
], $cookieJperez);
verificar(obtenerProyecto($proyectoId)['atrasado'] == 0, 'Al completar el pendiente vencido, el proyecto ya no está atrasado');

// ---------------------------------------------------------------------
// 13. Terminar el proyecto pasa la oportunidad a ganado.
// ---------------------------------------------------------------------
$token = tokenProyectos($cookieJperez, '?ver=' . $proyectoId);
[, $htmlTerminar] = peticion('POST', BASE . '/proyectos.php', [
    'csrf_token' => $token, 'accion' => 'terminar', 'proyecto_id' => $proyectoId,
], $cookieJperez);
verificar(str_contains($htmlTerminar, 'pasó a ganado'), 'Terminar el proyecto confirma el cambio');
verificar(obtenerOportunidad((int) $oportunidadAprobar['id'])['etapa'] === 'ganado', 'La oportunidad quedó en etapa ganado');

// ---------------------------------------------------------------------
// 14. Terminar un proyecto cuya oportunidad ya no está en aprobada se rechaza.
// ---------------------------------------------------------------------
$token = tokenProyectos($cookieJperez, '?ver=' . $proyectoId);
[, $htmlTerminarDeNuevo] = peticion('POST', BASE . '/proyectos.php', [
    'csrf_token' => $token, 'accion' => 'terminar', 'proyecto_id' => $proyectoId,
], $cookieJperez);
verificar(str_contains($htmlTerminarDeNuevo, 'etapa aprobada'), 'Terminar de nuevo un proyecto ya terminado se rechaza');

// ---------------------------------------------------------------------
// Limpieza: borrar los pendientes, el proyecto y las dos oportunidades
// de prueba.
// ---------------------------------------------------------------------
$pdoAdmin = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_ADMIN_USER, DB_ADMIN_PASS);
$pdoAdmin->prepare('DELETE FROM pendiente_proyecto WHERE proyecto_id = :id')->execute(['id' => $proyectoId]);
$pdoAdmin->prepare('DELETE FROM proyecto WHERE id = :id')->execute(['id' => $proyectoId]);
$pdoAdmin->prepare('DELETE FROM oportunidad WHERE id IN (:a, :b)')->execute([
    'a' => $oportunidadAprobar['id'],
    'b' => $oportunidadNoAprobada['id'],
]);

verificar(obtenerProyecto($proyectoId) === null, 'El proyecto de prueba se eliminó');
verificar(obtenerOportunidad((int) $oportunidadAprobar['id']) === null, 'Las oportunidades de prueba se eliminaron');

@unlink($cookieJperez);
@unlink($cookiePrios);
@unlink($cookieTecnico);

echo PHP_EOL . ($fallas === 0 ? "TODAS LAS PRUEBAS PASARON" : "$fallas PRUEBA(S) FALLARON") . PHP_EOL;
exit($fallas === 0 ? 0 : 1);

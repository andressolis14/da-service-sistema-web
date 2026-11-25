<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-04: solicitud y aprobación de permisos.
 *
 * Verifica, contra la aplicación real (requiere Apache + MySQL activos):
 *  - Una solicitud válida queda 'pendiente'.
 *  - Fechas inválidas (fin antes de inicio) se rechazan.
 *  - El motivo es obligatorio.
 *  - El jefe directo real del solicitante puede aprobar.
 *  - Alguien que NO es su jefe directo (aunque intente forjar el POST
 *    directamente) no puede resolverla: se verifica el jefe_id real, no
 *    un rol.
 *  - Una solicitud ya resuelta no se puede volver a resolver.
 *  - Un empleado no ve las solicitudes de otro en su propia lista.
 *  - POST sin csrf_token se rechaza.
 *  - Advertencia de fechas cruzadas (Tabla 41): sin cruce no hay aviso;
 *    con cruce parcial, total o de un solo día sí lo hay; un permiso
 *    rechazado no cuenta para el cruce. El sistema advierte pero no
 *    bloquea el envío (el jefe decide al revisar).
 *
 * Usa el empleado de datos de prueba id=10 (Laura Gómez, usuario lgomez),
 * cuyo jefe directo real es el empleado id=4 (Marta Lucía Díaz, usuario
 * mdiaz). El permiso de prueba que crea este script se borra al terminar
 * (da_app no tiene DELETE sobre 'permiso', así que la limpieza usa
 * da_admin directamente, fuera de la aplicación, igual que en otras
 * tareas de mantenimiento de datos de prueba de este proyecto).
 *
 * Uso: php tests/test_hu04_permisos.php
 */

require_once __DIR__ . '/../src/config/app.php';

const BASE = 'http://localhost/daservice';
const EMPLEADO_LAURA = 10; // solicitante
const EMPLEADO_MARTA = 4;  // jefe directo real de Laura
const MOTIVO_PRUEBA = 'Cita médica de control (prueba HU-04)';

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

function tokenPermisos(string $cookieJar): string
{
    [, $html] = peticion('GET', BASE . '/permisos.php', [], $cookieJar);
    return extraerToken($html);
}

/** fecha_solicitud es DATETIME con resolución de segundos: ante inserciones
 *  rápidas y seguidas, varias filas pueden empatar ahí, así que para
 *  identificar "la última creada" se usa el id (autoincremental), no el
 *  orden de listarPermisosDeEmpleado(). */
function ultimoIdPermisoDe(int $empleadoId): int
{
    $stmt = obtenerConexion()->prepare('SELECT MAX(id) FROM permiso WHERE empleado_id = :emp');
    $stmt->execute(['emp' => $empleadoId]);
    return (int) $stmt->fetchColumn();
}

function verificar(bool $condicion, string $descripcion): void
{
    global $fallas;
    echo ($condicion ? "[OK] " : "[FALLA] ") . $descripcion . PHP_EOL;
    if (!$condicion) {
        $fallas++;
    }
}

$cookieLaura = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
$cookieMarta = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
$cookieAjeno = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
$cookieOtroEmpleado = tempnam(sys_get_temp_dir(), 'daservice_cookies_');

iniciarSesion('lgomez', $cookieLaura);          // Laura, solicitante (empleado 10)
iniciarSesion('mdiaz', $cookieMarta);           // Marta, su jefe directo real (empleado 4)
iniciarSesion('jperez', $cookieAjeno);          // Vendedor, no es jefe de nadie
iniciarSesion('cruiz', $cookieOtroEmpleado);    // Carlos, otro empleado (no debe ver la solicitud de Laura)

$totalInicial = count(listarPermisosDeEmpleado(EMPLEADO_LAURA));

// ---------------------------------------------------------------------
// 1. Fechas inválidas (fin antes de inicio): se rechaza.
// ---------------------------------------------------------------------
$token = tokenPermisos($cookieLaura);
[, $htmlFechaInvalida] = peticion('POST', BASE . '/permisos.php', [
    'csrf_token'   => $token,
    'accion'       => 'solicitar',
    'fecha_inicio' => '2026-12-10',
    'fecha_fin'    => '2026-12-05',
    'motivo'       => MOTIVO_PRUEBA,
], $cookieLaura);
verificar(
    str_contains($htmlFechaInvalida, 'no puede ser anterior a la fecha de inicio'),
    'Fecha de fin anterior a la de inicio se rechaza'
);
verificar(count(listarPermisosDeEmpleado(EMPLEADO_LAURA)) === $totalInicial, 'El intento con fechas inválidas no creó ningún registro');

// ---------------------------------------------------------------------
// 2. Motivo vacío: se rechaza.
// ---------------------------------------------------------------------
$token = tokenPermisos($cookieLaura);
[, $htmlSinMotivo] = peticion('POST', BASE . '/permisos.php', [
    'csrf_token'   => $token,
    'accion'       => 'solicitar',
    'fecha_inicio' => '2026-12-05',
    'fecha_fin'    => '2026-12-06',
    'motivo'       => '',
], $cookieLaura);
verificar(str_contains($htmlSinMotivo, 'El motivo es obligatorio'), 'Motivo vacío se rechaza');
verificar(count(listarPermisosDeEmpleado(EMPLEADO_LAURA)) === $totalInicial, 'El intento sin motivo no creó ningún registro');

// ---------------------------------------------------------------------
// 3. POST sin csrf_token: se rechaza.
// ---------------------------------------------------------------------
[, $htmlSinCsrf] = peticion('POST', BASE . '/permisos.php', [
    'accion'       => 'solicitar',
    'fecha_inicio' => '2026-12-05',
    'fecha_fin'    => '2026-12-06',
    'motivo'       => MOTIVO_PRUEBA,
], $cookieLaura);
verificar(str_contains($htmlSinCsrf, 'La sesión del formulario expiró'), 'POST sin csrf_token es rechazado');
verificar(count(listarPermisosDeEmpleado(EMPLEADO_LAURA)) === $totalInicial, 'El POST sin CSRF no creó ningún registro');

// ---------------------------------------------------------------------
// 4. Solicitud válida: queda pendiente.
// ---------------------------------------------------------------------
$token = tokenPermisos($cookieLaura);
[, $htmlOk] = peticion('POST', BASE . '/permisos.php', [
    'csrf_token'   => $token,
    'accion'       => 'solicitar',
    'fecha_inicio' => '2026-12-05',
    'fecha_fin'    => '2026-12-06',
    'motivo'       => MOTIVO_PRUEBA,
], $cookieLaura);
verificar(str_contains($htmlOk, 'Solicitud enviada'), 'Solicitud válida se envía correctamente');

$misSolicitudes = listarPermisosDeEmpleado(EMPLEADO_LAURA);
verificar(count($misSolicitudes) === $totalInicial + 1, 'La solicitud válida quedó guardada');
$nueva = $misSolicitudes[0]; // ORDER BY fecha_solicitud DESC
verificar($nueva['estado'] === 'pendiente' && $nueva['motivo'] === MOTIVO_PRUEBA, 'La nueva solicitud queda en estado pendiente');
$idPermisoPrueba = (int) $nueva['id'];

// ---------------------------------------------------------------------
// 5. Un empleado no ve las solicitudes de otro en su propia lista.
// ---------------------------------------------------------------------
[, $htmlOtroEmpleado] = peticion('GET', BASE . '/permisos.php', [], $cookieOtroEmpleado);
verificar(!str_contains($htmlOtroEmpleado, MOTIVO_PRUEBA), 'Carlos (otro empleado) no ve la solicitud de Laura en su propia pantalla');

// ---------------------------------------------------------------------
// 6. Alguien que NO es el jefe directo no puede resolverla, aunque
//    forje el POST directamente (la UI ni le muestra el botón).
// ---------------------------------------------------------------------
$token = tokenPermisos($cookieAjeno);
[, $htmlNoJefe] = peticion('POST', BASE . '/permisos.php', [
    'csrf_token' => $token,
    'accion'     => 'resolver',
    'id'         => $idPermisoPrueba,
    'decision'   => 'aprobado',
], $cookieAjeno);
verificar(
    str_contains($htmlNoJefe, 'Solo el jefe directo del solicitante puede resolver'),
    'Alguien que no es el jefe directo no puede aprobar (verificado por jefe_id, no por rol)'
);
verificar(obtenerPermiso($idPermisoPrueba)['estado'] === 'pendiente', 'El estado sigue pendiente tras el intento no autorizado');

// ---------------------------------------------------------------------
// 7. El jefe directo real sí puede aprobar.
// ---------------------------------------------------------------------
$token = tokenPermisos($cookieMarta);
[, $htmlAprobado] = peticion('POST', BASE . '/permisos.php', [
    'csrf_token' => $token,
    'accion'     => 'resolver',
    'id'         => $idPermisoPrueba,
    'decision'   => 'aprobado',
], $cookieMarta);
verificar(str_contains($htmlAprobado, 'Solicitud aprobado'), 'El jefe directo real aprueba la solicitud');

$permisoAprobado = obtenerPermiso($idPermisoPrueba);
verificar($permisoAprobado['estado'] === 'aprobado', 'El estado queda en aprobado');
verificar($permisoAprobado['fecha_respuesta'] !== null, 'Queda registrada la fecha de respuesta');

// ---------------------------------------------------------------------
// 8. Una solicitud ya resuelta no se puede volver a resolver.
// ---------------------------------------------------------------------
$token = tokenPermisos($cookieMarta);
[, $htmlDobleResolucion] = peticion('POST', BASE . '/permisos.php', [
    'csrf_token' => $token,
    'accion'     => 'resolver',
    'id'         => $idPermisoPrueba,
    'decision'   => 'rechazado',
], $cookieMarta);
verificar(
    str_contains($htmlDobleResolucion, 'ya había sido resuelta'),
    'Intentar resolver una solicitud ya resuelta se rechaza'
);
verificar(obtenerPermiso($idPermisoPrueba)['estado'] === 'aprobado', 'El estado sigue en aprobado (no cambió a rechazado)');

// ---------------------------------------------------------------------
// 9. Advertencia de fechas cruzadas (Tabla 41): no bloquea, solo avisa.
//    Se usa un rango de fechas aparte (feb-mar 2027) para no interferir
//    con la solicitud ya aprobada de las secciones anteriores.
// ---------------------------------------------------------------------
$idsCruce = [];

// 9a. Primera solicitud en este rango: no hay nada con qué cruzarse.
$token = tokenPermisos($cookieLaura);
[, $htmlBase] = peticion('POST', BASE . '/permisos.php', [
    'csrf_token'   => $token,
    'accion'       => 'solicitar',
    'fecha_inicio' => '2027-02-10',
    'fecha_fin'    => '2027-02-20',
    'motivo'       => 'Base cruce (prueba HU-04)',
], $cookieLaura);
verificar(!str_contains($htmlBase, 'Advertencia'), 'Sin nada previo en el rango: la solicitud no trae advertencia');
$idBase = ultimoIdPermisoDe(EMPLEADO_LAURA);
$idsCruce[] = $idBase;

// 9b. Sin cruce: fechas totalmente aparte de la solicitud base (10-20 feb).
$token = tokenPermisos($cookieLaura);
[, $htmlSinCruce] = peticion('POST', BASE . '/permisos.php', [
    'csrf_token'   => $token,
    'accion'       => 'solicitar',
    'fecha_inicio' => '2027-03-01',
    'fecha_fin'    => '2027-03-05',
    'motivo'       => 'Sin cruce (prueba HU-04)',
], $cookieLaura);
verificar(!str_contains($htmlSinCruce, 'Advertencia'), 'Fechas totalmente aparte de la base: no hay advertencia');
$idsCruce[] = ultimoIdPermisoDe(EMPLEADO_LAURA);

// 9c. Cruce parcial: se solapa solo con la cola de la base (18-25 feb vs 10-20 feb).
$token = tokenPermisos($cookieLaura);
[, $htmlParcial] = peticion('POST', BASE . '/permisos.php', [
    'csrf_token'   => $token,
    'accion'       => 'solicitar',
    'fecha_inicio' => '2027-02-18',
    'fecha_fin'    => '2027-02-25',
    'motivo'       => 'Cruce parcial (prueba HU-04)',
], $cookieLaura);
verificar(str_contains($htmlParcial, 'Advertencia: estas fechas se cruzan'), 'Cruce parcial con la base: sí hay advertencia');
$idsCruce[] = ultimoIdPermisoDe(EMPLEADO_LAURA);

// 9d. Cruce total: queda completamente dentro de la base (12-14 feb vs 10-20 feb).
$token = tokenPermisos($cookieLaura);
[, $htmlTotal] = peticion('POST', BASE . '/permisos.php', [
    'csrf_token'   => $token,
    'accion'       => 'solicitar',
    'fecha_inicio' => '2027-02-12',
    'fecha_fin'    => '2027-02-14',
    'motivo'       => 'Cruce total (prueba HU-04)',
], $cookieLaura);
verificar(str_contains($htmlTotal, 'Advertencia: estas fechas se cruzan'), 'Cruce total (contenida en la base): sí hay advertencia');
$idCruceTotal = ultimoIdPermisoDe(EMPLEADO_LAURA);
$idsCruce[] = $idCruceTotal;

// 9e. Cruce de un solo día: toca únicamente el último día de la base (20 feb).
$token = tokenPermisos($cookieLaura);
[, $htmlUnDia] = peticion('POST', BASE . '/permisos.php', [
    'csrf_token'   => $token,
    'accion'       => 'solicitar',
    'fecha_inicio' => '2027-02-20',
    'fecha_fin'    => '2027-02-20',
    'motivo'       => 'Cruce de un dia (prueba HU-04)',
], $cookieLaura);
verificar(str_contains($htmlUnDia, 'Advertencia: estas fechas se cruzan'), 'Cruce de un solo día: sí hay advertencia');
$idsCruce[] = ultimoIdPermisoDe(EMPLEADO_LAURA);

// 9f. Un permiso RECHAZADO no cuenta para el cruce: se rechazan la base
//     y el cruce total (las dos que tocan 12-14 feb) y se repite esa
//     misma solicitud; ya no debe traer advertencia.
$token = tokenPermisos($cookieMarta);
peticion('POST', BASE . '/permisos.php', [
    'csrf_token' => $token,
    'accion'     => 'resolver',
    'id'         => $idBase,
    'decision'   => 'rechazado',
], $cookieMarta);
$token = tokenPermisos($cookieMarta);
peticion('POST', BASE . '/permisos.php', [
    'csrf_token' => $token,
    'accion'     => 'resolver',
    'id'         => $idCruceTotal,
    'decision'   => 'rechazado',
], $cookieMarta);
verificar(
    obtenerPermiso($idBase)['estado'] === 'rechazado' && obtenerPermiso($idCruceTotal)['estado'] === 'rechazado',
    'La base y el cruce total quedan rechazados'
);

$token = tokenPermisos($cookieLaura);
[, $htmlTrasRechazo] = peticion('POST', BASE . '/permisos.php', [
    'csrf_token'   => $token,
    'accion'       => 'solicitar',
    'fecha_inicio' => '2027-02-12',
    'fecha_fin'    => '2027-02-14',
    'motivo'       => 'Tras rechazo, mismas fechas (prueba HU-04)',
], $cookieLaura);
verificar(!str_contains($htmlTrasRechazo, 'Advertencia'), 'Con lo que se cruzaba ya rechazado, las mismas fechas no generan advertencia');
$idsCruce[] = ultimoIdPermisoDe(EMPLEADO_LAURA);

// ---------------------------------------------------------------------
// Limpieza: borrar los permisos de prueba. da_app no tiene DELETE sobre
// 'permiso' (solo sobre 'desprendible'), así que se usa da_admin (sus
// credenciales viven solo en config/local.php, nunca en este archivo).
// ---------------------------------------------------------------------
$pdoAdmin = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_ADMIN_USER, DB_ADMIN_PASS);
$pdoAdmin->prepare('DELETE FROM permiso WHERE id = :id')->execute(['id' => $idPermisoPrueba]);
verificar(obtenerPermiso($idPermisoPrueba) === null, 'El permiso de prueba se eliminó en la limpieza final');

foreach ($idsCruce as $idCruce) {
    $pdoAdmin->prepare('DELETE FROM permiso WHERE id = :id')->execute(['id' => $idCruce]);
    verificar(obtenerPermiso($idCruce) === null, "El permiso de prueba de cruce #$idCruce se eliminó en la limpieza final");
}

verificar(count(listarPermisosDeEmpleado(EMPLEADO_LAURA)) === $totalInicial, 'Laura queda con el mismo número de solicitudes que al inicio');

@unlink($cookieLaura);
@unlink($cookieMarta);
@unlink($cookieAjeno);
@unlink($cookieOtroEmpleado);

echo PHP_EOL . ($fallas === 0 ? "TODAS LAS PRUEBAS PASARON" : "$fallas PRUEBA(S) FALLARON") . PHP_EOL;
exit($fallas === 0 ? 0 : 1);

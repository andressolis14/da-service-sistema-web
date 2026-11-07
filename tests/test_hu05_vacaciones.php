<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-05: consulta de vacaciones disponibles.
 *
 * Fórmula verificada (regla #10 de CLAUDE.md): 15 días hábiles por año
 * completo trabajado, proporcional para años incompletos, con fechas
 * reales (DateTime::diff, nunca meses aproximados a 30 días) sobre un
 * año de 365.25 días.
 *
 * Parte A: pruebas directas de calcularDiasAcumulados()/
 * calcularSaldoVacaciones() con fechas conocidas (sin pasar por HTTP),
 * cada una comparada contra un cálculo de días independiente
 * (diasRealesEntre, con timestamps Unix) para confirmar que no se usa
 * una aproximación de 30 días por mes:
 *  - Menos de un año.
 *  - Justo 365 días calendario (el caso "un año").
 *  - Ingresó hoy (0 días).
 *  - Año bisiesto (el periodo incluye un 29 de febrero).
 *  - Nunca negativo, aunque lo tomado supere lo acumulado.
 *
 * Parte B: acceso por HTTP contra la app real:
 *  - El empleado consulta su propio saldo.
 *  - Su jefe directo consulta el de su equipo (incluye vacaciones ya
 *    tomadas, con fechas reales de siembra).
 *  - Contabilidad consulta el de cualquiera.
 *  - Alguien que no es su jefe ni es Contabilidad/Administrador: 403.
 *
 * Usa el empleado de datos de prueba id=9 (Paola Ríos, sin vacaciones
 * tomadas) para la prueba "nunca negativo": inserta temporalmente una
 * fila en vacaciones_tomadas y la borra al terminar (da_app no tiene
 * DELETE ahí; usa da_admin, igual que en HU-03/04/07).
 *
 * Uso: php tests/test_hu05_vacaciones.php
 */

require_once __DIR__ . '/../src/config/app.php';

const BASE = 'http://localhost/daservice';
const EMPLEADO_LAURA = 10; // jefe directo: empleado 4 (mdiaz)
const EMPLEADO_PAOLA = 9;  // sin vacaciones tomadas, para la prueba "nunca negativo"

$fallas = 0;

function verificar(bool $condicion, string $descripcion): void
{
    global $fallas;
    echo ($condicion ? "[OK] " : "[FALLA] ") . $descripcion . PHP_EOL;
    if (!$condicion) {
        $fallas++;
    }
}

/** Cálculo de días independiente (timestamps Unix), para cruzar contra calcularDiasAcumulados(). */
function diasRealesEntre(string $a, string $b): int
{
    return (int) round((strtotime($b) - strtotime($a)) / 86400);
}

function accumuladosEsperados(string $fechaIngreso, string $hoy): int
{
    return (int) floor(diasRealesEntre($fechaIngreso, $hoy) / 365.25 * 15);
}

// =======================================================================
// Parte A: cálculo, con fechas conocidas.
// =======================================================================

echo "--- Parte A: cálculo (fechas conocidas) ---" . PHP_EOL;

// 1. Menos de un año (100 días).
$hoy = new DateTime('2026-11-07');
$ingreso100 = (clone $hoy)->modify('-100 days')->format('Y-m-d');
$esperado100 = accumuladosEsperados($ingreso100, '2026-11-07');
$real100 = calcularDiasAcumulados($ingreso100, $hoy);
verificar($real100 === $esperado100, "Menos de un año (100 días): acumula $real100 días (esperado $esperado100)");
verificar($real100 > 0 && $real100 < 15, 'Con menos de un año, el acumulado es proporcional (mayor que 0 y menor que 15)');

// 2. Justo 365 días calendario ("un año").
$ingreso365 = (clone $hoy)->modify('-365 days')->format('Y-m-d');
$esperado365 = accumuladosEsperados($ingreso365, '2026-11-07');
$real365 = calcularDiasAcumulados($ingreso365, $hoy);
verificar($real365 === $esperado365, "365 días calendario exactos: acumula $real365 días (esperado $esperado365, con año de 365.25 días)");

// 3. Ingresó hoy: 0 días.
$real0 = calcularDiasAcumulados('2026-11-07', $hoy);
verificar($real0 === 0, 'Un empleado que ingresó hoy acumula 0 días');

// 4. Año bisiesto: el periodo incluye el 29 de febrero de 2024.
$hoyBisiesto = new DateTime('2024-11-07');
$esperadoBisiesto = accumuladosEsperados('2023-11-07', '2024-11-07');
$realBisiesto = calcularDiasAcumulados('2023-11-07', $hoyBisiesto);
$diasRealesBisiesto = diasRealesEntre('2023-11-07', '2024-11-07');
verificar($diasRealesBisiesto === 366, 'El periodo de prueba realmente incluye el día extra del año bisiesto (366 días)');
verificar($realBisiesto === $esperadoBisiesto, "Año bisiesto: acumula $realBisiesto días (esperado $esperadoBisiesto), contando el 29 de febrero");

// 5. Nunca negativo: se inserta temporalmente más días tomados que los acumulados.
$saldoPaolaAntes = calcularSaldoVacaciones(EMPLEADO_PAOLA);
verificar($saldoPaolaAntes['dias_tomados'] === 0, 'Paola (empleado de prueba) no tiene vacaciones tomadas antes de la prueba');

$pdo = obtenerConexion();
$pdo->prepare("INSERT INTO vacaciones_tomadas (empleado_id, fecha_inicio, fecha_fin, dias) VALUES (:emp, '2026-01-01', '2026-01-02', 9999)")
    ->execute(['emp' => EMPLEADO_PAOLA]);

$saldoPaolaExcedido = calcularSaldoVacaciones(EMPLEADO_PAOLA);
verificar($saldoPaolaExcedido['dias_disponibles'] === 0, 'Si lo tomado supera lo acumulado, el disponible nunca es negativo (queda en 0)');
verificar($saldoPaolaExcedido['dias_disponibles'] >= 0, 'El disponible nunca es un número negativo');

$pdoAdmin = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_ADMIN_USER, DB_ADMIN_PASS);
$pdoAdmin->prepare('DELETE FROM vacaciones_tomadas WHERE empleado_id = :emp AND dias = 9999')->execute(['emp' => EMPLEADO_PAOLA]);
$saldoPaolaRestaurado = calcularSaldoVacaciones(EMPLEADO_PAOLA);
verificar($saldoPaolaRestaurado['dias_tomados'] === 0, 'La fila de prueba se eliminó; Paola queda sin vacaciones tomadas, como al inicio');

// =======================================================================
// Parte B: acceso por HTTP.
// =======================================================================

echo PHP_EOL . "--- Parte B: acceso (HTTP) ---" . PHP_EOL;

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

function iniciarSesion(string $usuario, string $cookieJar): void
{
    [, $html] = peticion('GET', BASE . '/login.php', [], $cookieJar);
    preg_match('/name="csrf_token" value="([^"]*)"/', $html, $m);
    peticion('POST', BASE . '/login.php', [
        'csrf_token'     => $m[1] ?? '',
        'nombre_usuario' => $usuario,
        'password'       => 'Prueba2026*',
    ], $cookieJar);
}

$cookieLaura   = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
$cookieMarta   = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
$cookieLcastro = tempnam(sys_get_temp_dir(), 'daservice_cookies_');
$cookieJperez  = tempnam(sys_get_temp_dir(), 'daservice_cookies_');

iniciarSesion('lgomez', $cookieLaura);   // Laura, empleado 10
iniciarSesion('mdiaz', $cookieMarta);    // Marta, su jefe directo real (empleado 4)
iniciarSesion('lcastro', $cookieLcastro); // Contabilidad
iniciarSesion('jperez', $cookieJperez);  // Vendedor, no es jefe de Laura

$saldoReal = calcularSaldoVacaciones(EMPLEADO_LAURA);

[$statusPropio, $htmlPropio] = peticion('GET', BASE . '/vacaciones.php', [], $cookieLaura);
verificar($statusPropio === 200, 'Laura consulta su propia pantalla de vacaciones (200)');
verificar(str_contains($htmlPropio, (string) $saldoReal['dias_disponibles']), 'Su propio saldo mostrado coincide con el calculado (' . $saldoReal['dias_disponibles'] . ' días)');

[$statusJefe, $htmlJefe] = peticion('GET', BASE . '/vacaciones.php?empleado=' . EMPLEADO_LAURA, [], $cookieMarta);
verificar($statusJefe === 200, 'Su jefe directo real (Marta) consulta el saldo de Laura (200)');
verificar(str_contains($htmlJefe, (string) $saldoReal['dias_disponibles']), 'El jefe ve el mismo saldo calculado para Laura');

[$statusGestion] = peticion('GET', BASE . '/vacaciones.php?empleado=' . EMPLEADO_LAURA, [], $cookieLcastro);
verificar($statusGestion === 200, 'Contabilidad consulta el saldo de Laura sin ser su jefe (200)');

[$statusDenegado, $cuerpoDenegado] = peticion('GET', BASE . '/vacaciones.php?empleado=' . EMPLEADO_LAURA, [], $cookieJperez);
verificar($statusDenegado === 403, 'Alguien que no es su jefe ni Contabilidad/Administrador recibe 403 (Vendedor consultando a Laura)');
verificar(str_contains($cuerpoDenegado, 'solo puedes consultar tus propias vacaciones'), 'El mensaje de denegación es claro');

@unlink($cookieLaura);
@unlink($cookieMarta);
@unlink($cookieLcastro);
@unlink($cookieJperez);

echo PHP_EOL . ($fallas === 0 ? "TODAS LAS PRUEBAS PASARON" : "$fallas PRUEBA(S) FALLARON") . PHP_EOL;
exit($fallas === 0 ? 0 : 1);

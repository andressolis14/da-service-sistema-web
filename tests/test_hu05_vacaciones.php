<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-05: consulta de vacaciones disponibles.
 *
 * Fórmula verificada (regla #10 de CLAUDE.md, citada tal cual:
 * "Indicadores, vacaciones disponibles (15 días hábiles por año
 * completo) y atrasos se calculan por consulta."; método de cálculo
 * DT-17): cada año completo trabajado (contado con DateTime::diff->y,
 * calendario real) suma 15 días hábiles exactos; el tramo del año en
 * curso se prorratea sobre la duración real de ESE año en concreto
 * (365 o 366 días, nunca una aproximación de 30 días por mes).
 *
 * Parte A: pruebas directas de calcularDiasAcumulados()/
 * calcularSaldoVacaciones() con fechas conocidas (sin pasar por HTTP),
 * cada una comparada contra un cálculo independiente
 * (acumuladosEsperados, que cuenta los años con un bucle de fechas y
 * aritmética de timestamps Unix, no con DateInterval->y):
 *  - Aniversario exacto de 1 año: debe dar 15,00 exactos.
 *  - Aniversario exacto de 2 años: debe dar 30,00 exactos.
 *  - Menos de un año (proporcional).
 *  - Ingresó hoy (0 días).
 *  - Tramo parcial que incluye un 29 de febrero (confirma que ese
 *    tramo se prorratea sobre 366 días, no 365).
 *  - Nunca negativo, aunque lo tomado supere lo acumulado.
 *
 * Parte B: acceso por HTTP contra la app real:
 *  - El empleado consulta su propio saldo.
 *  - Su jefe directo consulta el de su equipo.
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

function casiIgual(float $a, float $b, float $tolerancia = 0.005): bool
{
    return abs($a - $b) < $tolerancia;
}

/**
 * Cálculo independiente: cuenta los años completos con un bucle de
 * fechas (no con DateInterval->y, que es lo que usa la función real),
 * y prorratea el tramo con aritmética de timestamps Unix.
 */
function acumuladosEsperados(string $fechaIngreso, string $hoyStr): float
{
    $cursor = new DateTime($fechaIngreso);
    $hoy = new DateTime($hoyStr);
    $anios = 0;
    while (true) {
        $siguiente = (clone $cursor)->modify('+1 year');
        if ($siguiente > $hoy) {
            break;
        }
        $cursor = $siguiente;
        $anios++;
    }
    $finDelTramo = (clone $cursor)->modify('+1 year');
    $diasDelTramo = (int) round(($finDelTramo->getTimestamp() - $cursor->getTimestamp()) / 86400);
    $diasTranscurridos = (int) round(($hoy->getTimestamp() - $cursor->getTimestamp()) / 86400);
    return round($anios * 15 + ($diasTranscurridos / $diasDelTramo) * 15, 2);
}

// =======================================================================
// Parte A: cálculo, con fechas conocidas.
// =======================================================================

echo "--- Parte A: cálculo (fechas conocidas) ---" . PHP_EOL;
$hoy = new DateTime('2026-11-07');

// 1. Aniversario exacto de 1 año: debe dar 15,00 exactos (no 14,99).
$ingreso1anio = (clone $hoy)->modify('-1 year')->format('Y-m-d');
$real1anio = calcularDiasAcumulados($ingreso1anio, $hoy);
verificar(casiIgual($real1anio, 15.0), "Aniversario exacto de 1 año: acumula $real1anio días (esperado 15,00 exactos)");
verificar(casiIgual($real1anio, acumuladosEsperados($ingreso1anio, '2026-11-07')), 'Coincide con el cálculo independiente (1 año)');

// 2. Aniversario exacto de 2 años: debe dar 30,00 exactos.
$ingreso2anios = (clone $hoy)->modify('-2 years')->format('Y-m-d');
$real2anios = calcularDiasAcumulados($ingreso2anios, $hoy);
verificar(casiIgual($real2anios, 30.0), "Aniversario exacto de 2 años: acumula $real2anios días (esperado 30,00 exactos)");
verificar(casiIgual($real2anios, acumuladosEsperados($ingreso2anios, '2026-11-07')), 'Coincide con el cálculo independiente (2 años)');

// 3. Menos de un año (100 días): proporcional.
$ingreso100 = (clone $hoy)->modify('-100 days')->format('Y-m-d');
$real100 = calcularDiasAcumulados($ingreso100, $hoy);
$esperado100 = acumuladosEsperados($ingreso100, '2026-11-07');
verificar(casiIgual($real100, $esperado100), "Menos de un año (100 días): acumula $real100 días (esperado $esperado100)");
verificar($real100 > 0 && $real100 < 15, 'Con menos de un año, el acumulado es proporcional (mayor que 0 y menor que 15)');

// 4. Ingresó hoy: 0 días.
$real0 = calcularDiasAcumulados('2026-11-07', $hoy);
verificar(casiIgual($real0, 0.0), 'Un empleado que ingresó hoy acumula 0 días');

// 5. Tramo parcial que incluye un 29 de febrero: se prorratea sobre 366, no 365.
// Ingresa el 15 jun 2023; a esta fecha de referencia (20 feb 2024) todavía no
// cumple un año, y ese primer año (15 jun 2023 -> 15 jun 2024) sí es bisiesto.
$hoyBisiesto = new DateTime('2024-02-20');
$realBisiesto = calcularDiasAcumulados('2023-06-15', $hoyBisiesto);
$esperadoBisiesto = acumuladosEsperados('2023-06-15', '2024-02-20');
verificar(casiIgual($realBisiesto, $esperadoBisiesto), "Tramo con 29 de febrero: acumula $realBisiesto días (esperado $esperadoBisiesto)");

$comoSiFueran365 = round((new DateTime('2023-06-15'))->diff($hoyBisiesto)->days / 365 * 15, 2);
verificar(!casiIgual($realBisiesto, $comoSiFueran365), 'El resultado es distinto al que daría dividir por 365 fijos (sí nota el día bisiesto)');

// 6. Nunca negativo: se inserta temporalmente más días tomados que los acumulados.
$saldoPaolaAntes = calcularSaldoVacaciones(EMPLEADO_PAOLA);
verificar($saldoPaolaAntes['dias_tomados'] === 0, 'Paola (empleado de prueba) no tiene vacaciones tomadas antes de la prueba');

$pdo = obtenerConexion();
$pdo->prepare("INSERT INTO vacaciones_tomadas (empleado_id, fecha_inicio, fecha_fin, dias) VALUES (:emp, '2026-01-01', '2026-01-02', 9999)")
    ->execute(['emp' => EMPLEADO_PAOLA]);

$saldoPaolaExcedido = calcularSaldoVacaciones(EMPLEADO_PAOLA);
verificar(casiIgual($saldoPaolaExcedido['dias_disponibles'], 0.0), 'Si lo tomado supera lo acumulado, el disponible nunca es negativo (queda en 0)');
verificar($saldoPaolaExcedido['dias_disponibles'] >= 0, 'El disponible nunca es un número negativo');

$pdoAdmin = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_ADMIN_USER, DB_ADMIN_PASS);
$pdoAdmin->prepare('DELETE FROM vacaciones_tomadas WHERE empleado_id = :emp AND dias = 9999')->execute(['emp' => EMPLEADO_PAOLA]);
$saldoPaolaRestaurado = calcularSaldoVacaciones(EMPLEADO_PAOLA);
verificar($saldoPaolaRestaurado['dias_tomados'] === 0, 'La fila de prueba se eliminó; Paola queda sin vacaciones tomadas, como al inicio');

// 7. El saldo se formatea con un máximo de 2 decimales, sin ceros sobrantes.
verificar(formatearDias(15.0) === '15', 'formatearDias(15.0) no muestra decimales sobrantes ("15")');
verificar(formatearDias(4.1) === '4.1', 'formatearDias(4.1) muestra un solo decimal ("4.1")');
verificar(formatearDias(8.333) === '8.33', 'formatearDias(8.333) se redondea a 2 decimales ("8.33")');

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
$disponiblesTexto = formatearDias($saldoReal['dias_disponibles']);

[$statusPropio, $htmlPropio] = peticion('GET', BASE . '/vacaciones.php', [], $cookieLaura);
verificar($statusPropio === 200, 'Laura consulta su propia pantalla de vacaciones (200)');
verificar(str_contains($htmlPropio, $disponiblesTexto), "Su propio saldo mostrado coincide con el calculado ($disponiblesTexto días)");

[$statusJefe, $htmlJefe] = peticion('GET', BASE . '/vacaciones.php?empleado=' . EMPLEADO_LAURA, [], $cookieMarta);
verificar($statusJefe === 200, 'Su jefe directo real (Marta) consulta el saldo de Laura (200)');
verificar(str_contains($htmlJefe, $disponiblesTexto), 'El jefe ve el mismo saldo calculado para Laura');

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

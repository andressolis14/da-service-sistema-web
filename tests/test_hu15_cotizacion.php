<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-15: registro de cotización en la oportunidad.
 *
 * Verifica, contra la aplicación real (requiere Apache + MySQL activos):
 *  - Guardar número y enlace válidos.
 *  - Un enlace con javascript: se rechaza.
 *  - Un enlace sin esquema (ni http ni https) se rechaza.
 *  - Un enlace vacío se rechaza.
 *  - Un número vacío se rechaza.
 *  - Una oportunidad inexistente se rechaza.
 *  - Rol no autorizado (Técnico) recibe 403.
 *  - POST sin csrf_token rechazado.
 *  - El enlace se muestra escapado (htmlspecialchars), no crudo.
 *
 * Usa la oportunidad de datos de prueba id=1 (sin cotización en la
 * siembra). Al terminar, deja cotizacion_numero y cotizacion_enlace
 * otra vez en NULL (UPDATE directo: da_app sí tiene permiso de UPDATE
 * sobre toda la base, a diferencia de DELETE).
 *
 * Uso: php tests/test_hu15_cotizacion.php
 */

require_once __DIR__ . '/../src/config/app.php';

const BASE = 'http://localhost/daservice';
const OPORTUNIDAD_PRUEBA = 1; // Cableado estructurado de la bodega, sin cotización en la siembra

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

function tokenVerOportunidad(int $id, string $cookieJar): string
{
    [, $html] = peticion('GET', BASE . '/oportunidades.php?ver=' . $id, [], $cookieJar);
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

iniciarSesion('jperez', $cookieVendedor); // Vendedor
iniciarSesion('amora', $cookieTecnico);   // Técnico, no autorizado

$oportunidadInicial = obtenerOportunidad(OPORTUNIDAD_PRUEBA);
verificar($oportunidadInicial['cotizacion_numero'] === null, 'La oportunidad de prueba empieza sin número de cotización');
verificar($oportunidadInicial['cotizacion_enlace'] === null, 'La oportunidad de prueba empieza sin enlace de cotización');

// ---------------------------------------------------------------------
// 1. Rol no autorizado (Técnico): no puede guardar cotización.
// ---------------------------------------------------------------------
[$statusTecnico] = peticion('POST', BASE . '/oportunidades.php', [
    'csrf_token'         => 'no-importa',
    'accion'             => 'cotizacion',
    'id'                 => OPORTUNIDAD_PRUEBA,
    'cotizacion_numero'  => 'COT-2026-999',
    'cotizacion_enlace'  => 'https://drive.example/cot-999',
], $cookieTecnico);
verificar($statusTecnico === 403, 'Un rol no autorizado (Técnico) recibe 403');

// ---------------------------------------------------------------------
// 2. Enlace con javascript: se rechaza.
// ---------------------------------------------------------------------
$token = tokenVerOportunidad(OPORTUNIDAD_PRUEBA, $cookieVendedor);
[, $htmlJs] = peticion('POST', BASE . '/oportunidades.php', [
    'csrf_token'        => $token,
    'accion'            => 'cotizacion',
    'id'                => OPORTUNIDAD_PRUEBA,
    'cotizacion_numero' => 'COT-2026-050',
    'cotizacion_enlace' => 'javascript:alert(1)',
], $cookieVendedor);
verificar(str_contains($htmlJs, 'debe empezar por http'), 'Un enlace con javascript: se rechaza');

// ---------------------------------------------------------------------
// 3. Enlace sin esquema se rechaza.
// ---------------------------------------------------------------------
$token = tokenVerOportunidad(OPORTUNIDAD_PRUEBA, $cookieVendedor);
[, $htmlSinEsquema] = peticion('POST', BASE . '/oportunidades.php', [
    'csrf_token'        => $token,
    'accion'            => 'cotizacion',
    'id'                => OPORTUNIDAD_PRUEBA,
    'cotizacion_numero' => 'COT-2026-050',
    'cotizacion_enlace' => 'drive.example/cot-050',
], $cookieVendedor);
verificar(str_contains($htmlSinEsquema, 'debe empezar por http'), 'Un enlace sin esquema (ni http ni https) se rechaza');

// ---------------------------------------------------------------------
// 4. Enlace vacío se rechaza.
// ---------------------------------------------------------------------
$token = tokenVerOportunidad(OPORTUNIDAD_PRUEBA, $cookieVendedor);
[, $htmlEnlaceVacio] = peticion('POST', BASE . '/oportunidades.php', [
    'csrf_token'        => $token,
    'accion'            => 'cotizacion',
    'id'                => OPORTUNIDAD_PRUEBA,
    'cotizacion_numero' => 'COT-2026-050',
    'cotizacion_enlace' => '',
], $cookieVendedor);
verificar(str_contains($htmlEnlaceVacio, 'enlace de la cotización es obligatorio'), 'Un enlace vacío se rechaza');

// ---------------------------------------------------------------------
// 5. Número vacío se rechaza.
// ---------------------------------------------------------------------
$token = tokenVerOportunidad(OPORTUNIDAD_PRUEBA, $cookieVendedor);
[, $htmlNumeroVacio] = peticion('POST', BASE . '/oportunidades.php', [
    'csrf_token'        => $token,
    'accion'            => 'cotizacion',
    'id'                => OPORTUNIDAD_PRUEBA,
    'cotizacion_numero' => '',
    'cotizacion_enlace' => 'https://drive.example/cot-050',
], $cookieVendedor);
verificar(str_contains($htmlNumeroVacio, 'número de cotización es obligatorio'), 'Un número vacío se rechaza');

verificar(obtenerOportunidad(OPORTUNIDAD_PRUEBA)['cotizacion_numero'] === null, 'Ninguno de los intentos inválidos guardó nada');

// ---------------------------------------------------------------------
// 6. Oportunidad inexistente se rechaza.
// ---------------------------------------------------------------------
$token = tokenVerOportunidad(OPORTUNIDAD_PRUEBA, $cookieVendedor);
[, $htmlInexistente] = peticion('POST', BASE . '/oportunidades.php', [
    'csrf_token'        => $token,
    'accion'            => 'cotizacion',
    'id'                => 999999,
    'cotizacion_numero' => 'COT-2026-050',
    'cotizacion_enlace' => 'https://drive.example/cot-050',
], $cookieVendedor);
verificar(str_contains($htmlInexistente, 'Oportunidad no encontrada'), 'Una oportunidad inexistente se rechaza');

// ---------------------------------------------------------------------
// 7. POST sin csrf_token se rechaza.
// ---------------------------------------------------------------------
[, $htmlSinCsrf] = peticion('POST', BASE . '/oportunidades.php', [
    'accion'            => 'cotizacion',
    'id'                => OPORTUNIDAD_PRUEBA,
    'cotizacion_numero' => 'COT-2026-050',
    'cotizacion_enlace' => 'https://drive.example/cot-050',
], $cookieVendedor);
verificar(str_contains($htmlSinCsrf, 'La sesión del formulario expiró'), 'POST sin csrf_token es rechazado');
verificar(obtenerOportunidad(OPORTUNIDAD_PRUEBA)['cotizacion_numero'] === null, 'El POST sin CSRF no guardó nada');

// ---------------------------------------------------------------------
// 8. Guardar número y enlace válidos.
// ---------------------------------------------------------------------
$token = tokenVerOportunidad(OPORTUNIDAD_PRUEBA, $cookieVendedor);
[, $htmlOk] = peticion('POST', BASE . '/oportunidades.php', [
    'csrf_token'        => $token,
    'accion'            => 'cotizacion',
    'id'                => OPORTUNIDAD_PRUEBA,
    'cotizacion_numero' => 'COT-2026-050',
    'cotizacion_enlace' => 'https://drive.example/cotizaciones?cliente=Plasticos&id=50',
], $cookieVendedor);
verificar(str_contains($htmlOk, 'Cotización registrada'), 'Guardar número y enlace válidos funciona');

$oportunidadConCotizacion = obtenerOportunidad(OPORTUNIDAD_PRUEBA);
verificar($oportunidadConCotizacion['cotizacion_numero'] === 'COT-2026-050', 'El número de cotización quedó guardado');
verificar(
    $oportunidadConCotizacion['cotizacion_enlace'] === 'https://drive.example/cotizaciones?cliente=Plasticos&id=50',
    'El enlace de cotización quedó guardado completo'
);

// ---------------------------------------------------------------------
// 9. El enlace se muestra escapado en pantalla (htmlspecialchars), no
//    crudo: el "&" de la URL debe aparecer como "&amp;" en el HTML.
// ---------------------------------------------------------------------
[, $htmlDetalle] = peticion('GET', BASE . '/oportunidades.php?ver=' . OPORTUNIDAD_PRUEBA, [], $cookieVendedor);
verificar(
    str_contains($htmlDetalle, 'cliente=Plasticos&amp;id=50'),
    'El enlace se muestra escapado (&amp; en vez de & crudo)'
);
verificar(
    !preg_match('/href="https:\/\/drive\.example\/cotizaciones\?cliente=Plasticos&id=50"/', $htmlDetalle),
    'El enlace NO aparece sin escapar en el HTML'
);
verificar(str_contains($htmlDetalle, 'rel="noopener noreferrer"'), 'El enlace lleva rel="noopener noreferrer"');

// ---------------------------------------------------------------------
// Limpieza: dejar la oportunidad de prueba sin cotización, como al
// inicio (UPDATE directo; da_app sí puede hacer UPDATE).
// ---------------------------------------------------------------------
$pdo = obtenerConexion();
$pdo->prepare('UPDATE oportunidad SET cotizacion_numero = NULL, cotizacion_enlace = NULL WHERE id = :id')
    ->execute(['id' => OPORTUNIDAD_PRUEBA]);

$oportunidadFinal = obtenerOportunidad(OPORTUNIDAD_PRUEBA);
verificar($oportunidadFinal['cotizacion_numero'] === null && $oportunidadFinal['cotizacion_enlace'] === null, 'La oportunidad de prueba queda otra vez sin cotización, como al inicio');

@unlink($cookieVendedor);
@unlink($cookieTecnico);

echo PHP_EOL . ($fallas === 0 ? "TODAS LAS PRUEBAS PASARON" : "$fallas PRUEBA(S) FALLARON") . PHP_EOL;
exit($fallas === 0 ? 0 : 1);

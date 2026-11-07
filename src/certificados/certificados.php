<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-03: certificado laboral automático en PDF (Dompdf).
 *
 * El PDF se genera al vuelo a partir de los datos vigentes del empleado
 * y nunca se guarda en disco; solo queda registrado en la tabla
 * 'certificado' cuándo se generó (quién lo solicitó y en qué momento),
 * igual que hacía la siembra. Los datos de la empresa (NIT, dirección,
 * representante) son ficticios y están marcados como tal en
 * src/config/empresa.php.
 */

function obtenerDatosCertificado(int $empleadoId): ?array
{
    $stmt = obtenerConexion()->prepare('
        SELECT e.nombre_completo, e.documento, e.cargo, e.fecha_ingreso, e.estado, a.nombre AS area_nombre
        FROM empleado e
        JOIN area a ON a.id = e.area_id
        WHERE e.id = :id
    ');
    $stmt->execute(['id' => $empleadoId]);
    $fila = $stmt->fetch();
    return $fila ?: null;
}

/** Devuelve ['exito'=>bool, 'mensaje'=>string, 'pdf'=>?string, 'nombreArchivo'=>?string]. */
function generarCertificadoEmpleado(int $empleadoId): array
{
    $datos = obtenerDatosCertificado($empleadoId);
    if (!$datos) {
        return ['exito' => false, 'mensaje' => 'Empleado no encontrado.'];
    }
    if ($datos['estado'] !== 'activo') {
        return ['exito' => false, 'mensaje' => 'Solo se genera el certificado laboral para empleados activos.'];
    }

    $stmt = obtenerConexion()->prepare('
        INSERT INTO certificado (empleado_id, fecha_solicitud, fecha_generacion)
        VALUES (:emp, NOW(), NOW())
    ');
    $stmt->execute(['emp' => $empleadoId]);

    $pdf = construirPdfCertificado($datos);
    $documentoSoloDigitos = preg_replace('/[^0-9]/', '', $datos['documento']);

    return [
        'exito'         => true,
        'mensaje'       => 'Certificado generado.',
        'pdf'           => $pdf,
        'nombreArchivo' => 'certificado_laboral_' . $documentoSoloDigitos . '.pdf',
    ];
}

function construirPdfCertificado(array $datos): string
{
    $dompdf = new \Dompdf\Dompdf([
        'defaultFont'        => 'DejaVu Sans', // soporta tildes y ñ correctamente
        'isHtml5ParserEnabled' => true,
    ]);
    $dompdf->loadHtml(vistaHtmlCertificado($datos), 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();
    return $dompdf->output();
}

/** DateTime::format('F') devuelve el mes en inglés; aquí se traduce a mano. */
function fechaLargaEnEspanol(DateTime $fecha): string
{
    static $meses = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];
    return $fecha->format('d') . ' de ' . $meses[(int) $fecha->format('n')] . ' de ' . $fecha->format('Y');
}

function vistaHtmlCertificado(array $datos): string
{
    $fechaIngreso = fechaLargaEnEspanol(new DateTime($datos['fecha_ingreso']));
    $fechaExpedicion = fechaLargaEnEspanol(new DateTime());

    $nombre = htmlspecialchars($datos['nombre_completo']);
    $documento = htmlspecialchars($datos['documento']);
    $cargo = htmlspecialchars($datos['cargo']);
    $area = htmlspecialchars($datos['area_nombre']);
    $razonSocial = htmlspecialchars(EMPRESA_RAZON_SOCIAL);
    $nit = htmlspecialchars(EMPRESA_NIT);
    $direccion = htmlspecialchars(EMPRESA_DIRECCION);
    $representante = htmlspecialchars(EMPRESA_REPRESENTANTE);

    return <<<HTML
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 12pt; color: #222; line-height: 1.6; }
    .encabezado { text-align: center; margin-bottom: 30px; }
    .encabezado h1 { font-size: 14pt; margin: 0 0 4px 0; }
    .encabezado p { font-size: 9pt; color: #555; margin: 0; }
    .titulo { text-align: center; font-size: 13pt; font-weight: bold; text-transform: uppercase; margin: 30px 0; letter-spacing: 1px; }
    .cuerpo { text-align: justify; margin: 0 20px; }
    .firma { margin-top: 70px; text-align: center; }
    .firma .linea { border-top: 1px solid #222; width: 260px; margin: 0 auto 6px auto; }
    .pie { margin-top: 50px; font-size: 8pt; color: #888; text-align: center; }
</style>
</head>
<body>
    <div class="encabezado">
        <h1>{$razonSocial}</h1>
        <p>NIT {$nit} &middot; {$direccion}</p>
    </div>

    <div class="titulo">Certificado laboral</div>

    <div class="cuerpo">
        <p>
            {$razonSocial}, identificada con NIT {$nit}, certifica que
            <strong>{$nombre}</strong>, identificado(a) con documento de identidad
            No. {$documento}, labora actualmente en la empresa desde el
            {$fechaIngreso}, desempeñando el cargo de <strong>{$cargo}</strong>
            en el área de {$area}.
        </p>
        <p>
            El presente certificado se expide a solicitud del interesado el
            {$fechaExpedicion}.
        </p>
    </div>

    <div class="firma">
        <div class="linea"></div>
        <p>{$representante}<br>Representante legal</p>
    </div>

    <div class="pie">
        Documento generado automáticamente por el sistema web de D.A SERVICE S.A.S.
    </div>
</body>
</html>
HTML;
}

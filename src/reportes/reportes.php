<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-14: reportes e indicadores, exportables a Excel.
 *
 * Todos se calculan por consulta (regla #10 de CLAUDE.md), nunca se
 * guarda un valor; todas las consultas usan obtenerConexionReportes()
 * (perfil de solo lectura 'da_reportes'). Son una adaptación directa
 * de las consultas de comprobación del script (C06, C07, C08, C09,
 * C13), con un filtro de periodo desde/hasta agregado.
 *
 * Reportes 1 a 3 (soporte técnico): Coordinador, Gerente, Administrador.
 * Reportes 4 y 5 (comerciales): Gerente, Administrador.
 */

const REPORTES_RANGO_MAXIMO_ANIOS = 5;

/** Valida un rango desde/hasta (ambos opcionales). Devuelve ['exito'=>bool,'mensaje'=>string]. */
function validarRangoPeriodo(?string $desde, ?string $hasta): array
{
    $desde = $desde !== '' ? $desde : null;
    $hasta = $hasta !== '' ? $hasta : null;

    $desdeDt = $desde ? DateTime::createFromFormat('Y-m-d', $desde) : null;
    $hastaDt = $hasta ? DateTime::createFromFormat('Y-m-d', $hasta) : null;

    if ($desde && (!$desdeDt || $desdeDt->format('Y-m-d') !== $desde)) {
        return ['exito' => false, 'mensaje' => 'La fecha "desde" no tiene un formato válido (AAAA-MM-DD).'];
    }
    if ($hasta && (!$hastaDt || $hastaDt->format('Y-m-d') !== $hasta)) {
        return ['exito' => false, 'mensaje' => 'La fecha "hasta" no tiene un formato válido (AAAA-MM-DD).'];
    }
    if ($desdeDt && $hastaDt) {
        if ($desdeDt > $hastaDt) {
            return ['exito' => false, 'mensaje' => 'La fecha "desde" no puede ser posterior a la fecha "hasta".'];
        }
        $limite = (clone $desdeDt)->modify('+' . REPORTES_RANGO_MAXIMO_ANIOS . ' years');
        if ($hastaDt > $limite) {
            return ['exito' => false, 'mensaje' => 'El rango no puede superar ' . REPORTES_RANGO_MAXIMO_ANIOS . ' años.'];
        }
    }

    return ['exito' => true, 'mensaje' => ''];
}

function reporteTicketsPorEstado(?string $desde, ?string $hasta): array
{
    $hastaFin = $hasta ? $hasta . ' 23:59:59' : null;
    $stmt = obtenerConexionReportes()->prepare('
        SELECT estado, solicitante_tipo, COUNT(*) AS total
        FROM ticket
        WHERE (:desde1 IS NULL OR fecha_creacion >= :desde2)
          AND (:hasta1 IS NULL OR fecha_creacion <= :hasta2)
        GROUP BY estado, solicitante_tipo
        ORDER BY estado, solicitante_tipo
    ');
    $stmt->execute(['desde1' => $desde, 'desde2' => $desde, 'hasta1' => $hastaFin, 'hasta2' => $hastaFin]);
    return $stmt->fetchAll();
}

function reporteTiempoMedioAtencion(?string $desde, ?string $hasta): array
{
    $hastaFin = $hasta ? $hasta . ' 23:59:59' : null;
    $stmt = obtenerConexionReportes()->prepare("
        SELECT t.id, t.asunto, TIMESTAMPDIFF(MINUTE, t.fecha_creacion, cierre.fecha) AS minutos_hasta_cierre
        FROM ticket t
        JOIN (
            SELECT ticket_id, MAX(fecha) AS fecha FROM actualizacion_ticket WHERE estado_nuevo = 'cerrado' GROUP BY ticket_id
        ) cierre ON cierre.ticket_id = t.id
        WHERE (:desde1 IS NULL OR t.fecha_creacion >= :desde2)
          AND (:hasta1 IS NULL OR t.fecha_creacion <= :hasta2)
        ORDER BY t.id
    ");
    $stmt->execute(['desde1' => $desde, 'desde2' => $desde, 'hasta1' => $hastaFin, 'hasta2' => $hastaFin]);
    $filas = $stmt->fetchAll();

    $promedio = null;
    if ($filas) {
        $promedio = round(array_sum(array_column($filas, 'minutos_hasta_cierre')) / count($filas), 1);
    }

    return ['detalle' => $filas, 'promedio_minutos' => $promedio, 'tickets_cerrados' => count($filas)];
}

function reporteCalificacionPromedio(?string $desde, ?string $hasta): array
{
    $hastaFin = $hasta ? $hasta . ' 23:59:59' : null;

    $stmtGeneral = obtenerConexionReportes()->prepare('
        SELECT ROUND(AVG(puntaje), 1) AS promedio, COUNT(*) AS total
        FROM calificacion
        WHERE (:desde1 IS NULL OR fecha >= :desde2) AND (:hasta1 IS NULL OR fecha <= :hasta2)
    ');
    $stmtGeneral->execute(['desde1' => $desde, 'desde2' => $desde, 'hasta1' => $hastaFin, 'hasta2' => $hastaFin]);
    $general = $stmtGeneral->fetch();

    $stmtTecnico = obtenerConexionReportes()->prepare('
        SELECT e.nombre_completo AS tecnico, COUNT(*) AS tickets_calificados, ROUND(AVG(c.puntaje), 1) AS promedio
        FROM calificacion c
        JOIN asignacion s ON s.ticket_id = c.ticket_id
        JOIN usuario u ON u.id = s.tecnico_id
        JOIN empleado e ON e.id = u.empleado_id
        WHERE (:desde1 IS NULL OR c.fecha >= :desde2) AND (:hasta1 IS NULL OR c.fecha <= :hasta2)
        GROUP BY e.id, e.nombre_completo
        ORDER BY promedio DESC
    ');
    $stmtTecnico->execute(['desde1' => $desde, 'desde2' => $desde, 'hasta1' => $hastaFin, 'hasta2' => $hastaFin]);

    return ['general' => $general, 'por_tecnico' => $stmtTecnico->fetchAll()];
}

function reporteOportunidadesPorEtapa(?string $desde, ?string $hasta): array
{
    $hastaFin = $hasta ? $hasta . ' 23:59:59' : null;
    $stmt = obtenerConexionReportes()->prepare("
        SELECT etapa, COUNT(*) AS oportunidades, SUM(valor_estimado) AS valor_total_cop
        FROM oportunidad
        WHERE (:desde1 IS NULL OR fecha_creacion >= :desde2) AND (:hasta1 IS NULL OR fecha_creacion <= :hasta2)
        GROUP BY etapa
        ORDER BY FIELD(etapa, 'calificado','propuesta','negociacion','aprobada','ganado','pendiente','perdido')
    ");
    $stmt->execute(['desde1' => $desde, 'desde2' => $desde, 'hasta1' => $hastaFin, 'hasta2' => $hastaFin]);
    return $stmt->fetchAll();
}

function reporteDesempenoVendedores(?string $desde, ?string $hasta): array
{
    $stmt = obtenerConexionReportes()->prepare('
        SELECT e.nombre_completo AS vendedor,
               SUM(d.llamadas_totales) AS llamadas,
               SUM(d.llamadas_contestadas) AS contestadas,
               ROUND(100 * SUM(d.llamadas_contestadas) / NULLIF(SUM(d.llamadas_totales), 0), 1) AS pct_contestadas,
               SUM(d.interesados) AS interesados,
               SUM(d.portafolios_enviados) AS portafolios
        FROM desempeno_diario d
        JOIN usuario u ON u.id = d.vendedor_id
        JOIN empleado e ON e.id = u.empleado_id
        WHERE (:desde1 IS NULL OR d.fecha >= :desde2) AND (:hasta1 IS NULL OR d.fecha <= :hasta2)
        GROUP BY e.id, e.nombre_completo
        ORDER BY e.nombre_completo
    ');
    $stmt->execute(['desde1' => $desde, 'desde2' => $desde, 'hasta1' => $hasta, 'hasta2' => $hasta]);
    return $stmt->fetchAll();
}

// --- Exportación a Excel (PhpSpreadsheet) -----------------------------

/**
 * Protege contra inyección de fórmulas en Excel/CSV (OWASP CSV
 * Injection): cualquier celda de texto que empiece por =, +, - o @ se
 * guarda con un apóstrofo delante y con tipo de dato explícito STRING,
 * para que Excel la trate siempre como texto literal, nunca como una
 * fórmula.
 */
function celdaSegura($valor): string
{
    $texto = (string) $valor;
    if ($texto !== '' && in_array($texto[0], ['=', '+', '-', '@'], true)) {
        return "'" . $texto;
    }
    return $texto;
}

function escribirCeldaTexto(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $hoja, string $coordenada, $valor): void
{
    $hoja->setCellValueExplicit($coordenada, celdaSegura($valor), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
}

function escribirEncabezadoReporte(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $hoja, string $titulo, ?string $desde, ?string $hasta): int
{
    escribirCeldaTexto($hoja, 'A1', $titulo);
    $periodo = ($desde || $hasta)
        ? ('Periodo: ' . ($desde ?: 'inicio del histórico') . ' a ' . ($hasta ?: 'hoy'))
        : 'Periodo: todo el histórico';
    escribirCeldaTexto($hoja, 'A2', $periodo);
    escribirCeldaTexto($hoja, 'A3', 'Generado el ' . (new DateTime())->format('Y-m-d H:i:s'));
    return 5; // primera fila libre para los encabezados de columnas
}

/** Arma el libro de Excel del reporte pedido, listo para descargarse. */
function construirExcelReporte(int $numero, ?string $desde, ?string $hasta): \PhpOffice\PhpSpreadsheet\Spreadsheet
{
    $libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $hoja = $libro->getActiveSheet();

    if ($numero === 1) {
        $hoja->setTitle('Tickets por estado');
        $fila = escribirEncabezadoReporte($hoja, 'Tickets por estado y tipo de solicitante', $desde, $hasta);
        escribirCeldaTexto($hoja, "A$fila", 'Estado');
        escribirCeldaTexto($hoja, "B$fila", 'Tipo de solicitante');
        $hoja->setCellValue("C$fila", 'Total');
        $fila++;
        foreach (reporteTicketsPorEstado($desde, $hasta) as $r) {
            escribirCeldaTexto($hoja, "A$fila", ucfirst(str_replace('_', ' ', $r['estado'])));
            escribirCeldaTexto($hoja, "B$fila", ucfirst($r['solicitante_tipo']));
            $hoja->setCellValue("C$fila", (int) $r['total']);
            $fila++;
        }
    } elseif ($numero === 2) {
        $hoja->setTitle('Tiempo medio de atencion');
        $datos = reporteTiempoMedioAtencion($desde, $hasta);
        $fila = escribirEncabezadoReporte($hoja, 'Tiempo medio de atención (minutos)', $desde, $hasta);
        escribirCeldaTexto($hoja, "A$fila", 'Promedio general (minutos)');
        $hoja->setCellValue("B$fila", $datos['promedio_minutos']);
        $fila++;
        escribirCeldaTexto($hoja, "A$fila", 'Tickets cerrados considerados');
        $hoja->setCellValue("B$fila", $datos['tickets_cerrados']);
        $fila += 2;
        escribirCeldaTexto($hoja, "A$fila", 'Ticket');
        escribirCeldaTexto($hoja, "B$fila", 'Asunto');
        escribirCeldaTexto($hoja, "C$fila", 'Minutos hasta el cierre');
        $fila++;
        foreach ($datos['detalle'] as $r) {
            $hoja->setCellValue("A$fila", (int) $r['id']);
            escribirCeldaTexto($hoja, "B$fila", $r['asunto']);
            $hoja->setCellValue("C$fila", (int) $r['minutos_hasta_cierre']);
            $fila++;
        }
    } elseif ($numero === 3) {
        $hoja->setTitle('Calificacion promedio');
        $datos = reporteCalificacionPromedio($desde, $hasta);
        $fila = escribirEncabezadoReporte($hoja, 'Calificación promedio del servicio', $desde, $hasta);
        escribirCeldaTexto($hoja, "A$fila", 'Promedio general');
        $hoja->setCellValue("B$fila", $datos['general']['promedio']);
        $fila++;
        escribirCeldaTexto($hoja, "A$fila", 'Calificaciones consideradas');
        $hoja->setCellValue("B$fila", (int) $datos['general']['total']);
        $fila += 2;
        escribirCeldaTexto($hoja, "A$fila", 'Técnico');
        escribirCeldaTexto($hoja, "B$fila", 'Tickets calificados');
        escribirCeldaTexto($hoja, "C$fila", 'Promedio');
        $fila++;
        foreach ($datos['por_tecnico'] as $r) {
            escribirCeldaTexto($hoja, "A$fila", $r['tecnico']);
            $hoja->setCellValue("B$fila", (int) $r['tickets_calificados']);
            $hoja->setCellValue("C$fila", $r['promedio']);
            $fila++;
        }
    } elseif ($numero === 4) {
        $hoja->setTitle('Oportunidades por etapa');
        $fila = escribirEncabezadoReporte($hoja, 'Oportunidades y valor estimado por etapa', $desde, $hasta);
        escribirCeldaTexto($hoja, "A$fila", 'Etapa');
        escribirCeldaTexto($hoja, "B$fila", 'Oportunidades');
        escribirCeldaTexto($hoja, "C$fila", 'Valor total (COP)');
        $fila++;
        foreach (reporteOportunidadesPorEtapa($desde, $hasta) as $r) {
            escribirCeldaTexto($hoja, "A$fila", ucfirst($r['etapa']));
            $hoja->setCellValue("B$fila", (int) $r['oportunidades']);
            $hoja->setCellValue("C$fila", (float) $r['valor_total_cop']);
            $fila++;
        }
    } elseif ($numero === 5) {
        $hoja->setTitle('Desempeno de vendedores');
        $fila = escribirEncabezadoReporte($hoja, 'Desempeño de vendedores', $desde, $hasta);
        escribirCeldaTexto($hoja, "A$fila", 'Vendedor');
        escribirCeldaTexto($hoja, "B$fila", 'Llamadas');
        escribirCeldaTexto($hoja, "C$fila", 'Contestadas');
        escribirCeldaTexto($hoja, "D$fila", '% contestadas');
        escribirCeldaTexto($hoja, "E$fila", 'Interesados');
        escribirCeldaTexto($hoja, "F$fila", 'Portafolios');
        $fila++;
        foreach (reporteDesempenoVendedores($desde, $hasta) as $r) {
            escribirCeldaTexto($hoja, "A$fila", $r['vendedor']);
            $hoja->setCellValue("B$fila", (int) $r['llamadas']);
            $hoja->setCellValue("C$fila", (int) $r['contestadas']);
            $hoja->setCellValue("D$fila", $r['pct_contestadas']);
            $hoja->setCellValue("E$fila", (int) $r['interesados']);
            $hoja->setCellValue("F$fila", (int) $r['portafolios']);
            $fila++;
        }
    }

    return $libro;
}

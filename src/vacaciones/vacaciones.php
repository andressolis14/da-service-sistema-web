<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-05: consulta de vacaciones disponibles.
 *
 * Fórmula (regla #10 de CLAUDE.md, citada tal cual: "Indicadores,
 * vacaciones disponibles (15 días hábiles por año completo) y atrasos
 * se calculan por consulta."): por cada año completo trabajado se
 * acumulan 15 días hábiles exactos, y el tramo del año en curso se
 * prorratea.
 *
 * DT-17 (método de cálculo, a validar con la empresa): los años
 * completos se cuentan con DateTime::diff (->y), que ya compara
 * fecha-a-fecha en el calendario real (sabe si el 29 de febrero debe
 * contar o no en cada caso); por eso cada aniversario exacto da 15,00
 * (o 30,00 a los 2 años, etc.), sin el redondeo hacia abajo que daba
 * 14,99 al dividir todo el tiempo trabajado entre un año fijo de
 * 365.25 días. Solo el tramo incompleto (desde el último aniversario
 * hasta hoy) se prorratea, dividiendo esos días reales entre la
 * duración real de ESE año en concreto (365 o 366, según si ese tramo
 * incluye un 29 de febrero), nunca una aproximación de 30 días por mes.
 *
 * Los días disponibles nunca son negativos: si ya tomó más de lo
 * acumulado (por ejemplo por un ajuste manual), se muestra 0. Se
 * redondea a un máximo de 2 decimales.
 */

/**
 * Días hábiles acumulados hasta $hoy (por defecto, hoy). $hoy existe
 * para que las pruebas puedan fijar una fecha de referencia conocida.
 */
function calcularDiasAcumulados(string $fechaIngreso, ?DateTimeInterface $hoy = null): float
{
    $ingreso = new DateTime($fechaIngreso);
    $hoy = $hoy instanceof DateTime ? $hoy : new DateTime('today');
    if ($hoy < $ingreso) {
        return 0.0;
    }

    $aniosCompletos = $ingreso->diff($hoy)->y;

    $ultimoAniversario = (clone $ingreso)->modify("+{$aniosCompletos} years");
    $siguienteAniversario = (clone $ultimoAniversario)->modify('+1 year');

    $diasDelTramo = $ultimoAniversario->diff($siguienteAniversario)->days; // 365 o 366 según ese año en concreto
    $diasTranscurridosEnElTramo = $ultimoAniversario->diff($hoy)->days;

    $acumulado = $aniosCompletos * 15 + ($diasTranscurridosEnElTramo / $diasDelTramo) * 15;
    return round($acumulado, 2);
}

/** Redondea a un máximo de 2 decimales, sin ceros sobrantes (15.00 -> "15", 4.10 -> "4.1"). */
function formatearDias(float $dias): string
{
    return rtrim(rtrim(number_format(round($dias, 2), 2, '.', ''), '0'), '.');
}

/** Devuelve el saldo de vacaciones de un empleado, o null si no existe. */
function calcularSaldoVacaciones(int $empleadoId): ?array
{
    $empleado = obtenerEmpleado($empleadoId);
    if (!$empleado) {
        return null;
    }

    $acumulados = calcularDiasAcumulados($empleado['fecha_ingreso']);

    $stmt = obtenerConexion()->prepare('SELECT COALESCE(SUM(dias), 0) FROM vacaciones_tomadas WHERE empleado_id = :id');
    $stmt->execute(['id' => $empleadoId]);
    $tomados = (int) $stmt->fetchColumn();

    $disponibles = round(max(0, $acumulados - $tomados), 2);

    return [
        'empleado_id'      => $empleadoId,
        'nombre_completo'  => $empleado['nombre_completo'],
        'fecha_ingreso'    => $empleado['fecha_ingreso'],
        'dias_acumulados'  => $acumulados,
        'dias_tomados'     => $tomados,
        'dias_disponibles' => $disponibles,
    ];
}

/** Empleados activos que tienen a $jefeEmpleadoId como jefe directo. */
function listarEquipoDe(int $jefeEmpleadoId): array
{
    $stmt = obtenerConexion()->prepare("
        SELECT id, nombre_completo FROM empleado
        WHERE jefe_id = :jefe AND estado = 'activo'
        ORDER BY nombre_completo
    ");
    $stmt->execute(['jefe' => $jefeEmpleadoId]);
    return $stmt->fetchAll();
}

<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-05: consulta de vacaciones disponibles.
 *
 * Fórmula (regla #10 de CLAUDE.md: "vacaciones disponibles (15 días
 * hábiles por año completo)"): por cada año completo trabajado se
 * acumulan 15 días hábiles, de forma proporcional para años
 * incompletos. El tiempo trabajado se calcula con la diferencia real
 * de fechas (DateTime::diff), nunca aproximando meses a 30 días, así
 * que los años bisiestos quedan cubiertos automáticamente.
 *
 * Decisión de diseño (ver resumen para el equipo de documentación): se
 * usa 365.25 como largo de año para la proporción (en vez de 365), para
 * no acumular un sesgo de más de un día cada 4 años por los bisiestos.
 * Los días disponibles nunca son negativos: si ya tomó más de lo
 * acumulado (por ejemplo por un ajuste manual), se muestra 0.
 */

/**
 * Días hábiles acumulados hasta $hoy (por defecto, hoy). $hoy existe
 * para que las pruebas puedan fijar una fecha de referencia conocida.
 */
function calcularDiasAcumulados(string $fechaIngreso, ?DateTimeInterface $hoy = null): int
{
    $ingreso = new DateTime($fechaIngreso);
    $hoy = $hoy instanceof DateTime ? $hoy : new DateTime('today');
    if ($hoy < $ingreso) {
        return 0;
    }
    $diasTrabajados = $ingreso->diff($hoy)->days;
    return (int) floor($diasTrabajados / 365.25 * 15);
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

    return [
        'empleado_id'      => $empleadoId,
        'nombre_completo'  => $empleado['nombre_completo'],
        'fecha_ingreso'    => $empleado['fecha_ingreso'],
        'dias_acumulados'  => $acumulados,
        'dias_tomados'     => $tomados,
        'dias_disponibles' => max(0, $acumulados - $tomados),
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

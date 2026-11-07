<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-04: solicitud y aprobación de permisos.
 *
 * Reglas aplicadas:
 * - Cualquier cuenta solicita permiso para sí misma; queda 'pendiente'.
 * - Solo el jefe directo del solicitante (empleado.jefe_id, no un rol)
 *   puede aprobar o rechazar. Se verifica en servidor comparando el
 *   empleado_id de quien resuelve contra el jefe_id real del solicitante;
 *   el rol de la cuenta no basta por sí solo (cualquier empleado activo
 *   puede ser jefe_id de otro, no solo quienes tienen el rol "Jefe").
 * - Una solicitud ya resuelta (aprobada o rechazada) no se puede volver
 *   a resolver: el UPDATE exige estado = 'pendiente' y se revisa cuántas
 *   filas cambiaron, para que dos resoluciones casi simultáneas no dejen
 *   pasar las dos.
 * - El esquema no tiene una columna para un motivo de rechazo aparte del
 *   motivo original de la solicitud; se deja así (ver resumen para el
 *   equipo de documentación) en vez de modificar la base de datos sin
 *   acordarlo primero.
 */

function listarPermisosDeEmpleado(int $empleadoId): array
{
    $stmt = obtenerConexion()->prepare('
        SELECT id, empleado_id, fecha_solicitud, fecha_inicio, fecha_fin, motivo, estado, fecha_respuesta
        FROM permiso
        WHERE empleado_id = :emp
        ORDER BY fecha_solicitud DESC
    ');
    $stmt->execute(['emp' => $empleadoId]);
    return $stmt->fetchAll();
}

/** Solicitudes de los empleados que tienen a $jefeEmpleadoId como jefe directo. */
function listarPermisosDeEquipo(int $jefeEmpleadoId): array
{
    $stmt = obtenerConexion()->prepare("
        SELECT p.id, p.empleado_id, p.fecha_solicitud, p.fecha_inicio, p.fecha_fin, p.motivo, p.estado, p.fecha_respuesta,
               e.nombre_completo
        FROM permiso p
        JOIN empleado e ON e.id = p.empleado_id
        WHERE e.jefe_id = :jefe
        ORDER BY (p.estado = 'pendiente') DESC, p.fecha_solicitud DESC
    ");
    $stmt->execute(['jefe' => $jefeEmpleadoId]);
    return $stmt->fetchAll();
}

function obtenerPermiso(int $id): ?array
{
    $stmt = obtenerConexion()->prepare('SELECT * FROM permiso WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $fila = $stmt->fetch();
    return $fila ?: null;
}

function solicitarPermiso(int $empleadoId, string $fechaInicio, string $fechaFin, string $motivo): array
{
    $inicio = DateTime::createFromFormat('Y-m-d', $fechaInicio);
    $fin = DateTime::createFromFormat('Y-m-d', $fechaFin);
    if (!$inicio || !$fin) {
        return ['exito' => false, 'mensaje' => 'Indica la fecha de inicio y la fecha de fin.'];
    }
    if ($fin < $inicio) {
        return ['exito' => false, 'mensaje' => 'La fecha de fin no puede ser anterior a la fecha de inicio.'];
    }
    $motivo = trim($motivo);
    if ($motivo === '') {
        return ['exito' => false, 'mensaje' => 'El motivo es obligatorio.'];
    }

    $stmt = obtenerConexion()->prepare('
        INSERT INTO permiso (empleado_id, fecha_inicio, fecha_fin, motivo)
        VALUES (:emp, :inicio, :fin, :motivo)
    ');
    $stmt->execute([
        'emp'    => $empleadoId,
        'inicio' => $fechaInicio,
        'fin'    => $fechaFin,
        'motivo' => $motivo,
    ]);
    return ['exito' => true, 'mensaje' => 'Solicitud enviada. Queda pendiente de aprobación de tu jefe directo.'];
}

/**
 * Aprueba o rechaza una solicitud. $aprobadorEmpleadoId es el empleado_id
 * (no el usuario_id) de quien intenta resolver, para compararlo contra el
 * jefe_id real del solicitante.
 */
function resolverPermiso(int $permisoId, string $decision, int $aprobadorUsuarioId, int $aprobadorEmpleadoId): array
{
    if (!in_array($decision, ['aprobado', 'rechazado'], true)) {
        return ['exito' => false, 'mensaje' => 'Decisión no válida.'];
    }

    $permiso = obtenerPermiso($permisoId);
    if (!$permiso) {
        return ['exito' => false, 'mensaje' => 'Solicitud no encontrada.'];
    }

    $solicitante = obtenerEmpleado((int) $permiso['empleado_id']);
    if (!$solicitante || (int) ($solicitante['jefe_id'] ?? 0) !== $aprobadorEmpleadoId) {
        return ['exito' => false, 'mensaje' => 'Solo el jefe directo del solicitante puede resolver esta solicitud.'];
    }

    $stmt = obtenerConexion()->prepare("
        UPDATE permiso
        SET estado = :estado, aprobador_id = :aprobador, fecha_respuesta = NOW()
        WHERE id = :id AND estado = 'pendiente'
    ");
    $stmt->execute([
        'estado'    => $decision,
        'aprobador' => $aprobadorUsuarioId,
        'id'        => $permisoId,
    ]);

    if ($stmt->rowCount() === 0) {
        return ['exito' => false, 'mensaje' => 'Esta solicitud ya había sido resuelta antes; no se puede resolver de nuevo.'];
    }

    return ['exito' => true, 'mensaje' => 'Solicitud ' . $decision . '.'];
}

<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-07: asignación de tickets a técnicos.
 *
 * El esquema no tiene un estado 'asignado' en ticket.estado (solo
 * 'pendiente'/'en_proceso'/'cerrado': ver da_service_bd.sql); un ticket
 * puede quedar en 'pendiente' con técnico ya asignado hasta que ese
 * técnico registre su primer avance (HU-08). Por eso "asignado" es un
 * estado VISUAL derivado (estadoVisualTicket), no se cambia el esquema.
 *
 * Cada asignación (primera vez o reasignación) es una fila nueva en
 * 'asignacion': eso ya es el historial de quién, cuándo y a quién, sin
 * necesidad de una tabla aparte. El técnico "actual" de un ticket es el
 * de la asignación más reciente (MAX(id) por ticket_id).
 */

function listarTecnicosActivos(): array
{
    $stmt = obtenerConexion()->prepare("
        SELECT u.id AS usuario_id, e.nombre_completo
        FROM usuario u
        JOIN empleado e ON e.id = u.empleado_id
        WHERE u.rol_id = :rol AND u.activo = 1 AND e.estado = 'activo'
        ORDER BY e.nombre_completo
    ");
    $stmt->execute(['rol' => ROL_TECNICO]);
    return $stmt->fetchAll();
}

function listarTickets(): array
{
    return obtenerConexion()->query("
        SELECT t.*,
          (SELECT a.tecnico_id FROM asignacion a WHERE a.ticket_id = t.id ORDER BY a.id DESC LIMIT 1) AS tecnico_actual_id,
          (SELECT e2.nombre_completo
             FROM asignacion a2 JOIN usuario u2 ON u2.id = a2.tecnico_id JOIN empleado e2 ON e2.id = u2.empleado_id
            WHERE a2.ticket_id = t.id ORDER BY a2.id DESC LIMIT 1) AS tecnico_actual_nombre
        FROM ticket t
        ORDER BY (t.estado = 'cerrado'), t.fecha_creacion DESC
    ")->fetchAll();
}

/** Tickets cuya asignación más reciente es este técnico (por usuario_id). */
function listarTicketsAsignadosA(int $tecnicoUsuarioId): array
{
    $stmt = obtenerConexion()->prepare("
        SELECT t.*
        FROM ticket t
        WHERE :tecnico = (SELECT a.tecnico_id FROM asignacion a WHERE a.ticket_id = t.id ORDER BY a.id DESC LIMIT 1)
        ORDER BY (t.estado = 'cerrado'), t.fecha_creacion DESC
    ");
    $stmt->execute(['tecnico' => $tecnicoUsuarioId]);
    return $stmt->fetchAll();
}

function obtenerTicket(int $id): ?array
{
    $stmt = obtenerConexion()->prepare('SELECT * FROM ticket WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $fila = $stmt->fetch();
    return $fila ?: null;
}

/** 'asignado' es un estado visual: el técnico ya tiene el ticket, aunque siga 'pendiente' en la BD hasta su primer avance (HU-08). */
function estadoVisualTicket(array $ticket): string
{
    if ($ticket['estado'] === 'pendiente' && !empty($ticket['tecnico_actual_id'])) {
        return 'asignado';
    }
    return $ticket['estado'];
}

/**
 * Asigna (o reasigna) un ticket a un técnico. Solo se permite si el
 * técnico está activo (usuario.activo=1, empleado.estado='activo') y
 * realmente tiene el rol Técnico; un empleado que no es técnico, o un
 * técnico inactivo, se rechaza.
 */
function asignarTicket(int $ticketId, int $tecnicoUsuarioId, int $coordinadorUsuarioId): array
{
    if (!obtenerTicket($ticketId)) {
        return ['exito' => false, 'mensaje' => 'Ticket no encontrado.'];
    }

    $pdo = obtenerConexion();
    $stmtTecnico = $pdo->prepare("
        SELECT u.id FROM usuario u JOIN empleado e ON e.id = u.empleado_id
        WHERE u.id = :id AND u.rol_id = :rol AND u.activo = 1 AND e.estado = 'activo'
    ");
    $stmtTecnico->execute(['id' => $tecnicoUsuarioId, 'rol' => ROL_TECNICO]);
    if (!$stmtTecnico->fetch()) {
        return ['exito' => false, 'mensaje' => 'Solo se puede asignar a un técnico activo.'];
    }

    $stmt = $pdo->prepare('
        INSERT INTO asignacion (ticket_id, tecnico_id, coordinador_id)
        VALUES (:ticket, :tecnico, :coordinador)
    ');
    $stmt->execute([
        'ticket'      => $ticketId,
        'tecnico'     => $tecnicoUsuarioId,
        'coordinador' => $coordinadorUsuarioId,
    ]);

    return ['exito' => true, 'mensaje' => 'Ticket asignado correctamente.'];
}

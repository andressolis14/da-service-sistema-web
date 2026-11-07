<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-10: historial y estados de clientes.
 * El cliente nunca se elimina: solo cambia de estado, y todo cambio
 * exige un motivo y queda registrado en cliente_estado_historial.
 */

function listarClientes(): array
{
    return obtenerConexion()->query('
        SELECT id, nombre, contacto, telefono, correo, estado, fecha_creacion
        FROM cliente
        ORDER BY nombre
    ')->fetchAll();
}

function obtenerCliente(int $id): ?array
{
    $stmt = obtenerConexion()->prepare('SELECT * FROM cliente WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $fila = $stmt->fetch();
    return $fila ?: null;
}

/** Historial de un cliente en orden cronológico (del cambio más antiguo al más reciente). */
function listarHistorialCliente(int $clienteId): array
{
    $stmt = obtenerConexion()->prepare('
        SELECT h.estado_anterior, h.estado_nuevo, h.motivo, h.fecha, u.nombre_usuario
        FROM cliente_estado_historial h
        JOIN usuario u ON u.id = h.usuario_id
        WHERE h.cliente_id = :id
        ORDER BY h.fecha ASC, h.id ASC
    ');
    $stmt->execute(['id' => $clienteId]);
    return $stmt->fetchAll();
}

/**
 * Cambia el estado de un cliente. Exige un motivo y dicho motivo, junto con
 * quién y cuándo, queda en cliente_estado_historial; el cliente nunca se borra.
 */
function cambiarEstadoCliente(int $id, string $nuevoEstado, string $motivo, int $usuarioId): array
{
    if (!in_array($nuevoEstado, ['prospecto', 'activo', 'inactivo'], true)) {
        return ['exito' => false, 'mensaje' => 'Estado no válido.'];
    }
    $motivo = trim($motivo);
    if ($motivo === '') {
        return ['exito' => false, 'mensaje' => 'El motivo del cambio es obligatorio.'];
    }

    $cliente = obtenerCliente($id);
    if (!$cliente) {
        return ['exito' => false, 'mensaje' => 'Cliente no encontrado.'];
    }
    if ($cliente['estado'] === $nuevoEstado) {
        return ['exito' => false, 'mensaje' => 'El cliente ya está en ese estado.'];
    }

    $pdo = obtenerConexion();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE cliente SET estado = :estado WHERE id = :id');
        $stmt->execute(['estado' => $nuevoEstado, 'id' => $id]);

        $stmtHist = $pdo->prepare('
            INSERT INTO cliente_estado_historial (cliente_id, estado_anterior, estado_nuevo, motivo, usuario_id)
            VALUES (:cliente_id, :anterior, :nuevo, :motivo, :usuario_id)
        ');
        $stmtHist->execute([
            'cliente_id' => $id,
            'anterior'   => $cliente['estado'],
            'nuevo'      => $nuevoEstado,
            'motivo'     => $motivo,
            'usuario_id' => $usuarioId,
        ]);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        return ['exito' => false, 'mensaje' => 'No se pudo registrar el cambio de estado.'];
    }

    return ['exito' => true, 'mensaje' => 'Estado actualizado y registrado en el historial.'];
}

<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-12: registro de interacciones con clientes.
 *
 * Las interacciones son un registro histórico: no se editan ni se
 * borran (ningún UPDATE/DELETE sobre 'interaccion' en todo el código;
 * ni el esquema ni el diseño piden lo contrario).
 */

const INTERACCION_TIPOS = ['llamada', 'correo', 'mensaje', 'visita'];
const INTERACCION_DESCRIPCION_MAXIMA = 65535; // límite real de un TEXT en MySQL

/** Interacciones de un cliente, en orden cronológico (de la más antigua a la más reciente). */
function listarInteraccionesDeCliente(int $clienteId): array
{
    $stmt = obtenerConexion()->prepare('
        SELECT i.*, e.nombre_completo AS vendedor_nombre
        FROM interaccion i
        JOIN usuario u ON u.id = i.vendedor_id
        JOIN empleado e ON e.id = u.empleado_id
        WHERE i.cliente_id = :cliente
        ORDER BY i.fecha ASC, i.id ASC
    ');
    $stmt->execute(['cliente' => $clienteId]);
    return $stmt->fetchAll();
}

/** $fecha en formato de <input type="datetime-local">: AAAA-MM-DDTHH:MM. */
function registrarInteraccion(int $clienteId, int $vendedorUsuarioId, string $fecha, string $tipo, string $descripcion): array
{
    if (!obtenerCliente($clienteId)) {
        return ['exito' => false, 'mensaje' => 'El cliente no existe.'];
    }
    if (!in_array($tipo, INTERACCION_TIPOS, true)) {
        return ['exito' => false, 'mensaje' => 'El tipo de interacción no es válido.'];
    }

    $fechaDt = DateTime::createFromFormat('Y-m-d\TH:i', $fecha);
    if (!$fechaDt) {
        $fechaDt = DateTime::createFromFormat('Y-m-d H:i', $fecha);
    }
    if (!$fechaDt) {
        return ['exito' => false, 'mensaje' => 'La fecha no es válida.'];
    }
    if ($fechaDt > new DateTime()) {
        return ['exito' => false, 'mensaje' => 'La fecha de la interacción no puede ser futura.'];
    }

    $descripcion = trim($descripcion);
    if ($descripcion === '') {
        return ['exito' => false, 'mensaje' => 'La descripción es obligatoria.'];
    }
    if (mb_strlen($descripcion) > INTERACCION_DESCRIPCION_MAXIMA) {
        return ['exito' => false, 'mensaje' => 'La descripción es demasiado larga.'];
    }

    $stmt = obtenerConexion()->prepare('
        INSERT INTO interaccion (cliente_id, vendedor_id, fecha, tipo, descripcion)
        VALUES (:cliente, :vendedor, :fecha, :tipo, :descripcion)
    ');
    $stmt->execute([
        'cliente'     => $clienteId,
        'vendedor'    => $vendedorUsuarioId,
        'fecha'       => $fechaDt->format('Y-m-d H:i:s'),
        'tipo'        => $tipo,
        'descripcion' => $descripcion,
    ]);

    return ['exito' => true, 'mensaje' => 'Interacción registrada.'];
}

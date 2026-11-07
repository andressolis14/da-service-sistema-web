<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-11: seguimiento de oportunidades comerciales.
 *
 * Esquema actual (sin cambios, acordado con el equipo de documentación:
 * no se agrega historial de etapas por ahora, queda como mejora futura).
 * 'motivo_perdida' ya existe en 'oportunidad' y el propio esquema exige
 * con un CHECK que no esté vacío cuando la etapa es 'perdido'; aquí se
 * valida lo mismo en la aplicación, para dar un mensaje claro antes de
 * llegar a la base de datos.
 *
 * DT-20: la moneda permitida es únicamente 'COP' (el esquema no define
 * una lista con ENUM/CHECK, solo lo dice el comentario de la columna;
 * se deja fijo en COP hasta que se acuerde admitir otras).
 */

const OPORTUNIDAD_ETAPAS = ['calificado', 'propuesta', 'negociacion', 'aprobada', 'ganado', 'pendiente', 'perdido'];
const OPORTUNIDAD_MONEDA_PERMITIDA = 'COP';

function listarOportunidades(): array
{
    return obtenerConexion()->query('
        SELECT o.*, c.nombre AS cliente_nombre, e.nombre_completo AS vendedor_nombre
        FROM oportunidad o
        JOIN cliente c ON c.id = o.cliente_id
        JOIN usuario u ON u.id = o.vendedor_id
        JOIN empleado e ON e.id = u.empleado_id
        ORDER BY o.fecha_creacion DESC
    ')->fetchAll();
}

function obtenerOportunidad(int $id): ?array
{
    $stmt = obtenerConexion()->prepare('SELECT * FROM oportunidad WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $fila = $stmt->fetch();
    return $fila ?: null;
}

function listarVendedoresActivos(): array
{
    $stmt = obtenerConexion()->prepare("
        SELECT u.id AS usuario_id, e.nombre_completo
        FROM usuario u
        JOIN empleado e ON e.id = u.empleado_id
        WHERE u.rol_id = :rol AND u.activo = 1 AND e.estado = 'activo'
        ORDER BY e.nombre_completo
    ");
    $stmt->execute(['rol' => ROL_VENDEDOR]);
    return $stmt->fetchAll();
}

function crearOportunidad(int $clienteId, int $vendedorUsuarioId, string $descripcion, string $valorEstimado, string $moneda): array
{
    if (!obtenerCliente($clienteId)) {
        return ['exito' => false, 'mensaje' => 'El cliente no existe.'];
    }
    $descripcion = trim($descripcion);
    if ($descripcion === '') {
        return ['exito' => false, 'mensaje' => 'Describe qué se le está ofreciendo al cliente.'];
    }
    if (!is_numeric($valorEstimado) || (float) $valorEstimado < 0) {
        return ['exito' => false, 'mensaje' => 'El valor estimado debe ser un número mayor o igual a cero.'];
    }
    if ($moneda !== OPORTUNIDAD_MONEDA_PERMITIDA) {
        return ['exito' => false, 'mensaje' => 'Por ahora solo se admite la moneda COP.'];
    }

    $stmt = obtenerConexion()->prepare('
        INSERT INTO oportunidad (cliente_id, vendedor_id, descripcion, valor_estimado, moneda)
        VALUES (:cliente, :vendedor, :descripcion, :valor, :moneda)
    ');
    $stmt->execute([
        'cliente'     => $clienteId,
        'vendedor'    => $vendedorUsuarioId,
        'descripcion' => $descripcion,
        'valor'       => (float) $valorEstimado,
        'moneda'      => $moneda,
    ]);

    return ['exito' => true, 'mensaje' => 'Oportunidad creada.'];
}

/** Cambia la etapa de una oportunidad. 'perdido' exige un motivo. */
function cambiarEtapaOportunidad(int $id, string $nuevaEtapa, string $motivoPerdida): array
{
    if (!in_array($nuevaEtapa, OPORTUNIDAD_ETAPAS, true)) {
        return ['exito' => false, 'mensaje' => 'Etapa no válida.'];
    }
    if (!obtenerOportunidad($id)) {
        return ['exito' => false, 'mensaje' => 'Oportunidad no encontrada.'];
    }

    $pdo = obtenerConexion();
    if ($nuevaEtapa === 'perdido') {
        $motivo = trim($motivoPerdida);
        if ($motivo === '') {
            return ['exito' => false, 'mensaje' => 'Indica el motivo de la pérdida.'];
        }
        $stmt = $pdo->prepare("UPDATE oportunidad SET etapa = 'perdido', motivo_perdida = :motivo WHERE id = :id");
        $stmt->execute(['motivo' => $motivo, 'id' => $id]);
    } else {
        $stmt = $pdo->prepare('UPDATE oportunidad SET etapa = :etapa, motivo_perdida = NULL WHERE id = :id');
        $stmt->execute(['etapa' => $nuevaEtapa, 'id' => $id]);
    }

    return ['exito' => true, 'mensaje' => 'Etapa actualizada.'];
}

<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-06: registrar un ticket de soporte desde el formulario público.
 */

/**
 * Crea un ticket. Devuelve ['exito'=>bool, 'mensaje'=>string, 'id'=>?int].
 * No acepta el ticket si no se aceptó el aviso de tratamiento de datos
 * (RNF-03): la fecha del aviso se guarda en el mismo momento del envío.
 */
function crearTicketPublico(array $datos): array
{
    $nombre      = trim($datos['solicitante_nombre'] ?? '');
    $correo      = trim($datos['solicitante_correo'] ?? '');
    $telefono    = trim($datos['solicitante_telefono'] ?? '');
    $tipo        = $datos['solicitante_tipo'] ?? '';
    $asunto      = trim($datos['asunto'] ?? '');
    $descripcion = trim($datos['descripcion'] ?? '');
    $avisoAceptado = !empty($datos['aviso_aceptado']);

    if ($nombre === '' || $correo === '' || $asunto === '' || $descripcion === '') {
        return ['exito' => false, 'mensaje' => 'Completa nombre, correo, asunto y descripción.', 'id' => null];
    }
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        return ['exito' => false, 'mensaje' => 'El correo no es válido.', 'id' => null];
    }
    if (!in_array($tipo, ['interno', 'externo'], true)) {
        return ['exito' => false, 'mensaje' => 'Indica si eres empleado (interno) o cliente (externo).', 'id' => null];
    }
    if (!$avisoAceptado) {
        return ['exito' => false, 'mensaje' => 'Debes aceptar el aviso de tratamiento de datos para continuar.', 'id' => null];
    }

    $pdo = obtenerConexion();
    $stmt = $pdo->prepare('
        INSERT INTO ticket (
            solicitante_nombre, solicitante_correo, solicitante_telefono, solicitante_tipo,
            asunto, descripcion, estado, aviso_aceptado, fecha_aviso
        ) VALUES (
            :nombre, :correo, :telefono, :tipo,
            :asunto, :descripcion, \'pendiente\', 1, NOW()
        )
    ');
    $stmt->execute([
        'nombre'      => $nombre,
        'correo'      => $correo,
        'telefono'    => $telefono !== '' ? $telefono : null,
        'tipo'        => $tipo,
        'asunto'      => $asunto,
        'descripcion' => $descripcion,
    ]);

    return ['exito' => true, 'mensaje' => 'Solicitud recibida.', 'id' => (int) $pdo->lastInsertId()];
}

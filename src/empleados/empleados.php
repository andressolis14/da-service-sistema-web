<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-01: registro y administración de empleados.
 * El empleado nunca se elimina: solo cambia entre 'activo' e 'inactivo'.
 */

function listarAreas(): array
{
    return obtenerConexion()->query('SELECT id, nombre FROM area ORDER BY nombre')->fetchAll();
}

/** Posibles jefes: cualquier empleado activo, excepto el propio (al editar). */
function listarPosiblesJefes(?int $excluirId = null): array
{
    $pdo = obtenerConexion();
    if ($excluirId) {
        $stmt = $pdo->prepare("SELECT id, nombre_completo FROM empleado WHERE estado = 'activo' AND id <> :id ORDER BY nombre_completo");
        $stmt->execute(['id' => $excluirId]);
    } else {
        $stmt = $pdo->query("SELECT id, nombre_completo FROM empleado WHERE estado = 'activo' ORDER BY nombre_completo");
    }
    return $stmt->fetchAll();
}

function listarEmpleados(): array
{
    return obtenerConexion()->query('
        SELECT e.id, e.nombre_completo, e.documento, e.correo_personal, e.cargo, e.fecha_ingreso, e.estado,
               a.nombre AS area_nombre, j.nombre_completo AS jefe_nombre
        FROM empleado e
        JOIN area a ON a.id = e.area_id
        LEFT JOIN empleado j ON j.id = e.jefe_id
        ORDER BY e.estado = \'inactivo\', e.nombre_completo
    ')->fetchAll();
}

function obtenerEmpleado(int $id): ?array
{
    $stmt = obtenerConexion()->prepare('SELECT * FROM empleado WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $fila = $stmt->fetch();
    return $fila ?: null;
}

/** Valida los campos comunes a crear/editar. Devuelve un mensaje de error o '' si todo está bien. */
function validarDatosEmpleado(array $datos, ?int $idEditado = null): string
{
    if (trim($datos['nombre_completo'] ?? '') === '') return 'El nombre completo es obligatorio.';
    if (trim($datos['documento'] ?? '') === '') return 'El documento es obligatorio.';
    if (trim($datos['correo_personal'] ?? '') === '' || !filter_var($datos['correo_personal'], FILTER_VALIDATE_EMAIL)) {
        return 'El correo personal no es válido.';
    }
    if (trim($datos['cargo'] ?? '') === '') return 'El cargo es obligatorio.';
    if (empty($datos['area_id'])) return 'Selecciona un área.';
    if (empty($datos['fecha_ingreso'])) return 'La fecha de ingreso es obligatoria.';
    if (!empty($datos['jefe_id']) && $idEditado !== null && (int) $datos['jefe_id'] === $idEditado) {
        return 'Un empleado no puede ser su propio jefe.';
    }
    return '';
}

function crearEmpleado(array $datos): array
{
    $error = validarDatosEmpleado($datos);
    if ($error !== '') {
        return ['exito' => false, 'mensaje' => $error];
    }

    $pdo = obtenerConexion();

    $stmtDoc = $pdo->prepare('SELECT id FROM empleado WHERE documento = :documento');
    $stmtDoc->execute(['documento' => trim($datos['documento'])]);
    if ($stmtDoc->fetch()) {
        return ['exito' => false, 'mensaje' => 'Ya existe un empleado con ese documento.'];
    }

    try {
        $stmt = $pdo->prepare('
            INSERT INTO empleado (nombre_completo, documento, correo_personal, cargo, area_id, jefe_id, fecha_ingreso, estado)
            VALUES (:nombre_completo, :documento, :correo_personal, :cargo, :area_id, :jefe_id, :fecha_ingreso, \'activo\')
        ');
        $stmt->execute([
            'nombre_completo'  => trim($datos['nombre_completo']),
            'documento'        => trim($datos['documento']),
            'correo_personal'  => trim($datos['correo_personal']),
            'cargo'            => trim($datos['cargo']),
            'area_id'          => (int) $datos['area_id'],
            'jefe_id'          => !empty($datos['jefe_id']) ? (int) $datos['jefe_id'] : null,
            'fecha_ingreso'    => $datos['fecha_ingreso'],
        ]);
        return ['exito' => true, 'mensaje' => 'Empleado creado correctamente.'];
    } catch (PDOException $e) {
        return ['exito' => false, 'mensaje' => 'No se pudo crear el empleado.'];
    }
}

function actualizarEmpleado(int $id, array $datos): array
{
    $error = validarDatosEmpleado($datos, $id);
    if ($error !== '') {
        return ['exito' => false, 'mensaje' => $error];
    }

    $pdo = obtenerConexion();

    $stmtDoc = $pdo->prepare('SELECT id FROM empleado WHERE documento = :documento AND id <> :id');
    $stmtDoc->execute(['documento' => trim($datos['documento']), 'id' => $id]);
    if ($stmtDoc->fetch()) {
        return ['exito' => false, 'mensaje' => 'Ya existe otro empleado con ese documento.'];
    }

    $stmt = $pdo->prepare('
        UPDATE empleado SET
            nombre_completo = :nombre_completo, documento = :documento, correo_personal = :correo_personal,
            cargo = :cargo, area_id = :area_id, jefe_id = :jefe_id, fecha_ingreso = :fecha_ingreso
        WHERE id = :id
    ');
    $stmt->execute([
        'nombre_completo'  => trim($datos['nombre_completo']),
        'documento'        => trim($datos['documento']),
        'correo_personal'  => trim($datos['correo_personal']),
        'cargo'            => trim($datos['cargo']),
        'area_id'          => (int) $datos['area_id'],
        'jefe_id'          => !empty($datos['jefe_id']) ? (int) $datos['jefe_id'] : null,
        'fecha_ingreso'    => $datos['fecha_ingreso'],
        'id'               => $id,
    ]);
    return ['exito' => true, 'mensaje' => 'Empleado actualizado correctamente.'];
}

/** Activa o desactiva un empleado. El registro y su historial nunca se borran. */
function cambiarEstadoEmpleado(int $id, string $nuevoEstado): array
{
    if (!in_array($nuevoEstado, ['activo', 'inactivo'], true)) {
        return ['exito' => false, 'mensaje' => 'Estado no válido.'];
    }
    $pdo = obtenerConexion();

    // Si se inactiva, nadie puede seguir teniéndolo como jefe directo.
    if ($nuevoEstado === 'inactivo') {
        $stmtJefe = $pdo->prepare('SELECT COUNT(*) FROM empleado WHERE jefe_id = :id');
        $stmtJefe->execute(['id' => $id]);
        if ((int) $stmtJefe->fetchColumn() > 0) {
            return ['exito' => false, 'mensaje' => 'No se puede inactivar: todavía es el jefe directo de otros empleados activos.'];
        }
    }

    $stmt = $pdo->prepare('UPDATE empleado SET estado = :estado WHERE id = :id');
    $stmt->execute(['estado' => $nuevoEstado, 'id' => $id]);
    return ['exito' => true, 'mensaje' => 'Estado actualizado.'];
}

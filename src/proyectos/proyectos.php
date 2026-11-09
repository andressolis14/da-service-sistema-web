<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-16: seguimiento de proyectos en ejecución.
 *
 * "Terminado" no es una columna propia de 'proyecto': se representa con
 * oportunidad.etapa = 'ganado' (confirmado por la consulta de
 * comprobación C12 del script: un proyecto cuya oportunidad no está en
 * 'aprobada' ni en 'ganado' no debería existir nunca). Por eso
 * "terminar" un proyecto es, en la base de datos, cambiar la etapa de
 * su oportunidad a 'ganado'.
 *
 * Atraso (definición acordada con el equipo de documentación, igual a
 * la consulta C11 del script): un proyecto está atrasado si tiene
 * algún pendiente con completado=0 y fecha_compromiso vencida, y su
 * oportunidad todavía está en 'aprobada'. Se calcula siempre por
 * consulta, nunca se guarda. DT-22: no se calcula "terminó tarde"
 * (no hay en el esquema una fecha real de término que lo permita);
 * queda como mejora futura conocida.
 */

/**
 * El responsable se restringe a los mismos roles que tienen acceso a
 * esta pantalla (Vendedor, Gerente, Administrador): si se permitiera
 * cualquier empleado activo, se podría asignar a alguien (por ejemplo
 * un Empleado o un Técnico) que luego nunca podría entrar a actualizar
 * su propio proyecto, porque toda la página usa exigirSesion() con
 * esos tres roles.
 */
function listarPosiblesResponsables(): array
{
    $stmt = obtenerConexion()->prepare("
        SELECT u.id AS usuario_id, e.nombre_completo
        FROM usuario u JOIN empleado e ON e.id = u.empleado_id
        WHERE u.activo = 1 AND e.estado = 'activo' AND u.rol_id IN (:vendedor, :gerente, :admin)
        ORDER BY e.nombre_completo
    ");
    $stmt->execute(['vendedor' => ROL_VENDEDOR, 'gerente' => ROL_GERENTE, 'admin' => ROL_ADMINISTRADOR]);
    return $stmt->fetchAll();
}

function validarResponsableActivo(int $usuarioId): bool
{
    $stmt = obtenerConexion()->prepare("
        SELECT 1 FROM usuario u JOIN empleado e ON e.id = u.empleado_id
        WHERE u.id = :id AND u.activo = 1 AND e.estado = 'activo' AND u.rol_id IN (:vendedor, :gerente, :admin)
    ");
    $stmt->execute(['id' => $usuarioId, 'vendedor' => ROL_VENDEDOR, 'gerente' => ROL_GERENTE, 'admin' => ROL_ADMINISTRADOR]);
    return (bool) $stmt->fetchColumn();
}

/** Oportunidades en etapa 'aprobada' que todavía no tienen proyecto: las únicas desde las que se puede crear uno. */
function listarOportunidadesAprobadasSinProyecto(): array
{
    return obtenerConexion()->query("
        SELECT o.id, o.descripcion, c.nombre AS cliente_nombre
        FROM oportunidad o
        JOIN cliente c ON c.id = o.cliente_id
        WHERE o.etapa = 'aprobada'
          AND NOT EXISTS (SELECT 1 FROM proyecto p WHERE p.oportunidad_id = o.id)
        ORDER BY o.fecha_creacion
    ")->fetchAll();
}

function obtenerProyectoDeOportunidad(int $oportunidadId): ?array
{
    $stmt = obtenerConexion()->prepare('SELECT id FROM proyecto WHERE oportunidad_id = :id');
    $stmt->execute(['id' => $oportunidadId]);
    $fila = $stmt->fetch();
    return $fila ?: null;
}

/** 'atrasado' se calcula aquí mismo con la definición de C11; nunca se guarda. */
function listarProyectos(): array
{
    return obtenerConexion()->query("
        SELECT pr.*, o.etapa AS oportunidad_etapa, o.descripcion AS oportunidad_descripcion,
               c.nombre AS cliente_nombre, e.nombre_completo AS responsable_nombre,
               (o.etapa = 'aprobada' AND EXISTS (
                   SELECT 1 FROM pendiente_proyecto pp
                   WHERE pp.proyecto_id = pr.id AND pp.completado = 0 AND pp.fecha_compromiso < CURDATE()
               )) AS atrasado
        FROM proyecto pr
        JOIN oportunidad o ON o.id = pr.oportunidad_id
        JOIN cliente c ON c.id = pr.cliente_id
        JOIN usuario u ON u.id = pr.responsable_id
        JOIN empleado e ON e.id = u.empleado_id
        ORDER BY pr.fecha_inicio DESC
    ")->fetchAll();
}

function obtenerProyecto(int $id): ?array
{
    $stmt = obtenerConexion()->prepare("
        SELECT pr.*, o.etapa AS oportunidad_etapa, o.descripcion AS oportunidad_descripcion,
               c.nombre AS cliente_nombre, e.nombre_completo AS responsable_nombre,
               (o.etapa = 'aprobada' AND EXISTS (
                   SELECT 1 FROM pendiente_proyecto pp
                   WHERE pp.proyecto_id = pr.id AND pp.completado = 0 AND pp.fecha_compromiso < CURDATE()
               )) AS atrasado
        FROM proyecto pr
        JOIN oportunidad o ON o.id = pr.oportunidad_id
        JOIN cliente c ON c.id = pr.cliente_id
        JOIN usuario u ON u.id = pr.responsable_id
        JOIN empleado e ON e.id = u.empleado_id
        WHERE pr.id = :id
    ");
    $stmt->execute(['id' => $id]);
    $fila = $stmt->fetch();
    return $fila ?: null;
}

function crearProyecto(int $oportunidadId, int $responsableUsuarioId, string $fechaInicio, string $fechaCompromiso): array
{
    $oportunidad = obtenerOportunidad($oportunidadId);
    if (!$oportunidad) {
        return ['exito' => false, 'mensaje' => 'La oportunidad no existe.'];
    }
    if ($oportunidad['etapa'] !== 'aprobada') {
        return ['exito' => false, 'mensaje' => 'Solo se puede crear un proyecto desde una oportunidad en etapa aprobada.'];
    }
    if (obtenerProyectoDeOportunidad($oportunidadId)) {
        return ['exito' => false, 'mensaje' => 'Esta oportunidad ya tiene un proyecto. Solo se admite uno por oportunidad.'];
    }
    if (!validarResponsableActivo($responsableUsuarioId)) {
        return ['exito' => false, 'mensaje' => 'El responsable debe ser un empleado activo con rol de Vendedor, Gerente o Administrador.'];
    }
    $inicio = DateTime::createFromFormat('Y-m-d', $fechaInicio);
    $compromiso = DateTime::createFromFormat('Y-m-d', $fechaCompromiso);
    if (!$inicio || !$compromiso) {
        return ['exito' => false, 'mensaje' => 'Las fechas no son válidas.'];
    }
    if ($compromiso < $inicio) {
        return ['exito' => false, 'mensaje' => 'La fecha de entrega comprometida no puede ser anterior a la fecha de inicio.'];
    }

    $stmt = obtenerConexion()->prepare('
        INSERT INTO proyecto (oportunidad_id, cliente_id, responsable_id, fecha_inicio, fecha_compromiso)
        VALUES (:oportunidad, :cliente, :responsable, :inicio, :compromiso)
    ');
    $stmt->execute([
        'oportunidad' => $oportunidadId,
        'cliente'     => $oportunidad['cliente_id'],
        'responsable' => $responsableUsuarioId,
        'inicio'      => $fechaInicio,
        'compromiso'  => $fechaCompromiso,
    ]);

    return ['exito' => true, 'mensaje' => 'Proyecto creado.'];
}

/**
 * "Termina" el proyecto: pasa su oportunidad a 'ganado'. Solo se
 * permite si la oportunidad sigue en 'aprobada' (el WHERE etapa =
 * 'aprobada' del UPDATE, con rowCount(), cubre también la carrera de
 * dos personas terminándolo a la vez). Si quedan pendientes sin
 * completar, no se bloquea: se avisa en el mensaje de éxito.
 */
function terminarProyecto(int $proyectoId): array
{
    $proyecto = obtenerProyecto($proyectoId);
    if (!$proyecto) {
        return ['exito' => false, 'mensaje' => 'Proyecto no encontrado.'];
    }
    if ($proyecto['oportunidad_etapa'] !== 'aprobada') {
        return ['exito' => false, 'mensaje' => 'Solo se puede terminar un proyecto cuya oportunidad está en etapa aprobada.'];
    }

    $pdo = obtenerConexion();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE oportunidad SET etapa = 'ganado', motivo_perdida = NULL WHERE id = :id AND etapa = 'aprobada'");
        $stmt->execute(['id' => $proyecto['oportunidad_id']]);
        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            return ['exito' => false, 'mensaje' => 'Solo se puede terminar un proyecto cuya oportunidad está en etapa aprobada.'];
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        return ['exito' => false, 'mensaje' => 'No se pudo terminar el proyecto.'];
    }

    $stmtPendientes = $pdo->prepare('SELECT COUNT(*) FROM pendiente_proyecto WHERE proyecto_id = :id AND completado = 0');
    $stmtPendientes->execute(['id' => $proyectoId]);
    $pendientesSinCompletar = (int) $stmtPendientes->fetchColumn();

    $mensaje = 'Proyecto terminado. La oportunidad pasó a ganado.';
    if ($pendientesSinCompletar > 0) {
        $mensaje .= " Aviso: quedaron $pendientesSinCompletar pendiente(s) sin completar.";
    }

    return ['exito' => true, 'mensaje' => $mensaje];
}

function listarPendientesDeProyecto(int $proyectoId): array
{
    $stmt = obtenerConexion()->prepare('SELECT * FROM pendiente_proyecto WHERE proyecto_id = :id ORDER BY fecha_compromiso ASC');
    $stmt->execute(['id' => $proyectoId]);
    return $stmt->fetchAll();
}

function agregarPendienteProyecto(int $proyectoId, string $descripcion, string $fechaCompromiso): array
{
    if (!obtenerProyecto($proyectoId)) {
        return ['exito' => false, 'mensaje' => 'Proyecto no encontrado.'];
    }
    $descripcion = trim($descripcion);
    if ($descripcion === '') {
        return ['exito' => false, 'mensaje' => 'La descripción del pendiente es obligatoria.'];
    }
    if (mb_strlen($descripcion) > 255) {
        return ['exito' => false, 'mensaje' => 'La descripción es demasiado larga (máximo 255 caracteres).'];
    }
    if (!DateTime::createFromFormat('Y-m-d', $fechaCompromiso)) {
        return ['exito' => false, 'mensaje' => 'La fecha del pendiente no es válida.'];
    }

    $stmt = obtenerConexion()->prepare('
        INSERT INTO pendiente_proyecto (proyecto_id, descripcion, fecha_compromiso, completado)
        VALUES (:proyecto, :descripcion, :fecha, 0)
    ');
    $stmt->execute(['proyecto' => $proyectoId, 'descripcion' => $descripcion, 'fecha' => $fechaCompromiso]);

    return ['exito' => true, 'mensaje' => 'Pendiente agregado.'];
}

function marcarPendienteCompletado(int $pendienteId): array
{
    $stmt = obtenerConexion()->prepare('UPDATE pendiente_proyecto SET completado = 1 WHERE id = :id');
    $stmt->execute(['id' => $pendienteId]);
    return ['exito' => true, 'mensaje' => 'Pendiente marcado como completado.'];
}

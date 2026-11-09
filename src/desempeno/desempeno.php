<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-13: registro de desempeño comercial (desempeño diario y
 * recordatorios del vendedor). No es el módulo de indicadores/reportes
 * (eso es HU-14).
 *
 * Decisión de diseño: un registro duplicado del mismo vendedor y día
 * se RECHAZA (no se reemplaza). El esquema ya lo impide con
 * uq_desempeno_diario_vendedor_id_fecha; aquí se valida antes, para
 * dar un mensaje claro. Se eligió rechazar y no reemplazar para que un
 * reingreso accidental del mismo día no borre silenciosamente datos ya
 * cargados (a diferencia de HU-02, donde reemplazar un PDF corrige un
 * archivo cargado por error; aquí serían números de un día de trabajo).
 */

function registrarDesempenoDiario(int $vendedorUsuarioId, string $fecha, array $datos): array
{
    $fechaDt = DateTime::createFromFormat('Y-m-d', $fecha);
    if (!$fechaDt) {
        return ['exito' => false, 'mensaje' => 'La fecha no es válida.'];
    }
    if ($fechaDt > new DateTime('today')) {
        return ['exito' => false, 'mensaje' => 'La fecha no puede ser futura.'];
    }

    $campos = ['llamadas_totales', 'llamadas_contestadas', 'buzon', 'interesados', 'no_interesados', 'pendientes_volver_llamar', 'portafolios_enviados'];
    $valores = [];
    foreach ($campos as $campo) {
        $valor = $datos[$campo] ?? '';
        if (!ctype_digit((string) $valor)) {
            return ['exito' => false, 'mensaje' => 'Todas las cantidades deben ser números enteros, no negativos.'];
        }
        $valores[$campo] = (int) $valor;
    }
    if ($valores['llamadas_contestadas'] > $valores['llamadas_totales']) {
        return ['exito' => false, 'mensaje' => 'Las llamadas contestadas no pueden ser más que el total de llamadas.'];
    }

    $pdo = obtenerConexion();
    $stmtExistente = $pdo->prepare('SELECT id FROM desempeno_diario WHERE vendedor_id = :vendedor AND fecha = :fecha');
    $stmtExistente->execute(['vendedor' => $vendedorUsuarioId, 'fecha' => $fecha]);
    if ($stmtExistente->fetch()) {
        return ['exito' => false, 'mensaje' => 'Ya existe un registro de desempeño para ese día. No se puede duplicar.'];
    }

    $stmt = $pdo->prepare('
        INSERT INTO desempeno_diario (
            vendedor_id, fecha, llamadas_totales, llamadas_contestadas, buzon,
            interesados, no_interesados, pendientes_volver_llamar, portafolios_enviados
        ) VALUES (
            :vendedor, :fecha, :llamadas_totales, :llamadas_contestadas, :buzon,
            :interesados, :no_interesados, :pendientes_volver_llamar, :portafolios_enviados
        )
    ');
    $stmt->execute(array_merge(['vendedor' => $vendedorUsuarioId, 'fecha' => $fecha], $valores));

    return ['exito' => true, 'mensaje' => 'Desempeño del día registrado.'];
}

function listarDesempenoDeVendedor(int $vendedorUsuarioId): array
{
    $stmt = obtenerConexion()->prepare('SELECT * FROM desempeno_diario WHERE vendedor_id = :vendedor ORDER BY fecha DESC');
    $stmt->execute(['vendedor' => $vendedorUsuarioId]);
    return $stmt->fetchAll();
}

function listarDesempenoDeTodos(): array
{
    return obtenerConexion()->query('
        SELECT d.*, e.nombre_completo AS vendedor_nombre
        FROM desempeno_diario d
        JOIN usuario u ON u.id = d.vendedor_id
        JOIN empleado e ON e.id = u.empleado_id
        ORDER BY d.fecha DESC, e.nombre_completo
    ')->fetchAll();
}

/** Nota y fecha obligatorias; la fecha del recordatorio no puede ser pasada al crearlo. */
function registrarRecordatorio(int $vendedorUsuarioId, int $clienteId, string $nota, string $fechaRecordatorio): array
{
    if (!obtenerCliente($clienteId)) {
        return ['exito' => false, 'mensaje' => 'El cliente no existe.'];
    }
    $nota = trim($nota);
    if ($nota === '') {
        return ['exito' => false, 'mensaje' => 'La nota del recordatorio es obligatoria.'];
    }
    if (mb_strlen($nota) > 255) {
        return ['exito' => false, 'mensaje' => 'La nota es demasiado larga (máximo 255 caracteres).'];
    }
    $fechaDt = DateTime::createFromFormat('Y-m-d', $fechaRecordatorio);
    if (!$fechaDt) {
        return ['exito' => false, 'mensaje' => 'La fecha del recordatorio no es válida.'];
    }
    if ($fechaDt < new DateTime('today')) {
        return ['exito' => false, 'mensaje' => 'La fecha del recordatorio no puede estar en el pasado.'];
    }

    $stmt = obtenerConexion()->prepare('
        INSERT INTO recordatorio (vendedor_id, cliente_id, nota, fecha_recordatorio)
        VALUES (:vendedor, :cliente, :nota, :fecha)
    ');
    $stmt->execute([
        'vendedor' => $vendedorUsuarioId,
        'cliente'  => $clienteId,
        'nota'     => $nota,
        'fecha'    => $fechaRecordatorio,
    ]);

    return ['exito' => true, 'mensaje' => 'Recordatorio registrado.'];
}

/** Recordatorios del vendedor, ordenados por fecha (igual que la consulta C15 del script de la base de datos). */
function listarRecordatoriosDeVendedor(int $vendedorUsuarioId): array
{
    $stmt = obtenerConexion()->prepare('
        SELECT r.*, c.nombre AS cliente_nombre
        FROM recordatorio r
        JOIN cliente c ON c.id = r.cliente_id
        WHERE r.vendedor_id = :vendedor
        ORDER BY r.fecha_recordatorio ASC
    ');
    $stmt->execute(['vendedor' => $vendedorUsuarioId]);
    return $stmt->fetchAll();
}

function listarRecordatoriosDeTodos(): array
{
    return obtenerConexion()->query('
        SELECT r.*, c.nombre AS cliente_nombre, e.nombre_completo AS vendedor_nombre
        FROM recordatorio r
        JOIN cliente c ON c.id = r.cliente_id
        JOIN usuario u ON u.id = r.vendedor_id
        JOIN empleado e ON e.id = u.empleado_id
        ORDER BY r.fecha_recordatorio ASC
    ')->fetchAll();
}

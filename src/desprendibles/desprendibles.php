<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-02: carga y consulta de desprendibles de nómina.
 *
 * Reglas aplicadas:
 * - Solo Contabilidad y Administrador cargan (verificado en la pantalla, no aquí).
 * - El archivo se valida por contenido (firma %PDF-), no solo por extensión,
 *   y se guarda con un nombre generado a partir de empleado y periodo, nunca
 *   el nombre original que trae el navegador.
 * - Máximo 6 desprendibles por empleado: al cargar el 7.º se borra el más
 *   antiguo (registro y archivo), dentro de una transacción de BD. El borrado
 *   del archivo físico es siempre posterior al commit de la BD (y "mejor
 *   esfuerzo"): si falla, queda un archivo huérfano en disco, pero la base
 *   de datos ya quedó consistente de todas formas.
 * - Un periodo repetido para el mismo empleado reemplaza el desprendible
 *   existente (se acordó con el equipo de documentación), en vez de
 *   rechazarse: evita que contabilidad tenga que gestionar el borrado del
 *   anterior a mano si cargó el PDF equivocado.
 */

const DESPRENDIBLE_TAMANO_MAXIMO = 5 * 1024 * 1024; // 5 MB
const DESPRENDIBLE_MAXIMO_POR_EMPLEADO = 6;

function carpetaDesprendiblesEmpleado(int $empleadoId): string
{
    return __DIR__ . '/../../storage/desprendibles/' . $empleadoId;
}

function listarDesprendiblesDeEmpleado(int $empleadoId): array
{
    $stmt = obtenerConexion()->prepare('
        SELECT id, empleado_id, periodo, fecha_carga
        FROM desprendible
        WHERE empleado_id = :id
        ORDER BY periodo DESC
    ');
    $stmt->execute(['id' => $empleadoId]);
    return $stmt->fetchAll();
}

function obtenerDesprendible(int $id): ?array
{
    $stmt = obtenerConexion()->prepare('SELECT * FROM desprendible WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $fila = $stmt->fetch();
    return $fila ?: null;
}

function periodoValido(string $periodo): bool
{
    return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo);
}

/** Lee los primeros bytes del archivo subido y confirma la firma real de un PDF. */
function archivoEsPdfValido(string $rutaTemporal): bool
{
    $f = fopen($rutaTemporal, 'rb');
    if (!$f) {
        return false;
    }
    $cabecera = fread($f, 5);
    fclose($f);
    return $cabecera === '%PDF-';
}

/**
 * Carga (o reemplaza) el desprendible de un empleado para un periodo.
 * $archivo es la entrada cruda de $_FILES['archivo'].
 */
function cargarDesprendible(int $empleadoId, string $periodo, array $archivo, int $usuarioId): array
{
    if (!obtenerEmpleado($empleadoId)) {
        return ['exito' => false, 'mensaje' => 'El empleado no existe.'];
    }
    if (!periodoValido($periodo)) {
        return ['exito' => false, 'mensaje' => 'El periodo debe tener el formato AAAA-MM.'];
    }
    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['exito' => false, 'mensaje' => 'No se pudo recibir el archivo. Intenta de nuevo.'];
    }
    if ($archivo['size'] > DESPRENDIBLE_TAMANO_MAXIMO) {
        return ['exito' => false, 'mensaje' => 'El archivo supera el tamaño máximo permitido (5 MB).'];
    }
    if (!is_uploaded_file($archivo['tmp_name']) || !archivoEsPdfValido($archivo['tmp_name'])) {
        return ['exito' => false, 'mensaje' => 'El archivo debe ser un PDF válido.'];
    }

    $carpeta = carpetaDesprendiblesEmpleado($empleadoId);
    if (!is_dir($carpeta) && !mkdir($carpeta, 0755, true) && !is_dir($carpeta)) {
        return ['exito' => false, 'mensaje' => 'No se pudo preparar el almacenamiento del archivo.'];
    }
    $rutaRelativa = 'storage/desprendibles/' . $empleadoId . '/' . $periodo . '.pdf';
    $rutaFinal = $carpeta . '/' . $periodo . '.pdf';

    $pdo = obtenerConexion();
    $stmtExistente = $pdo->prepare('SELECT id FROM desprendible WHERE empleado_id = :emp AND periodo = :periodo');
    $stmtExistente->execute(['emp' => $empleadoId, 'periodo' => $periodo]);
    $existente = $stmtExistente->fetch();

    if ($existente) {
        // Reemplazo: mismo periodo, mismo empleado. No cambia el total de registros.
        if (!move_uploaded_file($archivo['tmp_name'], $rutaFinal)) {
            return ['exito' => false, 'mensaje' => 'No se pudo guardar el archivo.'];
        }
        $stmt = $pdo->prepare('UPDATE desprendible SET archivo_ruta = :ruta, fecha_carga = NOW(), cargado_por = :cargado_por WHERE id = :id');
        $stmt->execute(['ruta' => $rutaRelativa, 'cargado_por' => $usuarioId, 'id' => $existente['id']]);
        return ['exito' => true, 'mensaje' => 'Ya existía un desprendible para ese periodo: se reemplazó por el nuevo archivo.'];
    }

    // Periodo nuevo: si ya tiene el máximo, el más antiguo se borra al cargar este.
    $stmtConteo = $pdo->prepare('SELECT COUNT(*) FROM desprendible WHERE empleado_id = :emp');
    $stmtConteo->execute(['emp' => $empleadoId]);
    $masAntiguo = null;
    if ((int) $stmtConteo->fetchColumn() >= DESPRENDIBLE_MAXIMO_POR_EMPLEADO) {
        $stmtAntiguo = $pdo->prepare('SELECT id, periodo, archivo_ruta FROM desprendible WHERE empleado_id = :emp ORDER BY periodo ASC LIMIT 1');
        $stmtAntiguo->execute(['emp' => $empleadoId]);
        $masAntiguo = $stmtAntiguo->fetch();
    }

    if (!move_uploaded_file($archivo['tmp_name'], $rutaFinal)) {
        return ['exito' => false, 'mensaje' => 'No se pudo guardar el archivo.'];
    }

    try {
        $pdo->beginTransaction();
        if ($masAntiguo) {
            $pdo->prepare('DELETE FROM desprendible WHERE id = :id')->execute(['id' => $masAntiguo['id']]);
        }
        $stmtInsert = $pdo->prepare('
            INSERT INTO desprendible (empleado_id, periodo, archivo_ruta, cargado_por)
            VALUES (:emp, :periodo, :ruta, :cargado_por)
        ');
        $stmtInsert->execute([
            'emp'         => $empleadoId,
            'periodo'     => $periodo,
            'ruta'        => $rutaRelativa,
            'cargado_por' => $usuarioId,
        ]);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        @unlink($rutaFinal); // no dejar el archivo nuevo huérfano si la BD falló
        return ['exito' => false, 'mensaje' => 'No se pudo registrar el desprendible.'];
    }

    if ($masAntiguo) {
        // Mejor esfuerzo: la BD ya quedó consistente (commit arriba), así que
        // si esto falla solo queda un archivo huérfano en disco, no un
        // registro sin archivo ni un archivo referenciado por dos filas.
        $rutaFisicaAntigua = __DIR__ . '/../../' . $masAntiguo['archivo_ruta'];
        if (!@unlink($rutaFisicaAntigua)) {
            error_log('HU-02: no se pudo borrar el archivo huérfano ' . $rutaFisicaAntigua);
        }
        return ['exito' => true, 'mensaje' => 'Desprendible cargado. Se alcanzó el máximo de 6 y se eliminó permanentemente el más antiguo (periodo ' . $masAntiguo['periodo'] . ').'];
    }

    return ['exito' => true, 'mensaje' => 'Desprendible cargado correctamente.'];
}

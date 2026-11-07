<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-02: carga y consulta de desprendibles de nómina.
 * Cualquier cuenta ve sus propios desprendibles (autoservicio). Solo
 * Contabilidad y Administrador cargan desprendibles para un empleado.
 */
require_once __DIR__ . '/../src/config/app.php';
exigirSesion();

$usuario = usuarioActual();
$esContabilidad = in_array((int) $usuario['rol_id'], [ROL_CONTABILIDAD, ROL_ADMINISTRADOR], true);
$mensaje = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$esContabilidad) {
        http_response_code(403);
        echo 'Acceso denegado: tu rol no tiene permiso para cargar desprendibles.';
        exit;
    }
    if (!validarCSRF($_POST['csrf_token'] ?? null)) {
        $mensaje = ['err', 'La sesión del formulario expiró. Intenta de nuevo.'];
    } elseif (($_POST['accion'] ?? '') === 'cargar') {
        $empleadoIdCarga = (int) ($_POST['empleado_id'] ?? 0);
        $resultado = cargarDesprendible($empleadoIdCarga, trim($_POST['periodo'] ?? ''), $_FILES['archivo'] ?? [], (int) $usuario['id']);
        $mensaje = [$resultado['exito'] ? 'ok' : 'err', $resultado['mensaje']];
    }
}

$empleadoSeleccionado = null;
if ($esContabilidad && isset($_GET['empleado'])) {
    $empleadoSeleccionado = obtenerEmpleado((int) $_GET['empleado']);
}

$misDesprendibles = listarDesprendiblesDeEmpleado((int) $usuario['empleado_id']);
$desprendiblesSeleccionado = $empleadoSeleccionado ? listarDesprendiblesDeEmpleado($empleadoSeleccionado['id']) : [];
$empleadosActivos = $esContabilidad ? array_values(array_filter(listarEmpleados(), fn($e) => $e['estado'] === 'activo')) : [];

$tituloPagina = 'Desprendibles';
$menu = [['/desprendibles.php', 'Desprendibles', 'HU-02']];
$vistaActual = '/desprendibles.php';
require __DIR__ . '/../views/layout/cabecera.php';
?>

<?php if ($mensaje): ?>
  <div class="msg <?= $mensaje[0] ?>"><?= htmlspecialchars($mensaje[1]) ?></div>
<?php endif; ?>

<?php if ($esContabilidad): ?>
  <h2>Cargar desprendible</h2>

  <?php if ($empleadoSeleccionado): ?>
    <form method="post" enctype="multipart/form-data" class="card" autocomplete="off">
      <?= campoCSRF() ?>
      <input type="hidden" name="accion" value="cargar">
      <input type="hidden" name="empleado_id" value="<?= (int) $empleadoSeleccionado['id'] ?>">
      <div class="grid2">
        <div class="field">
          <label>Empleado</label>
          <input type="text" value="<?= htmlspecialchars($empleadoSeleccionado['nombre_completo']) ?>" disabled>
        </div>
        <div class="field">
          <label for="periodo">Periodo</label>
          <input type="month" id="periodo" name="periodo" required>
        </div>
      </div>
      <div class="field">
        <label for="archivo">Archivo PDF (máx. 5 MB)</label>
        <input type="file" id="archivo" name="archivo" accept="application/pdf,.pdf" required>
      </div>
      <div class="row">
        <button class="btn" type="submit">Cargar desprendible</button>
        <a class="btn sec" href="<?= URL_BASE ?>/desprendibles.php">Cambiar de empleado</a>
      </div>
    </form>

    <h3>Desprendibles de <?= htmlspecialchars($empleadoSeleccionado['nombre_completo']) ?> (<?= count($desprendiblesSeleccionado) ?>/<?= DESPRENDIBLE_MAXIMO_POR_EMPLEADO ?>)</h3>
    <div class="tw">
      <table>
        <thead><tr><th>Periodo</th><th>Cargado el</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($desprendiblesSeleccionado as $d): ?>
            <tr>
              <td class="mono"><?= htmlspecialchars($d['periodo']) ?></td>
              <td class="mono"><?= htmlspecialchars($d['fecha_carga']) ?></td>
              <td><a class="btn sec sm" href="<?= URL_BASE ?>/desprendible_descargar.php?id=<?= (int) $d['id'] ?>">Descargar</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$desprendiblesSeleccionado): ?>
            <tr><td colspan="3" class="empty">Este empleado todavía no tiene desprendibles cargados.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p>Selecciona un empleado para cargar o revisar sus desprendibles.</p>
    <div class="tw">
      <table>
        <thead><tr><th>Nombre</th><th>Documento</th><th>Cargo</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($empleadosActivos as $e): ?>
            <tr>
              <td><?= htmlspecialchars($e['nombre_completo']) ?></td>
              <td class="num"><?= htmlspecialchars($e['documento']) ?></td>
              <td><?= htmlspecialchars($e['cargo']) ?></td>
              <td><a class="btn sec sm" href="<?= URL_BASE ?>/desprendibles.php?empleado=<?= (int) $e['id'] ?>">Gestionar desprendibles</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  <hr style="border:0;border-top:1px solid var(--line);margin:28px 0">
<?php endif; ?>

<h2>Mis desprendibles</h2>
<div class="tw">
  <table>
    <thead><tr><th>Periodo</th><th>Cargado el</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($misDesprendibles as $d): ?>
        <tr>
          <td class="mono"><?= htmlspecialchars($d['periodo']) ?></td>
          <td class="mono"><?= htmlspecialchars($d['fecha_carga']) ?></td>
          <td><a class="btn sec sm" href="<?= URL_BASE ?>/desprendible_descargar.php?id=<?= (int) $d['id'] ?>">Descargar</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$misDesprendibles): ?>
        <tr><td colspan="3" class="empty">Todavía no tienes desprendibles cargados.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../views/layout/pie.php'; ?>

<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-04: solicitud y aprobación de permisos.
 * Cualquier cuenta solicita permiso para sí misma. Quien tenga empleados
 * a cargo (jefe_id, verificado en servidor, no por rol) ve y resuelve las
 * solicitudes de su equipo.
 */
require_once __DIR__ . '/../src/config/app.php';
exigirSesion();

$usuario = usuarioActual();
$mensaje = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarCSRF($_POST['csrf_token'] ?? null)) {
        $mensaje = ['err', 'La sesión del formulario expiró. Intenta de nuevo.'];
    } else {
        $accion = $_POST['accion'] ?? '';
        if ($accion === 'solicitar') {
            $resultado = solicitarPermiso(
                (int) $usuario['empleado_id'],
                $_POST['fecha_inicio'] ?? '',
                $_POST['fecha_fin'] ?? '',
                $_POST['motivo'] ?? ''
            );
            $mensaje = [$resultado['exito'] ? 'ok' : 'err', $resultado['mensaje']];
        } elseif ($accion === 'resolver') {
            $resultado = resolverPermiso(
                (int) ($_POST['id'] ?? 0),
                $_POST['decision'] ?? '',
                (int) $usuario['id'],
                (int) $usuario['empleado_id']
            );
            $mensaje = [$resultado['exito'] ? 'ok' : 'err', $resultado['mensaje']];
        }
    }
}

$misPermisos = listarPermisosDeEmpleado((int) $usuario['empleado_id']);
$permisosDeEquipo = listarPermisosDeEquipo((int) $usuario['empleado_id']);

$tituloPagina = 'Permisos';
$menu = [['/permisos.php', 'Permisos', 'HU-04']];
$vistaActual = '/permisos.php';
require __DIR__ . '/../views/layout/cabecera.php';
?>

<?php if ($mensaje): ?>
  <div class="msg <?= $mensaje[0] ?>"><?= htmlspecialchars($mensaje[1]) ?></div>
<?php endif; ?>

<?php if ($permisosDeEquipo): ?>
  <h2>Solicitudes de mi equipo</h2>
  <div class="tw">
    <table>
      <thead><tr><th>Empleado</th><th>Del</th><th>Al</th><th>Motivo</th><th>Estado</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($permisosDeEquipo as $p): ?>
          <tr>
            <td><?= htmlspecialchars($p['nombre_completo']) ?></td>
            <td class="mono"><?= htmlspecialchars($p['fecha_inicio']) ?></td>
            <td class="mono"><?= htmlspecialchars($p['fecha_fin']) ?></td>
            <td><?= htmlspecialchars($p['motivo']) ?></td>
            <td><span class="pill p-<?= $p['estado'] ?>"><?= htmlspecialchars(ucfirst($p['estado'])) ?></span></td>
            <td>
              <?php if ($p['estado'] === 'pendiente'): ?>
                <div class="row">
                  <form method="post" style="display:inline">
                    <?= campoCSRF() ?>
                    <input type="hidden" name="accion" value="resolver">
                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                    <input type="hidden" name="decision" value="aprobado">
                    <button class="btn sec sm" type="submit">Aprobar</button>
                  </form>
                  <form method="post" style="display:inline">
                    <?= campoCSRF() ?>
                    <input type="hidden" name="accion" value="resolver">
                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                    <input type="hidden" name="decision" value="rechazado">
                    <button class="btn bad sm" type="submit">Rechazar</button>
                  </form>
                </div>
              <?php else: ?>
                <span class="mono" style="font-size:12px;color:var(--muted)">
                  Resuelto el <?= htmlspecialchars($p['fecha_respuesta']) ?>
                </span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <hr style="border:0;border-top:1px solid var(--line);margin:28px 0">
<?php endif; ?>

<h2>Solicitar un permiso</h2>
<form method="post" class="card" autocomplete="off">
  <?= campoCSRF() ?>
  <input type="hidden" name="accion" value="solicitar">
  <div class="grid2">
    <div class="field">
      <label for="fecha_inicio">Desde</label>
      <input type="date" id="fecha_inicio" name="fecha_inicio" required>
    </div>
    <div class="field">
      <label for="fecha_fin">Hasta</label>
      <input type="date" id="fecha_fin" name="fecha_fin" required>
    </div>
  </div>
  <div class="field">
    <label for="motivo">Motivo (obligatorio)</label>
    <input type="text" id="motivo" name="motivo" required maxlength="255">
  </div>
  <div class="row">
    <button class="btn" type="submit">Enviar solicitud</button>
  </div>
</form>

<h2>Mis solicitudes</h2>
<div class="tw">
  <table>
    <thead><tr><th>Solicitada el</th><th>Del</th><th>Al</th><th>Motivo</th><th>Estado</th></tr></thead>
    <tbody>
      <?php foreach ($misPermisos as $p): ?>
        <tr>
          <td class="mono"><?= htmlspecialchars($p['fecha_solicitud']) ?></td>
          <td class="mono"><?= htmlspecialchars($p['fecha_inicio']) ?></td>
          <td class="mono"><?= htmlspecialchars($p['fecha_fin']) ?></td>
          <td><?= htmlspecialchars($p['motivo']) ?></td>
          <td><span class="pill p-<?= $p['estado'] ?>"><?= htmlspecialchars(ucfirst($p['estado'])) ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$misPermisos): ?>
        <tr><td colspan="5" class="empty">Todavía no has solicitado ningún permiso.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../views/layout/pie.php'; ?>

<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-01: registro y administración de empleados.
 * Solo Administrador y Contabilidad gestionan empleados.
 */
require_once __DIR__ . '/../src/config/app.php';
exigirSesion([ROL_ADMINISTRADOR, ROL_CONTABILIDAD]);

$mensaje = null;
$empleadoEnEdicion = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarCSRF($_POST['csrf_token'] ?? null)) {
        $mensaje = ['err', 'La sesión del formulario expiró. Intenta de nuevo.'];
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'crear') {
            $resultado = crearEmpleado($_POST);
            $mensaje = [$resultado['exito'] ? 'ok' : 'err', $resultado['mensaje']];
        } elseif ($accion === 'actualizar') {
            $id = (int) ($_POST['id'] ?? 0);
            $resultado = actualizarEmpleado($id, $_POST);
            $mensaje = [$resultado['exito'] ? 'ok' : 'err', $resultado['mensaje']];
            if (!$resultado['exito']) {
                $empleadoEnEdicion = obtenerEmpleado($id);
            }
        } elseif ($accion === 'cambiar_estado') {
            $id = (int) ($_POST['id'] ?? 0);
            $nuevoEstado = $_POST['nuevo_estado'] ?? '';
            $resultado = cambiarEstadoEmpleado($id, $nuevoEstado);
            $mensaje = [$resultado['exito'] ? 'ok' : 'err', $resultado['mensaje']];
        }
    }
}

if ($empleadoEnEdicion === null && isset($_GET['editar'])) {
    $empleadoEnEdicion = obtenerEmpleado((int) $_GET['editar']);
}

$areas = listarAreas();
$jefes = listarPosiblesJefes($empleadoEnEdicion['id'] ?? null);
$empleados = listarEmpleados();

$tituloPagina = 'Empleados';
$menu = [['/empleados.php', 'Empleados', 'HU-01']];
$vistaActual = '/empleados.php';
require __DIR__ . '/../views/layout/cabecera.php';
?>
<h2><?= $empleadoEnEdicion ? 'Editar empleado' : 'Nuevo empleado' ?></h2>
<?php if ($mensaje): ?>
  <div class="msg <?= $mensaje[0] ?>"><?= htmlspecialchars($mensaje[1]) ?></div>
<?php endif; ?>

<form method="post" class="card" autocomplete="off">
  <?= campoCSRF() ?>
  <input type="hidden" name="accion" value="<?= $empleadoEnEdicion ? 'actualizar' : 'crear' ?>">
  <?php if ($empleadoEnEdicion): ?>
    <input type="hidden" name="id" value="<?= (int) $empleadoEnEdicion['id'] ?>">
  <?php endif; ?>
  <div class="grid2">
    <div class="field">
      <label for="nombre_completo">Nombre completo</label>
      <input type="text" id="nombre_completo" name="nombre_completo" required
             value="<?= htmlspecialchars($empleadoEnEdicion['nombre_completo'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="documento">Documento</label>
      <input type="text" id="documento" name="documento" required
             value="<?= htmlspecialchars($empleadoEnEdicion['documento'] ?? '') ?>">
    </div>
  </div>
  <div class="grid2">
    <div class="field">
      <label for="correo_personal">Correo personal</label>
      <input type="text" id="correo_personal" name="correo_personal" required
             value="<?= htmlspecialchars($empleadoEnEdicion['correo_personal'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="cargo">Cargo</label>
      <input type="text" id="cargo" name="cargo" required
             value="<?= htmlspecialchars($empleadoEnEdicion['cargo'] ?? '') ?>">
    </div>
  </div>
  <div class="grid2">
    <div class="field">
      <label for="area_id">Área</label>
      <select id="area_id" name="area_id" required>
        <option value="">Selecciona un área</option>
        <?php foreach ($areas as $area): ?>
          <option value="<?= $area['id'] ?>"<?= (($empleadoEnEdicion['area_id'] ?? null) == $area['id']) ? ' selected' : '' ?>>
            <?= htmlspecialchars($area['nombre']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="jefe_id">Jefe directo (opcional)</label>
      <select id="jefe_id" name="jefe_id">
        <option value="">Sin jefe directo</option>
        <?php foreach ($jefes as $jefe): ?>
          <option value="<?= $jefe['id'] ?>"<?= (($empleadoEnEdicion['jefe_id'] ?? null) == $jefe['id']) ? ' selected' : '' ?>>
            <?= htmlspecialchars($jefe['nombre_completo']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="field" style="max-width:220px">
    <label for="fecha_ingreso">Fecha de ingreso</label>
    <input type="date" id="fecha_ingreso" name="fecha_ingreso" required
           value="<?= htmlspecialchars($empleadoEnEdicion['fecha_ingreso'] ?? '') ?>">
  </div>
  <div class="row">
    <button class="btn" type="submit"><?= $empleadoEnEdicion ? 'Guardar cambios' : 'Crear empleado' ?></button>
    <?php if ($empleadoEnEdicion): ?>
      <a class="btn sec" href="<?= URL_BASE ?>/empleados.php">Cancelar</a>
    <?php endif; ?>
  </div>
</form>

<div>
  <h3>Empleados registrados</h3>
  <div class="tw">
    <table>
      <thead><tr><th>Nombre</th><th>Documento</th><th>Cargo</th><th>Área</th><th>Jefe</th><th>Estado</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($empleados as $e): ?>
          <tr>
            <td><?= htmlspecialchars($e['nombre_completo']) ?></td>
            <td class="num"><?= htmlspecialchars($e['documento']) ?></td>
            <td><?= htmlspecialchars($e['cargo']) ?></td>
            <td><?= htmlspecialchars($e['area_nombre']) ?></td>
            <td><?= htmlspecialchars($e['jefe_nombre'] ?? '—') ?></td>
            <td><?= $e['estado'] === 'activo' ? '<span class="pill p-activo">Activo</span>' : '<span class="pill p-inactivo">Inactivo</span>' ?></td>
            <td>
              <div class="row">
                <a class="btn sec sm" href="<?= URL_BASE ?>/empleados.php?editar=<?= $e['id'] ?>">Editar</a>
                <form method="post" style="display:inline">
                  <?= campoCSRF() ?>
                  <input type="hidden" name="accion" value="cambiar_estado">
                  <input type="hidden" name="id" value="<?= $e['id'] ?>">
                  <input type="hidden" name="nuevo_estado" value="<?= $e['estado'] === 'activo' ? 'inactivo' : 'activo' ?>">
                  <button class="btn <?= $e['estado'] === 'activo' ? 'bad' : 'sec' ?> sm" type="submit">
                    <?= $e['estado'] === 'activo' ? 'Desactivar' : 'Activar' ?>
                  </button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../views/layout/pie.php'; ?>

<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-10: historial y estados de clientes.
 * Solo los roles comerciales (Vendedor, Gerente) y el Administrador
 * gestionan el estado de los clientes.
 */
require_once __DIR__ . '/../src/config/app.php';
exigirSesion([ROL_VENDEDOR, ROL_GERENTE, ROL_ADMINISTRADOR]);

$usuario = usuarioActual();
$mensaje = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarCSRF($_POST['csrf_token'] ?? null)) {
        $mensaje = ['err', 'La sesión del formulario expiró. Intenta de nuevo.'];
    } elseif (($_POST['accion'] ?? '') === 'cambiar_estado') {
        $id = (int) ($_POST['id'] ?? 0);
        $resultado = cambiarEstadoCliente($id, $_POST['nuevo_estado'] ?? '', $_POST['motivo'] ?? '', (int) $usuario['id']);
        $mensaje = [$resultado['exito'] ? 'ok' : 'err', $resultado['mensaje']];
    }
}

$clienteVerId = isset($_GET['ver']) ? (int) $_GET['ver'] : null;
$clienteVer = $clienteVerId ? obtenerCliente($clienteVerId) : null;
$historial = $clienteVer ? listarHistorialCliente($clienteVerId) : [];
$clientes = listarClientes();

$tituloPagina = 'Clientes';
$menu = [['/clientes.php', 'Clientes', 'HU-10']];
$vistaActual = '/clientes.php';
require __DIR__ . '/../views/layout/cabecera.php';
?>

<?php if ($mensaje): ?>
  <div class="msg <?= $mensaje[0] ?>"><?= htmlspecialchars($mensaje[1]) ?></div>
<?php endif; ?>

<?php if ($clienteVer): ?>
  <h2><?= htmlspecialchars($clienteVer['nombre']) ?></h2>
  <p class="mono">
    <?= htmlspecialchars($clienteVer['contacto'] ?? '—') ?> ·
    <?= htmlspecialchars($clienteVer['telefono'] ?? '—') ?> ·
    <?= htmlspecialchars($clienteVer['correo'] ?? '—') ?> ·
    <span class="pill p-<?= $clienteVer['estado'] === 'prospecto' ? 'pendiente' : $clienteVer['estado'] ?>">
      <?= htmlspecialchars(ucfirst($clienteVer['estado'])) ?>
    </span>
  </p>

  <form method="post" class="card" autocomplete="off">
    <?= campoCSRF() ?>
    <input type="hidden" name="accion" value="cambiar_estado">
    <input type="hidden" name="id" value="<?= (int) $clienteVer['id'] ?>">
    <div class="grid2">
      <div class="field">
        <label for="nuevo_estado">Nuevo estado</label>
        <select id="nuevo_estado" name="nuevo_estado" required>
          <option value="">Selecciona un estado</option>
          <?php foreach (['prospecto', 'activo', 'inactivo'] as $estado): ?>
            <option value="<?= $estado ?>"<?= $estado === $clienteVer['estado'] ? ' disabled' : '' ?>>
              <?= ucfirst($estado) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="motivo">Motivo (obligatorio)</label>
        <input type="text" id="motivo" name="motivo" required maxlength="255"
               placeholder="Por ejemplo: aceptó la cotización, dejó de comprar, etc.">
      </div>
    </div>
    <div class="row">
      <button class="btn" type="submit">Registrar cambio de estado</button>
      <a class="btn sec" href="<?= URL_BASE ?>/clientes.php">Volver al listado</a>
    </div>
  </form>

  <div>
    <h3>Historial de estados</h3>
    <div class="tw">
      <table>
        <thead><tr><th>Fecha</th><th>Estado anterior</th><th>Estado nuevo</th><th>Motivo</th><th>Usuario</th></tr></thead>
        <tbody>
          <?php foreach ($historial as $h): ?>
            <tr>
              <td class="mono"><?= htmlspecialchars($h['fecha']) ?></td>
              <td><?= $h['estado_anterior'] ? htmlspecialchars(ucfirst($h['estado_anterior'])) : '—' ?></td>
              <td><?= htmlspecialchars(ucfirst($h['estado_nuevo'])) ?></td>
              <td><?= htmlspecialchars($h['motivo']) ?></td>
              <td><?= htmlspecialchars($h['nombre_usuario']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$historial): ?>
            <tr><td colspan="5" class="empty">Sin cambios de estado todavía.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php else: ?>
  <h2>Clientes</h2>
  <div class="tw">
    <table>
      <thead><tr><th>Nombre</th><th>Contacto</th><th>Teléfono</th><th>Correo</th><th>Estado</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($clientes as $c): ?>
          <tr>
            <td><?= htmlspecialchars($c['nombre']) ?></td>
            <td><?= htmlspecialchars($c['contacto'] ?? '—') ?></td>
            <td class="num"><?= htmlspecialchars($c['telefono'] ?? '—') ?></td>
            <td><?= htmlspecialchars($c['correo'] ?? '—') ?></td>
            <td>
              <span class="pill p-<?= $c['estado'] === 'prospecto' ? 'pendiente' : $c['estado'] ?>">
                <?= htmlspecialchars(ucfirst($c['estado'])) ?>
              </span>
            </td>
            <td><a class="btn sec sm" href="<?= URL_BASE ?>/clientes.php?ver=<?= $c['id'] ?>">Ver / cambiar estado</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../views/layout/pie.php'; ?>

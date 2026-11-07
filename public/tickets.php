<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-07: asignación de tickets a técnicos.
 * Coordinador y Administrador ven y asignan todos los tickets; el
 * técnico ve solo los suyos (los de su asignación más reciente).
 */
require_once __DIR__ . '/../src/config/app.php';
exigirSesion([ROL_TECNICO, ROL_COORDINADOR, ROL_ADMINISTRADOR]);

$usuario = usuarioActual();
$esGestionSoporte = in_array((int) $usuario['rol_id'], [ROL_COORDINADOR, ROL_ADMINISTRADOR], true);
$mensaje = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$esGestionSoporte) {
        http_response_code(403);
        echo 'Acceso denegado: tu rol no tiene permiso para asignar tickets.';
        exit;
    }
    if (!validarCSRF($_POST['csrf_token'] ?? null)) {
        $mensaje = ['err', 'La sesión del formulario expiró. Intenta de nuevo.'];
    } elseif (($_POST['accion'] ?? '') === 'asignar') {
        $resultado = asignarTicket(
            (int) ($_POST['ticket_id'] ?? 0),
            (int) ($_POST['tecnico_id'] ?? 0),
            (int) $usuario['id']
        );
        $mensaje = [$resultado['exito'] ? 'ok' : 'err', $resultado['mensaje']];
    }
}

$tickets = $esGestionSoporte ? listarTickets() : listarTicketsAsignadosA((int) $usuario['id']);
$tecnicos = $esGestionSoporte ? listarTecnicosActivos() : [];

$tituloPagina = 'Tickets';
$menu = [['/tickets.php', 'Tickets', 'HU-07']];
$vistaActual = '/tickets.php';
require __DIR__ . '/../views/layout/cabecera.php';
?>

<?php if ($mensaje): ?>
  <div class="msg <?= $mensaje[0] ?>"><?= htmlspecialchars($mensaje[1]) ?></div>
<?php endif; ?>

<h2><?= $esGestionSoporte ? 'Todos los tickets' : 'Mis tickets asignados' ?></h2>
<div class="tw">
  <table>
    <thead>
      <tr>
        <th>Asunto</th>
        <th>Solicitante</th>
        <th>Creado</th>
        <th>Estado</th>
        <th>Técnico</th>
        <?php if ($esGestionSoporte): ?><th></th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($tickets as $t): ?>
        <tr>
          <td><?= htmlspecialchars($t['asunto']) ?></td>
          <td><?= htmlspecialchars($t['solicitante_nombre']) ?></td>
          <td class="mono"><?= htmlspecialchars($t['fecha_creacion']) ?></td>
          <td><span class="pill p-<?= estadoVisualTicket($t) ?>"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', estadoVisualTicket($t)))) ?></span></td>
          <td><?= htmlspecialchars($t['tecnico_actual_nombre'] ?? '—') ?></td>
          <?php if ($esGestionSoporte): ?>
            <td>
              <form method="post" class="row" style="gap:6px">
                <?= campoCSRF() ?>
                <input type="hidden" name="accion" value="asignar">
                <input type="hidden" name="ticket_id" value="<?= (int) $t['id'] ?>">
                <select name="tecnico_id" required>
                  <option value="">Técnico...</option>
                  <?php foreach ($tecnicos as $tec): ?>
                    <option value="<?= (int) $tec['usuario_id'] ?>"<?= (isset($t['tecnico_actual_id']) && (int) $t['tecnico_actual_id'] === (int) $tec['usuario_id']) ? ' selected' : '' ?>>
                      <?= htmlspecialchars($tec['nombre_completo']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <button class="btn sec sm" type="submit"><?= $t['tecnico_actual_id'] ? 'Reasignar' : 'Asignar' ?></button>
              </form>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$tickets): ?>
        <tr><td colspan="<?= $esGestionSoporte ? 6 : 5 ?>" class="empty">No hay tickets para mostrar.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../views/layout/pie.php'; ?>

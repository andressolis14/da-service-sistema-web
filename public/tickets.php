<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-07: asignación de tickets a técnicos.
 * HU-08: avances y cierre de tickets.
 * Coordinador y Administrador ven y asignan todos los tickets; el
 * técnico ve solo los suyos. El técnico asignado (o los roles de
 * gestión) registra avances y cierra el ticket.
 */
require_once __DIR__ . '/../src/config/app.php';
exigirSesion([ROL_TECNICO, ROL_COORDINADOR, ROL_ADMINISTRADOR]);

$usuario = usuarioActual();
$esGestionSoporte = in_array((int) $usuario['rol_id'], [ROL_COORDINADOR, ROL_ADMINISTRADOR], true);
$mensaje = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'asignar') {
        if (!$esGestionSoporte) {
            http_response_code(403);
            echo 'Acceso denegado: tu rol no tiene permiso para asignar tickets.';
            exit;
        }
        if (!validarCSRF($_POST['csrf_token'] ?? null)) {
            $mensaje = ['err', 'La sesión del formulario expiró. Intenta de nuevo.'];
        } else {
            $resultado = asignarTicket(
                (int) ($_POST['ticket_id'] ?? 0),
                (int) ($_POST['tecnico_id'] ?? 0),
                (int) $usuario['id']
            );
            $mensaje = [$resultado['exito'] ? 'ok' : 'err', $resultado['mensaje']];
        }
    } elseif ($accion === 'avance') {
        $ticketIdAvance = (int) ($_POST['ticket_id'] ?? 0);
        $ticketParaAvance = obtenerTicketConTecnico($ticketIdAvance);
        $esTecnicoAsignado = $ticketParaAvance
            && (int) ($ticketParaAvance['tecnico_actual_id'] ?? 0) === (int) $usuario['id'];

        if (!$esGestionSoporte && !$esTecnicoAsignado) {
            http_response_code(403);
            echo 'Acceso denegado: solo el técnico asignado a este ticket (o un rol de gestión) puede registrar avances.';
            exit;
        }
        if (!validarCSRF($_POST['csrf_token'] ?? null)) {
            $mensaje = ['err', 'La sesión del formulario expiró. Intenta de nuevo.'];
        } else {
            $resultado = registrarAvanceTicket(
                $ticketIdAvance,
                (int) $usuario['id'],
                $_POST['estado_nuevo'] ?? '',
                $_POST['comentario'] ?? '',
                $_POST['modalidad'] ?? ''
            );
            $mensaje = [$resultado['exito'] ? 'ok' : 'err', $resultado['mensaje']];
        }
    }
}

$ticketVerId = isset($_GET['ver']) ? (int) $_GET['ver'] : null;
$ticketVer = null;
$avances = [];
$fechaCierre = null;
$puedeRegistrarAvance = false;

if ($ticketVerId) {
    $ticketVer = obtenerTicketConTecnico($ticketVerId);
    if ($ticketVer) {
        $esTecnicoAsignado = (int) ($ticketVer['tecnico_actual_id'] ?? 0) === (int) $usuario['id'];
        if (!$esGestionSoporte && !$esTecnicoAsignado) {
            http_response_code(403);
            echo 'Acceso denegado: solo el técnico asignado a este ticket (o un rol de gestión) puede verlo.';
            exit;
        }
        $avances = listarAvancesDeTicket($ticketVerId);
        $fechaCierre = obtenerFechaCierre($ticketVerId);
        $puedeRegistrarAvance = ($esGestionSoporte || $esTecnicoAsignado) && $ticketVer['estado'] !== 'cerrado';
    }
}

$tickets = $esGestionSoporte ? listarTickets() : listarTicketsAsignadosA((int) $usuario['id']);
$tecnicos = $esGestionSoporte ? listarTecnicosActivos() : [];

$tituloPagina = 'Tickets';
$menu = [['/tickets.php', 'Tickets', 'HU-07 · HU-08']];
$vistaActual = '/tickets.php';
require __DIR__ . '/../views/layout/cabecera.php';
?>

<?php if ($mensaje): ?>
  <div class="msg <?= $mensaje[0] ?>"><?= htmlspecialchars($mensaje[1]) ?></div>
<?php endif; ?>

<?php if ($ticketVer): ?>
  <h2><?= htmlspecialchars($ticketVer['asunto']) ?></h2>
  <p class="mono">
    <?= htmlspecialchars($ticketVer['solicitante_nombre']) ?> ·
    <span class="pill p-<?= estadoVisualTicket($ticketVer) ?>"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', estadoVisualTicket($ticketVer)))) ?></span>
    <?php if ($fechaCierre): ?> · Cerrado el <?= htmlspecialchars($fechaCierre) ?><?php endif; ?>
  </p>
  <p><?= nl2br(htmlspecialchars($ticketVer['descripcion'])) ?></p>

  <h3>Historial de avances</h3>
  <div class="tw">
    <table>
      <thead><tr><th>Fecha</th><th>Técnico</th><th>Modalidad</th><th>Nuevo estado</th><th>Comentario</th></tr></thead>
      <tbody>
        <?php foreach ($avances as $a): ?>
          <tr>
            <td class="mono"><?= htmlspecialchars($a['fecha']) ?></td>
            <td><?= htmlspecialchars($a['nombre_completo']) ?></td>
            <td><?= htmlspecialchars(ucfirst($a['modalidad'])) ?></td>
            <td><span class="pill p-<?= $a['estado_nuevo'] ?>"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $a['estado_nuevo']))) ?></span></td>
            <td><?= htmlspecialchars($a['comentario']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$avances): ?>
          <tr><td colspan="5" class="empty">Todavía no hay avances registrados.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($puedeRegistrarAvance): ?>
    <h3>Registrar avance</h3>
    <form method="post" class="card" autocomplete="off">
      <?= campoCSRF() ?>
      <input type="hidden" name="accion" value="avance">
      <input type="hidden" name="ticket_id" value="<?= (int) $ticketVer['id'] ?>">
      <div class="grid2">
        <div class="field">
          <label for="estado_nuevo">Nuevo estado</label>
          <select id="estado_nuevo" name="estado_nuevo" required>
            <option value="en_proceso">En proceso</option>
            <option value="cerrado">Cerrado</option>
          </select>
        </div>
        <div class="field">
          <label for="modalidad">Modalidad</label>
          <select id="modalidad" name="modalidad" required>
            <option value="remota">Remota</option>
            <option value="presencial">Presencial</option>
          </select>
        </div>
      </div>
      <div class="field">
        <label for="comentario">Descripción del avance</label>
        <textarea id="comentario" name="comentario" required></textarea>
      </div>
      <div class="row">
        <button class="btn" type="submit">Registrar avance</button>
        <a class="btn sec" href="<?= URL_BASE ?>/tickets.php">Volver al listado</a>
      </div>
    </form>
  <?php elseif ($ticketVer['estado'] === 'cerrado'): ?>
    <p class="empty">Este ticket está cerrado: no admite más avances.</p>
  <?php endif; ?>

<?php else: ?>

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
          <th></th>
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
            <td><a class="btn sec sm" href="<?= URL_BASE ?>/tickets.php?ver=<?= (int) $t['id'] ?>">Ver / avances</a></td>
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
          <tr><td colspan="<?= $esGestionSoporte ? 7 : 6 ?>" class="empty">No hay tickets para mostrar.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/../views/layout/pie.php'; ?>

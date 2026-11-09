<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-16: seguimiento de proyectos en ejecución.
 * Roles comerciales (Vendedor, Gerente) y Administrador. Dentro de un
 * proyecto, solo su responsable (o Gerente/Administrador) puede
 * actualizarlo: agregar/completar pendientes o terminarlo.
 */
require_once __DIR__ . '/../src/config/app.php';
exigirSesion([ROL_VENDEDOR, ROL_GERENTE, ROL_ADMINISTRADOR]);

$usuario = usuarioActual();
$esGestion = in_array((int) $usuario['rol_id'], [ROL_GERENTE, ROL_ADMINISTRADOR], true);
$mensaje = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear') {
        if (!validarCSRF($_POST['csrf_token'] ?? null)) {
            $mensaje = ['err', 'La sesión del formulario expiró. Intenta de nuevo.'];
        } else {
            $resultado = crearProyecto(
                (int) ($_POST['oportunidad_id'] ?? 0),
                (int) ($_POST['responsable_id'] ?? 0),
                $_POST['fecha_inicio'] ?? '',
                $_POST['fecha_compromiso'] ?? ''
            );
            $mensaje = [$resultado['exito'] ? 'ok' : 'err', $resultado['mensaje']];
        }
    } elseif (in_array($accion, ['terminar', 'pendiente', 'completar_pendiente'], true)) {
        $proyectoId = (int) ($_POST['proyecto_id'] ?? 0);
        $proyecto = obtenerProyecto($proyectoId);
        $esResponsable = $proyecto && (int) $proyecto['responsable_id'] === (int) $usuario['id'];

        if (!$esGestion && !$esResponsable) {
            http_response_code(403);
            echo 'Acceso denegado: solo el responsable de este proyecto (o un rol de gestión) puede actualizarlo.';
            exit;
        }
        if (!validarCSRF($_POST['csrf_token'] ?? null)) {
            $mensaje = ['err', 'La sesión del formulario expiró. Intenta de nuevo.'];
        } elseif ($accion === 'terminar') {
            $resultado = terminarProyecto($proyectoId);
            $mensaje = [$resultado['exito'] ? 'ok' : 'err', $resultado['mensaje']];
        } elseif ($accion === 'pendiente') {
            $resultado = agregarPendienteProyecto($proyectoId, $_POST['descripcion'] ?? '', $_POST['fecha_compromiso_pendiente'] ?? '');
            $mensaje = [$resultado['exito'] ? 'ok' : 'err', $resultado['mensaje']];
        } elseif ($accion === 'completar_pendiente') {
            $resultado = marcarPendienteCompletado((int) ($_POST['pendiente_id'] ?? 0));
            $mensaje = [$resultado['exito'] ? 'ok' : 'err', $resultado['mensaje']];
        }
    }
}

$proyectoVerId = isset($_GET['ver']) ? (int) $_GET['ver'] : null;
$proyectoVer = $proyectoVerId ? obtenerProyecto($proyectoVerId) : null;
$pendientes = $proyectoVer ? listarPendientesDeProyecto($proyectoVerId) : [];
$puedeActualizar = $proyectoVer && ($esGestion || (int) $proyectoVer['responsable_id'] === (int) $usuario['id']);

$proyectos = listarProyectos();
$oportunidadesDisponibles = listarOportunidadesAprobadasSinProyecto();
$responsables = listarPosiblesResponsables();

$tituloPagina = 'Proyectos';
$menu = [['/proyectos.php', 'Proyectos', 'HU-16']];
$vistaActual = '/proyectos.php';
require __DIR__ . '/../views/layout/cabecera.php';
?>

<?php if ($mensaje): ?>
  <div class="msg <?= $mensaje[0] ?>"><?= htmlspecialchars($mensaje[1]) ?></div>
<?php endif; ?>

<?php if ($proyectoVer): ?>
  <h2><?= htmlspecialchars($proyectoVer['oportunidad_descripcion']) ?></h2>
  <p class="mono">
    Cliente: <?= htmlspecialchars($proyectoVer['cliente_nombre']) ?> ·
    Responsable: <?= htmlspecialchars($proyectoVer['responsable_nombre']) ?> ·
    Inicio: <?= htmlspecialchars($proyectoVer['fecha_inicio']) ?> ·
    Entrega comprometida: <?= htmlspecialchars($proyectoVer['fecha_compromiso']) ?>
  </p>
  <p>
    <span class="pill p-<?= $proyectoVer['oportunidad_etapa'] ?>"><?= htmlspecialchars(ucfirst($proyectoVer['oportunidad_etapa'])) ?></span>
    <?php if ($proyectoVer['atrasado']): ?>
      <span class="pill p-rechazado">Atrasado</span>
    <?php elseif ($proyectoVer['oportunidad_etapa'] === 'aprobada'): ?>
      <span class="pill p-activo">En plazo</span>
    <?php endif; ?>
  </p>

  <h3>Pendientes</h3>
  <div class="tw">
    <table>
      <thead><tr><th>Descripción</th><th>Fecha compromiso</th><th>Estado</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($pendientes as $p): ?>
          <tr>
            <td><?= htmlspecialchars($p['descripcion']) ?></td>
            <td class="mono"><?= htmlspecialchars($p['fecha_compromiso']) ?></td>
            <td>
              <?php if ($p['completado']): ?>
                <span class="pill p-activo">Completado</span>
              <?php elseif ($p['fecha_compromiso'] < date('Y-m-d')): ?>
                <span class="pill p-rechazado">Vencido</span>
              <?php else: ?>
                <span class="pill p-pendiente">Pendiente</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!$p['completado'] && $puedeActualizar): ?>
                <form method="post" style="display:inline">
                  <?= campoCSRF() ?>
                  <input type="hidden" name="accion" value="completar_pendiente">
                  <input type="hidden" name="proyecto_id" value="<?= (int) $proyectoVer['id'] ?>">
                  <input type="hidden" name="pendiente_id" value="<?= (int) $p['id'] ?>">
                  <button class="btn sec sm" type="submit">Marcar completado</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$pendientes): ?>
          <tr><td colspan="4" class="empty">Sin pendientes registrados.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($puedeActualizar): ?>
    <form method="post" class="card" autocomplete="off">
      <?= campoCSRF() ?>
      <input type="hidden" name="accion" value="pendiente">
      <input type="hidden" name="proyecto_id" value="<?= (int) $proyectoVer['id'] ?>">
      <div class="grid2">
        <div class="field">
          <label for="descripcion">Nuevo pendiente</label>
          <input type="text" id="descripcion" name="descripcion" required maxlength="255">
        </div>
        <div class="field">
          <label for="fecha_compromiso_pendiente">Fecha compromiso</label>
          <input type="date" id="fecha_compromiso_pendiente" name="fecha_compromiso_pendiente" required>
        </div>
      </div>
      <div class="row"><button class="btn sec" type="submit">Agregar pendiente</button></div>
    </form>

    <?php if ($proyectoVer['oportunidad_etapa'] === 'aprobada'): ?>
      <form method="post" style="margin-top:14px">
        <?= campoCSRF() ?>
        <input type="hidden" name="accion" value="terminar">
        <input type="hidden" name="proyecto_id" value="<?= (int) $proyectoVer['id'] ?>">
        <button class="btn" type="submit">Terminar proyecto</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>

  <p style="margin-top:14px"><a class="btn sec" href="<?= URL_BASE ?>/proyectos.php">Volver al listado</a></p>

<?php else: ?>

  <h2>Nuevo proyecto</h2>
  <?php if ($oportunidadesDisponibles): ?>
    <form method="post" class="card" autocomplete="off">
      <?= campoCSRF() ?>
      <input type="hidden" name="accion" value="crear">
      <div class="grid2">
        <div class="field">
          <label for="oportunidad_id">Oportunidad aprobada</label>
          <select id="oportunidad_id" name="oportunidad_id" required>
            <option value="">Selecciona una oportunidad...</option>
            <?php foreach ($oportunidadesDisponibles as $o): ?>
              <option value="<?= (int) $o['id'] ?>"><?= htmlspecialchars($o['cliente_nombre'] . ' — ' . $o['descripcion']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="responsable_id">Responsable</label>
          <select id="responsable_id" name="responsable_id" required>
            <option value="">Selecciona un responsable...</option>
            <?php foreach ($responsables as $r): ?>
              <option value="<?= (int) $r['usuario_id'] ?>"><?= htmlspecialchars($r['nombre_completo']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="grid2">
        <div class="field">
          <label for="fecha_inicio">Fecha de inicio</label>
          <input type="date" id="fecha_inicio" name="fecha_inicio" required>
        </div>
        <div class="field">
          <label for="fecha_compromiso">Entrega comprometida</label>
          <input type="date" id="fecha_compromiso" name="fecha_compromiso" required>
        </div>
      </div>
      <div class="row"><button class="btn" type="submit">Crear proyecto</button></div>
    </form>
  <?php else: ?>
    <p class="empty">No hay oportunidades aprobadas sin proyecto todavía.</p>
  <?php endif; ?>

  <h2>Proyectos</h2>
  <div class="tw">
    <table>
      <thead><tr><th>Cliente</th><th>Proyecto</th><th>Responsable</th><th>Entrega comprometida</th><th>Estado</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($proyectos as $p): ?>
          <tr>
            <td><?= htmlspecialchars($p['cliente_nombre']) ?></td>
            <td><?= htmlspecialchars($p['oportunidad_descripcion']) ?></td>
            <td><?= htmlspecialchars($p['responsable_nombre']) ?></td>
            <td class="mono"><?= htmlspecialchars($p['fecha_compromiso']) ?></td>
            <td>
              <span class="pill p-<?= $p['oportunidad_etapa'] ?>"><?= htmlspecialchars(ucfirst($p['oportunidad_etapa'])) ?></span>
              <?php if ($p['atrasado']): ?><span class="pill p-rechazado">Atrasado</span><?php endif; ?>
            </td>
            <td><a class="btn sec sm" href="<?= URL_BASE ?>/proyectos.php?ver=<?= (int) $p['id'] ?>">Ver</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$proyectos): ?>
          <tr><td colspan="6" class="empty">Todavía no hay proyectos registrados.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/../views/layout/pie.php'; ?>

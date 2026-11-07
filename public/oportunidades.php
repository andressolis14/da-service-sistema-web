<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-11: seguimiento de oportunidades comerciales.
 * Acceso para los roles comerciales (Vendedor, Gerente) y Administrador.
 */
require_once __DIR__ . '/../src/config/app.php';
exigirSesion([ROL_VENDEDOR, ROL_GERENTE, ROL_ADMINISTRADOR]);

$usuario = usuarioActual();
$esVendedor = (int) $usuario['rol_id'] === ROL_VENDEDOR;
$mensaje = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarCSRF($_POST['csrf_token'] ?? null)) {
        $mensaje = ['err', 'La sesión del formulario expiró. Intenta de nuevo.'];
    } else {
        $accion = $_POST['accion'] ?? '';
        if ($accion === 'crear') {
            $vendedorId = $esVendedor ? (int) $usuario['id'] : (int) ($_POST['vendedor_id'] ?? 0);
            $resultado = crearOportunidad(
                (int) ($_POST['cliente_id'] ?? 0),
                $vendedorId,
                $_POST['descripcion'] ?? '',
                $_POST['valor_estimado'] ?? '',
                $_POST['moneda'] ?? ''
            );
            $mensaje = [$resultado['exito'] ? 'ok' : 'err', $resultado['mensaje']];
        } elseif ($accion === 'cambiar_etapa') {
            $resultado = cambiarEtapaOportunidad(
                (int) ($_POST['id'] ?? 0),
                $_POST['etapa'] ?? '',
                $_POST['motivo_perdida'] ?? ''
            );
            $mensaje = [$resultado['exito'] ? 'ok' : 'err', $resultado['mensaje']];
        }
    }
}

$oportunidadVerId = isset($_GET['ver']) ? (int) $_GET['ver'] : null;
$oportunidadVer = $oportunidadVerId ? obtenerOportunidad($oportunidadVerId) : null;

$clientes = listarClientes();
$vendedores = $esVendedor ? [] : listarVendedoresActivos();
$oportunidades = listarOportunidades();

$tituloPagina = 'Oportunidades';
$menu = [['/oportunidades.php', 'Oportunidades', 'HU-11']];
$vistaActual = '/oportunidades.php';
require __DIR__ . '/../views/layout/cabecera.php';
?>

<?php if ($mensaje): ?>
  <div class="msg <?= $mensaje[0] ?>"><?= htmlspecialchars($mensaje[1]) ?></div>
<?php endif; ?>

<?php if ($oportunidadVer): ?>
  <h2><?= htmlspecialchars($oportunidadVer['descripcion']) ?></h2>
  <p class="mono">
    Valor: <?= number_format((float) $oportunidadVer['valor_estimado'], 2) ?> <?= htmlspecialchars($oportunidadVer['moneda']) ?> ·
    <span class="pill p-<?= $oportunidadVer['etapa'] ?>"><?= htmlspecialchars(ucfirst($oportunidadVer['etapa'])) ?></span>
  </p>
  <?php if ($oportunidadVer['etapa'] === 'perdido' && $oportunidadVer['motivo_perdida']): ?>
    <p>Motivo de la pérdida: <?= htmlspecialchars($oportunidadVer['motivo_perdida']) ?></p>
  <?php endif; ?>

  <form method="post" class="card" autocomplete="off">
    <?= campoCSRF() ?>
    <input type="hidden" name="accion" value="cambiar_etapa">
    <input type="hidden" name="id" value="<?= (int) $oportunidadVer['id'] ?>">
    <div class="grid2">
      <div class="field">
        <label for="etapa">Nueva etapa</label>
        <select id="etapa" name="etapa" required>
          <?php foreach (OPORTUNIDAD_ETAPAS as $etapa): ?>
            <option value="<?= $etapa ?>"<?= $etapa === $oportunidadVer['etapa'] ? ' selected' : '' ?>><?= ucfirst($etapa) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="motivo_perdida">Motivo (obligatorio solo si pasa a "Perdido")</label>
        <input type="text" id="motivo_perdida" name="motivo_perdida" maxlength="255">
      </div>
    </div>
    <div class="row">
      <button class="btn" type="submit">Actualizar etapa</button>
      <a class="btn sec" href="<?= URL_BASE ?>/oportunidades.php">Volver al listado</a>
    </div>
  </form>

<?php else: ?>

  <h2>Nueva oportunidad</h2>
  <form method="post" class="card" autocomplete="off">
    <?= campoCSRF() ?>
    <input type="hidden" name="accion" value="crear">
    <div class="grid2">
      <div class="field">
        <label for="cliente_id">Cliente</label>
        <select id="cliente_id" name="cliente_id" required>
          <option value="">Selecciona un cliente...</option>
          <?php foreach ($clientes as $c): ?>
            <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if (!$esVendedor): ?>
        <div class="field">
          <label for="vendedor_id">Vendedor responsable</label>
          <select id="vendedor_id" name="vendedor_id" required>
            <option value="">Selecciona un vendedor...</option>
            <?php foreach ($vendedores as $v): ?>
              <option value="<?= (int) $v['usuario_id'] ?>"><?= htmlspecialchars($v['nombre_completo']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
    </div>
    <div class="field">
      <label for="descripcion">¿Qué se le está ofreciendo?</label>
      <input type="text" id="descripcion" name="descripcion" required maxlength="255">
    </div>
    <div class="grid2">
      <div class="field">
        <label for="valor_estimado">Valor estimado</label>
        <input type="text" id="valor_estimado" name="valor_estimado" required inputmode="decimal" placeholder="Ej: 15000000">
      </div>
      <div class="field">
        <label for="moneda">Moneda</label>
        <select id="moneda" name="moneda" required>
          <option value="COP" selected>COP</option>
        </select>
      </div>
    </div>
    <div class="row"><button class="btn" type="submit">Crear oportunidad</button></div>
  </form>

  <h2>Oportunidades</h2>
  <div class="tw">
    <table>
      <thead><tr><th>Cliente</th><th>Vendedor</th><th>Descripción</th><th>Valor</th><th>Etapa</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($oportunidades as $o): ?>
          <tr>
            <td><?= htmlspecialchars($o['cliente_nombre']) ?></td>
            <td><?= htmlspecialchars($o['vendedor_nombre']) ?></td>
            <td><?= htmlspecialchars($o['descripcion']) ?></td>
            <td class="num"><?= number_format((float) $o['valor_estimado'], 2) ?> <?= htmlspecialchars($o['moneda']) ?></td>
            <td><span class="pill p-<?= $o['etapa'] ?>"><?= htmlspecialchars(ucfirst($o['etapa'])) ?></span></td>
            <td><a class="btn sec sm" href="<?= URL_BASE ?>/oportunidades.php?ver=<?= (int) $o['id'] ?>">Ver / cambiar etapa</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$oportunidades): ?>
          <tr><td colspan="6" class="empty">Todavía no hay oportunidades registradas.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/../views/layout/pie.php'; ?>

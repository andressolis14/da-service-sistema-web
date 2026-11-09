<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-13: registro de desempeño comercial.
 * El vendedor registra y ve su propio desempeño diario y sus
 * recordatorios. Gerente y Administrador ven los de todos (solo
 * lectura: no registran a nombre de un vendedor).
 */
require_once __DIR__ . '/../src/config/app.php';
exigirSesion([ROL_VENDEDOR, ROL_GERENTE, ROL_ADMINISTRADOR]);

$usuario = usuarioActual();
$esVendedor = (int) $usuario['rol_id'] === ROL_VENDEDOR;
$mensaje = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$esVendedor) {
        http_response_code(403);
        echo 'Acceso denegado: solo un vendedor registra su propio desempeño o sus recordatorios.';
        exit;
    }
    if (!validarCSRF($_POST['csrf_token'] ?? null)) {
        $mensaje = ['err', 'La sesión del formulario expiró. Intenta de nuevo.'];
    } else {
        $accion = $_POST['accion'] ?? '';
        if ($accion === 'desempeno') {
            $resultado = registrarDesempenoDiario((int) $usuario['id'], $_POST['fecha'] ?? '', $_POST);
            $mensaje = [$resultado['exito'] ? 'ok' : 'err', $resultado['mensaje']];
        } elseif ($accion === 'recordatorio') {
            $resultado = registrarRecordatorio(
                (int) $usuario['id'],
                (int) ($_POST['cliente_id'] ?? 0),
                $_POST['nota'] ?? '',
                $_POST['fecha_recordatorio'] ?? ''
            );
            $mensaje = [$resultado['exito'] ? 'ok' : 'err', $resultado['mensaje']];
        }
    }
}

$miDesempeno = $esVendedor ? listarDesempenoDeVendedor((int) $usuario['id']) : [];
$misRecordatorios = $esVendedor ? listarRecordatoriosDeVendedor((int) $usuario['id']) : [];
$desempenoDeTodos = !$esVendedor ? listarDesempenoDeTodos() : [];
$recordatoriosDeTodos = !$esVendedor ? listarRecordatoriosDeTodos() : [];
$clientes = $esVendedor ? listarClientes() : [];

$tituloPagina = 'Desempeño comercial';
$menu = [['/desempeno.php', 'Desempeño', 'HU-13']];
$vistaActual = '/desempeno.php';
require __DIR__ . '/../views/layout/cabecera.php';
?>

<?php if ($mensaje): ?>
  <div class="msg <?= $mensaje[0] ?>"><?= htmlspecialchars($mensaje[1]) ?></div>
<?php endif; ?>

<?php if ($esVendedor): ?>
  <h2>Registrar desempeño del día</h2>
  <form method="post" class="card" autocomplete="off">
    <?= campoCSRF() ?>
    <input type="hidden" name="accion" value="desempeno">
    <div class="field" style="max-width:220px">
      <label for="fecha">Fecha</label>
      <input type="date" id="fecha" name="fecha" required max="<?= date('Y-m-d') ?>">
    </div>
    <div class="grid2">
      <div class="field">
        <label for="llamadas_totales">Llamadas totales</label>
        <input type="number" id="llamadas_totales" name="llamadas_totales" required min="0" value="0">
      </div>
      <div class="field">
        <label for="llamadas_contestadas">Llamadas contestadas</label>
        <input type="number" id="llamadas_contestadas" name="llamadas_contestadas" required min="0" value="0">
      </div>
      <div class="field">
        <label for="buzon">A buzón</label>
        <input type="number" id="buzon" name="buzon" required min="0" value="0">
      </div>
      <div class="field">
        <label for="interesados">Interesados</label>
        <input type="number" id="interesados" name="interesados" required min="0" value="0">
      </div>
      <div class="field">
        <label for="no_interesados">No interesados</label>
        <input type="number" id="no_interesados" name="no_interesados" required min="0" value="0">
      </div>
      <div class="field">
        <label for="pendientes_volver_llamar">Pendientes de volver a llamar</label>
        <input type="number" id="pendientes_volver_llamar" name="pendientes_volver_llamar" required min="0" value="0">
      </div>
      <div class="field">
        <label for="portafolios_enviados">Portafolios enviados</label>
        <input type="number" id="portafolios_enviados" name="portafolios_enviados" required min="0" value="0">
      </div>
    </div>
    <div class="row"><button class="btn" type="submit">Registrar desempeño</button></div>
  </form>

  <div class="tw">
    <table>
      <thead>
        <tr><th>Fecha</th><th>Llamadas</th><th>Contestadas</th><th>Buzón</th><th>Interesados</th><th>No interesados</th><th>Volver a llamar</th><th>Portafolios</th></tr>
      </thead>
      <tbody>
        <?php foreach ($miDesempeno as $d): ?>
          <tr>
            <td class="mono"><?= htmlspecialchars($d['fecha']) ?></td>
            <td class="num"><?= (int) $d['llamadas_totales'] ?></td>
            <td class="num"><?= (int) $d['llamadas_contestadas'] ?></td>
            <td class="num"><?= (int) $d['buzon'] ?></td>
            <td class="num"><?= (int) $d['interesados'] ?></td>
            <td class="num"><?= (int) $d['no_interesados'] ?></td>
            <td class="num"><?= (int) $d['pendientes_volver_llamar'] ?></td>
            <td class="num"><?= (int) $d['portafolios_enviados'] ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$miDesempeno): ?>
          <tr><td colspan="8" class="empty">Todavía no has registrado tu desempeño.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <hr style="border:0;border-top:1px solid var(--line);margin:28px 0">

  <h2>Nuevo recordatorio</h2>
  <form method="post" class="card" autocomplete="off">
    <?= campoCSRF() ?>
    <input type="hidden" name="accion" value="recordatorio">
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
      <div class="field">
        <label for="fecha_recordatorio">Fecha</label>
        <input type="date" id="fecha_recordatorio" name="fecha_recordatorio" required min="<?= date('Y-m-d') ?>">
      </div>
    </div>
    <div class="field">
      <label for="nota">Nota</label>
      <input type="text" id="nota" name="nota" required maxlength="255">
    </div>
    <div class="row"><button class="btn sec" type="submit">Registrar recordatorio</button></div>
  </form>

  <div class="tw">
    <table>
      <thead><tr><th>Fecha</th><th>Cliente</th><th>Nota</th></tr></thead>
      <tbody>
        <?php foreach ($misRecordatorios as $r): ?>
          <tr>
            <td class="mono"><?= htmlspecialchars($r['fecha_recordatorio']) ?></td>
            <td><?= htmlspecialchars($r['cliente_nombre']) ?></td>
            <td><?= htmlspecialchars($r['nota']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$misRecordatorios): ?>
          <tr><td colspan="3" class="empty">No tienes recordatorios pendientes.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

<?php else: ?>

  <h2>Desempeño de todos los vendedores</h2>
  <div class="tw">
    <table>
      <thead>
        <tr><th>Vendedor</th><th>Fecha</th><th>Llamadas</th><th>Contestadas</th><th>Buzón</th><th>Interesados</th><th>No interesados</th><th>Volver a llamar</th><th>Portafolios</th></tr>
      </thead>
      <tbody>
        <?php foreach ($desempenoDeTodos as $d): ?>
          <tr>
            <td><?= htmlspecialchars($d['vendedor_nombre']) ?></td>
            <td class="mono"><?= htmlspecialchars($d['fecha']) ?></td>
            <td class="num"><?= (int) $d['llamadas_totales'] ?></td>
            <td class="num"><?= (int) $d['llamadas_contestadas'] ?></td>
            <td class="num"><?= (int) $d['buzon'] ?></td>
            <td class="num"><?= (int) $d['interesados'] ?></td>
            <td class="num"><?= (int) $d['no_interesados'] ?></td>
            <td class="num"><?= (int) $d['pendientes_volver_llamar'] ?></td>
            <td class="num"><?= (int) $d['portafolios_enviados'] ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$desempenoDeTodos): ?>
          <tr><td colspan="9" class="empty">Todavía no hay registros de desempeño.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <hr style="border:0;border-top:1px solid var(--line);margin:28px 0">

  <h2>Recordatorios de todos los vendedores</h2>
  <div class="tw">
    <table>
      <thead><tr><th>Vendedor</th><th>Fecha</th><th>Cliente</th><th>Nota</th></tr></thead>
      <tbody>
        <?php foreach ($recordatoriosDeTodos as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['vendedor_nombre']) ?></td>
            <td class="mono"><?= htmlspecialchars($r['fecha_recordatorio']) ?></td>
            <td><?= htmlspecialchars($r['cliente_nombre']) ?></td>
            <td><?= htmlspecialchars($r['nota']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$recordatoriosDeTodos): ?>
          <tr><td colspan="4" class="empty">No hay recordatorios registrados.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/../views/layout/pie.php'; ?>

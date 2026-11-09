<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-14: reportes e indicadores.
 * Reportes 1-3 (soporte): Coordinador, Gerente, Administrador.
 * Reportes 4-5 (comerciales): Gerente, Administrador.
 * Todo se calcula por consulta (regla #10 de CLAUDE.md).
 */
require_once __DIR__ . '/../src/config/app.php';
exigirSesion([ROL_COORDINADOR, ROL_GERENTE, ROL_ADMINISTRADOR]);

$usuario = usuarioActual();
$esGerenciaOAdmin = in_array((int) $usuario['rol_id'], [ROL_GERENTE, ROL_ADMINISTRADOR], true);

$desde = trim($_GET['desde'] ?? '');
$hasta = trim($_GET['hasta'] ?? '');
$validacion = validarRangoPeriodo($desde, $hasta);
$errorPeriodo = $validacion['exito'] ? null : $validacion['mensaje'];
if ($errorPeriodo) {
    $desde = '';
    $hasta = '';
}
$desdeFiltro = $desde !== '' ? $desde : null;
$hastaFiltro = $hasta !== '' ? $hasta : null;

$tituloPagina = 'Reportes';
$menu = [['/reportes.php', 'Reportes', 'HU-14']];
$vistaActual = '/reportes.php';
require __DIR__ . '/../views/layout/cabecera.php';
?>

<?php if ($errorPeriodo): ?>
  <div class="msg err"><?= htmlspecialchars($errorPeriodo) ?></div>
<?php endif; ?>

<h2>Periodo</h2>
<form method="get" class="row card">
  <div class="field">
    <label for="desde">Desde</label>
    <input type="date" id="desde" name="desde" value="<?= htmlspecialchars($desde) ?>">
  </div>
  <div class="field">
    <label for="hasta">Hasta</label>
    <input type="date" id="hasta" name="hasta" value="<?= htmlspecialchars($hasta) ?>">
  </div>
  <div class="field" style="align-self:flex-end">
    <button class="btn" type="submit">Aplicar</button>
  </div>
</form>
<p class="mono" style="font-size:12px;color:var(--muted)">
  <?= ($desde || $hasta) ? ('Periodo aplicado: ' . htmlspecialchars($desde ?: 'inicio') . ' a ' . htmlspecialchars($hasta ?: 'hoy')) : 'Sin filtro: se muestra todo el histórico.' ?>
</p>

<?php
function formularioExportar(int $numero, string $desde, string $hasta): string
{
    return '<form method="post" action="' . URL_BASE . '/reporte_exportar.php" style="display:inline">'
        . campoCSRF()
        . '<input type="hidden" name="numero_reporte" value="' . $numero . '">'
        . '<input type="hidden" name="desde" value="' . htmlspecialchars($desde) . '">'
        . '<input type="hidden" name="hasta" value="' . htmlspecialchars($hasta) . '">'
        . '<button class="btn sec sm" type="submit">Exportar a Excel</button></form>';
}
?>

<hr style="border:0;border-top:1px solid var(--line);margin:28px 0">
<h2>1. Tickets por estado y tipo de solicitante</h2>
<?= formularioExportar(1, $desde, $hasta) ?>
<div class="tw" style="margin-top:10px">
  <table>
    <thead><tr><th>Estado</th><th>Tipo</th><th>Total</th></tr></thead>
    <tbody>
      <?php foreach (reporteTicketsPorEstado($desdeFiltro, $hastaFiltro) as $r): ?>
        <tr>
          <td><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $r['estado']))) ?></td>
          <td><?= htmlspecialchars(ucfirst($r['solicitante_tipo'])) ?></td>
          <td class="num"><?= (int) $r['total'] ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<hr style="border:0;border-top:1px solid var(--line);margin:28px 0">
<h2>2. Tiempo medio de atención</h2>
<?= formularioExportar(2, $desde, $hasta) ?>
<?php $tiempoMedio = reporteTiempoMedioAtencion($desdeFiltro, $hastaFiltro); ?>
<p style="margin-top:10px">
  Promedio general: <strong><?= $tiempoMedio['promedio_minutos'] ?? '—' ?></strong> minutos
  (<?= $tiempoMedio['tickets_cerrados'] ?> tickets cerrados considerados)
</p>

<hr style="border:0;border-top:1px solid var(--line);margin:28px 0">
<h2>3. Calificación promedio</h2>
<?= formularioExportar(3, $desde, $hasta) ?>
<?php $calificacion = reporteCalificacionPromedio($desdeFiltro, $hastaFiltro); ?>
<p style="margin-top:10px">
  Promedio general: <strong><?= $calificacion['general']['promedio'] ?? '—' ?></strong>
  (<?= (int) ($calificacion['general']['total'] ?? 0) ?> calificaciones)
</p>
<div class="tw">
  <table>
    <thead><tr><th>Técnico</th><th>Tickets calificados</th><th>Promedio</th></tr></thead>
    <tbody>
      <?php foreach ($calificacion['por_tecnico'] as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['tecnico']) ?></td>
          <td class="num"><?= (int) $r['tickets_calificados'] ?></td>
          <td class="num"><?= $r['promedio'] ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($esGerenciaOAdmin): ?>
  <hr style="border:0;border-top:1px solid var(--line);margin:28px 0">
  <h2>4. Oportunidades y valor por etapa</h2>
  <?= formularioExportar(4, $desde, $hasta) ?>
  <div class="tw" style="margin-top:10px">
    <table>
      <thead><tr><th>Etapa</th><th>Oportunidades</th><th>Valor total (COP)</th></tr></thead>
      <tbody>
        <?php foreach (reporteOportunidadesPorEtapa($desdeFiltro, $hastaFiltro) as $r): ?>
          <tr>
            <td><span class="pill p-<?= $r['etapa'] ?>"><?= htmlspecialchars(ucfirst($r['etapa'])) ?></span></td>
            <td class="num"><?= (int) $r['oportunidades'] ?></td>
            <td class="num"><?= number_format((float) $r['valor_total_cop'], 2) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <hr style="border:0;border-top:1px solid var(--line);margin:28px 0">
  <h2>5. Desempeño de vendedores</h2>
  <?= formularioExportar(5, $desde, $hasta) ?>
  <div class="tw" style="margin-top:10px">
    <table>
      <thead><tr><th>Vendedor</th><th>Llamadas</th><th>Contestadas</th><th>% contestadas</th><th>Interesados</th><th>Portafolios</th></tr></thead>
      <tbody>
        <?php foreach (reporteDesempenoVendedores($desdeFiltro, $hastaFiltro) as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['vendedor']) ?></td>
            <td class="num"><?= (int) $r['llamadas'] ?></td>
            <td class="num"><?= (int) $r['contestadas'] ?></td>
            <td class="num"><?= $r['pct_contestadas'] ?? '—' ?></td>
            <td class="num"><?= (int) $r['interesados'] ?></td>
            <td class="num"><?= (int) $r['portafolios'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../views/layout/pie.php'; ?>

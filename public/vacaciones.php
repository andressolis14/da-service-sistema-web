<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-05: consulta de vacaciones disponibles.
 * Cualquier cuenta consulta su propio saldo. El jefe directo consulta
 * el de su equipo; Contabilidad y Administrador, el de cualquiera.
 */
require_once __DIR__ . '/../src/config/app.php';
exigirSesion();

$usuario = usuarioActual();
$empleadoPropioId = (int) $usuario['empleado_id'];
$esGestionNomina = in_array((int) $usuario['rol_id'], [ROL_CONTABILIDAD, ROL_ADMINISTRADOR], true);
$miEquipo = listarEquipoDe($empleadoPropioId);

$empleadoConsultadoId = isset($_GET['empleado']) ? (int) $_GET['empleado'] : $empleadoPropioId;

if ($empleadoConsultadoId !== $empleadoPropioId && !$esGestionNomina) {
    $objetivo = obtenerEmpleado($empleadoConsultadoId);
    $esSuJefe = $objetivo && (int) ($objetivo['jefe_id'] ?? 0) === $empleadoPropioId;
    if (!$esSuJefe) {
        http_response_code(403);
        echo 'Acceso denegado: solo puedes consultar tus propias vacaciones, o las de tu equipo si eres su jefe directo.';
        exit;
    }
}

$miSaldo = calcularSaldoVacaciones($empleadoPropioId);
$saldoConsultado = $empleadoConsultadoId !== $empleadoPropioId ? calcularSaldoVacaciones($empleadoConsultadoId) : null;
$empleadosParaConsultar = $esGestionNomina
    ? array_values(array_filter(listarEmpleados(), fn($e) => $e['estado'] === 'activo'))
    : $miEquipo;

$tituloPagina = 'Vacaciones';
$menu = [['/vacaciones.php', 'Vacaciones', 'HU-05']];
$vistaActual = '/vacaciones.php';
require __DIR__ . '/../views/layout/cabecera.php';
?>

<h2>Mis vacaciones</h2>
<?php if ($miSaldo): ?>
  <div class="tw">
    <table>
      <thead><tr><th>Fecha de ingreso</th><th>Días acumulados</th><th>Días tomados</th><th>Días disponibles</th></tr></thead>
      <tbody>
        <tr>
          <td class="mono"><?= htmlspecialchars($miSaldo['fecha_ingreso']) ?></td>
          <td class="num"><?= $miSaldo['dias_acumulados'] ?></td>
          <td class="num"><?= $miSaldo['dias_tomados'] ?></td>
          <td class="num"><strong><?= $miSaldo['dias_disponibles'] ?></strong></td>
        </tr>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php if ($empleadosParaConsultar): ?>
  <hr style="border:0;border-top:1px solid var(--line);margin:28px 0">
  <h2><?= $esGestionNomina ? 'Consultar las vacaciones de un empleado' : 'Vacaciones de mi equipo' ?></h2>
  <form method="get" class="row">
    <select name="empleado">
      <option value="">Selecciona un empleado...</option>
      <?php foreach ($empleadosParaConsultar as $e): ?>
        <option value="<?= (int) $e['id'] ?>"<?= $empleadoConsultadoId === (int) $e['id'] ? ' selected' : '' ?>>
          <?= htmlspecialchars($e['nombre_completo']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button class="btn sec" type="submit">Consultar</button>
  </form>

  <?php if ($saldoConsultado): ?>
    <div class="tw" style="margin-top:14px">
      <table>
        <thead><tr><th>Empleado</th><th>Fecha de ingreso</th><th>Días acumulados</th><th>Días tomados</th><th>Días disponibles</th></tr></thead>
        <tbody>
          <tr>
            <td><?= htmlspecialchars($saldoConsultado['nombre_completo']) ?></td>
            <td class="mono"><?= htmlspecialchars($saldoConsultado['fecha_ingreso']) ?></td>
            <td class="num"><?= $saldoConsultado['dias_acumulados'] ?></td>
            <td class="num"><?= $saldoConsultado['dias_tomados'] ?></td>
            <td class="num"><strong><?= $saldoConsultado['dias_disponibles'] ?></strong></td>
          </tr>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../views/layout/pie.php'; ?>

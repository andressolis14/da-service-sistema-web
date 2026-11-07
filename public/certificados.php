<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-03: certificado laboral en PDF.
 * Cualquier cuenta genera el suyo (autoservicio). Contabilidad y
 * Administrador también pueden generarlo para cualquier empleado activo.
 */
require_once __DIR__ . '/../src/config/app.php';
exigirSesion();

$usuario = usuarioActual();
$esGestionNomina = in_array((int) $usuario['rol_id'], [ROL_CONTABILIDAD, ROL_ADMINISTRADOR], true);

$miDato = obtenerDatosCertificado((int) $usuario['empleado_id']);
$empleadosActivos = $esGestionNomina ? array_values(array_filter(listarEmpleados(), fn($e) => $e['estado'] === 'activo')) : [];

$tituloPagina = 'Certificado laboral';
$menu = [['/certificados.php', 'Certificados', 'HU-03']];
$vistaActual = '/certificados.php';
require __DIR__ . '/../views/layout/cabecera.php';
?>

<h2>Mi certificado laboral</h2>
<?php if ($miDato && $miDato['estado'] === 'activo'): ?>
  <p>
    Se generará en PDF con tus datos actuales: <?= htmlspecialchars($miDato['nombre_completo']) ?>,
    cargo <?= htmlspecialchars($miDato['cargo']) ?>, área <?= htmlspecialchars($miDato['area_nombre']) ?>.
  </p>
  <form method="post" action="<?= URL_BASE ?>/certificado_generar.php">
    <?= campoCSRF() ?>
    <input type="hidden" name="empleado_id" value="<?= (int) $usuario['empleado_id'] ?>">
    <button class="btn" type="submit">Generar mi certificado (PDF)</button>
  </form>
<?php else: ?>
  <p class="empty">No se encontró tu registro de empleado activo.</p>
<?php endif; ?>

<?php if ($esGestionNomina): ?>
  <hr style="border:0;border-top:1px solid var(--line);margin:28px 0">
  <h2>Generar certificado para un empleado</h2>
  <div class="tw">
    <table>
      <thead><tr><th>Nombre</th><th>Documento</th><th>Cargo</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($empleadosActivos as $e): ?>
          <tr>
            <td><?= htmlspecialchars($e['nombre_completo']) ?></td>
            <td class="num"><?= htmlspecialchars($e['documento']) ?></td>
            <td><?= htmlspecialchars($e['cargo']) ?></td>
            <td>
              <form method="post" action="<?= URL_BASE ?>/certificado_generar.php" style="display:inline">
                <?= campoCSRF() ?>
                <input type="hidden" name="empleado_id" value="<?= (int) $e['id'] ?>">
                <button class="btn sec sm" type="submit">Generar certificado</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../views/layout/pie.php'; ?>

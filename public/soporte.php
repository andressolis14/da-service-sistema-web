<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-06: formulario público de soporte técnico (sin cuenta).
 * RNF-03: no se envía sin aceptar el aviso de tratamiento de datos.
 */
require_once __DIR__ . '/../src/config/app.php';

$error = '';
$ticketCreado = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarCSRF($_POST['csrf_token'] ?? null)) {
        $error = 'La sesión del formulario expiró. Intenta de nuevo.';
    } else {
        $resultado = crearTicketPublico($_POST);
        if ($resultado['exito']) {
            $ticketCreado = $resultado['id'];
        } else {
            $error = $resultado['mensaje'];
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Soporte técnico — Sistema web D.A SERVICE S.A.S.</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= URL_BASE ?>/assets/css/estilo.css">
</head>
<body class="pantalla-acceso">
  <div class="tarjeta-acceso ancha">
    <div style="text-align:center">
      <div class="logo-acceso">DA</div>
      <h1 class="titulo-acceso">Solicitud de soporte técnico</h1>
      <p class="subtitulo-acceso">No necesitas cuenta para reportar un problema</p>
    </div>

    <?php if ($ticketCreado): ?>
      <div class="msg ok" style="font-size:14px">
        <strong>Solicitud recibida.</strong> Tu número de ticket es <strong>#<?= $ticketCreado ?></strong>.
        Guárdalo junto con el correo que diste: lo vas a necesitar para consultar el estado más adelante.
      </div>
      <p style="text-align:center;margin-top:1rem">
        <a href="<?= URL_BASE ?>/consultar_ticket.php">Consultar el estado de una solicitud</a> ·
        <a href="<?= URL_BASE ?>/soporte.php">Reportar otra solicitud</a>
      </p>
    <?php else: ?>
      <?php if ($error): ?>
        <div class="msg err" style="margin-bottom:1.25rem"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="post" class="card" autocomplete="off" style="border:0;padding:0">
        <?= campoCSRF() ?>
        <div class="grid2">
          <div class="field">
            <label for="solicitante_nombre">Nombre o empresa</label>
            <input type="text" id="solicitante_nombre" name="solicitante_nombre" required
                   value="<?= htmlspecialchars($_POST['solicitante_nombre'] ?? '') ?>">
          </div>
          <div class="field">
            <label for="solicitante_correo">Correo</label>
            <input type="text" id="solicitante_correo" name="solicitante_correo" required
                   value="<?= htmlspecialchars($_POST['solicitante_correo'] ?? '') ?>">
          </div>
        </div>
        <div class="grid2">
          <div class="field">
            <label for="solicitante_telefono">Teléfono (opcional)</label>
            <input type="text" id="solicitante_telefono" name="solicitante_telefono"
                   value="<?= htmlspecialchars($_POST['solicitante_telefono'] ?? '') ?>">
          </div>
          <div class="field">
            <label for="solicitante_tipo">¿Quién reporta?</label>
            <select id="solicitante_tipo" name="solicitante_tipo" required>
              <option value="">Selecciona una opción</option>
              <?php $tipoSel = $_POST['solicitante_tipo'] ?? ''; ?>
              <option value="interno"<?= $tipoSel === 'interno' ? ' selected' : '' ?>>Soy empleado de D.A SERVICE</option>
              <option value="externo"<?= $tipoSel === 'externo' ? ' selected' : '' ?>>Soy cliente externo</option>
            </select>
          </div>
        </div>
        <div class="field">
          <label for="asunto">Asunto</label>
          <input type="text" id="asunto" name="asunto" required
                 value="<?= htmlspecialchars($_POST['asunto'] ?? '') ?>">
        </div>
        <div class="field">
          <label for="descripcion">Descripción</label>
          <textarea id="descripcion" name="descripcion" required><?= htmlspecialchars($_POST['descripcion'] ?? '') ?></textarea>
        </div>

        <div class="rule" style="font-size:12px;max-height:140px;overflow-y:auto">
          <b>Aviso de tratamiento de datos personales (Ley 1581 de 2012).</b>
          D.A SERVICE S.A.S. recolecta los datos de este formulario (nombre, correo, teléfono) únicamente
          para dar trámite a tu solicitud de soporte técnico y contactarte sobre su avance. No se comparten
          con terceros ni se usan para otro fin. Puedes solicitar la actualización o eliminación de tus datos
          escribiendo al correo de contacto de la empresa.
        </div>
        <label class="chk">
          <input type="checkbox" name="aviso_aceptado" value="1" required>
          Acepto el aviso de tratamiento de datos personales.
        </label>

        <div class="row"><button class="btn" type="submit">Enviar solicitud</button></div>
      </form>
    <?php endif; ?>

    <div class="pie-acceso">
      <a href="<?= URL_BASE ?>/consultar_ticket.php">¿Ya reportaste algo? Consulta el estado</a> ·
      <a href="<?= URL_BASE ?>/login.php">¿Eres parte del equipo? Inicia sesión</a>
    </div>
  </div>
</body>
</html>

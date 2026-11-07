<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-09: calificación del servicio.
 * El cliente accede sin cuenta, con número de ticket y correo, igual
 * que consultar_ticket.php (mismo mensaje de error uniforme y el mismo
 * límite de intentos fallidos: comparten consultaTicketPermitida()).
 * Solo se puede calificar un ticket cerrado, una sola vez (regla #7 de
 * CLAUDE.md).
 */
require_once __DIR__ . '/../src/config/app.php';

$mensaje = null;
$exito = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!consultaTicketPermitida()) {
        $mensaje = ['err', 'Hiciste demasiados intentos. Espera unos minutos antes de volver a intentarlo.'];
    } elseif (!validarCSRF($_POST['csrf_token'] ?? null)) {
        $mensaje = ['err', 'La sesión del formulario expiró. Intenta de nuevo.'];
    } else {
        $resultado = registrarCalificacion(
            (int) ($_POST['numero_ticket'] ?? 0),
            $_POST['correo'] ?? '',
            (int) ($_POST['puntaje'] ?? 0),
            $_POST['comentario'] ?? ''
        );
        if ($resultado['exito']) {
            $exito = true;
            $mensaje = ['ok', $resultado['mensaje']];
        } else {
            if (!empty($resultado['generico'])) {
                registrarIntentoFallidoConsultaTicket();
            }
            $mensaje = ['err', $resultado['mensaje']];
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Calificar el servicio — Sistema web D.A SERVICE S.A.S.</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= URL_BASE ?>/assets/css/estilo.css">
</head>
<body class="pantalla-acceso">
  <div class="tarjeta-acceso ancha">
    <div style="text-align:center">
      <div class="logo-acceso">DA</div>
      <h1 class="titulo-acceso">Calificar el servicio</h1>
      <p class="subtitulo-acceso">Solo se puede calificar una solicitud ya cerrada</p>
    </div>

    <?php if ($mensaje): ?>
      <div class="msg <?= $mensaje[0] ?>" style="margin-bottom:1rem">
        <?= htmlspecialchars($mensaje[1]) ?>
      </div>
    <?php endif; ?>

    <?php if (!$exito): ?>
      <form method="post" class="card" autocomplete="off" style="border:0;padding:0">
        <?= campoCSRF() ?>
        <div class="grid2">
          <div class="field">
            <label for="numero_ticket">Número de ticket</label>
            <input type="number" id="numero_ticket" name="numero_ticket" required min="1"
                   value="<?= htmlspecialchars($_POST['numero_ticket'] ?? '') ?>">
          </div>
          <div class="field">
            <label for="correo">Correo</label>
            <input type="text" id="correo" name="correo" required
                   value="<?= htmlspecialchars($_POST['correo'] ?? '') ?>">
          </div>
        </div>
        <div class="field">
          <label for="puntaje">Calificación (1 a 5)</label>
          <select id="puntaje" name="puntaje" required>
            <option value="">Selecciona...</option>
            <?php for ($i = 5; $i >= 1; $i--): ?>
              <option value="<?= $i ?>"><?= $i ?> — <?= str_repeat('★', $i) . str_repeat('☆', 5 - $i) ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="field">
          <label for="comentario">Comentario (opcional)</label>
          <textarea id="comentario" name="comentario" maxlength="255"><?= htmlspecialchars($_POST['comentario'] ?? '') ?></textarea>
        </div>
        <div class="row"><button class="btn" type="submit">Enviar calificación</button></div>
      </form>
    <?php endif; ?>

    <div class="pie-acceso">
      <a href="<?= URL_BASE ?>/consultar_ticket.php">Consultar el estado de una solicitud</a> ·
      <a href="<?= URL_BASE ?>/login.php">¿Eres parte del equipo? Inicia sesión</a>
    </div>
  </div>
</body>
</html>

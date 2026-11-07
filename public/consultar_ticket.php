<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * HU-08: consulta pública del estado de un ticket.
 * El cliente nunca inicia sesión; el número de ticket junto con el
 * correo que dio al reportarlo funciona como credencial (regla #7 de
 * CLAUDE.md: "el cliente consulta con número de ticket y correo"). No
 * se muestra el detalle interno de los avances (comentarios internos,
 * nombre del técnico), solo el estado y las fechas.
 *
 * Sin enumeración: se devuelve exactamente el mismo mensaje tanto si el
 * número de ticket no existe como si existe pero el correo no coincide
 * (buscarTicketPorIdYCorreo valida ambos en una sola consulta), y hay
 * un límite de intentos fallidos por sesión para dificultar probar
 * combinaciones al azar.
 */
require_once __DIR__ . '/../src/config/app.php';

$ticket = null;
$buscado = false;
$limiteExcedido = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $buscado = true;
    if (!consultaTicketPermitida()) {
        $limiteExcedido = true;
    } else {
        $ticketId = (int) ($_POST['numero_ticket'] ?? 0);
        $correo = $_POST['correo'] ?? '';
        $ticket = buscarTicketPorIdYCorreo($ticketId, $correo);
        if (!$ticket) {
            registrarIntentoFallidoConsultaTicket();
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Consultar una solicitud — Sistema web D.A SERVICE S.A.S.</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= URL_BASE ?>/assets/css/estilo.css">
</head>
<body class="pantalla-acceso">
  <div class="tarjeta-acceso ancha">
    <div style="text-align:center">
      <div class="logo-acceso">DA</div>
      <h1 class="titulo-acceso">Consultar una solicitud</h1>
      <p class="subtitulo-acceso">Ingresa el número de ticket y el correo que diste al reportarlo</p>
    </div>

    <form method="post" class="card" autocomplete="off" style="border:0;padding:0">
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
      <div class="row"><button class="btn" type="submit">Consultar</button></div>
    </form>

    <?php if ($buscado): ?>
      <?php if ($limiteExcedido): ?>
        <div class="msg err" style="margin-top:1rem">
          Hiciste demasiados intentos. Espera unos minutos antes de volver a consultar.
        </div>
      <?php elseif ($ticket): ?>
        <div class="msg ok" style="margin-top:1rem">
          <strong>#<?= (int) $ticket['id'] ?> — <?= htmlspecialchars($ticket['asunto']) ?></strong><br>
          Estado: <span class="pill p-<?= $ticket['estado'] ?>"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $ticket['estado']))) ?></span><br>
          Reportado el <?= htmlspecialchars($ticket['fecha_creacion']) ?>
          <?php $fechaCierre = obtenerFechaCierre((int) $ticket['id']); ?>
          <?php if ($fechaCierre): ?><br>Cerrado el <?= htmlspecialchars($fechaCierre) ?><?php endif; ?>
        </div>
        <?php if ($ticket['estado'] === 'cerrado' && !obtenerCalificacion((int) $ticket['id'])): ?>
          <p style="text-align:center;margin-top:1rem">
            <a href="<?= URL_BASE ?>/calificar_ticket.php">Calificar este servicio</a>
          </p>
        <?php endif; ?>
      <?php else: ?>
        <div class="msg err" style="margin-top:1rem">No se encontró ninguna solicitud con ese número y ese correo.</div>
      <?php endif; ?>
    <?php endif; ?>

    <div class="pie-acceso"><a href="<?= URL_BASE ?>/login.php">¿Eres parte del equipo? Inicia sesión</a></div>
  </div>
</body>
</html>

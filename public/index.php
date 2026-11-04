<?php
/**
 * Punto de entrada temporal para comprobar el entorno (RNF-05).
 * Cuando exista el login (RNF-01), esta página pasará a redirigir
 * según haya o no sesión activa.
 */
require_once __DIR__ . '/../src/config/app.php';

$chequeos = [];

// 1. Conexión a la base de datos con el perfil da_app
try {
    $pdo = obtenerConexion();
    $total = $pdo->query('SELECT COUNT(*) FROM empleado')->fetchColumn();
    $chequeos[] = ['ok', "Conexión a la base de datos (perfil da_app): $total empleados de prueba."];
} catch (Throwable $e) {
    $chequeos[] = ['err', 'Conexión a la base de datos: ' . $e->getMessage()];
}

// 2. Librerías de Composer
$chequeos[] = [class_exists(\Dompdf\Dompdf::class) ? 'ok' : 'err', 'Dompdf (generación de PDF) ' . (class_exists(\Dompdf\Dompdf::class) ? 'cargado correctamente.' : 'no se encontró.')];
$chequeos[] = [class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class) ? 'ok' : 'err', 'PhpSpreadsheet (reportes Excel) ' . (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class) ? 'cargado correctamente.' : 'no se encontró.')];

// 3. Sesión
$chequeos[] = ['ok', 'Sesión de PHP activa, nombre de cookie: ' . session_name() . '.'];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Entorno — Sistema web D.A SERVICE S.A.S.</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="<?= URL_BASE ?>/assets/css/estilo.css">
</head>
<body>
<div class="wrap">
  <header class="app-header">
    <div>
      <h1>Sistema web D.A SERVICE S.A.S.</h1>
      <p class="sub">Comprobación de entorno (RNF-05)</p>
    </div>
  </header>
  <main class="app-main" style="margin-top:0">
    <h2>Estado del entorno</h2>
    <?php foreach ($chequeos as [$estado, $texto]): ?>
      <div class="msg <?= $estado ?>"><?= htmlspecialchars($texto) ?></div>
    <?php endforeach; ?>
    <div class="rule"><b>Siguiente paso.</b> RNF-01: cuentas y sesión (login con <code>password_hash</code>).</div>
  </main>
</div>
</body>
</html>

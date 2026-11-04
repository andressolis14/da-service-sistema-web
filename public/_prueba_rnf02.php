<?php
/**
 * Página temporal para probar RNF-02 (control de acceso por rol).
 * Solo el administrador puede entrar aquí; cualquier otro rol debe
 * recibir 403. Se elimina cuando HU-01 (empleados) construya la
 * pantalla real que reemplaza a esta prueba.
 */
require_once __DIR__ . '/../src/config/app.php';
exigirSesion([ROL_ADMINISTRADOR]);

$usuario = usuarioActual();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Prueba RNF-02 — Sistema web D.A SERVICE S.A.S.</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="<?= URL_BASE ?>/assets/css/estilo.css">
</head>
<body>
<div class="wrap">
  <header class="app-header">
    <div><h1>Sistema web D.A SERVICE S.A.S.</h1><p class="sub">Prueba de control de acceso (RNF-02)</p></div>
  </header>
  <main class="app-main" style="margin-top:0">
    <div class="msg ok">
      Entraste como <?= htmlspecialchars($usuario['nombre_completo']) ?>
      (rol id <?= (int) $usuario['rol_id'] ?>, administrador). Si otro rol intenta esta misma URL,
      debe recibir 403.
    </div>
  </main>
</div>
</body>
</html>

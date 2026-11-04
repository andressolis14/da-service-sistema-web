<?php
/**
 * Cabecera común de toda pantalla autenticada.
 * Espera en el ámbito que la incluye:
 *   string $tituloPagina
 *   array  $menu   lista de [ruta, etiqueta, hu] del rol actual
 *   string $vistaActual  ruta activa, para resaltar en el menú
 * Usa helpers de src/config/app.php: usuarioActual(), nombreRolActual()
 */
$usuario = usuarioActual();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($tituloPagina ?? 'Sistema web D.A SERVICE S.A.S.') ?></title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="<?= URL_BASE ?>/assets/css/estilo.css">
</head>
<body>
<div class="wrap">
  <header class="app-header">
    <div>
      <h1>Sistema web D.A SERVICE S.A.S.</h1>
      <p class="sub"><?= htmlspecialchars($tituloPagina ?? '') ?></p>
    </div>
    <div class="who">
      <span class="nombre"><?= htmlspecialchars($usuario['nombre_usuario'] ?? '') ?></span>
      <span class="rol"><?= htmlspecialchars(nombreRolActual() ?? '') ?></span>
      <a href="<?= URL_BASE ?>/logout.php">Cerrar sesión</a>
    </div>
  </header>
  <div class="app">
    <nav class="app-nav" aria-label="Menú del rol">
      <div class="rol-nav"><?= htmlspecialchars(nombreRolActual() ?? '') ?></div>
      <?php foreach (($menu ?? []) as [$ruta, $etiqueta, $hu]): ?>
        <a href="<?= htmlspecialchars($ruta) ?>"<?= ($vistaActual ?? '') === $ruta ? ' class="activo"' : '' ?>>
          <?= htmlspecialchars($etiqueta) ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <main class="app-main">

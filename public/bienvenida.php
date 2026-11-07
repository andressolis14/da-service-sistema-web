<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * Pantalla de bienvenida tras el acceso: confirma la sesión y el rol,
 * y muestra los módulos de ese rol (se activan a medida que se
 * construye cada historia).
 */
require_once __DIR__ . '/../src/config/app.php';
exigirSesion();

$usuario = usuarioActual();

// Módulos por rol (etiqueta, ícono, historia). 'ruta' queda null hasta que
// la historia correspondiente esté construida.
// Se indexa por el id del rol (constantes ROL_*), nunca por el nombre
// escrito a mano (eso fue lo que causó el defecto de "técnico" en RNF-01).
$modulosPorRol = [
    ROL_EMPLEADO      => [
        ['Mis desprendibles',        'fa-file-invoice-dollar', 'HU-02', null],
        ['Mis certificados',         'fa-file-signature',      'HU-03', null],
        ['Solicitar un permiso',     'fa-calendar-check',      'HU-04', null],
        ['Mis vacaciones',           'fa-umbrella-beach',      'HU-05', null],
    ],
    ROL_JEFE          => [
        ['Permisos de mi equipo',    'fa-user-check',          'HU-04', null],
    ],
    ROL_CONTABILIDAD  => [
        ['Empleados',                'fa-id-card',              'HU-01', null],
        ['Cargar desprendible',      'fa-file-upload',          'HU-02', null],
    ],
    ROL_TECNICO       => [
        ['Mis tickets asignados',    'fa-screwdriver-wrench',   'HU-08', null],
    ],
    ROL_COORDINADOR   => [
        ['Todos los tickets',        'fa-headset',              'HU-06 · HU-07 · HU-08', null],
    ],
    ROL_VENDEDOR      => [
        ['Clientes',                 'fa-building',             'HU-10', '/clientes.php'],
        ['Oportunidades',            'fa-handshake',            'HU-11 · HU-15', null],
        ['Proyectos',                'fa-diagram-project',      'HU-16', null],
    ],
    ROL_GERENTE       => [
        ['Clientes',                 'fa-building',             'HU-10', '/clientes.php'],
        ['Indicadores comerciales',  'fa-chart-line',           'HU-13', null],
        ['Reportes',                 'fa-file-excel',           'HU-14', null],
    ],
    ROL_ADMINISTRADOR => [
        ['Empleados y cuentas',      'fa-users-gear',           'HU-01 · RNF-02', null],
        ['Clientes',                 'fa-building',             'HU-10', '/clientes.php'],
    ],
];
$modulos = $modulosPorRol[(int) $usuario['rol_id']] ?? [];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bienvenida — Sistema web D.A SERVICE S.A.S.</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= URL_BASE ?>/assets/css/estilo.css">
</head>
<body class="pantalla-acceso">
  <div class="tarjeta-acceso ancha">
    <div style="text-align:center">
      <div class="logo-acceso">DA</div>
      <h1 class="titulo-acceso">Hola, <?= htmlspecialchars(explode(' ', $usuario['nombre_completo'])[0]) ?></h1>
      <p class="subtitulo-acceso">
        <?= htmlspecialchars($usuario['nombre_completo']) ?>
        <span class="pill p-aprobado" style="margin-left:6px"><?= htmlspecialchars(ucfirst($usuario['rol_nombre'])) ?></span>
      </p>
    </div>

    <div class="rejilla-modulos">
      <?php foreach ($modulos as [$titulo, $icono, $hu, $ruta]): ?>
        <?php if ($ruta): ?>
          <a class="tarjeta-modulo" href="<?= htmlspecialchars(URL_BASE . $ruta) ?>">
        <?php else: ?>
          <div class="tarjeta-modulo" style="opacity:.55;cursor:not-allowed" title="Aún no construido">
        <?php endif; ?>
          <div class="icono"><i class="fas <?= htmlspecialchars($icono) ?>"></i></div>
          <div class="titulo"><?= htmlspecialchars($titulo) ?></div>
          <div class="descripcion mono"><?= htmlspecialchars($hu) ?><?= $ruta ? '' : ' · próximamente' ?></div>
        <?php echo $ruta ? '</a>' : '</div>'; ?>
      <?php endforeach; ?>
      <?php if (!$modulos): ?>
        <p class="empty">Tu rol todavía no tiene módulos asignados.</p>
      <?php endif; ?>
    </div>

    <div class="pie-acceso"><a href="<?= URL_BASE ?>/logout.php">Cerrar sesión</a></div>
  </div>
</body>
</html>

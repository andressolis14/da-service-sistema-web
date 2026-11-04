<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * RNF-01: acceso al sistema (cuentas y sesión).
 */
require_once __DIR__ . '/../src/config/app.php';

if (estaAutenticado()) {
    header('Location: ' . URL_BASE . '/bienvenida.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarCSRF($_POST['csrf_token'] ?? null)) {
        $error = 'La sesión del formulario expiró. Intenta de nuevo.';
    } else {
        $nombreUsuario = trim($_POST['nombre_usuario'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($nombreUsuario === '' || $password === '') {
            $error = 'Escribe tu usuario y tu contraseña.';
        } else {
            $datos = autenticar($nombreUsuario, $password);
            if ($datos === null) {
                $error = 'Usuario o contraseña incorrectos.';
            } else {
                iniciarSesionUsuario($datos);
                header('Location: ' . URL_BASE . '/bienvenida.php');
                exit;
            }
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Acceso — Sistema web D.A SERVICE S.A.S.</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= URL_BASE ?>/assets/css/estilo.css">
</head>
<body class="pantalla-acceso">
  <div class="tarjeta-acceso">
    <div class="logo-acceso">DA</div>
    <h1 class="titulo-acceso">Acceso al sistema</h1>
    <p class="subtitulo-acceso">D.A SERVICE S.A.S. — ingresa tus credenciales</p>

    <?php if ($error): ?>
      <div class="msg err" role="alert" style="margin-bottom:1.25rem"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <?= campoCSRF() ?>
      <div class="campo-acceso">
        <label for="nombre_usuario">Usuario</label>
        <input type="text" id="nombre_usuario" name="nombre_usuario" required autofocus
               value="<?= htmlspecialchars($_POST['nombre_usuario'] ?? '') ?>">
      </div>
      <div class="campo-acceso">
        <label for="password">Contraseña</label>
        <div class="grupo-clave">
          <input type="password" id="password" name="password" required>
          <button type="button" class="alternar-clave" id="alternarClave" aria-label="Mostrar contraseña">
            <i class="fas fa-eye"></i>
          </button>
        </div>
      </div>
      <button type="submit" class="boton-acceso">Iniciar sesión</button>
    </form>

    <div class="pie-acceso">D.A SERVICE S.A.S. — Yumbo, Valle del Cauca</div>
  </div>
  <script>
    document.getElementById('alternarClave').addEventListener('click', function () {
      const campo = document.getElementById('password');
      const icono = this.querySelector('i');
      const esTexto = campo.type === 'text';
      campo.type = esTexto ? 'password' : 'text';
      icono.classList.toggle('fa-eye', esTexto);
      icono.classList.toggle('fa-eye-slash', !esTexto);
    });
  </script>
</body>
</html>

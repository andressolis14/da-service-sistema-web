<?php
/**
 * Punto de entrada de la aplicación.
 */
require_once __DIR__ . '/../src/config/app.php';

header('Location: ' . URL_BASE . (estaAutenticado() ? '/bienvenida.php' : '/login.php'));
exit;

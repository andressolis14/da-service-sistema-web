<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * Arranque de la aplicación: sesión, dependencias de Composer, conexión a BD
 * y funciones de ayuda para autenticación/roles/CSRF.
 *
 * Decisión técnica DT-14: la protección CSRF (token por sesión, validado con
 * hash_equals antes de aceptar cualquier formulario) se implementa aquí como
 * parte de RNF-02, en vez de abrir un RNF-06 nuevo (acordado con el equipo
 * de documentación).
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../auth/roles.php';
require_once __DIR__ . '/../auth/autenticacion.php';
require_once __DIR__ . '/../tickets/crear_ticket.php';
require_once __DIR__ . '/../empleados/empleados.php';

// URL base de la aplicación (ver Alias /daservice en httpd.conf -> /public).
define('URL_BASE', '/daservice');

// Sesión
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
if (session_status() === PHP_SESSION_NONE) {
    session_name('DASERVICE_SESS');
    session_start();
}

date_default_timezone_set('America/Bogota');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// --- CSRF (DT-14) ---------------------------------------------------
function tokenCSRF(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validarCSRF(?string $token): bool
{
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string) $token);
}

/** Imprime el campo oculto con el token, para usar dentro de cada <form>. */
function campoCSRF(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(tokenCSRF()) . '">';
}

// --- Autenticación y roles -------------------------------------------
function estaAutenticado(): bool
{
    return !empty($_SESSION['usuario_id']);
}

function usuarioActual(): ?array
{
    if (!estaAutenticado()) {
        return null;
    }
    return [
        'id'              => $_SESSION['usuario_id'],
        'empleado_id'     => $_SESSION['empleado_id'],
        'nombre_usuario'  => $_SESSION['nombre_usuario'],
        'nombre_completo' => $_SESSION['nombre_completo'],
        'rol_id'          => $_SESSION['rol_id'],
        'rol_nombre'      => $_SESSION['rol_nombre'],
    ];
}

function nombreRolActual(): ?string
{
    return $_SESSION['rol_nombre'] ?? null;
}

/** El empleado siempre puede ver/gestionar sus propios registros (autoservicio), sin importar el rol. */
function esDueno(int $empleadoIdDelRegistro): bool
{
    return estaAutenticado() && (int) $_SESSION['empleado_id'] === $empleadoIdDelRegistro;
}

/**
 * Exige que haya sesión activa y, si se indica, que el rol esté en la lista
 * permitida. Se llama al inicio de cada página protegida: el rol se verifica
 * siempre en el servidor (comparando por id, nunca por el nombre del rol
 * escrito a mano), nunca solo ocultando botones en la vista (regla de
 * CLAUDE.md). Los ids permitidos se pasan como las constantes ROL_* de
 * src/auth/roles.php.
 */
function exigirSesion(array $rolesIdPermitidos = []): void
{
    if (!estaAutenticado()) {
        header('Location: ' . URL_BASE . '/login.php');
        exit;
    }
    if ($rolesIdPermitidos && !in_array((int) $_SESSION['rol_id'], $rolesIdPermitidos, true)) {
        http_response_code(403);
        echo 'Acceso denegado: tu rol no tiene permiso para ver esta página.';
        exit;
    }
}

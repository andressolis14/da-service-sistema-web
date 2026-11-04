<?php
/**
 * D.A SERVICE S.A.S - Sistema web
 * RNF-01: autenticación de cuentas.
 */

/**
 * Verifica usuario y contraseña. Devuelve los datos de sesión si son
 * correctos, o null si las credenciales no son válidas o la cuenta/el
 * empleado están inactivos.
 */
function autenticar(string $nombreUsuario, string $password): ?array
{
    $pdo = obtenerConexion();

    $stmt = $pdo->prepare('
        SELECT u.id AS usuario_id, u.empleado_id, u.rol_id, u.nombre_usuario,
               u.contrasena_hash, u.activo,
               e.nombre_completo, e.estado AS empleado_estado,
               r.nombre AS rol_nombre
        FROM usuario u
        JOIN empleado e ON e.id = u.empleado_id
        JOIN rol r ON r.id = u.rol_id
        WHERE u.nombre_usuario = :nombre_usuario
        LIMIT 1
    ');
    $stmt->execute(['nombre_usuario' => $nombreUsuario]);
    $fila = $stmt->fetch();

    if (!$fila) {
        return null;
    }
    if (!password_verify($password, $fila['contrasena_hash'])) {
        return null;
    }
    if ((int) $fila['activo'] !== 1 || $fila['empleado_estado'] !== 'activo') {
        return null;
    }

    unset($fila['contrasena_hash'], $fila['activo'], $fila['empleado_estado']);
    return $fila;
}

/** Guarda los datos del usuario autenticado en la sesión. */
function iniciarSesionUsuario(array $datos): void
{
    session_regenerate_id(true);
    $_SESSION['usuario_id']      = $datos['usuario_id'];
    $_SESSION['empleado_id']     = $datos['empleado_id'];
    $_SESSION['rol_id']          = $datos['rol_id'];
    $_SESSION['rol_nombre']      = $datos['rol_nombre'];
    $_SESSION['nombre_usuario']  = $datos['nombre_usuario'];
    $_SESSION['nombre_completo'] = $datos['nombre_completo'];
}

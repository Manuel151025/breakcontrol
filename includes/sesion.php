<?php
// ============================================================
//  FUNCIONES DE SESIÓN Y AUTENTICACIÓN
//  Archivo: includes/sesion.php
// ============================================================

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

session_name(SESSION_NOMBRE);
session_start();

// Verificar que el usuario esté logueado
// Si no lo está, redirigir al login
function requerirLogin(): void {
    if (!isset($_SESSION['id_usuario'])) {
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }

    // Verificar expiración de sesión por inactividad
    if (isset($_SESSION['ultima_actividad'])) {
        if (time() - $_SESSION['ultima_actividad'] > SESSION_DURACION) {
            cerrarSesion();
        }
    }

    $_SESSION['ultima_actividad'] = time();
}

// Verificar que sea propietario para funciones restringidas
function requerirPropietario(): void {
    requerirLogin();
    if ($_SESSION['rol'] !== 'propietario') {
        header('Location: ' . APP_URL . '/tablero.php?error=acceso_denegado');
        exit;
    }
}

// Iniciar sesión del usuario
function iniciarSesion(string $nombre_usuario, string $contrasena): bool {
    $pdo  = getConexion();
    $stmt = $pdo->prepare(
        "SELECT id_usuario, nombre_completo, contrasena_hash, rol
         FROM usuario
         WHERE nombre_usuario = ? AND activo = 1"
    );
    $stmt->execute([$nombre_usuario]);
    $usuario = $stmt->fetch();

    if ($usuario && password_verify($contrasena, $usuario['contrasena_hash'])) {
        $_SESSION['id_usuario']       = $usuario['id_usuario'];
        $_SESSION['nombre_completo']  = $usuario['nombre_completo'];
        $_SESSION['rol']              = $usuario['rol'];
        $_SESSION['ultima_actividad'] = time();
        return true;
    }

    return false;
}

// Cerrar sesión
function cerrarSesion(): void {
    session_unset();
    session_destroy();
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

// Obtener el usuario actual
function usuarioActual(): array {
    return [
        'id_usuario' => $_SESSION['id_usuario']      ?? null,
        'nombre'     => $_SESSION['nombre_completo'] ?? '',
        'rol'        => $_SESSION['rol']             ?? '',
    ];
}

// Verificar si es propietario (sin redirigir)
function esPropietario(): bool {
    return isset($_SESSION['rol']) && $_SESSION['rol'] === 'propietario';
}

<?php
// ============================================================
//  CONEXIÓN A LA BASE DE DATOS
//  Archivo: config/db.php
// ============================================================

define('DB_HOST',   'localhost');
define('DB_USER',   'root');
define('DB_PASS',   '');           // En XAMPP la contraseña por defecto es vacía
define('DB_NAME',   'panaderia_bd');
define('DB_CHARSET','utf8mb4');

function getConexion(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $opciones = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opciones);
        } catch (PDOException $e) {
            die(json_encode([
                'error' => true,
                'mensaje' => 'Error de conexión a la base de datos: ' . $e->getMessage()
            ]));
        }
    }

    return $pdo;
}

<?php
// SCRIPT TEMPORAL — ELIMINAR DESPUÉS DE USARLO
require_once __DIR__ . '/config/db.php';

$nueva = 'admin123';
$hash  = password_hash($nueva, PASSWORD_BCRYPT);

$pdo  = getConexion();
$stmt = $pdo->prepare("UPDATE usuario SET contrasena_hash = ? WHERE nombre_usuario = 'propietario'");
$stmt->execute([$hash]);

echo "<h3>✅ Contraseña actualizada correctamente.</h3>";
echo "<p>Usuario: <strong>propietario</strong></p>";
echo "<p>Contraseña: <strong>admin123</strong></p>";
echo "<p><a href='http://localhost/panaderia/login.php'>Ir al login</a></p>";
echo "<br><strong style='color:red'>Elimina este archivo (reset_password.php) después de ingresar.</strong>";

<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/sesion.php';
require_once __DIR__ . '/../../includes/funciones.php';

requerirPropietario();

$pdo = getConexion();
$id  = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT nombre FROM insumo WHERE id_insumo = ? AND activo = 1");
$stmt->execute([$id]);
$insumo = $stmt->fetch();

if (!$insumo) {
    redirigir(APP_URL . '/modules/inventario/index.php', 'error', 'Insumo no encontrado.');
}

$pdo->prepare("UPDATE insumo SET activo = 0 WHERE id_insumo = ?")->execute([$id]);

redirigir(
    APP_URL . '/modules/inventario/index.php',
    'alerta',
    "Insumo <strong>{$insumo['nombre']}</strong> desactivado. Puedes reactivarlo desde Editar."
);

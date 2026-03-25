<?php
// ============================================================
//  CONFIGURACIÓN GENERAL DE LA APLICACIÓN
//  Archivo: config/app.php
// ============================================================

define('APP_NOMBRE',   'Sistema Inventario Panadería');
define('APP_VERSION',  '1.0');
define('APP_URL',      'http://localhost/panaderia');

// Zona horaria (Colombia)
date_default_timezone_set('America/Bogota');

// Sesión
define('SESSION_NOMBRE',   'panaderia_session');
define('SESSION_DURACION', 28800); // 8 horas en segundos

// Rutas de módulos
define('MOD_INVENTARIO', APP_URL . '/modules/inventario');
define('MOD_RECETAS',    APP_URL . '/modules/recetas');
define('MOD_COMPRAS',    APP_URL . '/modules/compras');
define('MOD_FINANZAS',   APP_URL . '/modules/finanzas');
define('MOD_TABLERO',    APP_URL . '/modules/tablero');

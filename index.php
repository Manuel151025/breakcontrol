<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/sesion.php';
requerirLogin();
header('Location: ' . APP_URL . '/modules/tablero/index.php');
exit;

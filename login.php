<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/sesion.php';

if (isset($_SESSION['id_usuario'])) {
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($_POST['usuario'] ?? '');
    $clave   = $_POST['clave'] ?? '';
    if (empty($usuario) || empty($clave)) {
        $error = 'Por favor ingresa tu usuario y contraseña.';
    } else {
        if (iniciarSesion($usuario, $clave)) {
            header('Location: ' . APP_URL . '/index.php');
            exit;
        } else {
            $error = 'Usuario o contraseña incorrectos.';
        }
    }
}

try {
    $pdo = getConexion();

    // Inventario
    $total_insumos  = (int)$pdo->query("SELECT COUNT(*) FROM insumo WHERE activo = 1")->fetchColumn();
    $insumos_bajos  = (int)$pdo->query("SELECT COUNT(*) FROM insumo WHERE stock_actual <= punto_reposicion AND activo = 1")->fetchColumn();

    // Produccion hoy
    $prod_hoy       = (int)$pdo->query("SELECT COUNT(*) FROM produccion WHERE DATE(fecha_produccion) = CURDATE()")->fetchColumn();
    $tandas_hoy     = (float)$pdo->query("SELECT COALESCE(SUM(cantidad_tandas),0) FROM produccion WHERE DATE(fecha_produccion) = CURDATE()")->fetchColumn();

    // Ventas hoy
    $ventas_hoy     = (float)$pdo->query("SELECT COALESCE(SUM(total_venta),0) FROM venta WHERE DATE(fecha_hora) = CURDATE()")->fetchColumn();
    $num_ventas     = (int)$pdo->query("SELECT COUNT(*) FROM venta WHERE DATE(fecha_hora) = CURDATE()")->fetchColumn();

    // Gastos hoy
    $gastos_hoy     = (float)$pdo->query("SELECT COALESCE(SUM(valor),0) FROM gasto WHERE DATE(fecha_gasto) = CURDATE()")->fetchColumn();

    // Costo produccion hoy (FIFO real)
    $costo_prod_hoy = (float)$pdo->query("SELECT COALESCE(SUM(cl.costo_consumo),0) FROM consumo_lote cl INNER JOIN produccion pr ON pr.id_produccion=cl.id_produccion WHERE DATE(pr.fecha_produccion)=CURDATE()")->fetchColumn();

    // Utilidad del dia
    $utilidad_hoy   = $ventas_hoy - $costo_prod_hoy - $gastos_hoy;

    // Compras del mes (columna correcta: total_pagado)
    $compras_mes    = (float)$pdo->query("SELECT COALESCE(SUM(total_pagado),0) FROM compra WHERE MONTH(fecha_compra)=MONTH(CURDATE()) AND YEAR(fecha_compra)=YEAR(CURDATE())")->fetchColumn();

    // Cierre guardado hoy
    $cierre_hoy     = $pdo->query("SELECT id_cierre FROM cierre_dia WHERE fecha = CURDATE()")->fetchColumn();

    // Productos activos
    $productos_act  = (int)$pdo->query("SELECT COUNT(*) FROM producto WHERE activo = 1")->fetchColumn();

} catch(Exception $e) {
    $total_insumos = $insumos_bajos = $prod_hoy = $tandas_hoy = 0;
    $ventas_hoy = $num_ventas = $gastos_hoy = $costo_prod_hoy = $utilidad_hoy = 0;
    $compras_mes = $productos_act = 0;
    $cierre_hoy = false;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Panadería — Acceso</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;0,900;1,400&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500&display=swap" rel="stylesheet">
  <style>
    :root {
      --cafe:      #945b35;
      --terracota: #c8956e;
      --naranja:   #c67124;
      --miel:      #e4a565;
      --crema:     #ecc198;
      --oscuro:    #1a0f07;
      --sombra:    #4a2a1000;
      --glass:     rgba(40, 23, 11, 0.32);
      --glass-b:   rgba(228, 164, 101, 0.34);
    }

    *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

    /* Cursor nativo oculto */
    body, input, button, a { cursor: none !important; }

    body {
      font-family: 'DM Sans', sans-serif;
      min-height: 100vh;
      overflow: hidden;
      background: var(--oscuro);
    }

    /* Cursor croissant personalizado */
    #cursor-custom {
      position: fixed;
      pointer-events: none;
      z-index: 99999;
      font-size: 26px;
      line-height: 1;
      transform: translate(-4px, -24px) scale(1);
      transition: transform 0.13s cubic-bezier(0.34, 1.56, 0.64, 1),
                  filter 0.13s ease;
      filter: drop-shadow(0 4px 10px rgba(0,0,0,0.6))
              drop-shadow(0 1px 4px rgba(198,113,36,0.4));
      will-change: transform;
      user-select: none;
    }

    #cursor-custom.clicking {
      transform: translate(-4px, -24px) scale(0.65);
      filter: drop-shadow(0 2px 5px rgba(0,0,0,0.4))
              drop-shadow(0 0 10px rgba(228,165,101,0.55));
    }

    .fondo {
      position: fixed; inset: 0; z-index: 0;
      background-image: url('https://images.unsplash.com/photo-1549931319-a545dcf3bc73?w=1920&q=85');
      background-size: cover;
      background-position: center 40%;
    }

    .fondo::after {
      content:''; position:absolute; inset:0;
      background: rgba(10, 8, 6, 0.62);
    }

    .particulas { position:fixed; inset:0; z-index:1; pointer-events:none; overflow:hidden; }

    .particula {
      position:absolute; bottom:-60px; font-size:1.4rem; opacity:0;
      animation: subir linear infinite;
      filter: drop-shadow(0 0 5px rgba(228,165,101,0.18));
    }

    @keyframes subir {
      0%   { transform:translateY(0) rotate(0deg) scale(0.7); opacity:0; }
      8%   { opacity:0.28; }
      85%  { opacity:0.1; }
      100% { transform:translateY(-110vh) rotate(180deg) scale(1); opacity:0; }
    }

    /* ── LAYOUT ── */
    .layout {
      position:relative; z-index:10;
      min-height:100vh;
      display:flex;
      flex-direction:column;
      padding: 1.1rem;
      gap: 1rem;
    }

    /* ── TOPBAR ── */
    .topbar {
      display:grid;
      grid-template-columns: 1fr auto 1fr;
      align-items:center;
      padding: 0.8rem 1.4rem;
      background: var(--glass);
      border: 1px solid var(--glass-b);
      border-radius: 16px;
      backdrop-filter: blur(24px);
      animation: fadeDown 0.6s ease both;
    }

    /* Hora — izquierda */
    .topbar-hora {
      display:flex; flex-direction:column;
    }

    .hora-reloj {
      font-family:'Playfair Display',serif;
      font-size:1.3rem; font-weight:700;
      color:var(--crema); line-height:1;
    }

    .hora-fecha {
      font-size:0.62rem; text-transform:uppercase;
      letter-spacing:0.15em; color:var(--terracota); opacity:0.6;
      margin-top:0.2rem;
    }

    /* Marca — centro */
    .marca {
      display:flex; align-items:center; gap:0.7rem;
      justify-content:center;
    }

    .marca-icono {
      width:40px; height:40px;
      background:linear-gradient(135deg, var(--naranja), var(--cafe));
      border-radius:10px; display:flex; align-items:center;
      justify-content:center; font-size:1.25rem;
      box-shadow:0 3px 14px rgba(198,113,36,0.4);
    }

    .marca-nombre {
      font-family:'Playfair Display',serif;
      font-weight:700; font-size:1.1rem; color:var(--crema);
    }

    .marca-sub {
      font-size:0.58rem; text-transform:uppercase;
      letter-spacing:0.18em; color:var(--terracota); opacity:0.6;
      margin-top:0.12rem;
    }

    /* Clima — derecha */
    .clima-bloque {
      display:flex; align-items:center; gap:0.5rem;
      justify-content:flex-end;
    }

    .clima-widget {
      display:flex; align-items:center; gap:0.6rem;
      background:rgba(255,255,255,0.04);
      border:1px solid var(--glass-b);
      border-radius:12px; padding:0.45rem 0.9rem;
      transition:border-color 0.3s;
    }

    .clima-icono  { font-size:1.5rem; }
    .clima-info   { display:flex; flex-direction:column; align-items:flex-end; }
    .clima-lugar  { font-size:0.58rem; text-transform:uppercase; letter-spacing:0.14em; color:var(--terracota); opacity:0.6; }
    .clima-temp   { font-family:'Playfair Display',serif; font-size:1.3rem; font-weight:700; color:var(--crema); line-height:1; }
    .clima-desc   { font-size:0.6rem; color:var(--miel); opacity:0.75; }

    .btn-clima {
      background:rgba(198,113,36,0.14);
      border:1px solid rgba(198,113,36,0.22);
      border-radius:10px; padding:0.45rem 0.7rem;
      color:var(--miel); font-size:0.78rem;
      display:flex; align-items:center; gap:0.3rem;
      transition:all 0.2s;
    }
    .btn-clima:hover { background:rgba(198,113,36,0.26); }
    .btn-clima.girando svg { animation: girar 0.9s linear infinite; }
    @keyframes girar { to { transform:rotate(360deg); } }

    /* ── ZONA CENTRAL ── */
    .centro {
      flex:1;
      display:flex;
      align-items:center;
      justify-content:center;
      gap: 1.4rem;
    }

    /* Paneles con ancho limitado */
    .panel-login,
    .panel-stats {
      width: 100%;
      max-width: 420px;
    }

    /* ── PANEL LOGIN ── */
    .panel-login {
      background: rgba(69, 46, 24, 0.04);
      border: 1px solid rgba(255,255,255,0.09);
      border-radius: 22px;
      backdrop-filter: blur(32px);
      -webkit-backdrop-filter: blur(32px);
      padding: 2.4rem 2.6rem;
      box-shadow: 0 30px 70px rgba(0,0,0,0.55), 0 1px 0 rgba(255,255,255,0.05) inset;
      animation: fadeRight 0.7s ease 0.15s both;
    }

    /* Logo dentro del panel */
    .login-logo {
      display: flex; align-items: center; gap: 0.65rem;
      margin-bottom: 1.6rem;
    }

    .login-logo-ico {
      width: 38px; height: 38px;
      background: linear-gradient(135deg, var(--naranja), #b85e10);
      border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.2rem;
      box-shadow: 0 4px 16px rgba(198,113,36,0.45);
      flex-shrink: 0;
    }

    .login-logo-name {
      font-family: 'Playfair Display', serif;
      font-size: 1.1rem; font-weight: 700;
      color: var(--crema); letter-spacing: -0.01em;
    }

    .login-hora-tag {
      display: none; /* oculto — saludo dinámico ya lo cubre */
    }

    .login-saludo {
      font-family: 'Playfair Display', serif;
      font-size: 2rem; font-weight: 900;
      color: var(--crema); line-height: 1.15; margin-bottom: 0.35rem;
    }

    .login-saludo em { font-style: normal; }

    .login-sub {
      font-size: 0.82rem; color: rgba(236,193,152,0.5);
      line-height: 1.5; margin-bottom: 1.8rem;
    }

    .login-error {
      background: rgba(220,53,69,0.1);
      border: 1px solid rgba(220,53,69,0.3);
      border-left: 3px solid #dc3545;
      border-radius: 10px; padding: 0.75rem 1rem;
      font-size: 0.82rem; color: #f8a9b0;
      margin-bottom: 1.3rem;
      display: flex; align-items: center; gap: 0.45rem;
    }

    .campo-label {
      font-size: 0.72rem; font-weight: 600;
      color: rgba(236,193,152,0.6);
      display: block; margin-bottom: 0.42rem;
      letter-spacing: 0.02em;
    }

    .campo-wrap { position: relative; margin-bottom: 1.1rem; }

    .campo-icon {
      position: absolute; left: 0.95rem; top: 50%;
      transform: translateY(-50%);
      font-size: 1rem; color: rgba(236,193,152,0.3);
      pointer-events: none; z-index: 1;
    }

    .campo-input {
      width: 100%;
      background: rgba(255,255,255,0.05);
      border: 1px solid rgba(255,255,255,0.11);
      border-radius: 11px;
      padding: 0.82rem 1rem 0.82rem 2.65rem;
      font-family: 'DM Sans', sans-serif;
      font-size: 0.88rem; color: var(--crema);
      transition: all 0.22s; outline: none;
    }

    .campo-input::placeholder { color: rgba(255, 255, 255, 0.39); }

    .campo-input:focus {
      border-color: rgba(198,113,36,0.5);
      background: rgba(255,255,255,0.08);
      box-shadow: 0 0 0 3px rgba(198,113,36,0.12);
    }

    /* campo contraseña con botón ojo */
    .campo-pass .campo-input { padding-right: 2.8rem; }

    .btn-ojo {
      position: absolute; right: 0.85rem; top: 50%;
      transform: translateY(-50%);
      background: none; border: none;
      color: rgba(236,193,152,0.3);
      font-size: 1rem; padding: 0.2rem;
      transition: color 0.2s; z-index: 1;
    }

    .btn-ojo:hover { color: rgba(236,193,152,0.7); }

    /* Opciones: recordarme + olvidaste */
    .login-opciones {
      display: flex; align-items: center;
      justify-content: space-between;
      margin-bottom: 1.4rem; margin-top: -0.2rem;
    }

    .check-wrap {
      display: flex; align-items: center; gap: 0.42rem;
    }

    .check-wrap input[type="checkbox"] {
      width: 15px; height: 15px;
      accent-color: var(--naranja); border-radius: 4px;
    }

    .check-label {
      font-size: 0.78rem; color: rgba(236,193,152,0.5);
      font-weight: 500;
    }

    .link-olvido {
      font-size: 0.78rem; color: var(--miel);
      text-decoration: none; font-weight: 600;
      transition: color 0.2s;
    }

    .link-olvido:hover { color: #fff; }

    .btn-login {
      width: 100%; padding: 0.95rem;
      background: linear-gradient(135deg, var(--naranja), #b85e10);
      border: none; border-radius: 11px;
      font-family: 'DM Sans', sans-serif;
      font-size: 0.92rem; font-weight: 700;
      color: #fff; transition: all 0.3s;
      box-shadow: 0 4px 24px rgba(198,113,36,0.42);
      letter-spacing: 0.02em;
    }

    .btn-login:hover { transform: translateY(-2px); box-shadow: 0 8px 32px rgba(198,113,36,0.55); }
    .btn-login:active { transform: translateY(0); opacity: 0.9; }

    .login-pie {
      display: flex; align-items: center; gap: 0.45rem;
      margin-top: 1.6rem; padding-top: 1.3rem;
      border-top: 1px solid rgba(228,165,101,0.07);
    }

    .pie-dot { width: 5px; height: 5px; border-radius: 50%; background: var(--naranja); opacity: 0.4; }
    .pie-txt  { font-size: 0.66rem; color: var(--terracota); opacity: 0.4; letter-spacing: 0.05em; }

    /* ── PANEL STATS ── */
    .panel-stats {
      background:var(--glass);
      border:1px solid var(--glass-b);
      border-radius:22px;
      backdrop-filter:blur(24px);
      padding:2rem 2rem;
      animation: fadeLeft 0.7s ease 0.2s both;
    }

    .stats-titulo {
      font-size:0.63rem; text-transform:uppercase; letter-spacing:0.28em;
      color:var(--naranja); margin-bottom:1.3rem;
      display:flex; align-items:center; gap:0.5rem;
    }

    .stats-titulo::before {
      content:''; width:7px; height:7px; border-radius:50%;
      background:var(--naranja); box-shadow:0 0 7px var(--naranja);
      animation:pulsar 2s ease infinite; flex-shrink:0;
    }

    @keyframes pulsar {
      0%,100% { opacity:1; transform:scale(1); }
      50%      { opacity:0.4; transform:scale(0.6); }
    }

    .stat-grid {
      display:grid; grid-template-columns:1fr 1fr;
      gap:0.8rem;
    }

    .stat-card {
      background:rgba(255,255,255,0.03);
      border:1px solid rgba(228,165,101,0.08);
      border-radius:14px; padding:1.1rem 1rem;
      transition:border-color 0.2s;
    }

    .stat-card:hover { border-color:rgba(228,165,101,0.17); }
    .stat-card.alerta { border-color:rgba(229,115,115,0.22); background:rgba(229,115,115,0.04); }
    .stat-card.ok     { border-color:rgba(129,199,132,0.18); }

    .sc-etiqueta {
      font-size:0.58rem; text-transform:uppercase; letter-spacing:0.14em;
      color:var(--terracota); opacity:0.58; margin-bottom:0.4rem;
    }

    .sc-valor {
      font-family:'Playfair Display',serif;
      font-size:2rem; font-weight:700;
      color:var(--crema); line-height:1; margin-bottom:0.25rem;
    }

    .sc-valor.rojo  { color:#ef9a9a; }
    .sc-valor.verde { color:#a5d6a7; }
    .sc-valor.miel  { color:var(--miel); font-size:1.35rem; }

    .sc-detalle { font-size:0.65rem; color:var(--miel); opacity:0.5; }

    /* Animaciones */
    @keyframes fadeDown  { from{opacity:0;transform:translateY(-16px)} to{opacity:1;transform:translateY(0)} }
    @keyframes fadeLeft  { from{opacity:0;transform:translateX(16px)}  to{opacity:1;transform:translateX(0)} }
    @keyframes fadeRight { from{opacity:0;transform:translateX(-16px)} to{opacity:1;transform:translateX(0)} }

    @media (max-width:860px) {
      .centro { flex-direction:column; align-items:center; }
      .panel-stats { display:none; }
      body { overflow-y:auto; }
      .topbar { grid-template-columns:1fr 1fr; }
      .marca { display:none; }
    }
  </style>
</head>
<body>

<div class="fondo"></div>
<div class="particulas" id="particulas"></div>

<div class="layout">

  <!-- TOPBAR -->
  <header class="topbar">

    <!-- Hora — izquierda -->
    <div class="topbar-hora">
      <span class="hora-reloj" id="hora-reloj">--:--:--</span>
      <span class="hora-fecha" id="hora-fecha"></span>
    </div>

    <!-- Marca — centro -->
    <div class="marca">
      <img src="data:image/png;base64,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" alt="BreakControl" class="marca-icono" style="width:44px;height:44px;border-radius:10px;object-fit:cover;display:block;">
      <div>
        <div class="marca-nombre">BreakControl</div>
        <div class="marca-sub">Sistema de inventario</div>
      </div>
    </div>

    <!-- Clima — derecha -->
    <div class="clima-bloque">
      <div class="clima-widget">
        <span class="clima-icono" id="clima-icono">⏳</span>
        <div class="clima-info">
          <span class="clima-lugar">Florencia, CO</span>
          <span class="clima-temp" id="clima-temp">--°C</span>
          <span class="clima-desc" id="clima-desc">Cargando...</span>
        </div>
      </div>
      <button class="btn-clima" id="btn-clima" onclick="obtenerClima(true)" title="Actualizar clima">
        <svg id="icono-ref" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M23 4v6h-6"/><path d="M1 20v-6h6"/>
          <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
        </svg>
      </button>
    </div>

  </header>

  <!-- ZONA CENTRAL -->
  <main class="centro">

    <!-- LOGIN -->
    <div class="panel-login">

      <!-- Logo -->
      <div class="login-logo">
        <img src="data:image/png;base64,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" alt="BreakControl" class="login-logo-ico" style="width:52px;height:52px;border-radius:12px;object-fit:cover;display:block;">
        <div class="login-logo-name">Iniciar Sesión</div>
      </div>

      <div class="login-saludo" id="login-saludo"></div>
      <div class="login-sub"   id="login-sub">Accede al sistema de inventario</div>

      <?php if ($error): ?>
      <div class="login-error">⚠ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" autocomplete="off">

        <label class="campo-label" for="f-usuario">Usuario o Email</label>
        <div class="campo-wrap">
          <span class="campo-icon">👤</span>
          <input id="f-usuario" type="text" name="usuario" class="campo-input"
                 placeholder="Introduce tu usuario"
                 value="<?= htmlspecialchars($_POST['usuario'] ?? '') ?>"
                 autofocus required>
        </div>

        <label class="campo-label" for="f-clave">Contraseña</label>
        <div class="campo-wrap campo-pass">
          <span class="campo-icon">🔒</span>
          <input id="f-clave" type="password" name="clave" class="campo-input"
                 placeholder="Introduce tu contraseña" required>
          <button type="button" class="btn-ojo" id="btn-ojo">
            <span id="ico-ojo">👁️</span>
          </button>
        </div>

        <button type="submit" class="btn-login">Ingresar</button>
      </form>

      <div style="text-align:center;margin-top:.8rem;">
        <a href="<?= APP_URL ?>/recuperar_pin.php"
           style="font-size:.78rem;color:var(--c3,#c67124);font-weight:600;text-decoration:none;">
          🔑 ¿Olvidaste tu contraseña?
        </a>
      </div>

      <div class="login-pie">
        <div class="pie-dot"></div>
        <span class="pie-txt">Solo personal autorizado · Florencia, Caquetá · 2026</span>
      </div>
    </div>

    <!-- STATS -->
    <div class="panel-stats">
      <div class="stats-titulo">Estado del día &mdash; <?= date('d/m/Y') ?></div>
      <div class="stat-grid">

        <div class="stat-card <?= $ventas_hoy > 0 ? 'ok' : '' ?>">
          <div class="sc-etiqueta">Ventas hoy</div>
          <div class="sc-valor miel">$<?= number_format($ventas_hoy, 0, ',', '.') ?></div>
          <div class="sc-detalle"><?= $num_ventas ?> venta<?= $num_ventas != 1 ? 's' : '' ?> registrada<?= $num_ventas != 1 ? 's' : '' ?></div>
        </div>

        <div class="stat-card <?= $utilidad_hoy >= 0 ? 'ok' : 'alerta' ?>">
          <div class="sc-etiqueta">Utilidad neta hoy</div>
          <div class="sc-valor <?= $utilidad_hoy >= 0 ? 'verde' : 'rojo' ?>"><?= $utilidad_hoy >= 0 ? '+' : '-' ?>$<?= number_format(abs($utilidad_hoy), 0, ',', '.') ?></div>
          <div class="sc-detalle"><?= $utilidad_hoy >= 0 ? 'ganancia del d&iacute;a' : 'p&eacute;rdida del d&iacute;a' ?></div>
        </div>

        <div class="stat-card <?= $prod_hoy > 0 ? 'ok' : '' ?>">
          <div class="sc-etiqueta">Producci&oacute;n hoy</div>
          <div class="sc-valor"><?= number_format($tandas_hoy, 0, ',', '.') ?></div>
          <div class="sc-detalle"><?= $prod_hoy ?> lote<?= $prod_hoy != 1 ? 's' : '' ?> registrado<?= $prod_hoy != 1 ? 's' : '' ?></div>
        </div>

        <div class="stat-card <?= $insumos_bajos > 0 ? 'alerta' : 'ok' ?>">
          <div class="sc-etiqueta">Stock bajo</div>
          <div class="sc-valor <?= $insumos_bajos > 0 ? 'rojo' : 'verde' ?>"><?= $insumos_bajos ?></div>
          <div class="sc-detalle"><?= $insumos_bajos > 0 ? 'requieren reposici&oacute;n' : 'todo en orden &#10003;' ?></div>
        </div>

        <div class="stat-card <?= $gastos_hoy > 0 ? 'alerta' : '' ?>">
          <div class="sc-etiqueta">Gastos hoy</div>
          <div class="sc-valor miel">$<?= number_format($gastos_hoy, 0, ',', '.') ?></div>
          <div class="sc-detalle">operativos del d&iacute;a</div>
        </div>

        <div class="stat-card <?= $cierre_hoy ? 'ok' : '' ?>">
          <div class="sc-etiqueta">Cierre del d&iacute;a</div>
          <div class="sc-valor <?= $cierre_hoy ? 'verde' : '' ?>"><?= $cierre_hoy ? '&#10003;' : '&mdash;' ?></div>
          <div class="sc-detalle"><?= $cierre_hoy ? 'guardado hoy' : 'pendiente' ?></div>
        </div>

      </div>

      <div style="margin-top:1rem;padding-top:.8rem;border-top:1px solid rgba(228,165,101,0.1);display:flex;align-items:center;justify-content:space-between;gap:.5rem;flex-wrap:wrap;">
        <span style="font-size:.58rem;text-transform:uppercase;letter-spacing:.14em;color:var(--terracota);opacity:.5;">
          <?= $total_insumos ?> insumos &middot; <?= $productos_act ?> productos &middot; $<?= number_format($compras_mes/1000,1) ?>k compras mes
        </span>
        <span id="ultima-act" style="font-size:.58rem;color:var(--terracota);opacity:.4;"></span>
      </div>
    </div>

  </main>

</div>

<script>
// ── RELOJ Y SALUDO ──
(function() {
  const ahora = new Date();
  const hora  = ahora.getHours();
  let saludo, sub;

  if (hora >= 5 && hora < 12) {
    saludo = '¡Buenos días!';
    sub    = 'Que sea una gran jornada de producción.';
  } else if (hora >= 12 && hora < 18) {
    saludo = '¡Buenas tardes!';
    sub    = 'La tarde es perfecta para revisar las ventas del día.';
  } else {
    saludo = '¡Buenas noches!';
    sub    = 'Un buen cierre de día empieza con una revisión del inventario.';
  }

  const fecha = ahora.toLocaleDateString('es-CO', {weekday:'long', day:'numeric', month:'long', year:'numeric'});
  document.getElementById('login-saludo').textContent = saludo;
  document.getElementById('login-sub').textContent    = sub;
  document.getElementById('hora-fecha').textContent   = fecha;

  function tick() {
    const n = new Date();
    document.getElementById('hora-reloj').textContent =
      n.toLocaleTimeString('es-CO', {hour:'2-digit', minute:'2-digit', second:'2-digit'});
  }
  tick();
  setInterval(tick, 1000);
})();

// ── ULTIMA ACTUALIZACION ──
(function() {
  const el = document.getElementById('ultima-act');
  if (el) {
    const ahora = new Date();
    el.textContent = 'actualizado ' + ahora.toLocaleTimeString('es-CO', {hour:'2-digit', minute:'2-digit'});
  }
  // Auto-refresh cada 2 minutos para mantener datos al dia
  setTimeout(() => location.reload(), 120000);
})();

// ── OJO CONTRASEÑA ──
document.getElementById('btn-ojo').addEventListener('click', function() {
  const inp = document.getElementById('f-clave');
  const ico = document.getElementById('ico-ojo');
  if (inp.type === 'password') {
    inp.type = 'text';
    ico.textContent = '🙈';
  } else {
    inp.type = 'password';
    ico.textContent = '👁️';
  }
});

// ── CLIMA ──
const WMO = {
  0:['☀️','Despejado'], 1:['🌤️','Mayorm. despejado'],
  2:['⛅','Parcialmente nublado'], 3:['☁️','Nublado'],
  45:['🌫️','Niebla'], 48:['🌫️','Escarcha'],
  51:['🌦️','Llovizna leve'], 53:['🌧️','Llovizna'], 55:['🌧️','Llovizna intensa'],
  61:['🌧️','Lluvia leve'], 63:['🌧️','Lluvia'], 65:['🌧️','Lluvia intensa'],
  80:['🌦️','Chubascos'], 81:['🌧️','Chubascos mod.'], 82:['⛈️','Chubascos fuertes'],
  95:['⛈️','Tormenta'], 96:['⛈️','Tormenta c/granizo'], 99:['⛈️','Tormenta severa'],
};

async function obtenerClima(manual = false) {
  const btn  = document.getElementById('btn-clima');
  btn.classList.add('girando');
  try {
    const r = await fetch(
      'https://api.open-meteo.com/v1/forecast?latitude=1.6144&longitude=-75.6062&current_weather=true&timezone=America%2FBogota',
      { cache:'no-store' }
    );
    const d  = await r.json();
    const cw = d.current_weather;
    const [icon, desc] = WMO[cw.weathercode] || ['🌡️','Variable'];
    document.getElementById('clima-icono').textContent = icon;
    document.getElementById('clima-temp').textContent  = Math.round(cw.temperature) + '°C';
    document.getElementById('clima-desc').textContent  = desc;
    if (manual) {
      const w = document.querySelector('.clima-widget');
      w.style.borderColor = 'rgba(228,165,101,0.45)';
      setTimeout(() => w.style.borderColor = '', 1500);
    }
  } catch(e) {
    document.getElementById('clima-icono').textContent = '📡';
    document.getElementById('clima-desc').textContent  = 'Sin conexión';
  } finally {
    btn.classList.remove('girando');
  }
}

obtenerClima();
setInterval(obtenerClima, 10 * 60 * 1000);

// ── PARTÍCULAS ──
const items = ['🍞','🥐','🥖','🧁','🍩','🫓','🧇','🌾','🥚','🧈','🍫','🫙'];
const cont  = document.getElementById('particulas');

function crearParticula() {
  const el = document.createElement('div');
  el.className   = 'particula';
  el.textContent = items[Math.floor(Math.random() * items.length)];
  const dur  = 9 + Math.random() * 13;
  const dly  = Math.random() * 12;
  el.style.left              = (Math.random() * 96) + '%';
  el.style.fontSize          = (0.85 + Math.random() * 1) + 'rem';
  el.style.animationDuration = dur + 's';
  el.style.animationDelay    = dly + 's';
  cont.appendChild(el);
  setTimeout(() => { el.remove(); crearParticula(); }, (dur + dly) * 1000 + 500);
}

for (let i = 0; i < 16; i++) crearParticula();
</script>

<div id="cursor-custom">🥐</div>

<script>
// ── CURSOR CROISSANT ──
const cur = document.getElementById('cursor-custom');
let mx = -100, my = -100;

document.addEventListener('mousemove', e => {
  mx = e.clientX;
  my = e.clientY;
  cur.style.left = mx + 'px';
  cur.style.top  = my + 'px';
});

document.addEventListener('mousedown', () => cur.classList.add('clicking'));
document.addEventListener('mouseup',   () => cur.classList.remove('clicking'));

// Ocultar cuando sale de la ventana
document.addEventListener('mouseleave', () => cur.style.opacity = '0');
document.addEventListener('mouseenter', () => cur.style.opacity = '1');
</script>

</body>
</html>
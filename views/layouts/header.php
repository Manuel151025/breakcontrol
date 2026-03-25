<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/sesion.php';
$user = usuarioActual();

$current = $_SERVER['REQUEST_URI'];
function navActive($path) {
    global $current;
    return strpos($current, $path) !== false ? 'on' : '';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <title><?= $page_title ?? 'BreakControl' ?> — BreakControl</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,wght@0,600;0,800;1,600&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="<?= APP_URL ?>/assets/css/responsive.css" rel="stylesheet">
  <style>
    :root {
      --c1:#945b35; --c2:#c8956e; --c3:#c67124; --c4:#e4a565; --c5:#ecc198;
      --cbg:#faf3ea; --ccard:#ffffff; --clight:#fdf6ee;
      --ink:#281508; --ink2:#6b3d1e; --ink3:#b87a4a;
      --border:rgba(148,91,53,.12);
      --shadow:0 1px 8px rgba(148,91,53,.09);
      --shadow2:0 4px 20px rgba(148,91,53,.15);
      --nav-h:64px;
    }
    *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
    html, body { font-family:'Plus Jakarta Sans',sans-serif; background:var(--cbg); color:var(--ink); }

    /* ══ NAVBAR DESKTOP ══ */
    nav {
      position:fixed; top:0; left:0; right:0; z-index:900;
      height:var(--nav-h);
      background:linear-gradient(100deg,var(--c1) 0%,var(--c3) 55%,var(--c4) 100%);
      display:flex; align-items:center;
      padding:0 1rem; gap:.2rem;
      box-shadow:0 3px 24px rgba(100,40,10,.35);
    }
    nav::after {
      content:''; position:absolute; bottom:0; left:0; right:0; height:1px;
      background:linear-gradient(90deg,transparent,rgba(255,255,255,.45),transparent);
    }

    .n-logo { display:flex; align-items:center; gap:.65rem; text-decoration:none; margin-right:.5rem; flex-shrink:0; padding:.18rem .6rem .18rem .15rem; border-radius:12px; transition:background .2s; }
    .n-logo:hover { background:rgba(255,255,255,.12); }
    .n-logo-img { width:42px; height:42px; border-radius:50%; object-fit:cover; border:2.5px solid rgba(255,255,255,.6); box-shadow:0 2px 10px rgba(80,30,5,.45),0 0 0 4px rgba(255,255,255,.12); flex-shrink:0; transition:transform .3s cubic-bezier(.34,1.56,.64,1),box-shadow .3s; }
    .n-logo:hover .n-logo-img { transform:scale(1.08) rotate(-5deg); box-shadow:0 4px 18px rgba(80,30,5,.55),0 0 0 5px rgba(255,255,255,.22); }
    .n-logo-name { font-family:'Fraunces',serif; font-size:1.12rem; font-weight:800; color:#fff; letter-spacing:-.01em; line-height:1.1; text-shadow:0 1px 6px rgba(80,30,5,.35); }
    .n-logo-sub  { font-size:.5rem; text-transform:uppercase; letter-spacing:.2em; color:rgba(255,255,255,.6); }

    .n-sep { width:1px; height:26px; background:linear-gradient(to bottom,transparent,rgba(255,255,255,.3),transparent); margin:0 .3rem; flex-shrink:0; }

    .n-menu { display:flex; align-items:center; gap:.2rem; }
    .n-item { display:flex; align-items:center; text-decoration:none; height:42px; border-radius:10px; padding:0 .65rem; color:rgba(255,255,255,.8); font-size:.82rem; font-weight:600; overflow:hidden; white-space:nowrap; max-width:44px; gap:0; transition:background .2s,max-width .25s ease,gap .25s,padding .2s; }
    .n-item i { font-size:1.22rem; flex-shrink:0; }
    .n-lbl { opacity:0; max-width:0; font-size:.82rem; transition:opacity .15s .04s,max-width .25s ease; }
    .n-item:hover, .n-item.on { background:rgba(255,255,255,.22); color:#fff; max-width:170px; gap:.45rem; padding:0 .75rem; }
    .n-item.on { background:rgba(255,255,255,.25); box-shadow:inset 0 0 0 1px rgba(255,255,255,.2); }
    .n-item:hover .n-lbl, .n-item.on .n-lbl { opacity:1; max-width:130px; }

    .n-right { margin-left:auto; display:flex; align-items:center; gap:.55rem; flex-shrink:0; }
    .n-clock { font-size:.84rem; font-weight:700; color:#fff; letter-spacing:.08em; background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.2); border-radius:8px; padding:.28rem .7rem; font-variant-numeric:tabular-nums; }

    /* Botón ciudad */
    .n-ciudad-btn {
      display:flex; align-items:center; gap:.4rem;
      background:linear-gradient(135deg, rgba(255,255,255,.18), rgba(255,255,255,.08));
      border:1px solid rgba(255,255,255,.28);
      border-radius:10px; padding:.32rem .7rem .32rem .55rem;
      cursor:pointer; color:#fff; font-size:.76rem; font-weight:700;
      font-family:inherit; white-space:nowrap; max-width:160px;
      overflow:hidden; text-overflow:ellipsis;
      transition:all .25s ease;
      box-shadow:0 2px 8px rgba(0,0,0,.1), inset 0 1px 0 rgba(255,255,255,.15);
    }
    .n-ciudad-btn:hover {
      background:linear-gradient(135deg, rgba(255,255,255,.28), rgba(255,255,255,.14));
      border-color:rgba(255,255,255,.4);
      transform:translateY(-1px);
      box-shadow:0 4px 12px rgba(0,0,0,.15), inset 0 1px 0 rgba(255,255,255,.2);
    }
    .n-ciudad-btn i { color:#fff; font-size:.88rem; flex-shrink:0; filter:drop-shadow(0 1px 2px rgba(0,0,0,.2)); }
    #ciudad-lbl { overflow:hidden; text-overflow:ellipsis; max-width:110px; text-shadow:0 1px 3px rgba(0,0,0,.15); }

    /* Ocultar "Cambiar ciudad" del menú en desktop */
    .n-menu-ciudad { display:none; }

    .n-user { display:flex; align-items:center; gap:.45rem; background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.22); border-radius:22px; padding:.26rem .75rem .26rem .3rem; transition:background .2s; }
    .n-user:hover { background:rgba(255,255,255,.22); }
    .n-avatar { width:30px; height:30px; border-radius:50%; background:rgba(255,255,255,.28); border:1.5px solid rgba(255,255,255,.45); display:flex; align-items:center; justify-content:center; font-size:.8rem; color:#fff; font-weight:800; flex-shrink:0; }
    .n-uname { font-size:.78rem; color:#fff; font-weight:700; }
    .n-urole { font-size:.55rem; color:rgba(255,255,255,.62); text-transform:uppercase; letter-spacing:.1em; }
    .n-logout { width:34px; height:34px; border-radius:8px; background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.2); color:rgba(255,255,255,.85); font-size:1rem; display:flex; align-items:center; justify-content:center; text-decoration:none; transition:all .2s; flex-shrink:0; }
    .n-logout:hover { background:rgba(220,53,69,.35); color:#fff; border-color:rgba(220,53,69,.5); }

    /* Hamburguesa — solo mobile */
    .n-hamburger { display:none; align-items:center; justify-content:center; width:38px; height:38px; border-radius:10px; background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.25); color:#fff; font-size:1.25rem; cursor:pointer; flex-shrink:0; transition:background .2s; -webkit-tap-highlight-color:transparent; }
    .n-hamburger:hover { background:rgba(255,255,255,.25); }

    /* Separadores dentro del menú mobile */
    .n-menu-sep { display:none; height:1px; background:rgba(255,255,255,.15); margin:.25rem 0; }
    .n-menu-logout { display:none; }

    /* ══ MOBILE NAV (≤768px) ══ */
    @media (max-width: 768px) {
      nav {
        height:auto !important;
        min-height:58px;
        padding:.45rem .8rem;
        flex-wrap:wrap;
        align-items:center;
        gap:0;
      }
      nav.nav-open { padding-bottom:.55rem; }

      /* Logo: izquierda */
      .n-logo { flex-shrink:0; margin-right:0; padding:.1rem .4rem .1rem .1rem; }
      .n-logo-img { width:34px; height:34px; }
      .n-logo-name { font-size:.88rem; }
      .n-logo-sub { display:none; }
      .n-sep { display:none; }

      /* Derecha: compacta — solo user + hamburguesa */
      .n-right { margin-left:auto; order:2; gap:.3rem; flex-shrink:0; }
      .n-clock       { display:none; }
      .n-ciudad-btn  { display:none; }   /* oculto — acceso desde menú */
      .n-logout      { display:none; }   /* oculto — acceso desde menú */
      .n-urole       { display:none; }
      .n-uname       { font-size:.75rem; }
      .n-hamburger   { display:flex; }

      /* Menú desplegable */
      .n-menu {
        display:none;
        flex-direction:column;
        align-items:stretch;
        width:100%;
        flex-basis:100%;
        gap:.2rem;
        order:3;
        padding-top:.45rem;
        border-top:1px solid rgba(255,255,255,.15);
        margin-top:.3rem;
      }
      .n-menu.open { display:flex; }

      /* Ítems de módulo */
      .n-item {
        height:46px !important;
        max-width:100% !important;
        padding:0 1rem !important;
        gap:.65rem !important;
        background:rgba(255,255,255,.08);
        border-radius:10px;
        font-size:.88rem;
        -webkit-tap-highlight-color:transparent;
      }
      .n-item .n-lbl { opacity:1 !important; max-width:200px !important; }
      .n-item.on { background:rgba(255,255,255,.22); }

      /* Separador + acciones extra al final del menú */
      .n-menu-sep    { display:block; }
      .n-menu-logout { display:flex; align-items:center; gap:.65rem; height:46px; padding:0 1rem; border-radius:10px; background:rgba(220,53,69,.12); color:rgba(255,220,220,.9); text-decoration:none; font-size:.88rem; font-weight:600; -webkit-tap-highlight-color:transparent; transition:background .2s; }
      .n-menu-logout:hover { background:rgba(220,53,69,.25); }
      .n-menu-ciudad { display:flex; align-items:center; gap:.65rem; height:46px; padding:0 1rem; border-radius:10px; background:rgba(255,255,255,.08); color:rgba(255,255,255,.85); font-size:.88rem; font-weight:600; cursor:pointer; -webkit-tap-highlight-color:transparent; border:none; font-family:inherit; width:100%; transition:background .2s; }
      .n-menu-ciudad:hover { background:rgba(255,255,255,.15); }
    }

    @media (max-width: 480px) {
      nav { padding:.4rem .7rem; }
      .n-logo-name { display:none; }
      .n-uname { font-size:.72rem; }
      .n-avatar { width:28px; height:28px; font-size:.72rem; }
    }

    /* ══ MODAL CIUDAD ══ */
    .modal-ciudad-overlay {
      display:none; position:fixed; inset:0;
      background:rgba(40,21,8,.55); backdrop-filter:blur(4px);
      z-index:9999; align-items:center; justify-content:center; padding:1rem;
    }
    .modal-ciudad-overlay.open { display:flex; }
    .modal-ciudad-box {
      background:var(--cbg); border-radius:20px; width:100%; max-width:400px;
      box-shadow:0 12px 48px rgba(100,40,10,.3), 0 0 0 1px rgba(148,91,53,.1);
      overflow:hidden; max-height:80vh; display:flex; flex-direction:column;
      animation: modalIn .25s ease;
    }
    @keyframes modalIn { from { opacity:0; transform:scale(.95) translateY(8px); } to { opacity:1; transform:scale(1) translateY(0); } }
    .modal-ciudad-head {
      display:flex; align-items:center; justify-content:space-between;
      padding:1rem 1.3rem;
      background:linear-gradient(135deg, var(--c1) 0%, var(--c3) 60%, var(--c4) 100%);
      position:relative; overflow:hidden;
    }
    .modal-ciudad-head::before {
      content:''; position:absolute; top:-50%; right:-15%; width:140px; height:140px;
      border-radius:50%; background:rgba(255,255,255,.07);
    }
    .modal-ciudad-head span {
      font-family:'Fraunces',serif; font-size:1rem; font-weight:800; color:#fff;
      display:flex; align-items:center; gap:.55rem; position:relative; z-index:1;
      text-shadow:0 1px 4px rgba(80,30,5,.25);
    }
    .modal-ciudad-head span i { font-size:1.1rem; }
    .modal-ciudad-close {
      background:rgba(255,255,255,.18); border:1px solid rgba(255,255,255,.25);
      border-radius:10px; width:32px; height:32px; color:#fff; font-size:.9rem;
      cursor:pointer; display:flex; align-items:center; justify-content:center;
      transition:all .2s; position:relative; z-index:1;
    }
    .modal-ciudad-close:hover { background:rgba(255,255,255,.32); transform:scale(1.05); }
    .modal-ciudad-search {
      padding:.75rem 1rem; background:#fff;
      border-bottom:1px solid var(--border);
    }
    .modal-ciudad-search input {
      width:100%; padding:.55rem .85rem .55rem 2.2rem;
      border:1.5px solid var(--border); border-radius:10px;
      font-family:inherit; font-size:.85rem; color:var(--ink);
      background:var(--clight) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%23b87a4a' viewBox='0 0 16 16'%3E%3Cpath d='M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001q.044.06.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1 1 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0'/%3E%3C/svg%3E") no-repeat .7rem center;
      outline:none; transition:border-color .2s, box-shadow .2s;
    }
    .modal-ciudad-search input::placeholder { color:var(--ink3); }
    .modal-ciudad-search input:focus { border-color:var(--c3); box-shadow:0 0 0 3px rgba(198,113,36,.1); }
    .modal-ciudad-list { overflow-y:auto; flex:1; background:#fff; }
    .modal-ciudad-item {
      display:flex; align-items:center; gap:.65rem;
      padding:.7rem 1.2rem; cursor:pointer; font-size:.84rem;
      color:var(--ink2); transition:all .15s;
      border-bottom:1px solid rgba(148,91,53,.06);
    }
    .modal-ciudad-item:last-child { border-bottom:none; }
    .modal-ciudad-item:hover { background:var(--clight); color:var(--ink); padding-left:1.4rem; }
    .modal-ciudad-item.activa {
      background:linear-gradient(90deg, rgba(198,113,36,.08), rgba(198,113,36,.02));
      color:var(--c3); font-weight:700;
      border-left:3px solid var(--c3);
      padding-left:calc(1.2rem - 3px);
    }
    .modal-ciudad-item i { color:var(--c5); font-size:.85rem; flex-shrink:0; transition:color .15s; }
    .modal-ciudad-item:hover i { color:var(--c3); }
    .modal-ciudad-item.activa i { color:var(--c3); }
  </style>
</head>
<body>

<nav id="main-nav">
  <!-- LOGO -->
  <a href="<?= APP_URL ?>/modules/tablero/index.php" class="n-logo">
    <img src="<?= APP_URL ?>/assets/img/logo.png"
         onerror="this.style.display='none'"
         alt="BreakControl" class="n-logo-img">
    <div>
      <div class="n-logo-name">BreakControl</div>
      <div class="n-logo-sub">Sistema de gestión</div>
    </div>
  </a>

  <div class="n-sep"></div>

  <!-- MENÚ -->
  <div class="n-menu" id="n-menu">
    <a href="<?= APP_URL ?>/modules/tablero/index.php"    class="n-item <?= navActive('/tablero') ?>"><i class="bi bi-speedometer2"></i><span class="n-lbl">Tablero</span></a>
    <a href="<?= APP_URL ?>/modules/inventario/index.php" class="n-item <?= navActive('/inventario') ?>"><i class="bi bi-box-seam-fill"></i><span class="n-lbl">Inventario</span></a>
    <a href="<?= APP_URL ?>/modules/produccion/index.php" class="n-item <?= navActive('/produccion') ?>"><i class="bi bi-fire"></i><span class="n-lbl">Producción</span></a>
    <a href="<?= APP_URL ?>/modules/ventas/index.php"     class="n-item <?= navActive('/ventas') ?>"><i class="bi bi-bag-fill"></i><span class="n-lbl">Ventas</span></a>
    <a href="<?= APP_URL ?>/modules/recetas/index.php"    class="n-item <?= navActive('/recetas') ?>"><i class="bi bi-journal-richtext"></i><span class="n-lbl">Recetas</span></a>
    <a href="<?= APP_URL ?>/modules/compras/index.php"    class="n-item <?= navActive('/compras') ?>"><i class="bi bi-cart-fill"></i><span class="n-lbl">Compras</span></a>
    <a href="<?= APP_URL ?>/modules/finanzas/index.php"   class="n-item <?= navActive('/finanzas') ?>"><i class="bi bi-cash-stack"></i><span class="n-lbl">Finanzas</span></a>
    <a href="<?= APP_URL ?>/modules/gastos/index.php"     class="n-item <?= navActive('/gastos') ?>"><i class="bi bi-receipt-cutoff"></i><span class="n-lbl">Gastos</span></a>
    <a href="<?= APP_URL ?>/modules/cierre/index.php"     class="n-item <?= navActive('/cierre') ?>"><i class="bi bi-moon-stars-fill"></i><span class="n-lbl">Cierre del día</span></a>
    <!-- Solo visible en mobile: config + ciudad + logout -->
    <div class="n-menu-sep"></div>
    <a href="<?= APP_URL ?>/modules/configuracion/pin.php" class="n-menu-ciudad" style="text-decoration:none;">
      <i class="bi bi-key-fill"></i><span>Configurar PIN</span>
    </a>
    <button class="n-menu-ciudad" onclick="abrirModalCiudad()">
      <i class="bi bi-geo-alt-fill"></i><span>Cambiar ciudad</span>
    </button>
    <a href="<?= APP_URL ?>/logout.php" class="n-menu-logout">
      <i class="bi bi-box-arrow-right"></i><span>Cerrar sesión</span>
    </a>
  </div>

  <!-- DERECHA -->
  <div class="n-right">
    <span class="n-clock" id="nc">--:--</span>

    <!-- Botón ciudad (desktop) -->
    <button class="n-ciudad-btn" onclick="abrirModalCiudad()" title="Cambiar ciudad">
      <i class="bi bi-geo-alt-fill"></i>
      <span id="ciudad-lbl">Florencia</span>
    </button>

    <!-- Usuario (click va a config PIN) -->
    <a href="<?= APP_URL ?>/modules/configuracion/pin.php" class="n-user" style="text-decoration:none;cursor:pointer;" title="Configurar PIN de recuperación">
      <div class="n-avatar"><?= strtoupper(substr($user['nombre'], 0, 1)) ?></div>
      <div>
        <div class="n-uname"><?= htmlspecialchars($user['nombre']) ?></div>
        <div class="n-urole">Propietario</div>
      </div>
    </a>

    <!-- Logout (desktop) -->
    <a href="<?= APP_URL ?>/logout.php" class="n-logout" title="Cerrar sesión">
      <i class="bi bi-box-arrow-right"></i>
    </a>

    <!-- Hamburguesa -->
    <button class="n-hamburger" id="n-ham" aria-label="Menú">
      <i class="bi bi-list" id="ham-ico"></i>
    </button>
  </div>
</nav>

<!-- MODAL CIUDAD -->
<div id="modal-ciudad" class="modal-ciudad-overlay">
  <div class="modal-ciudad-box">
    <div class="modal-ciudad-head">
      <span><i class="bi bi-geo-alt-fill"></i> Seleccionar ciudad</span>
      <button class="modal-ciudad-close" onclick="cerrarModalCiudad()" aria-label="Cerrar">✕</button>
    </div>
    <div class="modal-ciudad-search">
      <input type="text" id="ciudad-buscar" placeholder="Buscar ciudad o departamento…" oninput="filtrarCiudades(this.value)">
    </div>
    <div class="modal-ciudad-list" id="ciudad-lista"></div>
  </div>
</div>

<script>
/* ── Reloj ── */
(function tick(){
  document.getElementById('nc').textContent =
    new Date().toLocaleTimeString('es-CO',{hour:'2-digit',minute:'2-digit'});
  setTimeout(tick,1000);
})();

/* ── Ciudades ── */
var CIUDADES = {
  '1.6144,-75.6062':'Caquetá, Florencia',
  '4.6097,-74.0817':'Cundinamarca, Bogotá',
  '6.2518,-75.5636':'Antioquia, Medellín',
  '3.4516,-76.5320':'Valle del Cauca, Cali',
  '10.9685,-74.7813':'Atlántico, Barranquilla',
  '10.2508,-75.3217':'Bolívar, Cartagena',
  '2.9273,-75.2819':'Huila, Neiva',
  '7.1193,-73.1227':'Santander, Bucaramanga',
  '4.4389,-75.2322':'Tolima, Ibagué',
  '4.8133,-75.6961':'Risaralda, Pereira',
  '4.5339,-75.6811':'Quindío, Armenia',
  '5.0689,-75.5174':'Caldas, Manizales',
  '5.5353,-73.3678':'Boyacá, Tunja',
  '11.2404,-74.1990':'Magdalena, Santa Marta',
  '10.4631,-73.2532':'Cesar, Valledupar',
  '1.2136,-77.2811':'Nariño, Pasto',
  '7.8939,-72.5078':'Norte de Santander, Cúcuta',
  '8.7500,-75.8833':'Córdoba, Montería',
  '9.3047,-75.3978':'Sucre, Sincelejo',
  '11.5444,-72.9072':'La Guajira, Riohacha',
  '5.6922,-76.6581':'Chocó, Quibdó',
  '4.1420,-73.6266':'Meta, Villavicencio',
  '7.0805,-70.7602':'Arauca, Arauca',
  '5.3080,-72.4121':'Casanare, Yopal',
  '2.4419,-76.6063':'Cauca, Popayán',
  '1.1472,-76.6464':'Putumayo, Mocoa',
  '4.1227,-69.5642':'Amazonas, Leticia',
  '3.8653,-67.9239':'Guainía, Inírida',
  '2.5683,-72.6417':'Guaviare, San José del Guaviare',
  '1.1983,-70.1733':'Vaupés, Mitú',
  '6.1890,-67.4850':'Vichada, Puerto Carreño',
  '12.5847,-81.7006':'San Andrés'
};

/* Leer ciudad guardada */
var ciudadActual = localStorage.getItem('pan_ciudad') || '1.6144,-75.6062';
(function(){
  var nombre = CIUDADES[ciudadActual] || 'Florencia';
  var lbl = document.getElementById('ciudad-lbl');
  if (lbl) lbl.textContent = nombre.split(',')[1] ? nombre.split(',')[1].trim() : nombre;
})();

function renderListaCiudades(filtro) {
  var lista = document.getElementById('ciudad-lista');
  lista.innerHTML = '';
  var q = (filtro || '').toLowerCase();
  Object.keys(CIUDADES).forEach(function(coord) {
    var nombre = CIUDADES[coord];
    if (q && nombre.toLowerCase().indexOf(q) === -1) return;
    var div = document.createElement('div');
    div.className = 'modal-ciudad-item' + (coord === ciudadActual ? ' activa' : '');
    div.innerHTML = '<i class="bi bi-' + (coord === ciudadActual ? 'geo-alt-fill' : 'geo-alt') + '"></i>' + nombre;
    div.onclick = function() {
      ciudadActual = coord;
      localStorage.setItem('pan_ciudad', coord);
      var nombreCorto = nombre.split(',')[1] ? nombre.split(',')[1].trim() : nombre;
      var lbl = document.getElementById('ciudad-lbl');
      if (lbl) lbl.textContent = nombreCorto;
      cerrarModalCiudad();
      if (typeof window.ciudadCambiada === 'function') window.ciudadCambiada(coord, nombre);
    };
    lista.appendChild(div);
  });
}

function filtrarCiudades(q) { renderListaCiudades(q); }

function abrirModalCiudad() {
  document.getElementById('ciudad-buscar').value = '';
  renderListaCiudades('');
  document.getElementById('modal-ciudad').classList.add('open');
  document.body.style.overflow = 'hidden';
  setTimeout(function(){ document.getElementById('ciudad-buscar').focus(); }, 100);
}

function cerrarModalCiudad() {
  document.getElementById('modal-ciudad').classList.remove('open');
  document.body.style.overflow = '';
}

document.getElementById('modal-ciudad').addEventListener('click', function(e){
  if (e.target === this) cerrarModalCiudad();
});

/* ── Hamburguesa ── */
(function(){
  var btn  = document.getElementById('n-ham');
  var menu = document.getElementById('n-menu');
  var nav  = document.getElementById('main-nav');
  var ico  = document.getElementById('ham-ico');

  btn.addEventListener('click', function(){
    var open = menu.classList.toggle('open');
    nav.classList.toggle('nav-open', open);
    ico.className = open ? 'bi bi-x-lg' : 'bi bi-list';
  });

  menu.querySelectorAll('.n-item').forEach(function(a){
    a.addEventListener('click', function(){
      menu.classList.remove('open');
      nav.classList.remove('nav-open');
      ico.className = 'bi bi-list';
    });
  });
})();
</script>

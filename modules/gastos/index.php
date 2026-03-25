<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/sesion.php';
require_once __DIR__ . '/../../includes/funciones.php';

requerirPropietario();
$pdo  = getConexion();
$user = usuarioActual();
$hoy  = date('Y-m-d');

$msg_ok  = '';
$msg_err = '';

// ── Registrar gasto ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_gasto'])) {
    $cat  = $_POST['categoria']  ?? '';
    $desc = trim($_POST['descripcion'] ?? '');
    $val  = (float)str_replace(['.', '$', ' '], '', $_POST['valor'] ?? 0);

    // Validar categoría permitida
    if (!in_array($cat, ['compra','servicio','otro'])) $cat = '';

    if (!$cat || !$desc || $val <= 0) {
        $msg_err = 'Completa todos los campos correctamente.';
    } else {
        try {
            $pdo->prepare("INSERT INTO gasto (id_usuario, categoria, descripcion, valor) VALUES (?,?,?,?)")
                ->execute([$user['id_usuario'], $cat, $desc, $val]);
            $msg_ok = 'Gasto registrado correctamente.';
        } catch (Exception $e) {
            $msg_err = 'Error al registrar el gasto.';
        }
    }
}

// ── Eliminar gasto (solo del día actual) ──────────────────────────────────
if (!empty($_GET['del'])) {
    try {
        $pdo->prepare("DELETE FROM gasto WHERE id_gasto=? AND DATE(fecha_gasto)=CURDATE()")
            ->execute([(int)$_GET['del']]);
    } catch (Exception $e) { /* error silencioso */ }
    $redir_fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['fecha'] ?? '') ? $_GET['fecha'] : $hoy;
    header('Location: index.php?fecha=' . $redir_fecha); exit;
}

// ── Filtro por fecha ───────────────────────────────────────────────────────
$fecha_fil = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['fecha'] ?? '') ? $_GET['fecha'] : $hoy;

// Gastos del día filtrado
$stmt = $pdo->prepare("
    SELECT g.*, u.nombre_completo AS usuario
    FROM gasto g
    LEFT JOIN usuario u ON u.id_usuario = g.id_usuario
    WHERE DATE(g.fecha_gasto) = ?
    ORDER BY g.fecha_gasto DESC
");
$stmt->execute([$fecha_fil]);
$gastos_dia = $stmt->fetchAll();

$por_cat   = [];
foreach ($gastos_dia as $g) $por_cat[$g['categoria']] = ($por_cat[$g['categoria']] ?? 0) + $g['valor'];
$total_dia = array_sum(array_column($gastos_dia, 'valor'));

// Ingresos / compras del día para utilidad neta
$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_venta),0) FROM venta WHERE DATE(fecha_hora)=?");
$stmt->execute([$fecha_fil]);
$ingresos_dia = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_pagado),0) FROM compra WHERE DATE(fecha_compra)=?");
$stmt->execute([$fecha_fil]);
$compras_dia = (float)$stmt->fetchColumn();

$utilidad_neta = $ingresos_dia - $compras_dia - $total_dia;

// Totales del mes actual
$gastos_mes     = (float)$pdo->query("SELECT COALESCE(SUM(valor),0) FROM gasto WHERE MONTH(fecha_gasto)=MONTH(CURDATE()) AND YEAR(fecha_gasto)=YEAR(CURDATE())")->fetchColumn();
$num_gastos_mes = (int)$pdo->query("SELECT COUNT(*) FROM gasto WHERE MONTH(fecha_gasto)=MONTH(CURDATE()) AND YEAR(fecha_gasto)=YEAR(CURDATE())")->fetchColumn();

// Últimos 7 días para mini gráfico
$stmt = $pdo->query("
    SELECT DATE(fecha_gasto) AS dia, SUM(valor) AS total
    FROM gasto
    WHERE fecha_gasto >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(fecha_gasto) ORDER BY dia ASC
");
$gastos_7d_raw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$gastos_7d = [];
for ($i = 6; $i >= 0; $i--) {
    $f = date('Y-m-d', strtotime("-$i days"));
    $gastos_7d[] = ['lbl' => date('d/m', strtotime($f)), 'v' => (float)($gastos_7d_raw[$f] ?? 0)];
}
$chart_max_7d = max(array_column($gastos_7d, 'v') ?: [1]);

// Categorías
$cat_labels = [
    'compra'   => ['🛒', 'Compras',   '#1565c0', 'rgba(21,101,192,.1)'],
    'servicio' => ['💡', 'Servicios', '#e65100', 'rgba(230,81,0,.1)'],
    'otro'     => ['📝', 'Otros',     '#2e7d32', 'rgba(46,125,50,.1)'],
];

$page_title = 'Gastos';
require_once __DIR__ . '/../../views/layouts/header.php';
?>
<style>
  :root{--c1:#945b35;--c2:#c8956e;--c3:#c67124;--c4:#e4a565;--c5:#ecc198;--cbg:#faf3ea;--ccard:#fff;--clight:#fdf6ee;--ink:#281508;--ink2:#6b3d1e;--ink3:#b87a4a;--border:rgba(148,91,53,.12);--shadow:0 1px 8px rgba(148,91,53,.09);--shadow2:0 4px 20px rgba(148,91,53,.15);--nav-h:64px;}
  @keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
  @keyframes gradAnim{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}

  /* ── PAGE ── */
  .page{margin-top:var(--nav-h);min-height:calc(100vh - var(--nav-h));overflow-y:auto;display:grid;grid-template-rows:auto auto 1fr;gap:.7rem;padding:.75rem;}

  /* ── BANNER ── */
  .wc-banner{background:linear-gradient(125deg,#6b3211 0%,#945b35 18%,#c67124 35%,#e4a565 50%,#c67124 65%,#945b35 80%,#6b3211 100%);background-size:300% 300%;animation:gradAnim 8s ease infinite;border-radius:14px;padding:.9rem 1.4rem;display:flex;align-items:center;justify-content:space-between;box-shadow:var(--shadow2);gap:1rem;flex-wrap:wrap;}
  .wc-left{display:flex;align-items:center;gap:.9rem;}
  .wc-greeting{font-size:.65rem;text-transform:uppercase;letter-spacing:.2em;color:rgba(255,255,255,.65);margin-bottom:.15rem;}
  .wc-name{font-family:'Fraunces',serif;font-size:1.35rem;font-weight:800;color:#fff;line-height:1.1;}
  .wc-name em{font-style:italic;color:var(--c5);}
  .wc-sub{font-size:.72rem;color:rgba(255,255,255,.62);margin-top:.15rem;}
  .wc-pills{display:flex;gap:.55rem;flex-wrap:wrap;}
  .wc-pill{background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.2);border-radius:10px;padding:.5rem .85rem;text-align:center;min-width:68px;}
  .wc-pill-num{font-family:'Fraunces',serif;font-size:1.05rem;font-weight:800;color:#fff;line-height:1;}
  .wc-pill-lbl{font-size:.54rem;text-transform:uppercase;letter-spacing:.12em;color:rgba(255,255,255,.58);}
  .wc-pill.ok{background:rgba(200,255,220,.2);border-color:rgba(200,255,220,.35);}
  .wc-pill.ok .wc-pill-num{color:#c8ffd8;}
  .wc-pill.alert{background:rgba(255,205,210,.25);border-color:rgba(255,205,210,.4);}
  .wc-pill.alert .wc-pill-num{color:#ffcdd2;}

  /* ── TOPBAR ── */
  .topbar{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;}
  .mod-titulo{font-family:'Fraunces',serif;font-size:1.45rem;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:.5rem;}
  .mod-titulo i{color:var(--c3);}
  .top-actions{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;}
  .fil-lbl{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--ink3);}
  .fil-date{border:1px solid var(--border);border-radius:9px;padding:.42rem .75rem;font-size:.82rem;font-family:inherit;color:var(--ink);background:var(--ccard);}
  .fil-date:focus{outline:none;border-color:var(--c3);box-shadow:0 0 0 3px rgba(198,113,36,.1);}
  .btn-hoy{background:var(--clight);color:var(--ink2);border:1px solid var(--border);border-radius:9px;padding:.42rem .85rem;font-size:.8rem;font-weight:700;cursor:pointer;font-family:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:.35rem;transition:all .2s;}
  .btn-hoy:hover{border-color:var(--c3);color:var(--c3);}

  /* ── CUERPO ── */
  .g-body{display:grid;grid-template-columns:320px 1fr;gap:.7rem;min-height:0;}

  /* ── CARD ── */
  .card{background:var(--ccard);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);display:flex;flex-direction:column;overflow:hidden;animation:fadeUp .45s ease both;}
  .card:nth-child(1){animation-delay:.25s}.card:nth-child(2){animation-delay:.30s}
  .ch{display:flex;align-items:center;justify-content:space-between;padding:.8rem 1.1rem;flex-shrink:0;border-bottom:1px solid var(--border);}
  .ch-left{display:flex;align-items:center;gap:.5rem;}
  .ch-ico{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1rem;}
  .ico-nar{background:rgba(198,113,36,.1);color:var(--c3);}
  .ico-red{background:rgba(198,40,40,.1);color:#c62828;}
  .ch-title{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.17em;color:var(--ink3);}
  .badge{display:inline-flex;align-items:center;font-size:.62rem;font-weight:700;padding:.15rem .5rem;border-radius:20px;}
  .b-neu{background:var(--clight);color:var(--c1);border:1px solid var(--border);}

  /* ── FORMULARIO ── */
  .form-body{padding:.9rem 1.1rem;overflow-y:auto;flex:1;}
  .fl{margin-bottom:.65rem;}
  .fl label{font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:var(--ink3);display:block;margin-bottom:.3rem;}
  .fl input,.fl select{width:100%;border:1px solid var(--border);border-radius:9px;padding:.45rem .75rem;font-size:.84rem;color:var(--ink);font-family:inherit;background:var(--clight);transition:border-color .2s,box-shadow .2s;}
  .fl input:focus,.fl select:focus{outline:none;border-color:var(--c3);box-shadow:0 0 0 3px rgba(198,113,36,.1);}

  /* Botones de categoría */
  .cat-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:.4rem;margin-bottom:.65rem;}
  .cat-btn{padding:.6rem .3rem;border-radius:9px;font-size:.72rem;font-weight:700;border:1.5px solid var(--border);background:var(--clight);color:var(--ink2);cursor:pointer;transition:all .18s;font-family:inherit;text-align:center;display:flex;align-items:center;justify-content:center;gap:.2rem;flex-direction:column;line-height:1.2;}
  .cat-btn:hover{transform:translateY(-1px);box-shadow:var(--shadow);}
  .cat-btn.sel{color:#fff;border-color:transparent;box-shadow:0 3px 12px rgba(0,0,0,.15);}
  .cat-btn.compra.sel{background:#1565c0;}
  .cat-btn.servicio.sel{background:#e65100;}
  .cat-btn.otro.sel{background:#2e7d32;}

  .btn-guardar{width:100%;background:linear-gradient(135deg,var(--c3),var(--c1));color:#fff;border:none;border-radius:10px;padding:.65rem;font-size:.88rem;font-weight:700;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px rgba(198,113,36,.3);display:flex;align-items:center;justify-content:center;gap:.4rem;transition:all .2s;margin-top:.2rem;}
  .btn-guardar:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(198,113,36,.4);}

  /* Mini gráfico 7 días */
  .graf-zona{border-top:1px solid var(--border);padding:.75rem 1.1rem;flex-shrink:0;}
  .graf-titulo{font-size:.6rem;text-transform:uppercase;letter-spacing:.15em;color:var(--ink3);margin-bottom:.55rem;display:flex;align-items:center;gap:.35rem;}
  .grafico-mini{display:flex;align-items:flex-end;gap:4px;height:55px;padding-bottom:1.1rem;position:relative;}
  .gm-col{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;position:relative;}
  .gm-bar{width:100%;border-radius:3px 3px 0 0;min-height:3px;background:linear-gradient(to top,#c62828,#ef9a9a);transition:height .5s ease;cursor:pointer;position:relative;}
  .gm-bar.hoy{background:linear-gradient(to top,var(--c1),var(--c4));}
  .gm-bar:hover::after{content:attr(data-tip);position:absolute;bottom:105%;left:50%;transform:translateX(-50%);background:var(--ink);color:#fff;font-size:.6rem;padding:.15rem .4rem;border-radius:5px;white-space:nowrap;z-index:10;pointer-events:none;}
  .gm-lbl{position:absolute;bottom:-1rem;font-size:.53rem;color:var(--ink3);white-space:nowrap;}

  /* Resumen por categoría */
  .cat-sum{border-top:1px solid var(--border);padding:.75rem 1.1rem;flex-shrink:0;}
  .cat-sum-title{font-size:.6rem;text-transform:uppercase;letter-spacing:.14em;color:var(--ink3);margin-bottom:.5rem;}
  .cat-row{display:flex;align-items:center;gap:.5rem;margin-bottom:.32rem;}
  .cat-row:last-child{margin-bottom:0;}
  .cat-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
  .cat-name{font-size:.76rem;font-weight:600;flex:1;color:var(--ink2);}
  .cat-bar-w{width:48px;height:4px;background:var(--clight);border-radius:2px;overflow:hidden;flex-shrink:0;}
  .cat-bar-f{height:100%;border-radius:2px;}
  .cat-val{font-size:.73rem;font-weight:700;color:var(--ink2);min-width:68px;text-align:right;}

  /* Mensajes */
  .msg-ok{background:#e8f5e9;border:1px solid #a5d6a7;border-left:3px solid #2e7d32;border-radius:10px;padding:.6rem .9rem;font-size:.82rem;color:#1b5e20;font-weight:600;margin-bottom:.6rem;display:flex;align-items:center;gap:.4rem;}
  .msg-err{background:#ffebee;border:1px solid #ef9a9a;border-left:3px solid #c62828;border-radius:10px;padding:.6rem .9rem;font-size:.82rem;color:#c62828;margin-bottom:.6rem;display:flex;align-items:center;gap:.4rem;}

  /* ── TABLA ── */
  .tbl-wrap{overflow-y:auto;overflow-x:auto;flex:1;min-height:0;}
  .gt{width:100%;border-collapse:collapse;}
  .gt th{font-size:.61rem;text-transform:uppercase;letter-spacing:.1em;color:var(--ink3);font-weight:700;padding:.5rem .85rem;background:var(--clight);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:1;white-space:nowrap;}
  .gt td{font-size:.81rem;color:var(--ink);padding:.5rem .85rem;border-bottom:1px solid rgba(148,91,53,.05);vertical-align:middle;}
  .gt tr:last-child td{border-bottom:none;}
  .gt tr:hover td{background:rgba(250,243,234,.5);}
  .cat-tag{font-size:.6rem;font-weight:700;padding:.13rem .45rem;border-radius:20px;white-space:nowrap;display:inline-flex;align-items:center;gap:.22rem;}
  .btn-act{width:28px;height:28px;border-radius:7px;border:1px solid;display:inline-flex;align-items:center;justify-content:center;font-size:.8rem;text-decoration:none;transition:all .2s;cursor:pointer;background:transparent;}
  .btn-del{border-color:rgba(198,40,40,.2);color:#c62828;}.btn-del:hover{background:rgba(198,40,40,.1);}
  .gt tfoot td{background:var(--clight);padding:.55rem .85rem;font-weight:800;border-top:1.5px solid var(--border);}
  .tot-lbl{font-size:.72rem;color:var(--ink2);text-transform:uppercase;letter-spacing:.1em;text-align:right;}
  .tot-val{font-family:'Fraunces',serif;font-size:1.05rem;color:#c62828;text-align:right;}

  /* Caja utilidad neta */
  .util-box{margin:.65rem .85rem;padding:.65rem .9rem;border-radius:10px;font-size:.82rem;font-weight:700;display:flex;align-items:center;gap:.55rem;flex-shrink:0;}
  .util-box.pos{background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;}
  .util-box.neg{background:#ffebee;color:#c62828;border:1px solid #ef9a9a;}
  .util-num{font-family:'Fraunces',serif;font-size:1.1rem;margin-left:auto;}

  /* Empty */
  .empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.5rem;padding:2.5rem 1rem;color:var(--ink3);font-size:.82rem;text-align:center;flex:1;}
  .empty i{font-size:2.2rem;opacity:.3;}

  @media(max-width:768px){
    .page{height:auto;overflow:visible;margin-top:60px;}
    .g-body{grid-template-columns:1fr;}
    .tbl-wrap{max-height:350px;}
  }
</style>

<div class="page">

  <!-- ══ BANNER ══ -->
  <div class="wc-banner">
    <div class="wc-left">
      <div>
        <div class="wc-greeting">Panadería BreakControl</div>
        <div class="wc-name">Gastos <em>Operativos</em></div>
        <div class="wc-sub"><?= date('l, d \d\e F \d\e Y', strtotime($fecha_fil)) ?></div>
      </div>
    </div>
    <div class="wc-pills">
      <div class="wc-pill <?= $total_dia > 0 ? 'alert' : '' ?>">
        <div class="wc-pill-num">$<?= number_format($total_dia / 1000, 1) ?>k</div>
        <div class="wc-pill-lbl">Gastos día</div>
      </div>
      <div class="wc-pill">
        <div class="wc-pill-num">$<?= number_format($gastos_mes / 1000, 1) ?>k</div>
        <div class="wc-pill-lbl">Mes actual</div>
      </div>
      <div class="wc-pill">
        <div class="wc-pill-num"><?= $num_gastos_mes ?></div>
        <div class="wc-pill-lbl">Registros</div>
      </div>
      <div class="wc-pill <?= $utilidad_neta < 0 ? 'alert' : 'ok' ?>">
        <div class="wc-pill-num"><?= $utilidad_neta >= 0 ? '+' : '-' ?>$<?= number_format(abs($utilidad_neta) / 1000, 1) ?>k</div>
        <div class="wc-pill-lbl">Util. neta</div>
      </div>
    </div>
  </div>

  <!-- ══ TOPBAR ══ -->
  <div class="topbar">
    <div class="mod-titulo">
      <i class="bi bi-receipt-cutoff"></i> Gastos
    </div>
    <div class="top-actions">
      <span class="fil-lbl"><i class="bi bi-calendar3"></i></span>
      <input type="date" class="fil-date" value="<?= $fecha_fil ?>"
             onchange="location.href='?fecha='+this.value">
      <?php if ($fecha_fil !== $hoy): ?>
      <a href="index.php" class="btn-hoy"><i class="bi bi-arrow-counterclockwise"></i> Hoy</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- ══ CUERPO ══ -->
  <div class="g-body">

    <!-- ── CARD IZQUIERDA: formulario + gráfico + resumen ── -->
    <div class="card">
      <div class="ch">
        <div class="ch-left">
          <div class="ch-ico ico-nar"><i class="bi bi-plus-circle-fill"></i></div>
          <span class="ch-title">Registrar gasto</span>
        </div>
      </div>

      <div class="form-body">
        <?php if ($msg_ok): ?>
        <div class="msg-ok"><i class="bi bi-check-circle-fill"></i><?= $msg_ok ?></div>
        <?php endif; ?>
        <?php if ($msg_err): ?>
        <div class="msg-err"><i class="bi bi-exclamation-triangle-fill"></i><?= $msg_err ?></div>
        <?php endif; ?>

        <form method="POST">
          <div class="fl">
            <label>Categoría</label>
            <div class="cat-grid">
              <button type="button" class="cat-btn compra"   onclick="selCat('compra')"   id="cat-compra">  🛒<span>Compras</span></button>
              <button type="button" class="cat-btn servicio" onclick="selCat('servicio')" id="cat-servicio">💡<span>Servicios</span></button>
              <button type="button" class="cat-btn otro"     onclick="selCat('otro')"     id="cat-otro">    📝<span>Otros</span></button>
            </div>
            <input type="hidden" name="categoria" id="inp-cat" value="">
          </div>
          <div class="fl">
            <label>Descripción</label>
            <input type="text" name="descripcion" placeholder="Ej: Pago energía, Arriendo…" required>
          </div>
          <div class="fl">
            <label>Valor ($)</label>
            <input type="number" name="valor" placeholder="Ej: 85000" min="1" step="1" required>
          </div>
          <button type="submit" name="guardar_gasto" class="btn-guardar">
            <i class="bi bi-floppy-fill"></i> Guardar gasto
          </button>
        </form>
      </div>

      <!-- Mini gráfico 7 días -->
      <div class="graf-zona">
        <div class="graf-titulo"><i class="bi bi-bar-chart-fill"></i>Gastos últimos 7 días</div>
        <div class="grafico-mini">
          <?php foreach ($gastos_7d as $gd):
            $h = $chart_max_7d > 0 ? max(3, round(($gd['v'] / $chart_max_7d) * 48)) : 3;
            $es_hoy = $gd['lbl'] === date('d/m');
          ?>
          <div class="gm-col">
            <div class="gm-bar <?= $es_hoy ? 'hoy' : '' ?>" style="height:<?= $h ?>px"
                 data-tip="<?= $gd['lbl'] ?>: $<?= number_format($gd['v'],0,',','.') ?>"></div>
            <span class="gm-lbl"><?= $gd['lbl'] ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Resumen por categoría del día -->
      <?php if (!empty($por_cat)): ?>
      <div class="cat-sum">
        <div class="cat-sum-title">Distribución del día</div>
        <?php
        $max_cat  = max($por_cat ?: [1]);
        $cat_clrs = ['compra' => '#1565c0', 'servicio' => '#e65100', 'otro' => '#2e7d32'];
        foreach ($cat_labels as $k => $c):
          if (!isset($por_cat[$k])) continue;
          $pct = round(($por_cat[$k] / $max_cat) * 100);
        ?>
        <div class="cat-row">
          <div class="cat-dot" style="background:<?= $cat_clrs[$k] ?>"></div>
          <span class="cat-name"><?= $c[0] ?> <?= $c[1] ?></span>
          <div class="cat-bar-w"><div class="cat-bar-f" style="width:<?= $pct ?>%;background:<?= $cat_clrs[$k] ?>"></div></div>
          <span class="cat-val">$<?= number_format($por_cat[$k], 0, ',', '.') ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

    </div><!-- /card izquierda -->

    <!-- ── CARD DERECHA: tabla ── -->
    <div class="card">
      <div class="ch">
        <div class="ch-left">
          <div class="ch-ico ico-red"><i class="bi bi-list-ul"></i></div>
          <span class="ch-title">Gastos del <?= date('d/m/Y', strtotime($fecha_fil)) ?></span>
        </div>
        <span class="badge b-neu"><?= count($gastos_dia) ?> registro<?= count($gastos_dia) != 1 ? 's' : '' ?></span>
      </div>

      <?php if (empty($gastos_dia)): ?>
      <div class="empty">
        <i class="bi bi-receipt"></i>
        <strong>Sin gastos este día</strong>
        <span>Usa el formulario para registrar uno</span>
      </div>
      <?php else: ?>

      <div class="tbl-wrap">
        <table class="gt">
          <thead>
            <tr>
              <th>Hora</th>
              <th>Categoría</th>
              <th>Descripción</th>
              <th>Usuario</th>
              <th style="text-align:right">Valor</th>
              <th style="text-align:center">—</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $cat_cc = [
              'compra'   => ['#1565c0', 'rgba(21,101,192,.1)'],
              'servicio' => ['#e65100', 'rgba(230,81,0,.1)'],
              'otro'     => ['#2e7d32', 'rgba(46,125,50,.1)'],
            ];
            foreach ($gastos_dia as $g):
              $cc = $cat_cc[$g['categoria']] ?? ['#666','rgba(0,0,0,.08)'];
              $cl = $cat_labels[$g['categoria']] ?? ['📝','Otro'];
            ?>
            <tr>
              <td style="color:var(--ink3);font-size:.75rem;white-space:nowrap">
                <?= date('H:i', strtotime($g['fecha_gasto'])) ?>
              </td>
              <td>
                <span class="cat-tag" style="color:<?= $cc[0] ?>;background:<?= $cc[1] ?>">
                  <?= $cl[0] ?> <?= $cl[1] ?>
                </span>
              </td>
              <td style="font-weight:600;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                  title="<?= htmlspecialchars($g['descripcion']) ?>">
                <?= htmlspecialchars($g['descripcion']) ?>
              </td>
              <td style="font-size:.75rem;color:var(--ink3)">
                <?= htmlspecialchars($g['usuario'] ?? '—') ?>
              </td>
              <td style="text-align:right;font-weight:700;color:#c62828;font-family:'Fraunces',serif">
                $<?= number_format($g['valor'], 0, ',', '.') ?>
              </td>
              <td style="text-align:center">
                <a href="?del=<?= $g['id_gasto'] ?>&fecha=<?= $fecha_fil ?>"
                   class="btn-act btn-del" title="Eliminar"
                   onclick="return confirm('¿Eliminar este gasto?')">
                  <i class="bi bi-trash3"></i>
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="4" class="tot-lbl">Total gastos del día</td>
              <td class="tot-val">$<?= number_format($total_dia, 0, ',', '.') ?></td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- Caja utilidad neta del día -->
      <div class="util-box <?= $utilidad_neta >= 0 ? 'pos' : 'neg' ?>">
        <i class="bi bi-<?= $utilidad_neta >= 0 ? 'graph-up-arrow' : 'graph-down-arrow' ?>"></i>
        <div>
          <div style="font-size:.7rem;opacity:.72;font-weight:600">
            Ingresos $<?= number_format($ingresos_dia, 0, ',', '.') ?> —
            Compras $<?= number_format($compras_dia, 0, ',', '.') ?> —
            Gastos $<?= number_format($total_dia, 0, ',', '.') ?>
          </div>
          <div>Utilidad neta del día</div>
        </div>
        <div class="util-num">
          <?= $utilidad_neta >= 0 ? '+' : '-' ?>$<?= number_format(abs($utilidad_neta), 0, ',', '.') ?>
        </div>
      </div>

      <?php endif; ?>
    </div><!-- /card derecha -->

  </div><!-- /g-body -->
</div><!-- /page -->

<script>
function selCat(k) {
  document.querySelectorAll('.cat-btn').forEach(b => b.classList.remove('sel'));
  document.getElementById('cat-' + k).classList.add('sel');
  document.getElementById('inp-cat').value = k;
}
</script>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>

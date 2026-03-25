<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/sesion.php';
require_once __DIR__ . '/../../includes/funciones.php';

requerirPropietario();
$pdo  = getConexion();
$user = usuarioActual();

$ventas_hoy   = (float)$pdo->query("SELECT COALESCE(SUM(total_venta),0) FROM venta WHERE DATE(fecha_hora)=CURDATE()")->fetchColumn();
$ventas_ayer  = (float)$pdo->query("SELECT COALESCE(SUM(total_venta),0) FROM venta WHERE DATE(fecha_hora)=DATE_SUB(CURDATE(),INTERVAL 1 DAY)")->fetchColumn();
$num_ventas   = (int)$pdo->query("SELECT COUNT(*) FROM venta WHERE DATE(fecha_hora)=CURDATE()")->fetchColumn();
$diff_v       = $ventas_ayer > 0 ? round((($ventas_hoy - $ventas_ayer) / $ventas_ayer) * 100, 1) : null;

$ingresos_mes = (float)$pdo->query("SELECT COALESCE(SUM(total_venta),0) FROM venta WHERE MONTH(fecha_hora)=MONTH(CURDATE()) AND YEAR(fecha_hora)=YEAR(CURDATE())")->fetchColumn();
$compras_mes  = (float)$pdo->query("SELECT COALESCE(SUM(total_pagado),0) FROM compra WHERE MONTH(fecha_compra)=MONTH(CURDATE()) AND YEAR(fecha_compra)=YEAR(CURDATE())")->fetchColumn();
$utilidad_mes = $ingresos_mes - $compras_mes;

$total_insumos = (int)$pdo->query("SELECT COUNT(*) FROM insumo WHERE activo=1")->fetchColumn();
$prod_hoy      = (int)$pdo->query("SELECT COUNT(*) FROM produccion WHERE DATE(fecha_produccion)=CURDATE()")->fetchColumn();
$prods_act     = (int)$pdo->query("SELECT COUNT(*) FROM producto WHERE activo=1")->fetchColumn();

$alertas = $pdo->query("
    SELECT nombre, stock_actual, punto_reposicion, unidad_medida
    FROM insumo
    WHERE stock_actual <= punto_reposicion AND activo = 1
    ORDER BY (stock_actual / NULLIF(punto_reposicion, 0)) ASC
    LIMIT 5
")->fetchAll();
$num_alertas = count($alertas);

$prods_recientes = $pdo->query("
    SELECT pr.fecha_produccion, pr.cantidad_tandas, p.nombre, p.unidad_produccion
    FROM produccion pr
    INNER JOIN producto p ON p.id_producto = pr.id_producto
    ORDER BY pr.fecha_produccion DESC, pr.id_produccion DESC
    LIMIT 4
")->fetchAll();

$top_ventas = $pdo->query("
    SELECT p.nombre, SUM(v.unidades_vendidas) AS u, SUM(v.total_venta) AS t
    FROM venta v
    INNER JOIN producto p ON p.id_producto = v.id_producto
    WHERE DATE(v.fecha_hora) = CURDATE()
    GROUP BY v.id_producto
    ORDER BY t DESC
    LIMIT 4
")->fetchAll();

$dias_raw = $pdo->query("
    SELECT DATE(fecha_hora) AS d, COALESCE(SUM(total_venta), 0) AS t
    FROM venta
    WHERE fecha_hora >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(fecha_hora)
")->fetchAll(PDO::FETCH_KEY_PAIR);

$chart = [];
for ($i = 6; $i >= 0; $i--) {
    $f = date('Y-m-d', strtotime("-{$i} days"));
    $chart[] = [
        'lbl' => date('D', strtotime($f)),
        'v'   => (float)($dias_raw[$f] ?? 0),
        'hoy' => $i === 0
    ];
}
$chartMax = max(array_column($chart, 'v') ?: [1]);

// Ingredientes más consumidos hoy
$consumo_hoy = $pdo->query("
    SELECT i.nombre, i.unidad_medida,
           COALESCE(SUM(cl.cantidad_consumida),0) AS total
    FROM consumo_lote cl
    INNER JOIN lote l        ON l.id_lote       = cl.id_lote
    INNER JOIN insumo i      ON i.id_insumo      = l.id_insumo
    INNER JOIN produccion pr ON pr.id_produccion = cl.id_produccion
    WHERE DATE(pr.fecha_produccion) = CURDATE()
    GROUP BY i.id_insumo ORDER BY total DESC LIMIT 5
")->fetchAll();
if (empty($consumo_hoy)) {
    // Fallback: estimar desde recetas × tandas del día
    $consumo_hoy = $pdo->query("
        SELECT i.nombre, i.unidad_medida,
               SUM(ri.cantidad * pr.cantidad_tandas) AS total
        FROM produccion pr
        INNER JOIN receta_ingrediente ri ON ri.id_receta = pr.id_receta
        INNER JOIN insumo i ON i.id_insumo = ri.id_insumo
        WHERE DATE(pr.fecha_produccion) = CURDATE()
        GROUP BY i.id_insumo ORDER BY total DESC LIMIT 5
    ")->fetchAll();
}
$max_consumo_hoy = !empty($consumo_hoy) ? max(array_column($consumo_hoy, 'total')) : 1;
require_once __DIR__ . '/../../views/layouts/header.php';
?>
<!-- ESTILOS TABLERO -->
<style>
:root {
      --c1: #945b35;
      --c2: #c8956e;
      --c3: #c67124;
      --c4: #e4a565;
      --c5: #ecc198;
      --cbg: #faf3ea;
      --ccard: #ffffff;
      --clight: #fdf6ee;
      --ink: #281508;
      --ink2: #6b3d1e;
      --ink3: #b87a4a;
      --border: rgba(148,91,53,.12);
      --shadow: 0 1px 8px rgba(148,91,53,.09);
      --shadow2: 0 4px 20px rgba(148,91,53,.15);
      --nav-h: 64px;
    }

    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

    html, body {
      min-height: 100%;
      overflow-x: hidden;
      font-family: 'Plus Jakarta Sans', sans-serif;
      background: var(--cbg);
      color: var(--ink);
    }

    /* ══ LAYOUT PRINCIPAL ══ */
    .page {
      margin-top: var(--nav-h);
      padding: 1rem 1.1rem 1.5rem;
      display: grid;
      gap: 1rem;
    }

    /* ── BIENVENIDA ── */
    .welcome {
      display: grid;
      grid-template-columns: 1fr 210px;
      gap: 1rem;
      align-items: stretch;
    }

    @keyframes gradAnim{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}

    .wc-banner {
      background: linear-gradient(125deg,#6b3211 0%,#945b35 18%,#c67124 35%,#e4a565 50%,#c67124 65%,#945b35 80%,#6b3211 100%);
      background-size: 300% 300%;
      animation: gradAnim 8s ease infinite;
      border-radius: 14px;
      padding: .9rem 1.4rem;
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      box-shadow: var(--shadow2);
      flex-wrap: wrap;
    }

    .wc-greeting { font-size: .65rem; text-transform: uppercase; letter-spacing: .2em; color: rgba(255,255,255,.65); margin-bottom: .15rem; }
    .wc-name { font-family: 'Fraunces', serif; font-size: 1.35rem; font-weight: 800; color: #fff; line-height: 1.1; }
    .wc-name em { font-style: italic; color: var(--c5); }
    .wc-sub { font-size: .72rem; color: rgba(255,255,255,.62); margin-top: .15rem; }

    .wc-pills { display: flex; gap: .55rem; flex-shrink: 0; flex-wrap: wrap; }
    .wc-pill {
      background: rgba(255,255,255,.14);
      border: 1px solid rgba(255,255,255,.2);
      border-radius: 10px;
      padding: .5rem .85rem;
      text-align: center;
      min-width: 68px;
    }
    .wc-pill-num { font-family: 'Fraunces', serif; font-size: 1.35rem; font-weight: 800; color: #fff; line-height: 1; }
    .wc-pill-lbl { font-size: .54rem; text-transform: uppercase; letter-spacing: .12em; color: rgba(255,255,255,.58); }

    /* ── CLIMA ── */
    .clima-box {
      background: var(--ccard);
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: .9rem 1rem;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      box-shadow: var(--shadow);
      min-width: 210px;
    }
    .clima-top { display: flex; align-items: center; gap: .6rem; }
    .clima-ico { font-size: 2.2rem; line-height: 1; }
    .clima-temp { font-family: 'Fraunces', serif; font-size: 1.5rem; font-weight: 800; color: var(--ink); }
    .clima-desc { font-size: .78rem; color: var(--ink3); }
    .clima-city { font-size: .72rem; color: var(--c3); font-weight: 600; margin-top: .3rem; }
    .clima-city:hover { text-decoration: underline; }
    .clima-bottom { display: flex; align-items: center; justify-content: space-between; margin-top: .4rem; }
    .clima-upd { font-size: .62rem; color: var(--ink3); }
    .btn-ref {
      font-size: .66rem; font-weight: 600; color: var(--c3);
      background: none; border: 1px solid var(--border); border-radius: 8px;
      padding: .2rem .55rem; cursor: pointer; display: flex; align-items: center; gap: .3rem;
      transition: background .2s;
      font-family: inherit;
    }
    .btn-ref:hover { background: var(--clight); }
    .btn-ref.spin i { animation: spinRef .6s linear infinite; }
    @keyframes spinRef { to { transform: rotate(360deg); } }

    /* ══ GRID DE CARDS ══ */
    .grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 1rem;
    }

    .card {
      background: var(--ccard);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 1rem 1.1rem;
      box-shadow: var(--shadow);
      display: flex;
      flex-direction: column;
      gap: .45rem;
      overflow: hidden;
      transition: box-shadow .2s;
    }
    .card:hover { box-shadow: var(--shadow2); }

    /* ── CARD HEADER ── */
    .ch { display: flex; align-items: center; justify-content: space-between; margin-bottom: .25rem; flex-shrink: 0; }
    .ch-left { display: flex; align-items: center; gap: .5rem; }
    .ch-ico {
      width: 30px; height: 30px; border-radius: 9px;
      display: flex; align-items: center; justify-content: center;
      font-size: .95rem; flex-shrink: 0;
    }
    .ico-nar { background: rgba(198,113,36,.1); color: var(--c3); }
    .ico-red { background: rgba(198,40,40,.1); color: #c62828; }
    .ico-grn { background: rgba(46,125,50,.1); color: #2e7d32; }
    .ico-caf { background: rgba(148,91,53,.1); color: var(--c1); }
    .ch-title { font-size: .82rem; font-weight: 700; color: var(--ink); }

    /* ── BIG NUMBER ── */
    .bign { font-family: 'Fraunces', serif; font-size: 2rem; font-weight: 800; line-height: 1.1; }
    .bign.nar { color: var(--c3); }
    .sublbl { font-size: .72rem; color: var(--ink3); margin-top: -.1rem; }

    /* ── BADGES ── */
    .badge { font-size: .66rem; font-weight: 700; padding: .22rem .6rem; border-radius: 20px; white-space: nowrap; }
    .b-ok  { background: rgba(46,125,50,.1); color: #2e7d32; }
    .b-bad { background: rgba(198,40,40,.1); color: #c62828; }
    .b-neu { background: rgba(148,91,53,.1); color: var(--ink3); }

    /* ── MINI TABLE ── */
    .mt { width: 100%; border-collapse: collapse; margin-top: .35rem; }
    .mt th { font-size: .62rem; text-transform: uppercase; letter-spacing: .1em; color: var(--ink3); font-weight: 600; text-align: left; padding: .3rem .35rem; border-bottom: 1px solid var(--border); }
    .mt td { font-size: .78rem; padding: .35rem; border-bottom: 1px solid var(--border); }
    .mt td:last-child, .mt th:last-child { text-align: right; }
    .mt tbody tr:last-child td { border-bottom: none; }

    .al-row { display: flex; align-items: center; gap: 0.55rem; padding: 0.48rem 0; border-bottom: 1px solid var(--border); }
    .al-row:last-child { border-bottom: none; }
    .al-name { font-size: 0.82rem; font-weight: 600; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .al-bar-w { width: 52px; height: 5px; background: var(--clight); border-radius: 3px; overflow: hidden; flex-shrink: 0; }
    .al-bar-f { height: 100%; border-radius: 3px; background: linear-gradient(90deg, #c62828, #ef9a9a); }
    .al-val { font-size: 0.72rem; font-weight: 700; color: #c62828; white-space: nowrap; min-width: 55px; text-align: right; }

    .pr-row { display: flex; align-items: center; gap: 0.55rem; padding: 0.48rem 0; border-bottom: 1px solid var(--border); }
    .pr-row:last-child { border-bottom: none; }
    .pr-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--c4); flex-shrink: 0; }
    .pr-name { font-size: 0.84rem; font-weight: 600; flex: 1; }
    .pr-det  { font-size: 0.72rem; color: var(--ink3); }
    .pr-time { font-size: 0.68rem; color: var(--ink3); flex-shrink: 0; }

    .fin3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.55rem; flex-shrink: 0; margin-bottom: 0.8rem; }
    .fcard { background: var(--clight); border: 1px solid var(--border); border-radius: 10px; padding: 0.7rem 0.75rem; }
    .fcard-lbl { font-size: 0.6rem; text-transform: uppercase; letter-spacing: 0.12em; color: var(--ink3); margin-bottom: 0.3rem; display: flex; align-items: center; gap: 0.25rem; }
    .fcard-val { font-family: 'Fraunces', serif; font-size: 1.15rem; font-weight: 800; color: var(--ink); }
    .fcard-val.grn { color: #2e7d32; }
    .fcard-val.red { color: #c62828; }

    .graf-lbl { font-size: 0.62rem; text-transform: uppercase; letter-spacing: 0.14em; color: var(--ink3); margin-bottom: 0.5rem; flex-shrink: 0; }
    .graf-wrap { display: flex; align-items: flex-end; gap: 5px; flex: 1; min-height: 0; padding-bottom: 1px; }
    .gc { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 3px; height: 100%; justify-content: flex-end; }
    .gb { width: 100%; border-radius: 4px 4px 0 0; min-height: 3px; background: linear-gradient(to top, var(--c3), var(--c5)); transition: height 0.5s ease; }
    .gc.today .gb { background: linear-gradient(to top, var(--c1), var(--c3)); }
    .gd { font-size: 0.58rem; color: var(--ink3); }

    .ac-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.55rem; flex: 1; }
    .ac-btn { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.35rem; text-decoration: none; border-radius: 11px; padding: 0.65rem 0.4rem; font-size: 0.76rem; font-weight: 600; color: var(--ink); border: 1px solid var(--border); background: var(--clight); transition: all 0.2s; text-align: center; }
    .ac-btn i { font-size: 1.3rem; }
    .ac-btn:hover { transform: translateY(-2px); box-shadow: var(--shadow2); background: #fff; }

    .empty { display: flex; flex-direction: column; align-items: center; justify-content: center; flex: 1; gap: 0.35rem; color: var(--ink3); font-size: 0.8rem; text-align: center; opacity: 0.7; }
    .empty i { font-size: 1.7rem; opacity: 0.4; }

    ::-webkit-scrollbar { width: 3px; }
    ::-webkit-scrollbar-thumb { background: var(--c5); border-radius: 2px; }

    /* ══ RESPONSIVE ══ */
    @media (max-width: 1024px) {
      .grid {
        grid-template-columns: 1fr 1fr;
        grid-template-rows: auto;
      }
      .card { min-height: 280px; }
      .card[style*="grid-row: span 2"] { grid-column: 1 / -1 !important; grid-row: auto !important; }
      .page { overflow-y: auto; }
    }
    @media (min-width: 1025px){
      .grid{
         height: auto;
      }
    }
    @media (max-width: 480px) {
      .fin3 { grid-template-columns: 1fr; }
      .fin3 .fcard:last-child { grid-column: auto; }
    }
</style>

<!-- PÁGINA -->
<div class="page">

  <div class="welcome">
    <div class="wc-banner">
      <div>
        <div class="wc-greeting" id="wg"></div>
        <div class="wc-name">Bienvenido, <em><?= htmlspecialchars($user['nombre']) ?></em></div>
        <div class="wc-sub" id="ws"></div>
      </div>
      <div class="wc-pills">
        <div class="wc-pill">
          <div class="wc-pill-num"><?= $total_insumos ?></div>
          <div class="wc-pill-lbl">Insumos</div>
        </div>
        <div class="wc-pill" style="<?= $num_alertas > 0 ? 'background:rgba(255,205,210,.3)' : '' ?>">
          <div class="wc-pill-num" style="<?= $num_alertas > 0 ? 'color:#ffcdd2' : '' ?>"><?= $num_alertas ?></div>
          <div class="wc-pill-lbl">Alertas</div>
        </div>
        <div class="wc-pill">
          <div class="wc-pill-num"><?= $prod_hoy ?></div>
          <div class="wc-pill-lbl">Prod. hoy</div>
        </div>
        <div class="wc-pill">
          <div class="wc-pill-num"><?= $prods_act ?></div>
          <div class="wc-pill-lbl">Productos</div>
        </div>
      </div>
    </div>

    <div class="clima-box">
      <div>
        <div class="clima-top">
          <span class="clima-ico" id="cico">⏳</span>
          <div>
            <div class="clima-temp" id="ctemp">--°C</div>
            <div class="clima-desc" id="cdesc">Cargando...</div>
          </div>
        </div>
<div class="clima-city" id="ccity" onclick="abrirModalCiudad()" style="cursor:pointer">
  Florencia, Caquetá <i class="bi bi-chevron-down"></i>
</div>      </div>
      <div class="clima-bottom">
        <span class="clima-upd" id="cupd">—</span>
        <button class="btn-ref" id="bref" onclick="getClima(true)">
          <i class="bi bi-arrow-clockwise" id="bico"></i> Actualizar
        </button>
      </div>
    </div>
  </div>

  <div class="grid">

    <div class="card">
      <div class="ch">
        <div class="ch-left">
          <span class="ch-ico ico-nar"><i class="bi bi-currency-dollar"></i></span>
          <span class="ch-title">Ventas del día</span>
        </div>
        <?php if ($diff_v !== null): ?>
        <span class="badge <?= $diff_v >= 0 ? 'b-ok' : 'b-bad' ?>">
          <?= $diff_v >= 0 ? '+' : '' ?><?= $diff_v ?>% vs ayer
        </span>
        <?php else: ?>
        <span class="badge b-neu">Sin histórico</span>
        <?php endif; ?>
      </div>
      <div class="bign nar">$<?= number_format($ventas_hoy, 0, ',', '.') ?></div>
      <div class="sublbl"><?= $num_ventas ?> venta<?= $num_ventas != 1 ? 's' : '' ?> registrada<?= $num_ventas != 1 ? 's' : '' ?> hoy</div>
      <?php if (!empty($top_ventas)): ?>
      <table class="mt">
        <thead><tr><th>Producto</th><th>Und.</th><th>Total</th></tr></thead>
        <tbody>
          <?php foreach ($top_ventas as $tv): ?>
          <tr>
            <td title="<?= htmlspecialchars($tv['nombre']) ?>"><?= htmlspecialchars($tv['nombre']) ?></td>
            <td><?= $tv['u'] ?></td>
            <td>$<?= number_format($tv['t'], 0, ',', '.') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
      <div class="empty"><i class="bi bi-bag-x"></i>Sin ventas registradas hoy</div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="ch">
        <div class="ch-left">
          <span class="ch-ico ico-red"><i class="bi bi-exclamation-triangle-fill"></i></span>
          <span class="ch-title">Stock bajo</span>
        </div>
        <span class="badge <?= $num_alertas > 0 ? 'b-bad' : 'b-ok' ?>">
          <?= $num_alertas > 0 ? $num_alertas . ' insumo' . ($num_alertas != 1 ? 's' : '') : '✓ Todo OK' ?>
        </span>
      </div>
      <?php if (empty($alertas)): ?>
      <div class="empty">
        <i class="bi bi-check-circle-fill" style="color:#4caf50;opacity:1"></i>
        Inventario en orden
      </div>
      <?php else: ?>
        <?php foreach ($alertas as $a):
          $pct = $a['punto_reposicion'] > 0 ? min(100, round(($a['stock_actual'] / $a['punto_reposicion']) * 100)) : 0;
        ?>
        <div class="al-row">
          <span style="font-size:.9rem;flex-shrink:0">⚠️</span>
          <span class="al-name" title="<?= htmlspecialchars($a['nombre']) ?>"><?= htmlspecialchars($a['nombre']) ?></span>
          <div class="al-bar-w"><div class="al-bar-f" style="width:<?= $pct ?>%"></div></div>
          <span class="al-val"><?= formatoInteligente($a['stock_actual']) ?> <?= $a['unidad_medida'] ?></span>
        </div>
        <?php endforeach; ?>
        <div style="margin-top:.65rem;flex-shrink:0">
          <a href="<?= APP_URL ?>/modules/compras/index.php"
             style="font-size:.75rem;color:var(--c3);font-weight:700;text-decoration:none">
            <i class="bi bi-plus-circle"></i> Registrar compra
          </a>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="ch">
        <div class="ch-left">
          <span class="ch-ico ico-grn"><i class="bi bi-graph-up-arrow"></i></span>
          <span class="ch-title">Finanzas — <?= date('F') ?></span>
        </div>
      </div>
      <div class="fin3">
        <div class="fcard">
          <div class="fcard-lbl"><i class="bi bi-arrow-up-circle" style="color:#2e7d32"></i>Ingresos</div>
          <div class="fcard-val grn">$<?= number_format($ingresos_mes, 0, ',', '.') ?></div>
        </div>
        <div class="fcard">
          <div class="fcard-lbl"><i class="bi bi-arrow-down-circle" style="color:#c62828"></i>Compras</div>
          <div class="fcard-val red">$<?= number_format($compras_mes, 0, ',', '.') ?></div>
        </div>
        <div class="fcard">
          <div class="fcard-lbl"><i class="bi bi-calculator" style="color:var(--c3)"></i>Utilidad</div>
          <div class="fcard-val <?= $utilidad_mes >= 0 ? 'grn' : 'red' ?>">
            $<?= number_format(abs($utilidad_mes), 0, ',', '.') ?>
          </div>
        </div>
      </div>
      <div class="graf-lbl">Ventas últimos 7 días</div>
      <div class="graf-wrap" style="height:88px;flex:none">
        <?php foreach ($chart as $dc):
          $h = $chartMax > 0 ? max(4, round(($dc['v'] / $chartMax) * 100)) . '%' : '4%';
        ?>
        <div class="gc <?= $dc['hoy'] ? 'today' : '' ?>">
          <div class="gb" style="height:<?= $h ?>" title="$<?= number_format($dc['v'], 0, ',', '.') ?>"></div>
          <span class="gd"><?= substr($dc['lbl'], 0, 2) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <div class="ch">
        <div class="ch-left">
          <span class="ch-ico ico-nar"><i class="bi bi-fire"></i></span>
          <span class="ch-title">Últimas producciones</span>
        </div>
        <a href="<?= APP_URL ?>/modules/produccion/nueva_produccion.php"
           style="font-size:.73rem;color:var(--c3);font-weight:700;text-decoration:none">
          <i class="bi bi-plus-lg"></i> Nueva
        </a>
      </div>
      <?php if (empty($prods_recientes)): ?>
      <div class="empty"><i class="bi bi-inbox"></i>Sin producciones aún</div>
      <?php else: ?>
        <?php foreach ($prods_recientes as $pr): ?>
        <div class="pr-row">
          <div class="pr-dot"></div>
          <div style="flex:1;min-width:0">
            <div class="pr-name"><?= htmlspecialchars($pr['nombre']) ?></div>
            <div class="pr-det"><?= formatoInteligente($pr['cantidad_tandas']) ?> <?= $pr['unidad_produccion'] ?></div>
          </div>
          <div class="pr-time"><?= date('d/m H:i', strtotime($pr['fecha_produccion'])) ?></div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="ch">
        <div class="ch-left">
          <span class="ch-ico ico-caf"><i class="bi bi-lightning-fill"></i></span>
          <span class="ch-title">Acciones rápidas</span>
        </div>
      </div>
      <div class="ac-grid">
        <?php
        $acs = [
          ['/modules/produccion/nueva_produccion.php', 'bi-fire',           'Producción', 'var(--c3)', 'rgba(198,113,36,.08)'],
          ['/modules/ventas/nueva_venta.php',          'bi-bag-plus-fill',  'Nueva venta','#198754',   'rgba(25,135,84,.08)'],
          ['/modules/compras/index.php',               'bi-cart-plus-fill', 'Compra',     '#0d6efd',   'rgba(13,110,253,.08)'],
          ['/modules/inventario/index.php',            'bi-box-seam-fill',  'Inventario', 'var(--c1)', 'rgba(148,91,53,.08)'],
          ['/modules/ventas/clientes.php',             'bi-shop',           'Tiendas',   '#e91e63',   'rgba(233,30,99,.08)'],
          ['/modules/recetas/index.php',               'bi-journal-richtext','Recetas',   '#6f42c1',   'rgba(111,66,193,.08)'],
        ];
        foreach ($acs as [$url, $ico, $lbl, $col, $bg]): ?>
        <a href="<?= APP_URL . $url ?>" class="ac-btn"
           style="border-color:<?= $bg ?>;background:<?= $bg ?>">
          <i class="bi <?= $ico ?>" style="color:<?= $col ?>"></i>
          <?= $lbl ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>


    <!-- ── Ingredientes más usados hoy ── -->
    <div class="card">
      <div class="ch">
        <div class="ch-left">
          <span class="ch-ico ico-nar"><i class="bi bi-boxes"></i></span>
          <span class="ch-title">Ingredientes más usados hoy</span>
        </div>
        <a href="<?= APP_URL ?>/modules/finanzas/index.php"
           style="font-size:.73rem;color:var(--c3);font-weight:700;text-decoration:none">
          <i class="bi bi-graph-up"></i> Análisis
        </a>
      </div>
      <?php if (empty($consumo_hoy)): ?>
      <div class="empty"><i class="bi bi-box-seam"></i>Sin producción hoy</div>
      <?php else: ?>
        <?php foreach ($consumo_hoy as $c):
          $pct = $max_consumo_hoy > 0 ? max(4, round(($c['total'] / $max_consumo_hoy) * 100)) : 4;
        ?>
        <div class="al-row">
          <span class="al-name"><?= htmlspecialchars($c['nombre']) ?></span>
          <div class="al-bar-w">
            <div class="al-bar-f" style="width:<?= $pct ?>%;background:linear-gradient(90deg,var(--c3),var(--c5))"></div>
          </div>
          <span class="al-val" style="color:var(--c1)">
            <?= formatoInteligente((float)$c['total']) ?> <span style="font-weight:400;color:var(--ink3)"><?= $c['unidad_medida'] ?></span>
          </span>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </div>
</div>

<script>
(function tick() {
  document.getElementById('nc').textContent =
    new Date().toLocaleTimeString('es-CO', { hour:'2-digit', minute:'2-digit' });
  setTimeout(tick, 1000);
})();

(function() {
  const h = new Date().getHours();
  const d = new Date().toLocaleDateString('es-CO', { weekday:'long', day:'numeric', month:'long' });
  let g;

  if (h >= 5 && h < 12)       g = '☀️ Buenos días';
  else if (h >= 12 && h < 18) g = '🌤️ Buenas tardes';
  else                        g = '🌙 Buenas noches';

  document.getElementById('wg').textContent = g;
  document.getElementById('ws').textContent = d;
})();

const WMO = {
  0:['☀️','Despejado'],1:['🌤️','Parcial'],2:['⛅','Nubes'],
  3:['☁️','Nublado'],45:['🌫️','Niebla'],61:['🌧️','Lluvia'],
  80:['🌦️','Chubascos'],95:['⛈️','Tormenta']
};

document.getElementById('ccity').textContent = CIUDADES[ciudadActual] || '';

async function getClima(manual = false) {
  const btn = document.getElementById('bref');
  btn.classList.add('spin');

  const coords = ciudadActual.split(',');

  try {
    const r = await fetch(
      `https://api.open-meteo.com/v1/forecast?latitude=${coords[0]}&longitude=${coords[1]}&current_weather=true&timezone=America%2FBogota`
    );

    const data = await r.json();
    const cw = data.current_weather;
    const [icon, desc] = WMO[cw.weathercode] || ['🌡️','Variable'];

    document.getElementById('cico').textContent = icon;
    document.getElementById('ctemp').textContent = Math.round(cw.temperature) + '°C';
    document.getElementById('cdesc').textContent = desc;
    document.getElementById('cupd').textContent =
      'Act. ' + new Date().toLocaleTimeString('es-CO');

  } catch {
    document.getElementById('cdesc').textContent = 'Sin conexión';
  }

  btn.classList.remove('spin');
}


/* Callback desde modal ciudad (header.php) */
window.ciudadCambiada = function(coord, nombre) {
  ciudadActual = coord;
  document.getElementById('ccity').textContent = nombre;
  getClima(true);
};
getClima();
setInterval(getClima, 600000);

</script>
</body>
</html>
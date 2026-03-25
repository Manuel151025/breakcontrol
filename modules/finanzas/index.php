<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/sesion.php';
require_once __DIR__ . '/../../includes/funciones.php';

requerirPropietario();
$pdo  = getConexion();
$user = usuarioActual();

// ── Filtros ────────────────────────────────────────────────────────────────
$modo   = in_array($_GET['modo'] ?? 'mes', ['mes','semana','rango']) ? $_GET['modo'] : 'mes';
$anio   = (int)($_GET['anio']   ?? date('Y'));
$mes    = max(1, min(12, (int)($_GET['mes'] ?? date('m'))));
$semana = max(1, min(53, (int)($_GET['semana'] ?? date('W'))));
$desde  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'] ?? '') ? $_GET['desde'] : date('Y-m-01');
$hasta  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta'] ?? '') ? $_GET['hasta'] : date('Y-m-d');

if ($modo === 'mes') {
    $desde          = "$anio-" . str_pad($mes, 2, '0', STR_PAD_LEFT) . "-01";
    $hasta          = date('Y-m-t', strtotime($desde));
    $titulo_periodo = date('F Y', strtotime($desde));
} elseif ($modo === 'semana') {
    $dto = new DateTime();
    $dto->setISODate($anio, $semana, 1);
    $desde = $dto->format('Y-m-d');
    $dto->modify('+6 days');
    $hasta          = $dto->format('Y-m-d');
    $titulo_periodo = "Sem. $semana · " . date('d/m', strtotime($desde)) . " – " . date('d/m/Y', strtotime($hasta));
} else {
    $titulo_periodo = date('d/m/Y', strtotime($desde)) . " – " . date('d/m/Y', strtotime($hasta));
}

// ── Totales del período ────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_venta),0) FROM venta WHERE DATE(fecha_hora) BETWEEN :d AND :h");
$stmt->execute([':d' => $desde, ':h' => $hasta]);
$ingresos = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_pagado),0) FROM compra WHERE DATE(fecha_compra) BETWEEN :d AND :h");
$stmt->execute([':d' => $desde, ':h' => $hasta]);
$compras = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(valor),0) FROM gasto WHERE DATE(fecha_gasto) BETWEEN :d AND :h");
$stmt->execute([':d' => $desde, ':h' => $hasta]);
$gastos_op = (float)$stmt->fetchColumn();

$utilidad_bruta = $ingresos - $compras;
$utilidad_neta  = $ingresos - $compras - $gastos_op;
$margen_bruto   = $ingresos > 0 ? round(($utilidad_bruta / $ingresos) * 100, 1) : 0;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM venta WHERE DATE(fecha_hora) BETWEEN :d AND :h");
$stmt->execute([':d' => $desde, ':h' => $hasta]);
$num_ventas = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM compra WHERE DATE(fecha_compra) BETWEEN :d AND :h");
$stmt->execute([':d' => $desde, ':h' => $hasta]);
$num_compras = (int)$stmt->fetchColumn();

// ── Ventas y compras por día (gráfico) ────────────────────────────────────
$stmt = $pdo->prepare("SELECT DATE(fecha_hora) AS dia, SUM(total_venta) AS total FROM venta WHERE DATE(fecha_hora) BETWEEN :d AND :h GROUP BY DATE(fecha_hora)");
$stmt->execute([':d' => $desde, ':h' => $hasta]);
$ventas_dia = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$stmt = $pdo->prepare("SELECT DATE(fecha_compra) AS dia, SUM(total_pagado) AS total FROM compra WHERE DATE(fecha_compra) BETWEEN :d AND :h GROUP BY DATE(fecha_compra)");
$stmt->execute([':d' => $desde, ':h' => $hasta]);
$compras_dia = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$dias_chart = [];
$cur = strtotime($desde);
$fin = strtotime($hasta);
while ($cur <= $fin) {
    $f = date('Y-m-d', $cur);
    $dias_chart[] = [
        'f'   => $f,
        'lbl' => date('d/m', $cur),
        'v'   => (float)($ventas_dia[$f]  ?? 0),
        'c'   => (float)($compras_dia[$f] ?? 0),
    ];
    $cur = strtotime('+1 day', $cur);
}
$chart_max = max(array_merge(array_column($dias_chart, 'v'), array_column($dias_chart, 'c'), [1]));

// ── Top productos vendidos ─────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT p.nombre, SUM(v.unidades_vendidas) AS unidades, SUM(v.total_venta) AS total
    FROM venta v INNER JOIN producto p ON p.id_producto = v.id_producto
    WHERE DATE(v.fecha_hora) BETWEEN :d AND :h
    GROUP BY v.id_producto ORDER BY total DESC LIMIT 6
");
$stmt->execute([':d' => $desde, ':h' => $hasta]);
$top_productos = $stmt->fetchAll();

// ── Top clientes ───────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT COALESCE(c.nombre,'Mostrador') AS cliente, c.tipo,
           SUM(v.total_venta) AS total, COUNT(*) AS transacciones
    FROM venta v LEFT JOIN cliente c ON c.id_cliente = v.id_cliente
    WHERE DATE(v.fecha_hora) BETWEEN :d AND :h
    GROUP BY v.id_cliente ORDER BY total DESC LIMIT 5
");
$stmt->execute([':d' => $desde, ':h' => $hasta]);
$top_clientes = $stmt->fetchAll();

// ── Top insumos comprados ──────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT i.nombre, SUM(c.cantidad) AS cantidad, SUM(c.total_pagado) AS total, i.unidad_medida
    FROM compra c INNER JOIN insumo i ON i.id_insumo = c.id_insumo
    WHERE DATE(c.fecha_compra) BETWEEN :d AND :h
    GROUP BY c.id_insumo ORDER BY total DESC LIMIT 5
");
$stmt->execute([':d' => $desde, ':h' => $hasta]);
$top_insumos = $stmt->fetchAll();

// ── Gastos por categoría ───────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT categoria, SUM(valor) AS total, COUNT(*) AS cantidad
    FROM gasto WHERE DATE(fecha_gasto) BETWEEN :d AND :h
    GROUP BY categoria ORDER BY total DESC
");
$stmt->execute([':d' => $desde, ':h' => $hasta]);
$gastos_cat = $stmt->fetchAll();

// ── Comparativo período anterior ───────────────────────────────────────────
$dias_periodo = max(1, (strtotime($hasta) - strtotime($desde)) / 86400 + 1);
$desde_ant    = date('Y-m-d', strtotime($desde) - $dias_periodo * 86400);
$hasta_ant    = date('Y-m-d', strtotime($desde) - 86400);

$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_venta),0) FROM venta WHERE DATE(fecha_hora) BETWEEN :d AND :h");
$stmt->execute([':d' => $desde_ant, ':h' => $hasta_ant]);
$ingresos_ant = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_pagado),0) FROM compra WHERE DATE(fecha_compra) BETWEEN :d AND :h");
$stmt->execute([':d' => $desde_ant, ':h' => $hasta_ant]);
$compras_ant  = (float)$stmt->fetchColumn();

$utilidad_ant  = $ingresos_ant - $compras_ant;
$diff_ingresos = $ingresos_ant  > 0 ? round((($ingresos       - $ingresos_ant)  / $ingresos_ant)  * 100, 1) : null;
$diff_compras  = $compras_ant   > 0 ? round((($compras        - $compras_ant)   / $compras_ant)   * 100, 1) : null;
$diff_utilidad = $utilidad_ant != 0  ? round((($utilidad_bruta - $utilidad_ant) / abs($utilidad_ant)) * 100, 1) : null;

// Años disponibles
$anios = $pdo->query("SELECT DISTINCT YEAR(fecha_hora) AS y FROM venta ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
if (empty($anios)) $anios = [date('Y')];

// Consumo de ingredientes en el período
$consumo_stmt = $pdo->prepare("
    SELECT i.nombre, i.unidad_medida,
           SUM(cl.cantidad_consumida) AS total_cant,
           SUM(cl.costo_consumo)      AS total_costo,
           GROUP_CONCAT(DISTINCT p.nombre ORDER BY p.nombre SEPARATOR ' · ') AS productos
    FROM consumo_lote cl
    INNER JOIN lote l        ON l.id_lote       = cl.id_lote
    INNER JOIN insumo i      ON i.id_insumo      = l.id_insumo
    INNER JOIN produccion pr ON pr.id_produccion = cl.id_produccion
    INNER JOIN producto p    ON p.id_producto    = pr.id_producto
    WHERE DATE(pr.fecha_produccion) BETWEEN :d AND :h
    GROUP BY i.id_insumo ORDER BY total_costo DESC LIMIT 6
");
$consumo_stmt->execute([':d' => $desde, ':h' => $hasta]);
$consumo_ingredientes = $consumo_stmt->fetchAll();

// Fallback estimado si consumo_lote está vacío
$es_estimado_consumo = false;
if (empty($consumo_ingredientes)) {
    $est_stmt = $pdo->prepare("
        SELECT i.nombre, i.unidad_medida,
               SUM(ri.cantidad * pr.cantidad_tandas) AS total_cant,
               0 AS total_costo,
               GROUP_CONCAT(DISTINCT p.nombre ORDER BY p.nombre SEPARATOR ' · ') AS productos
        FROM produccion pr
        INNER JOIN receta_ingrediente ri ON ri.id_receta = pr.id_receta
        INNER JOIN insumo i  ON i.id_insumo  = ri.id_insumo
        INNER JOIN producto p ON p.id_producto = pr.id_producto
        WHERE DATE(pr.fecha_produccion) BETWEEN :d AND :h
        GROUP BY i.id_insumo ORDER BY total_cant DESC LIMIT 6
    ");
    $est_stmt->execute([':d' => $desde, ':h' => $hasta]);
    $consumo_ingredientes = $est_stmt->fetchAll();
    $es_estimado_consumo = !empty($consumo_ingredientes);
}

$costo_prod_total = array_sum(array_column($consumo_ingredientes, 'total_costo'));
$max_ing_costo    = !empty($consumo_ingredientes) ? max(array_column($consumo_ingredientes, 'total_costo') ?: [0]) : 1;
$max_ing_cant     = !empty($consumo_ingredientes) ? max(array_column($consumo_ingredientes, 'total_cant'))  : 1;

$page_title = 'Finanzas';
require_once __DIR__ . '/../../views/layouts/header.php';
?>
<style>
  :root{--c1:#945b35;--c2:#c8956e;--c3:#c67124;--c4:#e4a565;--c5:#ecc198;--cbg:#faf3ea;--ccard:#fff;--clight:#fdf6ee;--ink:#281508;--ink2:#6b3d1e;--ink3:#b87a4a;--border:rgba(148,91,53,.12);--shadow:0 1px 8px rgba(148,91,53,.09);--shadow2:0 4px 20px rgba(148,91,53,.15);--nav-h:64px;}
  @keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
  @keyframes gradAnim{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}

  /* ── PAGE layout idéntico al resto de módulos ── */
  .page{margin-top:var(--nav-h);height:calc(100vh - var(--nav-h));overflow:hidden;display:grid;grid-template-rows:auto auto 1fr;gap:.7rem;padding:.75rem;}

  /* ── BANNER ── */
  .wc-banner{background:linear-gradient(125deg,#6b3211 0%,#945b35 18%,#c67124 35%,#e4a565 50%,#c67124 65%,#945b35 80%,#6b3211 100%);background-size:300% 300%;animation:gradAnim 8s ease infinite;border-radius:14px;padding:.9rem 1.4rem;display:flex;align-items:center;justify-content:space-between;box-shadow:var(--shadow2);gap:1rem;flex-wrap:wrap;}
  .wc-left{display:flex;align-items:center;gap:.9rem;}
  .wc-greeting{font-size:.65rem;text-transform:uppercase;letter-spacing:.2em;color:rgba(255,255,255,.65);margin-bottom:.15rem;}
  .wc-name{font-family:'Fraunces',serif;font-size:1.35rem;font-weight:800;color:#fff;line-height:1.1;}
  .wc-name em{font-style:italic;color:var(--c5);}
  .wc-sub{font-size:.72rem;color:rgba(255,255,255,.62);margin-top:.15rem;}
  .wc-pills{display:flex;gap:.55rem;flex-wrap:wrap;}
  .wc-pill{background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.2);border-radius:10px;padding:.5rem .85rem;text-align:center;min-width:68px;}
  .wc-pill-num{font-family:'Fraunces',serif;font-size:1rem;font-weight:800;color:#fff;}
  .wc-pill-lbl{font-size:.54rem;text-transform:uppercase;letter-spacing:.12em;color:rgba(255,255,255,.58);}
  .wc-pill.ok{background:rgba(200,255,220,.2);border-color:rgba(200,255,220,.35);}
  .wc-pill.ok .wc-pill-num{color:#c8ffd8;}
  .wc-pill.alert{background:rgba(255,205,210,.25);border-color:rgba(255,205,210,.4);}
  .wc-pill.alert .wc-pill-num{color:#ffcdd2;}

  /* ── TOPBAR ── */
  .topbar{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;}
  .mod-titulo{font-family:'Fraunces',serif;font-size:1.45rem;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:.5rem;}
  .mod-titulo i{color:var(--c3);}
  .top-actions{display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end;}
  .fin-filtro-grupo{display:flex;flex-direction:column;gap:.18rem;}
  .fin-filtro-lbl{font-size:.59rem;font-weight:700;text-transform:uppercase;letter-spacing:.13em;color:var(--ink3);}
  .fin-select,.fin-date{font-size:.82rem;border:1px solid var(--border);border-radius:9px;padding:.42rem .65rem;color:var(--ink);background:var(--clight);font-family:inherit;cursor:pointer;}
  .fin-select:focus,.fin-date:focus{outline:none;border-color:var(--c3);box-shadow:0 0 0 3px rgba(198,113,36,.1);}
  .modo-btns{display:flex;gap:.3rem;}
  .btn-modo{padding:.42rem .8rem;border-radius:8px;font-size:.78rem;font-weight:700;border:1px solid var(--border);background:var(--clight);color:var(--ink2);cursor:pointer;transition:all .18s;font-family:inherit;}
  .btn-modo.activo,.btn-modo:hover{background:var(--c3);color:#fff;border-color:var(--c3);}
  .btn-ver{background:linear-gradient(135deg,var(--c3),var(--c1));color:#fff;border:none;border-radius:10px;padding:.45rem .95rem;font-size:.82rem;font-weight:700;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:.4rem;transition:all .2s;}
  .btn-ver:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(198,113,36,.35);}
  .btn-exportar{display:inline-flex;align-items:center;gap:.38rem;background:rgba(25,135,84,.08);border:1px solid rgba(25,135,84,.2);color:#198754;border-radius:9px;padding:.42rem .8rem;font-size:.78rem;font-weight:700;text-decoration:none;transition:all .18s;}
  .btn-exportar:hover{background:rgba(25,135,84,.18);}

  /* ── CUERPO: dos columnas como resto de módulos ── */
  .g-body{display:grid;grid-template-columns:1fr 280px;gap:.7rem;min-height:0;}
  .right-col{display:flex;flex-direction:column;gap:.7rem;min-height:0;}

  /* ── CARD base ── */
  .card{background:var(--ccard);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);display:flex;flex-direction:column;overflow:hidden;min-height:0;animation:fadeUp .45s ease both;}
  .card:nth-child(1){animation-delay:.25s}.card:nth-child(2){animation-delay:.30s}
  .ch{display:flex;align-items:center;justify-content:space-between;padding:.8rem 1.1rem;flex-shrink:0;border-bottom:1px solid var(--border);}
  .ch-left{display:flex;align-items:center;gap:.5rem;}
  .ch-ico{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1rem;}
  .ico-nar{background:rgba(198,113,36,.1);color:var(--c3);}
  .ico-grn{background:rgba(46,125,50,.1);color:#2e7d32;}
  .ico-red{background:rgba(198,40,40,.1);color:#c62828;}
  .ico-azul{background:rgba(21,101,192,.1);color:#1565c0;}
  .ch-title{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.17em;color:var(--ink3);}
  .badge{display:inline-flex;align-items:center;font-size:.62rem;font-weight:700;padding:.15rem .5rem;border-radius:20px;}
  .b-neu{background:var(--clight);color:var(--c1);border:1px solid var(--border);}

  /* KPIs 2×2 dentro de card izquierda */
  .kpi-grid{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:.5rem;padding:.7rem 1.1rem;border-bottom:1px solid var(--border);flex-shrink:0;}
  .kpi{background:var(--clight);border:1px solid var(--border);border-radius:10px;padding:.55rem .75rem;}
  .kpi-lbl{font-size:.56rem;text-transform:uppercase;letter-spacing:.15em;color:var(--ink3);margin-bottom:.2rem;display:flex;align-items:center;gap:.25rem;}
  .kpi-lbl i{font-size:.78rem;}
  .kpi-val{font-family:'Fraunces',serif;font-size:1.2rem;font-weight:800;line-height:1;color:var(--ink);}
  .kpi-val.grn{color:#2e7d32;} .kpi-val.red{color:#c62828;} .kpi-val.nar{color:var(--c3);} .kpi-val.azul{color:#1565c0;}
  .kpi-badge{margin-top:.25rem;font-size:.62rem;font-weight:600;}
  .tag-sube{color:#2e7d32;background:#e8f5e9;padding:.08rem .38rem;border-radius:20px;}
  .tag-baja{color:#c62828;background:#ffebee;padding:.08rem .38rem;border-radius:20px;}
  .tag-neu{color:var(--ink3);background:var(--clight);padding:.08rem .38rem;border-radius:20px;border:1px solid var(--border);}

  /* Gráfico */
  .grafico-zona{padding:.85rem 1.1rem;border-bottom:1px solid var(--border);flex-shrink:0;}
  .zona-titulo{font-size:.61rem;text-transform:uppercase;letter-spacing:.17em;color:var(--ink3);margin-bottom:.6rem;display:flex;align-items:center;gap:.4rem;}
  .zona-titulo i{font-size:.9rem;}
  .grafico-wrap{display:flex;align-items:flex-end;gap:3px;height:120px;padding-bottom:1.25rem;position:relative;}
  .gc-grupo{flex:1;display:flex;align-items:flex-end;gap:2px;position:relative;}
  .gc-barra{flex:1;border-radius:4px 4px 0 0;min-height:3px;transition:height .5s ease;cursor:pointer;position:relative;}
  .gc-barra:hover::after{content:attr(data-tip);position:absolute;bottom:105%;left:50%;transform:translateX(-50%);background:var(--ink);color:#fff;font-size:.6rem;padding:.18rem .42rem;border-radius:5px;white-space:nowrap;z-index:10;pointer-events:none;}
  .gc-barra.ingreso{background:linear-gradient(to top,#2e7d32,#81c784);}
  .gc-barra.gasto{background:linear-gradient(to top,#c62828,#ef9a9a);}
  .gc-lbl{position:absolute;bottom:-1.15rem;left:50%;transform:translateX(-50%);font-size:.53rem;color:var(--ink3);white-space:nowrap;}
  .leyenda{display:flex;gap:.9rem;margin-top:.4rem;font-size:.7rem;color:var(--ink2);}
  .ley-item{display:flex;align-items:center;gap:.32rem;font-weight:600;}
  .ley-dot{width:10px;height:10px;border-radius:3px;}
  .ley-dot.v{background:#4caf50;} .ley-dot.c{background:#ef9a9a;}

  /* Scroll area con las listas */
  .card-left-scroll{overflow-y:auto;flex:1;min-height:0;}
  .lista-zona{padding:.7rem 1.1rem;border-bottom:1px solid var(--border);}
  .lista-zona:last-child{border-bottom:none;}
  .fila-item{display:flex;align-items:center;gap:.5rem;padding:.38rem 0;border-bottom:1px solid rgba(148,91,53,.05);}
  .fila-item:last-child{border-bottom:none;}
  .fi-nombre{font-size:.8rem;font-weight:600;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--ink);}
  .fi-sub{font-size:.68rem;color:var(--ink3);white-space:nowrap;}
  .barra-m-w{width:42px;height:5px;background:var(--clight);border-radius:3px;overflow:hidden;flex-shrink:0;}
  .barra-m-f{height:100%;border-radius:3px;background:linear-gradient(90deg,var(--c3),var(--c5));}
  .barra-m-f.roja{background:linear-gradient(90deg,#c62828,#ef9a9a);}
  .barra-m-f.verde{background:linear-gradient(90deg,#2e7d32,#81c784);}
  .fi-val{font-size:.78rem;font-weight:700;min-width:68px;text-align:right;white-space:nowrap;}

  /* Card derecha */
  .card-right-scroll{overflow-y:auto;flex:1;min-height:0;}
  .comp-zona{padding:.85rem 1.1rem;border-bottom:1px solid var(--border);}
  .comp-fila{padding:.52rem 0;border-bottom:1px solid rgba(148,91,53,.06);}
  .comp-fila:last-of-type{border-bottom:none;}
  .comp-lbl{font-size:.58rem;text-transform:uppercase;letter-spacing:.13em;color:var(--ink3);margin-bottom:.18rem;}
  .comp-nums{display:flex;align-items:baseline;gap:.52rem;flex-wrap:wrap;}
  .comp-actual{font-family:'Fraunces',serif;font-size:1rem;font-weight:800;color:var(--ink);}
  .comp-ant{font-size:.68rem;color:var(--ink3);}
  .comp-diff{font-size:.69rem;font-weight:700;margin-left:auto;}
  .info-box{background:var(--clight);border:1px solid var(--border);border-radius:9px;padding:.6rem .8rem;font-size:.72rem;color:var(--ink2);margin-top:.65rem;line-height:1.5;}
  .cat-zona{padding:.75rem 1.1rem;}
  .cat-fila{display:flex;align-items:center;gap:.52rem;padding:.42rem 0;border-bottom:1px solid rgba(148,91,53,.05);}
  .cat-fila:last-child{border-bottom:none;}
  .cat-ico{width:26px;height:26px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:.82rem;flex-shrink:0;}
  .cat-nombre{font-size:.8rem;font-weight:600;flex:1;color:var(--ink);}
  .cat-cnt{font-size:.68rem;color:var(--ink3);}
  .cat-val{font-size:.78rem;font-weight:700;}
  .util-neta{margin:.2rem .85rem .85rem;border-radius:10px;padding:.7rem .9rem;}

  /* Empty */
  .empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.45rem;padding:1.5rem 1rem;color:var(--ink3);font-size:.8rem;text-align:center;}
  .empty i{font-size:2rem;opacity:.3;}

  .card-ing{flex-shrink:0;}
  @media(max-width:1100px){.g-body{grid-template-columns:1fr;}}
  @media(max-width:768px){.page{height:auto;overflow:visible;margin-top:60px;}.kpi-grid{grid-template-columns:1fr 1fr;}}
</style>

<div class="page">

  <!-- ══ BANNER ══ -->
  <div class="wc-banner">
    <div class="wc-left">
      <div>
        <div class="wc-greeting">Panadería BreakControl</div>
        <div class="wc-name">Finanzas <em>& Resultados</em></div>
        <div class="wc-sub"><?= $titulo_periodo ?></div>
      </div>
    </div>
    <div class="wc-pills">
      <div class="wc-pill ok">
        <div class="wc-pill-num">$<?= number_format($ingresos / 1000, 1) ?>k</div>
        <div class="wc-pill-lbl">Ingresos</div>
      </div>
      <div class="wc-pill <?= $utilidad_bruta >= 0 ? 'ok' : 'alert' ?>">
        <div class="wc-pill-num">$<?= number_format(abs($utilidad_bruta) / 1000, 1) ?>k</div>
        <div class="wc-pill-lbl">Utilidad</div>
      </div>
      <div class="wc-pill">
        <div class="wc-pill-num"><?= $margen_bruto ?>%</div>
        <div class="wc-pill-lbl">Margen</div>
      </div>
      <div class="wc-pill">
        <div class="wc-pill-num"><?= $num_ventas ?></div>
        <div class="wc-pill-lbl">Ventas</div>
      </div>
    </div>
  </div>

  <!-- ══ TOPBAR ══ -->
  <div class="topbar">
    <div class="mod-titulo">
      <i class="bi bi-graph-up-arrow"></i> Finanzas
    </div>
    <div class="top-actions">
      <form method="GET" id="form-finanzas" style="display:flex;align-items:flex-end;gap:.5rem;flex-wrap:wrap;">
        <div class="fin-filtro-grupo">
          <span class="fin-filtro-lbl">Vista</span>
          <div class="modo-btns">
            <button type="button" onclick="cambiarModo('semana')" class="btn-modo <?= $modo==='semana'?'activo':'' ?>">Semana</button>
            <button type="button" onclick="cambiarModo('mes')"    class="btn-modo <?= $modo==='mes'   ?'activo':'' ?>">Mes</button>
            <button type="button" onclick="cambiarModo('rango')"  class="btn-modo <?= $modo==='rango' ?'activo':'' ?>">Rango</button>
          </div>
        </div>

        <?php if ($modo === 'mes'): ?>
        <div class="fin-filtro-grupo">
          <span class="fin-filtro-lbl">Mes</span>
          <select name="mes" class="fin-select">
            <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>" <?= $mes == $m ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="fin-filtro-grupo">
          <span class="fin-filtro-lbl">Año</span>
          <select name="anio" class="fin-select">
            <?php foreach ($anios as $a): ?>
            <option value="<?= $a ?>" <?= $anio == $a ? 'selected' : '' ?>><?= $a ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php elseif ($modo === 'semana'): ?>
        <div class="fin-filtro-grupo">
          <span class="fin-filtro-lbl">Semana</span>
          <select name="semana" class="fin-select">
            <?php for ($s = 1; $s <= 53; $s++): ?>
            <option value="<?= $s ?>" <?= $semana == $s ? 'selected' : '' ?>>Semana <?= $s ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="fin-filtro-grupo">
          <span class="fin-filtro-lbl">Año</span>
          <select name="anio" class="fin-select">
            <?php foreach ($anios as $a): ?>
            <option value="<?= $a ?>" <?= $anio == $a ? 'selected' : '' ?>><?= $a ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php else: ?>
        <div class="fin-filtro-grupo">
          <span class="fin-filtro-lbl">Desde</span>
          <input type="date" name="desde" value="<?= $desde ?>" class="fin-date">
        </div>
        <div class="fin-filtro-grupo">
          <span class="fin-filtro-lbl">Hasta</span>
          <input type="date" name="hasta" value="<?= $hasta ?>" class="fin-date">
        </div>
        <?php endif; ?>

        <input type="hidden" name="modo" id="hid-modo" value="<?= $modo ?>">
        <button type="submit" class="btn-ver"><i class="bi bi-search"></i> Ver</button>
      </form>

      <a href="<?= APP_URL ?>/modules/finanzas/exportar_pdf.php?desde=<?= $desde ?>&hasta=<?= $hasta ?>"
         class="btn-exportar" target="_blank">
        <i class="bi bi-file-earmark-pdf"></i> PDF
      </a>
    </div>
  </div>

  <!-- ══ CUERPO ══ -->
  <div class="g-body">

    <!-- ── CARD IZQUIERDA ── -->
    <div class="card">

      <!-- KPIs 2×2 -->
      <div class="kpi-grid">
        <div class="kpi">
          <div class="kpi-lbl"><i class="bi bi-arrow-up-circle-fill" style="color:#2e7d32"></i>Ingresos</div>
          <div class="kpi-val grn">$<?= number_format($ingresos, 0, ',', '.') ?></div>
          <div class="kpi-badge">
            <?php if ($diff_ingresos !== null): ?>
            <span class="<?= $diff_ingresos >= 0 ? 'tag-sube' : 'tag-baja' ?>"><?= $diff_ingresos >= 0 ? '+' : '' ?><?= $diff_ingresos ?>%</span>
            <span style="color:var(--ink3);margin-left:.25rem">vs ant.</span>
            <?php else: ?><span class="tag-neu"><?= $num_ventas ?> ventas</span><?php endif; ?>
          </div>
        </div>

        <div class="kpi">
          <div class="kpi-lbl"><i class="bi bi-arrow-down-circle-fill" style="color:#c62828"></i>Compras</div>
          <div class="kpi-val red">$<?= number_format($compras, 0, ',', '.') ?></div>
          <div class="kpi-badge">
            <?php if ($diff_compras !== null): ?>
            <span class="<?= $diff_compras <= 0 ? 'tag-sube' : 'tag-baja' ?>"><?= $diff_compras >= 0 ? '+' : '' ?><?= $diff_compras ?>%</span>
            <span style="color:var(--ink3);margin-left:.25rem">vs ant.</span>
            <?php else: ?><span class="tag-neu"><?= $num_compras ?> compras</span><?php endif; ?>
          </div>
        </div>

        <div class="kpi">
          <div class="kpi-lbl"><i class="bi bi-calculator" style="color:var(--c3)"></i>Utilidad bruta</div>
          <div class="kpi-val <?= $utilidad_bruta >= 0 ? 'grn' : 'red' ?>">$<?= number_format(abs($utilidad_bruta), 0, ',', '.') ?></div>
          <div class="kpi-badge">
            <?php if ($diff_utilidad !== null): ?>
            <span class="<?= $diff_utilidad >= 0 ? 'tag-sube' : 'tag-baja' ?>"><?= $diff_utilidad >= 0 ? '+' : '' ?><?= $diff_utilidad ?>%</span>
            <span style="color:var(--ink3);margin-left:.25rem">vs ant.</span>
            <?php else: ?><span class="tag-neu"><?= $utilidad_bruta >= 0 ? 'Positiva' : 'Negativa' ?></span><?php endif; ?>
          </div>
        </div>

        <div class="kpi">
          <div class="kpi-lbl"><i class="bi bi-percent" style="color:#1565c0"></i>Margen bruto</div>
          <div class="kpi-val <?= $margen_bruto >= 30 ? 'grn' : ($margen_bruto >= 10 ? 'nar' : 'red') ?>"><?= $margen_bruto ?>%</div>
          <div class="kpi-badge">
            <span class="tag-neu"><?= $margen_bruto >= 30 ? '✓ Saludable' : ($margen_bruto >= 10 ? '⚠ Ajustado' : '✗ Bajo') ?></span>
          </div>
        </div>
      </div>

      <!-- Gráfico -->
      <div class="grafico-zona">
        <div class="zona-titulo"><i class="bi bi-bar-chart-fill"></i>Ingresos vs Compras por día</div>
        <?php if (($ingresos + $compras) == 0): ?>
        <div class="empty" style="padding:.8rem"><i class="bi bi-bar-chart"></i>Sin datos en este período</div>
        <?php else: ?>
        <div class="grafico-wrap">
          <?php foreach ($dias_chart as $dc):
            $hv = $chart_max > 0 ? max(3, round(($dc['v'] / $chart_max) * 105)) : 3;
            $hc = $chart_max > 0 ? max(3, round(($dc['c'] / $chart_max) * 105)) : 3;
          ?>
          <div class="gc-grupo">
            <div class="gc-barra ingreso" style="height:<?= $hv ?>px"
                 data-tip="Ventas <?= $dc['lbl'] ?>: $<?= number_format($dc['v'],0,',','.') ?>"></div>
            <div class="gc-barra gasto"   style="height:<?= $hc ?>px"
                 data-tip="Compras <?= $dc['lbl'] ?>: $<?= number_format($dc['c'],0,',','.') ?>"></div>
            <span class="gc-lbl"><?= count($dias_chart) <= 16 ? $dc['lbl'] : substr($dc['lbl'],0,2) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="leyenda">
          <div class="ley-item"><span class="ley-dot v"></span>Ingresos</div>
          <div class="ley-item"><span class="ley-dot c"></span>Compras</div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Listas con scroll -->
      <div class="card-left-scroll">

        <!-- Top clientes -->
        <div class="lista-zona">
          <div class="zona-titulo"><i class="bi bi-people-fill"></i>Top clientes</div>
          <?php if (empty($top_clientes)): ?>
          <div class="empty" style="padding:.8rem"><i class="bi bi-people"></i>Sin ventas en el período</div>
          <?php else:
            $max_cli = max(array_column($top_clientes,'total') ?: [1]);
            foreach ($top_clientes as $cl):
              $pct = round(($cl['total'] / $max_cli) * 100);
          ?>
          <div class="fila-item">
            <span style="font-size:.88rem"><?= ($cl['tipo'] ?? '') === 'tienda' ? '🏪' : '🧑' ?></span>
            <span class="fi-nombre"><?= htmlspecialchars($cl['cliente']) ?></span>
            <span class="fi-sub"><?= $cl['transacciones'] ?> tx</span>
            <div class="barra-m-w"><div class="barra-m-f" style="width:<?= $pct ?>%"></div></div>
            <span class="fi-val">$<?= number_format($cl['total'],0,',','.') ?></span>
          </div>
          <?php endforeach; endif; ?>
        </div>

        <!-- Top insumos comprados -->
        <div class="lista-zona">
          <div class="zona-titulo"><i class="bi bi-cart-fill"></i>Principales compras</div>
          <?php if (empty($top_insumos)): ?>
          <div class="empty" style="padding:.8rem"><i class="bi bi-cart"></i>Sin compras en el período</div>
          <?php else:
            $max_i = max(array_column($top_insumos,'total') ?: [1]);
            foreach ($top_insumos as $ti):
              $pct = round(($ti['total'] / $max_i) * 100);
          ?>
          <div class="fila-item">
            <span class="fi-nombre"><?= htmlspecialchars($ti['nombre']) ?></span>
            <span class="fi-sub"><?= formatoInteligente($ti['cantidad']) ?> <?= $ti['unidad_medida'] ?></span>
            <div class="barra-m-w"><div class="barra-m-f roja" style="width:<?= $pct ?>%"></div></div>
            <span class="fi-val" style="color:#c62828">$<?= number_format($ti['total'],0,',','.') ?></span>
          </div>
          <?php endforeach; endif; ?>
        </div>

        <!-- ══ Consumo de ingredientes en producción ══ -->
        <div class="lista-zona">
          <div class="zona-titulo">
            <i class="bi bi-boxes"></i>Consumo de ingredientes en producción
            <?php if ($es_estimado_consumo): ?>
            <span style="font-size:.6rem;font-weight:400;color:var(--ink3);margin-left:.4rem">(estimado)</span>
            <?php elseif ($costo_prod_total > 0): ?>
            <span style="font-size:.6rem;font-weight:400;color:#2e7d32;margin-left:.4rem">(FIFO real)</span>
            <?php endif; ?>
          </div>

          <?php if (empty($consumo_ingredientes)): ?>
          <div class="empty" style="padding:.8rem"><i class="bi bi-boxes"></i>Sin producción en el período</div>

          <?php else: ?>
            <?php if ($costo_prod_total > 0): ?>
            <div style="background:var(--clight);border:1px solid var(--border);border-radius:9px;padding:.55rem .75rem;font-size:.72rem;color:var(--ink2);margin-bottom:.5rem;line-height:1.5;">
              💡 <strong><?= htmlspecialchars($consumo_ingredientes[0]['nombre']) ?></strong>
              es el insumo de mayor costo —
              <strong><?= round($consumo_ingredientes[0]['total_costo'] / $costo_prod_total * 100, 1) ?>%</strong>
              del total de producción ($<?= number_format($costo_prod_total, 0, ',', '.') ?>).
            </div>
            <?php endif; ?>

            <?php
            $use_cost = $costo_prod_total > 0;
            $max_bar  = $use_cost ? $max_ing_costo : $max_ing_cant;
            foreach ($consumo_ingredientes as $ci):
              $bar_val = $use_cost ? $ci['total_costo'] : $ci['total_cant'];
              $pct     = $max_bar > 0 ? max(4, round(($bar_val / $max_bar) * 100)) : 4;
              $color   = $use_cost ? 'roja' : '';
            ?>
            <div class="fila-item">
              <span class="fi-nombre" title="<?= htmlspecialchars($ci['nombre']) ?>">
                <?= htmlspecialchars($ci['nombre']) ?>
                <?php if (!empty($ci['productos'])): ?>
                <span class="fi-sub" style="display:block"><?= htmlspecialchars($ci['productos']) ?></span>
                <?php endif; ?>
              </span>
              <span class="fi-sub"><?= formatoInteligente((float)$ci['total_cant']) ?> <?= $ci['unidad_medida'] ?></span>
              <div class="barra-m-w" style="width:52px">
                <div class="barra-m-f <?= $color ?>" style="width:<?= $pct ?>%"></div>
              </div>
              <span class="fi-val" style="color:<?= $use_cost ? '#c62828' : 'var(--c1)' ?>">
                <?php if ($use_cost): ?>
                $<?= number_format($ci['total_costo'], 0, ',', '.') ?>
                <?php else: ?>
                <?= formatoInteligente((float)$ci['total_cant']) ?> <?= $ci['unidad_medida'] ?>
                <?php endif; ?>
              </span>
            </div>
            <?php endforeach; ?>

          <?php endif; ?>
        </div>

      </div><!-- /card-left-scroll -->
    </div><!-- /card izquierda -->

    <!-- ── COLUMNA DERECHA ── -->
    <div class="right-col">

    <!-- Card productos más vendidos -->
    <div class="card card-ing">
      <div class="ch">
        <div class="ch-left">
          <div class="ch-ico ico-grn"><i class="bi bi-box-seam-fill"></i></div>
          <span class="ch-title">Productos más vendidos</span>
        </div>
        <span class="badge b-neu" style="font-size:.58rem"><?= count($top_productos) ?> prod.</span>
      </div>
      <?php if (empty($top_productos)): ?>
      <div class="empty" style="padding:1rem"><i class="bi bi-box-seam"></i>Sin ventas</div>
      <?php else: ?>
      <div style="padding:.5rem .85rem;overflow-y:auto;max-height:280px">
        <?php
        $max_p = max(array_column($top_productos,'total') ?: [1]);
        foreach ($top_productos as $pp):
          $pct = round(($pp['total'] / $max_p) * 100);
        ?>
        <div class="fila-item">
          <span class="fi-nombre"><?= htmlspecialchars($pp['nombre']) ?></span>
          <span class="fi-sub"><?= $pp['unidades'] ?> und</span>
          <div class="barra-m-w" style="width:48px">
            <div class="barra-m-f verde" style="width:<?= $pct ?>%"></div>
          </div>
          <span class="fi-val" style="color:#2e7d32;min-width:55px">$<?= number_format($pp['total'],0,',','.') ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── CARD DERECHA: comparativo + gastos ── -->
    <div class="card">
      <div class="ch">
        <div class="ch-left">
          <div class="ch-ico ico-azul"><i class="bi bi-arrow-left-right"></i></div>
          <span class="ch-title">Comparativo</span>
        </div>
      </div>

      <div class="card-right-scroll">
        <!-- Comparativo vs período anterior -->
        <div class="comp-zona">
          <?php
          $filas_comp = [
            ['Ingresos',       $ingresos,       $ingresos_ant,  true,  'grn'],
            ['Compras',        $compras,        $compras_ant,   false, 'red'],
            ['Utilidad bruta', $utilidad_bruta, $utilidad_ant,  true,  $utilidad_bruta >= 0 ? 'grn' : 'red'],
          ];
          foreach ($filas_comp as [$lbl, $actual, $anterior, $mayor_mejor, $colorKey]):
            $diff = $anterior != 0 ? round((($actual - $anterior) / abs($anterior)) * 100, 1) : null;
            $sube = $actual >= $anterior;
            $bien = $mayor_mejor ? $sube : !$sube;
            $col  = $diff === null ? 'var(--ink3)' : ($bien ? '#2e7d32' : '#c62828');
            $ico  = $diff === null ? '' : ($sube ? '▲' : '▼');
            $colorVal = $colorKey === 'grn' ? '#2e7d32' : ($colorKey === 'red' ? '#c62828' : 'var(--ink)');
          ?>
          <div class="comp-fila">
            <div class="comp-lbl"><?= $lbl ?></div>
            <div class="comp-nums">
              <span class="comp-actual" style="color:<?= $colorVal ?>">$<?= number_format(abs($actual), 0, ',', '.') ?></span>
              <span class="comp-ant">ant: $<?= number_format(abs($anterior), 0, ',', '.') ?></span>
              <?php if ($diff !== null): ?>
              <span class="comp-diff" style="color:<?= $col ?>"><?= $ico ?> <?= abs($diff) ?>%</span>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>

          <div class="info-box">
            <strong>Período anterior:</strong><br>
            <?= date('d/m/Y', strtotime($desde_ant)) ?> — <?= date('d/m/Y', strtotime($hasta_ant)) ?>
          </div>
        </div>

        <!-- Gastos operativos -->
        <div class="ch" style="border-top:none;border-bottom:1px solid var(--border);padding:.75rem 1.1rem;">
          <div class="ch-left">
            <div class="ch-ico ico-red"><i class="bi bi-receipt-cutoff"></i></div>
            <span class="ch-title">Gastos operativos</span>
          </div>
          <?php if ($gastos_op > 0): ?>
          <span class="badge b-neu">$<?= number_format($gastos_op,0,',','.') ?></span>
          <?php endif; ?>
        </div>

        <div class="cat-zona">
          <?php if (empty($gastos_cat)): ?>
          <div class="empty" style="padding:1rem">
            <i class="bi bi-receipt"></i>Sin gastos en el período
            <a href="<?= APP_URL ?>/modules/gastos/index.php"
               style="font-size:.73rem;color:var(--c3);text-decoration:none;margin-top:.2rem;display:block">
              → Ir a Gastos
            </a>
          </div>
          <?php else:
            $cat_conf = [
              'compra'   => ['🛒', 'rgba(198,40,40,.1)',   '#c62828'],
              'servicio' => ['🔧', 'rgba(21,101,192,.1)',  '#1565c0'],
              'otro'     => ['📌', 'rgba(148,91,53,.1)',   'var(--c1)'],
            ];
            foreach ($gastos_cat as $gc):
              [$ic, $bg, $col] = $cat_conf[$gc['categoria']] ?? ['📌','rgba(148,91,53,.1)','var(--c1)'];
          ?>
          <div class="cat-fila">
            <div class="cat-ico" style="background:<?= $bg ?>"><?= $ic ?></div>
            <span class="cat-nombre"><?= ucfirst($gc['categoria']) ?></span>
            <span class="cat-cnt"><?= $gc['cantidad'] ?> reg.</span>
            <span class="cat-val" style="color:<?= $col ?>">$<?= number_format($gc['total'],0,',','.') ?></span>
          </div>
          <?php endforeach; endif; ?>
        </div>

        <!-- Utilidad neta si hay gastos -->
        <?php if ($gastos_op > 0): ?>
        <div class="util-neta" style="background:<?= $utilidad_neta >= 0 ? '#f1f8f2' : '#fef2f2' ?>;border:1px solid <?= $utilidad_neta >= 0 ? '#a5d6a7' : '#ef9a9a' ?>;">
          <div style="font-size:.58rem;text-transform:uppercase;letter-spacing:.15em;color:var(--ink3);margin-bottom:.28rem;">
            Utilidad neta
          </div>
          <div style="font-family:'Fraunces',serif;font-size:1.25rem;font-weight:800;color:<?= $utilidad_neta >= 0 ? '#2e7d32' : '#c62828' ?>">
            $<?= number_format(abs($utilidad_neta),0,',','.') ?>
          </div>
          <div style="font-size:.68rem;color:var(--ink3);margin-top:.22rem;">
            Ingresos − Compras − Gastos
          </div>
        </div>
        <?php endif; ?>

      </div><!-- /card-right-scroll -->
    </div><!-- /card derecha -->

    </div><!-- /right-col -->
  </div><!-- /g-body -->
</div><!-- /page -->

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>

<script>
function cambiarModo(m) {
  document.getElementById('hid-modo').value = m;
  document.getElementById('form-finanzas').submit();
}
</script>
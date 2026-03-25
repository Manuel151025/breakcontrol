<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/sesion.php';
require_once __DIR__ . '/../../includes/funciones.php';

requerirPropietario();
$pdo = getConexion();

$desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'] ?? '') ? $_GET['desde'] : date('Y-m-01');
$hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta'] ?? '') ? $_GET['hasta'] : date('Y-m-d');
$user  = usuarioActual();

// ── Totales ──────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_venta),0) FROM venta WHERE DATE(fecha_hora) BETWEEN :d AND :h");
$stmt->execute([':d'=>$desde,':h'=>$hasta]);
$ingresos = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_pagado),0) FROM compra WHERE DATE(fecha_compra) BETWEEN :d AND :h");
$stmt->execute([':d'=>$desde,':h'=>$hasta]);
$compras_total = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(valor),0) FROM gasto WHERE DATE(fecha_gasto) BETWEEN :d AND :h");
$stmt->execute([':d'=>$desde,':h'=>$hasta]);
$gastos_op = (float)$stmt->fetchColumn();

$utilidad_bruta = $ingresos - $compras_total;
$utilidad_neta  = $ingresos - $compras_total - $gastos_op;
$margen_bruto   = $ingresos > 0 ? round(($utilidad_bruta/$ingresos)*100,1) : 0;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM venta WHERE DATE(fecha_hora) BETWEEN :d AND :h");
$stmt->execute([':d'=>$desde,':h'=>$hasta]);
$num_ventas = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM compra WHERE DATE(fecha_compra) BETWEEN :d AND :h");
$stmt->execute([':d'=>$desde,':h'=>$hasta]);
$num_compras = (int)$stmt->fetchColumn();

// ── Ventas por día para gráfico ───────────────────────────────────
$stmt = $pdo->prepare("SELECT DATE(fecha_hora) AS dia, SUM(total_venta) AS total FROM venta WHERE DATE(fecha_hora) BETWEEN :d AND :h GROUP BY DATE(fecha_hora) ORDER BY dia");
$stmt->execute([':d'=>$desde,':h'=>$hasta]);
$ventas_dia = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// ── Top productos ─────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT p.nombre, SUM(v.unidades_vendidas) AS u, SUM(v.total_venta) AS t
    FROM venta v INNER JOIN producto p ON p.id_producto=v.id_producto
    WHERE DATE(v.fecha_hora) BETWEEN :d AND :h
    GROUP BY v.id_producto ORDER BY t DESC LIMIT 6
");
$stmt->execute([':d'=>$desde,':h'=>$hasta]);
$top_prod = $stmt->fetchAll();

// ── Detalle compras ───────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT c.fecha_compra, i.nombre AS insumo, c.cantidad, i.unidad_medida,
           c.precio_unitario, c.total_pagado
    FROM compra c INNER JOIN insumo i ON i.id_insumo=c.id_insumo
    WHERE DATE(c.fecha_compra) BETWEEN :d AND :h
    ORDER BY c.fecha_compra ASC
");
$stmt->execute([':d'=>$desde,':h'=>$hasta]);
$detalle_compras = $stmt->fetchAll();

// ── Detalle ventas ────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT DATE(v.fecha_hora) AS fecha, TIME(v.fecha_hora) AS hora,
           COALESCE(c.nombre,'Mostrador') AS cliente, c.tipo,
           p.nombre AS producto, v.unidades_vendidas,
           COALESCE(v.unidades_bonificacion,0) AS bonificacion,
           v.precio_unitario, v.total_venta
    FROM venta v
    INNER JOIN producto p ON p.id_producto = v.id_producto
    LEFT  JOIN cliente  c ON c.id_cliente  = v.id_cliente
    WHERE DATE(v.fecha_hora) BETWEEN :d AND :h
    ORDER BY v.fecha_hora ASC
");
$stmt->execute([':d'=>$desde,':h'=>$hasta]);
$detalle_ventas = $stmt->fetchAll();

// ── Días del período para gráfico ────────────────────────────────
$dias_chart = [];
$cur = strtotime($desde);
while ($cur <= strtotime($hasta)) {
    $f = date('Y-m-d',$cur);
    $dias_chart[] = ['lbl'=>date('d/m',$cur),'v'=>(float)($ventas_dia[$f]??0),'f'=>$f];
    $cur = strtotime('+1 day',$cur);
}
$chart_max = max(array_column($dias_chart,'v')?:[1]);

// Ruta al logo relativa a este archivo
$logo_path = APP_URL . '/assets/img/logo.png';
$titulo_periodo = date('d \d\e F Y', strtotime($desde)) . ' — ' . date('d \d\e F Y', strtotime($hasta));
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Reporte Financiero — <?= date('d/m/Y',strtotime($desde)) ?> al <?= date('d/m/Y',strtotime($hasta)) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,wght@0,400;0,600;0,800;1,600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --c1:#6b3211; --c2:#945b35; --c3:#c67124;
      --c4:#e4a565; --c5:#ecc198; --cbg:#faf3ea;
      --ink:#281508; --ink2:#6b3d1e; --ink3:#b87a4a;
      --verde:#2e7d32; --rojo:#c62828; --azul:#1565c0;
    }
    * { margin:0; padding:0; box-sizing:border-box; }
    body {
      font-family:'Plus Jakarta Sans',sans-serif;
      background:#f0e8dc; color:var(--ink);
      font-size:10pt; min-height:100vh;
    }

    /* ══ WRAPPER PÁGINA ══ */
    .pagina {
      max-width:21cm; margin:0 auto;
      background:white;
      box-shadow:0 0 40px rgba(0,0,0,.18);
    }

    /* ══ HEADER ══ */
    .pdf-header {
      background:linear-gradient(125deg,#3d1a07 0%,#6b3211 20%,#945b35 40%,#c67124 62%,#e4a565 80%,#c67124 90%,#6b3211 100%);
      background-size:200% 200%;
      padding:0;
      position:relative;
      overflow:hidden;
    }
    .pdf-header::before {
      content:'';
      position:absolute; inset:0;
      background:repeating-linear-gradient(
        45deg,
        transparent, transparent 20px,
        rgba(255,255,255,.03) 20px, rgba(255,255,255,.03) 21px
      );
    }
    .hdr-inner {
      position:relative; z-index:1;
      padding:1cm 1.3cm .85cm;
      display:flex; align-items:center; justify-content:space-between; gap:.8cm;
    }
    .hdr-logo-area { display:flex; align-items:center; gap:.55cm; }
    .hdr-logo-img {
      width:1.4cm; height:1.4cm; border-radius:.3cm;
      background:rgba(255,255,255,.15);
      border:1.5px solid rgba(255,255,255,.3);
      overflow:hidden;
      display:flex; align-items:center; justify-content:center;
      flex-shrink:0;
    }
    .hdr-logo-img img {
      width:100%; height:100%; object-fit:contain;
      display:block;
    }
    .hdr-text-block {}
    .hdr-nombre {
      font-family:'Fraunces',serif;
      font-size:18pt; font-weight:800; color:white; line-height:1;
    }
    .hdr-sub {
      font-size:6.5pt; text-transform:uppercase;
      letter-spacing:.2em; color:rgba(255,255,255,.6);
      margin-top:3px;
    }
    .hdr-divider {
      width:1px; height:1cm;
      background:rgba(255,255,255,.25);
    }
    .hdr-right { text-align:right; }
    .hdr-reporte-lbl {
      font-size:6pt; text-transform:uppercase;
      letter-spacing:.25em; color:rgba(255,255,255,.55);
      margin-bottom:3px;
    }
    .hdr-reporte {
      font-family:'Fraunces',serif;
      font-size:14pt; font-weight:800;
      font-style:italic; color:white; line-height:1;
    }
    .hdr-periodo {
      font-size:8pt; color:rgba(255,255,255,.75);
      margin-top:5px; display:flex; align-items:center;
      justify-content:flex-end; gap:4px;
    }
    .hdr-gen {
      font-size:6.5pt; color:rgba(255,255,255,.45);
      margin-top:3px;
    }
    /* Franja inferior del header */
    .hdr-stripe {
      height:.22cm;
      background:linear-gradient(90deg,
        rgba(255,255,255,.08) 0%,
        rgba(255,255,255,.18) 30%,
        rgba(255,255,255,.08) 60%,
        rgba(255,255,255,.14) 100%
      );
    }

    /* ══ KPI BAND ══ */
    .kpi-band {
      display:grid; grid-template-columns:repeat(4,1fr);
      border-bottom:2px solid #f0e0cc;
    }
    .kpi-card {
      padding:.5cm .55cm .45cm;
      border-right:1px solid #f0e0cc;
      position:relative; overflow:hidden;
    }
    .kpi-card:last-child { border-right:none; }
    .kpi-card::before {
      content:'';
      position:absolute; left:0; top:0; bottom:0;
      width:3px;
    }
    .kpi-card.verde::before  { background:var(--verde); }
    .kpi-card.rojo::before   { background:var(--rojo); }
    .kpi-card.naranja::before{ background:var(--c3); }
    .kpi-card.azul::before   { background:var(--azul); }
    .kpi-lbl {
      font-size:5.5pt; text-transform:uppercase;
      letter-spacing:.16em; color:var(--ink3);
      margin-bottom:.2cm; font-weight:700;
    }
    .kpi-val {
      font-family:'Fraunces',serif;
      font-size:17pt; font-weight:800; line-height:1;
    }
    .kpi-card.verde   .kpi-val { color:var(--verde); }
    .kpi-card.rojo    .kpi-val { color:var(--rojo); }
    .kpi-card.naranja .kpi-val { color:var(--c3); }
    .kpi-card.azul    .kpi-val { color:var(--azul); }
    .kpi-sub {
      font-size:6.5pt; color:var(--ink3);
      margin-top:.18cm; display:flex; align-items:center; gap:3px;
    }
    .kpi-dot {
      width:5px; height:5px; border-radius:50%;
      flex-shrink:0; display:inline-block;
    }

    /* ══ CUERPO ══ */
    .pdf-body { padding:.65cm 1.1cm .9cm; }

    /* ══ SECCIONES ══ */
    .sec { margin-bottom:.6cm; page-break-inside:avoid; }
    .sec-hdr {
      display:flex; align-items:center; gap:.3cm;
      margin-bottom:.32cm;
    }
    .sec-icon {
      width:.55cm; height:.55cm; border-radius:.12cm;
      display:flex; align-items:center; justify-content:center;
      font-size:9pt; flex-shrink:0;
    }
    .si-naranja { background:rgba(198,113,36,.12); }
    .si-verde   { background:rgba(46,125,50,.1); }
    .si-azul    { background:rgba(21,101,192,.1); }
    .si-gris    { background:rgba(100,100,100,.08); }
    .sec-titulo {
      font-family:'Fraunces',serif;
      font-size:10.5pt; font-weight:800; color:var(--ink2);
    }
    .sec-linea { flex:1; height:1px; background:#ede0cc; }

    /* ══ GRÁFICO BARRAS ══ */
    .grafico-wrap {
      background:#fdf8f2;
      border:1px solid #ede0cc;
      border-radius:.25cm;
      padding:.35cm .4cm .15cm;
      position:relative;
    }
    .grafico-barras {
      display:flex; align-items:flex-end; gap:2px;
      height:1.8cm;
    }
    .gb-col { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; }
    .gb-bar {
      width:100%; border-radius:2px 2px 0 0; min-height:3px;
      background:linear-gradient(to top,var(--c2),var(--c4));
      transition:all .2s;
    }
    .gb-bar.hoy { background:linear-gradient(to top,var(--c3),#f0c070); }
    .gb-lbl { font-size:4.5pt; color:var(--ink3); margin-top:2px; white-space:nowrap; }
    .grafico-linea-cero {
      position:absolute; bottom:.37cm; left:.4cm; right:.4cm;
      height:1px; background:rgba(148,91,53,.15);
    }

    /* ══ DOS COLUMNAS ══ */
    .dos-col {
      display:grid; grid-template-columns:1fr 1fr;
      gap:.55cm; margin-bottom:.6cm;
    }

    /* ══ RANKING PRODUCTOS ══ */
    .rank-row {
      display:flex; align-items:center; gap:.2cm;
      padding:.18cm 0;
      border-bottom:1px solid rgba(148,91,53,.06);
    }
    .rank-row:last-child { border-bottom:none; }
    .rank-num {
      width:.42cm; height:.42cm; border-radius:50%;
      background:var(--c2); color:white;
      font-size:6pt; font-weight:800;
      display:flex; align-items:center; justify-content:center;
      flex-shrink:0;
    }
    .rank-num.top1 { background:linear-gradient(135deg,#b8860b,#ffd700); color:#3d1a07; }
    .rank-num.top2 { background:linear-gradient(135deg,#778899,#aab); color:white; }
    .rank-num.top3 { background:linear-gradient(135deg,#a0522d,#cd853f); color:white; }
    .rank-nombre { flex:1; font-size:8pt; font-weight:600; }
    .rank-barra-w {
      width:1.4cm; height:4px;
      background:#f0e0cc; border-radius:2px; overflow:hidden;
    }
    .rank-barra-f {
      height:100%; border-radius:2px;
      background:linear-gradient(90deg,var(--c3),var(--c5));
    }
    .rank-und { font-size:6.5pt; color:var(--ink3); min-width:.7cm; text-align:right; }
    .rank-val { font-size:8pt; font-weight:800; color:var(--c2); min-width:1.1cm; text-align:right; }

    /* ══ TABLAS ══ */
    .pdf-table { width:100%; border-collapse:collapse; font-size:8pt; }
    .pdf-table thead tr {
      background:linear-gradient(90deg,var(--c1),var(--c2));
    }
    .pdf-table thead th {
      padding:.2cm .28cm;
      font-size:6pt; text-transform:uppercase;
      letter-spacing:.12em; font-weight:700;
      color:rgba(255,255,255,.9); text-align:left;
    }
    .pdf-table thead th.r { text-align:right; }
    .pdf-table thead th.c { text-align:center; }
    .pdf-table tbody tr:nth-child(even) { background:#fdf6ee; }
    .pdf-table tbody td {
      padding:.18cm .28cm;
      border-bottom:1px solid rgba(148,91,53,.05);
      color:var(--ink);
    }
    .pdf-table tbody td.r { text-align:right; font-weight:600; }
    .pdf-table tbody td.c { text-align:center; }
    .pdf-table tfoot td {
      padding:.22cm .28cm;
      background:linear-gradient(90deg,var(--c1),var(--c2));
      color:white; font-weight:800; font-size:8.5pt;
    }
    .pdf-table tfoot td.r { text-align:right; }
    /* Zebra hover only on screen */
    .pdf-table tbody tr:hover { background:#f5e8d6; }

    /* Tags cliente */
    .tag { font-size:5.5pt; font-weight:700; padding:1px 4px; border-radius:3px; }
    .tag-tienda { background:#e3f2fd; color:#0d47a1; }
    .tag-mayor  { background:#e8f5e9; color:#1b5e20; }
    .tag-mostr  { background:#fdf3e7; color:#6b3d1e; }
    /* Bonificación */
    .bonif { font-size:5.5pt; color:var(--verde); font-weight:700; }

    /* ══ RESUMEN FINANCIERO FINAL ══ */
    .resumen-final {
      background:linear-gradient(135deg,#fdf8f2,#fff);
      border:1px solid #ede0cc;
      border-radius:.3cm;
      padding:.5cm .6cm;
      margin-bottom:.6cm;
      display:grid; grid-template-columns:repeat(3,1fr);
      gap:.4cm;
    }
    .rf-item { text-align:center; }
    .rf-lbl { font-size:6pt; text-transform:uppercase; letter-spacing:.15em; color:var(--ink3); margin-bottom:.15cm; }
    .rf-val { font-family:'Fraunces',serif; font-size:13pt; font-weight:800; line-height:1; }
    .rf-val.pos { color:var(--verde); }
    .rf-val.neg { color:var(--rojo); }
    .rf-val.neu { color:var(--c3); }
    .rf-sub { font-size:6.5pt; color:var(--ink3); margin-top:.12cm; }

    /* ══ PIE DE PÁGINA ══ */
    .pdf-footer {
      background:#fdf8f2;
      border-top:2px solid #ede0cc;
      padding:.45cm 1.1cm;
      display:flex; align-items:center; justify-content:space-between;
    }
    .footer-logo-area { display:flex; align-items:center; gap:.35cm; }
    .footer-logo-img {
      width:.8cm; height:.8cm; border-radius:.15cm;
      overflow:hidden; opacity:.75;
    }
    .footer-logo-img img { width:100%; height:100%; object-fit:contain; }
    .footer-marca {
      font-family:'Fraunces',serif;
      font-size:8.5pt; font-weight:800; color:var(--c2);
    }
    .footer-ciudad { font-size:6pt; color:var(--ink3); margin-top:1px; }
    .footer-info { font-size:6.5pt; color:var(--ink3); text-align:right; line-height:1.6; }
    .footer-page { font-size:6pt; color:var(--ink3); opacity:.6; margin-top:2px; }

    /* ══ BOTÓN PANTALLA ══ */
    .btn-imprimir {
      position:fixed; top:1rem; right:1rem;
      background:linear-gradient(135deg,var(--c1),var(--c3));
      color:white; border:none; border-radius:10px;
      padding:.55rem 1.15rem; font-size:.82rem; font-weight:700;
      cursor:pointer; box-shadow:0 4px 18px rgba(148,91,53,.45);
      display:flex; align-items:center; gap:.4rem;
      font-family:inherit; z-index:999; transition:all .2s;
    }
    .btn-imprimir:hover { transform:translateY(-2px); box-shadow:0 7px 24px rgba(148,91,53,.55); }

    /* ══ IMPRESIÓN ══ */
    @media print {
      body { background:white; }
      .pagina { box-shadow:none; max-width:none; }
      .btn-imprimir, .no-print { display:none !important; }
      @page { margin:0; size:A4; }
      * { -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; }
    }
  </style>
</head>
<body>

<button class="btn-imprimir no-print" onclick="window.print()">
  🖨️ Guardar como PDF
</button>

<div class="pagina">

  <!-- ══ HEADER ══ -->
  <div class="pdf-header">
    <div class="hdr-inner">
      <div class="hdr-logo-area">
        <div class="hdr-logo-img">
          <img src="<?= APP_URL ?>/assets/img/logo.png" alt="Logo Panadería">
        </div>
        <div class="hdr-text-block">
          <div class="hdr-nombre">BreakControl</div>
          <div class="hdr-sub">Sistema de gestión · Florencia, Caquetá</div>
        </div>
      </div>
      <div class="hdr-divider"></div>
      <div class="hdr-right">
        <div class="hdr-reporte-lbl">Documento</div>
        <div class="hdr-reporte">Reporte Financiero</div>
        <div class="hdr-periodo">
          📅 <?= date('d/m/Y',strtotime($desde)) ?> — <?= date('d/m/Y',strtotime($hasta)) ?>
        </div>
        <div class="hdr-gen">
          Generado el <?= date('d/m/Y H:i') ?> por <?= htmlspecialchars($user['nombre']) ?>
        </div>
      </div>
    </div>
    <div class="hdr-stripe"></div>
  </div>

  <!-- ══ KPI BAND ══ -->
  <div class="kpi-band">
    <div class="kpi-card verde">
      <div class="kpi-lbl">Ingresos totales</div>
      <div class="kpi-val">$<?= number_format($ingresos,0,',','.') ?></div>
      <div class="kpi-sub">
        <span class="kpi-dot" style="background:var(--verde)"></span>
        <?= $num_ventas ?> ventas registradas
      </div>
    </div>
    <div class="kpi-card rojo">
      <div class="kpi-lbl">Compras de insumos</div>
      <div class="kpi-val">$<?= number_format($compras_total,0,',','.') ?></div>
      <div class="kpi-sub">
        <span class="kpi-dot" style="background:var(--rojo)"></span>
        <?= $num_compras ?> compras realizadas
      </div>
    </div>
    <div class="kpi-card naranja">
      <div class="kpi-lbl">Utilidad bruta</div>
      <div class="kpi-val">$<?= number_format(abs($utilidad_bruta),0,',','.') ?></div>
      <div class="kpi-sub">
        <span class="kpi-dot" style="background:<?= $utilidad_bruta>=0?'var(--verde)':'var(--rojo)' ?>"></span>
        <?= $utilidad_bruta >= 0 ? 'Resultado positivo ✓' : 'Resultado negativo ✗' ?>
      </div>
    </div>
    <div class="kpi-card azul">
      <div class="kpi-lbl">Margen bruto</div>
      <div class="kpi-val"><?= $margen_bruto ?>%</div>
      <div class="kpi-sub">
        <span class="kpi-dot" style="background:<?= $margen_bruto>=30?'var(--verde)':($margen_bruto>=10?'#e65100':'var(--rojo)') ?>"></span>
        <?= $margen_bruto>=30?'Saludable ✓':($margen_bruto>=10?'Ajustado ⚠':'Bajo ✗') ?>
      </div>
    </div>
  </div>

  <!-- ══ CUERPO ══ -->
  <div class="pdf-body">

    <!-- Gráfico de ventas diarias -->
    <?php if (!empty($dias_chart) && count($dias_chart) > 1): ?>
    <div class="sec">
      <div class="sec-hdr">
        <div class="sec-icon si-naranja">📊</div>
        <span class="sec-titulo">Ventas diarias del período</span>
        <div class="sec-linea"></div>
      </div>
      <div class="grafico-wrap">
        <div class="grafico-linea-cero"></div>
        <div class="grafico-barras">
          <?php
          $hoy_str = date('Y-m-d');
          foreach ($dias_chart as $dc):
            $h = $chart_max>0 ? max(4, round(($dc['v']/$chart_max)*100)) : 4;
            $es_hoy = $dc['f'] === $hoy_str;
          ?>
          <div class="gb-col">
            <div class="gb-bar <?= $es_hoy?'hoy':'' ?>" style="height:<?=$h?>%"
                 title="<?= $dc['lbl'] ?>: $<?= number_format($dc['v'],0,',','.') ?>"></div>
            <?php if (count($dias_chart) <= 22): ?>
            <div class="gb-lbl" style="<?= $es_hoy?'font-weight:700;color:var(--c3)':'' ?>"><?= $dc['lbl'] ?></div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Top productos + Detalle compras en dos columnas -->
    <div class="dos-col">

      <!-- Top productos -->
      <div class="sec">
        <div class="sec-hdr">
          <div class="sec-icon si-naranja">🏆</div>
          <span class="sec-titulo">Productos más vendidos</span>
        </div>
        <?php if (empty($top_prod)): ?>
        <p style="color:var(--ink3);font-size:7.5pt;padding:.3cm 0">Sin ventas en este período.</p>
        <?php else:
          $max_p = max(array_column($top_prod,'t')?:[1]);
          $clases_rank = ['top1','top2','top3'];
          foreach ($top_prod as $i=>$pp):
            $pct = round(($pp['t']/$max_p)*100);
        ?>
        <div class="rank-row">
          <div class="rank-num <?= $clases_rank[$i] ?? '' ?>"><?= $i+1 ?></div>
          <div class="rank-nombre"><?= htmlspecialchars($pp['nombre']) ?></div>
          <div class="rank-barra-w"><div class="rank-barra-f" style="width:<?=$pct?>%"></div></div>
          <div class="rank-und"><?= $pp['u'] ?> und</div>
          <div class="rank-val">$<?= number_format($pp['t'],0,',','.') ?></div>
        </div>
        <?php endforeach; endif; ?>
      </div>

      <!-- Detalle compras condensado -->
      <div class="sec">
        <div class="sec-hdr">
          <div class="sec-icon si-gris">🛒</div>
          <span class="sec-titulo">Detalle de compras</span>
        </div>
        <?php if (empty($detalle_compras)): ?>
        <p style="color:var(--ink3);font-size:7.5pt;padding:.3cm 0">Sin compras en este período.</p>
        <?php else: ?>
        <table class="pdf-table">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Insumo</th>
              <th class="r">Total</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($detalle_compras as $c): ?>
            <tr>
              <td><?= date('d/m',strtotime($c['fecha_compra'])) ?></td>
              <td><?= htmlspecialchars(mb_substr($c['insumo'],0,20)) ?></td>
              <td class="r">$<?= number_format($c['total_pagado'],0,',','.') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="2">Total compras</td>
              <td class="r">$<?= number_format($compras_total,0,',','.') ?></td>
            </tr>
          </tfoot>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- Detalle de ventas completo -->
    <div class="sec">
      <div class="sec-hdr">
        <div class="sec-icon si-verde">📋</div>
        <span class="sec-titulo">Detalle de ventas</span>
        <div class="sec-linea"></div>
      </div>
      <?php if (empty($detalle_ventas)): ?>
      <p style="color:var(--ink3);font-size:7.5pt;padding:.3cm 0">Sin ventas en este período.</p>
      <?php else: ?>
      <table class="pdf-table">
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Hora</th>
            <th>Cliente</th>
            <th>Producto</th>
            <th class="c">Und.</th>
            <th class="r">Precio</th>
            <th class="r">Total</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($detalle_ventas as $v):
            $tipo = $v['tipo'] ?? '';
            $bonif = (int)($v['bonificacion'] ?? 0);
          ?>
          <tr>
            <td><?= date('d/m/Y',strtotime($v['fecha'])) ?></td>
            <td style="font-size:7.5pt;color:var(--ink3)"><?= date('h:i a',strtotime($v['hora'])) ?></td>
            <td>
              <?php if ($tipo === 'tienda'): ?>
                <span class="tag tag-tienda">Tienda</span>
              <?php elseif ($tipo === 'mayorista'): ?>
                <span class="tag tag-mayor">Mayor.</span>
              <?php else: ?>
                <span class="tag tag-mostr">Mostr.</span>
              <?php endif; ?>
              <?= htmlspecialchars(mb_substr($v['cliente'],0,13)) ?>
            </td>
            <td><?= htmlspecialchars(mb_substr($v['producto'],0,18)) ?></td>
            <td class="c">
              <?= $v['unidades_vendidas'] ?>
              <?php if ($bonif > 0): ?>
              <div class="bonif">+<?= $bonif ?>🏪</div>
              <?php endif; ?>
            </td>
            <td class="r">$<?= number_format($v['precio_unitario'],0,',','.') ?></td>
            <td class="r">$<?= number_format($v['total_venta'],0,',','.') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="6">Total ingresos del período</td>
            <td class="r">$<?= number_format($ingresos,0,',','.') ?></td>
          </tr>
        </tfoot>
      </table>
      <?php endif; ?>
    </div>

    <!-- Resumen financiero final -->
    <div class="resumen-final">
      <div class="rf-item">
        <div class="rf-lbl">Ingresos</div>
        <div class="rf-val pos">$<?= number_format($ingresos,0,',','.') ?></div>
        <div class="rf-sub"><?= $num_ventas ?> ventas · <?= count($dias_chart) ?> días</div>
      </div>
      <div class="rf-item">
        <div class="rf-lbl">Utilidad bruta</div>
        <div class="rf-val <?= $utilidad_bruta>=0?'pos':'neg' ?>">
          <?= $utilidad_bruta>=0?'+':'' ?>$<?= number_format(abs($utilidad_bruta),0,',','.') ?>
        </div>
        <div class="rf-sub">Margen <?= $margen_bruto ?>%</div>
      </div>
      <div class="rf-item">
        <div class="rf-lbl">Utilidad neta</div>
        <div class="rf-val <?= $utilidad_neta>=0?'pos':'neg' ?>">
          <?= $utilidad_neta>=0?'+':'' ?>$<?= number_format(abs($utilidad_neta),0,',','.') ?>
        </div>
        <div class="rf-sub">Incl. gastos operativos</div>
      </div>
    </div>

  </div><!-- /pdf-body -->

  <!-- ══ PIE DE PÁGINA ══ -->
  <div class="pdf-footer">
    <div class="footer-logo-area">
      <div class="footer-logo-img">
        <img src="<?= APP_URL ?>/assets/img/logo.png" alt="Logo">
      </div>
      <div>
        <div class="footer-marca">Sistema BreakControl</div>
        <div class="footer-ciudad">Florencia, Caquetá · Colombia</div>
      </div>
    </div>
    <div class="footer-info">
      Generado por <?= htmlspecialchars($user['nombre']) ?> · <?= date('d/m/Y H:i') ?><br>
      Período: <?= date('d/m/Y',strtotime($desde)) ?> — <?= date('d/m/Y',strtotime($hasta)) ?>
      <div class="footer-page">Sistema de Gestión BreakControl</div>
    </div>
  </div>

</div><!-- /pagina -->

<script>
window.addEventListener('load', function() {
  setTimeout(function() { window.print(); }, 700);
});
</script>
</body>
</html>
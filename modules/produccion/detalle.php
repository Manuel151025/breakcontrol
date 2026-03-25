<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/sesion.php';
require_once __DIR__ . '/../../includes/funciones.php';

requerirLogin();
$pdo           = getConexion();
$id_produccion = (int)($_GET['id'] ?? 0);

if (!$id_produccion) {
    redirigir(APP_URL . '/modules/produccion/index.php', 'error', 'Producción no encontrada.');
}

$stmt = $pdo->prepare("
    SELECT pr.*, p.nombre AS producto, p.unidad_produccion,
           u.nombre_completo AS usuario
    FROM produccion pr
    INNER JOIN producto p ON p.id_producto = pr.id_producto
    LEFT  JOIN usuario  u ON u.id_usuario  = pr.id_usuario
    WHERE pr.id_produccion = ?
");
$stmt->execute([$id_produccion]);
$produccion = $stmt->fetch();

if (!$produccion) {
    redirigir(APP_URL . '/modules/produccion/index.php', 'error', 'Producción no encontrada.');
}

$stmt2 = $pdo->prepare("
    SELECT cl.cantidad_consumida, cl.cantidad_con_merma, cl.costo_consumo,
           i.nombre AS insumo, i.unidad_medida,
           l.numero_lote, l.fecha_ingreso
    FROM consumo_lote cl
    INNER JOIN lote   l ON l.id_lote   = cl.id_lote
    INNER JOIN insumo i ON i.id_insumo = l.id_insumo
    WHERE cl.id_produccion = ?
    ORDER BY i.nombre ASC
");
$stmt2->execute([$id_produccion]);
$consumos = $stmt2->fetchAll();

$costo_total = array_sum(array_column($consumos, 'costo_consumo'));
$num_insumos = count(array_unique(array_column($consumos, 'insumo')));

$page_title = 'Detalle Producción';
require_once __DIR__ . '/../../views/layouts/header.php';
?>
<style>
  :root{--c1:#945b35;--c2:#c8956e;--c3:#c67124;--c4:#e4a565;--c5:#ecc198;--cbg:#faf3ea;--ccard:#fff;--clight:#fdf6ee;--ink:#281508;--ink2:#6b3d1e;--ink3:#b87a4a;--border:rgba(148,91,53,.12);--shadow:0 1px 8px rgba(148,91,53,.09);--shadow2:0 4px 20px rgba(148,91,53,.15);--nav-h:64px;}
  @keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
  @keyframes gradAnim{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}
  .page{margin-top:var(--nav-h);height:calc(100vh - var(--nav-h));overflow:hidden;display:grid;grid-template-rows:auto auto 1fr;gap:.7rem;padding:.75rem;}
  .wc-banner{background:linear-gradient(125deg,#6b3211 0%,#945b35 18%,#c67124 35%,#e4a565 50%,#c67124 65%,#945b35 80%,#6b3211 100%);background-size:300% 300%;animation:gradAnim 8s ease infinite;border-radius:14px;padding:.9rem 1.4rem;display:flex;align-items:center;justify-content:space-between;box-shadow:var(--shadow2);gap:1rem;flex-wrap:wrap;}
  .wc-left{display:flex;align-items:center;gap:.9rem;}
  .wc-greeting{font-size:.65rem;text-transform:uppercase;letter-spacing:.2em;color:rgba(255,255,255,.65);margin-bottom:.15rem;}
  .wc-name{font-family:'Fraunces',serif;font-size:1.35rem;font-weight:800;color:#fff;line-height:1.1;}
  .wc-name em{font-style:italic;color:var(--c5);}
  .wc-sub{font-size:.72rem;color:rgba(255,255,255,.62);margin-top:.15rem;}
  .wc-pills{display:flex;gap:.55rem;flex-wrap:wrap;}
  .wc-pill{background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.2);border-radius:10px;padding:.5rem .85rem;text-align:center;min-width:68px;}
  .wc-pill-num{font-family:'Fraunces',serif;font-size:1.35rem;font-weight:800;color:#fff;line-height:1;}
  .wc-pill-lbl{font-size:.54rem;text-transform:uppercase;letter-spacing:.12em;color:rgba(255,255,255,.58);}
  .wc-pill.ok{background:rgba(200,255,220,.2);border-color:rgba(200,255,220,.35);}
  .wc-pill.ok .wc-pill-num{color:#c8ffd8;}
  .topbar{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;}
  .mod-titulo{font-family:'Fraunces',serif;font-size:1.45rem;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:.5rem;}
  .mod-titulo i{color:var(--c3);}
  .btn-back{background:var(--ccard);color:var(--ink2);border:1px solid var(--border);border-radius:10px;padding:.45rem .9rem;font-size:.82rem;font-weight:600;display:inline-flex;align-items:center;gap:.4rem;text-decoration:none;transition:all .2s;}
  .btn-back:hover{background:var(--clight);border-color:var(--c3);color:var(--ink);}
  .g-body{display:grid;grid-template-columns:300px 1fr;gap:.7rem;min-height:0;}
  .card{background:var(--ccard);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);display:flex;flex-direction:column;overflow:hidden;min-height:0;animation:fadeUp .45s ease both;}
  .card:nth-child(1){animation-delay:.2s}.card:nth-child(2){animation-delay:.28s}
  .ch{display:flex;align-items:center;justify-content:space-between;padding:.8rem 1.1rem;flex-shrink:0;border-bottom:1px solid var(--border);}
  .ch-left{display:flex;align-items:center;gap:.5rem;}
  .ch-ico{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1rem;}
  .ico-nar{background:rgba(198,113,36,.1);color:var(--c3);}
  .ico-fire{background:rgba(229,57,53,.1);color:#e53935;}
  .ch-title{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.17em;color:var(--ink3);}
  .badge{display:inline-flex;align-items:center;font-size:.62rem;font-weight:700;padding:.15rem .5rem;border-radius:20px;}
  .b-neu{background:var(--clight);color:var(--c1);border:1px solid var(--border);}
  .resumen-body{padding:.9rem 1.1rem;overflow-y:auto;flex:1;}
  .dato-item{margin-bottom:.75rem;}
  .dato-lbl{font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.15em;color:var(--ink3);margin-bottom:.2rem;}
  .dato-val{font-size:.9rem;font-weight:700;color:var(--ink);}
  .dato-val.grande{font-family:'Fraunces',serif;font-size:1.5rem;color:var(--c3);}
  .dato-val.verde{color:#2e7d32;}
  .sep{height:1px;background:var(--border);margin:.75rem 0;}
  .obs-box{background:var(--clight);border:1px solid var(--border);border-radius:9px;padding:.55rem .75rem;font-size:.82rem;color:var(--ink2);font-style:italic;}
  .tbl-wrap{overflow-y:auto;overflow-x:auto;flex:1;min-height:0;}
  .gt{width:100%;border-collapse:collapse;}
  .gt th{font-size:.61rem;text-transform:uppercase;letter-spacing:.1em;color:var(--ink3);font-weight:700;padding:.5rem .85rem;background:var(--clight);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:1;white-space:nowrap;}
  .gt td{font-size:.81rem;color:var(--ink);padding:.5rem .85rem;border-bottom:1px solid rgba(148,91,53,.05);vertical-align:middle;}
  .gt tr:last-child td{border-bottom:none;}
  .gt tr:hover td{background:rgba(250,243,234,.5);}
  .gt tfoot td{background:var(--clight);padding:.55rem .85rem;font-weight:800;border-top:1.5px solid var(--border);}
  .lote-code{font-family:monospace;font-size:.78rem;background:var(--clight);border:1px solid var(--border);border-radius:5px;padding:.1rem .4rem;color:var(--ink2);}
  .merma-tag{font-size:.6rem;font-weight:700;padding:.1rem .38rem;border-radius:20px;background:rgba(255,152,0,.1);color:#e65100;border:1px solid rgba(255,152,0,.2);}
  .empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.5rem;padding:2.5rem 1rem;color:var(--ink3);font-size:.82rem;text-align:center;flex:1;}
  .empty i{font-size:2.2rem;opacity:.3;}
  @media(max-width:768px){.page{height:auto;overflow:visible;margin-top:60px;}.g-body{grid-template-columns:1fr;}}
</style>

<div class="page">
  <div class="wc-banner">
    <div class="wc-left">
      <div>
        <div class="wc-greeting">Panadería BreakControl</div>
        <div class="wc-name">Detalle de <em>Producción</em></div>
        <div class="wc-sub"><?= date('l, d \d\e F \d\e Y', strtotime($produccion['fecha_produccion'])) ?></div>
      </div>
    </div>
    <div class="wc-pills">
      <div class="wc-pill ok">
        <div class="wc-pill-num"><?= formatoInteligente($produccion['cantidad_tandas']) ?></div>
        <div class="wc-pill-lbl">Tandas</div>
      </div>
      <div class="wc-pill">
        <div class="wc-pill-num"><?= $num_insumos ?></div>
        <div class="wc-pill-lbl">Insumos</div>
      </div>
      <div class="wc-pill ok">
        <div class="wc-pill-num">$<?= number_format($costo_total / 1000, 1) ?>k</div>
        <div class="wc-pill-lbl">Costo</div>
      </div>
    </div>
  </div>

  <div class="topbar">
    <div class="mod-titulo"><i class="bi bi-clipboard-data-fill"></i> Detalle #<?= $id_produccion ?></div>
    <a href="index.php" class="btn-back"><i class="bi bi-arrow-left"></i> Volver</a>
  </div>

  <div class="g-body">
    <div class="card">
      <div class="ch">
        <div class="ch-left">
          <div class="ch-ico ico-nar"><i class="bi bi-info-circle-fill"></i></div>
          <span class="ch-title">Resumen</span>
        </div>
      </div>
      <div class="resumen-body">
        <div class="dato-item">
          <div class="dato-lbl">Producto</div>
          <div class="dato-val"><?= htmlspecialchars($produccion['producto']) ?></div>
          <div style="font-size:.72rem;color:var(--ink3);"><?= $produccion['unidad_produccion'] ?></div>
        </div>
        <div class="dato-item">
          <div class="dato-lbl">Tandas producidas</div>
          <div class="dato-val grande"><?= formatoInteligente($produccion['cantidad_tandas']) ?></div>
        </div>
        <div class="dato-item">
          <div class="dato-lbl">Fecha y hora</div>
          <div class="dato-val"><?= date('d/m/Y', strtotime($produccion['fecha_produccion'])) ?></div>
          <div style="font-size:.72rem;color:var(--ink3);"><?= date('H:i', strtotime($produccion['fecha_produccion'])) ?></div>
        </div>
        <div class="dato-item">
          <div class="dato-lbl">Registrado por</div>
          <div class="dato-val"><?= htmlspecialchars($produccion['usuario'] ?? '—') ?></div>
        </div>
        <div class="sep"></div>
        <div class="dato-item">
          <div class="dato-lbl">Costo total de insumos</div>
          <div class="dato-val verde">$<?= number_format($costo_total, 0, ',', '.') ?></div>
        </div>
        <div class="dato-item">
          <div class="dato-lbl">Insumos utilizados</div>
          <div class="dato-val"><?= $num_insumos ?> ingrediente<?= $num_insumos != 1 ? 's' : '' ?></div>
        </div>
        <?php if (!empty($produccion['observaciones'])): ?>
        <div class="sep"></div>
        <div class="dato-item">
          <div class="dato-lbl">Observaciones</div>
          <div class="obs-box"><?= htmlspecialchars($produccion['observaciones']) ?></div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="ch">
        <div class="ch-left">
          <div class="ch-ico ico-fire"><i class="bi bi-layers-fill"></i></div>
          <span class="ch-title">Ingredientes descontados (FIFO)</span>
        </div>
        <span class="badge b-neu"><?= count($consumos) ?> consumo<?= count($consumos) != 1 ? 's' : '' ?></span>
      </div>
      <?php if (empty($consumos)): ?>
      <div class="empty">
        <i class="bi bi-inbox"></i>
        <strong>Sin detalle de consumos</strong>
        <span>Esta producción fue registrada sin descuento de inventario</span>
      </div>
      <?php else: ?>
      <div class="tbl-wrap">
        <table class="gt">
          <thead>
            <tr>
              <th>Ingrediente</th>
              <th>Lote usado</th>
              <th>Ingreso del lote</th>
              <th style="text-align:right">Cantidad</th>
              <th style="text-align:right">Con merma</th>
              <th style="text-align:right">Costo</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($consumos as $c): ?>
          <tr>
            <td><strong><?= htmlspecialchars($c['insumo']) ?></strong></td>
            <td><span class="lote-code"><?= htmlspecialchars($c['numero_lote']) ?></span></td>
            <td style="color:var(--ink3);font-size:.76rem;"><?= date('d/m/Y', strtotime($c['fecha_ingreso'])) ?></td>
            <td style="text-align:right;">
              <?= formatoInteligente($c['cantidad_consumida']) ?>
              <span style="font-size:.72rem;color:var(--ink3)"><?= $c['unidad_medida'] ?></span>
            </td>
            <td style="text-align:right;">
              <?= formatoInteligente($c['cantidad_con_merma']) ?>
              <span style="font-size:.72rem;color:var(--ink3)"><?= $c['unidad_medida'] ?></span>
              <?php if (round($c['cantidad_con_merma'],4) != round($c['cantidad_consumida'],4)): ?>
              <span class="merma-tag">+merma</span>
              <?php endif; ?>
            </td>
            <td style="text-align:right;font-weight:700;color:var(--c3);">
              $<?= number_format($c['costo_consumo'], 0, ',', '.') ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="5" style="font-size:.75rem;color:var(--ink2);text-align:right;text-transform:uppercase;letter-spacing:.08em;">Costo total</td>
              <td style="font-family:'Fraunces',serif;font-size:1.05rem;color:#2e7d32;text-align:right;">$<?= number_format($costo_total, 0, ',', '.') ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>

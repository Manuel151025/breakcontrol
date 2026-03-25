<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/sesion.php';
require_once __DIR__ . '/../../includes/funciones.php';

requerirPropietario();
$pdo = getConexion();

// Acepta ?ids=1,2,3  o  ?id_compra=1 (botón individual)
if (!empty($_GET['ids'])) {
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $_GET['ids'])))));
    $ids = array_slice($ids, 0, 6);
} elseif (!empty($_GET['id_compra'])) {
    $ids = [(int)$_GET['id_compra']];
} else {
    header('Location: index.php'); exit;
}
if (empty($ids)) { header('Location: index.php'); exit; }

$ph = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("
    SELECT c.id_compra, c.cantidad, c.precio_unitario, c.total_pagado,
           c.fecha_compra, c.variacion_precio_pct,
           i.nombre AS insumo, i.unidad_medida, i.stock_actual, i.punto_reposicion,
           p.nombre AS proveedor,
           l.numero_lote, l.fecha_ingreso
    FROM compra c
    INNER JOIN insumo    i ON i.id_insumo    = c.id_insumo
    INNER JOIN proveedor p ON p.id_proveedor = c.id_proveedor
    LEFT  JOIN lote      l ON l.id_compra    = c.id_compra
    WHERE c.id_compra IN ($ph)
    ORDER BY FIELD(c.id_compra, $ph)
");
$stmt->execute(array_merge($ids, $ids));
$filas = $stmt->fetchAll();
if (empty($filas)) { header('Location: index.php'); exit; }

$etiquetas = [];
foreach ($filas as $d) {
    $stock = (float)$d['stock_actual'];
    $punto = (float)$d['punto_reposicion'];
    if ($stock <= $punto)            { $sem = 'crit'; $sem_lbl = 'Stock critico'; }
    elseif ($stock <= $punto * 1.5)  { $sem = 'mid';  $sem_lbl = 'Stock bajo'; }
    else                             { $sem = 'ok';   $sem_lbl = 'Stock normal'; }
    $var = (float)$d['variacion_precio_pct'];
    $etiquetas[] = [
        'insumo'    => htmlspecialchars($d['insumo']),
        'proveedor' => htmlspecialchars($d['proveedor']),
        'lote_num'  => htmlspecialchars($d['numero_lote'] ?? 'LOT-' . str_pad($d['id_compra'], 4, '0', STR_PAD_LEFT)),
        'fecha_fmt' => date('d/m/Y', strtotime($d['fecha_compra'])),
        'cantidad'  => formatoInteligente($d['cantidad']) . ' ' . $d['unidad_medida'],
        'unidad'    => $d['unidad_medida'],
        'precio'    => '$ ' . number_format($d['precio_unitario'], 0, ',', '.'),
        'total'     => '$ ' . number_format($d['total_pagado'], 0, ',', '.'),
        'sem'       => $sem,
        'sem_lbl'   => $sem_lbl,
        'var'       => $var,
        'var_lbl'   => $var == 0 ? '' : ($var > 0 ? "&#9650; {$var}%" : '&#9660; ' . abs($var) . '%'),
        'var_color' => $var > 0 ? '#c62828' : '#2e7d32',
        'seed'      => ($d['id_compra'] * 7) % 22,
    ];
}
$total_etq  = count($etiquetas);
$titulo_sub = $total_etq === 1
    ? $etiquetas[0]['insumo'] . ' &middot; Lote ' . $etiquetas[0]['lote_num']
    : $total_etq . ' compras seleccionadas';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Etiquetas de Lote &mdash; BreakControl</title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,wght@0,700;0,900;1,700&family=Plus+Jakarta+Sans:wght@400;600;700;800&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  :root{--c1:#945b35;--c3:#c67124;--c4:#e4a565;--c5:#ecc198;--cbg:#faf3ea;--ink:#281508;--ink2:#6b3d1e;--ink3:#b87a4a;--border:rgba(148,91,53,.15)}
  body{font-family:'Plus Jakarta Sans',sans-serif;background:#ede4d6;min-height:100vh;padding:1.5rem;color:var(--ink)}
  .action-bar{max-width:820px;margin:0 auto 1.5rem;background:rgba(255,255,255,.85);border:1px solid var(--border);border-radius:14px;padding:.85rem 1.2rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;box-shadow:0 2px 12px rgba(148,91,53,.1)}
  .ab-left{display:flex;align-items:center;gap:.75rem}
  .ab-ico{width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,var(--c1),var(--c3));display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0}
  .ab-title{font-family:'Fraunces',serif;font-size:1rem;font-weight:900;color:var(--ink)}
  .ab-sub{font-size:.7rem;color:var(--ink3);font-weight:600}
  .ab-actions{display:flex;gap:.5rem}
  .btn-ab{padding:.48rem 1rem;border-radius:9px;font-size:.8rem;font-weight:700;font-family:inherit;cursor:pointer;border:none;display:inline-flex;align-items:center;gap:.4rem;transition:all .2s;text-decoration:none}
  .btn-print{background:linear-gradient(135deg,var(--c3),var(--c1));color:#fff;box-shadow:0 3px 12px rgba(198,113,36,.3)}
  .btn-back{background:rgba(255,255,255,.9);color:var(--ink2);border:1px solid var(--border)}
  .sheet-label{max-width:820px;margin:0 auto .75rem;font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.18em;color:var(--ink3);display:flex;align-items:center;gap:.5rem}
  .sheet-label::after{content:'';flex:1;height:1px;background:var(--border)}
  .aviso-vacio{max-width:820px;margin:0 auto .6rem;background:rgba(198,113,36,.07);border:1px solid rgba(198,113,36,.2);border-radius:9px;padding:.45rem .9rem;font-size:.73rem;color:var(--ink3);display:flex;align-items:center;gap:.5rem}
  .a4-sheet{max-width:820px;margin:0 auto;background:#fff;border-radius:14px;box-shadow:0 8px 40px rgba(40,15,0,.18);padding:22px;aspect-ratio:210/297;display:grid;grid-template-columns:1fr 1fr;grid-template-rows:1fr 1fr 1fr;gap:16px;position:relative;overflow:hidden}
  .a4-sheet::before{content:'';position:absolute;inset:0;background:linear-gradient(to right,transparent calc(50% - .4px),rgba(148,91,53,.13) calc(50% - .4px),rgba(148,91,53,.13) calc(50% + .4px),transparent calc(50% + .4px)),linear-gradient(to bottom,transparent calc(33.33% - .4px),rgba(148,91,53,.13) calc(33.33% - .4px),rgba(148,91,53,.13) calc(33.33% + .4px),transparent calc(33.33% + .4px),transparent calc(66.66% - .4px),rgba(148,91,53,.13) calc(66.66% - .4px),rgba(148,91,53,.13) calc(66.66% + .4px),transparent calc(66.66% + .4px));pointer-events:none;z-index:0}
  .cut-corner{position:absolute;width:8px;height:8px;z-index:1;pointer-events:none}
  .cut-corner::before,.cut-corner::after{content:'';position:absolute;background:rgba(148,91,53,.28)}
  .cut-corner::before{width:1px;height:8px}.cut-corner::after{width:8px;height:1px;top:0}
  .cut-corner.tl{top:9px;left:9px}.cut-corner.tr{top:9px;right:9px}.cut-corner.tr::before{right:0;left:auto}.cut-corner.tr::after{right:0}
  .cut-corner.bl{bottom:9px;left:9px}.cut-corner.bl::before{bottom:0;top:auto}.cut-corner.bl::after{bottom:0;top:auto}
  .cut-corner.br{bottom:9px;right:9px}.cut-corner.br::before{right:0;left:auto;bottom:0;top:auto}.cut-corner.br::after{right:0;bottom:0;top:auto}
  .sticker{border-radius:8px;overflow:hidden;display:flex;flex-direction:column;position:relative;z-index:1;border:1px solid rgba(148,91,53,.1);box-shadow:0 1px 5px rgba(148,91,53,.1);animation:popIn .35s cubic-bezier(.34,1.36,.64,1) both}
  @keyframes popIn{from{opacity:0;transform:scale(.9)}to{opacity:1;transform:scale(1)}}
  .sticker.vacia{background:repeating-linear-gradient(45deg,#faf8f5,#faf8f5 4px,#f3ede4 4px,#f3ede4 8px);border:1px dashed rgba(148,91,53,.2);box-shadow:none;display:flex;align-items:center;justify-content:center}
  .sticker.vacia span{font-size:.6rem;color:rgba(148,91,53,.3);font-weight:700;text-transform:uppercase;letter-spacing:.12em}
  .stk-head{background:linear-gradient(115deg,#5c2d0e 0%,#8b4513 30%,#c67124 65%,#e4a565 100%);padding:.38rem .6rem;display:flex;align-items:center;justify-content:space-between;gap:.3rem;flex-shrink:0}
  .stk-brand{font-family:'Fraunces',serif;font-size:.52rem;font-weight:900;color:rgba(255,255,255,.85);letter-spacing:.07em;text-transform:uppercase;display:flex;align-items:center;gap:.25rem}
  .stk-brand-dot{width:4px;height:4px;border-radius:50%;background:rgba(255,255,255,.45)}
  .stk-lote{font-family:'JetBrains Mono',monospace;font-size:.5rem;font-weight:700;color:rgba(255,255,255,.8);background:rgba(0,0,0,.22);padding:.08rem .3rem;border-radius:3px;letter-spacing:.04em;flex-shrink:0}
  .stk-body{background:#fffbf5;flex:1;padding:.42rem .6rem;display:flex;flex-direction:column;gap:.2rem;position:relative}
  .stk-nombre{font-family:'Fraunces',serif;font-size:.82rem;font-weight:900;color:var(--ink);line-height:1.1;padding-right:1rem}
  .stk-proveedor{font-size:.5rem;color:var(--ink3);font-weight:600;text-transform:uppercase;letter-spacing:.09em}
  .stk-datos{display:grid;grid-template-columns:1fr 1fr;gap:.18rem .35rem;margin-top:.06rem}
  .stk-dato{display:flex;flex-direction:column;gap:.02rem}
  .stk-dato-lbl{font-size:.42rem;text-transform:uppercase;letter-spacing:.1em;color:var(--ink3);font-weight:700}
  .stk-dato-val{font-size:.62rem;font-weight:700;color:var(--ink)}
  .stk-dato-val.big{font-family:'Fraunces',serif;font-size:.75rem;color:var(--c1)}
  .stk-semaforo{position:absolute;top:.38rem;right:.6rem;width:6px;height:6px;border-radius:50%;box-shadow:0 0 0 2px rgba(255,255,255,.6)}
  .sem-ok{background:#4caf50}.sem-mid{background:#ff9800}.sem-crit{background:#f44336}
  .stk-var{font-size:.48rem;font-weight:700;padding:.06rem .3rem;border-radius:3px;display:inline-flex;align-items:center;align-self:flex-start;margin-top:.05rem}
  .stk-foot{background:linear-gradient(90deg,#fdf6ee,#faf0e0);border-top:1px dashed rgba(148,91,53,.18);padding:.22rem .6rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
  .stk-foot-txt{font-size:.44rem;color:var(--ink3);font-weight:600;text-transform:uppercase;letter-spacing:.09em}
  .barcode{display:flex;align-items:flex-end;gap:1px;height:14px}
  .barcode-bar{background:var(--ink2);border-radius:1px}
  .sheet-footer{max-width:820px;margin:1.25rem auto 0;background:rgba(255,255,255,.75);border:1px solid var(--border);border-radius:11px;padding:.65rem 1rem;font-size:.73rem;color:var(--ink3);display:flex;align-items:center;gap:.55rem;flex-wrap:wrap}
  .tt-chip{display:inline-flex;align-items:center;gap:.28rem;background:var(--cbg);border:1px solid var(--border);border-radius:20px;padding:.12rem .55rem;font-size:.68rem;font-weight:700;color:var(--ink2)}
  .dot-chip{width:6px;height:6px;border-radius:50%;flex-shrink:0}
  @media print{
    body{background:#fff;padding:0}
    .action-bar,.sheet-label,.sheet-footer,.aviso-vacio{display:none!important}
    .a4-sheet{max-width:100%;margin:0;box-shadow:none;border-radius:0;width:210mm;height:297mm;padding:10mm;gap:5mm}
  }
</style>
</head>
<body>

<div class="action-bar">
  <div class="ab-left">
    <div class="ab-ico" style="padding:0;overflow:hidden;">
      <img src="<?= APP_URL ?>/assets/img/logo.png" alt="Logo"
           style="width:100%;height:100%;object-fit:contain;display:block;">
    </div>
    <div>
      <div class="ab-title">Etiquetas de lote</div>
      <div class="ab-sub"><?= $titulo_sub ?></div>
    </div>
  </div>
  <div class="ab-actions">
    <a href="index.php" class="btn-ab btn-back">&larr; Volver a Compras</a>
    <button class="btn-ab btn-print" onclick="window.print()">&#128438; Imprimir</button>
  </div>
</div>

<div class="sheet-label">
  Hoja A4 &mdash; <?= $total_etq ?> etiqueta(s)
  <?php if ($total_etq < 6): ?>&middot; <?= 6 - $total_etq ?> espacio(s) vac&iacute;o(s)<?php endif; ?>
</div>

<?php if ($total_etq < 6): ?>
<div class="aviso-vacio">
  Los espacios vac&iacute;os tienen trama. Al imprimir, rec&oacute;rtalos o gu&aacute;rdalos para otra vez.
</div>
<?php endif; ?>

<div class="a4-sheet">
  <div class="cut-corner tl"></div>
  <div class="cut-corner tr"></div>
  <div class="cut-corner bl"></div>
  <div class="cut-corner br"></div>

  <?php for ($n = 0; $n < 6; $n++):
    if (!isset($etiquetas[$n])): ?>
    <div class="sticker vacia"><span>espacio vac&iacute;o</span></div>
  <?php else: $e = $etiquetas[$n]; ?>
  <div class="sticker">
    <div class="stk-head">
      <div class="stk-brand">
        <img src="<?= APP_URL ?>/assets/img/logo.png" alt="Logo"
             style="width:14px;height:14px;object-fit:contain;border-radius:2px;background:rgba(255,255,255,.2);">
        <span>BreakControl</span><div class="stk-brand-dot"></div><span>Panader&iacute;a</span>
      </div>
      <div class="stk-lote"><?= $e['lote_num'] ?></div>
    </div>
    <div class="stk-body">
      <div class="stk-semaforo sem-<?= $e['sem'] ?>"></div>
      <div class="stk-nombre"><?= $e['insumo'] ?></div>
      <div class="stk-proveedor">&#128230; <?= $e['proveedor'] ?></div>
      <div class="stk-datos">
        <div class="stk-dato"><span class="stk-dato-lbl">Cantidad</span><span class="stk-dato-val big"><?= $e['cantidad'] ?></span></div>
        <div class="stk-dato"><span class="stk-dato-lbl">Ingreso</span><span class="stk-dato-val"><?= $e['fecha_fmt'] ?></span></div>
        <div class="stk-dato"><span class="stk-dato-lbl">Precio / <?= $e['unidad'] ?></span><span class="stk-dato-val"><?= $e['precio'] ?></span></div>
        <div class="stk-dato"><span class="stk-dato-lbl">Total pagado</span><span class="stk-dato-val"><?= $e['total'] ?></span></div>
      </div>
      <?php if ($e['var'] != 0): ?>
      <span class="stk-var" style="background:<?= $e['var'] > 0 ? 'rgba(198,40,40,.1)' : 'rgba(46,125,50,.1)' ?>;color:<?= $e['var_color'] ?>"><?= $e['var_lbl'] ?> vs compra anterior</span>
      <?php endif; ?>
    </div>
    <div class="stk-foot">
      <span class="stk-foot-txt"><?= $e['sem_lbl'] ?></span>
      <div class="barcode" id="bc<?= $n ?>"></div>
    </div>
  </div>
  <?php endif; endfor; ?>
</div>

<div class="sheet-footer">
  <span>Sem&aacute;foro:</span>
  <span class="tt-chip"><span class="dot-chip" style="background:#4caf50"></span>Normal</span>
  <span class="tt-chip"><span class="dot-chip" style="background:#ff9800"></span>Bajo</span>
  <span class="tt-chip"><span class="dot-chip" style="background:#f44336"></span>Cr&iacute;tico</span>
  <span style="opacity:.6;font-size:.68rem">&mdash; Las gu&iacute;as de corte desaparecen al imprimir</span>
</div>

<script>
const seeds = <?= json_encode(array_column($etiquetas, 'seed')) ?>;
const bars  = [14,8,12,16,6,14,10,14,8,12,16,6,10,14,8,16,12,6,14,10,8,12,16,10];
seeds.forEach((seed, idx) => {
  const el = document.getElementById('bc' + idx);
  if (!el) return;
  bars.forEach((h, i) => {
    const bar = document.createElement('div');
    bar.className = 'barcode-bar';
    bar.style.height = bars[(i + seed) % bars.length] + 'px';
    bar.style.width  = (i % 4 === 0) ? '2px' : '1.2px';
    el.appendChild(bar);
  });
});
</script>
</body>
</html>
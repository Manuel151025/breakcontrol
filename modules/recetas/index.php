<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/sesion.php';
require_once __DIR__ . '/../../includes/funciones.php';

requerirPropietario();
$pdo  = getConexion();
$user = usuarioActual();

// ── Desactivar producto ─────────────────────────────────────────
if (!empty($_GET['del'])) {
    try {
        $pdo->prepare("UPDATE producto SET activo=0 WHERE id_producto=?")->execute([(int)$_GET['del']]);
    } catch (Exception $e) { /* error silencioso */ }
    header('Location: index.php'); exit;
}

// ── Buscar ──────────────────────────────────────────────────────
$busca = trim($_GET['q'] ?? '');

$stmt = $pdo->prepare("
    SELECT p.*,
           r.id_receta,
           COUNT(DISTINCT ri.id_insumo) AS num_ingredientes
    FROM producto p
    LEFT JOIN receta r ON r.id_producto=p.id_producto AND r.es_vigente=1
    LEFT JOIN receta_ingrediente ri ON ri.id_receta=r.id_receta
    WHERE p.activo=1 " . ($busca ? "AND p.nombre LIKE ?" : "") . "
    GROUP BY p.id_producto
    ORDER BY p.nombre
");
$stmt->execute($busca ? ["%$busca%"] : []);
$productos = $stmt->fetchAll();

// KPIs
$total_productos = count($productos);
$con_receta      = count(array_filter($productos, fn($p) => $p['id_receta']));
$sin_receta      = $total_productos - $con_receta;
$precio_prom     = $total_productos > 0 ? array_sum(array_column($productos, 'precio_venta')) / $total_productos : 0;

// Mensaje flash
$msg_ok = '';
if (!empty($_GET['ok'])) $msg_ok = 'Receta guardada correctamente.';

$page_title = 'Recetas';
require_once __DIR__ . '/../../views/layouts/header.php';
?>
<style>
  :root{--c1:#945b35;--c2:#c8956e;--c3:#c67124;--c4:#e4a565;--c5:#ecc198;--cbg:#faf3ea;--ccard:#fff;--clight:#fdf6ee;--ink:#281508;--ink2:#6b3d1e;--ink3:#b87a4a;--border:rgba(148,91,53,.12);--shadow:0 1px 8px rgba(148,91,53,.09);--shadow2:0 4px 20px rgba(148,91,53,.15);--nav-h:64px;}
  @keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
  @keyframes gradAnim{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}
  .page{margin-top:var(--nav-h);height:calc(100vh - var(--nav-h));overflow:hidden;display:grid;grid-template-rows:auto auto 1fr;gap:.7rem;padding:.75rem;}
  /* BANNER */
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
  .wc-pill.warn{background:rgba(255,235,59,.18);border-color:rgba(255,235,59,.3);}
  .wc-pill.warn .wc-pill-num{color:#fff9c4;}
  /* TOPBAR */
  .topbar{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;}
  .mod-titulo{font-family:'Fraunces',serif;font-size:1.45rem;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:.5rem;}
  .mod-titulo i{color:var(--c3);}
  .top-actions{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;}
  .inp-search{border:1px solid var(--border);border-radius:9px;padding:.45rem .75rem;font-size:.82rem;font-family:inherit;color:var(--ink);background:var(--ccard);width:200px;}
  .inp-search:focus{outline:none;border-color:var(--c3);box-shadow:0 0 0 3px rgba(198,113,36,.1);}
  .btn-grad{background:linear-gradient(135deg,var(--c3),var(--c1));color:#fff;border:none;border-radius:10px;padding:.5rem 1rem;font-size:.82rem;font-weight:700;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px rgba(198,113,36,.3);display:inline-flex;align-items:center;gap:.4rem;text-decoration:none;transition:all .2s;}
  .btn-grad:hover{transform:translateY(-2px);color:#fff;box-shadow:0 6px 20px rgba(198,113,36,.4);}
  /* CARD CATÁLOGO */
  .card{background:var(--ccard);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);display:flex;flex-direction:column;overflow:hidden;min-height:0;animation:fadeUp .4s ease both;}
  .ch{display:flex;align-items:center;justify-content:space-between;padding:.8rem 1.1rem;flex-shrink:0;border-bottom:1px solid var(--border);}
  .ch-left{display:flex;align-items:center;gap:.5rem;}
  .ch-ico{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1rem;}
  .ico-pur{background:rgba(103,58,183,.1);color:#673ab7;}
  .ch-title{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.17em;color:var(--ink3);}
  .badge{display:inline-flex;align-items:center;font-size:.62rem;font-weight:700;padding:.15rem .5rem;border-radius:20px;}
  .b-neu{background:var(--clight);color:var(--c1);border:1px solid var(--border);}
  /* GRID PRODUCTOS */
  .prod-grid{overflow-y:auto;flex:1;padding:.85rem;display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:.7rem;align-content:start;}
  .prod-card{background:var(--clight);border:1.5px solid var(--border);border-radius:13px;padding:.9rem;transition:all .2s;position:relative;display:flex;flex-direction:column;gap:.3rem;animation:fadeUp .35s ease both;}
  .prod-card:hover{background:#fff;border-color:var(--c3);box-shadow:var(--shadow2);transform:translateY(-2px);}
  .prod-emoji{font-size:1.9rem;line-height:1;}
  .prod-nombre{font-family:'Fraunces',serif;font-size:1.05rem;font-weight:800;color:var(--ink);line-height:1.2;}
  .prod-meta{font-size:.68rem;color:var(--ink3);display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;}
  .prod-precio{font-family:'Fraunces',serif;font-size:1.2rem;font-weight:800;color:var(--c3);margin-top:.1rem;}
  .prod-tags{display:flex;gap:.35rem;flex-wrap:wrap;margin-top:.15rem;}
  .tag{font-size:.58rem;font-weight:700;padding:.12rem .42rem;border-radius:20px;}
  .tag-receta{background:rgba(103,58,183,.1);color:#673ab7;border:1px solid rgba(103,58,183,.2);}
  .tag-noreceta{background:rgba(255,152,0,.1);color:#e65100;border:1px solid rgba(255,152,0,.2);}
  .tag-ing{background:rgba(198,113,36,.1);color:var(--c3);border:1px solid rgba(198,113,36,.2);}
  .tag-cat{background:var(--ccard);color:var(--ink2);border:1px solid var(--border);}
  .prod-actions{display:flex;gap:.3rem;margin-top:auto;padding-top:.6rem;}
  .btn-act{flex:1;height:30px;border-radius:7px;border:1px solid;display:inline-flex;align-items:center;justify-content:center;font-size:.74rem;text-decoration:none;transition:all .2s;cursor:pointer;font-family:inherit;font-weight:600;gap:.2rem;background:transparent;}
  .btn-edit{background:rgba(25,118,210,.07);border-color:rgba(25,118,210,.2);color:#1565c0;}
  .btn-edit:hover{background:rgba(25,118,210,.15);}
  .btn-receta{background:rgba(103,58,183,.07);border-color:rgba(103,58,183,.2);color:#673ab7;}
  .btn-receta:hover{background:rgba(103,58,183,.15);}
  .btn-del{background:rgba(198,40,40,.07);border-color:rgba(198,40,40,.15);color:#c62828;}
  .btn-del:hover{background:rgba(198,40,40,.18);}
  .empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.6rem;padding:3rem 1rem;color:var(--ink3);font-size:.82rem;text-align:center;flex:1;}
  .empty i{font-size:2.5rem;opacity:.3;}
  .msg-ok{background:#e8f5e9;border:1px solid #a5d6a7;border-left:3px solid #2e7d32;border-radius:10px;padding:.6rem .9rem;font-size:.82rem;color:#1b5e20;font-weight:600;display:flex;align-items:center;gap:.4rem;margin:.1rem .85rem .5rem;}
  @media(max-width:768px){.page{height:auto;overflow:visible;margin-top:60px;}.inp-search{width:130px;}}
</style>

<div class="page">
  <!-- BANNER -->
  <div class="wc-banner">
    <div class="wc-left">
      <div>
        <div class="wc-greeting">Panadería BreakControl</div>
        <div class="wc-name">Módulo de <em>Recetas</em></div>
        <div class="wc-sub">Catálogo de productos y sus ingredientes</div>
      </div>
    </div>
    <div class="wc-pills">
      <div class="wc-pill ok"><div class="wc-pill-num"><?= $total_productos ?></div><div class="wc-pill-lbl">Productos</div></div>
      <div class="wc-pill ok"><div class="wc-pill-num"><?= $con_receta ?></div><div class="wc-pill-lbl">Con receta</div></div>
      <?php if ($sin_receta > 0): ?>
      <div class="wc-pill warn"><div class="wc-pill-num"><?= $sin_receta ?></div><div class="wc-pill-lbl">Sin receta</div></div>
      <?php endif; ?>
      <div class="wc-pill"><div class="wc-pill-num">$<?= number_format($precio_prom, 0, ',', '.') ?></div><div class="wc-pill-lbl">Precio prom.</div></div>
    </div>
  </div>

  <!-- TOPBAR -->
  <div class="topbar">
    <div class="mod-titulo"><i class="bi bi-journal-richtext"></i> Recetas</div>
    <div class="top-actions">
      <form method="get" style="display:flex;gap:.4rem;">
        <input type="text" name="q" class="inp-search" placeholder="Buscar producto…" value="<?= htmlspecialchars($busca) ?>">
      </form>
      <a href="crear_producto.php" class="btn-grad">
        <i class="bi bi-plus-lg"></i> Nuevo producto
      </a>
    </div>
  </div>

  <!-- CATÁLOGO -->
  <div class="card">
    <div class="ch">
      <div class="ch-left">
        <div class="ch-ico ico-pur"><i class="bi bi-grid-3x3-gap-fill"></i></div>
        <span class="ch-title">Catálogo de productos</span>
      </div>
      <span class="badge b-neu"><?= $total_productos ?> activos</span>
    </div>

    <?php if ($msg_ok): ?>
    <div class="msg-ok"><i class="bi bi-check-circle-fill"></i><?= $msg_ok ?></div>
    <?php endif; ?>

    <?php if (empty($productos)): ?>
    <div class="empty">
      <i class="bi bi-journal-x"></i>
      <strong>Sin productos</strong>
      <span>Crea el primer producto con el botón <em>Nuevo producto</em></span>
    </div>
    <?php else: ?>
    <div class="prod-grid">
      <?php
      $emojis   = ['🥐','🍞','🥖','🍩','🫓',];
      $cat_icon = ['sal'=>'🧂','dulce'=>'🍬','especial'=>'⭐'];
      foreach ($productos as $i => $p):
      ?>
      <div class="prod-card" style="animation-delay:<?= $i * 0.04 ?>s">
        <div class="prod-emoji"><?= $emojis[$i % count($emojis)] ?></div>
        <div class="prod-nombre"><?= htmlspecialchars($p['nombre']) ?></div>
        <div class="prod-meta">
          <span><?= $p['unidad_produccion'] ?></span>
          <?php if ($p['cantidad_por_tanda'] > 0): ?>
          <span>· <?= $p['cantidad_por_tanda'] ?> uds/tanda</span>
          <?php endif; ?>
        </div>
        <div class="prod-precio">$<?= number_format($p['precio_venta'], 0, ',', '.') ?></div>
        <div class="prod-tags">
          <span class="tag tag-cat"><?= ($cat_icon[$p['categoria']] ?? '•') . ' ' . ucfirst($p['categoria'] ?? '') ?></span>
          <?php if ($p['id_receta']): ?>
          <span class="tag tag-receta"><i class="bi bi-check-circle"></i> Con receta</span>
          <span class="tag tag-ing"><i class="bi bi-list-ul"></i> <?= $p['num_ingredientes'] ?> ing.</span>
          <?php else: ?>
          <span class="tag tag-noreceta"><i class="bi bi-exclamation-circle"></i> Sin receta</span>
          <?php endif; ?>
        </div>
        <div class="prod-actions">
          <a href="editar_producto.php?id=<?= $p['id_producto'] ?>" class="btn-act btn-edit" title="Editar datos">
            <i class="bi bi-pencil"></i> Editar
          </a>
          <?php if ($p['id_receta']): ?>
          <a href="editar_receta.php?id=<?= $p['id_producto'] ?>" class="btn-act btn-receta" title="Ver/editar receta">
            <i class="bi bi-journal-plus"></i> Receta
          </a>
          <?php else: ?>
          <a href="editar_receta.php?id=<?= $p['id_producto'] ?>" class="btn-act btn-receta" title="Crear receta">
            <i class="bi bi-plus-circle"></i> Receta
          </a>
          <?php endif; ?>
          <a href="index.php?del=<?= $p['id_producto'] ?>" class="btn-act btn-del" title="Desactivar"
             onclick="return confirm('¿Desactivar «<?= htmlspecialchars($p['nombre']) ?>»?')">
            <i class="bi bi-trash3"></i>
          </a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
</body></html>

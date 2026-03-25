<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/sesion.php';
require_once __DIR__ . '/../../includes/funciones.php';

requerirPropietario();
$pdo  = getConexion();
$user = usuarioActual();

// index.php solo muestra historial — el registro va en nueva_produccion.php

// ── Filtro por fecha ────────────────────────────────────────────
$fecha_fil = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['fecha'] ?? '') ? $_GET['fecha'] : date('Y-m-d');

$stmt = $pdo->prepare("
    SELECT pr.*, p.nombre AS producto, p.unidad_produccion,
           u.nombre_completo AS operario
    FROM produccion pr
    INNER JOIN producto p ON p.id_producto=pr.id_producto
    LEFT  JOIN usuario  u ON u.id_usuario=pr.id_usuario
    WHERE DATE(pr.fecha_produccion)=?
    ORDER BY pr.fecha_produccion DESC
");
$stmt->execute([$fecha_fil]);
$producciones = $stmt->fetchAll();
$total_tandas = array_sum(array_column($producciones, 'cantidad_tandas'));

// ── KPIs ────────────────────────────────────────────────────────
$prod_hoy        = (float)$pdo->query("SELECT COALESCE(SUM(cantidad_tandas),0) FROM produccion WHERE DATE(fecha_produccion)=CURDATE()")->fetchColumn();
$prod_ayer       = (float)$pdo->query("SELECT COALESCE(SUM(cantidad_tandas),0) FROM produccion WHERE DATE(fecha_produccion)=DATE_SUB(CURDATE(),INTERVAL 1 DAY)")->fetchColumn();
$prod_mes        = (int)$pdo->query("SELECT COUNT(*) FROM produccion WHERE MONTH(fecha_produccion)=MONTH(CURDATE()) AND YEAR(fecha_produccion)=YEAR(CURDATE())")->fetchColumn();
$productos_activos = (int)$pdo->query("SELECT COUNT(*) FROM producto WHERE activo=1")->fetchColumn();

// ── Top productos del mes ───────────────────────────────────────
$top_productos = $pdo->query("
    SELECT p.nombre, SUM(pr.cantidad_tandas) AS tandas
    FROM produccion pr INNER JOIN producto p ON p.id_producto=pr.id_producto
    WHERE MONTH(pr.fecha_produccion)=MONTH(CURDATE()) AND YEAR(pr.fecha_produccion)=YEAR(CURDATE())
    GROUP BY pr.id_producto ORDER BY tandas DESC LIMIT 5
")->fetchAll();
$max_tandas = max(array_column($top_productos, 'tandas') ?: [1]);

// El formulario de nueva producción está en nueva_produccion.php

$page_title = 'Producción';
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
  /* TOPBAR */
  .topbar{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;}
  .mod-titulo{font-family:'Fraunces',serif;font-size:1.45rem;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:.5rem;}
  .mod-titulo i{color:var(--c3);}
  .fil-wrap{display:flex;align-items:center;gap:.5rem;}
  .fil-lbl{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--ink3);}
  .fil-date{border:1px solid var(--border);border-radius:9px;padding:.38rem .7rem;font-size:.82rem;font-family:inherit;color:var(--ink);background:var(--ccard);}
  .fil-date:focus{outline:none;border-color:var(--c3);box-shadow:0 0 0 3px rgba(198,113,36,.1);}
  .btn-grad{background:linear-gradient(135deg,var(--c3),var(--c1));color:#fff;border:none;border-radius:10px;padding:.45rem .9rem;font-size:.82rem;font-weight:700;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:.4rem;text-decoration:none;transition:all .2s;box-shadow:0 3px 10px rgba(198,113,36,.25);}
  .btn-grad:hover{transform:translateY(-1px);box-shadow:0 5px 16px rgba(198,113,36,.35);color:#fff;}
  /* CUERPO */
  .g-body{display:grid;grid-template-columns:300px 1fr;gap:.7rem;min-height:0;}
  .card{background:var(--ccard);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);display:flex;flex-direction:column;overflow:hidden;min-height:0;animation:fadeUp .45s ease both;}
  .card:nth-child(1){animation-delay:.25s}.card:nth-child(2){animation-delay:.3s}
  .ch{display:flex;align-items:center;justify-content:space-between;padding:.8rem 1.1rem;flex-shrink:0;border-bottom:1px solid var(--border);}
  .ch-left{display:flex;align-items:center;gap:.5rem;}
  .ch-ico{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1rem;}
  .ico-nar{background:rgba(198,113,36,.1);color:var(--c3);}
  .ico-fire{background:rgba(229,57,53,.1);color:#e53935;}
  .ch-title{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.17em;color:var(--ink3);}
  .badge{display:inline-flex;align-items:center;font-size:.62rem;font-weight:700;padding:.15rem .5rem;border-radius:20px;}
  .b-neu{background:var(--clight);color:var(--c1);border:1px solid var(--border);}
  /* FORM */
  .form-body{padding:.9rem 1.1rem;overflow-y:auto;flex:1;}
  .fl{margin-bottom:.65rem;}
  .fl label{font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:var(--ink3);display:block;margin-bottom:.3rem;}
  .fl input,.fl select,.fl textarea{width:100%;border:1px solid var(--border);border-radius:9px;padding:.45rem .75rem;font-size:.84rem;color:var(--ink);font-family:inherit;background:var(--clight);transition:border-color .2s,box-shadow .2s;}
  .fl input:focus,.fl select:focus,.fl textarea:focus{outline:none;border-color:var(--c3);box-shadow:0 0 0 3px rgba(198,113,36,.1);}
  .fl textarea{resize:vertical;min-height:55px;}
  .tandas-ctrl{display:flex;align-items:center;gap:.5rem;}
  .tandas-btn{width:36px;height:36px;border-radius:8px;border:1.5px solid var(--border);background:var(--clight);color:var(--ink2);font-size:1.2rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .18s;flex-shrink:0;}
  .tandas-btn:hover{background:var(--c3);color:#fff;border-color:var(--c3);}
  .tandas-inp{text-align:center!important;font-family:'Fraunces',serif!important;font-size:1.4rem!important;font-weight:800!important;width:70px!important;}
  .btn-guardar{width:100%;background:linear-gradient(135deg,var(--c3),var(--c1));color:#fff;border:none;border-radius:10px;padding:.65rem;font-size:.88rem;font-weight:700;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px rgba(198,113,36,.3);display:flex;align-items:center;justify-content:center;gap:.4rem;transition:all .2s;margin-top:.2rem;}
  .btn-guardar:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(198,113,36,.4);}
  .btn-nueva{display:flex;align-items:center;justify-content:center;gap:.4rem;width:100%;padding:.5rem;border:1.5px dashed var(--border);border-radius:10px;color:var(--ink3);font-size:.8rem;font-weight:600;text-decoration:none;transition:all .2s;margin-top:.5rem;}
  .btn-nueva:hover{border-color:var(--c3);color:var(--c3);background:rgba(198,113,36,.04);}
  /* TOP PRODUCTOS */
  .top-prod{border-top:1px solid var(--border);padding:.75rem 1.1rem;flex-shrink:0;}
  .top-title{font-size:.61rem;text-transform:uppercase;letter-spacing:.14em;color:var(--ink3);margin-bottom:.5rem;font-weight:700;}
  .top-row{display:flex;align-items:center;gap:.5rem;margin-bottom:.32rem;}
  .top-name{font-size:.75rem;font-weight:600;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--ink2);}
  .top-bar-w{width:50px;height:4px;background:var(--clight);border-radius:2px;overflow:hidden;flex-shrink:0;}
  .top-bar-f{height:100%;border-radius:2px;background:linear-gradient(90deg,var(--c3),var(--c4));}
  .top-val{font-size:.72rem;font-weight:700;color:var(--c1);min-width:40px;text-align:right;}
  /* TABLA */
  .tbl-wrap{overflow-y:auto;overflow-x:auto;flex:1;min-height:0;}
  .gt{width:100%;border-collapse:collapse;}
  .gt th{font-size:.61rem;text-transform:uppercase;letter-spacing:.1em;color:var(--ink3);font-weight:700;padding:.5rem .85rem;background:var(--clight);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:1;white-space:nowrap;}
  .gt td{font-size:.81rem;color:var(--ink);padding:.5rem .85rem;border-bottom:1px solid rgba(148,91,53,.05);vertical-align:middle;}
  .gt tr:last-child td{border-bottom:none;}
  .gt tr:hover td{background:rgba(250,243,234,.5);}
  .gt tfoot td{background:var(--clight);padding:.55rem .85rem;font-weight:800;border-top:1.5px solid var(--border);}
  .tanda-num{font-family:'Fraunces',serif;font-size:1.2rem;font-weight:800;color:var(--c1);}
  .empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.5rem;padding:2.5rem 1rem;color:var(--ink3);font-size:.82rem;text-align:center;flex:1;}
  .empty i{font-size:2.2rem;opacity:.3;}
  .btn-act{width:28px;height:28px;border-radius:7px;border:1px solid;display:inline-flex;align-items:center;justify-content:center;font-size:.8rem;text-decoration:none;transition:all .2s;cursor:pointer;background:transparent;}
  .btn-edit{border-color:rgba(25,118,210,.25);color:#1565c0;}.btn-edit:hover{background:rgba(25,118,210,.1);}
  .msg-ok{background:#e8f5e9;border:1px solid #a5d6a7;border-left:3px solid #2e7d32;border-radius:10px;padding:.6rem .9rem;font-size:.82rem;color:#1b5e20;font-weight:600;margin-bottom:.6rem;display:flex;align-items:center;gap:.4rem;}
  .msg-err{background:#ffebee;border:1px solid #ef9a9a;border-left:3px solid #c62828;border-radius:10px;padding:.6rem .9rem;font-size:.82rem;color:#c62828;margin-bottom:.6rem;display:flex;align-items:center;gap:.4rem;}
  @media(max-width:768px){
    .page{height:auto;overflow:visible;margin-top:60px;}
    .g-body{grid-template-columns:1fr;}
    .gt th:nth-child(4),.gt td:nth-child(4){display:none;}
  }
</style>

<div class="page">
  <!-- BANNER -->
  <div class="wc-banner">
    <div class="wc-left">
      <div>
        <div class="wc-greeting">Panadería BreakControl</div>
        <div class="wc-name">Control de <em>Producción</em></div>
        <div class="wc-sub">Registro de tandas diarias · <?= $productos_activos ?> productos activos</div>
      </div>
    </div>
    <div class="wc-pills">
      <div class="wc-pill <?= $prod_hoy > 0 ? 'ok' : '' ?>">
        <div class="wc-pill-num"><?= $prod_hoy ?></div>
        <div class="wc-pill-lbl">Tandas hoy</div>
      </div>
      <div class="wc-pill">
        <div class="wc-pill-num"><?= $prod_ayer ?></div>
        <div class="wc-pill-lbl">Ayer</div>
      </div>
      <div class="wc-pill">
        <div class="wc-pill-num"><?= $prod_mes ?></div>
        <div class="wc-pill-lbl">Registros mes</div>
      </div>
      <div class="wc-pill ok">
        <div class="wc-pill-num"><?= $productos_activos ?></div>
        <div class="wc-pill-lbl">Productos</div>
      </div>
    </div>
  </div>

  <!-- TOPBAR -->
  <div class="topbar">
    <div class="mod-titulo"><i class="bi bi-fire"></i> Producción</div>
    <div class="fil-wrap">
      <span class="fil-lbl">Fecha:</span>
      <form method="get">
        <input type="date" name="fecha" class="fil-date" value="<?= htmlspecialchars($fecha_fil) ?>" onchange="this.form.submit()">
      </form>
      <a href="nueva_produccion.php" class="btn-grad"><i class="bi bi-plus-lg"></i> Nueva con receta</a>
    </div>
  </div>

  <!-- CUERPO -->
  <div class="g-body">

    <!-- PANEL KPIs + TOP PRODUCTOS -->
    <div class="card">
      <div class="ch">
        <div class="ch-left">
          <div class="ch-ico ico-nar"><i class="bi bi-bar-chart-fill"></i></div>
          <span class="ch-title">Resumen del mes</span>
        </div>
      </div>
      <div class="form-body">
        <?php if (!empty($_SESSION['mensaje_texto'])): ?>
        <div class="msg-ok"><i class="bi bi-check-circle-fill"></i><?= mostrarMensaje() ?></div>
        <?php endif; ?>

        <!-- KPI Cards -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:.85rem;">
          <div style="background:var(--clight);border:1px solid var(--border);border-radius:10px;padding:.65rem .75rem;text-align:center;">
            <div style="font-family:'Fraunces',serif;font-size:1.6rem;font-weight:800;color:var(--c3);line-height:1"><?= $prod_hoy ?></div>
            <div style="font-size:.6rem;text-transform:uppercase;letter-spacing:.1em;color:var(--ink3);margin-top:.15rem;">Tandas hoy</div>
          </div>
          <div style="background:var(--clight);border:1px solid var(--border);border-radius:10px;padding:.65rem .75rem;text-align:center;">
            <div style="font-family:'Fraunces',serif;font-size:1.6rem;font-weight:800;color:var(--ink2);line-height:1"><?= $prod_ayer ?></div>
            <div style="font-size:.6rem;text-transform:uppercase;letter-spacing:.1em;color:var(--ink3);margin-top:.15rem;">Tandas ayer</div>
          </div>
          <div style="background:var(--clight);border:1px solid var(--border);border-radius:10px;padding:.65rem .75rem;text-align:center;">
            <div style="font-family:'Fraunces',serif;font-size:1.6rem;font-weight:800;color:var(--c1);line-height:1"><?= $prod_mes ?></div>
            <div style="font-size:.6rem;text-transform:uppercase;letter-spacing:.1em;color:var(--ink3);margin-top:.15rem;">Registros mes</div>
          </div>
          <div style="background:var(--clight);border:1px solid var(--border);border-radius:10px;padding:.65rem .75rem;text-align:center;">
            <div style="font-family:'Fraunces',serif;font-size:1.6rem;font-weight:800;color:#2e7d32;line-height:1"><?= $productos_activos ?></div>
            <div style="font-size:.6rem;text-transform:uppercase;letter-spacing:.1em;color:var(--ink3);margin-top:.15rem;">Productos</div>
          </div>
        </div>

        <?php if (!empty($top_productos)): ?>
        <div class="top-prod" style="border-top:1px solid var(--border);padding-top:.75rem;">
          <div class="top-title">Top productos del mes</div>
          <?php foreach ($top_productos as $tp): ?>
          <div class="top-row">
            <span class="top-name"><?= htmlspecialchars($tp['nombre']) ?></span>
            <div class="top-bar-w"><div class="top-bar-f" style="width:<?= round($tp['tandas']/$max_tandas*100) ?>%"></div></div>
            <span class="top-val"><?= $tp['tandas'] ?> t.</span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- TABLA DEL DÍA -->
    <div class="card">
      <div class="ch">
        <div class="ch-left">
          <div class="ch-ico ico-fire"><i class="bi bi-clipboard-data"></i></div>
          <span class="ch-title">Producción del <?= date('d/m/Y', strtotime($fecha_fil)) ?></span>
        </div>
        <span class="badge b-neu"><?= count($producciones) ?> registros</span>
      </div>
      <div class="tbl-wrap">
        <?php if (empty($producciones)): ?>
        <div class="empty">
          <i class="bi bi-fire"></i>
          <strong>Sin registros</strong>
          <span>No hay producción para esta fecha</span>
        </div>
        <?php else: ?>
        <table class="gt">
          <thead>
            <tr>
              <th>#</th>
              <th>Producto</th>
              <th>Tandas</th>
              <th>Operario</th>
              <th>Hora</th>
              <th>Notas</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($producciones as $pr): ?>
          <tr>
            <td><span style="font-family:'Fraunces',serif;font-weight:700;color:var(--ink3);"><?= $pr['id_produccion'] ?></span></td>
            <td>
              <strong><?= htmlspecialchars($pr['producto']) ?></strong><br>
              <span style="font-size:.7rem;color:var(--ink3);"><?= $pr['unidad_produccion'] ?></span>
            </td>
            <td><span class="tanda-num"><?= (int)$pr['cantidad_tandas'] ?></span></td>
            <td><?= htmlspecialchars($pr['operario'] ?? '—') ?></td>
            <td><?= date('H:i', strtotime($pr['fecha_produccion'])) ?></td>
            <td style="font-size:.77rem;color:var(--ink3);">
              <?= $pr['observaciones'] ? htmlspecialchars(substr($pr['observaciones'],0,50)).(strlen($pr['observaciones'])>50?'…':'') : '—' ?>
            </td>
            <td>
              <a href="detalle.php?id=<?= $pr['id_produccion'] ?>" class="btn-act btn-edit" title="Ver detalle"><i class="bi bi-eye"></i></a>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="2" style="font-size:.75rem;color:var(--ink2);text-align:right;text-transform:uppercase;letter-spacing:.08em;">Total tandas</td>
              <td style="font-family:'Fraunces',serif;font-size:1.1rem;color:var(--c3);"><?= (int)$total_tandas ?></td>
              <td colspan="3"></td>
            </tr>
          </tfoot>
        </table>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

</body></html>

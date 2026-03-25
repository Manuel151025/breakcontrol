<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/sesion.php';
require_once __DIR__ . '/../../includes/funciones.php';

requerirPropietario();
$pdo  = getConexion();
$user = usuarioActual();

$msg_ok  = '';
$msg_err = '';

// ══════════════════════════════════════════════════════════════
//  POST — Registrar venta
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_venta'])) {
    $id_prod    = (int)($_POST['id_producto']    ?? 0);
    $cantidad   = (int)($_POST['cantidad']        ?? 0);   // unidades que PAGA el cliente
    $id_cliente = (int)($_POST['id_cliente']      ?? 0);   // 0 = mostrador
    $precio_man = (float)($_POST['precio_manual'] ?? 0);
    $dar_napa   = isset($_POST['dar_napa']) && $_POST['dar_napa'] === '1';
    $napa_cant  = max(0, (int)($_POST['napa_cantidad'] ?? 0));

    if (!$id_prod)        $msg_err = 'Selecciona un producto.';
    elseif ($cantidad<=0) $msg_err = 'La cantidad debe ser mayor a 0.';
    else {
        // Precio base del producto
        $sp = $pdo->prepare("SELECT precio_venta FROM producto WHERE id_producto=?");
        $sp->execute([$id_prod]);
        $precio_venta = $precio_man > 0 ? $precio_man : (float)$sp->fetchColumn();

        $bonificacion = 0;   // unidades extra sin cobrar
        $napa         = 0;   // ñapa (solo mostrador)
        $und_fisicas  = $cantidad;

        if ($id_cliente > 0) {
            // ── Bonificación 20% para tiendas ────────────────────────
            $tc = $pdo->prepare("SELECT tipo FROM cliente WHERE id_cliente=?");
            $tc->execute([$id_cliente]);
            $tipo_cliente = $tc->fetchColumn();
            if ($tipo_cliente === 'tienda') {
                $bonificacion = (int)floor($cantidad * 0.20);
                $und_fisicas  = $cantidad + $bonificacion;
            }
        } else {
            // ── Ñapa para mostrador (manual) ──────────────────────────
            if ($dar_napa && $napa_cant > 0) {
                $napa        = $napa_cant;
                $und_fisicas = $cantidad + $napa;
            }
        }

        // Validar stock para todas las unidades físicas que salen
        $val = validarStockVenta($id_prod, $und_fisicas);
        if (!$val['ok']) {
            $extra = '';
            if ($bonificacion > 0) $extra = " (incluye <strong>{$bonificacion} de bonif. tienda</strong>)";
            if ($napa > 0)         $extra = " (incluye <strong>{$napa} de ñapa</strong>)";
            $msg_err = $val['mensaje'] . $extra;
        } else {
            $total = round($precio_venta * $cantidad, 2);
            // unidades_bonificacion guarda el total de extra (bonif tienda o ñapa)
            $extra_total = $bonificacion + $napa;

            $stmt = $pdo->prepare("
                INSERT INTO venta
                    (id_producto, id_cliente, fecha_hora,
                     unidades_vendidas, precio_unitario, total_venta, unidades_bonificacion)
                VALUES (?, ?, NOW(), ?, ?, ?, ?)
            ");
            $stmt->execute([
                $id_prod,
                $id_cliente > 0 ? $id_cliente : null,
                $und_fisicas,
                $precio_venta,
                $total,
                $extra_total,
            ]);

            $np = $pdo->prepare("SELECT nombre FROM producto WHERE id_producto=?");
            $np->execute([$id_prod]);
            $nombre_prod = $np->fetchColumn();

            $detalle_extra = '';
            if ($bonificacion > 0) $detalle_extra = " + <strong>{$bonificacion} bonificados 🏪</strong>";
            if ($napa > 0)         $detalle_extra = " + <strong>{$napa} de ñapa 🎁</strong>";

            $msg_ok = "Venta registrada: <strong>{$cantidad} und. cobradas</strong>"
                    . $detalle_extra
                    . " = <strong>{$und_fisicas} entregadas</strong>"
                    . " de <strong>" . htmlspecialchars($nombre_prod) . "</strong>"
                    . " · Total: <strong>$" . number_format($total,0,',','.') . "</strong>";
        }
    }
}

// ══════════════════════════════════════════════════════════════
//  POST — Editar venta
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_venta'])) {
    $id_venta   = (int)($_POST['id_venta']      ?? 0);
    $id_prod    = (int)($_POST['ev_producto']    ?? 0);
    $cantidad   = (int)($_POST['ev_cantidad']    ?? 0);   // unidades PAGADAS
    $id_cliente = (int)($_POST['ev_cliente']     ?? 0);
    $precio_man = (float)($_POST['ev_precio']    ?? 0);

    if (!$id_venta || !$id_prod || $cantidad <= 0) {
        $msg_err = 'Datos inválidos para editar la venta.';
    } else {
        $sp = $pdo->prepare("SELECT precio_venta FROM producto WHERE id_producto=?");
        $sp->execute([$id_prod]);
        $precio_venta = $precio_man > 0 ? $precio_man : (float)$sp->fetchColumn();

        $bonificacion = 0;
        $napa         = 0;
        $und_fisicas  = $cantidad;

        if ($id_cliente > 0) {
            $tc = $pdo->prepare("SELECT tipo FROM cliente WHERE id_cliente=?");
            $tc->execute([$id_cliente]);
            $tipo_cliente = $tc->fetchColumn();
            if ($tipo_cliente === 'tienda') {
                $bonificacion = (int)floor($cantidad * 0.20);
                $und_fisicas  = $cantidad + $bonificacion;
            }
        }

        $total       = round($precio_venta * $cantidad, 2);
        $extra_total = $bonificacion + $napa;

        $pdo->prepare("
            UPDATE venta
            SET id_producto=?, id_cliente=?, unidades_vendidas=?,
                precio_unitario=?, total_venta=?, unidades_bonificacion=?
            WHERE id_venta=?
        ")->execute([
            $id_prod,
            $id_cliente > 0 ? $id_cliente : null,
            $und_fisicas,
            $precio_venta,
            $total,
            $extra_total,
            $id_venta,
        ]);

        $np = $pdo->prepare("SELECT nombre FROM producto WHERE id_producto=?");
        $np->execute([$id_prod]);
        $msg_ok = "Venta <strong>#$id_venta</strong> actualizada correctamente.";
    }
}

// ══════════════════════════════════════════════════════════════
//  GET — Eliminar venta (solo del día actual)
// ══════════════════════════════════════════════════════════════
if (!empty($_GET['del_venta'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM venta WHERE id_venta=? AND DATE(fecha_hora)=CURDATE()");
        $stmt->execute([(int)$_GET['del_venta']]);
    } catch (Exception $e) {
        // FK o error de BD — ignorar silenciosamente
    }
    header('Location: index.php'); exit;
}

// ── Productos con stock disponible hoy ──────────────────────────
$stmt = $pdo->prepare("
    SELECT p.id_producto, p.nombre, p.precio_venta, p.categoria,
           COALESCE(
               (SELECT SUM(pr.unidades_producidas) FROM produccion pr
                WHERE pr.id_producto=p.id_producto AND DATE(pr.fecha_produccion)=CURDATE()), 0
           ) - COALESCE(
               (SELECT SUM(v.unidades_vendidas) FROM venta v
                WHERE v.id_producto=p.id_producto AND DATE(v.fecha_hora)=CURDATE()), 0
           ) AS stock_hoy
    FROM producto p
    WHERE p.activo=1
    ORDER BY p.nombre
");
$stmt->execute();
$productos = $stmt->fetchAll();

// ── Clientes activos ─────────────────────────────────────────────
$clientes = $pdo->query("SELECT id_cliente, nombre FROM cliente WHERE activo=1 AND tipo='tienda' ORDER BY nombre")->fetchAll();

// ── Ventas de hoy ────────────────────────────────────────────────
$ventas_hoy = $pdo->query("
    SELECT v.id_venta, v.unidades_vendidas, v.precio_unitario, v.total_venta,
           COALESCE(v.unidades_bonificacion, 0) AS unidades_bonificacion,
           v.fecha_hora,
           p.nombre AS producto,
           COALESCE(c.nombre, 'Mostrador') AS cliente,
           COALESCE(c.tipo, '') AS tipo_cliente
    FROM venta v
    INNER JOIN producto p ON p.id_producto = v.id_producto
    LEFT  JOIN cliente  c ON c.id_cliente  = v.id_cliente
    WHERE DATE(v.fecha_hora) = CURDATE()
    ORDER BY v.fecha_hora DESC
")->fetchAll();

$total_ingresos_hoy = array_sum(array_column($ventas_hoy,'total_venta'));
$total_unidades_hoy = array_sum(array_column($ventas_hoy,'unidades_vendidas'));
$num_ventas_hoy     = count($ventas_hoy);

// ── KPIs rápidos ─────────────────────────────────────────────────
$ventas_ayer = (float)$pdo->query("SELECT COALESCE(SUM(total_venta),0) FROM venta WHERE DATE(fecha_hora)=DATE_SUB(CURDATE(),INTERVAL 1 DAY)")->fetchColumn();
$diff_pct    = $ventas_ayer > 0 ? round((($total_ingresos_hoy - $ventas_ayer) / $ventas_ayer) * 100, 1) : null;

$page_title = 'Ventas';
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
  .wc-pill{background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.2);border-radius:10px;padding:.5rem .85rem;text-align:center;min-width:72px;}
  .wc-pill-num{font-family:'Fraunces',serif;font-size:1.35rem;font-weight:800;color:#fff;line-height:1;}
  .wc-pill-lbl{font-size:.54rem;text-transform:uppercase;letter-spacing:.12em;color:rgba(255,255,255,.58);}
  .wc-pill.ok{background:rgba(200,255,220,.2);border-color:rgba(200,255,220,.35);}
  .wc-pill.ok .wc-pill-num{color:#c8ffd8;}
  .wc-pill.warn{background:rgba(255,235,59,.15);border-color:rgba(255,235,59,.25);}
  .wc-pill.warn .wc-pill-num{color:#fff9c4;}
  .topbar{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;}
  .mod-titulo{font-family:'Fraunces',serif;font-size:1.45rem;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:.5rem;}
  .mod-titulo i{color:var(--c3);}
  .top-actions{display:flex;gap:.5rem;align-items:center;}
  .btn-sec{background:var(--ccard);color:var(--ink2);border:1px solid var(--border);border-radius:10px;padding:.45rem .9rem;font-size:.82rem;font-weight:600;display:inline-flex;align-items:center;gap:.4rem;text-decoration:none;transition:all .2s;}
  .btn-sec:hover{background:var(--clight);border-color:var(--c3);color:var(--ink);}
  /* LAYOUT */
  .g-body{display:grid;grid-template-columns:320px 1fr;gap:.7rem;min-height:0;}
  .card{background:var(--ccard);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);display:flex;flex-direction:column;overflow:hidden;min-height:0;animation:fadeUp .45s ease both;}
  .card:nth-child(1){animation-delay:.2s}.card:nth-child(2){animation-delay:.28s}
  .ch{display:flex;align-items:center;justify-content:space-between;padding:.8rem 1.1rem;flex-shrink:0;border-bottom:1px solid var(--border);}
  .ch-left{display:flex;align-items:center;gap:.5rem;}
  .ch-ico{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1rem;}
  .ico-nar{background:rgba(198,113,36,.1);color:var(--c3);}
  .ico-grn{background:rgba(25,135,84,.1);color:#198754;}
  .ch-title{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.17em;color:var(--ink3);}
  .badge{display:inline-flex;align-items:center;font-size:.62rem;font-weight:700;padding:.15rem .5rem;border-radius:20px;}
  .b-neu{background:var(--clight);color:var(--c1);border:1px solid var(--border);}
  .b-grn{background:#e8f5e9;color:#2e7d32;}
  /* FORM */
  .form-body{padding:.9rem 1.1rem;overflow-y:auto;flex:1;}
  .fl{margin-bottom:.72rem;}
  .fl label{font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:var(--ink3);display:block;margin-bottom:.28rem;}
  .fl input,.fl select{width:100%;border:1px solid var(--border);border-radius:9px;padding:.45rem .75rem;font-size:.84rem;color:var(--ink);font-family:inherit;background:var(--clight);transition:border-color .2s,box-shadow .2s;box-sizing:border-box;}
  .fl input:focus,.fl select:focus{outline:none;border-color:var(--c3);box-shadow:0 0 0 3px rgba(198,113,36,.1);}
  /* PRODUCTO CARD selector visual */
  .prod-select-wrap{position:relative;}
  .stock-indicator{display:flex;align-items:center;justify-content:space-between;padding:.4rem .75rem;background:var(--clight);border:1px solid var(--border);border-radius:8px;margin-top:.3rem;font-size:.75rem;transition:all .2s;}
  .stock-num{font-family:'Fraunces',serif;font-size:1.1rem;font-weight:800;}
  .stock-ok{color:#2e7d32;}
  .stock-warn{color:#e65100;}
  .stock-cero{color:#c62828;}
  /* Cantidad */
  .und-ctrl{display:flex;align-items:center;gap:.5rem;}
  .und-btn{width:38px;height:38px;border-radius:8px;border:1.5px solid var(--border);background:var(--clight);color:var(--ink2);font-size:1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .18s;flex-shrink:0;}
  .und-btn:hover{background:var(--c3);color:#fff;border-color:var(--c3);}
  .und-inp{text-align:center!important;font-family:'Fraunces',serif!important;font-size:1.6rem!important;font-weight:800!important;}
  /* Precio */
  .precio-row{display:grid;grid-template-columns:1fr 1fr;gap:.5rem;}
  .precio-display{background:linear-gradient(135deg,rgba(46,125,50,.08),rgba(46,125,50,.03));border:1px solid rgba(46,125,50,.2);border-radius:9px;padding:.45rem .75rem;display:flex;flex-direction:column;}
  .precio-lbl-s{font-size:.58rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#2e7d32;margin-bottom:.1rem;}
  .precio-total{font-family:'Fraunces',serif;font-size:1.3rem;font-weight:800;color:#1b5e20;}
  /* Botón */
  .btn-guardar{width:100%;background:linear-gradient(135deg,var(--c3),var(--c1));color:#fff;border:none;border-radius:10px;padding:.75rem;font-size:.95rem;font-weight:700;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px rgba(198,113,36,.3);display:flex;align-items:center;justify-content:center;gap:.4rem;transition:all .2s;margin-top:.2rem;}
  .btn-guardar:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 6px 20px rgba(198,113,36,.4);}
  .btn-guardar:disabled{opacity:.4;cursor:not-allowed;}
  /* Mensajes */
  .msg-ok{background:#e8f5e9;border:1px solid #a5d6a7;border-left:3px solid #2e7d32;border-radius:10px;padding:.6rem .9rem;font-size:.8rem;color:#1b5e20;font-weight:600;margin-bottom:.65rem;display:flex;align-items:flex-start;gap:.4rem;}
  .msg-err{background:#ffebee;border:1px solid #ef9a9a;border-left:3px solid #c62828;border-radius:10px;padding:.6rem .9rem;font-size:.8rem;color:#c62828;margin-bottom:.65rem;}
  /* TABLA */
  .tbl-wrap{overflow-y:auto;flex:1;min-height:0;}
  .gt{width:100%;border-collapse:collapse;}
  .gt th{font-size:.61rem;text-transform:uppercase;letter-spacing:.1em;color:var(--ink3);font-weight:700;padding:.5rem .9rem;background:var(--clight);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:1;}
  .gt td{font-size:.82rem;color:var(--ink);padding:.5rem .9rem;border-bottom:1px solid rgba(148,91,53,.05);vertical-align:middle;}
  .gt tr:last-child td{border-bottom:none;}
  .gt tr:hover td{background:rgba(250,243,234,.5);}
  .gt tfoot td{background:var(--clight);padding:.6rem .9rem;font-weight:800;border-top:1.5px solid var(--border);}
  .empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.5rem;padding:3rem 1rem;color:var(--ink3);font-size:.82rem;text-align:center;flex:1;}
  .empty i{font-size:2.2rem;opacity:.3;}
  .tag-cat{font-size:.58rem;font-weight:700;padding:.1rem .38rem;border-radius:20px;background:var(--clight);color:var(--ink3);border:1px solid var(--border);}
  .diff-up{color:#2e7d32;font-size:.72rem;font-weight:700;}
  .diff-dn{color:#c62828;font-size:.72rem;font-weight:700;}
  @media(max-width:900px){.page{height:auto;overflow:visible;margin-top:60px;}.g-body{grid-template-columns:1fr;}}
</style>

<div class="page">

  <div class="wc-banner">
    <div class="wc-left">
      <div>
        <div class="wc-greeting">Panadería BreakControl</div>
        <div class="wc-name">Módulo de <em>Ventas</em></div>
        <div class="wc-sub">Registro de ventas del día · <?= date('d/m/Y') ?></div>
      </div>
    </div>
    <div class="wc-pills">
      <div class="wc-pill <?= $total_ingresos_hoy > 0 ? 'ok' : '' ?>">
        <div class="wc-pill-num">$<?= number_format($total_ingresos_hoy/1000,1) ?>k</div>
        <div class="wc-pill-lbl">Ingresos hoy</div>
      </div>
      <div class="wc-pill">
        <div class="wc-pill-num"><?= $num_ventas_hoy ?></div>
        <div class="wc-pill-lbl">Ventas</div>
      </div>
      <div class="wc-pill">
        <div class="wc-pill-num"><?= $total_unidades_hoy ?></div>
        <div class="wc-pill-lbl">Unidades</div>
      </div>
      <?php if ($diff_pct !== null): ?>
      <div class="wc-pill <?= $diff_pct >= 0 ? 'ok' : 'warn' ?>">
        <div class="wc-pill-num"><?= ($diff_pct >= 0 ? '+' : '') . $diff_pct ?>%</div>
        <div class="wc-pill-lbl">vs ayer</div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="topbar">
    <div class="mod-titulo"><i class="bi bi-cart3"></i> Ventas</div>
    <div class="top-actions">
      <a href="clientes.php" class="btn-sec"><i class="bi bi-shop"></i> Tiendas</a>
    </div>
  </div>

  <div class="g-body">

    <!-- FORMULARIO VENTA -->
    <div class="card">
      <div class="ch">
        <div class="ch-left"><div class="ch-ico ico-nar"><i class="bi bi-cart-plus-fill"></i></div><span class="ch-title">Nueva venta</span></div>
      </div>
      <div class="form-body">

        <?php if ($msg_ok): ?>
        <div class="msg-ok"><i class="bi bi-check-circle-fill"></i><span><?= $msg_ok ?></span></div>
        <?php endif; ?>
        <?php if ($msg_err): ?>
        <div class="msg-err"><i class="bi bi-exclamation-circle-fill"></i> <?= $msg_err ?></div>
        <?php endif; ?>

        <form method="post" id="form-venta">

          <div class="fl">
            <label>Producto</label>
            <div class="prod-select-wrap">
              <select name="id_producto" id="sel-prod" required onchange="actualizarVista()">
                <option value="">— Seleccionar producto —</option>
                <?php foreach ($productos as $p): ?>
                <option value="<?= $p['id_producto'] ?>"
                  data-precio="<?= $p['precio_venta'] ?>"
                  data-stock="<?= max(0,(int)$p['stock_hoy']) ?>"
                  data-cat="<?= $p['categoria'] ?>"
                  <?= (isset($_POST['id_producto']) && $_POST['id_producto']==$p['id_producto']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($p['nombre']) ?>
                </option>
                <?php endforeach; ?>
              </select>
              <div class="stock-indicator" id="stock-box" style="display:none">
                <span style="color:var(--ink3);font-size:.68rem">Stock disponible hoy</span>
                <span class="stock-num" id="stock-num">0</span>
              </div>
            </div>
          </div>

          <div class="fl">
            <label>Cantidad</label>
            <div class="und-ctrl">
              <button type="button" class="und-btn" onclick="changeQ(-1)">−</button>
              <input type="number" name="cantidad" id="inp-cant" class="und-inp"
                     min="1" value="<?= (int)($_POST['cantidad'] ?? 1) ?>"
                     required oninput="actualizarVista()">
              <button type="button" class="und-btn" onclick="changeQ(1)">+</button>
            </div>
          </div>

          <div class="fl">
            <div class="precio-row">
              <div>
                <label>Precio por unidad ($)</label>
                <input type="number" name="precio_manual" id="inp-precio"
                       min="0" step="50" placeholder="Precio"
                       value="<?= htmlspecialchars($_POST['precio_manual'] ?? '', ENT_QUOTES) ?? '' ?>"
                       oninput="actualizarVista()">
              </div>
              <div class="precio-display">
                <span class="precio-lbl-s">Total a cobrar</span>
                <span class="precio-total" id="total-display">$0</span>
              </div>
            </div>
          </div>

          <div class="fl">
            <label>Cliente <span style="font-weight:400;text-transform:none;font-size:.68rem">Mostrador = ñapa · Tienda = +20% producto</span></label>
            <select name="id_cliente" id="sel-cliente" onchange="actualizarVista()">
              <option value="0" data-tipo="">— Mostrador (Ñapa opcional) —</option>
              <?php foreach ($clientes as $c): ?>
              <option value="<?= $c['id_cliente'] ?>"
                data-tipo="tienda"
                <?= (isset($_POST['id_cliente']) && $_POST['id_cliente']==$c['id_cliente']) ? 'selected' : '' ?>>
                🏪 <?= htmlspecialchars($c['nombre']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- PANEL BONIFICACIÓN TIENDA (automática 20%) -->
          <div id="panel-bonif" class="fl" style="display:none">
            <div style="background:rgba(46,125,50,.07);border:1.5px solid rgba(46,125,50,.25);border-radius:10px;padding:.55rem .85rem;display:flex;align-items:center;justify-content:space-between;gap:.5rem;flex-wrap:wrap;">
              <div style="display:flex;align-items:center;gap:.5rem;">
                <span style="font-size:1.1rem">🏪</span>
                <div>
                  <div style="font-size:.63rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:#2e7d32">Bonificación tienda — 20% en producto</div>
                  <div style="font-size:.75rem;color:#1b5e20;margin-top:.12rem">
                    Cobra <strong id="bonif-paga">0</strong>
                    → entrega <strong id="bonif-entrega">0</strong>
                    (<span id="bonif-extra" style="color:#2e7d32;font-weight:700">+0 gratis</span>)
                  </div>
                </div>
              </div>
              <div style="text-align:right">
                <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;color:#2e7d32">Total a cobrar</div>
                <div style="font-family:'Fraunces',serif;font-size:1.2rem;font-weight:800;color:#1b5e20" id="bonif-total">$0</div>
              </div>
            </div>
          </div>

          <!-- PANEL ÑAPA (solo mostrador, manual) -->
          <div id="panel-napa" class="fl" style="display:none">
            <div style="background:rgba(198,113,36,.06);border:1.5px solid rgba(198,113,36,.22);border-radius:10px;padding:.6rem .85rem;">

              <!-- Toggle ¿dar ñapa? -->
              <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;">
                <div style="display:flex;align-items:center;gap:.5rem;">
                  <span style="font-size:1rem">🎁</span>
                  <div>
                    <div style="font-size:.63rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:var(--c3)">¿Dar ñapa?</div>
                    <div style="font-size:.68rem;color:var(--ink3);margin-top:.1rem">Solo para ventas de mostrador</div>
                  </div>
                </div>
                <!-- Toggle switch -->
                <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;user-select:none;font-size:.78rem;color:var(--ink2);font-weight:600">
                  <span id="napa-lbl-no" style="color:var(--ink3)">No</span>
                  <div style="position:relative;width:42px;height:22px;">
                    <input type="checkbox" name="dar_napa" id="chk-napa" value="1"
                           style="position:absolute;opacity:0;width:100%;height:100%;cursor:pointer;margin:0;z-index:1;"
                           onchange="toggleNapa(this.checked)">
                    <div id="napa-track" style="width:42px;height:22px;border-radius:11px;background:#d0c4b8;transition:background .2s;"></div>
                    <div id="napa-thumb" style="position:absolute;top:2px;left:2px;width:18px;height:18px;border-radius:50%;background:#fff;transition:transform .2s;box-shadow:0 1px 4px rgba(0,0,0,.2);"></div>
                  </div>
                  <span id="napa-lbl-si" style="color:var(--c3);font-weight:700;display:none">Sí</span>
                </label>
              </div>

              <!-- Cantidad de ñapa (aparece solo si toggle ON) -->
              <div id="napa-detalle" style="display:none;margin-top:.6rem;border-top:1px solid rgba(198,113,36,.15);padding-top:.55rem;">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:.6rem;flex-wrap:wrap;">
                  <div>
                    <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--ink3);margin-bottom:.25rem">Panes de ñapa (gratis)</div>
                    <div style="display:flex;align-items:center;gap:.4rem;">
                      <button type="button" onclick="changeNapa(-1)"
                        style="width:30px;height:30px;border-radius:7px;border:1.5px solid rgba(198,113,36,.25);background:var(--clight);color:var(--ink2);font-size:.95rem;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;">−</button>
                      <input type="number" name="napa_cantidad" id="inp-napa"
                             min="1" value="1"
                             oninput="actualizarVista()"
                             style="width:60px;text-align:center;border:1px solid rgba(198,113,36,.25);border-radius:8px;padding:.3rem .4rem;font-family:'Fraunces',serif;font-size:1.3rem;font-weight:800;color:var(--c3);background:var(--clight);">
                      <button type="button" onclick="changeNapa(1)"
                        style="width:30px;height:30px;border-radius:7px;border:1.5px solid rgba(198,113,36,.25);background:var(--clight);color:var(--ink2);font-size:.95rem;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;">+</button>
                    </div>
                  </div>
                  <div style="text-align:right;background:rgba(198,113,36,.08);border-radius:8px;padding:.35rem .7rem;">
                    <div style="font-size:.58rem;font-weight:700;text-transform:uppercase;color:var(--ink3)">Entrega total</div>
                    <div style="font-family:'Fraunces',serif;font-size:1.1rem;font-weight:800;color:var(--c3)" id="napa-total-und">— panes</div>
                    <div style="font-size:.6rem;color:var(--ink3)" id="napa-resumen">cobra 0, ñapa 0</div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <button type="submit" name="guardar_venta" class="btn-guardar" id="btn-vender">
            <i class="bi bi-bag-check-fill"></i> Registrar venta
          </button>

        </form>
      </div>
    </div>

    <!-- TABLA DE VENTAS DE HOY -->
    <div class="card">
      <div class="ch">
        <div class="ch-left"><div class="ch-ico ico-grn"><i class="bi bi-receipt"></i></div><span class="ch-title">Ventas de hoy</span></div>
        <span class="badge b-grn">$<?= number_format($total_ingresos_hoy,0,',','.') ?></span>
      </div>
      <?php if (empty($ventas_hoy)): ?>
      <div class="empty">
        <i class="bi bi-cart-x"></i>
        <strong>Sin ventas aún hoy</strong>
        <span>Registra la primera venta con el formulario</span>
      </div>
      <?php else: ?>
      <div class="tbl-wrap">
        <table class="gt">
          <thead>
            <tr>
              <th>Hora</th>
              <th>Producto</th>
              <th style="text-align:center">Uds.</th>
              <th style="text-align:right">P/u</th>
              <th style="text-align:right">Total</th>
              <th>Cliente</th>
              <th style="width:60px"></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($ventas_hoy as $v):
            $extra   = (int)($v['unidades_bonificacion'] ?? 0);
            $pagadas = $v['unidades_vendidas'] - $extra;
            $es_mostrador = empty($v['tipo_cliente']) || $v['tipo_cliente'] === '';
            $es_tienda_v  = $v['tipo_cliente'] === 'tienda';
          ?>
          <tr>
            <td style="color:var(--ink3);font-size:.74rem"><?= date('H:i', strtotime($v['fecha_hora'])) ?></td>
            <td style="font-weight:600"><?= htmlspecialchars($v['producto']) ?></td>
            <td style="text-align:center">
              <span style="font-weight:800;font-family:'Fraunces',serif;font-size:1.05rem;color:var(--c3)"><?= $v['unidades_vendidas'] ?></span>
              <?php if ($extra > 0 && $es_tienda_v): ?>
              <div style="font-size:.58rem;color:#2e7d32;font-weight:700;line-height:1.3">
                <?= $pagadas ?> <span style="color:#2e7d32">+<?= $extra ?>🏪</span>
              </div>
              <?php elseif ($extra > 0 && $es_mostrador): ?>
              <div style="font-size:.58rem;color:var(--c3);font-weight:700;line-height:1.3">
                <?= $pagadas ?> <span style="color:var(--c3)">+<?= $extra ?>🎁</span>
              </div>
              <?php endif; ?>
            </td>
            <td style="text-align:right;font-size:.78rem;color:var(--ink3)">$<?= number_format($v['precio_unitario'],0,',','.') ?></td>
            <td style="text-align:right;font-weight:700;color:#1b5e20">$<?= number_format($v['total_venta'],0,',','.') ?></td>
            <td style="font-size:.78rem;color:var(--ink3)">
              <?= htmlspecialchars($v['cliente']) ?>
              <?php if ($es_tienda_v): ?>
              <span style="font-size:.55rem;font-weight:700;padding:.05rem .3rem;border-radius:20px;background:rgba(46,125,50,.1);color:#2e7d32;border:1px solid rgba(46,125,50,.2);margin-left:.2rem">tienda</span>
              <?php elseif ($es_mostrador && $extra > 0): ?>
              <span style="font-size:.55rem;font-weight:700;padding:.05rem .3rem;border-radius:20px;background:rgba(198,113,36,.1);color:var(--c3);border:1px solid rgba(198,113,36,.2);margin-left:.2rem">ñapa</span>
              <?php endif; ?>
            </td>
            <td style="text-align:center;white-space:nowrap">
              <button onclick="abrirEditarVenta(<?= htmlspecialchars(json_encode($v), ENT_QUOTES) ?>)"
                style="width:26px;height:26px;border-radius:7px;border:1px solid var(--border);background:transparent;color:var(--ink2);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:.75rem;transition:all .2s"
                onmouseover="this.style.background='var(--clight)';this.style.borderColor='var(--c3)'"
                onmouseout="this.style.background='transparent';this.style.borderColor='var(--border)'"
                title="Editar">
                <i class="bi bi-pencil"></i>
              </button>
              <a href="index.php?del_venta=<?= $v['id_venta'] ?>"
                onclick="return confirm('¿Eliminar esta venta? No se puede deshacer.')"
                style="width:26px;height:26px;border-radius:7px;border:1px solid var(--border);background:transparent;color:#c62828;display:inline-flex;align-items:center;justify-content:center;font-size:.75rem;text-decoration:none;transition:all .2s"
                onmouseover="this.style.background='#ffebee'"
                onmouseout="this.style.background='transparent'"
                title="Eliminar">
                <i class="bi bi-trash3-fill"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="3" style="text-align:right;font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;color:var(--ink2)">Total hoy</td>
              <td style="text-align:center;font-family:'Fraunces',serif;color:var(--c3)"><?= $total_unidades_hoy ?></td>
              <td></td>
              <td style="text-align:right;color:#1b5e20">$<?= number_format($total_ingresos_hoy,0,',','.') ?></td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<script>
// Datos de productos para JS
const prods = {};
<?php foreach ($productos as $p): ?>
prods[<?= $p['id_producto'] ?>] = {
  precio: <?= $p['precio_venta'] ?>,
  stock: <?= max(0,(int)$p['stock_hoy']) ?>,
  nombre: <?= json_encode($p['nombre']) ?>
};
<?php endforeach; ?>

function changeQ(d) {
  const i = document.getElementById('inp-cant');
  i.value = Math.max(1, (parseInt(i.value) || 1) + d);
  actualizarVista();
}

function changeNapa(d) {
  const i = document.getElementById('inp-napa');
  i.value = Math.max(1, (parseInt(i.value) || 1) + d);
  actualizarVista();
}

function toggleNapa(on) {
  document.getElementById('napa-detalle').style.display = on ? 'block' : 'none';
  document.getElementById('napa-track').style.background = on ? 'var(--c3)' : '#d0c4b8';
  document.getElementById('napa-thumb').style.transform = on ? 'translateX(20px)' : 'translateX(0)';
  document.getElementById('napa-lbl-no').style.display = on ? 'none' : 'inline';
  document.getElementById('napa-lbl-si').style.display = on ? 'inline' : 'none';
  if (!on) document.getElementById('inp-napa').value = 1;
  actualizarVista();
}

function actualizarVista() {
  const sel    = document.getElementById('sel-prod');
  const selCli = document.getElementById('sel-cliente');
  const id     = sel.value;
  const cant   = parseInt(document.getElementById('inp-cant').value) || 0;
  const inpP   = document.getElementById('inp-precio');
  const sbx    = document.getElementById('stock-box');
  const snum   = document.getElementById('stock-num');
  const tdsp   = document.getElementById('total-display');
  const btn    = document.getElementById('btn-vender');
  const panelB = document.getElementById('panel-bonif');
  const panelN = document.getElementById('panel-napa');

  const tipoCliente = selCli ? (selCli.selectedOptions[0]?.dataset?.tipo || '') : '';
  const esTienda    = tipoCliente === 'tienda';
  const esMostrador = !selCli || selCli.value === '0';

  if (!id) {
    sbx.style.display  = 'none';
    panelB.style.display = 'none';
    panelN.style.display = 'none';
    tdsp.textContent = '$0';
    btn.disabled = false;
    return;
  }

  const prod = prods[id];

  // Mostrar / ocultar paneles según tipo de cliente
  panelB.style.display = (esTienda && cant > 0) ? 'block' : 'none';
  panelN.style.display = esMostrador ? 'block' : 'none';

  // Calcular extras según tipo
  const bonif   = esTienda ? Math.floor(cant * 0.20) : 0;
  const chkNapa = document.getElementById('chk-napa');
  const napaCant = (esMostrador && chkNapa && chkNapa.checked)
    ? (parseInt(document.getElementById('inp-napa').value) || 0)
    : 0;
  const fisicas = cant + bonif + napaCant;

  // Stock
  sbx.style.display = 'flex';
  const stockOk = fisicas <= prod.stock;
  snum.textContent = prod.stock;
  snum.className = 'stock-num ' + (prod.stock === 0 ? 'stock-cero' : prod.stock < 5 ? 'stock-warn' : 'stock-ok');

  // Precio (solo cobra las pagadas)
  if (!inpP.value || inpP.value == 0) inpP.value = prod.precio;
  const precio = parseFloat(inpP.value) || prod.precio;
  const total  = precio * cant;
  tdsp.textContent = '$' + total.toLocaleString('es-CO', {maximumFractionDigits: 0});

  // Panel tienda
  if (esTienda && cant > 0) {
    document.getElementById('bonif-paga').textContent    = cant;
    document.getElementById('bonif-entrega').textContent = cant + bonif;
    document.getElementById('bonif-extra').textContent   = '+' + bonif + ' gratis';
    document.getElementById('bonif-total').textContent   = '$' + total.toLocaleString('es-CO', {maximumFractionDigits: 0});
  }

  // Panel ñapa (actualizar resumen)
  if (esMostrador && napaCant > 0) {
    document.getElementById('napa-total-und').textContent = fisicas + ' panes';
    document.getElementById('napa-resumen').textContent   = 'cobra ' + cant + ', ñapa ' + napaCant;
  } else if (esMostrador) {
    document.getElementById('napa-total-und').textContent = cant + ' panes';
    document.getElementById('napa-resumen').textContent   = 'sin ñapa';
  }

  // Bloquear si no alcanza el stock para todo lo que sale
  if (fisicas > prod.stock) {
    btn.disabled = true;
    snum.className = 'stock-num stock-cero';
    const faltaTxt = fisicas > prod.stock
      ? ' (faltan ' + (fisicas - prod.stock) + (napaCant > 0 ? ' con ñapa' : bonif > 0 ? ' con bonif.' : '') + ')'
      : '';
    snum.textContent = prod.stock + faltaTxt;
  } else {
    btn.disabled = false;
  }
}

// Al seleccionar producto, poner precio
document.getElementById('sel-prod').addEventListener('change', function() {
  const id = this.value;
  if (id && prods[id]) {
    document.getElementById('inp-precio').value = prods[id].precio;
  } else {
    document.getElementById('inp-precio').value = '';
  }
  actualizarVista();
});

// Iniciar si hay datos del POST
window.addEventListener('DOMContentLoaded', actualizarVista);
</script>

<!-- ══════════════════════════════════════════════════
     MODAL EDITAR VENTA
════════════════════════════════════════════════════ -->
<div id="modal-editar" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;display:none;align-items:center;justify-content:center;padding:1rem">
  <div style="background:#fff;border-radius:18px;padding:1.5rem;width:100%;max-width:420px;box-shadow:0 8px 40px rgba(0,0,0,.2);position:relative">
    <button onclick="cerrarModal()" style="position:absolute;top:.8rem;right:.8rem;background:transparent;border:none;font-size:1.2rem;cursor:pointer;color:var(--ink2);line-height:1">✕</button>
    <h3 style="margin:0 0 1.2rem;font-family:'Fraunces',serif;color:var(--ink);font-size:1.1rem">
      <i class="bi bi-pencil-square" style="color:var(--c3)"></i> Editar Venta <span id="ev-id-lbl" style="font-size:.85rem;color:var(--ink3)"></span>
    </h3>

    <form method="post" action="index.php">
      <input type="hidden" name="editar_venta" value="1">
      <input type="hidden" name="id_venta" id="ev-id">

      <div style="margin-bottom:.9rem">
        <label style="font-size:.78rem;font-weight:700;color:var(--ink2);display:block;margin-bottom:.3rem">Producto</label>
        <select name="ev_producto" id="ev-producto" style="width:100%;padding:.5rem .7rem;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.88rem;background:var(--clight);color:var(--ink)" onchange="recalcModal()">
          <?php foreach ($productos as $p): ?>
          <option value="<?= $p['id_producto'] ?>" data-precio="<?= $p['precio_venta'] ?>"><?= htmlspecialchars($p['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.7rem;margin-bottom:.9rem">
        <div>
          <label style="font-size:.78rem;font-weight:700;color:var(--ink2);display:block;margin-bottom:.3rem">Uds. pagadas</label>
          <input type="number" name="ev_cantidad" id="ev-cantidad" min="1" value="1"
            style="width:100%;padding:.5rem .7rem;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.88rem;background:var(--clight);color:var(--ink);box-sizing:border-box"
            oninput="recalcModal()">
        </div>
        <div>
          <label style="font-size:.78rem;font-weight:700;color:var(--ink2);display:block;margin-bottom:.3rem">Precio unit.</label>
          <input type="number" name="ev_precio" id="ev-precio" min="0" step="50"
            style="width:100%;padding:.5rem .7rem;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.88rem;background:var(--clight);color:var(--ink);box-sizing:border-box"
            oninput="recalcModal()">
        </div>
      </div>

      <div style="margin-bottom:1.1rem">
        <label style="font-size:.78rem;font-weight:700;color:var(--ink2);display:block;margin-bottom:.3rem">Cliente</label>
        <select name="ev_cliente" id="ev-cliente" style="width:100%;padding:.5rem .7rem;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.88rem;background:var(--clight);color:var(--ink)" onchange="recalcModal()">
          <option value="0">— Mostrador —</option>
          <?php
          $clientes_todos = $pdo->query("SELECT id_cliente, nombre, tipo FROM cliente WHERE activo=1 ORDER BY nombre")->fetchAll();
          foreach ($clientes_todos as $cl):
          ?>
          <option value="<?= $cl['id_cliente'] ?>" data-tipo="<?= $cl['tipo'] ?>"><?= htmlspecialchars($cl['nombre']) ?><?= $cl['tipo']==='tienda' ? ' 🏪' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Resumen recalculado -->
      <div id="ev-resumen" style="background:var(--clight);border-radius:10px;padding:.7rem .9rem;margin-bottom:1rem;font-size:.82rem;color:var(--ink2);display:flex;gap:.5rem;flex-direction:column"></div>

      <button type="submit" style="width:100%;background:linear-gradient(135deg,var(--c3),var(--c1));color:#fff;border:none;border-radius:10px;padding:.65rem;font-size:.88rem;font-weight:700;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px rgba(198,113,36,.3)">
        <i class="bi bi-check-lg"></i> Guardar cambios
      </button>
    </form>
  </div>
</div>

<script>
function abrirEditarVenta(v) {
  document.getElementById('ev-id').value      = v.id_venta;
  document.getElementById('ev-id-lbl').textContent = '#' + v.id_venta;
  document.getElementById('ev-producto').value = v.id_producto || '';
  document.getElementById('ev-precio').value   = v.precio_unitario;

  // Unidades pagadas = vendidas - bonificacion
  const extra = parseInt(v.unidades_bonificacion) || 0;
  const pagadas = parseInt(v.unidades_vendidas) - extra;
  document.getElementById('ev-cantidad').value = Math.max(1, pagadas);

  // Seleccionar cliente
  const sel = document.getElementById('ev-cliente');
  sel.value = v.id_cliente || '0';
  if (sel.value !== String(v.id_cliente || '0')) sel.value = '0';

  recalcModal();
  const modal = document.getElementById('modal-editar');
  modal.style.display = 'flex';
  document.body.style.overflow = 'hidden';
}

function cerrarModal() {
  document.getElementById('modal-editar').style.display = 'none';
  document.body.style.overflow = '';
}

function recalcModal() {
  const prodSel  = document.getElementById('ev-producto');
  const cantidad = parseInt(document.getElementById('ev-cantidad').value) || 0;
  const precio   = parseFloat(document.getElementById('ev-precio').value) || 0;
  const cliSel   = document.getElementById('ev-cliente');
  const tipo     = cliSel.selectedOptions[0]?.dataset?.tipo || '';
  const esTienda = tipo === 'tienda';

  const bonif    = esTienda ? Math.floor(cantidad * 0.20) : 0;
  const fisicas  = cantidad + bonif;
  const total    = precio * cantidad;

  const r = document.getElementById('ev-resumen');
  let html = `<div>💰 Total cobrado: <strong>$${total.toLocaleString('es-CO',{maximumFractionDigits:0})}</strong></div>`;
  html    += `<div>📦 Unidades entregadas: <strong>${fisicas}</strong>`;
  if (bonif > 0) html += ` <span style="color:#2e7d32;font-size:.78rem">(${cantidad} pagadas + ${bonif} bonif. tienda 🏪)</span>`;
  html    += `</div>`;
  r.innerHTML = html;
}

// Cerrar modal al hacer click fuera
document.getElementById('modal-editar').addEventListener('click', function(e) {
  if (e.target === this) cerrarModal();
});
</script>
</body></html>
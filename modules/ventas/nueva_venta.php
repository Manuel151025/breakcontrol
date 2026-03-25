<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/sesion.php';
require_once __DIR__ . '/../../includes/funciones.php';

requerirPropietario();
$pdo  = getConexion();
$user = usuarioActual();
$msg_ok = ''; $msg_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_venta'])) {
    $id_prod   = (int)($_POST['id_producto']       ?? 0);
    $id_cli    = (int)($_POST['id_cliente']         ?? 0) ?: null;
    $unidades  = (int)($_POST['unidades_vendidas']  ?? 0);
    $precio    = (float)($_POST['precio_unitario']  ?? 0);
    $sobrantes = (int)($_POST['unidades_sobrantes'] ?? 0);

    // ── Validaciones básicas ──────────────────────────────────────
    if (!$id_prod || $unidades <= 0 || $precio <= 0) {
        $msg_err = 'Completa todos los campos correctamente.';

    // ── Validar que sobrantes no superen las unidades vendidas ────
    } elseif ($sobrantes > $unidades) {
        $msg_err = 'Los sobrantes no pueden ser mayores que las unidades vendidas.';

    } else {
        // ── Validar stock disponible del producto ─────────────────
        $stock = validarStockVenta($id_prod, $unidades);

        if (!$stock['ok']) {
            $msg_err = $stock['mensaje'];
        } else {
            $total = $unidades * $precio;
            try {
                $pdo->prepare("INSERT INTO venta (id_producto,id_cliente,id_usuario,unidades_vendidas,precio_unitario,total_venta,unidades_sobrantes,fecha_hora) VALUES (?,?,?,?,?,?,?,NOW())")
                    ->execute([$id_prod, $id_cli, $user['id_usuario'], $unidades, $precio, $total, $sobrantes]);
                $_SESSION['msg_ok'] = 'Venta registrada: $' . number_format($total, 0, ',', '.');
                header('Location: nueva_venta.php');
                exit;
            } catch (Exception $e) {
                $msg_err = 'Error al registrar la venta. Intenta de nuevo.';
            }
        }
    }
}

$productos_list = $pdo->query("
    SELECT p.id_producto, p.nombre, p.precio_venta,
           GREATEST(0,
               COALESCE((SELECT SUM(pr.unidades_producidas) FROM produccion pr
                         WHERE pr.id_producto = p.id_producto AND DATE(pr.fecha_produccion) = CURDATE()), 0)
             - COALESCE((SELECT SUM(v2.unidades_vendidas)   FROM venta v2
                         WHERE v2.id_producto = p.id_producto AND DATE(v2.fecha_hora) = CURDATE()), 0)
           ) AS stock_disponible
    FROM producto p
    WHERE p.activo = 1
    ORDER BY p.nombre
")->fetchAll();
$clientes_list  = $pdo->query("SELECT id_cliente, nombre, tipo FROM cliente WHERE activo=1 ORDER BY nombre")->fetchAll();
$ventas_hoy     = $pdo->query("
    SELECT v.*, p.nombre AS producto, c.nombre AS cliente, c.tipo AS tipo_cliente
    FROM venta v
    INNER JOIN producto p ON p.id_producto=v.id_producto
    LEFT  JOIN cliente  c ON c.id_cliente=v.id_cliente
    WHERE DATE(v.fecha_hora)=CURDATE()
    ORDER BY v.fecha_hora DESC LIMIT 20
")->fetchAll();
$total_hoy = array_sum(array_column($ventas_hoy, 'total_venta'));
$num_hoy   = count($ventas_hoy);

$page_title = 'Nueva Venta';
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
  .wc-pill.ok .wc-pill-num{color:#c8ffd8;}.wc-pill.ok{background:rgba(200,255,220,.2);border-color:rgba(200,255,220,.35);}
  .topbar{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;}
  .mod-titulo{font-family:'Fraunces',serif;font-size:1.45rem;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:.5rem;}
  .mod-titulo i{color:var(--c3);}
  .btn-back{background:var(--ccard);color:var(--ink2);border:1px solid var(--border);border-radius:10px;padding:.45rem .9rem;font-size:.82rem;font-weight:600;display:inline-flex;align-items:center;gap:.4rem;text-decoration:none;transition:all .2s;}
  .btn-back:hover{background:var(--clight);border-color:var(--c3);color:var(--ink);}
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
  .form-body{padding:.9rem 1.1rem;overflow-y:auto;flex:1;}
  .fl{margin-bottom:.65rem;}
  .fl label{font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:var(--ink3);display:block;margin-bottom:.3rem;}
  .fl input,.fl select{width:100%;border:1px solid var(--border);border-radius:9px;padding:.45rem .75rem;font-size:.84rem;color:var(--ink);font-family:inherit;background:var(--clight);transition:border-color .2s,box-shadow .2s;}
  .fl input:focus,.fl select:focus{outline:none;border-color:var(--c3);box-shadow:0 0 0 3px rgba(198,113,36,.1);}
  .precio-wrap{display:flex;align-items:center;gap:.5rem;}
  .precio-prefix{font-family:'Fraunces',serif;font-size:1.2rem;font-weight:800;color:var(--ink3);}
  .precio-inp{font-family:'Fraunces',serif!important;font-size:1.4rem!important;font-weight:800!important;text-align:right!important;}
  .und-ctrl{display:flex;align-items:center;gap:.5rem;}
  .und-btn{width:36px;height:36px;border-radius:8px;border:1.5px solid var(--border);background:var(--clight);color:var(--ink2);font-size:1.2rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .18s;flex-shrink:0;}
  .und-btn:hover{background:var(--c3);color:#fff;border-color:var(--c3);}
  .und-inp{text-align:center!important;font-family:'Fraunces',serif!important;font-size:1.4rem!important;font-weight:800!important;width:70px!important;}
  .total-preview{background:linear-gradient(135deg,rgba(198,113,36,.08),rgba(148,91,53,.04));border:1px solid rgba(198,113,36,.2);border-radius:10px;padding:.6rem .9rem;display:flex;align-items:center;justify-content:space-between;margin:.4rem 0 .65rem;}
  .total-lbl{font-size:.65rem;text-transform:uppercase;letter-spacing:.14em;color:var(--ink3);font-weight:700;}
  .total-val{font-family:'Fraunces',serif;font-size:1.5rem;font-weight:800;color:var(--c3);}
  .btn-guardar{width:100%;background:linear-gradient(135deg,var(--c3),var(--c1));color:#fff;border:none;border-radius:10px;padding:.7rem;font-size:.9rem;font-weight:700;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px rgba(198,113,36,.3);display:flex;align-items:center;justify-content:center;gap:.4rem;transition:all .2s;}
  .btn-guardar:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(198,113,36,.4);}
  .msg-ok{background:#e8f5e9;border:1px solid #a5d6a7;border-left:3px solid #2e7d32;border-radius:10px;padding:.6rem .9rem;font-size:.82rem;color:#1b5e20;font-weight:600;margin-bottom:.6rem;display:flex;align-items:center;gap:.4rem;}
  .msg-err{background:#ffebee;border:1px solid #ef9a9a;border-left:3px solid #c62828;border-radius:10px;padding:.6rem .9rem;font-size:.82rem;color:#c62828;margin-bottom:.6rem;display:flex;align-items:center;gap:.4rem;}
  .tbl-wrap{overflow-y:auto;overflow-x:auto;flex:1;min-height:0;}
  .gt{width:100%;border-collapse:collapse;}
  .gt th{font-size:.61rem;text-transform:uppercase;letter-spacing:.1em;color:var(--ink3);font-weight:700;padding:.5rem .85rem;background:var(--clight);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:1;white-space:nowrap;}
  .gt td{font-size:.81rem;color:var(--ink);padding:.5rem .85rem;border-bottom:1px solid rgba(148,91,53,.05);vertical-align:middle;}
  .gt tr:last-child td{border-bottom:none;}
  .gt tr:hover td{background:rgba(250,243,234,.5);}
  .gt tfoot td{background:var(--clight);padding:.55rem .85rem;font-weight:800;border-top:1.5px solid var(--border);}
  .tag-tienda{background:#e3f2fd;color:#0d47a1;font-size:.6rem;font-weight:700;padding:.12rem .45rem;border-radius:20px;}
  .tag-mostrador{background:var(--clight);color:var(--c1);font-size:.6rem;font-weight:700;padding:.12rem .45rem;border-radius:20px;}
  .empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.5rem;padding:2.5rem 1rem;color:var(--ink3);font-size:.82rem;text-align:center;flex:1;}
  .empty i{font-size:2.2rem;opacity:.3;}
  @media(max-width:768px){.page{height:auto;overflow:visible;margin-top:60px;}.g-body{grid-template-columns:1fr;}}
</style>

<div class="page">
  <!-- BANNER -->
  <div class="wc-banner">
    <div class="wc-left">
      <div>
        <div class="wc-greeting">Panadería BreakControl</div>
        <div class="wc-name">Nueva <em>Venta</em></div>
        <div class="wc-sub">Registro de ventas del día · <?= date('d/m/Y') ?></div>
      </div>
    </div>
    <div class="wc-pills">
      <div class="wc-pill <?= $total_hoy > 0 ? 'ok' : '' ?>">
        <div class="wc-pill-num">$<?= number_format($total_hoy/1000,1,',','.') ?>k</div>
        <div class="wc-pill-lbl">Total hoy</div>
      </div>
      <div class="wc-pill">
        <div class="wc-pill-num"><?= $num_hoy ?></div>
        <div class="wc-pill-lbl">Ventas hoy</div>
      </div>
    </div>
  </div>

  <!-- TOPBAR -->
  <div class="topbar">
    <div class="mod-titulo"><i class="bi bi-bag-plus-fill"></i> Nueva venta</div>
    <a href="index.php" class="btn-back"><i class="bi bi-arrow-left"></i> Volver</a>
  </div>

  <!-- CUERPO -->
  <div class="g-body">

    <!-- FORMULARIO -->
    <div class="card">
      <div class="ch">
        <div class="ch-left"><div class="ch-ico ico-nar"><i class="bi bi-pencil-fill"></i></div><span class="ch-title">Datos de la venta</span></div>
      </div>
      <div class="form-body">
        <?php if ($msg_ok): ?><div class="msg-ok"><i class="bi bi-check-circle-fill"></i><?= $msg_ok ?></div><?php endif; ?>
        <?php if ($msg_err): ?><div class="msg-err"><i class="bi bi-exclamation-circle-fill"></i><?= $msg_err ?></div><?php endif; ?>
        <form method="post" id="form-venta">
          <div class="fl">
            <label>Producto</label>
            <select name="id_producto" id="sel-prod" required onchange="setPrecio(this)">
              <option value="">— Seleccionar —</option>
              <?php foreach ($productos_list as $p):
                $stock = (int) $p['stock_disponible'];
                $agotado = $stock <= 0;
              ?>
              <option value="<?= $p['id_producto'] ?>"
                      data-precio="<?= $p['precio_venta'] ?>"
                      data-stock="<?= $stock ?>"
                      <?= $agotado ? 'disabled' : '' ?>>
                <?= htmlspecialchars($p['nombre']) ?>
                — <?= $agotado ? '❌ Sin stock' : "✅ {$stock} disponibles" ?>
              </option>
              <?php endforeach; ?>
            </select>
            <div id="stock-badge" style="margin-top:.35rem;font-size:.76rem;font-weight:600;display:none;"></div>
          </div>
          <div class="fl">
            <label>Unidades vendidas</label>
            <div class="und-ctrl">
              <button type="button" class="und-btn" onclick="changeUnd(-1)">−</button>
              <input type="number" name="unidades_vendidas" id="inp-und" class="und-inp" min="1" value="1" required oninput="calcTotal()">
              <button type="button" class="und-btn" onclick="changeUnd(1)">+</button>
            </div>
          </div>
          <div class="fl">
            <label>Precio unitario</label>
            <div class="precio-wrap">
              <span class="precio-prefix">$</span>
              <input type="number" name="precio_unitario" id="inp-precio" class="precio-inp" min="0" step="50" value="0" required oninput="calcTotal()">
            </div>
          </div>
          <div class="total-preview">
            <span class="total-lbl">Total venta</span>
            <span class="total-val" id="lbl-total">$0</span>
          </div>
          <div class="fl">
            <label>Cliente <span style="font-weight:400;text-transform:none;font-size:.7rem;">(opcional)</span></label>
            <select name="id_cliente" id="sel-cliente" onchange="actualizarBonif()">
              <option value="" data-tipo="mostrador">Mostrador</option>
              <?php foreach ($clientes_list as $c): ?>
              <option value="<?= $c['id_cliente'] ?>" data-tipo="<?= $c['tipo'] ?>"><?= $c['tipo']==='tienda'?'🏪 ':'🧑 ' ?><?= htmlspecialchars($c['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fl">
            <label>Sobrantes <span style="font-weight:400;text-transform:none;font-size:.7rem;">(panes sin vender al cierre)</span></label>
            <input type="number" name="unidades_sobrantes" min="0" value="0">
          </div>
          <!-- Aviso bonificación tienda -->
          <div id="bonif-box" style="display:none;background:rgba(13,71,161,.07);border:1px solid rgba(13,71,161,.2);border-radius:10px;padding:.6rem .9rem;font-size:.82rem;color:#0d47a1;margin-bottom:.65rem;">
            <i class="bi bi-gift-fill"></i> <strong>Tienda — bonificación 20%:</strong><br>
            Por cada 10 panes cobrados se entregan <strong id="bonif-extra">0</strong> panes extra físicamente.<br>
            <span style="font-size:.74rem;">Total a entregar: <strong id="bonif-total">0</strong> panes · Total cobrado: <strong id="bonif-cobrado">$0</strong></span>
          </div>
          <button type="submit" name="guardar_venta" class="btn-guardar">
            <i class="bi bi-bag-check-fill"></i> Registrar venta
          </button>
        </form>
      </div>
    </div>

    <!-- TABLA VENTAS HOY -->
    <div class="card">
      <div class="ch">
        <div class="ch-left"><div class="ch-ico ico-grn"><i class="bi bi-clock-history"></i></div><span class="ch-title">Ventas de hoy</span></div>
        <span class="badge b-grn">$<?= number_format($total_hoy,0,',','.') ?></span>
      </div>
      <?php if (empty($ventas_hoy)): ?>
      <div class="empty"><i class="bi bi-bag-x"></i><strong>Sin ventas aún</strong><span>Los registros de hoy aparecen aquí</span></div>
      <?php else: ?>
      <div class="tbl-wrap">
        <table class="gt">
          <thead>
            <tr><th>Hora</th><th>Producto</th><th>Cliente</th><th style="text-align:center">Und.</th><th style="text-align:right">Total</th></tr>
          </thead>
          <tbody>
          <?php foreach ($ventas_hoy as $v): ?>
          <tr>
            <td style="color:var(--ink3);font-size:.75rem"><?= date('H:i',strtotime($v['fecha_hora'])) ?></td>
            <td style="font-weight:600"><?= htmlspecialchars($v['producto']) ?></td>
            <td>
              <?php if ($v['cliente']): ?>
                <span class="<?= $v['tipo_cliente']==='tienda'?'tag-tienda':'tag-mostrador' ?>"><?= $v['tipo_cliente']==='tienda'?'🏪':'🧑' ?> <?= htmlspecialchars($v['cliente']) ?></span>
              <?php else: ?><span class="tag-mostrador">🧑 Mostrador</span><?php endif; ?>
            </td>
            <td style="text-align:center;font-weight:700"><?= $v['unidades_vendidas'] ?></td>
            <td style="text-align:right;font-weight:700;color:var(--c3)">$<?= number_format($v['total_venta'],0,',','.') ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="4" style="font-size:.75rem;color:var(--ink2);text-align:right;text-transform:uppercase;letter-spacing:.08em;">Total hoy</td>
              <td style="font-family:'Fraunces',serif;font-size:1.1rem;color:#2e7d32;text-align:right;">$<?= number_format($total_hoy,0,',','.') ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>
<script>
function setPrecio(sel){
  const opt = sel.selectedOptions[0];
  const precio = opt.dataset.precio || 0;
  const stock  = parseInt(opt.dataset.stock ?? 0);
  document.getElementById('inp-precio').value = precio;

  // Mostrar badge de stock
  const badge = document.getElementById('stock-badge');
  if (opt.value) {
    badge.style.display = 'block';
    if (stock <= 0) {
      badge.innerHTML = '<span style="color:#c62828">❌ Sin unidades disponibles</span>';
    } else if (stock <= 20) {
      badge.innerHTML = '<span style="color:#e65100">⚠️ Stock bajo: ' + stock + ' unidades disponibles</span>';
    } else {
      badge.innerHTML = '<span style="color:#2e7d32">✅ ' + stock + ' unidades disponibles</span>';
    }
    // Ajustar max del input de unidades
    document.getElementById('inp-und').max = stock;
  } else {
    badge.style.display = 'none';
    document.getElementById('inp-und').removeAttribute('max');
  }

  calcTotal();
}

function changeUnd(d){
  const i   = document.getElementById('inp-und');
  const max = parseInt(i.max) || Infinity;
  i.value   = Math.min(max, Math.max(1, (parseInt(i.value) || 1) + d));
  calcTotal();
}

function actualizarBonif(){
  calcTotal();
}

function calcTotal(){
  const und    = parseInt(document.getElementById('inp-und').value) || 0;
  const precio = parseFloat(document.getElementById('inp-precio').value) || 0;
  const t      = und * precio;
  document.getElementById('lbl-total').textContent = '$' + t.toLocaleString('es-CO',{maximumFractionDigits:0});

  // Validar stock en tiempo real
  const sel    = document.getElementById('sel-prod');
  const opt    = sel.selectedOptions[0];
  const stock  = parseInt(opt?.dataset.stock ?? 0);
  const btn    = document.querySelector('.btn-guardar');
  const badge  = document.getElementById('stock-badge');

  if (opt?.value && und > stock) {
    badge.style.display = 'block';
    badge.innerHTML = '<span style="color:#c62828">❌ Excede el stock: máximo ' + stock + ' unidades</span>';
    btn.disabled = true;
    btn.style.opacity = '0.5';
    btn.style.cursor  = 'not-allowed';
  } else if (opt?.value) {
    // Restaurar badge normal
    if (stock <= 0) {
      badge.innerHTML = '<span style="color:#c62828">❌ Sin unidades disponibles</span>';
    } else if (stock <= 20) {
      badge.innerHTML = '<span style="color:#e65100">⚠️ Stock bajo: ' + stock + ' unidades disponibles</span>';
    } else {
      badge.innerHTML = '<span style="color:#2e7d32">✅ ' + stock + ' unidades disponibles</span>';
    }
    btn.disabled = false;
    btn.style.opacity = '1';
    btn.style.cursor  = 'pointer';
  }

  // Bonificación tienda: por cada 10 cobrados → 2 extra (20%)
  const cli  = document.getElementById('sel-cliente');
  const tipo = cli.selectedOptions[0]?.dataset.tipo || 'mostrador';
  const box  = document.getElementById('bonif-box');
  if (tipo === 'tienda' && und > 0) {
    const extra = Math.floor(und / 10) * 2;
    box.style.display = 'block';
    document.getElementById('bonif-extra').textContent   = extra;
    document.getElementById('bonif-total').textContent   = und + extra;
    document.getElementById('bonif-cobrado').textContent = '$' + t.toLocaleString('es-CO',{maximumFractionDigits:0});
  } else {
    box.style.display = 'none';
  }
}
calcTotal();
</script>
</body></html>
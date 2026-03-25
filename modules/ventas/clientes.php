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

// Guardar tienda
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_cliente'])) {
    $nombre   = trim($_POST['nombre']   ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $notas    = trim($_POST['notas']    ?? '');
    $id_edit  = (int)($_POST['id_cliente'] ?? 0);

    if (!$nombre) {
        $msg_err = 'El nombre de la tienda es obligatorio.';
    } elseif ($id_edit) {
        $pdo->prepare("UPDATE cliente SET nombre=?, tipo='tienda', telefono=? WHERE id_cliente=?")
            ->execute([$nombre, $telefono, $id_edit]);
        redirigir(APP_URL.'/modules/ventas/clientes.php', 'exito', "Tienda <strong>$nombre</strong> actualizada.");
    } else {
        $ck = $pdo->prepare("SELECT id_cliente FROM cliente WHERE nombre=? AND activo=1");
        $ck->execute([$nombre]);
        if ($ck->fetch()) {
            $msg_err = "Ya existe una tienda con ese nombre.";
        } else {
            $pdo->prepare("INSERT INTO cliente (nombre, tipo, telefono, activo) VALUES (?, 'tienda', ?, 1)")
                ->execute([$nombre, $telefono]);
            $msg_ok = "Tienda <strong>$nombre</strong> registrada correctamente.";
        }
    }
}

if (!empty($_GET['del'])) {
    $pdo->prepare("UPDATE cliente SET activo=0 WHERE id_cliente=?")->execute([(int)$_GET['del']]);
    header('Location: clientes.php'); exit;
}

$editando = null;
if (!empty($_GET['edit'])) {
    $s = $pdo->prepare("SELECT * FROM cliente WHERE id_cliente=? AND tipo='tienda'");
    $s->execute([(int)$_GET['edit']]);
    $editando = $s->fetch();
}

$busca  = trim($_GET['q'] ?? '');
$where  = $busca ? "AND c.nombre LIKE ?" : "";
$params = $busca ? ["%$busca%"] : [];

$stmt = $pdo->prepare("
    SELECT c.*,
           COUNT(v.id_venta) AS num_compras,
           COALESCE(SUM(v.total_venta),0) AS total_comprado,
           COALESCE(SUM(v.unidades_vendidas),0) AS total_unidades
    FROM cliente c
    LEFT JOIN venta v ON v.id_cliente = c.id_cliente
    WHERE c.activo=1 AND c.tipo='tienda' $where
    GROUP BY c.id_cliente
    ORDER BY total_comprado DESC, c.nombre
");
$stmt->execute($params);
$tiendas = $stmt->fetchAll();

$total_tiendas          = count($tiendas);
$total_ventas_tiendas   = array_sum(array_column($tiendas, 'total_comprado'));

$page_title = 'Tiendas';
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
  .wc-pill{background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.2);border-radius:10px;padding:.5rem .85rem;text-align:center;min-width:80px;}
  .wc-pill-num{font-family:'Fraunces',serif;font-size:1.35rem;font-weight:800;color:#fff;line-height:1;}
  .wc-pill-lbl{font-size:.54rem;text-transform:uppercase;letter-spacing:.12em;color:rgba(255,255,255,.58);}
  .wc-pill.ok{background:rgba(200,255,220,.2);border-color:rgba(200,255,220,.35);}
  .wc-pill.ok .wc-pill-num{color:#c8ffd8;}
  .topbar{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;}
  .mod-titulo{font-family:'Fraunces',serif;font-size:1.45rem;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:.5rem;}
  .mod-titulo i{color:var(--c3);}
  .top-actions{display:flex;gap:.5rem;align-items:center;}
  .btn-sec{background:var(--ccard);color:var(--ink2);border:1px solid var(--border);border-radius:10px;padding:.45rem .9rem;font-size:.82rem;font-weight:600;display:inline-flex;align-items:center;gap:.4rem;text-decoration:none;transition:all .2s;}
  .btn-sec:hover{background:var(--clight);border-color:var(--c3);color:var(--ink);}
  .inp-search{border:1px solid var(--border);border-radius:9px;padding:.45rem .75rem;font-size:.82rem;font-family:inherit;color:var(--ink);background:var(--ccard);width:200px;}
  .inp-search:focus{outline:none;border-color:var(--c3);box-shadow:0 0 0 3px rgba(198,113,36,.1);}
  .g-body{display:grid;grid-template-columns:300px 1fr;gap:.7rem;min-height:0;}
  .card{background:var(--ccard);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);display:flex;flex-direction:column;overflow:hidden;min-height:0;animation:fadeUp .45s ease both;}
  .card:nth-child(1){animation-delay:.2s}.card:nth-child(2){animation-delay:.28s}
  .ch{display:flex;align-items:center;justify-content:space-between;padding:.8rem 1.1rem;flex-shrink:0;border-bottom:1px solid var(--border);}
  .ch-left{display:flex;align-items:center;gap:.5rem;}
  .ch-ico{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1rem;}
  .ico-nar{background:rgba(198,113,36,.1);color:var(--c3);}
  .ico-grn{background:rgba(46,125,50,.1);color:#2e7d32;}
  .ch-title{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.17em;color:var(--ink3);}
  .badge{display:inline-flex;align-items:center;font-size:.62rem;font-weight:700;padding:.15rem .5rem;border-radius:20px;}
  .b-neu{background:var(--clight);color:var(--c1);border:1px solid var(--border);}
  /* FORM */
  .form-body{padding:.9rem 1.1rem;overflow-y:auto;flex:1;}
  .fl{margin-bottom:.72rem;}
  .fl label{font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:var(--ink3);display:block;margin-bottom:.28rem;}
  .fl input,.fl textarea{width:100%;border:1px solid var(--border);border-radius:9px;padding:.45rem .75rem;font-size:.84rem;color:var(--ink);font-family:inherit;background:var(--clight);transition:border-color .2s;box-sizing:border-box;}
  .fl input:focus,.fl textarea:focus{outline:none;border-color:var(--c3);box-shadow:0 0 0 3px rgba(198,113,36,.1);}
  .fl textarea{resize:vertical;min-height:52px;}
  /* Badge tipo fijo */
  .tipo-fijo{display:inline-flex;align-items:center;gap:.4rem;background:rgba(46,125,50,.08);border:1px solid rgba(46,125,50,.2);border-radius:9px;padding:.42rem .75rem;font-size:.82rem;color:#2e7d32;font-weight:700;width:100%;}
  .tipo-fijo i{font-size:.9rem;}
  .bonif-nota{font-size:.68rem;color:var(--ink3);margin-top:.3rem;display:flex;align-items:center;gap:.3rem;line-height:1.4;}
  .bonif-nota i{color:var(--c3);flex-shrink:0;}
  /* Botones */
  .btn-guardar{width:100%;background:linear-gradient(135deg,var(--c3),var(--c1));color:#fff;border:none;border-radius:10px;padding:.65rem;font-size:.88rem;font-weight:700;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px rgba(198,113,36,.3);display:flex;align-items:center;justify-content:center;gap:.4rem;transition:all .2s;}
  .btn-guardar:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(198,113,36,.4);}
  .btn-cancel{width:100%;background:var(--clight);color:var(--ink2);border:1px solid var(--border);border-radius:10px;padding:.55rem;font-size:.82rem;font-weight:600;cursor:pointer;font-family:inherit;margin-top:.4rem;transition:all .2s;text-align:center;display:block;text-decoration:none;}
  .btn-cancel:hover{border-color:var(--c3);color:var(--c3);}
  .msg-ok{background:#e8f5e9;border:1px solid #a5d6a7;border-left:3px solid #2e7d32;border-radius:10px;padding:.6rem .9rem;font-size:.8rem;color:#1b5e20;font-weight:600;margin-bottom:.65rem;display:flex;align-items:flex-start;gap:.4rem;}
  .msg-err{background:#ffebee;border:1px solid #ef9a9a;border-left:3px solid #c62828;border-radius:10px;padding:.6rem .9rem;font-size:.8rem;color:#c62828;margin-bottom:.65rem;}
  .edit-banner{background:rgba(21,101,192,.08);border:1px solid rgba(21,101,192,.2);border-radius:10px;padding:.55rem .9rem;font-size:.8rem;color:#1565c0;font-weight:600;margin-bottom:.65rem;display:flex;align-items:center;gap:.4rem;}
  /* TABLA */
  .tbl-wrap{overflow-y:auto;flex:1;min-height:0;}
  .gt{width:100%;border-collapse:collapse;}
  .gt th{font-size:.61rem;text-transform:uppercase;letter-spacing:.1em;color:var(--ink3);font-weight:700;padding:.5rem .85rem;background:var(--clight);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:1;white-space:nowrap;}
  .gt td{font-size:.82rem;color:var(--ink);padding:.52rem .85rem;border-bottom:1px solid rgba(148,91,53,.05);vertical-align:middle;}
  .gt tr:last-child td{border-bottom:none;}
  .gt tr:hover td{background:rgba(250,243,234,.5);}
  .tienda-chip{display:inline-flex;align-items:center;gap:.3rem;font-size:.65rem;font-weight:700;padding:.15rem .5rem;border-radius:20px;background:rgba(46,125,50,.1);color:#2e7d32;border:1px solid rgba(46,125,50,.2);}
  .btn-act{width:28px;height:28px;border-radius:7px;border:1px solid;display:inline-flex;align-items:center;justify-content:center;font-size:.8rem;text-decoration:none;transition:all .2s;cursor:pointer;background:transparent;}
  .btn-edit{border-color:rgba(25,118,210,.25);color:#1565c0;}.btn-edit:hover{background:rgba(25,118,210,.1);}
  .btn-del{border-color:rgba(198,40,40,.2);color:#c62828;}.btn-del:hover{background:rgba(198,40,40,.1);}
  .empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.5rem;padding:3rem 1rem;color:var(--ink3);font-size:.82rem;text-align:center;flex:1;}
  .empty i{font-size:2.2rem;opacity:.3;}
  /* Barra de progreso de ventas */
  .venta-bar-w{width:70px;height:5px;background:var(--clight);border-radius:3px;overflow:hidden;display:inline-block;vertical-align:middle;}
  .venta-bar-f{height:100%;border-radius:3px;background:linear-gradient(90deg,var(--c3),var(--c4));}
  @media(max-width:900px){.page{height:auto;overflow:visible;margin-top:60px;}.g-body{grid-template-columns:1fr;}}
</style>

<div class="page">

  <!-- BANNER -->
  <div class="wc-banner">
    <div class="wc-left">
      <div>
        <div class="wc-greeting">Panadería BreakControl</div>
        <div class="wc-name">Clientes <em>Tiendas</em></div>
        <div class="wc-sub">Tiendas con bonificación del 20% en producto</div>
      </div>
    </div>
    <div class="wc-pills">
      <div class="wc-pill ok">
        <div class="wc-pill-num"><?= $total_tiendas ?></div>
        <div class="wc-pill-lbl">Tiendas</div>
      </div>
      <div class="wc-pill <?= $total_ventas_tiendas > 0 ? 'ok' : '' ?>">
        <div class="wc-pill-num">$<?= number_format($total_ventas_tiendas/1000, 1) ?>k</div>
        <div class="wc-pill-lbl">Vendido</div>
      </div>
    </div>
  </div>

  <!-- TOPBAR -->
  <div class="topbar">
    <div class="mod-titulo"><i class="bi bi-shop"></i> Tiendas</div>
    <div class="top-actions">
      <form method="get" style="display:flex;gap:.4rem">
        <input type="text" name="q" class="inp-search" placeholder="Buscar tienda…" value="<?= htmlspecialchars($busca) ?>">
      </form>
      <a href="index.php" class="btn-sec"><i class="bi bi-arrow-left"></i> Volver a Ventas</a>
    </div>
  </div>

  <div class="g-body">

    <!-- FORMULARIO REGISTRO -->
    <div class="card">
      <div class="ch">
        <div class="ch-left">
          <div class="ch-ico ico-nar"><i class="bi bi-<?= $editando ? 'pencil-fill' : 'shop' ?>"></i></div>
          <span class="ch-title"><?= $editando ? 'Editar tienda' : 'Nueva tienda' ?></span>
        </div>
      </div>
      <div class="form-body">

        <?php if ($msg_ok): ?><div class="msg-ok"><i class="bi bi-check-circle-fill"></i><?= $msg_ok ?></div><?php endif; ?>
        <?php if ($msg_err): ?><div class="msg-err"><i class="bi bi-exclamation-circle-fill"></i> <?= $msg_err ?></div><?php endif; ?>
        <?php if ($editando): ?>
        <div class="edit-banner"><i class="bi bi-pencil-square"></i> Editando: <strong><?= htmlspecialchars($editando['nombre']) ?></strong></div>
        <?php endif; ?>

        <form method="post">
          <?php if ($editando): ?><input type="hidden" name="id_cliente" value="<?= $editando['id_cliente'] ?>"><?php endif; ?>

          <div class="fl">
            <label>Nombre de la tienda <span style="color:#c62828">*</span></label>
            <input type="text" name="nombre"
                   placeholder="Ej: Tienda Don Pedro"
                   value="<?= htmlspecialchars($editando['nombre'] ?? '') ?>"
                   required autofocus>
          </div>

          <div class="fl">
            <label>Tipo de cliente</label>
            <div class="tipo-fijo">
              <i class="bi bi-shop-window"></i> Tienda
            </div>
            <div class="bonif-nota">
              <i class="bi bi-gift-fill"></i>
              Recibe bonificación automática del <strong>20% en producto</strong> — paga 10, recibe 12.
            </div>
          </div>

          <div class="fl">
            <label>Teléfono <span style="font-weight:400;text-transform:none;font-size:.68rem">(opcional)</span></label>
            <input type="tel" name="telefono"
                   placeholder="Ej: 3001234567"
                   value="<?= htmlspecialchars($editando['telefono'] ?? '') ?>">
          </div>

          <div class="fl">
            <label>Notas <span style="font-weight:400;text-transform:none;font-size:.68rem">(opcional)</span></label>
            <textarea name="notas" placeholder="Pedido frecuente, días de visita…"><?= htmlspecialchars($editando['notas'] ?? '') ?></textarea>
          </div>

          <button type="submit" name="guardar_cliente" class="btn-guardar">
            <i class="bi bi-<?= $editando ? 'check-lg' : 'shop' ?>"></i>
            <?= $editando ? 'Guardar cambios' : 'Registrar tienda' ?>
          </button>
          <?php if ($editando): ?>
          <a href="clientes.php" class="btn-cancel">Cancelar</a>
          <?php endif; ?>
        </form>
      </div>
    </div>

    <!-- TABLA DE TIENDAS -->
    <div class="card">
      <div class="ch">
        <div class="ch-left"><div class="ch-ico ico-grn"><i class="bi bi-table"></i></div><span class="ch-title">Tiendas registradas</span></div>
        <span class="badge b-neu"><?= $total_tiendas ?> tiendas</span>
      </div>
      <?php if (empty($tiendas)): ?>
      <div class="empty">
        <i class="bi bi-shop"></i>
        <strong>Sin tiendas aún</strong>
        <span>Registra la primera tienda con el formulario</span>
      </div>
      <?php else:
        $max_venta = max(array_column($tiendas, 'total_comprado') ?: [1]);
      ?>
      <div class="tbl-wrap">
        <table class="gt">
          <thead>
            <tr>
              <th>Tienda</th>
              <th>Teléfono</th>
              <th style="text-align:center">Compras</th>
              <th style="text-align:center">Unidades</th>
              <th style="text-align:right">Total comprado</th>
              <th style="width:60px">Ranking</th>
              <th style="width:60px"></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($tiendas as $t): ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:.5rem;">
                <span style="font-size:1.1rem">🏪</span>
                <div>
                  <strong><?= htmlspecialchars($t['nombre']) ?></strong>
                  <?php if (!empty($t['notas'])): ?>
                  <div style="font-size:.68rem;color:var(--ink3);margin-top:.1rem"><?= htmlspecialchars(mb_substr($t['notas'] ?? '', 0, 42)) . (mb_strlen($t['notas'] ?? '') > 42 ? '…' : '') ?></div>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td style="font-size:.78rem;color:var(--ink3)"><?= htmlspecialchars($t['telefono'] ?? '—') ?></td>
            <td style="text-align:center;font-weight:700"><?= $t['num_compras'] ?></td>
            <td style="text-align:center;font-weight:700;color:var(--c3)"><?= number_format($t['total_unidades'],0,',','.') ?></td>
            <td style="text-align:right;font-weight:800;color:#2e7d32;font-family:'Fraunces',serif;font-size:.95rem">
              $<?= number_format($t['total_comprado'],0,',','.') ?>
            </td>
            <td style="text-align:center;">
              <?php
                $pct = $max_venta > 0 ? round(($t['total_comprado'] / $max_venta) * 100) : 0;
              ?>
              <div class="venta-bar-w"><div class="venta-bar-f" style="width:<?= $pct ?>%"></div></div>
            </td>
            <td>
              <div style="display:flex;gap:.3rem">
                <a href="clientes.php?edit=<?= $t['id_cliente'] ?>" class="btn-act btn-edit" title="Editar"><i class="bi bi-pencil"></i></a>
                <a href="clientes.php?del=<?= $t['id_cliente'] ?>" class="btn-act btn-del" title="Desactivar"
                   onclick="return confirm('¿Desactivar a <?= addslashes(htmlspecialchars($t['nombre'])) ?>?')">
                  <i class="bi bi-trash3"></i>
                </a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>
</body></html>
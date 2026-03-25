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

// ── Guardar insumo ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_insumo'])) {
    $nombre  = trim($_POST['nombre'] ?? '');
    $unidad  = trim($_POST['unidad_medida'] ?? '');
    $stock   = (float)($_POST['stock_actual'] ?? 0);
    $reposi  = (float)($_POST['punto_reposicion'] ?? 0);
    $es_har  = isset($_POST['es_harina']) ? 1 : 0;
    $id_edit = (int)($_POST['id_insumo'] ?? 0);

    $unidades_validas = ['kg','g','L','ml','unidad'];
    if (!$nombre || !$unidad) {
        $msg_err = 'Nombre y unidad son obligatorios.';
    } elseif (!in_array($unidad, $unidades_validas)) {
        $msg_err = 'Unidad de medida no válida.';
    } elseif ($id_edit) {
        // Verificar que el nuevo nombre no lo use otro insumo distinto
        $chk = $pdo->prepare("SELECT id_insumo FROM insumo WHERE nombre = ? AND id_insumo != ?");
        $chk->execute([$nombre, $id_edit]);
        if ($chk->fetch()) {
            $msg_err = "Ya existe otro insumo con el nombre \"$nombre\". Elige un nombre diferente.";
        } else {
            $pdo->prepare("UPDATE insumo SET nombre=?,unidad_medida=?,stock_actual=?,punto_reposicion=?,es_harina=? WHERE id_insumo=?")
                ->execute([$nombre, $unidad, $stock, $reposi, $es_har, $id_edit]);
            redirigir(APP_URL . '/modules/inventario/index.php', 'exito', "Insumo <strong>$nombre</strong> actualizado correctamente.");
        }
    } else {
        // Si existe con el mismo nombre (activo o inactivo) → reactivar con nuevos datos
        $chk = $pdo->prepare("SELECT id_insumo, activo FROM insumo WHERE nombre = ?");
        $chk->execute([$nombre]);
        $existe = $chk->fetch();
        if ($existe) {
            // Ya existe → actualizar y reactivar en lugar de duplicar
            $pdo->prepare("UPDATE insumo SET unidad_medida=?,stock_actual=?,punto_reposicion=?,es_harina=?,activo=1 WHERE id_insumo=?")
                ->execute([$unidad, $stock, $reposi, $es_har, $existe['id_insumo']]);
            $msg_ok = "Insumo <strong>$nombre</strong> actualizado y reactivado correctamente.";
        } else {
            $pdo->prepare("INSERT INTO insumo (nombre,unidad_medida,stock_actual,punto_reposicion,es_harina,activo) VALUES (?,?,?,?,?,1)")
                ->execute([$nombre, $unidad, $stock, $reposi, $es_har]);
            $msg_ok = 'Insumo registrado correctamente.';
        }
    }
}

// ── Eliminar ────────────────────────────────────────────────────
if (!empty($_GET['del'])) {
    $pdo->prepare("DELETE FROM insumo WHERE id_insumo=?")->execute([(int)$_GET['del']]);
    header('Location: index.php'); exit;
}

// ── Datos para editar ───────────────────────────────────────────
$editando = null;
if (!empty($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM insumo WHERE id_insumo=?");
    $stmt->execute([(int)$_GET['edit']]);
    $editando = $stmt->fetch();
}

// ── Filtros ─────────────────────────────────────────────────────
$busca         = trim($_GET['q'] ?? '');
$filtro_alerta = !empty($_GET['alerta']);

$where  = "WHERE i.activo=1";
$params = [];
if ($busca)         { $where .= " AND i.nombre LIKE ?"; $params[] = "%$busca%"; }
if ($filtro_alerta) { $where .= " AND i.stock_actual <= i.punto_reposicion"; }

// ── Listado con precio del último lote activo ───────────────────
$stmt = $pdo->prepare("
    SELECT i.*,
           COUNT(DISTINCT CASE WHEN l.estado='activo' AND l.cantidad_disponible>0 THEN l.id_lote END) AS num_lotes,
           COALESCE(
             (SELECT l2.precio_unitario FROM lote l2
              WHERE l2.id_insumo=i.id_insumo
              ORDER BY l2.fecha_ingreso DESC LIMIT 1), 0
           ) AS precio_ultimo
    FROM insumo i
    LEFT JOIN lote l ON l.id_insumo=i.id_insumo
    $where
    GROUP BY i.id_insumo
    ORDER BY i.nombre
");
$stmt->execute($params);
$insumos = $stmt->fetchAll();

// ── KPIs ────────────────────────────────────────────────────────
$total_insumos    = (int)$pdo->query("SELECT COUNT(*) FROM insumo WHERE activo=1")->fetchColumn();
$alertas_count    = (int)$pdo->query("SELECT COUNT(*) FROM insumo WHERE activo=1 AND stock_actual<=punto_reposicion")->fetchColumn();
$lotes_activos    = (int)$pdo->query("SELECT COUNT(*) FROM lote WHERE estado='activo' AND cantidad_disponible>0")->fetchColumn();
$valor_inventario = (float)$pdo->query("SELECT COALESCE(SUM(l.cantidad_disponible * l.precio_unitario),0) FROM lote l WHERE l.estado='activo' AND l.cantidad_disponible>0")->fetchColumn();

$page_title = 'Inventario';
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
  .wc-pill.alert{background:rgba(255,205,210,.25);border-color:rgba(255,205,210,.4);}
  .wc-pill.alert .wc-pill-num{color:#ffcdd2;}
  .wc-pill.ok{background:rgba(200,255,220,.2);border-color:rgba(200,255,220,.35);}
  .wc-pill.ok .wc-pill-num{color:#c8ffd8;}
  /* TOPBAR */
  .topbar{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;}
  .mod-titulo{font-family:'Fraunces',serif;font-size:1.45rem;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:.5rem;}
  .mod-titulo i{color:var(--c3);}
  .top-actions{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;}
  .btn-sec{background:var(--ccard);color:var(--ink2);border:1px solid var(--border);border-radius:10px;padding:.5rem 1rem;font-size:.82rem;font-weight:600;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:.4rem;text-decoration:none;transition:all .2s;}
  .btn-sec:hover{background:var(--clight);border-color:var(--c3);color:var(--ink);}
  .btn-sec.active{background:rgba(198,113,36,.1);border-color:var(--c3);color:var(--c3);}
  .inp-search{border:1px solid var(--border);border-radius:9px;padding:.45rem .75rem;font-size:.82rem;font-family:inherit;color:var(--ink);background:var(--ccard);width:200px;}
  .inp-search:focus{outline:none;border-color:var(--c3);box-shadow:0 0 0 3px rgba(198,113,36,.1);}
  /* CUERPO */
  .g-body{display:grid;grid-template-columns:300px 1fr;gap:.7rem;min-height:0;}
  .card{background:var(--ccard);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);display:flex;flex-direction:column;overflow:hidden;min-height:0;animation:fadeUp .45s ease both;}
  .card:nth-child(1){animation-delay:.25s}.card:nth-child(2){animation-delay:.3s}
  .ch{display:flex;align-items:center;justify-content:space-between;padding:.8rem 1.1rem;flex-shrink:0;border-bottom:1px solid var(--border);}
  .ch-left{display:flex;align-items:center;gap:.5rem;}
  .ch-ico{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1rem;}
  .ico-nar{background:rgba(198,113,36,.1);color:var(--c3);}
  .ch-title{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.17em;color:var(--ink3);}
  .badge{display:inline-flex;align-items:center;font-size:.62rem;font-weight:700;padding:.15rem .5rem;border-radius:20px;}
  .b-neu{background:var(--clight);color:var(--c1);border:1px solid var(--border);}
  /* FORM */
  .form-body{padding:.9rem 1.1rem;overflow-y:auto;flex:1;}
  .fl{margin-bottom:.65rem;}
  .fl label{font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:var(--ink3);display:block;margin-bottom:.3rem;}
  .fl input,.fl select{width:100%;border:1px solid var(--border);border-radius:9px;padding:.45rem .75rem;font-size:.84rem;color:var(--ink);font-family:inherit;background:var(--clight);transition:border-color .2s,box-shadow .2s;}
  .fl input:focus,.fl select:focus{outline:none;border-color:var(--c3);box-shadow:0 0 0 3px rgba(198,113,36,.1);}
  .fl-row{display:grid;grid-template-columns:1fr 1fr;gap:.5rem;}
  .check-row{display:flex;align-items:center;gap:.5rem;padding:.4rem .75rem;border:1px solid var(--border);border-radius:9px;background:var(--clight);cursor:pointer;margin-bottom:.65rem;}
  .check-row input[type=checkbox]{width:16px;height:16px;accent-color:var(--c3);cursor:pointer;}
  .check-row span{font-size:.82rem;color:var(--ink2);font-weight:500;}
  .btn-guardar{width:100%;background:linear-gradient(135deg,var(--c3),var(--c1));color:#fff;border:none;border-radius:10px;padding:.65rem;font-size:.88rem;font-weight:700;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px rgba(198,113,36,.3);display:flex;align-items:center;justify-content:center;gap:.4rem;transition:all .2s;margin-top:.2rem;}
  .btn-guardar:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(198,113,36,.4);}
  .btn-cancel{width:100%;background:var(--clight);color:var(--ink2);border:1px solid var(--border);border-radius:10px;padding:.55rem;font-size:.82rem;font-weight:600;cursor:pointer;font-family:inherit;margin-top:.4rem;transition:all .2s;text-align:center;display:block;text-decoration:none;}
  .btn-cancel:hover{border-color:var(--c3);color:var(--c3);}
  /* TABLA */
  .tbl-wrap{overflow-y:auto;overflow-x:auto;flex:1;min-height:0;}
  .gt{width:100%;border-collapse:collapse;}
  .gt th{font-size:.61rem;text-transform:uppercase;letter-spacing:.1em;color:var(--ink3);font-weight:700;padding:.5rem .85rem;background:var(--clight);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:1;white-space:nowrap;}
  .gt td{font-size:.81rem;color:var(--ink);padding:.5rem .85rem;border-bottom:1px solid rgba(148,91,53,.05);vertical-align:middle;}
  .gt tr:last-child td{border-bottom:none;}
  .gt tr:hover td{background:rgba(250,243,234,.5);}
  .gt tr.alerta-row td{background:rgba(255,235,238,.4);}
  .stock-bar-w{width:60px;height:5px;background:var(--clight);border-radius:3px;overflow:hidden;display:inline-block;vertical-align:middle;}
  .stock-bar-f{height:100%;border-radius:3px;}
  .bar-ok{background:#43a047;}.bar-warn{background:#ff8f00;}.bar-crit{background:#e53935;}
  .tag-alerta{font-size:.58rem;font-weight:700;padding:.15rem .45rem;border-radius:20px;background:rgba(229,57,53,.1);color:#e53935;border:1px solid rgba(229,57,53,.2);}
  .tag-ok{font-size:.58rem;font-weight:700;padding:.15rem .45rem;border-radius:20px;background:rgba(67,160,71,.1);color:#2e7d32;border:1px solid rgba(67,160,71,.2);}
  .tag-harina{font-size:.55rem;font-weight:700;padding:.1rem .38rem;border-radius:20px;background:rgba(198,113,36,.1);color:var(--c3);border:1px solid rgba(198,113,36,.2);}
  .btn-act{width:28px;height:28px;border-radius:7px;border:1px solid;display:inline-flex;align-items:center;justify-content:center;font-size:.8rem;text-decoration:none;transition:all .2s;cursor:pointer;background:transparent;}
  .btn-edit{border-color:rgba(25,118,210,.25);color:#1565c0;}.btn-edit:hover{background:rgba(25,118,210,.1);}
  .btn-del{border-color:rgba(198,40,40,.2);color:#c62828;}.btn-del:hover{background:rgba(198,40,40,.1);}
  .empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.5rem;padding:2.5rem 1rem;color:var(--ink3);font-size:.82rem;text-align:center;flex:1;}
  .empty i{font-size:2.2rem;opacity:.3;}
  .msg-ok{background:#e8f5e9;border:1px solid #a5d6a7;border-left:3px solid #2e7d32;border-radius:10px;padding:.6rem .9rem;font-size:.82rem;color:#1b5e20;font-weight:600;margin-bottom:.6rem;display:flex;align-items:center;gap:.4rem;}
  .msg-err{background:#ffebee;border:1px solid #ef9a9a;border-left:3px solid #c62828;border-radius:10px;padding:.6rem .9rem;font-size:.82rem;color:#c62828;margin-bottom:.6rem;display:flex;align-items:center;gap:.4rem;}
  .edit-banner{background:rgba(21,101,192,.08);border:1px solid rgba(21,101,192,.2);border-radius:10px;padding:.6rem .9rem;font-size:.8rem;color:#1565c0;font-weight:600;margin-bottom:.7rem;display:flex;align-items:center;gap:.4rem;}
  @media(max-width:768px){
    .page{height:auto;overflow:visible;margin-top:60px;}
    .g-body{grid-template-columns:1fr;}
    .inp-search{width:140px;}
    .gt th:nth-child(6),.gt td:nth-child(6),
    .gt th:nth-child(7),.gt td:nth-child(7){display:none;}
  }
</style>

<div class="page">
  <!-- BANNER -->
  <div class="wc-banner">
    <div class="wc-left">
      <div>
        <div class="wc-greeting">Panadería BreakControl</div>
        <div class="wc-name">Inventario <em>de Insumos</em></div>
        <div class="wc-sub">Control de stock en tiempo real</div>
      </div>
    </div>
    <div class="wc-pills">
      <div class="wc-pill">
        <div class="wc-pill-num"><?= $total_insumos ?></div>
        <div class="wc-pill-lbl">Insumos</div>
      </div>
      <div class="wc-pill <?= $alertas_count > 0 ? 'alert' : 'ok' ?>">
        <div class="wc-pill-num"><?= $alertas_count ?></div>
        <div class="wc-pill-lbl">Alertas</div>
      </div>
      <div class="wc-pill">
        <div class="wc-pill-num"><?= $lotes_activos ?></div>
        <div class="wc-pill-lbl">Lotes</div>
      </div>
      <div class="wc-pill ok">
        <div class="wc-pill-num">$<?= number_format($valor_inventario/1000,1) ?>k</div>
        <div class="wc-pill-lbl">Valor total</div>
      </div>
    </div>
  </div>

  <!-- TOPBAR -->
  <div class="topbar">
    <div class="mod-titulo"><i class="bi bi-box-seam-fill"></i> Inventario</div>
    <div class="top-actions">
      <form method="get" style="display:flex;gap:.4rem;align-items:center;">
        <input type="text" name="q" class="inp-search" placeholder="Buscar insumo…" value="<?= htmlspecialchars($busca) ?>">
        <a href="index.php?alerta=1<?= $busca ? '&q='.urlencode($busca) : '' ?>" class="btn-sec <?= $filtro_alerta ? 'active' : '' ?>">
          <i class="bi bi-exclamation-triangle<?= $filtro_alerta ? '-fill' : '' ?>"></i> Alertas
        </a>
      </form>
      <?php if ($editando): ?>
        <a href="index.php" class="btn-sec"><i class="bi bi-plus-lg"></i> Nuevo</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- CUERPO -->
  <div class="g-body">
    <!-- FORMULARIO -->
    <div class="card">
      <div class="ch">
        <div class="ch-left">
          <div class="ch-ico ico-nar"><i class="bi bi-<?= $editando ? 'pencil-fill' : 'plus-lg' ?>"></i></div>
          <span class="ch-title"><?= $editando ? 'Editar insumo' : 'Nuevo insumo' ?></span>
        </div>
      </div>
      <div class="form-body">
        <?php if ($msg_ok): ?><div class="msg-ok"><i class="bi bi-check-circle-fill"></i><?= $msg_ok ?></div><?php endif; ?>
        <?php if ($msg_err): ?><div class="msg-err"><i class="bi bi-exclamation-circle-fill"></i><?= $msg_err ?></div><?php endif; ?>
        <?php if ($editando): ?>
        <div class="edit-banner"><i class="bi bi-pencil-square"></i> Editando: <strong><?= htmlspecialchars($editando['nombre']) ?></strong></div>
        <?php endif; ?>
        <form method="post">
          <?php if ($editando): ?><input type="hidden" name="id_insumo" value="<?= $editando['id_insumo'] ?>"><?php endif; ?>
          <div class="fl">
            <label>Nombre del insumo</label>
            <input type="text" name="nombre" placeholder="Ej: Harina de trigo" required value="<?= htmlspecialchars($editando['nombre'] ?? '') ?>">
          </div>
          <div class="fl-row">
            <div class="fl">
              <label>Unidad de medida</label>
              <select name="unidad_medida">
                <?php foreach(['kg','g','L','ml','unidad'] as $u): ?>
                <option value="<?= $u ?>" <?= ($editando['unidad_medida'] ?? 'kg') === $u ? 'selected' : '' ?>><?= $u ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="fl">
              <label>Stock actual</label>
              <input type="number" name="stock_actual" min="0" step="0.001" placeholder="0" value="<?= $editando ? rtrim(rtrim(number_format((float)$editando['stock_actual'], 3, '.', ''), '0'), '.') : '' ?>">
            </div>
          </div>
          <div class="fl">
            <label>Punto de reposición</label>
            <input type="number" name="punto_reposicion" min="0" step="0.001" placeholder="Stock mínimo para alertar" value="<?= $editando ? rtrim(rtrim(number_format((float)$editando['punto_reposicion'], 3, '.', ''), '0'), '.') : '' ?>">
          </div>
          <label class="check-row">
            <input type="checkbox" name="es_harina" value="1" <?= ($editando['es_harina'] ?? 0) ? 'checked' : '' ?>>
            <span>Es harina (aplica merma del 6%)</span>
          </label>
          <button type="submit" name="guardar_insumo" class="btn-guardar">
            <i class="bi bi-<?= $editando ? 'check-lg' : 'plus-lg' ?>"></i>
            <?= $editando ? 'Guardar cambios' : 'Registrar insumo' ?>
          </button>
          <?php if ($editando): ?>
          <a href="index.php" class="btn-cancel">Cancelar</a>
          <?php endif; ?>
        </form>
      </div>
    </div>

    <!-- TABLA -->
    <div class="card">
      <div class="ch">
        <div class="ch-left">
          <div class="ch-ico ico-nar"><i class="bi bi-table"></i></div>
          <span class="ch-title">Insumos registrados</span>
        </div>
        <span class="badge b-neu"><?= count($insumos) ?> resultados</span>
      </div>
      <div class="tbl-wrap">
        <?php if (empty($insumos)): ?>
        <div class="empty">
          <i class="bi bi-box-seam"></i>
          <strong>Sin insumos</strong>
          <span>Registra el primer insumo usando el formulario</span>
        </div>
        <?php else: ?>
        <table class="gt">
          <thead>
            <tr>
              <th>Insumo</th>
              <th>Unidad</th>
              <th>Stock actual</th>
              <th>Nivel</th>
              <th>Reposición</th>
              <th>Precio/u</th>
              <th>Valor</th>
              <th>Lotes</th>
              <th>Estado</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($insumos as $ins):
            $pct      = $ins['punto_reposicion'] > 0 ? min(100, round($ins['stock_actual'] / $ins['punto_reposicion'] * 50)) : 100;
            $alerta   = $ins['stock_actual'] <= $ins['punto_reposicion'];
            $barClass = $pct >= 80 ? 'bar-ok' : ($pct >= 40 ? 'bar-warn' : 'bar-crit');
            $valor_ins = $ins['stock_actual'] * $ins['precio_ultimo'];
          ?>
          <tr class="<?= $alerta ? 'alerta-row' : '' ?>">
            <td>
              <strong><?= htmlspecialchars($ins['nombre']) ?></strong>
              <?php if ($ins['es_harina']): ?><span class="tag-harina">🌾 harina</span><?php endif; ?>
            </td>
            <td><?= $ins['unidad_medida'] ?></td>
            <td><span style="font-family:'Fraunces',serif;font-weight:700;"><?= formatoInteligente($ins['stock_actual']) ?></span> <span style="font-size:.72rem;color:var(--ink3)"><?= $ins['unidad_medida'] ?></span></td>
            <td>
              <div class="stock-bar-w"><div class="stock-bar-f <?= $barClass ?>" style="width:<?= $pct ?>%"></div></div>
            </td>
            <td><?= formatoInteligente($ins['punto_reposicion']) ?> <span style="font-size:.72rem;color:var(--ink3)"><?= $ins['unidad_medida'] ?></span></td>
            <td><?= $ins['precio_ultimo'] > 0 ? '$'.number_format($ins['precio_ultimo'],0,',','.') : '<span style="color:var(--ink3);font-size:.75rem;">—</span>' ?></td>
            <td><?= $valor_ins > 0 ? '$'.number_format($valor_ins,0,',','.') : '—' ?></td>
            <td style="text-align:center;"><?= $ins['num_lotes'] ?></td>
            <td>
              <?= $alerta
                ? '<span class="tag-alerta"><i class="bi bi-exclamation-circle"></i> Bajo</span>'
                : '<span class="tag-ok"><i class="bi bi-check-circle"></i> OK</span>' ?>
            </td>
            <td style="white-space:nowrap;">
              <div style="display:flex;gap:.3rem;">
                <a href="index.php?edit=<?= $ins['id_insumo'] ?>" class="btn-act btn-edit" title="Editar"><i class="bi bi-pencil"></i></a>
                <a href="index.php?del=<?= $ins['id_insumo'] ?>" class="btn-act btn-del" title="Eliminar" onclick="return confirm('¿Eliminar este insumo? Esta acción no se puede deshacer.')"><i class="bi bi-trash3-fill"></i></a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
</body></html>
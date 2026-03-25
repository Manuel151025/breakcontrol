<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/sesion.php';
require_once __DIR__ . '/../../includes/funciones.php';

requerirPropietario();
$pdo         = getConexion();
$user        = usuarioActual();
$id_producto = (int)($_GET['id'] ?? 0);
$errores     = [];

$stmt = $pdo->prepare("SELECT * FROM producto WHERE id_producto=? AND activo=1");
$stmt->execute([$id_producto]);
$producto = $stmt->fetch();
if (!$producto) { header('Location: index.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids_insumo   = $_POST['id_insumo']   ?? [];
    $cantidades   = $_POST['cantidad']    ?? [];
    $notas        = $_POST['notas']       ?? [];
    $aplica_merma = $_POST['aplica_merma'] ?? [];

    $validos = [];
    foreach ($ids_insumo as $i => $id_ins) {
        $id_ins  = (int)$id_ins;
        $cant_g  = (float)($cantidades[$i] ?? 0);
        $nota    = trim($notas[$i] ?? '');
        $merma   = in_array($i, array_keys($aplica_merma)) ? 1 : 0;
        if ($id_ins > 0 && $cant_g > 0) {
            $row = $pdo->prepare("SELECT unidad_medida FROM insumo WHERE id_insumo=?");
            $row->execute([$id_ins]);
            $unidad = $row->fetchColumn();
            $cant_guardar = in_array($unidad, ['kg','L']) ? $cant_g / 1000 : $cant_g;
            $validos[] = ['id_insumo'=>$id_ins,'cantidad'=>$cant_guardar,'notas'=>$nota,'aplica_merma'=>$merma];
        }
    }

    if (empty($validos)) { $errores[] = 'Agrega al menos un ingrediente con cantidad.'; }

    if (empty($errores)) {
        $check = $pdo->prepare("SELECT id_receta FROM receta WHERE id_producto=? AND es_vigente=1 LIMIT 1");
        $check->execute([$id_producto]);
        $id_receta = $check->fetchColumn();

        if (!$id_receta) {
            $pdo->prepare("INSERT INTO receta (id_producto,id_usuario,version,es_vigente,es_ajuste_temporal,fecha_creacion) VALUES (?,?,1,1,0,NOW())")
                ->execute([$id_producto, $user['id_usuario']]);
            $id_receta = $pdo->lastInsertId();
        }

        $pdo->prepare("DELETE FROM receta_ingrediente WHERE id_receta=?")->execute([$id_receta]);
        $ins = $pdo->prepare("INSERT INTO receta_ingrediente (id_receta,id_insumo,cantidad,aplica_merma,notas) VALUES (?,?,?,?,?)");
        foreach ($validos as $v) {
            $ins->execute([$id_receta, $v['id_insumo'], $v['cantidad'], $v['aplica_merma'], $v['notas']]);
        }
        header('Location: index.php?ok=1'); exit;
    }
}

$receta_row = $pdo->prepare("SELECT id_receta FROM receta WHERE id_producto=? AND es_vigente=1 LIMIT 1");
$receta_row->execute([$id_producto]);
$id_receta_actual = $receta_row->fetchColumn() ?: 0;

$ingredientes = [];
if ($id_receta_actual) {
    $stmt = $pdo->prepare("
        SELECT ri.*, i.nombre AS nombre_insumo, i.unidad_medida, i.es_harina,
               CASE WHEN i.unidad_medida IN ('kg','L') THEN ri.cantidad*1000 ELSE ri.cantidad END AS cant_mostrar
        FROM receta_ingrediente ri
        INNER JOIN insumo i ON i.id_insumo=ri.id_insumo
        WHERE ri.id_receta=? ORDER BY i.nombre
    ");
    $stmt->execute([$id_receta_actual]);
    $ingredientes = $stmt->fetchAll();
}

$todos_insumos = $pdo->query("SELECT id_insumo, nombre, unidad_medida, es_harina FROM insumo WHERE activo=1 ORDER BY nombre")->fetchAll();

$page_title = 'Receta — ' . $producto['nombre'];
require_once __DIR__ . '/../../views/layouts/header.php';
?>
<style>
  :root{--c1:#945b35;--c2:#c8956e;--c3:#c67124;--c4:#e4a565;--c5:#ecc198;--cbg:#faf3ea;--ccard:#fff;--clight:#fdf6ee;--ink:#281508;--ink2:#6b3d1e;--ink3:#b87a4a;--border:rgba(148,91,53,.12);--shadow:0 1px 8px rgba(148,91,53,.09);--shadow2:0 4px 20px rgba(148,91,53,.15);--nav-h:64px;}
  @keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
  @keyframes gradAnim{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}
  @keyframes modalIn{from{opacity:0;transform:translateY(18px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}

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
  .mod-sub{font-size:.75rem;color:var(--ink3);font-weight:500;background:var(--clight);border:1px solid var(--border);border-radius:8px;padding:.3rem .75rem;}
  .btn-back{background:var(--ccard);color:var(--ink2);border:1px solid var(--border);border-radius:10px;padding:.45rem .9rem;font-size:.82rem;font-weight:600;display:inline-flex;align-items:center;gap:.4rem;text-decoration:none;transition:all .2s;}
  .btn-back:hover{background:var(--clight);border-color:var(--c3);color:var(--ink);}
  .card{background:var(--ccard);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);display:flex;flex-direction:column;overflow:hidden;min-height:0;animation:fadeUp .4s ease both;}
  .ch{display:flex;align-items:center;justify-content:space-between;padding:.8rem 1.1rem;flex-shrink:0;border-bottom:1px solid var(--border);}
  .ch-left{display:flex;align-items:center;gap:.5rem;}
  .ch-ico{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1rem;}
  .ico-pur{background:rgba(103,58,183,.1);color:#673ab7;}
  .ch-title{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.17em;color:var(--ink3);}
  .badge{display:inline-flex;align-items:center;font-size:.62rem;font-weight:700;padding:.15rem .5rem;border-radius:20px;}
  .b-neu{background:var(--clight);color:var(--c1);border:1px solid var(--border);}
  /* TABLA */
  .tbl-wrap{overflow-y:auto;overflow-x:auto;flex:1;min-height:0;max-height:calc(100vh - 380px);}
  .gt{width:100%;border-collapse:collapse;}
  .gt th{font-size:.61rem;text-transform:uppercase;letter-spacing:.1em;color:var(--ink3);font-weight:700;padding:.55rem .85rem;background:var(--clight);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:1;white-space:nowrap;}
  .gt td{font-size:.82rem;color:var(--ink);padding:.5rem .85rem;border-bottom:1px solid rgba(148,91,53,.05);vertical-align:middle;}
  .gt tr:last-child td{border-bottom:none;}
  .gt tr:hover td{background:rgba(250,243,234,.35);}
  /* PICKER BUTTON — reemplaza el select nativo */
  .ing-picker-btn{
    display:flex;align-items:center;gap:.5rem;
    width:100%;min-height:36px;
    background:var(--ccard);
    border:1px solid var(--border);
    border-radius:9px;
    padding:.35rem .65rem;
    cursor:pointer;font-family:inherit;
    text-align:left;transition:all .2s;
    color:var(--ink);
  }
  .ing-picker-btn:hover{border-color:var(--c3);background:var(--clight);}
  .ing-picker-btn.seleccionado{border-color:var(--c3);background:rgba(198,113,36,.05);}
  .ing-picker-btn.vacio{color:var(--ink3);}
  .picker-ico{font-size:.8rem;opacity:.5;flex-shrink:0;}
  .picker-nombre{font-size:.82rem;font-weight:600;flex:1;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;}
  .picker-tag{font-size:.55rem;font-weight:700;padding:.06rem .32rem;border-radius:20px;flex-shrink:0;}
  .tag-harina{background:rgba(198,113,36,.12);color:var(--c3);border:1px solid rgba(198,113,36,.2);}
  .tag-unidad{background:var(--clight);color:var(--ink3);border:1px solid var(--border);}
  /* INPUTS */
  .inp-cant{width:100px;border:1px solid var(--border);border-radius:8px;padding:.38rem .65rem;font-size:.82rem;color:var(--ink);font-family:inherit;background:var(--ccard);text-align:right;transition:border-color .2s;}
  .inp-cant:focus{outline:none;border-color:var(--c3);box-shadow:0 0 0 2px rgba(198,113,36,.1);}
  .inp-nota{width:100%;border:1px solid var(--border);border-radius:8px;padding:.38rem .65rem;font-size:.78rem;color:var(--ink);font-family:inherit;background:var(--ccard);}
  .inp-nota:focus{outline:none;border-color:var(--c3);}
  .lbl-unidad{font-size:.72rem;font-weight:700;color:var(--ink3);min-width:28px;display:inline-block;}
  .chk-merma{width:16px;height:16px;cursor:pointer;accent-color:var(--c3);}
  /* FOOTER */
  #form-receta{display:flex;flex-direction:column;flex:1;min-height:0;}
  .card-foot{border-top:1px solid var(--border);padding:.8rem 1.1rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;gap:.6rem;flex-wrap:wrap;background:var(--ccard);}
  .btn-agregar{background:var(--clight);color:var(--ink2);border:1.5px dashed var(--border);border-radius:9px;padding:.45rem .9rem;font-size:.82rem;font-weight:600;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:.35rem;transition:all .2s;}
  .btn-agregar:hover{border-color:var(--c3);color:var(--c3);background:rgba(198,113,36,.04);}
  .btn-guardar{background:linear-gradient(135deg,var(--c3),var(--c1));color:#fff;border:none;border-radius:10px;padding:.55rem 1.4rem;font-size:.88rem;font-weight:700;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px rgba(198,113,36,.3);display:inline-flex;align-items:center;gap:.4rem;transition:all .2s;}
  .btn-guardar:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(198,113,36,.4);}
  .btn-del-row{background:rgba(229,57,53,.07);border:1px solid rgba(229,57,53,.18);color:#c62828;border-radius:7px;width:28px;height:28px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.85rem;transition:all .18s;flex-shrink:0;}
  .btn-del-row:hover{background:rgba(229,57,53,.18);}
  .msg-err-list{background:#ffebee;border:1px solid #ef9a9a;border-left:3px solid #c62828;border-radius:10px;padding:.7rem 1rem;font-size:.82rem;color:#c62828;margin:.6rem 1rem;flex-shrink:0;}

  /* ══ MODAL PICKER ══ */
  .modal-backdrop{
    position:fixed;inset:0;z-index:9000;
    background:rgba(20,8,2,.45);
    backdrop-filter:blur(3px);
    display:flex;align-items:center;justify-content:center;
    padding:1rem;
  }
  .modal-box{
    background:var(--ccard);
    border-radius:18px;
    box-shadow:0 24px 60px rgba(40,15,0,.3);
    width:100%;max-width:520px;
    max-height:80vh;
    display:flex;flex-direction:column;
    animation:modalIn .22s cubic-bezier(.34,1.36,.64,1) both;
  }
  .modal-hdr{
    display:flex;align-items:center;justify-content:space-between;
    padding:.9rem 1.1rem;
    border-bottom:1px solid var(--border);
    flex-shrink:0;
  }
  .modal-titulo{font-family:'Fraunces',serif;font-size:1.05rem;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:.45rem;}
  .modal-titulo i{color:var(--c3);}
  .modal-cerrar{background:var(--clight);border:1px solid var(--border);border-radius:8px;width:30px;height:30px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--ink3);font-size:1rem;transition:all .18s;}
  .modal-cerrar:hover{background:#ffebee;border-color:rgba(198,40,40,.2);color:#c62828;}
  /* Buscador */
  .modal-search-wrap{padding:.7rem 1rem;border-bottom:1px solid var(--border);flex-shrink:0;}
  .modal-search{
    width:100%;border:1px solid var(--border);border-radius:10px;
    padding:.48rem .75rem .48rem 2.2rem;
    font-size:.88rem;font-family:inherit;color:var(--ink);
    background:var(--clight);
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' fill='%23b87a4a' viewBox='0 0 16 16'%3E%3Cpath d='M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.099zm-5.242 1.656a5.5 5.5 0 1 1 0-11 5.5 5.5 0 0 1 0 11z'/%3E%3C/svg%3E");
    background-repeat:no-repeat;
    background-position:.65rem center;
    transition:border-color .2s,box-shadow .2s;
    box-sizing:border-box;
  }
  .modal-search:focus{outline:none;border-color:var(--c3);box-shadow:0 0 0 3px rgba(198,113,36,.1);}
  .modal-search::placeholder{color:var(--ink3);}
  /* Grid de cards */
  .modal-grid-wrap{overflow-y:auto;flex:1;padding:.65rem .85rem .85rem;}
  .modal-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(138px,1fr));gap:.45rem;}
  /* Card de insumo */
  .ins-card{
    background:var(--clight);
    border:1.5px solid var(--border);
    border-radius:11px;
    padding:.6rem .7rem;
    cursor:pointer;
    transition:all .18s;
    display:flex;flex-direction:column;gap:.25rem;
    position:relative;
  }
  .ins-card:hover{background:#fff;border-color:var(--c3);box-shadow:0 3px 12px rgba(148,91,53,.12);transform:translateY(-2px);}
  .ins-card.usado{opacity:.38;pointer-events:none;}
  .ins-card-ico{font-size:1.3rem;line-height:1;margin-bottom:.05rem;}
  .ins-card-nombre{font-size:.82rem;font-weight:700;color:var(--ink);line-height:1.2;}
  .ins-card-meta{display:flex;align-items:center;gap:.3rem;flex-wrap:wrap;margin-top:.1rem;}
  .ins-card-tag{font-size:.56rem;font-weight:700;padding:.05rem .32rem;border-radius:20px;}
  .ins-card-tag.und{background:var(--ccard);color:var(--ink3);border:1px solid var(--border);}
  .ins-card-tag.har{background:rgba(198,113,36,.12);color:var(--c3);border:1px solid rgba(198,113,36,.2);}
  /* Checkmark seleccionado */
  .ins-card.activo{background:#fff;border-color:var(--c3);box-shadow:0 0 0 3px rgba(198,113,36,.1);}
  .ins-card.activo::after{
    content:'✓';
    position:absolute;top:.3rem;right:.4rem;
    font-size:.7rem;font-weight:800;color:var(--c3);
  }
  .modal-empty{padding:2rem;text-align:center;color:var(--ink3);font-size:.85rem;}
  .modal-empty i{font-size:1.8rem;opacity:.25;display:block;margin-bottom:.4rem;}
  @media(max-width:768px){.page{height:auto;overflow:visible;margin-top:60px;}.tbl-wrap{max-height:60vh;}.modal-grid{grid-template-columns:repeat(auto-fill,minmax(110px,1fr));}}
</style>

<div class="page">

  <!-- BANNER -->
  <div class="wc-banner">
    <div class="wc-left">
      <div>
        <div class="wc-greeting">Panadería BreakControl</div>
        <div class="wc-name">Editar <em>Receta</em></div>
        <div class="wc-sub"><?= htmlspecialchars($producto['nombre']) ?> · <?= $producto['unidad_produccion'] ?></div>
      </div>
    </div>
    <div class="wc-pills">
      <div class="wc-pill <?= !empty($ingredientes) ? 'ok' : '' ?>">
        <div class="wc-pill-num"><?= count($ingredientes) ?></div>
        <div class="wc-pill-lbl">Ingredientes</div>
      </div>
      <div class="wc-pill">
        <div class="wc-pill-num">$<?= number_format($producto['precio_venta'],0,',','.') ?></div>
        <div class="wc-pill-lbl">Precio venta</div>
      </div>
    </div>
  </div>

  <!-- TOPBAR -->
  <div class="topbar">
    <div style="display:flex;align-items:center;gap:.7rem;flex-wrap:wrap;">
      <div class="mod-titulo"><i class="bi bi-journal-text"></i> <?= htmlspecialchars($producto['nombre']) ?></div>
      <?php if ($producto['cantidad_por_tanda'] > 0): ?>
      <span class="mod-sub">Rinde <?= $producto['cantidad_por_tanda'] ?> unidades por tanda</span>
      <?php endif; ?>
    </div>
    <a href="index.php" class="btn-back"><i class="bi bi-arrow-left"></i> Volver</a>
  </div>

  <!-- CARD -->
  <div class="card">
    <div class="ch">
      <div class="ch-left">
        <div class="ch-ico ico-pur"><i class="bi bi-list-check"></i></div>
        <span class="ch-title">Ingredientes de la receta</span>
      </div>
      <span class="badge b-neu" id="badge-count"><?= count($ingredientes) ?> ingredientes</span>
    </div>

    <?php if (!empty($errores)): ?>
    <div class="msg-err-list">
      <strong><i class="bi bi-exclamation-triangle-fill"></i> Errores:</strong>
      <ul style="margin:.3rem 0 0 1.2rem;padding:0"><?php foreach ($errores as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    <form method="post" id="form-receta">
      <div class="tbl-wrap">
        <table class="gt">
          <thead>
            <tr>
              <th style="width:36%">Ingrediente</th>
              <th style="width:17%">Cantidad</th>
              <th style="width:7%">Unidad</th>
              <th style="width:8%;text-align:center">Merma</th>
              <th>Nota</th>
              <th style="width:36px"></th>
            </tr>
          </thead>
          <tbody id="tbody-ing">
          <?php
          $ids_usados = array_column($ingredientes, 'id_insumo');
          if (empty($ingredientes)):
              echo filaVacia($todos_insumos, [], 0);
          else:
              foreach ($ingredientes as $idx => $ing):
                  $label = in_array($ing['unidad_medida'],['kg','L']) ? 'g'
                         : ($ing['unidad_medida']==='unidad' ? 'unid.' : $ing['unidad_medida']);
          ?>
          <tr class="fila-ing" data-id="<?= $ing['id_insumo'] ?>">
            <td>
              <!-- Hidden input para el POST -->
              <input type="hidden" name="id_insumo[]" value="<?= $ing['id_insumo'] ?>" class="hid-id">
              <!-- Botón visual picker -->
              <button type="button" class="ing-picker-btn seleccionado"
                      data-id="<?= $ing['id_insumo'] ?>"
                      onclick="abrirModal(this)">
                <i class="bi bi-bag-fill picker-ico" style="color:var(--c3)"></i>
                <span class="picker-nombre"><?= htmlspecialchars($ing['nombre_insumo']) ?></span>
                <?php if ($ing['es_harina']): ?>
                <span class="picker-tag tag-harina">🌾 harina</span>
                <?php endif; ?>
                <span class="picker-tag tag-unidad"><?= $label ?></span>
                <i class="bi bi-chevron-down" style="font-size:.65rem;opacity:.4;margin-left:auto"></i>
              </button>
            </td>
            <td><input type="number" name="cantidad[]" class="inp-cant" min="0.001" step="0.001" value="<?= rtrim(rtrim(number_format((float)$ing['cant_mostrar'],4,'.',''),'0'),'.') ?>""></td>
            <td><span class="lbl-unidad"><?= $label ?></span></td>
            <td style="text-align:center"><input type="checkbox" name="aplica_merma[<?= $idx ?>]" class="chk-merma" <?= $ing['aplica_merma']?'checked':'' ?>></td>
            <td><input type="text" name="notas[]" class="inp-nota" placeholder="Notas…" value="<?= htmlspecialchars($ing['notas'] ?? '') ?>"></td>
            <td><button type="button" class="btn-del-row" onclick="eliminarFila(this)"><i class="bi bi-trash3"></i></button></td>
          </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <div class="card-foot">
        <button type="button" class="btn-agregar" onclick="agregarFila()">
          <i class="bi bi-plus-lg"></i> Agregar ingrediente
        </button>
        <button type="submit" class="btn-guardar">
          <i class="bi bi-check-lg"></i> Guardar receta
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ══ MODAL PICKER ══ -->
<div class="modal-backdrop" id="modal-picker" style="display:none" onclick="cerrarModalFuera(event)">
  <div class="modal-box">
    <div class="modal-hdr">
      <div class="modal-titulo"><i class="bi bi-bag-heart-fill"></i> Seleccionar ingrediente</div>
      <button type="button" class="modal-cerrar" onclick="cerrarModal()"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="modal-search-wrap">
      <input type="text" class="modal-search" id="modal-buscar"
             placeholder="Buscar ingrediente…"
             oninput="filtrarCards(this.value)"
             autocomplete="off">
    </div>
    <div class="modal-grid-wrap">
      <div class="modal-grid" id="modal-grid"></div>
      <div class="modal-empty" id="modal-empty" style="display:none">
        <i class="bi bi-search"></i>
        No hay ingredientes que coincidan
      </div>
    </div>
  </div>
</div>

<?php
function filaVacia($insumos, $usados = [], $idx = 0) {
    ob_start(); ?>
    <tr class="fila-ing" data-id="">
      <td>
        <input type="hidden" name="id_insumo[]" value="" class="hid-id">
        <button type="button" class="ing-picker-btn vacio" onclick="abrirModal(this)">
          <i class="bi bi-plus-circle picker-ico"></i>
          <span class="picker-nombre" style="color:var(--ink3)">— Seleccionar ingrediente —</span>
          <i class="bi bi-chevron-down" style="font-size:.65rem;opacity:.35;margin-left:auto"></i>
        </button>
      </td>
      <td><input type="number" name="cantidad[]" class="inp-cant" min="0.001" step="0.001" placeholder="0"></td>
      <td><span class="lbl-unidad">g</span></td>
      <td style="text-align:center"><input type="checkbox" name="aplica_merma[]" class="chk-merma"></td>
      <td><input type="text" name="notas[]" class="inp-nota" placeholder="Notas…"></td>
      <td><button type="button" class="btn-del-row" onclick="eliminarFila(this)"><i class="bi bi-trash3"></i></button></td>
    </tr>
    <?php return ob_get_clean();
}
?>

<script>
// Datos de todos los insumos
const insumosData = <?= json_encode(array_map(fn($i) => [
    'id'     => $i['id_insumo'],
    'nombre' => $i['nombre'],
    'unidad' => $i['unidad_medida'],
    'harina' => (bool)$i['es_harina'],
], $todos_insumos)) ?>;

// Íconos por tipo de insumo (por nombre clave)
function getIco(nombre) {
    const n = nombre.toLowerCase();
    if (n.includes('harina'))    return '🌾';
    if (n.includes('azuc'))      return '🍬';
    if (n.includes('sal'))       return '🧂';
    if (n.includes('levadura'))  return '🧫';
    if (n.includes('huevo'))     return '🥚';
    if (n.includes('leche'))     return '🥛';
    if (n.includes('mantequi'))  return '🧈';
    if (n.includes('aceite'))    return '🫙';
    if (n.includes('esencia'))   return '💧';
    if (n.includes('manjar') || n.includes('arequipe')) return '🍯';
    return '🥄';
}

function getLabelUnidad(und) {
    if (und === 'kg' || und === 'L') return 'g';
    if (und === 'unidad') return 'unid.';
    return und;
}

// ── Referencia a la fila que abrió el modal ──
let filaActiva = null;

function abrirModal(btn) {
    filaActiva = btn.closest('tr');
    const idActual = filaActiva.dataset.id;

    // IDs ya usados en OTRAS filas
    const usados = [...document.querySelectorAll('.fila-ing')]
        .filter(tr => tr !== filaActiva)
        .map(tr => tr.dataset.id)
        .filter(Boolean);

    // Construir cards
    renderCards(insumosData, usados, idActual);
    document.getElementById('modal-buscar').value = '';
    document.getElementById('modal-picker').style.display = 'flex';
    setTimeout(() => document.getElementById('modal-buscar').focus(), 120);
}

function renderCards(lista, usados, idActual, filtro = '') {
    const grid = document.getElementById('modal-grid');
    const empty = document.getElementById('modal-empty');
    const q = filtro.toLowerCase().trim();
    const filtrados = lista.filter(i => !q || i.nombre.toLowerCase().includes(q));

    grid.innerHTML = '';
    if (filtrados.length === 0) {
        empty.style.display = 'block';
        return;
    }
    empty.style.display = 'none';

    filtrados.forEach(ins => {
        const card = document.createElement('div');
        card.className = 'ins-card'
            + (usados.includes(String(ins.id)) ? ' usado' : '')
            + (String(ins.id) === String(idActual) ? ' activo' : '');
        card.dataset.id = ins.id;

        const lbl = getLabelUnidad(ins.unidad);
        card.innerHTML = `
            <div class="ins-card-ico">${getIco(ins.nombre)}</div>
            <div class="ins-card-nombre">${ins.nombre}</div>
            <div class="ins-card-meta">
                <span class="ins-card-tag und">${lbl}</span>
                ${ins.harina ? '<span class="ins-card-tag har">🌾 harina</span>' : ''}
            </div>`;
        card.onclick = () => seleccionarInsumo(ins);
        grid.appendChild(card);
    });
}

function filtrarCards(q) {
    const idActual = filaActiva ? filaActiva.dataset.id : '';
    const usados = [...document.querySelectorAll('.fila-ing')]
        .filter(tr => tr !== filaActiva)
        .map(tr => tr.dataset.id).filter(Boolean);
    renderCards(insumosData, usados, idActual, q);
}

function seleccionarInsumo(ins) {
    if (!filaActiva) return;

    const lbl = getLabelUnidad(ins.unidad);
    const btn = filaActiva.querySelector('.ing-picker-btn');
    const hid = filaActiva.querySelector('.hid-id');
    const lblUnidad = filaActiva.querySelector('.lbl-unidad');

    // Actualizar el hidden input
    hid.value = ins.id;
    filaActiva.dataset.id = ins.id;

    // Actualizar el botón visual
    btn.className = 'ing-picker-btn seleccionado';
    btn.innerHTML = `
        <i class="bi bi-bag-fill picker-ico" style="color:var(--c3)"></i>
        <span class="picker-nombre">${ins.nombre}</span>
        ${ins.harina ? '<span class="picker-tag tag-harina">🌾 harina</span>' : ''}
        <span class="picker-tag tag-unidad">${lbl}</span>
        <i class="bi bi-chevron-down" style="font-size:.65rem;opacity:.4;margin-left:auto"></i>`;
    btn.onclick = () => abrirModal(btn);

    // Actualizar etiqueta de unidad en la fila
    if (lblUnidad) lblUnidad.textContent = lbl;

    cerrarModal();
    actualizarCount();
}

function cerrarModal() {
    document.getElementById('modal-picker').style.display = 'none';
    filaActiva = null;
}

function cerrarModalFuera(e) {
    if (e.target === document.getElementById('modal-picker')) cerrarModal();
}

// Cerrar con Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') cerrarModal();
});

// ── Agregar / eliminar filas ──
let contadorFilas = <?= count($ingredientes) ?>;

function agregarFila() {
    const usados = [...document.querySelectorAll('.fila-ing')]
        .map(tr => tr.dataset.id).filter(Boolean);

    const tr = document.createElement('tr');
    tr.className = 'fila-ing';
    tr.dataset.id = '';
    tr.innerHTML = `
        <td>
          <input type="hidden" name="id_insumo[]" value="" class="hid-id">
          <button type="button" class="ing-picker-btn vacio" onclick="abrirModal(this)">
            <i class="bi bi-plus-circle picker-ico"></i>
            <span class="picker-nombre" style="color:var(--ink3)">— Seleccionar ingrediente —</span>
            <i class="bi bi-chevron-down" style="font-size:.65rem;opacity:.35;margin-left:auto"></i>
          </button>
        </td>
        <td><input type="number" name="cantidad[]" class="inp-cant" min="0.001" step="0.001" placeholder="0"></td>
        <td><span class="lbl-unidad">g</span></td>
        <td style="text-align:center"><input type="checkbox" name="aplica_merma[]" class="chk-merma"></td>
        <td><input type="text" name="notas[]" class="inp-nota" placeholder="Notas…"></td>
        <td><button type="button" class="btn-del-row" onclick="eliminarFila(this)"><i class="bi bi-trash3"></i></button></td>`;
    document.getElementById('tbody-ing').appendChild(tr);
    actualizarCount();
    // Abrir el modal automáticamente en la nueva fila
    abrirModal(tr.querySelector('.ing-picker-btn'));
}

function eliminarFila(btn) {
    const filas = document.querySelectorAll('.fila-ing');
    if (filas.length <= 1) { alert('Debe haber al menos un ingrediente.'); return; }
    btn.closest('tr').remove();
    actualizarCount();
}

function actualizarCount() {
    const n = document.querySelectorAll('.fila-ing').length;
    document.getElementById('badge-count').textContent = n + ' ingrediente' + (n!==1?'s':'');
}
</script>
</body></html>
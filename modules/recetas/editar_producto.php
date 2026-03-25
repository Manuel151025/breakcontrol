<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/sesion.php';
require_once __DIR__ . '/../../includes/funciones.php';

requerirPropietario();
$pdo        = getConexion();
$id_producto = (int)($_GET['id'] ?? 0);
$errores    = [];
$msg_ok     = '';

// Cargar producto
$stmt = $pdo->prepare("SELECT * FROM producto WHERE id_producto=? AND activo=1");
$stmt->execute([$id_producto]);
$producto = $stmt->fetch();
if (!$producto) { header('Location: index.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre         = trim($_POST['nombre']              ?? '');
    $categoria      = in_array($_POST['categoria'] ?? '', ['sal','dulce','especial']) ? $_POST['categoria'] : 'sal';
    $unidad         = trim($_POST['unidad_produccion']   ?? '');
    $cantidad_tanda = (int)($_POST['cantidad_por_tanda'] ?? 0);
    $precio_venta   = (float)str_replace(['.','$',' '], '', $_POST['precio_venta'] ?? 0);

    if (empty($nombre)) $errores[] = 'El nombre es obligatorio.';
    if (empty($unidad)) $errores[] = 'La unidad de producción es obligatoria.';

    if (empty($errores)) {
        $pdo->prepare("UPDATE producto SET nombre=?,categoria=?,unidad_produccion=?,cantidad_por_tanda=?,precio_venta=? WHERE id_producto=?")
            ->execute([$nombre, $categoria, $unidad, $cantidad_tanda, $precio_venta, $id_producto]);
        // Recargar
        $stmt->execute([$id_producto]);
        $producto = $stmt->fetch();
        $msg_ok   = 'Producto actualizado correctamente.';
    }
}

// Verificar si tiene receta
$id_receta = $pdo->prepare("SELECT id_receta FROM receta WHERE id_producto=? AND es_vigente=1 LIMIT 1");
$id_receta->execute([$id_producto]);
$tiene_receta = $id_receta->fetchColumn();

$page_title = 'Editar — ' . $producto['nombre'];
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
  .top-right{display:flex;gap:.5rem;align-items:center;}
  .btn-back{background:var(--ccard);color:var(--ink2);border:1px solid var(--border);border-radius:10px;padding:.45rem .9rem;font-size:.82rem;font-weight:600;display:inline-flex;align-items:center;gap:.4rem;text-decoration:none;transition:all .2s;}
  .btn-back:hover{background:var(--clight);border-color:var(--c3);color:var(--ink);}
  .btn-receta{background:linear-gradient(135deg,#7b1fa2,#6a1b9a);color:#fff;border:none;border-radius:10px;padding:.45rem .9rem;font-size:.82rem;font-weight:700;display:inline-flex;align-items:center;gap:.4rem;text-decoration:none;transition:all .2s;box-shadow:0 3px 10px rgba(103,58,183,.3);}
  .btn-receta:hover{transform:translateY(-1px);box-shadow:0 5px 16px rgba(103,58,183,.4);color:#fff;}
  .center-wrap{display:flex;align-items:flex-start;justify-content:center;padding:.5rem;overflow-y:auto;}
  .form-card{background:var(--ccard);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);width:100%;max-width:540px;overflow:hidden;animation:fadeUp .4s ease both;}
  .ch{display:flex;align-items:center;gap:.5rem;padding:.8rem 1.1rem;border-bottom:1px solid var(--border);}
  .ch-ico{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1rem;background:rgba(25,118,210,.1);color:#1565c0;}
  .ch-title{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.17em;color:var(--ink3);}
  .form-body{padding:1.1rem 1.2rem;}
  .fl{margin-bottom:.75rem;}
  .fl label{font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:var(--ink3);display:block;margin-bottom:.3rem;}
  .fl input,.fl select{width:100%;border:1px solid var(--border);border-radius:9px;padding:.5rem .8rem;font-size:.85rem;color:var(--ink);font-family:inherit;background:var(--clight);transition:border-color .2s,box-shadow .2s;}
  .fl input:focus,.fl select:focus{outline:none;border-color:var(--c3);box-shadow:0 0 0 3px rgba(198,113,36,.1);}
  .fl-hint{font-size:.67rem;color:var(--ink3);margin-top:.25rem;}
  .fl-row{display:grid;grid-template-columns:1fr 1fr;gap:.55rem;}
  .cat-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:.5rem;}
  .cat-opt{position:relative;}
  .cat-opt input{position:absolute;opacity:0;width:0;height:0;}
  .cat-lbl{display:flex;flex-direction:column;align-items:center;gap:.3rem;padding:.65rem .4rem;border:1.5px solid var(--border);border-radius:10px;cursor:pointer;transition:all .2s;font-size:.8rem;font-weight:600;color:var(--ink3);text-align:center;background:var(--clight);}
  .cat-lbl i{font-size:1.25rem;}
  .cat-opt input:checked + .cat-lbl{border-color:var(--c3);background:rgba(198,113,36,.07);color:var(--c1);box-shadow:0 0 0 3px rgba(198,113,36,.1);}
  .btn-guardar{width:100%;background:linear-gradient(135deg,var(--c3),var(--c1));color:#fff;border:none;border-radius:10px;padding:.72rem;font-size:.9rem;font-weight:700;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px rgba(198,113,36,.3);display:flex;align-items:center;justify-content:center;gap:.4rem;transition:all .2s;margin-top:.4rem;}
  .btn-guardar:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(198,113,36,.4);}
  .msg-ok{background:#e8f5e9;border:1px solid #a5d6a7;border-left:3px solid #2e7d32;border-radius:10px;padding:.6rem .9rem;font-size:.82rem;color:#1b5e20;font-weight:600;margin-bottom:.75rem;display:flex;align-items:center;gap:.4rem;}
  .msg-err{background:#ffebee;border:1px solid #ef9a9a;border-left:3px solid #c62828;border-radius:10px;padding:.6rem .9rem;font-size:.82rem;color:#c62828;margin-bottom:.75rem;}
  .msg-err ul{margin:.25rem 0 0 1.1rem;padding:0;}
  .btn-ir-receta{display:flex;align-items:center;justify-content:center;gap:.4rem;width:100%;padding:.55rem;border:1.5px dashed rgba(103,58,183,.3);border-radius:10px;color:#673ab7;font-size:.82rem;font-weight:700;text-decoration:none;background:rgba(103,58,183,.04);margin-top:.5rem;transition:all .2s;}
  .btn-ir-receta:hover{background:rgba(103,58,183,.1);border-color:#673ab7;}
  @media(max-width:768px){.page{height:auto;overflow:visible;margin-top:60px;}}
</style>

<div class="page">
  <div class="wc-banner">
    <div class="wc-left">
      <div>
        <div class="wc-greeting">Panadería BreakControl</div>
        <div class="wc-name">Editar <em>Producto</em></div>
        <div class="wc-sub"><?= htmlspecialchars($producto['nombre']) ?></div>
      </div>
    </div>
    <div class="wc-pills">
      <div class="wc-pill <?= $tiene_receta ? 'ok' : '' ?>">
        <div class="wc-pill-num"><?= $tiene_receta ? '✓' : '—' ?></div>
        <div class="wc-pill-lbl"><?= $tiene_receta ? 'Con receta' : 'Sin receta' ?></div>
      </div>
      <div class="wc-pill">
        <div class="wc-pill-num">$<?= number_format($producto['precio_venta'], 0, ',', '.') ?></div>
        <div class="wc-pill-lbl">Precio venta</div>
      </div>
    </div>
  </div>

  <div class="topbar">
    <div class="mod-titulo"><i class="bi bi-pencil-square"></i> <?= htmlspecialchars($producto['nombre']) ?></div>
    <div class="top-right">
      <a href="editar_receta.php?id=<?= $id_producto ?>" class="btn-receta">
        <i class="bi bi-journal-plus"></i> <?= $tiene_receta ? 'Ver receta' : 'Crear receta' ?>
      </a>
      <a href="index.php" class="btn-back"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>
  </div>

  <div class="center-wrap">
    <div class="form-card">
      <div class="ch">
        <div class="ch-ico"><i class="bi bi-pencil-fill"></i></div>
        <span class="ch-title">Datos del producto</span>
      </div>
      <div class="form-body">
        <?php if ($msg_ok): ?><div class="msg-ok"><i class="bi bi-check-circle-fill"></i><?= $msg_ok ?></div><?php endif; ?>
        <?php if (!empty($errores)): ?>
        <div class="msg-err"><ul><?php foreach ($errores as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

        <form method="post">
          <div class="fl">
            <label>Categoría</label>
            <div class="cat-row">
              <?php foreach(['sal'=>['bi-slash-circle','Sal'],'dulce'=>['bi-heart','Dulce'],'especial'=>['bi-star','Especial']] as $val => [$ico, $lbl]): ?>
              <div class="cat-opt">
                <input type="radio" name="categoria" id="c-<?= $val ?>" value="<?= $val ?>"
                  <?= ($producto['categoria'] ?? 'sal') === $val ? 'checked' : '' ?>>
                <label class="cat-lbl" for="c-<?= $val ?>"><i class="bi <?= $ico ?>"></i><?= $lbl ?></label>
              </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="fl">
            <label>Nombre <span style="color:#c62828">*</span></label>
            <input type="text" name="nombre" required value="<?= htmlspecialchars($producto['nombre']) ?>">
          </div>

          <div class="fl-row">
            <div class="fl">
              <label>Unidad de producción <span style="color:#c62828">*</span></label>
              <select name="unidad_produccion" required>
                <option value="">— Seleccionar —</option>
                <?php foreach(['lata','carro','unidad'] as $u): ?>
                <option value="<?= $u ?>" <?= $producto['unidad_produccion'] === $u ? 'selected' : '' ?>><?= ucfirst($u) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="fl">
              <label>Unidades por tanda</label>
              <input type="number" name="cantidad_por_tanda" min="0" step="1"
                     value="<?= $producto['cantidad_por_tanda'] ?? 0 ?>">
              <div class="fl-hint">0 si varía</div>
            </div>
          </div>

          <div class="fl">
            <label>Precio de venta</label>
            <input type="number" name="precio_venta" min="0" step="50"
                   value="<?= $producto['precio_venta'] ?? 0 ?>">
          </div>

          <button type="submit" class="btn-guardar">
            <i class="bi bi-check-lg"></i> Guardar cambios
          </button>
        </form>

        <a href="editar_receta.php?id=<?= $id_producto ?>" class="btn-ir-receta">
          <i class="bi bi-journal-richtext"></i>
          <?= $tiene_receta ? 'Ir a editar la receta' : 'Definir ingredientes de receta' ?>
        </a>
      </div>
    </div>
  </div>
</div>
</body></html>

<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/sesion.php';
require_once __DIR__ . '/../../includes/funciones.php';

requerirPropietario();
$pdo     = getConexion();
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre         = trim($_POST['nombre']              ?? '');
    $categoria      = in_array($_POST['categoria'] ?? '', ['sal','dulce','especial']) ? $_POST['categoria'] : 'sal';
    $unidad         = trim($_POST['unidad_produccion']   ?? '');
    $cantidad_tanda = (int)($_POST['cantidad_por_tanda'] ?? 0);
    $precio_venta   = (float)str_replace(['.','$',' '], '', $_POST['precio_venta'] ?? 0);

    if (empty($nombre))  $errores[] = 'El nombre es obligatorio.';
    if (empty($unidad))  $errores[] = 'La unidad de producción es obligatoria.';

    if (empty($errores)) {
        try {
            $pdo->prepare("INSERT INTO producto (nombre,categoria,unidad_produccion,cantidad_por_tanda,precio_venta,activo,fecha_creacion) VALUES (?,?,?,?,?,1,NOW())")
                ->execute([$nombre, $categoria, $unidad, $cantidad_tanda, $precio_venta]);
            $id_nuevo = $pdo->lastInsertId();
            header('Location: editar_receta.php?id=' . $id_nuevo); exit;
        } catch (PDOException $e) {
            $errores[] = 'Error al guardar el producto. Intenta de nuevo.';
        }
    }
}

$page_title = 'Nuevo Producto';
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
  .topbar{display:flex;align-items:center;justify-content:space-between;gap:.75rem;}
  .mod-titulo{font-family:'Fraunces',serif;font-size:1.45rem;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:.5rem;}
  .mod-titulo i{color:var(--c3);}
  .btn-back{background:var(--ccard);color:var(--ink2);border:1px solid var(--border);border-radius:10px;padding:.45rem .9rem;font-size:.82rem;font-weight:600;display:inline-flex;align-items:center;gap:.4rem;text-decoration:none;transition:all .2s;}
  .btn-back:hover{background:var(--clight);border-color:var(--c3);color:var(--ink);}
  .center-wrap{display:flex;align-items:flex-start;justify-content:center;padding:.5rem;overflow-y:auto;}
  .form-card{background:var(--ccard);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);width:100%;max-width:540px;overflow:hidden;animation:fadeUp .4s ease both;}
  .ch{display:flex;align-items:center;gap:.5rem;padding:.8rem 1.1rem;border-bottom:1px solid var(--border);}
  .ch-ico{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1rem;background:rgba(198,113,36,.1);color:var(--c3);}
  .ch-title{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.17em;color:var(--ink3);}
  .form-body{padding:1.1rem 1.2rem;}
  .fl{margin-bottom:.75rem;}
  .fl label{font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:var(--ink3);display:block;margin-bottom:.3rem;}
  .fl input,.fl select{width:100%;border:1px solid var(--border);border-radius:9px;padding:.5rem .8rem;font-size:.85rem;color:var(--ink);font-family:inherit;background:var(--clight);transition:border-color .2s,box-shadow .2s;}
  .fl input:focus,.fl select:focus{outline:none;border-color:var(--c3);box-shadow:0 0 0 3px rgba(198,113,36,.1);}
  .fl-hint{font-size:.67rem;color:var(--ink3);margin-top:.25rem;}
  .fl-row{display:grid;grid-template-columns:1fr 1fr;gap:.55rem;}
  /* CATEGORÍA */
  .cat-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:.5rem;}
  .cat-opt{position:relative;}
  .cat-opt input{position:absolute;opacity:0;width:0;height:0;}
  .cat-lbl{display:flex;flex-direction:column;align-items:center;gap:.3rem;padding:.65rem .4rem;border:1.5px solid var(--border);border-radius:10px;cursor:pointer;transition:all .2s;font-size:.8rem;font-weight:600;color:var(--ink3);text-align:center;background:var(--clight);}
  .cat-lbl i{font-size:1.25rem;}
  .cat-opt input:checked + .cat-lbl{border-color:var(--c3);background:rgba(198,113,36,.07);color:var(--c1);box-shadow:0 0 0 3px rgba(198,113,36,.1);}
  .btn-guardar{width:100%;background:linear-gradient(135deg,var(--c3),var(--c1));color:#fff;border:none;border-radius:10px;padding:.72rem;font-size:.9rem;font-weight:700;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px rgba(198,113,36,.3);display:flex;align-items:center;justify-content:center;gap:.4rem;transition:all .2s;margin-top:.4rem;}
  .btn-guardar:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(198,113,36,.4);}
  .msg-err{background:#ffebee;border:1px solid #ef9a9a;border-left:3px solid #c62828;border-radius:10px;padding:.6rem .9rem;font-size:.82rem;color:#c62828;margin-bottom:.75rem;}
  .msg-err ul{margin:.25rem 0 0 1.1rem;padding:0;}
  .nota-info{background:rgba(198,113,36,.06);border:1px solid rgba(198,113,36,.18);border-radius:9px;padding:.55rem .85rem;font-size:.78rem;color:var(--ink3);margin-bottom:.8rem;display:flex;align-items:center;gap:.5rem;}
  @media(max-width:768px){.page{height:auto;overflow:visible;margin-top:60px;}}
</style>

<div class="page">
  <div class="wc-banner">
    <div class="wc-left">
      <div>
        <div class="wc-greeting">Panadería BreakControl</div>
        <div class="wc-name">Nuevo <em>Producto</em></div>
        <div class="wc-sub">Luego definirás los ingredientes de la receta</div>
      </div>
    </div>
  </div>

  <div class="topbar">
    <div class="mod-titulo"><i class="bi bi-plus-circle"></i> Nuevo producto</div>
    <a href="index.php" class="btn-back"><i class="bi bi-arrow-left"></i> Volver</a>
  </div>

  <div class="center-wrap">
    <div class="form-card">
      <div class="ch">
        <div class="ch-ico"><i class="bi bi-box-seam"></i></div>
        <span class="ch-title">Datos del producto</span>
      </div>
      <div class="form-body">
        <?php if (!empty($errores)): ?>
        <div class="msg-err"><ul><?php foreach ($errores as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

        <div class="nota-info">
          <i class="bi bi-info-circle-fill" style="color:var(--c3);flex-shrink:0"></i>
          Al guardar irás directo a definir los ingredientes de la receta.
        </div>

        <form method="post">
          <!-- CATEGORÍA -->
          <div class="fl">
            <label>Categoría</label>
            <div class="cat-row">
              <div class="cat-opt">
                <input type="radio" name="categoria" id="c-sal" value="sal"
                  <?= ($_POST['categoria'] ?? 'sal') === 'sal' ? 'checked' : '' ?>>
                <label class="cat-lbl" for="c-sal"><i class="bi bi-slash-circle"></i>Sal</label>
              </div>
              <div class="cat-opt">
                <input type="radio" name="categoria" id="c-dulce" value="dulce"
                  <?= ($_POST['categoria'] ?? '') === 'dulce' ? 'checked' : '' ?>>
                <label class="cat-lbl" for="c-dulce"><i class="bi bi-heart"></i>Dulce</label>
              </div>
              <div class="cat-opt">
                <input type="radio" name="categoria" id="c-especial" value="especial"
                  <?= ($_POST['categoria'] ?? '') === 'especial' ? 'checked' : '' ?>>
                <label class="cat-lbl" for="c-especial"><i class="bi bi-star"></i>Especial</label>
              </div>
            </div>
          </div>

          <!-- NOMBRE -->
          <div class="fl">
            <label>Nombre <span style="color:#c62828">*</span></label>
            <input type="text" name="nombre" placeholder="Ej: Pan integral" required autofocus
                   value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>">
          </div>

          <!-- UNIDAD + TANDA -->
          <div class="fl-row">
            <div class="fl">
              <label>Unidad de producción <span style="color:#c62828">*</span></label>
              <select name="unidad_produccion" required>
                <option value="">— Seleccionar —</option>
                <?php foreach(['unidad'] as $u): ?>
                <option value="<?= $u ?>" <?= ($_POST['unidad_produccion'] ?? '') === $u ? 'selected' : '' ?>><?= ucfirst($u) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="fl-hint">La receta se define por esta unidad</div>
            </div>
            <div class="fl">
              <label>Unidades por tanda</label>
              <input type="number" name="cantidad_por_tanda" min="0" step="1"
                     value="<?= htmlspecialchars($_POST['cantidad_por_tanda'] ?? '0', ENT_QUOTES) ?? 0 ?>" placeholder="0">
              <div class="fl-hint">0 si varía</div>
            </div>
          </div>

          <!-- PRECIO -->
          <div class="fl">
            <label>Precio de venta</label>
            <input type="number" name="precio_venta" min="0" step="50"
                   value="<?= htmlspecialchars($_POST['precio_venta'] ?? '0', ENT_QUOTES) ?? 0 ?>">
            <div class="fl-hint">Deja en 0 si el precio varía</div>
          </div>

          <button type="submit" class="btn-guardar">
            <i class="bi bi-arrow-right"></i> Guardar y definir receta
          </button>
        </form>
      </div>
    </div>
  </div>
</div>
</body></html>

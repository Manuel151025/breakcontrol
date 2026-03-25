<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/sesion.php';
require_once __DIR__ . '/../../includes/funciones.php';

requerirPropietario();

$pdo     = getConexion();
$id      = (int) ($_GET['id'] ?? 0);
$errores = [];

// Obtener insumo
$stmt  = $pdo->prepare("SELECT * FROM insumo WHERE id_insumo = ?");
$stmt->execute([$id]);
$insumo = $stmt->fetch();

if (!$insumo) {
    redirigir(APP_URL . '/modules/inventario/index.php', 'error', 'Insumo no encontrado.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre      = limpiar($_POST['nombre']          ?? '');
    $unidad      = limpiar($_POST['unidad_medida']   ?? '');
    $es_harina   = isset($_POST['es_harina']) ? 1 : 0;
    $punto_repos = (float) ($_POST['punto_reposicion'] ?? 0);
    $activo      = isset($_POST['activo']) ? 1 : 0;

    if (empty($nombre)) $errores[] = 'El nombre es obligatorio.';
    if (empty($unidad)) $errores[] = 'La unidad de medida es obligatoria.';

    // Verificar nombre único (excepto el mismo)
    if (empty($errores)) {
        $check = $pdo->prepare("SELECT id_insumo FROM insumo WHERE nombre = ? AND id_insumo != ?");
        $check->execute([$nombre, $id]);
        if ($check->fetch()) $errores[] = "Ya existe otro insumo con el nombre \"$nombre\".";
    }

    if (empty($errores)) {
        $stmt = $pdo->prepare(
            "UPDATE insumo SET nombre=?, unidad_medida=?, es_harina=?, punto_reposicion=?, activo=?
             WHERE id_insumo=?"
        );
        $stmt->execute([$nombre, $unidad, $es_harina, $punto_repos, $activo, $id]);

        redirigir(
            APP_URL . '/modules/inventario/index.php',
            'exito',
            "Insumo <strong>$nombre</strong> actualizado."
        );
    }

    // Si hay errores, conservar valores del POST
    $insumo = array_merge($insumo, $_POST, ['es_harina' => $es_harina, 'activo' => $activo]);
}

$titulo = 'Editar insumo';
include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="row justify-content-center">
  <div class="col-md-6">

    <div class="d-flex align-items-center mb-4">
      <a href="<?= APP_URL ?>/modules/inventario/index.php" class="btn btn-outline-secondary btn-sm me-3">
        <i class="bi bi-arrow-left"></i> Volver
      </a>
      <h4 class="mb-0"><i class="bi bi-pencil"></i> Editar insumo</h4>
    </div>

    <?php if (!empty($errores)): ?>
    <div class="alert alert-danger">
      <ul class="mb-0"><?php foreach ($errores as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-body">
        <form method="POST">

          <div class="mb-3">
            <label class="form-label fw-semibold">Nombre <span class="text-danger">*</span></label>
            <input type="text" name="nombre" class="form-control"
                   value="<?= htmlspecialchars($insumo['nombre']) ?>" required>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Unidad de medida <span class="text-danger">*</span></label>
            <select name="unidad_medida" class="form-select" required>
              <?php foreach (['kg','g','L','ml','unidad'] as $u): ?>
              <option value="<?= $u ?>" <?= $insumo['unidad_medida'] === $u ? 'selected' : '' ?>><?= $u ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Punto de reposición</label>
            <input type="number" name="punto_reposicion" class="form-control input-cantidad"
                   value="<?= $insumo['punto_reposicion'] ?>" min="0">
          </div>

          <div class="mb-3">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="es_harina" id="es_harina"
                     <?= $insumo['es_harina'] ? 'checked' : '' ?>>
              <label class="form-check-label" for="es_harina">
                Es <strong>harina</strong> (aplica merma del 6%)
              </label>
            </div>
          </div>

          <div class="mb-4">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="activo" id="activo"
                     <?= $insumo['activo'] ? 'checked' : '' ?>>
              <label class="form-check-label" for="activo">Insumo activo</label>
            </div>
          </div>

          <div class="d-grid">
            <button type="submit" class="btn btn-dark">
              <i class="bi bi-check-lg"></i> Guardar cambios
            </button>
          </div>

        </form>
      </div>
    </div>

  </div>
</div>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>

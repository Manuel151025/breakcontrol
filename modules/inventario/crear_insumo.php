<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/sesion.php';
require_once __DIR__ . '/../../includes/funciones.php';

requerirPropietario();

$pdo    = getConexion();
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre          = limpiar($_POST['nombre']          ?? '');
    $unidad          = limpiar($_POST['unidad_medida']   ?? '');
    $es_harina       = isset($_POST['es_harina']) ? 1 : 0;
    $punto_repos     = (float) ($_POST['punto_reposicion'] ?? 0);

    // Validaciones
    if (empty($nombre))  $errores[] = 'El nombre es obligatorio.';
    if (empty($unidad))  $errores[] = 'La unidad de medida es obligatoria.';
    if ($punto_repos < 0) $errores[] = 'El punto de reposición no puede ser negativo.';

    // Verificar nombre único
    if (empty($errores)) {
        $check = $pdo->prepare("SELECT id_insumo FROM insumo WHERE nombre = ?");
        $check->execute([$nombre]);
        if ($check->fetch()) $errores[] = "Ya existe un insumo con el nombre \"$nombre\".";
    }

    if (empty($errores)) {
        $stmt = $pdo->prepare(
            "INSERT INTO insumo (nombre, unidad_medida, es_harina, punto_reposicion)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$nombre, $unidad, $es_harina, $punto_repos]);

        redirigir(
            APP_URL . '/modules/inventario/index.php',
            'exito',
            "Insumo <strong>$nombre</strong> creado correctamente."
        );
    }
}

$titulo = 'Nuevo insumo';
include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="row justify-content-center">
  <div class="col-md-6">

    <div class="d-flex align-items-center mb-4">
      <a href="<?= APP_URL ?>/modules/inventario/index.php" class="btn btn-outline-secondary btn-sm me-3">
        <i class="bi bi-arrow-left"></i> Volver
      </a>
      <h4 class="mb-0"><i class="bi bi-plus-circle"></i> Nuevo insumo</h4>
    </div>

    <?php if (!empty($errores)): ?>
    <div class="alert alert-danger">
      <ul class="mb-0">
        <?php foreach ($errores as $e): ?>
          <li><?= $e ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-body">
        <form method="POST">

          <div class="mb-3">
            <label class="form-label fw-semibold">Nombre del insumo <span class="text-danger">*</span></label>
            <input type="text" name="nombre" class="form-control"
                   value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>"
                   placeholder="Ej: Harina de trigo" required autofocus>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Unidad de medida <span class="text-danger">*</span></label>
            <select name="unidad_medida" class="form-select" required>
              <option value="">Seleccionar...</option>
              <?php foreach (['kg','g','L','ml','unidad'] as $u): ?>
              <option value="<?= $u ?>" <?= (($_POST['unidad_medida'] ?? '') === $u) ? 'selected' : '' ?>>
                <?= $u ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Punto de reposición</label>
            <div class="input-group">
              <input type="number" name="punto_reposicion" class="form-control input-cantidad"
                     value="<?= htmlspecialchars($_POST['punto_reposicion'] ?? '0', ENT_QUOTES) ?? 0 ?>"
                     min="0" step="0.001">
              <span class="input-group-text text-muted">unidades</span>
            </div>
            <div class="form-text">Cantidad mínima antes de generar alerta de compra.</div>
          </div>

          <div class="mb-4">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="es_harina" id="es_harina"
                     <?= isset($_POST['es_harina']) ? 'checked' : '' ?>>
              <label class="form-check-label" for="es_harina">
                Este insumo es <strong>harina</strong> (se aplicará merma del 6% al producir)
              </label>
            </div>
          </div>

          <div class="d-grid">
            <button type="submit" class="btn btn-dark">
              <i class="bi bi-check-lg"></i> Guardar insumo
            </button>
          </div>

        </form>
      </div>
    </div>

  </div>
</div>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>

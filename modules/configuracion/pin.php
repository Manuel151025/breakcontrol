<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/sesion.php';

requerirPropietario();
$pdo  = getConexion();
$user = usuarioActual();

$msg_ok  = '';
$msg_err = '';

// Verificar si ya tiene PIN
$stmt = $pdo->prepare("SELECT pin_recuperacion FROM usuario WHERE id_usuario=?");
$stmt->execute([$user['id_usuario']]);
$tiene_pin = !empty($stmt->fetchColumn());

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_pin'])) {
    $clave_actual = $_POST['clave_actual'] ?? '';
    $pin          = trim($_POST['pin'] ?? '');
    $pin_conf     = trim($_POST['pin_confirmar'] ?? '');

    // Validar contraseña actual
    $stmt = $pdo->prepare("SELECT contrasena_hash FROM usuario WHERE id_usuario=?");
    $stmt->execute([$user['id_usuario']]);
    $hash = $stmt->fetchColumn();

    if (!password_verify($clave_actual, $hash)) {
        $msg_err = 'Contraseña actual incorrecta.';
    } elseif (!preg_match('/^\d{6}$/', $pin)) {
        $msg_err = 'El PIN debe ser exactamente 6 dígitos numéricos.';
    } elseif ($pin !== $pin_conf) {
        $msg_err = 'Los PINs no coinciden.';
    } else {
        try {
            $pin_hash = password_hash($pin, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE usuario SET pin_recuperacion=? WHERE id_usuario=?")
                ->execute([$pin_hash, $user['id_usuario']]);
            $msg_ok = 'PIN de recuperación guardado correctamente.';
            $tiene_pin = true;
        } catch (Exception $e) {
            $msg_err = 'Error al guardar el PIN.';
        }
    }
}

$page_title = 'Configurar PIN';
require_once __DIR__ . '/../../views/layouts/header.php';
?>
<style>
  :root{--c1:#945b35;--c3:#c67124;--c4:#e4a565;--cbg:#faf3ea;--ccard:#fff;--clight:#fdf6ee;--ink:#281508;--ink2:#6b3d1e;--ink3:#b87a4a;--border:rgba(148,91,53,.12);--shadow:0 1px 8px rgba(148,91,53,.09);--shadow2:0 4px 20px rgba(148,91,53,.15);--nav-h:64px;}
  @keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
  .page{margin-top:var(--nav-h);min-height:calc(100vh - var(--nav-h));display:flex;align-items:flex-start;justify-content:center;padding:2rem 1rem;}
  .card-pin{background:var(--ccard);border-radius:16px;box-shadow:var(--shadow2);max-width:440px;width:100%;overflow:hidden;animation:fadeUp .4s ease;}
  .card-pin-head{background:linear-gradient(135deg,var(--c1),var(--c3),var(--c4));padding:1.3rem 1.8rem;color:#fff;}
  .card-pin-head h2{font-family:'Fraunces',serif;font-size:1.25rem;font-weight:800;margin-bottom:.15rem;}
  .card-pin-head p{font-size:.75rem;opacity:.7;}
  .card-pin-body{padding:1.5rem 1.8rem 1.8rem;}
  .fl{margin-bottom:1rem;}
  .fl label{display:block;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:var(--ink3);margin-bottom:.3rem;}
  .fl input{width:100%;border:1.5px solid var(--border);border-radius:10px;padding:.55rem .8rem;font-size:.88rem;font-family:inherit;color:var(--ink);background:var(--clight);transition:border-color .2s,box-shadow .2s;}
  .fl input:focus{outline:none;border-color:var(--c3);box-shadow:0 0 0 3px rgba(198,113,36,.1);}
  .fl .hint{font-size:.68rem;color:var(--ink3);margin-top:.25rem;}
  .btn-guardar{width:100%;background:linear-gradient(135deg,var(--c3),var(--c1));color:#fff;border:none;border-radius:11px;padding:.68rem;font-size:.88rem;font-weight:700;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px rgba(198,113,36,.3);display:flex;align-items:center;justify-content:center;gap:.4rem;transition:all .2s;margin-top:.5rem;}
  .btn-guardar:hover{transform:translateY(-2px);box-shadow:0 7px 22px rgba(198,113,36,.45);}
  .btn-volver{display:block;text-align:center;margin-top:1rem;font-size:.82rem;color:var(--c3);font-weight:600;text-decoration:none;}
  .btn-volver:hover{text-decoration:underline;}
  .msg-ok{background:#e8f5e9;border:1px solid #a5d6a7;border-left:3px solid #2e7d32;border-radius:10px;padding:.6rem .9rem;font-size:.82rem;color:#1b5e20;font-weight:600;margin-bottom:1rem;display:flex;align-items:center;gap:.4rem;}
  .msg-err{background:#ffebee;border:1px solid #ef9a9a;border-left:3px solid #c62828;border-radius:10px;padding:.6rem .9rem;font-size:.82rem;color:#c62828;font-weight:600;margin-bottom:1rem;display:flex;align-items:center;gap:.4rem;}
  .estado-pin{display:flex;align-items:center;gap:.5rem;padding:.65rem .9rem;border-radius:10px;font-size:.82rem;font-weight:600;margin-bottom:1.2rem;}
  .estado-pin.activo{background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;}
  .estado-pin.pendiente{background:#fff3e0;color:#e65100;border:1px solid #ffcc80;}
  @media(max-width:480px){.page{padding:1rem .5rem;}.card-pin-body{padding:1.2rem 1.2rem;}}
</style>

<div class="page">
  <div class="card-pin">
    <div class="card-pin-head">
      <h2>🔑 <?= $tiene_pin ? 'Cambiar' : 'Configurar' ?> PIN de recuperación</h2>
      <p>Este PIN te permite recuperar tu contraseña si la olvidas</p>
    </div>

    <div class="card-pin-body">
      <?php if ($msg_ok): ?>
      <div class="msg-ok">✅ <?= $msg_ok ?></div>
      <?php endif; ?>
      <?php if ($msg_err): ?>
      <div class="msg-err">⚠️ <?= htmlspecialchars($msg_err) ?></div>
      <?php endif; ?>

      <div class="estado-pin <?= $tiene_pin ? 'activo' : 'pendiente' ?>">
        <?= $tiene_pin ? '✅ PIN configurado — puedes cambiarlo abajo' : '⚠️ No tienes PIN configurado — configúralo ahora' ?>
      </div>

      <form method="POST">
        <div class="fl">
          <label>Contraseña actual (para confirmar tu identidad)</label>
          <input type="password" name="clave_actual" required placeholder="Tu contraseña actual">
        </div>

        <div class="fl">
          <label>Nuevo PIN de 6 dígitos</label>
          <input type="text" name="pin" required maxlength="6" pattern="\d{6}"
                 inputmode="numeric" placeholder="Ej: 123456"
                 style="font-family:'Fraunces',serif;font-size:1.2rem;letter-spacing:.5rem;text-align:center;">
          <div class="hint">Solo números. Memorízalo o guárdalo en un lugar seguro.</div>
        </div>

        <div class="fl">
          <label>Confirmar PIN</label>
          <input type="text" name="pin_confirmar" required maxlength="6" pattern="\d{6}"
                 inputmode="numeric" placeholder="Repite el PIN"
                 style="font-family:'Fraunces',serif;font-size:1.2rem;letter-spacing:.5rem;text-align:center;">
        </div>

        <button type="submit" name="guardar_pin" class="btn-guardar">
          🔑 <?= $tiene_pin ? 'Cambiar PIN' : 'Guardar PIN' ?>
        </button>
      </form>

      <a href="<?= APP_URL ?>/modules/tablero/index.php" class="btn-volver">← Volver al tablero</a>
    </div>
  </div>
</div>

</body></html>

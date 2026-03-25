<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';

session_name(SESSION_NOMBRE);
session_start();

// Si ya está logueado, redirigir
if (isset($_SESSION['id_usuario'])) {
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

$pdo   = getConexion();
$paso  = 1;
$error = '';
$ok    = '';
$usuario_input = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── PASO 1: Verificar usuario ──────────────────────────────
    if (isset($_POST['verificar_usuario'])) {
        $usuario_input = trim($_POST['usuario'] ?? '');
        if (empty($usuario_input)) {
            $error = 'Ingresa tu nombre de usuario.';
        } else {
            $stmt = $pdo->prepare("SELECT id_usuario, pin_recuperacion FROM usuario WHERE nombre_usuario=? AND activo=1");
            $stmt->execute([$usuario_input]);
            $user = $stmt->fetch();

            if (!$user) {
                $error = 'Usuario no encontrado.';
            } elseif (empty($user['pin_recuperacion'])) {
                $error = 'Este usuario no tiene PIN configurado. Contacta al administrador.';
            } else {
                $_SESSION['recover_user_id'] = $user['id_usuario'];
                $_SESSION['recover_usuario'] = $usuario_input;
                $paso = 2;
            }
        }
    }

    // ── PASO 2: Verificar PIN ──────────────────────────────────
    if (isset($_POST['verificar_pin'])) {
        $pin = trim($_POST['pin'] ?? '');
        $uid = $_SESSION['recover_user_id'] ?? null;
        $usuario_input = $_SESSION['recover_usuario'] ?? '';

        if (!$uid) {
            $error = 'Sesión expirada. Empieza de nuevo.';
            $paso = 1;
        } elseif (empty($pin) || !preg_match('/^\d{6}$/', $pin)) {
            $error = 'El PIN debe ser de 6 dígitos.';
            $paso = 2;
        } else {
            $stmt = $pdo->prepare("SELECT pin_recuperacion FROM usuario WHERE id_usuario=?");
            $stmt->execute([$uid]);
            $hash = $stmt->fetchColumn();

            if ($hash && password_verify($pin, $hash)) {
                $_SESSION['recover_pin_ok'] = true;
                $paso = 3;
            } else {
                $error = 'PIN incorrecto.';
                $paso = 2;
            }
        }
    }

    // ── PASO 3: Cambiar contraseña ─────────────────────────────
    if (isset($_POST['cambiar_clave'])) {
        $nueva  = $_POST['nueva_clave'] ?? '';
        $conf   = $_POST['confirmar_clave'] ?? '';
        $uid    = $_SESSION['recover_user_id'] ?? null;
        $pin_ok = $_SESSION['recover_pin_ok'] ?? false;
        $usuario_input = $_SESSION['recover_usuario'] ?? '';

        if (!$uid || !$pin_ok) {
            $error = 'Sesión expirada. Empieza de nuevo.';
            $paso = 1;
        } elseif (strlen($nueva) < 6) {
            $error = 'La contraseña debe tener al menos 6 caracteres.';
            $paso = 3;
        } elseif ($nueva !== $conf) {
            $error = 'Las contraseñas no coinciden.';
            $paso = 3;
        } else {
            try {
                $hash = password_hash($nueva, PASSWORD_BCRYPT);
                $pdo->prepare("UPDATE usuario SET contrasena_hash=? WHERE id_usuario=?")
                    ->execute([$hash, $uid]);
                // Limpiar sesión de recuperación
                unset($_SESSION['recover_user_id'], $_SESSION['recover_usuario'], $_SESSION['recover_pin_ok']);
                $ok = '¡Contraseña actualizada! Ya puedes iniciar sesión.';
                $paso = 0; // Mostrar éxito
            } catch (Exception $e) {
                $error = 'Error al actualizar la contraseña.';
                $paso = 3;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Recuperar contraseña — BreakControl</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root{--c1:#945b35;--c3:#c67124;--c4:#e4a565;--c5:#ecc198;--cbg:#faf3ea;--ccard:#fff;--clight:#fdf6ee;--ink:#281508;--ink2:#6b3d1e;--ink3:#b87a4a;--border:rgba(148,91,53,.12);}
    *{margin:0;padding:0;box-sizing:border-box;}
    body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--cbg);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem;}
    .card{background:var(--ccard);border-radius:20px;box-shadow:0 8px 40px rgba(148,91,53,.12);max-width:420px;width:100%;overflow:hidden;}
    .card-header{background:linear-gradient(135deg,var(--c1),var(--c3),var(--c4));padding:1.5rem 2rem;text-align:center;}
    .card-header h1{font-family:'Fraunces',serif;font-size:1.4rem;font-weight:800;color:#fff;margin-bottom:.2rem;}
    .card-header p{font-size:.78rem;color:rgba(255,255,255,.7);}
    .card-body{padding:1.8rem 2rem 2rem;}

    /* Pasos */
    .pasos{display:flex;justify-content:center;gap:.5rem;margin-bottom:1.5rem;}
    .paso-dot{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;border:2px solid var(--border);color:var(--ink3);background:var(--clight);transition:all .3s;}
    .paso-dot.activo{background:var(--c3);border-color:var(--c3);color:#fff;}
    .paso-dot.done{background:#2e7d32;border-color:#2e7d32;color:#fff;}

    .fl{margin-bottom:1rem;}
    .fl label{display:block;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:var(--ink3);margin-bottom:.35rem;}
    .fl input{width:100%;border:1.5px solid var(--border);border-radius:10px;padding:.6rem .85rem;font-size:.9rem;font-family:inherit;color:var(--ink);background:var(--clight);transition:border-color .2s,box-shadow .2s;}
    .fl input:focus{outline:none;border-color:var(--c3);box-shadow:0 0 0 3px rgba(198,113,36,.1);}

    /* PIN input especial */
    .pin-wrap{display:flex;justify-content:center;gap:.5rem;margin:1rem 0;}
    .pin-digit{width:42px;height:50px;border:2px solid var(--border);border-radius:10px;text-align:center;font-size:1.3rem;font-weight:800;font-family:'Fraunces',serif;color:var(--ink);background:var(--clight);transition:border-color .2s;}
    .pin-digit:focus{outline:none;border-color:var(--c3);box-shadow:0 0 0 3px rgba(198,113,36,.12);}
    input[name="pin"]{position:absolute;opacity:0;pointer-events:none;}

    .btn{width:100%;background:linear-gradient(135deg,var(--c3),var(--c1));color:#fff;border:none;border-radius:12px;padding:.72rem;font-size:.9rem;font-weight:700;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px rgba(198,113,36,.3);transition:all .2s;display:flex;align-items:center;justify-content:center;gap:.4rem;margin-top:.5rem;}
    .btn:hover{transform:translateY(-2px);box-shadow:0 7px 22px rgba(198,113,36,.45);}

    .btn-sec{width:100%;background:var(--clight);color:var(--ink2);border:1px solid var(--border);border-radius:10px;padding:.55rem;font-size:.82rem;font-weight:600;cursor:pointer;font-family:inherit;margin-top:.6rem;text-align:center;display:block;text-decoration:none;transition:all .2s;}
    .btn-sec:hover{border-color:var(--c3);color:var(--c3);}

    .msg-err{background:#ffebee;border:1px solid #ef9a9a;border-left:3px solid #c62828;border-radius:10px;padding:.6rem .9rem;font-size:.82rem;color:#c62828;font-weight:600;margin-bottom:1rem;display:flex;align-items:center;gap:.4rem;}
    .msg-ok{background:#e8f5e9;border:1px solid #a5d6a7;border-left:3px solid #2e7d32;border-radius:10px;padding:.6rem .9rem;font-size:.82rem;color:#1b5e20;font-weight:600;margin-bottom:1rem;display:flex;align-items:center;gap:.4rem;}

    .hint{font-size:.72rem;color:var(--ink3);text-align:center;margin-top:.8rem;line-height:1.5;}
    .hint a{color:var(--c3);text-decoration:none;font-weight:600;}
    .hint a:hover{text-decoration:underline;}
  </style>
</head>
<body>

<div class="card">
  <div class="card-header">
    <h1>🔐 Recuperar contraseña</h1>
    <p>Usa tu PIN de 6 dígitos para restablecer tu acceso</p>
  </div>

  <div class="card-body">
    <!-- Indicador de pasos -->
    <?php if ($paso >= 1 && $paso <= 3): ?>
    <div class="pasos">
      <div class="paso-dot <?= $paso == 1 ? 'activo' : ($paso > 1 ? 'done' : '') ?>">1</div>
      <div class="paso-dot <?= $paso == 2 ? 'activo' : ($paso > 2 ? 'done' : '') ?>">2</div>
      <div class="paso-dot <?= $paso == 3 ? 'activo' : '' ?>">3</div>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="msg-err">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($ok): ?>
    <div class="msg-ok">✅ <?= htmlspecialchars($ok) ?></div>
    <a href="<?= APP_URL ?>/login.php" class="btn" style="margin-top:1rem;">
      Ir al login
    </a>
    <?php endif; ?>

    <!-- PASO 1: Usuario -->
    <?php if ($paso == 1): ?>
    <form method="POST">
      <div class="fl">
        <label for="usuario">Nombre de usuario</label>
        <input type="text" name="usuario" id="usuario" placeholder="Ej: propietario"
               value="<?= htmlspecialchars($usuario_input) ?>" required autofocus>
      </div>
      <button type="submit" name="verificar_usuario" class="btn">
        Siguiente →
      </button>
    </form>
    <?php endif; ?>

    <!-- PASO 2: PIN -->
    <?php if ($paso == 2): ?>
    <p style="text-align:center;font-size:.85rem;color:var(--ink2);margin-bottom:1rem;">
      Ingresa el PIN de 6 dígitos para <strong><?= htmlspecialchars($_SESSION['recover_usuario'] ?? '') ?></strong>
    </p>
    <form method="POST">
      <div class="pin-wrap">
        <?php for ($i = 0; $i < 6; $i++): ?>
        <input type="text" class="pin-digit" maxlength="1" inputmode="numeric" pattern="[0-9]"
               data-idx="<?= $i ?>" autocomplete="off">
        <?php endfor; ?>
      </div>
      <input type="hidden" name="pin" id="pin-hidden" value="">
      <button type="submit" name="verificar_pin" class="btn">
        Verificar PIN
      </button>
    </form>
    <script>
    (function(){
      const digits = document.querySelectorAll('.pin-digit');
      const hidden = document.getElementById('pin-hidden');
      function updateHidden(){
        hidden.value = Array.from(digits).map(d => d.value).join('');
      }
      digits.forEach((d, i) => {
        d.addEventListener('input', function(){
          this.value = this.value.replace(/\D/g,'').slice(0,1);
          updateHidden();
          if(this.value && i < 5) digits[i+1].focus();
        });
        d.addEventListener('keydown', function(e){
          if(e.key === 'Backspace' && !this.value && i > 0){
            digits[i-1].focus();
            digits[i-1].value = '';
            updateHidden();
          }
        });
        d.addEventListener('paste', function(e){
          e.preventDefault();
          const txt = (e.clipboardData.getData('text') || '').replace(/\D/g,'').slice(0,6);
          for(let j=0;j<6;j++) digits[j].value = txt[j]||'';
          updateHidden();
          if(txt.length >= 6) digits[5].focus();
        });
      });
      digits[0].focus();
    })();
    </script>
    <?php endif; ?>

    <!-- PASO 3: Nueva contraseña -->
    <?php if ($paso == 3): ?>
    <p style="text-align:center;font-size:.85rem;color:#2e7d32;margin-bottom:1rem;font-weight:600;">
      ✅ PIN verificado. Crea tu nueva contraseña.
    </p>
    <form method="POST">
      <div class="fl">
        <label for="nueva_clave">Nueva contraseña</label>
        <input type="password" name="nueva_clave" id="nueva_clave"
               placeholder="Mínimo 6 caracteres" required minlength="6" autofocus>
      </div>
      <div class="fl">
        <label for="confirmar_clave">Confirmar contraseña</label>
        <input type="password" name="confirmar_clave" id="confirmar_clave"
               placeholder="Repite la contraseña" required minlength="6">
      </div>
      <button type="submit" name="cambiar_clave" class="btn">
        🔑 Cambiar contraseña
      </button>
    </form>
    <?php endif; ?>

    <?php if ($paso > 0): ?>
    <div class="hint">
      <a href="<?= APP_URL ?>/login.php">← Volver al login</a>
    </div>
    <?php endif; ?>

    <?php if ($paso == 1): ?>
    <div class="hint">
      ¿No tienes PIN? Pídelo al propietario del sistema.
    </div>
    <?php endif; ?>
  </div>
</div>

</body>
</html>

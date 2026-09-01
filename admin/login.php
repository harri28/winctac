<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rate_limit.php';

function adminLogueado(): bool { return !empty($_SESSION['admin_id']); }

if (adminLogueado()) { header('Location: ' . BASE_URL . '/admin/index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    $pdo   = getDB();
    $clave = loginClave($email);

    $bloqueado = $email ? loginBloqueado($pdo, $clave) : null;
    if ($bloqueado) {
        $error = 'Demasiados intentos fallidos. Intenta de nuevo en ' . ceil($bloqueado / 60) . ' minuto(s).';
    } elseif ($email && $pass) {
        $stmt = $pdo->prepare('SELECT * FROM admin_usuarios WHERE (email = ? OR nombre = ?) AND tienda_id = ? AND activo = TRUE LIMIT 1');
        $stmt->execute([$email, $email, TIENDA_ID]);
        $admin = $stmt->fetch();
        if ($admin && password_verify($pass, $admin['password_hash'])) {
            limpiarIntentos($pdo, $clave);
            $_SESSION['admin_id']     = $admin['id'];
            $_SESSION['admin_nombre'] = $admin['nombre'];
            header('Location: ' . BASE_URL . '/admin/index.php');
            exit;
        } else {
            registrarIntentoFallido($pdo, $clave);
            $error = 'Credenciales incorrectas.';
        }
    } else {
        $error = 'Ingresa tu correo y contraseña.';
    }
}

$cfg = getShopConfig();
$shopName = htmlspecialchars($cfg['nombre_tienda'] ?? 'Mi Tienda');
$faviconOk = !empty($cfg['logo_path']) && file_exists(UPLOADS_PATH . '/' . $cfg['logo_path']);
$faviconTypes = ['svg' => 'image/svg+xml', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];
$faviconType = $faviconOk ? ($faviconTypes[strtolower(pathinfo($cfg['logo_path'], PATHINFO_EXTENSION))] ?? 'image/jpeg') : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — <?= $shopName ?></title>
    <?php if ($faviconOk): ?>
    <link rel="icon" type="<?= $faviconType ?>" href="<?= UPLOADS_URL ?>/<?= htmlspecialchars($cfg['logo_path']) ?>">
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <?= brandColorStyleTag($cfg) ?>
</head>
<body style="background:var(--surface-3);display:flex;align-items:center;justify-content:center;min-height:100vh">

<div style="width:100%;max-width:380px;padding:20px">
    <div style="text-align:center;margin-bottom:28px">
        <?php if (!empty($cfg['logo_path']) && file_exists(UPLOADS_PATH . '/' . $cfg['logo_path'])): ?>
        <div style="width:52px;height:52px;border-radius:14px;display:inline-flex;align-items:center;justify-content:center;overflow:hidden;margin-bottom:14px">
            <img src="<?= UPLOADS_URL ?>/<?= htmlspecialchars($cfg['logo_path']) ?>" alt="<?= $shopName ?>" style="width:100%;height:100%;object-fit:contain">
        </div>
        <?php else: ?>
        <div style="width:52px;height:52px;background:var(--primary);border-radius:14px;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:1.4rem;margin-bottom:14px">
            <i class="fas fa-store"></i>
        </div>
        <?php endif; ?>
        <h1 style="font-size:1.3rem;font-weight:700"><?= $shopName ?></h1>
        <p style="color:var(--text-muted);font-size:.9rem">Panel de administración</p>
    </div>

    <div class="auth-card">
        <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label class="form-label">Usuario</label>
                <input type="text" name="email" class="form-control" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus autocomplete="username">
            </div>
            <div class="form-group">
                <label class="form-label">Contraseña</label>
                <div style="position:relative">
                    <input type="password" name="password" id="admin-pass" class="form-control" required autocomplete="current-password" style="padding-right:42px">
                    <button type="button" onclick="togglePass('admin-pass', this)" tabindex="-1"
                            style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:.9rem;padding:4px">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-full btn-lg">
                <i class="fas fa-sign-in-alt"></i> Ingresar
            </button>
        </form>

    </div>

    <div style="text-align:center;margin-top:16px">
        <a href="<?= BASE_URL ?>/" style="font-size:.85rem;color:var(--text-muted)">← Ir a la tienda</a>
    </div>
</div>
<script>
function togglePass(id, btn) {
    const inp = document.getElementById(id);
    const show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    btn.querySelector('i').className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
}
</script>
</body>
</html>

<?php
$pageTitle  = 'Crear Cuenta';
$currentPage = 'registro';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
require_once __DIR__ . '/../config/database.php';

if (clienteLogueado()) { header('Location: ' . BASE_URL . '/'); exit; }

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre    = trim($_POST['nombre'] ?? '');
    $apellidos = trim($_POST['apellidos'] ?? '');
    $dni       = trim($_POST['dni'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $celular   = trim($_POST['celular'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $distrito  = trim($_POST['distrito'] ?? '');
    $ciudad    = trim($_POST['ciudad'] ?? 'Lima');
    $pass      = $_POST['password'] ?? '';
    $pass2     = $_POST['password2'] ?? '';

    if (!$nombre || !$email || !$celular || !$pass) {
        $error = 'Completa todos los campos requeridos.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'El correo electrónico no es válido.';
    } elseif (strlen($pass) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres.';
    } elseif ($pass !== $pass2) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        $pdo = getDB();
        $exists = $pdo->prepare('SELECT 1 FROM clientes WHERE email = ?');
        $exists->execute([$email]);
        if ($exists->fetch()) {
            $error = 'Ya existe una cuenta con ese correo electrónico.';
        } else {
            $pdo->prepare('
                INSERT INTO clientes (nombre, apellidos, dni, email, celular, direccion, distrito, ciudad, password_hash)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ')->execute([$nombre, $apellidos, $dni, $email, $celular, $direccion, $distrito, $ciudad, password_hash($pass)]);

            $c = $pdo->prepare('SELECT * FROM clientes WHERE email = ?');
            $c->execute([$email]);
            loginCliente($c->fetch());
            header('Location: ' . ($_GET['redirect'] ?? (BASE_URL . '/')));
            exit;
        }
    }
}
?>

<div class="page-wrapper">
    <div class="container">
        <div class="auth-wrapper" style="max-width:540px">
            <div class="auth-card">
                <div class="auth-title"><i class="fas fa-user-plus" style="color:var(--primary)"></i> Crear Cuenta</div>
                <div class="auth-sub">Crea tu cuenta para realizar pedidos</div>

                <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                        <div class="form-group">
                            <label class="form-label">Nombre <span style="color:var(--danger)">*</span></label>
                            <input type="text" name="nombre" class="form-control" value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Apellidos</label>
                            <input type="text" name="apellidos" class="form-control" value="<?= htmlspecialchars($_POST['apellidos'] ?? '') ?>">
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                        <div class="form-group">
                            <label class="form-label">DNI</label>
                            <input type="text" name="dni" class="form-control" maxlength="15" value="<?= htmlspecialchars($_POST['dni'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Celular <span style="color:var(--danger)">*</span></label>
                            <input type="tel" name="celular" class="form-control" placeholder="999 999 999" value="<?= htmlspecialchars($_POST['celular'] ?? '') ?>" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Correo electrónico <span style="color:var(--danger)">*</span></label>
                        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Dirección</label>
                        <input type="text" name="direccion" class="form-control" placeholder="Av. Los Pinos 123" value="<?= htmlspecialchars($_POST['direccion'] ?? '') ?>">
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                        <div class="form-group">
                            <label class="form-label">Distrito</label>
                            <input type="text" name="distrito" class="form-control" value="<?= htmlspecialchars($_POST['distrito'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Ciudad</label>
                            <input type="text" name="ciudad" class="form-control" value="<?= htmlspecialchars($_POST['ciudad'] ?? 'Lima') ?>">
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                        <div class="form-group">
                            <label class="form-label">Contraseña <span style="color:var(--danger)">*</span></label>
                            <input type="password" name="password" class="form-control" placeholder="Mín. 6 caracteres" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Confirmar contraseña <span style="color:var(--danger)">*</span></label>
                            <input type="password" name="password2" class="form-control" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-full btn-lg" style="margin-top:8px">
                        <i class="fas fa-user-plus"></i> Crear cuenta
                    </button>
                </form>

                <div style="text-align:center;margin-top:20px;font-size:.9rem;color:var(--text-muted)">
                    ¿Ya tienes cuenta? <a href="<?= BASE_URL ?>/cuenta/login.php">Inicia sesión</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

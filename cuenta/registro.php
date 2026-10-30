<?php
// Todo lo que pueda terminar en header('Location: ...') debe resolverse
// ANTES de includes/header.php: ese archivo ya imprime HTML, así que un
// redirect después fallaría en silencio (headers already sent).
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/puntos.php';

if (clienteLogueado()) { header('Location: ' . BASE_URL . '/'); exit; }

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre    = trim($_POST['nombre'] ?? '');
    $apellidos = trim($_POST['apellidos'] ?? '');
    $dni       = normalizarDocumento($_POST['dni'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $celular   = trim($_POST['celular'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $distrito  = trim($_POST['distrito'] ?? '');
    $ciudad    = trim($_POST['ciudad'] ?? 'Lima');
    $pass      = $_POST['password'] ?? '';
    $pass2     = $_POST['password2'] ?? '';

    if (!$nombre || !$dni || !$email || !$celular || !$pass) {
        $error = 'Completa todos los campos requeridos.';
    } elseif (!documentoValido($dni)) {
        $error = 'Ingresa un DNI de 8 dígitos o un carné de extranjería válido.';
    } elseif (!preg_match('/^\+?[\d\s]{9,15}$/', $celular)) {
        $error = 'Ingresa un número de celular válido.';
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
        $existsDni = $pdo->prepare("SELECT 1 FROM clientes WHERE UPPER(REPLACE(dni, ' ', '')) = ?");
        $existsDni->execute([$dni]);
        if ($exists->fetch()) {
            $error = 'Ya existe una cuenta con ese correo electrónico.';
        } elseif ($existsDni->fetch()) {
            $error = 'Ya existe una cuenta con ese DNI. Inicia sesión o recupera tu acceso.';
        } else {
            $pdo->prepare('
                INSERT INTO clientes (nombre, apellidos, dni, email, celular, direccion, distrito, ciudad, password_hash)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ')->execute([$nombre, $apellidos, $dni, $email, $celular, $direccion, $distrito, $ciudad, password_hash($pass, PASSWORD_DEFAULT)]);

            $c = $pdo->prepare('SELECT * FROM clientes WHERE email = ?');
            $c->execute([$email]);
            $nuevo = $c->fetch();
            loginCliente($nuevo);
            // Compras previas en tienda física con este DNI: se acreditan al afiliarse
            acreditarComprasPendientes($pdo, (int) $nuevo['id'], $dni);
            header('Location: ' . redirectInterno($_GET['redirect'] ?? null));
            exit;
        }
    }
}

$pageTitle  = 'Crear Cuenta';
$currentPage = 'registro';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-wrapper">
    <div class="container">
        <div class="auth-wrapper" style="max-width:540px">
            <div class="auth-card">
                <div class="auth-title"><i class="fas fa-user-plus" style="color:var(--primary)"></i> Crear Cuenta</div>
                <div class="auth-sub">Crea tu cuenta para realizar pedidos<?= !empty($cfg['puntos_activo']) ? ' y afiliarte al programa de puntos' : '' ?></div>

                <?php if (!empty($cfg['puntos_activo'])): ?>
                <div class="alert" style="background:var(--primary-bg);color:var(--text);font-size:.85rem;border-left:4px solid var(--primary)">
                    <i class="fas fa-star" style="color:var(--primary)"></i>
                    Al registrarte quedas afiliado al <strong>programa de puntos</strong>: ganas puntos en tus compras de la web
                    y de nuestra tienda física (identificándote con tu DNI) y los canjeas por descuentos.
                </div>
                <?php endif; ?>

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
                            <label class="form-label">DNI / CE <span style="color:var(--danger)">*</span></label>
                            <input type="text" name="dni" class="form-control" maxlength="15" inputmode="numeric" placeholder="8 dígitos" value="<?= htmlspecialchars($_POST['dni'] ?? '') ?>" required>
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
                    ¿Ya tienes cuenta? <a href="<?= BASE_URL ?>/cuenta/login">Inicia sesión</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

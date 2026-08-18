<?php
$pageTitle  = 'Iniciar Sesión';
$currentPage = 'login';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rate_limit.php';

if (clienteLogueado()) {
    header('Location: ' . ($_GET['redirect'] ?? (BASE_URL . '/')));
    exit;
}

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
        $stmt = $pdo->prepare('SELECT * FROM clientes WHERE email = ? AND activo = TRUE');
        $stmt->execute([$email]);
        $c = $stmt->fetch();
        if ($c && password_verify($pass, $c['password_hash'])) {
            limpiarIntentos($pdo, $clave);
            loginCliente($c);
            header('Location: ' . ($_GET['redirect'] ?? (BASE_URL . '/')));
            exit;
        } else {
            registrarIntentoFallido($pdo, $clave);
            $error = 'Correo o contraseña incorrectos.';
        }
    } else {
        $error = 'Por favor ingresa tu correo y contraseña.';
    }
}
?>

<div class="page-wrapper">
    <div class="container">
        <div class="auth-wrapper">
            <div class="auth-card">
                <div class="auth-title"><i class="fas fa-user-circle" style="color:var(--primary)"></i> Iniciar Sesión</div>
                <div class="auth-sub">Ingresa a tu cuenta para continuar</div>

                <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">Usuario o correo</label>
                        <input type="text" name="email" class="form-control" placeholder="usuario o email"
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus autocomplete="username">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Contraseña</label>
                        <div style="position:relative">
                            <input type="password" name="password" id="cli-pass" class="form-control" placeholder="••••••••" required autocomplete="current-password" style="padding-right:42px">
                            <button type="button" onclick="togglePass('cli-pass', this)" tabindex="-1"
                                    style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:.9rem;padding:4px">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-full btn-lg" style="margin-top:8px">
                        <i class="fas fa-sign-in-alt"></i> Ingresar
                    </button>
                </form>

                <div style="text-align:center;margin-top:20px;font-size:.9rem;color:var(--text-muted)">
                    ¿No tienes cuenta?
                    <a href="<?= BASE_URL ?>/cuenta/registro.php<?= isset($_GET['redirect']) ? '?redirect='.urlencode($_GET['redirect']) : '' ?>">
                        Regístrate aquí
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<script>
function togglePass(id, btn) {
    const inp = document.getElementById(id);
    const show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    btn.querySelector('i').className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
}
</script>

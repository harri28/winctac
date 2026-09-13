<?php
// ============================================================
// TRABAJA CON NOSOTROS — Formulario público de postulación
// ============================================================
$pageTitle   = 'Trabaja con nosotros';
$currentPage = 'trabaja-con-nosotros';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/config/database.php';

$pdo     = getDB();
$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre   = trim($_POST['nombre'] ?? '');
    $dni      = trim($_POST['dni'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');

    if (!$nombre || !$dni || !$telefono) {
        $error = 'Completa todos los campos.';
    } elseif (mb_strlen($nombre) > 150 || mb_strlen($telefono) > 20) {
        $error = 'Uno de los campos es demasiado largo.';
    } elseif (!preg_match('/^[A-Za-z0-9]{6,15}$/', $dni)) {
        $error = 'DNI / documento de identidad inválido.';
    } elseif (empty($_FILES['cv']['tmp_name']) || $_FILES['cv']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Adjunta tu CV en PDF.';
    } elseif ($_FILES['cv']['size'] > 10 * 1024 * 1024) {
        $error = 'El CV no puede pesar más de 10 MB.';
    } else {
        // Nunca confiar solo en la extensión: se valida además la cabecera
        // real del archivo (los PDF siempre empiezan con "%PDF-").
        $ext        = strtolower(pathinfo($_FILES['cv']['name'], PATHINFO_EXTENSION));
        $firstBytes = file_get_contents($_FILES['cv']['tmp_name'], false, null, 0, 5);

        if ($ext !== 'pdf' || $firstBytes !== '%PDF-') {
            $error = 'El archivo debe ser un PDF válido.';
        } else {
            $dir = UPLOADS_PATH . '/postulaciones';
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            $fname = 'cv_' . time() . '_' . rand(1000, 9999) . '.pdf';

            if (!move_uploaded_file($_FILES['cv']['tmp_name'], $dir . '/' . $fname)) {
                $error = 'No se pudo guardar el archivo. Intenta de nuevo.';
            } else {
                try {
                    $pdo->prepare('INSERT INTO postulaciones (tienda_id, nombre, dni, telefono, cv_path) VALUES (?, ?, ?, ?, ?)')
                        ->execute([TIENDA_ID, $nombre, $dni, $telefono, $fname]);
                    $success = true;
                } catch (PDOException $e) {
                    @unlink($dir . '/' . $fname);
                    $error = 'No se pudo registrar tu postulación. Verifica los datos e intenta de nuevo.';
                }
            }
        }
    }
}
?>

<div class="page-wrapper">
    <div class="container">
        <div class="auth-wrapper" style="max-width:540px">
            <div class="auth-card">

                <?php if ($success): ?>
                    <div class="auth-title"><i class="fas fa-circle-check" style="color:var(--success)"></i> ¡Postulación enviada!</div>
                    <div class="auth-sub">Gracias por tu interés. Revisaremos tu información y nos pondremos en contacto si tu perfil encaja con una vacante.</div>
                    <a href="<?= BASE_URL ?>/nosotros.php" class="btn btn-secondary btn-full btn-lg" style="margin-top:20px">
                        <i class="fas fa-arrow-left"></i> Volver a Nosotros
                    </a>
                <?php else: ?>
                    <div class="auth-title"><i class="fas fa-briefcase" style="color:var(--primary)"></i> Trabaja con nosotros</div>
                    <div class="auth-sub">Déjanos tus datos y tu CV — te contactaremos si hay una vacante para ti.</div>

                    <?php if ($error): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data">
                        <div class="form-group">
                            <label class="form-label">Nombre completo <span style="color:var(--danger)">*</span></label>
                            <input type="text" name="nombre" class="form-control" maxlength="150" value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>" required>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                            <div class="form-group">
                                <label class="form-label">DNI <span style="color:var(--danger)">*</span></label>
                                <input type="text" name="dni" class="form-control" maxlength="15" value="<?= htmlspecialchars($_POST['dni'] ?? '') ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Teléfono <span style="color:var(--danger)">*</span></label>
                                <input type="tel" name="telefono" class="form-control" placeholder="999 999 999" maxlength="20" value="<?= htmlspecialchars($_POST['telefono'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">CV (PDF, máx. 10 MB) <span style="color:var(--danger)">*</span></label>
                            <input type="file" name="cv" class="form-control" accept="application/pdf" required>
                        </div>
                        <button type="submit" class="btn btn-primary btn-full btn-lg" style="margin-top:8px">
                            <i class="fas fa-paper-plane"></i> Enviar postulación
                        </button>
                    </form>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

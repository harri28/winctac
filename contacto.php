<?php
// ============================================================
// CONTACTO — Formulario público + canales de atención
// ============================================================
$pageTitle       = 'Contacto';
$currentPage     = 'contacto';
$metaDescription = 'Comunícate con nosotros por WhatsApp, teléfono, correo o mediante nuestro formulario de contacto.';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/config/database.php';

$pdo     = getDB();
$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre   = trim($_POST['nombre'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $asunto   = trim($_POST['asunto'] ?? '');
    $mensaje  = trim($_POST['mensaje'] ?? '');

    // "website" es un campo trampa oculto: los bots lo rellenan, las personas no.
    if (!empty($_POST['website'])) {
        $success = true;
    } elseif (!empty($_SESSION['contacto_ultimo_envio']) && time() - $_SESSION['contacto_ultimo_envio'] < 60) {
        $error = 'Ya recibimos tu mensaje. Espera un minuto antes de enviar otro.';
    } elseif (!$nombre || !$email || !$mensaje) {
        $error = 'Completa tu nombre, correo y mensaje.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Ingresa un correo electrónico válido.';
    } elseif (mb_strlen($nombre) > 150 || mb_strlen($email) > 150 || mb_strlen($telefono) > 20
              || mb_strlen($asunto) > 150 || mb_strlen($mensaje) > 3000) {
        $error = 'Uno de los campos es demasiado largo.';
    } else {
        try {
            $pdo->prepare('INSERT INTO mensajes_contacto (tienda_id, nombre, email, telefono, asunto, mensaje) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([TIENDA_ID, $nombre, $email, $telefono, $asunto, $mensaje]);
            $_SESSION['contacto_ultimo_envio'] = time();
            $success = true;
        } catch (PDOException $e) {
            $error = 'No se pudo enviar tu mensaje. Intenta de nuevo o escríbenos por WhatsApp.';
        }
    }
}

$waUrl     = whatsappUrl($cfg, 'Hola ' . ($cfg['nombre_tienda'] ?? '') . ', quisiera hacer una consulta.');
$email     = trim($cfg['contacto_email'] ?? '');
$celular   = trim($cfg['contacto_celular'] ?? '');
$direccion = trim($cfg['portal_direccion'] ?? '');
$redes     = redesSociales($cfg);
?>

<div class="page-wrapper">
<div class="container" style="max-width:1100px">

    <div class="page-intro">
        <h1>Contáctanos</h1>
        <p>Estamos para ayudarte. Escríbenos por el canal que prefieras o déjanos un mensaje y te responderemos a la brevedad.</p>
    </div>

    <div class="contact-layout">
        <!-- Canales de atención -->
        <div class="contact-channels">
            <?php if ($waUrl): ?>
            <a href="<?= htmlspecialchars($waUrl) ?>" target="_blank" rel="noopener" class="contact-channel contact-channel--wa">
                <i class="fab fa-whatsapp"></i>
                <div><strong>WhatsApp</strong><span>Respuesta rápida en horario de atención</span></div>
            </a>
            <?php endif; ?>
            <?php if ($celular): ?>
            <a href="tel:<?= htmlspecialchars(preg_replace('/\D/', '', $celular)) ?>" class="contact-channel">
                <i class="fas fa-phone"></i>
                <div><strong>Teléfono</strong><span><?= htmlspecialchars($celular) ?></span></div>
            </a>
            <?php endif; ?>
            <?php if ($email): ?>
            <a href="mailto:<?= htmlspecialchars($email) ?>" class="contact-channel">
                <i class="fas fa-envelope"></i>
                <div><strong>Correo</strong><span><?= htmlspecialchars($email) ?></span></div>
            </a>
            <?php endif; ?>
            <?php if ($direccion): ?>
            <a href="https://www.google.com/maps?q=<?= urlencode($direccion) ?>" target="_blank" rel="noopener" class="contact-channel">
                <i class="fas fa-location-dot"></i>
                <div><strong>Dirección</strong><span><?= htmlspecialchars($direccion) ?></span></div>
            </a>
            <?php endif; ?>
            <?php if ($redes): ?>
            <div class="card">
                <div class="card-title"><i class="fas fa-share-nodes"></i> Síguenos</div>
                <div class="social-links social-links--dark">
                    <?php foreach ($redes as $red): ?>
                    <a href="<?= htmlspecialchars($red['url']) ?>" target="_blank" rel="noopener" aria-label="<?= $red['label'] ?>" title="<?= $red['label'] ?>"><i class="fab <?= $red['icon'] ?>"></i></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Formulario -->
        <div class="card">
            <?php if ($success): ?>
                <div class="auth-title"><i class="fas fa-circle-check" style="color:var(--success)"></i> ¡Mensaje enviado!</div>
                <div class="auth-sub">Gracias por escribirnos. Te responderemos lo antes posible al correo que nos indicaste.</div>
                <a href="<?= BASE_URL ?>/" class="btn btn-secondary btn-lg" style="margin-top:20px">
                    <i class="fas fa-arrow-left"></i> Volver al inicio
                </a>
            <?php else: ?>
                <div class="card-title"><i class="fas fa-paper-plane"></i> Envíanos un mensaje</div>

                <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST">
                    <div class="form-group">
                        <label class="form-label" for="c-nombre">Nombre completo <span style="color:var(--danger)">*</span></label>
                        <input type="text" id="c-nombre" name="nombre" class="form-control" maxlength="150" value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>" required>
                    </div>
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label" for="c-email">Correo <span style="color:var(--danger)">*</span></label>
                            <input type="email" id="c-email" name="email" class="form-control" maxlength="150" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="c-telefono">Teléfono</label>
                            <input type="tel" id="c-telefono" name="telefono" class="form-control" placeholder="999 999 999" maxlength="20" value="<?= htmlspecialchars($_POST['telefono'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="c-asunto">Asunto</label>
                        <input type="text" id="c-asunto" name="asunto" class="form-control" maxlength="150" value="<?= htmlspecialchars($_POST['asunto'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="c-mensaje">Mensaje <span style="color:var(--danger)">*</span></label>
                        <textarea id="c-mensaje" name="mensaje" class="form-control" rows="5" maxlength="3000" required><?= htmlspecialchars($_POST['mensaje'] ?? '') ?></textarea>
                    </div>
                    <div class="hp-field" aria-hidden="true">
                        <label>No llenar <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                    </div>
                    <button type="submit" class="btn btn-primary btn-full btn-lg">
                        <i class="fas fa-paper-plane"></i> Enviar mensaje
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

</div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

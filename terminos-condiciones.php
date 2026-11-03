<?php
$pageTitle       = 'Términos y Condiciones';
$metaDescription = 'Términos y condiciones generales de acceso y uso del sitio.';
$currentPage     = 'terminos-condiciones';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-wrapper">
    <div class="container" style="max-width:860px">
        <div class="page-intro">
            <h1>Términos y Condiciones</h1>
            <p>Última actualización: <?= date('d/m/Y') ?></p>
        </div>

        <div class="legal-content" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);padding:32px 28px;line-height:1.75;color:var(--text)">
            <p style="color:var(--text-muted);text-align:center;padding:40px 0;font-style:italic">
                El contenido de esta página aún no ha sido redactado. Edita este archivo en
                <code>terminos-condiciones.php</code> para publicar los términos y condiciones de tu tienda.
            </p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

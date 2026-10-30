<?php
// ============================================================
// SLIDER DE BANNERS (Admin → Configuración → Banners)
// Se muestra en el Catálogo — la portada es institucional.
// ============================================================
$banners = [];
try {
    $bannersStmt = getDB()->prepare("SELECT * FROM banners WHERE activo = TRUE AND tienda_id = ? AND (imagen_path IS NOT NULL OR imagen_url IS NOT NULL) ORDER BY orden ASC, id ASC");
    $bannersStmt->execute([TIENDA_ID]);
    $banners = $bannersStmt->fetchAll();
} catch (Exception $e) {}
?>
<?php if ($banners): ?>
<div class="hero-slider" id="hero-slider">
    <div class="slider-track" id="slider-track">
        <?php foreach ($banners as $b): ?>
        <div class="slider-slide">
            <?php
            $imgSrc = '';
            if (!empty($b['imagen_path'])) {
                $imgSrc = UPLOADS_URL . '/' . htmlspecialchars($b['imagen_path']);
            } elseif (!empty($b['imagen_url'])) {
                $imgSrc = htmlspecialchars($b['imagen_url']);
            }
            ?>
            <?php if ($b['enlace']): ?><a href="<?= htmlspecialchars($b['enlace']) ?>" style="display:block;height:100%"><?php endif; ?>
            <?php if ($imgSrc): ?>
            <img src="<?= $imgSrc ?>" alt="<?= htmlspecialchars($b['titulo']) ?>">
            <?php endif; ?>
            <?php if ($b['titulo'] || $b['subtitulo']): ?>
            <div class="slider-slide-overlay">
                <?php if ($b['titulo']): ?><div class="slider-slide-title"><?= htmlspecialchars($b['titulo']) ?></div><?php endif; ?>
                <?php if ($b['subtitulo']): ?><div class="slider-slide-sub"><?= htmlspecialchars($b['subtitulo']) ?></div><?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($b['enlace']): ?></a><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="slider-arrows">
        <button class="slider-arrow" id="slider-prev"><i class="fas fa-chevron-left"></i></button>
        <button class="slider-arrow" id="slider-next"><i class="fas fa-chevron-right"></i></button>
    </div>
</div>

<script>
(function() {
    const slider  = document.getElementById('hero-slider');
    const track   = document.getElementById('slider-track');
    const VISIBLE = 4;
    const N       = <?= count($banners) ?>;

    // Necesitamos al menos N slides originales + VISIBLE clones al final
    // para que al llegar al último el primero ya esté listo a la derecha
    const orig = [...track.children];
    while (track.children.length < N + VISIBLE) {
        orig.forEach(s => track.appendChild(s.cloneNode(true)));
    }

    let cur = 0, timer;

    function W() { return slider.offsetWidth / VISIBLE; }

    function setSizes() {
        const w = W() + 'px';
        [...track.children].forEach(s => { s.style.minWidth = w; s.style.width = w; });
    }

    // Salto sin animación — se usa después de que la transición llega a los clones
    function jump(n) {
        cur = n;
        track.style.transition = 'none';
        track.style.transform  = `translateX(-${cur * W()}px)`;
        track.offsetHeight; // forzar reflow
        requestAnimationFrame(() => requestAnimationFrame(() => {
            track.style.transition = '';
        }));
    }

    // Deslizar con animación; si llegamos al territorio de clones, resetear silenciosamente
    function slide(n) {
        cur = n;
        track.style.transform = `translateX(-${cur * W()}px)`;
        // Cuando cur >= N estamos viendo clones del inicio → jump de vuelta
        if (cur >= N) setTimeout(() => jump(cur - N), 580);
    }

    function start() {
        clearInterval(timer);
        timer = setInterval(() => slide(cur + 1), 1500);
    }

    setSizes();

    document.getElementById('slider-next')?.addEventListener('click', () => { slide(cur + 1); start(); });
    document.getElementById('slider-prev')?.addEventListener('click', () => {
        if (cur <= 0) { jump(N); setTimeout(() => slide(N - 1), 20); }
        else slide(cur - 1);
        start();
    });
    slider.addEventListener('mouseenter', () => clearInterval(timer));
    slider.addEventListener('mouseleave', start);
    window.addEventListener('resize', () => { setSizes(); jump(cur); });

    start();
})();
</script>
<?php endif; ?>


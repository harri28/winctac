<?php
$adminPage  = 'banners';
$adminTitle = 'Banners del inicio';
require_once __DIR__ . '/includes/header.php';

$pdo     = getDB();
$success = '';
$error   = '';

// Guardar franja de anuncio
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_anuncio'])) {
    $anuncioTexto  = trim($_POST['anuncio_texto'] ?? '');
    $anuncioActivo = isset($_POST['anuncio_activo']) ? 't' : 'f';
    $pdo->prepare('UPDATE config SET anuncio_texto = ?, anuncio_activo = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$anuncioTexto, $anuncioActivo, TIENDA_ID]);
    header('Location: ' . BASE_URL . '/admin/banners.php?ok=anuncio');
    exit;
}

// Auto-migrar columnas si no existen
try { $pdo->exec("ALTER TABLE banners ADD COLUMN IF NOT EXISTS imagen_url TEXT DEFAULT ''"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE banners ADD COLUMN IF NOT EXISTS producto_id INTEGER DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE banners ALTER COLUMN imagen_path DROP NOT NULL"); } catch (Exception $e) {}

// Normalizar a exactamente 6 slots con orden 1..6 (sin duplicados), por tienda.
// Prioriza filas con contenido real; renumera huecos y elimina sobrantes vacíos.
$rowsStmt = $pdo->prepare("
    SELECT id, orden, imagen_path, imagen_url, titulo
    FROM banners
    WHERE tienda_id = ?
    ORDER BY (imagen_path IS NOT NULL OR imagen_url IS NOT NULL OR titulo <> '') DESC, orden ASC, id ASC
");
$rowsStmt->execute([TIENDA_ID]);
$rows = $rowsStmt->fetchAll();

$total = count($rows);
foreach ($rows as $i => $r) {
    $conContenido = !empty($r['imagen_path']) || !empty($r['imagen_url']) || $r['titulo'] !== '';
    if ($i < 6) {
        if ((int)$r['orden'] !== $i + 1) {
            $pdo->prepare('UPDATE banners SET orden = ? WHERE id = ?')->execute([$i + 1, $r['id']]);
        }
    } elseif (!$conContenido) {
        $pdo->prepare('DELETE FROM banners WHERE id = ?')->execute([$r['id']]);
        $total--;
    }
}

for ($i = min($total, 6) + 1; $i <= 6; $i++) {
    $pdo->prepare("INSERT INTO banners (tienda_id, imagen_path, imagen_url, titulo, subtitulo, enlace, orden, activo) VALUES (?, NULL, NULL, '', '', '', ?, TRUE)")
        ->execute([TIENDA_ID, $i]);
}

// Guardar slot
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['banner_id'])) {
    $id          = intval($_POST['banner_id']);
    $slot_num    = intval($_POST['slot_num'] ?? 0);
    $titulo      = trim($_POST['titulo'] ?? '');
    $subtitulo   = trim($_POST['subtitulo'] ?? '');
    $enlace      = trim($_POST['enlace'] ?? '');
    $producto_id = !empty($_POST['producto_id']) ? intval($_POST['producto_id']) : null;
    $imagen_url  = trim($_POST['imagen_url'] ?? '');
    $activo      = isset($_POST['activo']) ? 't' : 'f';

    // Mantener imagen actual si no se sube una nueva
    $curr  = $pdo->prepare('SELECT imagen_path FROM banners WHERE id = ? AND tienda_id = ?');
    $curr->execute([$id, TIENDA_ID]);
    $fname = ($curr->fetch())['imagen_path'] ?? null;

    if (!empty($_FILES['banner_img']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['banner_img']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            $newFname = 'banner_' . time() . '_' . rand(100,999) . '.' . $ext;
            if (!is_dir(UPLOADS_PATH)) mkdir(UPLOADS_PATH, 0775, true);
            if (move_uploaded_file($_FILES['banner_img']['tmp_name'], UPLOADS_PATH . '/' . $newFname)) {
                if ($fname && file_exists(UPLOADS_PATH . '/' . $fname)) @unlink(UPLOADS_PATH . '/' . $fname);
                $fname      = $newFname;
                $imagen_url = ''; // imagen subida tiene prioridad
            } else { $error = "Banner $slot_num: no se pudo guardar la imagen."; }
        } else { $error = 'Formato no permitido. Usa JPG, PNG o WEBP.'; }
    }

    if (!$error) {
        $pdo->prepare('UPDATE banners SET imagen_path=?, imagen_url=?, titulo=?, subtitulo=?, enlace=?, producto_id=?, activo=? WHERE id=? AND tienda_id=?')
            ->execute([$fname ?: null, $imagen_url ?: null, $titulo, $subtitulo, $enlace, $producto_id, $activo, $id, TIENDA_ID]);
        $success = "Banner $slot_num guardado correctamente.";
    }
}

// Limpiar slot
if (!empty($_GET['clear']) && is_numeric($_GET['clear'])) {
    $row = $pdo->prepare('SELECT imagen_path FROM banners WHERE id = ? AND tienda_id = ?');
    $row->execute([intval($_GET['clear']), TIENDA_ID]);
    $b = $row->fetch();
    if ($b && $b['imagen_path'] && file_exists(UPLOADS_PATH . '/' . $b['imagen_path'])) {
        @unlink(UPLOADS_PATH . '/' . $b['imagen_path']);
    }
    $pdo->prepare("UPDATE banners SET imagen_path=NULL, imagen_url=NULL, titulo='', subtitulo='', enlace='', producto_id=NULL WHERE id=? AND tienda_id=?")
        ->execute([intval($_GET['clear']), TIENDA_ID]);
    header('Location: ' . BASE_URL . '/admin/banners.php?ok=1'); exit;
}

if (($_GET['ok'] ?? '') === 'anuncio') $success = 'Franja de anuncio guardada.';
elseif (isset($_GET['ok'])) $success = 'Cambio guardado.';

$cfgStmt = $pdo->prepare('SELECT * FROM config WHERE id = ?');
$cfgStmt->execute([TIENDA_ID]);
$cfg     = $cfgStmt->fetch();
$bannersStmt = $pdo->prepare('SELECT * FROM banners WHERE tienda_id = ? ORDER BY orden ASC LIMIT 6');
$bannersStmt->execute([TIENDA_ID]);
$banners = $bannersStmt->fetchAll();
?>

<div class="admin-topbar">
    <h1 class="admin-page-title"><i class="fas fa-images"></i> Banners del inicio</h1>
    <p style="color:var(--text-muted);font-size:.85rem;margin-top:2px">6 slots para el carrusel. Vincula cada uno a un producto o sube una imagen personalizada.</p>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Franja de anuncio -->
<div class="card" style="margin-bottom:20px">
    <div class="card-title"><i class="fas fa-bullhorn"></i> Franja de anuncio</div>
    <form method="POST">
        <div class="form-group">
            <label class="form-label">Texto</label>
            <input type="text" name="anuncio_texto" class="form-control" placeholder="Ej: Envío gratis en compras mayores a S/50" value="<?= htmlspecialchars($cfg['anuncio_texto'] ?? '') ?>" maxlength="180">
            <div class="form-hint">Franja de 30px que aparece arriba de todo, sobre el encabezado de la tienda</div>
        </div>
        <div style="display:flex;align-items:center;gap:14px">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:0">
                <input type="checkbox" name="anuncio_activo" value="1" <?= !empty($cfg['anuncio_activo']) ? 'checked' : '' ?>>
                Mostrar en la tienda
            </label>
            <div style="flex:1"></div>
            <button type="submit" name="save_anuncio" class="btn btn-primary" style="font-size:.83rem">
                <i class="fas fa-save"></i> Guardar anuncio
            </button>
        </div>
    </form>
</div>

<div class="card" style="padding:0;overflow:hidden">
    <div class="card-title" style="padding:18px 20px 0">Slots del carrusel</div>

    <!-- Tira de pestañas: una miniatura por slot -->
    <div style="display:flex;gap:2px;padding:16px 20px;overflow-x:auto">
    <?php foreach ($banners as $i => $b):
        $slotNum = $i + 1;
        $hasImg  = !empty($b['imagen_path']) || !empty($b['imagen_url']);
        $imgSrc  = '';
        if (!empty($b['imagen_path']))  $imgSrc = UPLOADS_URL . '/' . htmlspecialchars($b['imagen_path']);
        elseif (!empty($b['imagen_url'])) $imgSrc = htmlspecialchars($b['imagen_url']);
    ?>
        <button type="button" class="banner-tab <?= $i === 0 ? 'active' : '' ?>" data-tab="<?= $slotNum ?>"
                style="position:relative;flex-shrink:0;width:96px;padding:0;border:2px solid <?= $i === 0 ? 'var(--primary)' : 'var(--border)' ?>;border-radius:var(--radius-sm);overflow:hidden;cursor:pointer;background:none">
            <div style="width:100%;height:54px;background:var(--surface-2);display:flex;align-items:center;justify-content:center;color:#cbd5e1">
                <?php if ($imgSrc): ?>
                <img src="<?= $imgSrc ?>" style="width:100%;height:100%;object-fit:cover">
                <?php else: ?>
                <i class="fas fa-image"></i>
                <?php endif; ?>
            </div>
            <div style="display:flex;align-items:center;justify-content:space-between;padding:4px 6px;background:var(--surface)">
                <span style="font-size:.72rem;font-weight:700;color:var(--text-muted)">Slot <?= $slotNum ?></span>
                <span style="width:7px;height:7px;border-radius:50%;background:<?= ($b['activo'] && $hasImg) ? 'var(--success)' : '#cbd5e1' ?>"></span>
            </div>
        </button>
    <?php endforeach; ?>
    </div>

    <!-- Panel de edición: solo el slot seleccionado es visible -->
    <div style="border-top:1px solid var(--border);padding:20px">
    <?php foreach ($banners as $i => $b):
        $slotNum = $i + 1;
        $hasImg  = !empty($b['imagen_path']) || !empty($b['imagen_url']);
    ?>
    <div class="banner-panel" data-panel="<?= $slotNum ?>" style="<?= $i === 0 ? '' : 'display:none' ?>">

        <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px">
            <span style="font-size:.95rem;font-weight:700">Slot <?= $slotNum ?></span>
            <span style="font-size:.72rem;font-weight:700;padding:3px 10px;border-radius:20px;<?= ($b['activo'] && $hasImg) ? 'background:#dcfce7;color:#166534' : 'background:#f1f5f9;color:#94a3b8' ?>">
                <?= ($b['activo'] && $hasImg) ? 'Activo' : 'Vacío' ?>
            </span>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="banner_id"   value="<?= $b['id'] ?>">
            <input type="hidden" name="slot_num"    value="<?= $slotNum ?>">
            <input type="hidden" name="imagen_url"  class="hid-img-url"  value="<?= htmlspecialchars($b['imagen_url'] ?? '') ?>">
            <input type="hidden" name="producto_id" class="hid-prod-id"  value="<?= $b['producto_id'] ?? '' ?>">
            <input type="hidden" name="enlace"      class="hid-enlace"   value="<?= htmlspecialchars($b['enlace'] ?? '') ?>">
            <input type="hidden" name="subtitulo"   value="<?= htmlspecialchars($b['subtitulo'] ?? '') ?>">

            <div style="display:grid;grid-template-columns:1.4fr 1fr 1fr;gap:12px;align-items:end">

                <!-- Selector de producto -->
                <div class="form-group" style="margin:0">
                    <label class="form-label" style="font-size:.78rem">Producto</label>
                    <select class="form-control prod-select"
                            data-current="<?= $b['producto_id'] ?? '' ?>"
                            style="font-size:.82rem">
                        <option value="">— Cargando productos… —</option>
                    </select>
                    <div class="form-hint" style="font-size:.7rem">Al seleccionar se usa la imagen del producto</div>
                </div>

                <!-- Título -->
                <div class="form-group" style="margin:0">
                    <label class="form-label" style="font-size:.78rem">Título (opcional)</label>
                    <input type="text" name="titulo" class="form-control slot-titulo"
                           value="<?= htmlspecialchars($b['titulo'] ?? '') ?>"
                           placeholder="Ej: Paracetamol 500mg"
                           style="font-size:.82rem">
                </div>

                <!-- Imagen personalizada -->
                <div class="form-group" style="margin:0">
                    <label class="form-label" style="font-size:.78rem">
                        Imagen propia
                        <span style="color:var(--text-muted);font-weight:400">(reemplaza la del producto)</span>
                    </label>
                    <input type="file" name="banner_img" class="form-control" accept="image/*" style="font-size:.78rem;padding:4px 8px">
                </div>
            </div>

            <!-- Acciones -->
            <div style="display:flex;align-items:center;gap:10px;margin-top:12px">
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.82rem">
                    <input type="checkbox" name="activo" value="1" <?= $b['activo'] ? 'checked' : '' ?>>
                    Mostrar en slider
                </label>
                <div style="flex:1"></div>
                <?php if ($hasImg): ?>
                <a href="?clear=<?= $b['id'] ?>"
                   onclick="return confirm('¿Limpiar el banner del slot <?= $slotNum ?>?')"
                   class="btn btn-secondary" style="padding:5px 12px;font-size:.78rem">
                    <i class="fas fa-times"></i> Limpiar
                </a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary" style="padding:5px 16px;font-size:.82rem">
                    <i class="fas fa-save"></i> Guardar
                </button>
            </div>
        </form>
    </div>
    <?php endforeach; ?>
    </div>
</div>

<script>
(function() {
    document.querySelectorAll('.banner-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            const slot = tab.dataset.tab;
            document.querySelectorAll('.banner-tab').forEach(t => t.style.borderColor = 'var(--border)');
            tab.style.borderColor = 'var(--primary)';
            document.querySelectorAll('.banner-panel').forEach(p => {
                p.style.display = (p.dataset.panel === slot) ? '' : 'none';
            });
        });
    });

    let cache = [];

    function populateSelects() {
        document.querySelectorAll('.prod-select').forEach(sel => {
            const currentId = sel.dataset.current || '';
            sel.innerHTML = '<option value="">— Sin producto —</option>';
            cache.forEach(p => {
                const opt       = document.createElement('option');
                opt.value       = p.id;
                opt.textContent = p.nombre;
                opt.dataset.imagen = p.imagen || '';
                if (String(p.id) === String(currentId)) opt.selected = true;
                sel.appendChild(opt);
            });
        });
    }

    fetch(window.BASE_URL + '/api/index.php?action=productos')
        .then(r => r.json())
        .then(data => {
            if (data.success) cache = data.data;
            populateSelects();
        })
        .catch(() => {
            document.querySelectorAll('.prod-select').forEach(s => {
                s.innerHTML = '<option value="">— Error al cargar —</option>';
            });
        });

    document.addEventListener('change', function(e) {
        if (!e.target.classList.contains('prod-select')) return;
        const sel  = e.target;
        const form = sel.closest('form');
        const opt  = sel.options[sel.selectedIndex];
        const id   = sel.value;
        const img  = opt ? (opt.dataset.imagen || '') : '';
        const nom  = opt ? opt.textContent : '';

        form.querySelector('.hid-prod-id').value = id;
        form.querySelector('.hid-img-url').value = img;

        if (id) {
            // Pre-rellenar título si está vacío
            const titInput = form.querySelector('.slot-titulo');
            if (!titInput.value) titInput.value = nom;
            // Pre-rellenar enlace
            form.querySelector('.hid-enlace').value = window.BASE_URL + '/producto.php?id=' + id;
        } else {
            form.querySelector('.hid-img-url').value = '';
            form.querySelector('.hid-enlace').value  = '';
        }
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

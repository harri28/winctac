<?php
// ============================================================
// DESCARGA DE CV — streaming de PDF, gateado por sesión de admin
// (fuera de admin/api.php porque ese archivo fuerza Content-Type JSON)
// ============================================================
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

if (empty($_SESSION['admin_id'])) {
    http_response_code(403);
    exit('No autorizado');
}

$pdo = getDB();

// Igual que requireAdmin(): revalida que el admin logueado pertenezca a
// TIENDA_ID, no solo que exista sesión (evita que una cookie de otra tienda
// sirva para descargar CVs de esta).
$check = $pdo->prepare('SELECT 1 FROM admin_usuarios WHERE id = ? AND tienda_id = ?');
$check->execute([$_SESSION['admin_id'], TIENDA_ID]);
if (!$check->fetchColumn()) {
    http_response_code(403);
    exit('No autorizado');
}

$id  = intval($_GET['id'] ?? 0);
$row = $pdo->prepare('SELECT nombre, cv_path FROM postulaciones WHERE id = ? AND tienda_id = ?');
$row->execute([$id, TIENDA_ID]);
$postulante = $row->fetch();

$path = $postulante ? UPLOADS_PATH . '/postulaciones/' . $postulante['cv_path'] : null;
if (!$postulante || !file_exists($path)) {
    http_response_code(404);
    exit('CV no encontrado');
}

$nombreArchivo = preg_replace('/[^A-Za-z0-9_-]/', '_', $postulante['nombre']);

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="CV_' . $nombreArchivo . '.pdf"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;

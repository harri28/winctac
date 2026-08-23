<?php
// ============================================================
// SETUP — Ejecutar schema SQL en BD selvadigital
// Acceder: http://localhost/ecomerce/database/setup.php
// ============================================================
require_once __DIR__ . '/../config/app.php';

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '5432';
$name = getenv('DB_NAME') ?: 'selvadigital';
$dsn  = "pgsql:host=$host;port=$port;dbname=$name";
$user = getenv('DB_USER') ?: 'postgres';
$pass = getenv('DB_PASS') ?: '1234';

echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<title>Setup Selvadigital</title>
<style>
body{font-family:system-ui,sans-serif;max-width:700px;margin:40px auto;padding:20px;background:#f8fafc}
h1{color:#0e7490}
.ok{background:#dcfce7;border-left:4px solid #16a34a;padding:10px 14px;margin:8px 0;border-radius:4px}
.err{background:#fee2e2;border-left:4px solid #dc2626;padding:10px 14px;margin:8px 0;border-radius:4px}
.info{background:#e0f2fe;border-left:4px solid #0891b2;padding:10px 14px;margin:8px 0;border-radius:4px}
a{color:#0e7490}
</style></head><body>';

echo '<h1>Setup — Selvadigital E-commerce</h1>';

try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo '<div class="ok">✓ Conexión a BD <strong>selvadigital</strong> exitosa</div>';

    $sql = file_get_contents(__DIR__ . '/schema.sql');
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn($s) => strlen($s) > 5
    );

    $ok = 0; $err = 0;
    foreach ($statements as $stmt) {
        try {
            $pdo->exec($stmt);
            $ok++;
        } catch (PDOException $e) {
            echo '<div class="err">✗ ' . htmlspecialchars(substr($stmt, 0, 80)) . '...<br><small>' . htmlspecialchars($e->getMessage()) . '</small></div>';
            $err++;
        }
    }

    echo "<div class=\"ok\">✓ $ok sentencias ejecutadas correctamente</div>";
    if ($err) echo "<div class=\"err\">✗ $err sentencias con error</div>";

    echo '<div class="info">
        <strong>Credenciales de admin por defecto:</strong><br>
        Email: admin<br>
        Contraseña: admin123
    </div>';

} catch (PDOException $e) {
    echo '<div class="err">✗ Error de conexión: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

echo '<p><a href="' . BASE_URL . '/">→ Ir a la tienda</a> &nbsp;|&nbsp; <a href="' . BASE_URL . '/admin/login.php">→ Panel admin</a></p>';
echo '</body></html>';

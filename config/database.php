<?php
// ============================================================
// CONEXIÓN PDO — BD selvadigital (PostgreSQL)
// ============================================================

// En dev local no hay pool de PHP-FPM que inyecte DB_* via `env[...]` como en
// prod. Un `config/env.local.php` (gitignored) con putenv() cubre ese hueco
// sin relajar la validación de abajo para producción.
if (file_exists(__DIR__ . '/env.local.php')) {
    require_once __DIR__ . '/env.local.php';
}

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $host = getenv('DB_HOST');
        $port = getenv('DB_PORT');
        $name = getenv('DB_NAME');
        $user = getenv('DB_USER');
        $pass = getenv('DB_PASS');
        if (!$host || !$port || !$name || !$user || $pass === false) {
            throw new RuntimeException('Database connection environment is incomplete.');
        }
        $pdo = new PDO(
            "pgsql:host=$host;port=$port;dbname=$name",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}

function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

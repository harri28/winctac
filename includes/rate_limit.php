<?php
// ============================================================
// RATE LIMITING DE LOGIN — Bloqueo temporal tras intentos fallidos
// Clave = IP + identificador de cuenta, así un intento de fuerza
// bruta contra una cuenta no bloquea a otros usuarios de la misma IP.
// ============================================================

function loginClave(string $identificador): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return $ip . '|' . strtolower(trim($identificador));
}

// Retorna segundos restantes de bloqueo, o null si no está bloqueado.
// La comparación de tiempo se hace en SQL (NOW()) para no depender de que
// la zona horaria de PHP coincida con la del servidor de PostgreSQL.
function loginBloqueado(PDO $pdo, string $clave): ?int {
    $stmt = $pdo->prepare("
        SELECT GREATEST(0, CEIL(EXTRACT(EPOCH FROM (bloqueado_hasta - NOW()))))::int AS restante
        FROM login_intentos
        WHERE clave = ? AND bloqueado_hasta > NOW()
    ");
    $stmt->execute([$clave]);
    $row = $stmt->fetch();
    return $row ? (int)$row['restante'] : null;
}

function registrarIntentoFallido(PDO $pdo, string $clave, int $maxIntentos = 5, int $bloqueoMinutos = 15): void {
    $pdo->prepare("
        INSERT INTO login_intentos (clave, intentos, bloqueado_hasta, updated_at)
        VALUES (?, 1, NULL, NOW())
        ON CONFLICT (clave) DO UPDATE SET
            intentos = login_intentos.intentos + 1,
            bloqueado_hasta = CASE
                WHEN login_intentos.intentos + 1 >= ? THEN NOW() + (? || ' minutes')::interval
                ELSE login_intentos.bloqueado_hasta
            END,
            updated_at = NOW()
    ")->execute([$clave, $maxIntentos, $bloqueoMinutos]);
}

function limpiarIntentos(PDO $pdo, string $clave): void {
    $pdo->prepare('DELETE FROM login_intentos WHERE clave = ?')->execute([$clave]);
}

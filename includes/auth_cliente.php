<?php
// ============================================================
// AUTH — Sesión de cliente
// ============================================================

function clienteLogueado(): bool {
    return !empty($_SESSION['cliente_id']);
}

function clienteId(): int {
    return (int)($_SESSION['cliente_id'] ?? 0);
}

function clienteNombre(): string {
    return $_SESSION['cliente_nombre'] ?? '';
}

function requireClienteAuth(): void {
    if (!clienteLogueado()) {
        $redirect = urlencode($_SERVER['REQUEST_URI']);
        header('Location: ' . BASE_URL . '/cuenta/login?redirect=' . $redirect);
        exit;
    }
}

// Destino seguro para ?redirect= tras login/registro: solo URLs de la propia
// tienda (absolutas bajo BASE_URL o rutas locales "/..."), nunca otro dominio.
function redirectInterno(?string $url): string {
    $url = (string) $url;
    if ($url !== '' && (strpos($url, BASE_URL . '/') === 0
        || ($url[0] === '/' && !preg_match('#^/[/\\\\]#', $url)))) {
        return $url;
    }
    return BASE_URL . '/';
}

function loginCliente(array $cliente): void {
    $_SESSION['cliente_id']     = $cliente['id'];
    $_SESSION['cliente_nombre'] = $cliente['nombre'];
    $_SESSION['cliente_email']  = $cliente['email'];
}

function logoutCliente(): void {
    unset($_SESSION['cliente_id'], $_SESSION['cliente_nombre'], $_SESSION['cliente_email']);
}

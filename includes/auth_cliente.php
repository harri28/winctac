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
        header('Location: ' . BASE_URL . '/cuenta/login.php?redirect=' . $redirect);
        exit;
    }
}

function loginCliente(array $cliente): void {
    $_SESSION['cliente_id']     = $cliente['id'];
    $_SESSION['cliente_nombre'] = $cliente['nombre'];
    $_SESSION['cliente_email']  = $cliente['email'];
}

function logoutCliente(): void {
    unset($_SESSION['cliente_id'], $_SESSION['cliente_nombre'], $_SESSION['cliente_email']);
}

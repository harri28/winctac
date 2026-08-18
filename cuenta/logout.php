<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
logoutCliente();
header('Location: ' . BASE_URL . '/');
exit;

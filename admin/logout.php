<?php
require_once __DIR__ . '/../config/app.php';
unset($_SESSION['admin_id'], $_SESSION['admin_nombre']);
header('Location: ' . BASE_URL . '/admin/login.php');
exit;

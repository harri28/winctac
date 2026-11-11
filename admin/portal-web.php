<?php
// Portal Web se fusionó con "Datos de la tienda" en Configuración (admin/config.php).
// Se conserva esta URL para que los enlaces y marcadores antiguos sigan funcionando.
require_once __DIR__ . '/../config/app.php';
header('Location: ' . BASE_URL . '/admin/config.php#datos-tienda', true, 301);
exit;

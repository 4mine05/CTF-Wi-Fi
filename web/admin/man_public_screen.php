<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
requireAdmin();

// Este endpoint solo acepta acciones enviadas desde formularios POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

$enabled = $_POST['enabled'] ?? '';

// La pantalla publica solo admite los estados apagado o encendido.
if (!in_array($enabled, ['0', '1'], true)) {
    http_response_code(400);
    exit('Valor no válido.');
}

// Guarda el nuevo estado de visibilidad de la pantalla publica.
$stmt = $pdo->prepare("
    UPDATE app_config
    SET config_value = ?, updated_at = NOW()
    WHERE config_key = 'public_screen_enabled'
");
$stmt->execute([$enabled]);

header('Location: /admin/users.php');
exit;

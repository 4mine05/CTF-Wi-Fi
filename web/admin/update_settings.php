<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
requireAdmin();

// Este endpoint solo acepta acciones enviadas desde formularios POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

$maxPlayers = (int)($_POST['max_players'] ?? 0);

// Limita el valor para evitar configuraciones absurdas o peligrosas.
if ($maxPlayers < 1 || $maxPlayers > 500) {
    http_response_code(400);
    exit('El límite de plazas debe estar entre 1 y 500.');
}

try {
    // Actualiza la configuracion despues de validar las plazas ya aprobadas.
    $pdo->beginTransaction();

    // No permite bajar el limite por debajo de jugadores ya aprobados.
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM users
        WHERE status = 'approved'
    ");
    $approvedCount = (int)$stmt->fetchColumn();

    if ($maxPlayers < $approvedCount) {
        throw new RuntimeException(
            "No puedes poner un límite menor que los usuarios ya aprobados ({$approvedCount})."
        );
    }

    // Guarda el nuevo limite en la tabla de configuracion.
    $stmt = $pdo->prepare("
        INSERT INTO app_config (config_key, config_value, updated_at)
        VALUES ('max_players', ?, NOW())
        ON DUPLICATE KEY UPDATE
            config_value = VALUES(config_value),
            updated_at = NOW()
    ");
    $stmt->execute([(string)$maxPlayers]);


    $pdo->commit();
    header('Location: /admin/users.php');
    exit;
} catch (Throwable $e) {
    // Revierte el cambio si falla la validacion o escritura.
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    exit($e->getMessage());
}

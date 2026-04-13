<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

$maxPlayers = (int)($_POST['max_players'] ?? 0);

if ($maxPlayers < 1 || $maxPlayers > 500) {
    http_response_code(400);
    exit('El límite de plazas debe estar entre 1 y 500.');
}

try {
    $pdo->beginTransaction();

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
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    exit($e->getMessage());
}

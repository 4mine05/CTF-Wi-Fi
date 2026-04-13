<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

$userId = (int)($_POST['user_id'] ?? 0);
if ($userId <= 0) {
    exit('ID de usuario no válido.');
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT id, alias, role, status
        FROM users
        WHERE id = ?
        FOR UPDATE
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        throw new RuntimeException('Usuario no encontrado.');
    }

    if ($user['role'] !== 'player') {
        throw new RuntimeException('Solo se pueden aprobar jugadores.');
    }

    if (!in_array($user['status'], ['pending_review', 'waitlisted'], true)) {
        throw new RuntimeException('Ese usuario no está pendiente ni en lista de espera.');
    }

    if ($user['status'] === 'waitlisted') {
        $maxPlayers = getConfigInt($pdo, 'max_players', 30);
        $reservedSlots = countReservedSlots($pdo);

        if ($reservedSlots >= $maxPlayers) {
            throw new RuntimeException('No hay plazas libres para aprobar a este usuario.');
        }

        $stmt = $pdo->prepare("
            UPDATE waitlist
            SET status = 'promoted', promoted_at = NOW()
            WHERE user_id = ? AND status = 'waiting'
        ");
        $stmt->execute([$userId]);
    }

    $stmt = $pdo->prepare("
        UPDATE users
        SET status = 'approved',
            approved_at = NOW(),
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$userId]);

    $stmt = $pdo->prepare("
        INSERT INTO player_envs (
            user_id,
            env_status,
            container_name,
            ssh_host,
            ssh_port,
            container_username,
            initial_password_enc,
            provision_requested_at,
            created_at,
            activated_at,
            finished_at,
            last_sync_at,
            error_message
        )
        VALUES (
            ?, 'pending',
            NULL, NULL, NULL, NULL, NULL,
            NOW(),
            NULL, NULL, NULL, NULL, NULL
        )
        ON DUPLICATE KEY UPDATE
            env_status = 'pending',
            container_name = NULL,
            ssh_host = NULL,
            ssh_port = NULL,
            container_username = NULL,
            initial_password_enc = NULL,
            provision_requested_at = NOW(),
            created_at = NULL,
            activated_at = NULL,
            finished_at = NULL,
            last_sync_at = NULL,
            error_message = NULL
    ");
$stmt->execute([$userId]);

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

<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
requireAdmin();

// Este endpoint solo acepta acciones enviadas desde formularios POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

$userId = (int)($_POST['user_id'] ?? 0);
if ($userId <= 0) {
    exit('ID de usuario no válido.');
}

try {
    // Revierte la aprobacion y el entorno pendiente en una transaccion.
    $pdo->beginTransaction();

    // Bloquea el usuario para validar rol y estado.
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
        throw new RuntimeException('Solo se puede desaprobar a jugadores.');
    }

    if ($user['status'] !== 'approved') {
        throw new RuntimeException('Solo se puede desaprobar a usuarios aprobados.');
    }

    // Comprueba que no exista un entorno ya creado o activo.
    $stmt = $pdo->prepare("
        SELECT env_status
        FROM player_envs
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $env = $stmt->fetch();

    if ($env && in_array($env['env_status'], ['creating', 'created', 'active'], true)) {
        throw new RuntimeException(
            'No se puede desaprobar a este usuario porque ya tiene un entorno creado o activo.'
        );
    }

    // Devuelve la cuenta a revision pendiente.
    $stmt = $pdo->prepare("
        UPDATE users
        SET status = 'pending_review',
            approved_at = NULL,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$userId]);

    // Cancela la solicitud de provisionamiento del entorno.
    $stmt = $pdo->prepare("
        UPDATE player_envs
        SET env_status = 'not_created',
            provision_requested_at = NULL
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);

    $pdo->commit();
    header('Location: /admin/users.php');
    exit;
} catch (Throwable $e) {
    // Revierte la desaprobacion si falla alguna parte.
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    exit($e->getMessage());
}

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
    // Mueve al jugador a espera junto con su entrada de waitlist.
    $pdo->beginTransaction();

    // Bloquea el usuario para validar que sigue pendiente.
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
        throw new RuntimeException('Solo se pueden mover jugadores a la lista de espera.');
    }

    if ($user['status'] !== 'pending_review') {
        throw new RuntimeException('Solo se puede enviar a espera a usuarios pendientes de revisión.');
    }

    // Cambia la cuenta a estado de lista de espera.
    $stmt = $pdo->prepare("
        UPDATE users
        SET status = 'waitlisted', updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$userId]);

    // Crea o reactiva la entrada de lista de espera.
    $stmt = $pdo->prepare("
        INSERT INTO waitlist (user_id, joined_at, status)
        VALUES (?, NOW(), 'waiting')
        ON DUPLICATE KEY UPDATE
            status = 'waiting',
            joined_at = NOW(),
            promoted_at = NULL,
            removed_at = NULL
    ");
    $stmt->execute([$userId]);


    $pdo->commit();
    header('Location: /admin/users.php');
    exit;
} catch (Throwable $e) {
    // Revierte el movimiento si falla cualquier parte.
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    exit($e->getMessage());
}

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
    // Agrupa la eliminacion logica, lista de espera y entorno en una transaccion.
    $pdo->beginTransaction();

    // Bloquea el usuario para validar su estado actual.
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

    if ((int)$user['id'] === (int)$_SESSION['user']['id']) {
        throw new RuntimeException('No puedes eliminar tu propia cuenta de administrador.');
    }

    if ($user['role'] === 'admin') {
        throw new RuntimeException('No se permite eliminar cuentas de administrador desde este panel.');
    }

    if ($user['status'] === 'deleted') {
        throw new RuntimeException('El usuario ya estaba eliminado.');
    }

    // Marca la cuenta como eliminada sin borrar el registro historico.
    $stmt = $pdo->prepare("
        UPDATE users
        SET status = 'deleted',
            deleted_at = NOW(),
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$userId]);

    // Retira al usuario de la lista de espera si estaba esperando.
    $stmt = $pdo->prepare("
        UPDATE waitlist
        SET status = 'removed',
            removed_at = NOW()
        WHERE user_id = ? AND status = 'waiting'
    ");
    $stmt->execute([$userId]);

    // Cierra o descarta el entorno asociado segun su estado actual.
    $stmt = $pdo->prepare("
        UPDATE player_envs
        SET env_status = CASE
                WHEN env_status IN ('creating', 'created', 'active', 'finished') THEN 'finished'
                ELSE 'not_created'
            END,
            provision_requested_at = NULL,
            finished_at = CASE
                WHEN env_status IN ('creating', 'created', 'active', 'finished') THEN NOW()
                ELSE finished_at
            END
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);

    $pdo->commit();
    header('Location: /admin/users.php');
    exit;
} catch (Throwable $e) {
    // Revierte la eliminacion si falla alguna parte.
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    exit($e->getMessage());
}

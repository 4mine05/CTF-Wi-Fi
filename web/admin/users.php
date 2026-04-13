<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
requireAdmin();

$publicScreenEnabled = getConfig($pdo, 'public_screen_enabled', '1') === '1';
$maxPlayers = getConfigInt($pdo, 'max_players', 30);

$stmt = $pdo->query("
    SELECT COUNT(*) FROM users WHERE status IN ('pending_review', 'approved')
");
$reservedSlots = (int)$stmt->fetchColumn();

$stmt = $pdo->query("
    SELECT COUNT(*) FROM users WHERE status = 'approved'
");
$approvedCount = (int)$stmt->fetchColumn();

$stmt = $pdo->query("
    SELECT COUNT(*) FROM users WHERE status = 'pending_review'
");
$pendingCount = (int)$stmt->fetchColumn();

$stmt = $pdo->query("
    SELECT COUNT(*) FROM users WHERE status = 'waitlisted'
");
$waitlistedCount = (int)$stmt->fetchColumn();

$stmt = $pdo->query("
    SELECT COUNT(*) FROM users WHERE status = 'deleted'
");
$deletedCount = (int)$stmt->fetchColumn();

$freeSlots = max(0, $maxPlayers - $reservedSlots);

$stmt = $pdo->query("
    SELECT 
        u.id,
        u.alias,
        u.role,
        u.status,
        u.created_at,
        u.approved_at,
        u.last_login_at,
        e.env_status
    FROM users u
    LEFT JOIN player_envs e ON e.user_id = u.id
    ORDER BY
        FIELD(u.role, 'admin', 'player'),
        FIELD(u.status, 'pending_review', 'approved', 'waitlisted', 'blocked', 'deleted'),
        u.created_at DESC
");
$allUsers = $stmt->fetchAll();
// Traducir a español
function userStatusLabel(string $status): string
{
    return match ($status) {
        'pending_review' => 'Pendiente',
        'approved' => 'Aprobado',
        'waitlisted' => 'Lista de espera',
        'deleted' => 'Eliminado',
        default => $status,
    };
}
// Traducir a español
function envStatusLabel(?string $status): string
{
    return match ($status) {
        null => '-',
        'not_created' => 'Sin crear',
        'pending' => 'Pendiente',
        'creating' => 'Creando',
        'created' => 'Creado',
        'active' => 'Activo',
        'finished' => 'Finalizado',
        'error' => 'Error',
        default => $status,
    };
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel admin - Usuarios</title>
    <!-- <style>
        body {
            font-family: Arial, sans-serif;
            margin: 24px;
        }
        .cards {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }
        .card {
            border: 1px solid #ccc;
            border-radius: 8px;
            padding: 12px 16px;
            min-width: 180px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 10px;
            vertical-align: top;
            text-align: left;
        }
        th {
            background: #f3f3f3;
        }
        form.inline {
            display: inline-block;
            margin: 2px;
        }
        .actions {
            min-width: 260px;
        }
    </style>
-->
</head>
<body>
    <h1>Panel de administración</h1>

    <p>Administrador: <strong><?= h((string)$_SESSION['user']['alias']) ?></strong></p>
    <p><a href="/public/logout.php">Cerrar sesión</a></p>

    <h2>Configuración de plazas</h2>
    <form method="post" action="/admin/update_settings.php">
        <label>
            Límite máximo de plazas:
            <input
                type="number"
                name="max_players"
                min="1"
                max="500"
                value="<?= h((string)$maxPlayers) ?>"
                required
            >
        </label>
        <button type="submit">Guardar</button>
    </form>

    <div class="cards">
        <div class="card">
            <strong>Reservadas/Ocupadas</strong><br>
            <?= h((string)$reservedSlots) ?> / <?= h((string)$maxPlayers) ?>
        </div>

        <div class="card">
            <strong>Plazas libres</strong><br>
            <?= h((string)$freeSlots) ?>
        </div>

        <div class="card">
            <strong>Aprobados</strong><br>
            <?= h((string)$approvedCount) ?>
        </div>

        <div class="card">
            <strong>Pendientes</strong><br>
            <?= h((string)$pendingCount) ?>
        </div>

        <div class="card">
            <strong>En espera</strong><br>
            <?= h((string)$waitlistedCount) ?>
        </div>

        <div class="card">
            <strong>Eliminados</strong><br>
            <?= h((string)$deletedCount) ?>
        </div>
    </div>

    <h2>Pantalla pública</h2>
    <p>
        Estado actual:
        <strong><?= $publicScreenEnabled ? 'Habilitada' : 'Deshabilitada' ?></strong>
    </p>

    <form method="post" action="/admin/man_public_screen.php"
        onsubmit="return confirm('¿Seguro que quieres cambiar el estado de la pantalla pública?');">
        <input type="hidden" name="enabled" value="<?= $publicScreenEnabled ? '0' : '1' ?>">
        <button type="submit">
            <?= $publicScreenEnabled ? 'Deshabilitar pantalla pública' : 'Habilitar pantalla pública' ?>
        </button>
        <p> <a href="/admin/screen.php" target="_blank">Abrir pantalla</a> </p>
    </form>

    <h2>Todos los usuarios</h2>

    <?php if (!$allUsers): ?>
        <p>No hay usuarios registrados.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Alias</th>
                    <th>Rol</th>
                    <th>Estado</th>
                    <th>Entorno</th>
                    <th>Creado</th>
                    <th>Aprobado</th>
                    <th>Último login</th>
                    <th class="actions">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allUsers as $user): ?>
                    <tr>
                        <td><?= h((string)$user['id']) ?></td>
                        <td><?= h((string)$user['alias']) ?></td>
                        <td><?= h((string)$user['role']) ?></td>
                        <td><?= h(userStatusLabel((string)$user['status'])) ?></td>
                        <td><?= h(envStatusLabel($user['env_status'] ?? null)) ?></td>
                        <td><?= h((string)$user['created_at']) ?></td>
                        <td><?= h((string)($user['approved_at'] ?? '-')) ?></td>
                        <td><?= h((string)($user['last_login_at'] ?? '-')) ?></td>
                        <td class="actions">
                            <?php if ($user['role'] === 'admin'): ?>
                                <em>Sin acciones para admin</em>

                            <?php elseif ($user['status'] === 'pending_review'): ?>
                                <form class="inline" method="post" action="/admin/approve_user.php">
                                    <input type="hidden" name="user_id" value="<?= h((string)$user['id']) ?>">
                                    <button type="submit">Aprobar</button>
                                </form>

                                <form class="inline" method="post" action="/admin/send_to_waitlist.php">
                                    <input type="hidden" name="user_id" value="<?= h((string)$user['id']) ?>">
                                    <button type="submit">Enviar a espera</button>
                                </form>

                                <form class="inline" method="post" action="/admin/delete_user.php"
                                      onsubmit="return confirm('¿Eliminar este usuario?');">
                                    <input type="hidden" name="user_id" value="<?= h((string)$user['id']) ?>">
                                    <button type="submit">Eliminar</button>
                                </form>

                            <?php elseif ($user['status'] === 'waitlisted'): ?>
                                <form class="inline" method="post" action="/admin/approve_user.php">
                                    <input type="hidden" name="user_id" value="<?= h((string)$user['id']) ?>">
                                    <button type="submit">Aprobar si hay plaza</button>
                                </form>

                                <form class="inline" method="post" action="/admin/delete_user.php"
                                      onsubmit="return confirm('¿Eliminar este usuario?');">
                                    <input type="hidden" name="user_id" value="<?= h((string)$user['id']) ?>">
                                    <button type="submit">Eliminar</button>
                                </form>

                            <?php elseif ($user['status'] === 'approved'): ?>
    				<form class="inline" method="post" action="/admin/unapprove_user.php"
          				onsubmit="return confirm('¿Devolver este usuario a pendiente de revisión?');">
        				<input type="hidden" name="user_id" value="<?= h((string)$user['id']) ?>">
        				<button type="submit">Desaprobar</button>
    				</form>

    				<form class="inline" method="post" action="/admin/delete_user.php"
    				      onsubmit="return confirm('¿Eliminar este usuario?');">
    				    <input type="hidden" name="user_id" value="<?= h((string)$user['id']) ?>">
    				    <button type="submit">Eliminar</button>
    				</form>
                            <?php elseif ($user['status'] === 'deleted'): ?>
                                <em>Usuario eliminado</em>

                            <?php else: ?>
                                <em>Sin acciones</em>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</body>
</html>

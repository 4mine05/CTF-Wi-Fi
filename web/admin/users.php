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
        FIELD(u.status, 'pending_review', 'approved', 'waitlisted', 'deleted'),
        u.created_at DESC
");
$allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
        default => (string)$status,
    };
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel admin</title>
    <link rel="stylesheet" href="/stylesheet/styles.css">
</head>
<body>
    <div class="wrapper wide">
        <div class="topbar">
            <div>Administrador: <strong><?= h((string)$_SESSION['user']['alias']) ?></strong></div>
            <div>
                <a href="/admin/screen.php" target="_blank" rel="noopener noreferrer">Abrir pantalla</a> |
                <a href="/public/logout.php">Cerrar sesión</a>
            </div>
        </div>

        <div class="card">
            <div class="eyebrow">Administración</div>
            <h1>Panel de control</h1>
            <p class="muted">
                Gestiona plazas, estados de usuarios y visibilidad pública del evento desde una sola vista.
            </p>
        </div>

        <div class="cards actions-top">
            <div class="card compact center">
                <div class="muted">Reservadas/Ocupadas</div>
                <div class="big"><?= h((string)$reservedSlots) ?> / <?= h((string)$maxPlayers) ?></div>
            </div>
            <div class="card compact center">
                <div class="muted">Plazas libres</div>
                <div class="big"><?= h((string)$freeSlots) ?></div>
            </div>
            <div class="card compact center">
                <div class="muted">Aprobados</div>
                <div class="big"><?= h((string)$approvedCount) ?></div>
            </div>
            <div class="card compact center">
                <div class="muted">Pendientes</div>
                <div class="big"><?= h((string)$pendingCount) ?></div>
            </div>
            <div class="card compact center">
                <div class="muted">En espera</div>
                <div class="big"><?= h((string)$waitlistedCount) ?></div>
            </div>
            <div class="card compact center">
                <div class="muted">Eliminados</div>
                <div class="big"><?= h((string)$deletedCount) ?></div>
            </div>
        </div>

        <div class="grid equal actions-top">
            <div class="card">
                <h2>Configuracion de plazas</h2>
                <p class="muted">
                    Ajusta el numero maximo de plazas reservadas u ocupadas dentro del evento.
                </p>

                <form method="post" action="/admin/update_settings.php">
                    <div class="field">
                        <label for="max_players">Limite máximo de plazas</label>
                        <input
                            type="number"
                            name="max_players"
                            id="max_players"
                            min="1"
                            max="500"
                            value="<?= h((string)$maxPlayers) ?>"
                            required
                        >
                    </div>

                    <div class="actions">
                        <button type="submit" class="btn btn-primary">Guardar</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <h2>Pantalla pública</h2>
                <div class="message <?= $publicScreenEnabled ? 'success' : 'warning' ?>">
                    Estado actual: <strong><?= $publicScreenEnabled ? 'Habilitada' : 'Deshabilitada' ?></strong>
                </div>

                <form
                    method="post"
                    action="/admin/man_public_screen.php"
                    onsubmit="return confirm('Cambiar el estado de la pantalla publica?');"
                >
                    <input type="hidden" name="enabled" value="<?= $publicScreenEnabled ? '0' : '1' ?>">

                    <div class="actions">
                        <button
                            type="submit"
                            class="btn <?= $publicScreenEnabled ? 'btn-danger' : 'btn-primary' ?>"
                        >
                            <?= $publicScreenEnabled ? 'Deshabilitar pantalla publica' : 'Habilitar pantalla publica' ?>
                        </button>
                        <a href="/admin/screen.php" target="_blank" rel="noopener noreferrer" class="btn btn-secondary">
                            Abrir pantalla
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card actions-top">
            <h2>Todos los usuarios</h2>
            <p class="muted">
                Vista general de cuentas, entorno asignado y acciones disponibles segun el estado del usuario.
            </p>

            <?php if (!$allUsers): ?>
                <div class="empty-state">No hay usuarios registrados.</div>
            <?php else: ?>
                <div class="table-wrap">
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
                                <th class="actions-col">Acciones</th>
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
                                    <td class="actions-col">
                                        <?php if ($user['role'] === 'admin'): ?>
                                            <span class="muted">Sin acciones para admin</span>

                                        <?php elseif ($user['status'] === 'pending_review'): ?>
                                            <form class="inline" method="post" action="/admin/approve_user.php">
                                                <input type="hidden" name="user_id" value="<?= h((string)$user['id']) ?>">
                                                <button type="submit" class="btn btn-primary">Aprobar</button>
                                            </form>

                                            <form class="inline" method="post" action="/admin/send_to_waitlist.php">
                                                <input type="hidden" name="user_id" value="<?= h((string)$user['id']) ?>">
                                                <button type="submit" class="btn btn-secondary">Enviar a espera</button>
                                            </form>

                                            <form
                                                class="inline"
                                                method="post"
                                                action="/admin/delete_user.php"
                                                onsubmit="return confirm('Eliminar este usuario?');"
                                            >
                                                <input type="hidden" name="user_id" value="<?= h((string)$user['id']) ?>">
                                                <button type="submit" class="btn btn-danger">Eliminar</button>
                                            </form>

                                        <?php elseif ($user['status'] === 'waitlisted'): ?>
                                            <form class="inline" method="post" action="/admin/approve_user.php">
                                                <input type="hidden" name="user_id" value="<?= h((string)$user['id']) ?>">
                                                <button type="submit" class="btn btn-primary">Aprobar si hay plaza</button>
                                            </form>

                                            <form
                                                class="inline"
                                                method="post"
                                                action="/admin/delete_user.php"
                                                onsubmit="return confirm('Eliminar este usuario?');"
                                            >
                                                <input type="hidden" name="user_id" value="<?= h((string)$user['id']) ?>">
                                                <button type="submit" class="btn btn-danger">Eliminar</button>
                                            </form>

                                        <?php elseif ($user['status'] === 'approved'): ?>
                                            <form
                                                class="inline"
                                                method="post"
                                                action="/admin/unapprove_user.php"
                                                onsubmit="return confirm('Devolver este usuario a pendiente de revision?');"
                                            >
                                                <input type="hidden" name="user_id" value="<?= h((string)$user['id']) ?>">
                                                <button type="submit" class="btn btn-secondary">Desaprobar</button>
                                            </form>

                                            <form
                                                class="inline"
                                                method="post"
                                                action="/admin/delete_user.php"
                                                onsubmit="return confirm('Eliminar este usuario?');"
                                            >
                                                <input type="hidden" name="user_id" value="<?= h((string)$user['id']) ?>">
                                                <button type="submit" class="btn btn-danger">Eliminar</button>
                                            </form>

                                        <?php elseif ($user['status'] === 'deleted'): ?>
                                            <span class="muted">Usuario eliminado</span>

                                        <?php else: ?>
                                            <span class="muted">Sin acciones</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

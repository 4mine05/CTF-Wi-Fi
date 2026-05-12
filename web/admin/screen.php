<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
requireAdmin();

// Muestra la pantalla publica solo si esta activada en la configuracion.
$publicScreenEnabled = getConfig($pdo, 'public_screen_enabled', '1');
if ($publicScreenEnabled !== '1') {
    http_response_code(403);
    exit('La pantalla pública está desactivada.');
}

// Capacidad maxima configurada para el evento.
$maxPlayers = getConfigInt($pdo, 'max_players', 30);

// Cuenta plazas ocupadas o reservadas por usuarios pendientes y aprobados.
$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM users
    WHERE status IN ('pending_review', 'approved')
");
$reservedSlots = (int)$stmt->fetchColumn();

$freeSlots = max(0, $maxPlayers - $reservedSlots);

// Cuenta los usuarios que siguen esperando plaza.
$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM waitlist
    WHERE status = 'waiting'
");
$waitlistCount = (int)$stmt->fetchColumn();

// Muestra los primeros usuarios de la lista de espera.
$stmt = $pdo->query("
    SELECT u.alias, w.joined_at
    FROM waitlist w
    JOIN users u ON u.id = w.user_id
    WHERE w.status = 'waiting'
      AND u.status = 'waitlisted'
    ORDER BY w.joined_at ASC
    LIMIT 10
");
$waitlist = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Cuenta entornos de jugador ya creados o activos.
$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM player_envs
    WHERE env_status IN ('created', 'active')
");
$activeEnvs = (int)$stmt->fetchColumn();

// Obtiene el top de jugadores aprobados para el leaderboard.
$stmt = $pdo->query("
    SELECT u.alias, s.points, s.levels_completed, s.hints_used
    FROM scores s
    JOIN users u ON u.id = s.user_id
    WHERE u.status = 'approved'
    ORDER BY s.points DESC, s.levels_completed DESC
    LIMIT 5
");
$leaderboard = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pantalla pública - CTF WiFi</title>
    <!-- Refresca la vista para mostrar datos actualizados durante el evento. -->
    <meta http-equiv="refresh" content="3">
    <link rel="stylesheet" href="/stylesheet/styles.css">
</head>
<body>
    <div class="wrapper wide">
        <div class="topbar">
            <div>
                <div class="eyebrow">Vista en tiempo real</div>
                <h1>CTF WiFi</h1>
            </div>
            <div class="muted">Actualización automática cada 3 segundo.</div>
        </div>

        <div class="cards">
            <div class="card compact center">
                <div class="muted">Plazas reservadas/ocupadas</div>
                <div class="big"><?= h((string)$reservedSlots) ?> / <?= h((string)$maxPlayers) ?></div>
            </div>
            <div class="card compact center">
                <div class="muted">Plazas libres</div>
                <div class="big"><?= h((string)$freeSlots) ?></div>
            </div>
            <div class="card compact center">
                <div class="muted">Usuarios en espera</div>
                <div class="big"><?= h((string)$waitlistCount) ?></div>
            </div>
            <div class="card compact center">
                <div class="muted">Entornos activos</div>
                <div class="big"><?= h((string)$activeEnvs) ?></div>
            </div>
        </div>

        <div class="grid equal actions-top">
            <div class="card">
                <h2>Lista de espera</h2>

                <?php if (!$waitlist): ?>
                    <div class="empty-state">No hay usuarios en lista de espera.</div>
                <?php else: ?>
                    <ol class="status-list">
                        <?php foreach ($waitlist as $row): ?>
                            <li>
                                <strong><?= h((string)$row['alias']) ?></strong>
                                <span class="muted">- en espera</span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2>Leaderboard</h2>

                <?php if (!$leaderboard): ?>
                    <div class="empty-state">Todavía no hay puntuaciones registradas.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Alias</th>
                                    <th>Puntos</th>
                                    <th>Niveles</th>
                                    <th>Pistas</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($leaderboard as $index => $row): ?>
                                    <tr>
                                        <td><?= h((string)($index + 1)) ?></td>
                                        <td><?= h((string)$row['alias']) ?></td>
                                        <td><?= h((string)$row['points']) ?></td>
                                        <td><?= h((string)$row['levels_completed']) ?></td>
                                        <td><?= h((string)$row['hints_used']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>

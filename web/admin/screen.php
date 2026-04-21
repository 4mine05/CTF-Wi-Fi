<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
requireAdmin();

$publicScreenEnabled = getConfig($pdo, 'public_screen_enabled', '1');
if ($publicScreenEnabled !== '1') {
    http_response_code(403);
    exit('La pantalla pública está desactivada.');
}

$maxPlayers = getConfigInt($pdo, 'max_players', 30);

$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM users
    WHERE status IN ('pending_review', 'approved')
");
$reservedSlots = (int)$stmt->fetchColumn();

$freeSlots = max(0, $maxPlayers - $reservedSlots);

$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM waitlist
    WHERE status = 'waiting'
");
$waitlistCount = (int)$stmt->fetchColumn();

$stmt = $pdo->query("
    SELECT u.alias, w.joined_at
    FROM waitlist w
    JOIN users u ON u.id = w.user_id
    WHERE w.status = 'waiting'
      AND u.status = 'waitlisted'
    ORDER BY w.joined_at ASC
    LIMIT 10
");
$waitlist = $stmt->fetchAll();

$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM player_envs
    WHERE env_status IN ('created', 'active')
");
$activeEnvs = (int)$stmt->fetchColumn();

$stmt = $pdo->query("
    SELECT u.alias, s.points, s.levels_completed, s.hints_used, s.total_time_seconds
    FROM scores s
    JOIN users u ON u.id = s.user_id
    WHERE u.status = 'approved'
    ORDER BY s.points DESC, s.levels_completed DESC, s.total_time_seconds ASC
    LIMIT 10
");
$leaderboard = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Pantalla pública - CTF WiFi</title>
    <meta http-equiv="refresh" content="1">
</head>
<body>
    <h1>CTF WiFi</h1>
    <div class="topbar">    
        <div class="card">
            <div>Plazas reservadas/ocupadas</div>
            <div class="big"><?= h((string)$reservedSlots) ?> / <?= h((string)$maxPlayers) ?></div>
        </div>

        <div class="card">
            <div>Plazas libres</div>
            <div class="big"><?= h((string)$freeSlots) ?></div>
        </div>

        <div class="card">
            <div>Usuarios en espera</div>
            <div class="big"><?= h((string)$waitlistCount) ?></div>
        </div>
    </div>

    <div class="grid">
        <div class="card">
            <h2>Lista de espera</h2>

            <?php if (!$waitlist): ?>
                <p>No hay usuarios en lista de espera.</p>
            <?php else: ?>
                <ol>
                    <?php foreach ($waitlist as $row): ?>
                        <li>
                            <strong><?= h((string)$row['alias']) ?></strong>
                            <span class="muted"- en espera</span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Leaderboard</h2>

            <?php if (!$leaderboard): ?>
                <p>Todavía no hay puntuaciones registradas.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Alias</th>
                            <th>Puntos</th>
                            <th>Niveles</th>
                            <th>Pistas</th>
                            <th>Tiempo</th>
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
                                <td><?= h(formatSeconds((int)$row['total_time_seconds'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

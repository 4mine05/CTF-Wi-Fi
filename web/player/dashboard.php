<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
requireLogin();

if (($_SESSION['user']['role'] ?? '') !== 'player') {
    http_response_code(403);
    exit('Acceso solo para jugadores.');
}

$userId = (int)$_SESSION['user']['id'];
$status = $_SESSION['user']['status'] ?? '';

if ($status === 'pending_review') {
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Mi panel</title>
    </head>
    <body>
        <h1>Mi panel</h1>
        <p>Tu cuenta está pendiente de revisión por el administrador.</p>
        <p><a href="/public/logout.php">Cerrar sesión</a></p>
    </body>
    </html>
    <?php
    exit;
}

if ($status === 'waitlisted') {
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Mi panel</title>
    </head>
    <body>
        <h1>Mi panel</h1>
        <p>Ahora mismo estás en lista de espera.</p>
        <p><a href="/public/logout.php">Cerrar sesión</a></p>
    </body>
    </html>
    <?php
    exit;
}

$stmt = $pdo->prepare("
    SELECT env_status, ssh_host, ssh_port, container_username
    FROM player_envs
    WHERE user_id = ?
    LIMIT 1
");
$stmt->execute([$userId]);
$env = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Mi panel</title>
</head>
<body>
    <h1>Mi panel</h1>

    <p>Bienvenido, <?= h((string)$_SESSION['user']['alias']) ?></p>
    <p><a href="/public/logout.php">Cerrar sesión</a></p>

    <?php if (!$env || $env['env_status'] === 'not_created' || $env['env_status'] === 'pending'): ?>
        <p>Tu entorno todavía no ha sido creado por el administrador.</p>

    <?php elseif (in_array($env['env_status'], ['created', 'active'], true)): ?>
        <p><strong>Tu entorno está listo.</strong></p>
    
    <?php
    $sshCommand = sprintf(
        'ssh %s@%s -p %s',
        (string)$env['container_username'],
        (string)$env['ssh_host'],
        (string)$env['ssh_port']
    );
    ?>
        <a href="intro.php">
            <button type="button">Comenzar</button>
        </a>

    <p><strong>Comando de acceso SSH:</strong></p>
    <textarea rows="1" cols="50" readonly><?= h($sshCommand) ?></textarea>

    <?php elseif ($env['env_status'] === 'error'): ?>
        <p>Hubo un error al crear tu entorno. Contacta con el administrador.</p>

    <?php else: ?>
        <p>Estado del entorno: <?= h((string)$env['env_status']) ?></p>
    <?php endif; ?>
</body>
</html>
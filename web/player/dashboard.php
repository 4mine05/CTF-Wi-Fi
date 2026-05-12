<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
requireLogin();

// El panel de jugador no debe ser accesible para administradores.
if (($_SESSION['user']['role'] ?? '') !== 'player') {
    http_response_code(403);
    exit('Acceso solo para jugadores.');
}

function dashboardAccountStatusLabel(string $status): string
{
    // Traduce el estado de la cuenta a texto visible para el jugador.
    return match ($status) {
        'pending_review' => 'Pendiente de revision',
        'waitlisted' => 'Lista de espera',
        'approved' => 'Aprobada',
        'deleted' => 'Sin acceso',
        default => $status !== '' ? $status : 'Desconocido',
    };
}

function dashboardEnvStatusLabel(?string $status): string
{
    // Traduce el estado tecnico del entorno a texto visible.
    return match ($status) {
        null => 'No disponible',
        'not_created' => 'Sin crear',
        'pending' => 'Pendiente',
        'creating' => 'Creando',
        'created' => 'Listo',
        'active' => 'Activo',
        'finished' => 'Finalizado',
        'error' => 'Error',
        default => $status,
    };
}

$userId = (int)$_SESSION['user']['id'];
$alias = (string)($_SESSION['user']['alias'] ?? 'jugador');
$status = (string)($_SESSION['user']['status'] ?? '');

$accountStatusLabel = dashboardAccountStatusLabel($status);
$envStatusRaw = null;
$envStatusLabel = 'No disponible';

$message = '';
$messageType = 'info';
$hasLevelAccessError = false;
$levelAccessMessage = '';

// Muestra errores de acceso a niveles tras redirigir desde ensureLevelUnlocked().
if (isset($_SESSION['level_access_error'])) {
    $message = (string)$_SESSION['level_access_error'];
    $messageType = 'error';
    $hasLevelAccessError = true;
    $levelAccessMessage = $message;
    unset($_SESSION['level_access_error']);
}

$showStartAction = false;
$showSshCommand = false;
$sshCommand = '';

if ($status === 'pending_review') {
    // Usuarios pendientes o en espera no reciben comando SSH.
    $message = 'Tu cuenta está pendiente de revision por el administrador.';
    $messageType = 'warning';
} elseif ($status === 'waitlisted') {
    $message = 'Ahora mismo estas en lista de espera. Se te asignara una plaza cuando quede disponible.';
    $messageType = 'warning';
} elseif ($status !== 'approved') {
    $message = 'Tu cuenta no tiene acceso al juego.';
    $messageType = 'error';
} else {
    // Carga el entorno asignado al jugador aprobado.
    $stmt = $pdo->prepare("
        SELECT env_status, ssh_host, ssh_port, container_username
        FROM player_envs
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $env = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $envStatusRaw = (string)($env['env_status'] ?? 'not_created');
    $envStatusLabel = dashboardEnvStatusLabel($envStatusRaw);

    if (!$env || in_array($envStatusRaw, ['not_created', 'pending', 'creating'], true)) {
        $message = 'Tu entorno todavía no esta listo. En cuanto se prepare podras comenzar la operación.';
        $messageType = 'warning';
    } elseif (in_array($envStatusRaw, ['created', 'active'], true)) {
        // Cuando el entorno esta listo, se muestra el comando SSH.
        $message = 'Tu entorno esta listo. Ya puedes acceder al laboratorio y comenzar la misión.';
        $messageType = 'success';
        $showStartAction = true;
        $showSshCommand = true;
        $sshCommand = sprintf(
            'ssh %s@%s -p %s',
            (string)$env['container_username'],
            (string)$env['ssh_host'],
            (string)$env['ssh_port']
        );
    } elseif ($envStatusRaw === 'error') {
        $message = 'Hubo un error al crear tu entorno. Contacta con el administrador.';
        $messageType = 'error';
    } else {
        $message = 'Estado actual del entorno: ' . $envStatusLabel . '.';
        $messageType = 'info';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Refresca el panel para ver cambios de altas y entornos. -->
    <meta http-equiv="refresh" content="15">
    <title>Mi panel</title>
    <link rel="stylesheet" href="/stylesheet/styles.css">
</head>
<body>
    <div class="wrapper">
        <div class="topbar">
            <div>Jugador: <strong><?= h($alias) ?></strong></div>
            <div><a href="/public/logout.php">Cerrar sesión</a></div>
        </div>

        <div class="grid">
            <div class="card">
                <div class="eyebrow">Panel del jugador</div>
                <h1>Mi panel</h1>
                <p class="muted">
                    Gestiona tu acceso al laboratorio y sigue el estado de tu entorno de práctica.
                </p>

                <div class="message <?= h($messageType) ?>">
                    <?= h($message) ?>
                </div>

                <?php if ($showSshCommand): ?>
                    <div class="field">
                        <label for="ssh-command">Comando de acceso SSH</label>
                        <textarea id="ssh-command" class="copy-area" rows="1" readonly><?= h($sshCommand) ?></textarea>
                    </div>
                <?php endif; ?>

                <div class="actions">
                    <?php if ($showStartAction): ?>
                        <a href="/player/intro.php" class="btn btn-primary">Comenzar</a>
                    <?php endif; ?>
                    <a href="/public/logout.php" class="btn btn-secondary">Cerrar sesión</a>
                </div>
            </div>

            <div class="card">
                <h2>Estado actual</h2>
                <p><strong>Cuenta:</strong> <?= h($accountStatusLabel) ?></p>
                <p><strong>Entorno:</strong> <?= h($envStatusLabel) ?></p>

                <hr>

                <h3>Siguientes pasos</h3>
                <ul class="status-list">
                    <?php if ($status === 'pending_review'): ?>
                        <li>Espera a que el administrador revise tu cuenta.</li>
                        <li>Cuando se apruebe, tu entorno podra empezar a prepararse.</li>
                    <?php elseif ($status === 'waitlisted'): ?>
                        <li>Mantente atento a la disponibilidad de plazas.</li>
                        <li>Cuando se libere una plaza, podras pasar al flujo normal del juego.</li>
                    <?php elseif ($showStartAction): ?>
                        <li>Accede a la introduccion y revisa las reglas de la operación.</li>
                        <li>Usa el comando SSH para entrar en tu laboratorio cuando lo necesites.</li>
                        <li>Avanza por los niveles desde el portal web.</li>
                    <?php else: ?>
                        <li>Consulta este panel hasta que el entorno cambie a listo o activo.</li>
                        <li>Si el estado permanece en error, contacta con el administrador.</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>

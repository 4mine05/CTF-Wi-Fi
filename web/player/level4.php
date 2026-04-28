<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
requireLogin();

if (($_SESSION['user']['role'] ?? '') !== 'player') {
    http_response_code(403);
    exit('Acceso solo para jugadores.');
}

$status = $_SESSION['user']['status'] ?? '';
if ($status !== 'approved') {
    http_response_code(403);
    exit('Tu cuenta no tiene acceso al juego.');
}

$userId = (int)($_SESSION['user']['id'] ?? 0);
$alias = (string)($_SESSION['user']['alias'] ?? 'jugador');

$levelNumber = 4;
$basePoints = 125;
$hintPenalty = 10;
$failedAttemptPenalty = 5;
$targetBssid = '52:54:46:33:48:53';
$targetSsid = 'espectro-core';

/*
 * El jugador debe obtener esta PSK con aircrack-ng.
 * Hash de la flag/PSK esperada: espectro2026
 */
$flagHash = '$2y$12$j605YyfAiV22DsPRebIWBuXgyH9bWW1wG2p3QZjO7CeQxm0FoWgIK';

$hints = [
    1 => 'Ya tienes el handshake: ahora no necesitas atacar al AP, sino probar claves offline contra la captura.',
    2 => 'El contenedor tiene un archivo .zip de apoyo. Busca paquetes comprimidos en /opt/ctf, /home y /tmp.',
    3 => 'Extrae el zip con unzip. La palabra que lo abre ya aparecio en la historia: el fantasma tenia nombre.',
];

$message = '';
$messageType = 'info';

/* Restringir acceso a niveles no desbloqueados */
ensureLevelUnlocked($pdo, $userId, 4);

$pdo->prepare("
    INSERT IGNORE INTO scores (user_id, points, levels_completed, hints_used, failed_attempts)
    VALUES (?, 0, 0, 0, 0)
")->execute([$userId]);

$pdo->prepare("
    INSERT IGNORE INTO user_level_progress (user_id, level_number, completed, hints_used, failed_attempts, points_earned)
    VALUES (?, ?, 0, 0, 0, 0)
")->execute([$userId, $levelNumber]);

/**
 * Cargar progreso actual del nivel
 */
$stmt = $pdo->prepare("
    SELECT completed, hints_used, failed_attempts, points_earned, completed_at
    FROM user_level_progress
    WHERE user_id = ? AND level_number = ?
    LIMIT 1
");
$stmt->execute([$userId, $levelNumber]);
$progress = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$progress) {
    http_response_code(500);
    exit('No se pudo cargar el progreso del nivel.');
}

$completed = (int)$progress['completed'] === 1;
$hintsUsed = (int)$progress['hints_used'];
$failedAttempts = (int)$progress['failed_attempts'];
$pointsEarned = (int)$progress['points_earned'];

$currentLevelPoints = max(
    0,
    $basePoints - ($hintsUsed * $hintPenalty) - ($failedAttempts * $failedAttemptPenalty)
);

/**
 * Procesar acciones
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$completed) {
    $action = $_POST['action'] ?? '';

    if ($action === 'use_hint') {
        if ($hintsUsed < count($hints)) {
            $pdo->beginTransaction();

            try {
                $pdo->prepare("
                    UPDATE user_level_progress
                    SET hints_used = hints_used + 1,
                        updated_at = NOW()
                    WHERE user_id = ? AND level_number = ?
                ")->execute([$userId, $levelNumber]);

                $pdo->prepare("
                    UPDATE scores
                    SET hints_used = hints_used + 1,
                        updated_at = NOW()
                    WHERE user_id = ?
                ")->execute([$userId]);

                $pdo->commit();

                header('Location: /player/level4.php');
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                $message = 'No se pudo registrar el uso de la pista.';
                $messageType = 'error';
            }
        } else {
            $message = 'Ya has utilizado todas las pistas disponibles en este nivel.';
            $messageType = 'warning';
        }
    }

    if ($action === 'submit_psk') {
        $submittedPsk = trim((string)($_POST['psk'] ?? ''));

        if ($submittedPsk === '') {
            $message = 'Debes introducir la PreSharedKey encontrada.';
            $messageType = 'error';
        } elseif (password_verify($submittedPsk, $flagHash)) {
            $finalPoints = max(
                0,
                $basePoints - ($hintsUsed * $hintPenalty) - ($failedAttempts * $failedAttemptPenalty)
            );

            $pdo->beginTransaction();

            try {
                $pdo->prepare("
                    UPDATE user_level_progress
                    SET completed = 1,
                        points_earned = ?,
                        completed_at = NOW(),
                        last_attempt_at = NOW(),
                        updated_at = NOW()
                    WHERE user_id = ? AND level_number = ?
                ")->execute([$finalPoints, $userId, $levelNumber]);

                $pdo->prepare("
                    UPDATE scores
                    SET points = points + ?,
                        levels_completed = levels_completed + 1,
                        updated_at = NOW()
                    WHERE user_id = ?
                ")->execute([$finalPoints, $userId]);

                $pdo->commit();

                $_SESSION['level4_completed_message'] = 'Has obtenido la PreSharedKey correcta y completado el nivel 4.';
                header('Location: /player/level4.php');
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                $message = 'No se pudo guardar el progreso del nivel.';
                $messageType = 'error';
            }
        } else {
            $pdo->beginTransaction();

            try {
                $pdo->prepare("
                    UPDATE user_level_progress
                    SET failed_attempts = failed_attempts + 1,
                        last_attempt_at = NOW(),
                        updated_at = NOW()
                    WHERE user_id = ? AND level_number = ?
                ")->execute([$userId, $levelNumber]);

                $pdo->prepare("
                    UPDATE scores
                    SET failed_attempts = failed_attempts + 1,
                        updated_at = NOW()
                    WHERE user_id = ?
                ")->execute([$userId]);

                $pdo->commit();

                header('Location: /player/level4.php?error=flag');
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                $message = 'No se pudo registrar el intento fallido.';
                $messageType = 'error';
            }
        }
    }
}

/**
 * Recargar progreso tras posibles cambios
 */
$stmt = $pdo->prepare("
    SELECT completed, hints_used, failed_attempts, points_earned, completed_at
    FROM user_level_progress
    WHERE user_id = ? AND level_number = ?
    LIMIT 1
");
$stmt->execute([$userId, $levelNumber]);
$progress = $stmt->fetch(PDO::FETCH_ASSOC);

$completed = (int)$progress['completed'] === 1;
$hintsUsed = (int)$progress['hints_used'];
$failedAttempts = (int)$progress['failed_attempts'];
$pointsEarned = (int)$progress['points_earned'];

$currentLevelPoints = max(
    0,
    $basePoints - ($hintsUsed * $hintPenalty) - ($failedAttempts * $failedAttemptPenalty)
);

if ($completed && isset($_SESSION['level4_completed_message'])) {
    $message = (string)$_SESSION['level4_completed_message'];
    $messageType = 'success';
    unset($_SESSION['level4_completed_message']);
}

if (isset($_GET['error']) && $_GET['error'] === 'flag') {
    $message = 'PreSharedKey incorrecta. Se ha aplicado una penalizacion.';
    $messageType = 'error';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nivel 4 - La llave del espectro</title>
    <link rel="stylesheet" href="/stylesheet/styles.css">
</head>
<body>
<div class="wrapper">
    <div class="topbar">
        <div>
            Jugador: <strong><?= h($alias) ?></strong>
        </div>
        <div>
            <a href="/player/intro.php">Volver a la introduccion</a> |
            <a href="/player/dashboard.php">Volver al panel</a>
        </div>
    </div>

    <div class="grid">
        <div class="card">
            <div class="eyebrow">Nivel 4</div>
            <h1>La llave del espectro</h1>

            <?php if ($message !== ''): ?>
                <div class="message <?= h($messageType) ?>">
                    <?= h($message) ?>
                </div>
            <?php endif; ?>

            <?php if ($completed): ?>
                <div class="message success">
                    Nivel completado. Has obtenido <strong><?= h((string)$pointsEarned) ?> puntos</strong>.
                </div>
            <?php endif; ?>

            <p>
                El handshake del nivel anterior ya confirma que la red <strong><code><?= h($targetSsid) ?></code></strong>
                usa una clave compartida. Ahora la investigacion pasa a ser offline: no necesitas seguir
                golpeando al punto de acceso, necesitas probar candidatos contra la captura.
            </p>

            <p>
                En el contenedor hay un paquete comprimido preparado por el equipo anterior. Dentro encontraras
                un diccionario pequeno para lanzar un ataque de fuerza bruta con <a href="https://aircrack-ng.org/doku.php?id=aircrack-ng" target="_blank"><code>aircrack-ng</code></a>.
            </p>

            <p>
                Tu objetivo es encontrar la <strong>PreSharedKey exacta</strong> de la red objetivo y enviarla
                en este portal.
            </p>

            <div class="helper-box">
                <p><strong>Ayuda</strong></p>
                <p class="muted">Comandos que puedes necesitar para resolver el reto:</p>
                <ul class="muted">
                    <li><a href="https://www.redhat.com/en/blog/linux-find-command" target="_blank"><code>find</code></a></li>
                    <li><a href="https://www.geeksforgeeks.org/linux-unix/unzip-command-in-linux/" target="_blank"><code>unzip</code></a></li>
                    <li><a href="https://aircrack-ng.org/doku.php?id=aircrack-ng" target="_blank"><code>aircrack-ng</code></a></li>
                </ul>
            </div>

            <div class="meta">
                <div class="meta-box">
                    <strong>Objetivo tecnico</strong>
                    <p class="muted">Usar el handshake capturado y un diccionario para recuperar la WPA2 PreSharedKey.</p>
                </div>
                <div class="meta-box">
                    <strong>Red objetivo</strong>
                    <p class="muted">SSID: <code><?= h($targetSsid) ?></code></p>
                    <p class="muted">BSSID: <code><?= h($targetBssid) ?></code></p>
                </div>
            </div>

            <?php if (!$completed): ?>
                <form method="post">
                    <label for="psk"><strong>Enviar PreSharedKey</strong></label>
                    <input
                        type="text"
                        name="psk"
                        id="psk"
                        placeholder="Introduce aqui la PSK encontrada"
                        autocomplete="off"
                    >

                    <div class="actions">
                        <button type="submit" name="action" value="submit_psk" class="btn btn-primary">
                            Validar PSK
                        </button>

                        <?php if ($hintsUsed < count($hints)): ?>
                            <button type="submit" name="action" value="use_hint" class="btn btn-danger">
                                Usar pista (-<?= h((string)$hintPenalty) ?> puntos)
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            <?php else: ?>
                <div class="actions actions-top">
                    <a href="/player/dashboard.php" class="btn btn-primary">Volver al panel</a>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Estado del nivel</h2>
            <p><strong>Puntuacion base:</strong> <?= h((string)$basePoints) ?> puntos</p>
            <p><strong>Puntos actuales del nivel:</strong> <?= h((string)$currentLevelPoints) ?> puntos</p>
            <p><strong>Pistas usadas:</strong> <?= h((string)$hintsUsed) ?> / <?= h((string)count($hints)) ?></p>
            <p><strong>Intentos fallidos:</strong> <?= h((string)$failedAttempts) ?></p>

            <hr>

            <h3>Material esperado</h3>
            <p class="muted">Handshake capturado en el nivel 3.</p>
            <p class="muted">Diccionario comprimido dentro del contenedor del jugador.</p>
            <p class="muted">Validacion esperada: PSK exacta de la red objetivo.</p>

            <hr>

            <h3>Pistas desbloqueadas</h3>

            <?php if ($hintsUsed === 0): ?>
                <p class="muted">Todavia no has desbloqueado ninguna pista.</p>
            <?php else: ?>
                <?php for ($i = 1; $i <= $hintsUsed; $i++): ?>
                    <div class="hint">
                        <strong>Pista <?= h((string)$i) ?>:</strong><br>
                        <?= h($hints[$i]) ?>
                    </div>
                <?php endfor; ?>
            <?php endif; ?>

            <hr>

            <h3>Penalizaciones</h3>
            <p class="muted">Cada pista utilizada resta <?= h((string)$hintPenalty) ?> puntos.</p>
            <p class="muted">Cada PSK incorrecta resta <?= h((string)$failedAttemptPenalty) ?> puntos.</p>
        </div>
    </div>
</div>
</body>
</html>

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

$levelNumber = 2;
$basePoints = 75;
$hintPenalty = 10;
$failedAttemptPenalty = 5;

/*
 * Sustituye este hash por el hash real de tu flag.
 * Ejemplo para generarlo:
 * php -r "echo password_hash('CTF{AA:BB:CC:DD:EE:FF}', PASSWORD_DEFAULT), PHP_EOL;"
 */
$flagHash = '$2y$12$q30g/BJWlZ8KocA4No1j8e4cv0Xc/Ce5W7CecCBtJAAh9llc0.jMa'; /*Flag: level2*/

$hints = [
    1 => 'El fantasma no dice su nombre, pero sus seguidores sí pueden delatarlo',
    2 => 'Revisa bien la pista que dejaste en el nivel anterior: el BSSID contiene una palabra importante en hexadecimal.',
    3 => 'Fuerza una reasociación del cliente y observa qué información revela.',
];

$message = '';
$messageType = 'info';

/**
 * Asegurar filas mínimas
 */
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

                header('Location: /player/level2.php');
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

    if ($action === 'submit_flag') {
        $submittedFlag = trim((string)($_POST['flag'] ?? ''));

        if ($submittedFlag === '') {
            $message = 'Debes introducir una flag.';
            $messageType = 'error';
        } elseif (!$flagHash) {
            $message = 'Debes configurar primero el hash real de la flag en level2.php.';
            $messageType = 'error';
        } else {
            if (password_verify($submittedFlag, $flagHash)) {
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

                    $pdo->prepare("
                        INSERT IGNORE INTO user_level_progress
                            (user_id, level_number, completed, hints_used, failed_attempts, points_earned)
                        VALUES (?, 3, 0, 0, 0, 0)
                    ")->execute([$userId]);

                    $pdo->commit();

                    if (file_exists(__DIR__ . '/level3.php')) {
                        header('Location: /player/level3.php');
                        exit;
                    }

                    $_SESSION['level2_completed_message'] = 'Has completado el nivel 2 correctamente.';
                    header('Location: /player/dashboard.php');
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

                    header('Location: /player/level2.php?error=flag');
                    exit;
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $message = 'No se pudo registrar el intento fallido.';
                    $messageType = 'error';
                }
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

if (isset($_GET['error']) && $_GET['error'] === 'flag') {
    $message = 'Flag incorrecta. Se ha aplicado una penalización.';
    $messageType = 'error';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nivel 2 - Poner nombre al fantasma</title>
    <link rel="stylesheet" href="/stylesheet/styles.css">
</head>
<body>
<div class="wrapper">
    <div class="topbar">
        <div>
            Jugador: <strong><?= h($alias) ?></strong>
        </div>
        <div>
            <a href="/player/intro.php">Volver a la introducción</a> |
            <a href="/player/dashboard.php">Volver al panel</a>
        </div>
    </div>

    <div class="grid">
        <div class="card">
            <div class="eyebrow">Nivel 2</div>
            <h1>Poner nombre al fantasma</h1>

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
                Ya has conseguido identificar qué dispositivo está emitiendo la red oculta.
                Ahora el siguiente paso es averiguar cómo la conocen sus clientes. Aunque el
                punto de acceso no publique su nombre, los equipos asociados pueden revelar
                la identidad real de la red.
            </p>

            <p>
                Tu misión en este nivel es reconstruir la identidad de esa infraestructura
                encubierta y entregar la flag con el <strong>SSID exacto</strong> de la red oculta.
            </p>


            <div class="helper-box">
                <p><strong>Ayuda</strong></p>
                <p class="muted">Comandos que puedes necesitar para resolver el reto:</p>
                <ul class="muted">
                    <li><a href="https://aircrack-ng.org/doku.php?id=airmon-ng" target="_blank"><code>airmon-ng</code></a></li>
                    <li><a href="https://aircrack-ng.org/doku.php?id=airodump-ng" target="_blank"><code>airodump-ng</code></a></li>
                    <li><a href="https://aircrack-ng.org/doku.php?id=aireplay-ng" target="_blank"><code>aireplay-ng</code></a></li>
                </ul>
            </div>

            <div class="meta">
                <div class="meta-box">
                    <strong>Objetivo</strong>
                    <p class="muted">Obtener el SSID real de la red WiFi oculta.</p>
                </div>
                <div class="meta-box">
                    <strong>Formato esperado</strong>
                    <p class="muted">Introduce la flag completa con el SSID exacto.</p>
                    <p class="muted">Ejemplo: wifi_fantasma</p>
                </div>
            </div>

            <?php if (!$completed): ?>
                <form method="post">
                    <label for="flag"><strong>Enviar flag</strong></label>
                    <input
                        type="text"
                        name="flag"
                        id="flag"
                        placeholder="Introduce aquí la flag del nivel 2"
                        autocomplete="off"
                    >

                    <div class="actions">
                        <button type="submit" name="action" value="submit_flag" class="btn btn-primary">
                            Enviar flag
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
                    <?php if (file_exists(__DIR__ . '/level3.php')): ?>
                        <a href="/player/level3.php" class="btn btn-primary">Ir al nivel 3</a>
                    <?php endif; ?>
                    <a href="/player/dashboard.php" class="btn btn-secondary">Volver al panel</a>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Estado del nivel</h2>
            <p><strong>Puntuación base:</strong> <?= h((string)$basePoints) ?> puntos</p>
            <p><strong>Puntos actuales del nivel:</strong> <?= h((string)$currentLevelPoints) ?> puntos</p>
            <p><strong>Pistas usadas:</strong> <?= h((string)$hintsUsed) ?> / <?= h((string)count($hints)) ?></p>
            <p><strong>Intentos fallidos:</strong> <?= h((string)$failedAttempts) ?></p>

            <hr>

            <h3>Pistas desbloqueadas</h3>

            <?php if ($hintsUsed === 0): ?>
                <p class="muted">Todavía no has desbloqueado ninguna pista.</p>
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
            <p class="muted">Cada intento fallido resta <?= h((string)$failedAttemptPenalty) ?> puntos.</p>
        </div>
    </div>
</div>
</body>
</html>

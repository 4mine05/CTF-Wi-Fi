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

$levelNumber = 1;
$basePoints = 100;
$hintPenalty = 10;
$failedAttemptPenalty = 5;

/*
 * Sustituye este hash por el hash real de tu flag.
 * Ejemplo para generarlo:
 * php -r "echo password_hash('CTF{AA:BB:CC:DD:EE:FF}', PASSWORD_DEFAULT), PHP_EOL;"
 */
$flagHash = '$2y$12$U8mbCWzlfaXUQRZaT8RH3OgnE3fdmE57YMo9cBSH2Njd1VJUcednK'; /*Flag: 44:45:41:55:54:48*/

$hints = [
    1 => 'Usa el modo monitor',
    2 => 'No puedes capturar lo que no estás mirando. Activa tu visión periférica sobre el objetivo y quédate en silencio recolectando balizas.',
    3 => 'La red oculta está escondida en el sexto carril de los 11 carriles.',
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

                header('Location: /player/level1.php');
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
            $message = 'Debes configurar primero el hash real de la flag en level1.php.';
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
                        VALUES (?, 2, 0, 0, 0, 0)
                    ")->execute([$userId]);

                    $pdo->commit();

                    if (file_exists(__DIR__ . '/level2.php')) {
                        header('Location: /player/level2.php');
                        exit;
                    }

                    $_SESSION['level1_completed_message'] = 'Has completado el nivel 1 correctamente.';
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

                    header('Location: /player/level1.php?error=flag');
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
    <title>Nivel 1 - Operación Espectro</title>
    <style>
        :root {
            --bg: #0f172a;
            --panel: #111827;
            --panel-2: #1f2937;
            --text: #e5e7eb;
            --muted: #9ca3af;
            --accent: #22c55e;
            --accent-hover: #16a34a;
            --border: #374151;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #38bdf8;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: linear-gradient(135deg, #0b1120, #111827);
            color: var(--text);
            min-height: 100vh;
        }

        .wrapper {
            max-width: 980px;
            margin: 0 auto;
            padding: 32px 20px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .topbar a {
            color: var(--muted);
            text-decoration: none;
        }

        .topbar a:hover {
            color: var(--text);
        }

        .grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
        }

        .card {
            background: rgba(17, 24, 39, 0.95);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 24px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.30);
        }

        .eyebrow {
            color: var(--warning);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-size: 0.88rem;
            margin-bottom: 10px;
        }

        h1, h2, h3 {
            margin-top: 0;
        }

        p {
            line-height: 1.7;
        }

        .muted {
            color: var(--muted);
        }

        .meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 20px;
        }

        .meta-box {
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 14px;
        }

        .message {
            margin-bottom: 18px;
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid var(--border);
        }

        .message.info { background: rgba(56, 189, 248, 0.10); border-color: rgba(56, 189, 248, 0.35); }
        .message.warning { background: rgba(245, 158, 11, 0.10); border-color: rgba(245, 158, 11, 0.35); }
        .message.error { background: rgba(239, 68, 68, 0.10); border-color: rgba(239, 68, 68, 0.35); }
        .message.success { background: rgba(34, 197, 94, 0.10); border-color: rgba(34, 197, 94, 0.35); }

        .hint {
            margin-top: 12px;
            padding: 12px 14px;
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: 10px;
        }

        form {
            margin-top: 18px;
        }

        input[type="text"] {
            width: 100%;
            padding: 12px;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: #0b1220;
            color: var(--text);
            margin-bottom: 12px;
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        button, .btn {
            display: inline-block;
            padding: 12px 18px;
            border-radius: 10px;
            border: none;
            text-decoration: none;
            font-weight: bold;
            cursor: pointer;
        }

        .btn-primary {
            background: var(--accent);
            color: #052e16;
        }

        .btn-primary:hover {
            background: var(--accent-hover);
        }

        .btn-secondary {
            background: transparent;
            color: var(--text);
            border: 1px solid var(--border);
        }

        .btn-danger {
            background: rgba(239, 68, 68, 0.15);
            color: #fecaca;
            border: 1px solid rgba(239, 68, 68, 0.35);
        }

        @media (max-width: 800px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
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
            <div class="eyebrow">Nivel 1</div>
            <h1>La señal que no debía existir</h1>

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
                Durante una auditoría rutinaria del espectro aparecen tramas sospechosas.
                No hay un SSID visible, pero sí actividad de clientes. Todo indica que alguien
                está operando un punto de acceso oculto dentro del entorno.
            </p>

            <p>
                Tu misión en este nivel es identificar el punto de acceso responsable de esta
                infraestructura encubierta y entregar la flag con el <strong>BSSID exacto</strong>
                de la red oculta.
            </p>

            <div class="meta">
                <div class="meta-box">
                    <strong>Objetivo</strong>
                    <p class="muted">Descubrir el BSSID exacto de la red WiFi oculta.</p>
                </div>
                <div class="meta-box">
                    <strong>Formato esperado</strong>
                    <p class="muted">Introduce la flag completa con el formato que hayas definido para el reto.</p>
                </div>
            </div>

            <?php if (!$completed): ?>
                <form method="post">
                    <label for="flag"><strong>Enviar flag</strong></label>
                    <input
                        type="text"
                        name="flag"
                        id="flag"
                        placeholder="Introduce aquí la flag del nivel 1"
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
                <div class="actions" style="margin-top: 18px;">
                    <?php if (file_exists(__DIR__ . '/level2.php')): ?>
                        <a href="/player/level2.php" class="btn btn-primary">Ir al nivel 2</a>
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

            <hr style="border-color:#374151; margin: 20px 0;">

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

            <hr style="border-color:#374151; margin: 20px 0;">

            <h3>Penalizaciones</h3>
            <p class="muted">Cada pista utilizada resta <?= h((string)$hintPenalty) ?> puntos.</p>
            <p class="muted">Cada intento fallido resta <?= h((string)$failedAttemptPenalty) ?> puntos.</p>
        </div>
    </div>
</div>
</body>
</html>
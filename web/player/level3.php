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

$levelNumber = 3;
$basePoints = 100;
$hintPenalty = 10;
$failedAttemptPenalty = 5;
$maxUploadBytes = 10 * 1024 * 1024;
$targetBssid = '52:54:46:33:48:53';
$targetSsid = 'espectro-core';

$hints = [
    1 => 'Sin un intercambio EAPOL valido no podras preparar un ataque offline.',
    2 => 'Si el cliente no genera trafico por si solo, fuerza una nueva asociacion para provocar el handshake.',
    3 => 'Antes de subir la captura, revisala con aircrack-ng: el servidor espera detectar al menos un handshake valido.',
];

$message = '';
$messageType = 'info';

if (isset($_SESSION['level3_flash']) && is_array($_SESSION['level3_flash'])) {
    $message = (string)($_SESSION['level3_flash']['message'] ?? '');
    $messageType = (string)($_SESSION['level3_flash']['type'] ?? 'info');
    unset($_SESSION['level3_flash']);
}

function setLevel3Flash(string $message, string $type): void
{
    $_SESSION['level3_flash'] = [
        'message' => $message,
        'type' => $type,
    ];
}

function uploadErrorToMessage(int $error): string
{
    switch ($error) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'El archivo supera el tamaño maximo permitido.';
        case UPLOAD_ERR_PARTIAL:
            return 'La subida del archivo no se completo correctamente.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'El servidor no tiene un directorio temporal disponible.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'El servidor no pudo escribir el archivo subido.';
        case UPLOAD_ERR_EXTENSION:
            return 'La subida fue detenida por una extension del servidor.';
        default:
            return 'No se pudo procesar el archivo subido.';
    }
}

function findAircrackBinary(): ?string
{
    $candidates = [
        '/usr/bin/aircrack-ng',
        '/usr/local/bin/aircrack-ng',
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    if (!function_exists('shell_exec')) {
        return null;
    }

    $resolved = @shell_exec('command -v aircrack-ng 2>/dev/null');
    if (!is_string($resolved)) {
        return null;
    }

    $resolved = trim($resolved);
    return $resolved !== '' ? $resolved : null;
}

function normalizeBssid(string $bssid): string
{
    return strtoupper(trim($bssid));
}

function normalizeSsid(string $ssid): string
{
    return trim($ssid);
}

function validateHandshakeCapture(string $capturePath, string $expectedBssid, ?string $expectedSsid = null): array
{
    if (!function_exists('shell_exec')) {
        return [
            'available' => false,
            'ok' => false,
            'message' => 'El servidor no puede ejecutar aircrack-ng para validar capturas.',
        ];
    }

    $aircrackBinary = findAircrackBinary();
    if ($aircrackBinary === null) {
        return [
            'available' => false,
            'ok' => false,
            'message' => 'El servidor no tiene aircrack-ng disponible para validar el archivo .cap.',
        ];
    }

    $command = escapeshellarg($aircrackBinary) . ' ' . escapeshellarg($capturePath) . ' 2>&1';
    $output = @shell_exec($command);

    if (!is_string($output) || trim($output) === '') {
        return [
            'available' => true,
            'ok' => false,
            'message' => 'No se pudo analizar la captura con aircrack-ng.',
        ];
    }

    $expectedBssid = normalizeBssid($expectedBssid);
    $expectedSsid = $expectedSsid !== null ? normalizeSsid($expectedSsid) : null;

    /**
     * Ejemplo de línea que queremos parsear:
     * 1  52:54:46:33:48:53  espectro-core             WPA (1 handshake)
     */
    $pattern = '/^\s*\d+\s+([0-9A-F:]{17})\s+(.+?)\s+WPA\d?\s*\(([0-9]+)\s+handshake/im';

    if (preg_match_all($pattern, $output, $matches, PREG_SET_ORDER) !== 1 && preg_match_all($pattern, $output, $matches, PREG_SET_ORDER) < 1) {
        return [
            'available' => true,
            'ok' => false,
            'message' => 'No se encontraron redes WPA/WPA2 con handshake en la captura.',
        ];
    }

    foreach ($matches as $match) {
        $foundBssid = normalizeBssid($match[1]);
        $foundSsid = normalizeSsid($match[2]);
        $handshakeCount = (int)$match[3];

        if ($foundBssid !== $expectedBssid) {
            continue;
        }

        if ($expectedSsid !== null && $foundSsid !== $expectedSsid) {
            return [
                'available' => true,
                'ok' => false,
                'message' => 'La captura contiene un handshake del BSSID correcto, pero el SSID no coincide con la red objetivo.',
            ];
        }

        if ($handshakeCount > 0) {
            return [
                'available' => true,
                'ok' => true,
                'message' => 'Captura válida: se ha detectado un handshake WPA/WPA2 de la red objetivo ' . $foundSsid . ' (' . $foundBssid . ').',
            ];
        }
    }

    return [
        'available' => true,
        'ok' => false,
        'message' => 'La captura no contiene un handshake válido de la red objetivo (' . $expectedBssid . ').',
    ];
}

/**
 * Asegurar filas minimas
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

                header('Location: /player/level3.php');
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

    if ($action === 'submit_capture') {
        $uploadedCapture = $_FILES['capture_file'] ?? null;

        if (!is_array($uploadedCapture) || (int)($uploadedCapture['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $message = 'Debes subir un archivo .cap.';
            $messageType = 'error';
        } elseif ((int)($uploadedCapture['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $message = uploadErrorToMessage((int)$uploadedCapture['error']);
            $messageType = 'error';
        } else {
            $originalName = (string)($uploadedCapture['name'] ?? '');
            $tmpName = (string)($uploadedCapture['tmp_name'] ?? '');
            $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
            $size = (int)($uploadedCapture['size'] ?? 0);

            if ($extension !== 'cap') {
                $message = 'Solo se aceptan archivos con extension .cap.';
                $messageType = 'error';
            } elseif ($size <= 0) {
                $message = 'El archivo subido esta vacio o no se pudo leer.';
                $messageType = 'error';
            } elseif ($size > $maxUploadBytes) {
                $message = 'El archivo supera el limite de 10 MB.';
                $messageType = 'error';
            } elseif ($tmpName === '' || !is_uploaded_file($tmpName)) {
                $message = 'No se pudo verificar el archivo subido.';
                $messageType = 'error';
            } else {
                $validation = validateHandshakeCapture($tmpName, $targetBssid, $targetSsid);

                if (!$validation['available']) {
                    $message = $validation['message'];
                    $messageType = 'error';
                } elseif ($validation['ok']) {
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

                        if (file_exists(__DIR__ . '/level4.php')) {
                            $pdo->prepare("
                                INSERT IGNORE INTO user_level_progress
                                    (user_id, level_number, completed, hints_used, failed_attempts, points_earned)
                                VALUES (?, 4, 0, 0, 0, 0)
                            ")->execute([$userId]);
                        }

                        $pdo->commit();

                        if (file_exists(__DIR__ . '/level4.php')) {
                            header('Location: /player/level4.php');
                            exit;
                        }

                        $_SESSION['level3_completed_message'] = $validation['message'];
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

                        setLevel3Flash($validation['message'], 'error');
                        header('Location: /player/level3.php');
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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nivel 3 - El acceso vigilado</title>
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
            <div class="eyebrow">Nivel 3</div>
            <h1>El acceso vigilado</h1>

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
                La investigación revela una segunda red, esta vez protegida con WPA2.
                A diferencia de la anterior, esta parece ser el verdadero punto de entrada a la infraestructura
            </p>

            <p>
                En este nivel no basta con verla, necesitas capturar la prueba criptográfica que permita atacarla fuera de línea.
            </p>

            <p>
                Tu objetivo es subir un archivo <strong><code>.cap</code></strong> con un <strong>handshake WPA2 valido</strong>
                para preparar una intrusion controlada.
            </p>

            <div class="helper-box">
                <p><strong>Ayuda</strong></p>
                <p class="muted">Comandos que puedes necesitar para resolver el reto:</p>
                <ul class="muted">
                    <li><a href="https://aircrack-ng.org/doku.php?id=airodump-ng" target="_blank"><code>airodump-ng</code></a></li>
                    <li><a href="https://aircrack-ng.org/doku.php?id=aireplay-ng" target="_blank"><code>aireplay-ng</code></a></li>
                    <li><a href="https://aircrack-ng.org/doku.php?id=aircrack-ng" target="_blank"><code>aircrack-ng</code></a></li>
                </ul>
            </div>

            <div class="meta">
                <div class="meta-box">
                    <strong>Objetivo tecnico</strong>
                    <p class="muted">Capturar y subir un handshake WPA2 valido en un archivo <code>.cap</code>.</p>
                </div>
                <div class="meta-box">
                    <strong>Validacion</strong>
                    <p class="muted">Se analizará tu captura para determinar si es un handshake WPA2 de la red objetivo es válido.</p>
                </div>
            </div>

            <?php if (!$completed): ?>
                <form method="post" enctype="multipart/form-data">
                    <label for="capture_file"><strong>Subir captura</strong></label>
                    <input
                        type="file"
                        name="capture_file"
                        id="capture_file"
                        accept=".cap,application/vnd.tcpdump.pcap,application/octet-stream"
                    >

                    <p class="muted">
                        Sube una captura .cap de hasta <?= h((string)($maxUploadBytes / (1024 * 1024))) ?> MB.
                        El nivel se completa cuando el servidor detecta un handshake WPA/WPA2 valido.
                    </p>

                    <div class="actions">
                        <button type="submit" name="action" value="submit_capture" class="btn btn-primary">
                            Validar captura
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
                    <?php if (file_exists(__DIR__ . '/level4.php')): ?>
                        <a href="/player/level4.php" class="btn btn-primary">Ir al nivel 4</a>
                    <?php endif; ?>
                    <a href="/player/dashboard.php" class="btn btn-secondary">Volver al panel</a>
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

            <h3>Requisitos de la captura</h3>
            <p class="muted">Formato admitido: archivo .cap.</p>
            <p class="muted">Tamaño maximo: <?= h((string)($maxUploadBytes / (1024 * 1024))) ?> MB.</p>
            <p class="muted">Validacion esperada: al menos un handshake WPA/WPA2.</p>

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
            <p class="muted">Cada captura invalida resta <?= h((string)$failedAttemptPenalty) ?> puntos.</p>
        </div>
    </div>
</div>
</body>
</html>

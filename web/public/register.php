<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';



$message = '';  
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $alias = trim($_POST['alias'] ?? '');
    $password = $_POST['password'] ?? '';
    $inviteCode = strtoupper(trim($_POST['invite_code'] ?? ''));

    if (!preg_match('/^[A-Za-z0-9_-]{3,32}$/', $alias)) {
        $error = 'El alias debe tener entre 3 y 32 caracteres y solo usar letras, números, guion o guion bajo.';
    } elseif (strlen($password) < 8) {
        $error = 'La contraseña debe tener al menos 8 caracteres.';
    } elseif ($inviteCode === '') {
        $error = 'Debes introducir un código de invitación.';
    } else {
        try {
            $pdo->beginTransaction();

            $registrationOpen = getConfig($pdo, 'registration_open', '1');
            if ($registrationOpen !== '1') {
                throw new RuntimeException('El registro está cerrado.');
            }

            $stmt = $pdo->prepare("
                SELECT id, is_active
                FROM invitation_codes
                WHERE code = ? AND is_active = ?
                FOR UPDATE
            ");
            $stmt->execute([$inviteCode, 1]);
            $codeRow = $stmt->fetch();

            if (!$codeRow) {
                throw new RuntimeException('Código de invitación no válido.');
            }

            if ((int)$codeRow['is_active'] !== 1) {
                throw new RuntimeException('El código de invitación está desactivado.');
            }

            $stmt = $pdo->prepare("SELECT id FROM users WHERE alias = ? LIMIT 1");
            $stmt->execute([$alias]);
            if ($stmt->fetch()) {
                throw new RuntimeException('Ese alias ya está en uso.');
            }

            $maxPlayers = getConfigInt($pdo, 'max_players', 30);
            $reservedSlots = countReservedSlots($pdo);

            $status = ($reservedSlots < $maxPlayers) ? 'pending_review' : 'waitlisted';

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $containerPasswordHash = makeLinuxPasswordHash($password);

            $stmt = $pdo->prepare("
                INSERT INTO users (alias, password_hash, container_password_hash, role, status, created_at, updated_at)
                VALUES (?, ?, ?, 'player', ?, NOW(), NOW())
            ");
            $stmt->execute([$alias, $passwordHash, $containerPasswordHash, $status]);

            $userId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare("
                INSERT INTO scores (user_id, points, levels_completed, valid_flags, hints_used, total_time_seconds)
                VALUES (?, 0, 0, 0, 0, 0, 0)
            ");
            $stmt->execute([$userId]);

            if ($status === 'waitlisted') {
                $stmt = $pdo->prepare("
                    INSERT INTO waitlist (user_id, joined_at, status)
                    VALUES (?, NOW(), 'waiting')
                ");
                $stmt->execute([$userId]);
            }

            $pdo->commit();

            if ($status === 'pending_review') {
                $message = 'Registro enviado correctamente. Tu cuenta está pendiente de revisión por el administrador.';
            } else {
                $message = 'Registro completado, pero ahora mismo no hay plazas. Has entrado en la lista de espera.';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Registro - CTF WiFi</title>
</head>
<body>
    <h1>Registro</h1>

    <?php if ($message !== ''): ?>
        <p style="color: green;"><?= h($message) ?></p>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <p style="color: red;"><?= h($error) ?></p>
    <?php endif; ?>

    <form method="post">
        <label>
            Alias:
            <input type="text" name="alias" maxlength="32" required>
        </label>
        <br><br>

        <label>
            Contraseña:
            <input type="password" name="password" required>
        </label>
        <br><br>

        <label>
            Código de invitación:
            <input type="text" name="invite_code" maxlength="64" required>
        </label>
        <br><br>

        <button type="submit">Registrarse</button>
    </form>

    <p><a href="/public/login.php">Ir al inicio de sesión</a></p>
</body>
</html>

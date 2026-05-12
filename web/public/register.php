<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

$error = '';

// Procesa el formulario de registro.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $alias = trim($_POST['alias'] ?? '');
    $password = $_POST['password'] ?? '';
    $inviteCode = strtoupper(trim($_POST['invite_code'] ?? ''));

    // Valida los datos basicos antes de tocar la base de datos.
    if (!preg_match('/^[A-Za-z0-9_-]{3,32}$/', $alias)) {
        $error = 'El alias debe tener entre 3 y 32 caracteres y solo usar letras, números, guion o guion bajo.';
    } elseif (strlen($password) < 8) {
        $error = 'La contraseña debe tener al menos 8 caracteres.';
    } elseif ($inviteCode === '') {
        $error = 'Debes introducir un código de invitación.';
    } else {
        try {
            // Agrupa el alta completa para evitar registros parciales.
            $pdo->beginTransaction();

            // Comprueba que el registro sigue abierto.
            $registrationOpen = getConfig($pdo, 'registration_open', '1');
            if ($registrationOpen !== '1') {
                throw new RuntimeException('El registro está cerrado.');
            }

            // Bloquea el codigo de invitacion durante la transaccion.
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

            // Evita duplicar alias de jugadores ya registrados.
            $stmt = $pdo->prepare("SELECT id FROM users WHERE alias = ? LIMIT 1");
            $stmt->execute([$alias]);
            if ($stmt->fetch()) {
                throw new RuntimeException('Ese alias ya está en uso.');
            }

            // Decide si el jugador entra a revision o a lista de espera.
            $maxPlayers = getConfigInt($pdo, 'max_players', 30);
            $reservedSlots = countReservedSlots($pdo);

            $status = ($reservedSlots < $maxPlayers) ? 'pending_review' : 'waitlisted';

            // Genera hashes para el portal web y para el usuario Linux del contenedor.
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $containerPasswordHash = makeLinuxPasswordHash($password);

            // Crea el usuario jugador con su estado inicial.
            $stmt = $pdo->prepare("
                INSERT INTO users (alias, password_hash, container_password_hash, role, status, created_at, updated_at)
                VALUES (?, ?, ?, 'player', ?, NOW(), NOW())
            ");
            $stmt->execute([$alias, $passwordHash, $containerPasswordHash, $status]);

            $userId = (int)$pdo->lastInsertId();

            // Inicializa la puntuacion del nuevo jugador.
            $stmt = $pdo->prepare("
                INSERT INTO scores (user_id, points, levels_completed, hints_used, failed_attempts)
                VALUES (?, 0, 0, 0, 0)
            ");
            $stmt->execute([$userId]);

            // Si no hay plazas, lo anade tambien a la lista de espera.
            if ($status === 'waitlisted') {
                $stmt = $pdo->prepare("
                    INSERT INTO waitlist (user_id, joined_at, status)
                    VALUES (?, NOW(), 'waiting')
                ");
                $stmt->execute([$userId]);
            }

            $pdo->commit();

            // Guarda el resultado para mostrarlo despues de redirigir al login.
            if ($status === 'pending_review') {
                $_SESSION['login_success'] = 'Registro enviado correctamente. Tu cuenta está pendiente de revisión por el administrador.';
            } else {
                $_SESSION['login_success'] = 'Registro completado, pero ahora mismo no hay plazas. Has entrado en la lista de espera.';
            }

            header('Location: /public/login.php');
            exit;
        } catch (Throwable $e) {
            // Revierte cualquier cambio si falla una parte del registro.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

// Mantiene valores escritos si el formulario falla.
$submittedAlias = (string)($_POST['alias'] ?? '');
$submittedInviteCode = (string)($_POST['invite_code'] ?? '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro - CTF WiFi</title>
    <link rel="stylesheet" href="/stylesheet/styles.css">
</head>
<body>
    <div class="wrapper narrow">
        <div class="topbar">
            <div>Portal CTF WiFi</div>
            <div><a href="/public/login.php">Iniciar sesión</a></div>
        </div>

        <div class="card">
            <div class="eyebrow">Registro</div>
            <h1>Crear cuenta</h1>
            <p class="muted">
                Registra tu acceso al evento con un alias, una contraseña válida y un código de invitación.
            </p>

            <?php if ($error !== ''): ?>
                <div class="message error"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post">
                <div class="field">
                    <label for="alias">Alias</label>
                    <input
                        type="text"
                        name="alias"
                        id="alias"
                        maxlength="32"
                        value="<?= h($submittedAlias) ?>"
                        autocomplete="username"
                        required
                    >
                </div>

                <div class="field">
                    <label for="password">Contraseña</label>
                    <input
                        type="password"
                        name="password"
                        id="password"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <div class="field">
                    <label for="invite_code">Código de invitación</label>
                    <input
                        type="text"
                        name="invite_code"
                        id="invite_code"
                        maxlength="64"
                        value="<?= h($submittedInviteCode) ?>"
                        required
                    >
                </div>

                <div class="actions">
                    <button type="submit" class="btn btn-primary">Registrarse</button>
                    <a href="/public/login.php" class="btn btn-secondary">Ir al login</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>

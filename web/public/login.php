<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

if (!empty($_SESSION['user'])) {
    redirectByRole();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $alias = trim($_POST['alias'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($alias === '' || $password === '') {
        $error = 'Debes rellenar alias y contraseña.';
    } else {
        $stmt = $pdo->prepare("
            SELECT id, alias, password_hash, role, status
            FROM users
            WHERE alias = ?
            LIMIT 1
        ");
        $stmt->execute([$alias]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'Credenciales incorrectas.';
        } elseif (in_array($user['status'], ['deleted'], true)) {
            $error = 'Tu cuenta no tiene acceso.';
        } else {
            session_regenerate_id(true);

            $_SESSION['user'] = [
                'id' => (int)$user['id'],
                'alias' => $user['alias'],
                'role' => $user['role'],
                'status' => $user['status'],
            ];

            $stmt = $pdo->prepare("
                UPDATE users
                SET last_login_at = NOW(), updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([(int)$user['id']]);

            redirectByRole();
        }
    }
}

$submittedAlias = (string)($_POST['alias'] ?? '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - CTF WiFi</title>
    <link rel="stylesheet" href="/stylesheets/css.css">
</head>
<body>
    <div class="wrapper narrow">
        <div class="topbar">
            <div>Portal CTF WiFi</div>
            <div><a href="/public/register.php">Crear cuenta</a></div>
        </div>

        <div class="card">
            <div class="eyebrow">Acceso</div>
            <h1>Iniciar sesion</h1>
            <p class="muted">
                Accede con tu alias y contraseña para entrar en el portal del laboratorio.
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
                        autocomplete="current-password"
                        required
                    >
                </div>

                <div class="actions">
                    <button type="submit" class="btn btn-primary">Entrar</button>
                    <a href="/public/register.php" class="btn btn-secondary">Ir al registro</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>

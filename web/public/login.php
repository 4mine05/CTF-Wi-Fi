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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Login - CTF WiFi</title>
</head>
<body>
    <h1>Iniciar sesión</h1>

    <?php if ($error !== ''): ?>
        <p style="color:red;"><?= h($error) ?></p>
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

        <button type="submit">Entrar</button>
    </form>

    <p><a href="/public/register.php">Ir al registro</a></p>
</body>
</html>

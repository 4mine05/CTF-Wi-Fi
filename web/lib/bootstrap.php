<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/db.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function refreshSessionUser(): void
{
    if (empty($_SESSION['user']['id'])) {
        return;
    }

    global $pdo;

    if (!$pdo instanceof PDO) {
        return;
    }

    $stmt = $pdo->prepare("
        SELECT id, alias, role, status
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([(int)$_SESSION['user']['id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || (string)$user['status'] === 'deleted') {
        unset($_SESSION['user']);
        $_SESSION['login_error'] = 'Tu cuenta no tiene acceso.';
        header('Location: /public/login.php');
        exit;
    }

    $_SESSION['user']['id'] = (int)$user['id'];
    $_SESSION['user']['alias'] = (string)$user['alias'];
    $_SESSION['user']['role'] = (string)$user['role'];
    $_SESSION['user']['status'] = (string)$user['status'];
}

function requireAdmin(): void
{
    if (empty($_SESSION['user'])) {
        $_SESSION['login_error'] = 'Debes iniciar sesión.';
        header('Location: /public/login.php');
        exit;
    }

    refreshSessionUser();

    if (($_SESSION['user']['role'] ?? '') !== 'admin') {
        $_SESSION['login_error'] = 'Acceso restringido al administrador.';
        unset($_SESSION['user']);
        header('Location: /public/login.php');
        exit;
    }
}

function getConfig(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT config_value FROM app_config WHERE config_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    return $value === false ? $default : (string)$value;
}

function getConfigInt(PDO $pdo, string $key, int $default = 0): int
{
    return (int)getConfig($pdo, $key, (string)$default);
}

/**
 * Las plazas reservadas/ocupadas cuentan pending_review + approved
 * para que el límite sea real antes de aprobar.
 */
function countReservedSlots(PDO $pdo): int
{
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM users
        WHERE status IN ('pending_review', 'approved')
    ");
    return (int)$stmt->fetchColumn();
}

/* Requerir inicio de sesión */
function requireLogin(): void
{
    if (empty($_SESSION['user'])) {
        $_SESSION['login_error'] = 'Debes iniciar sesión.';
        header('Location: /public/login.php');
        exit;
    }

    refreshSessionUser();
}

/* Redirigir según el rol */
function redirectByRole(): void
{
    if (empty($_SESSION['user'])) {
        header('Location: /public/login.php');
        exit;
    }

    refreshSessionUser();

    $role = $_SESSION['user']['role'] ?? '';

    if ($role === 'admin') {
        header('Location: /admin/index.php');
        exit;
    }

    if ($role === 'player') {
        header('Location: /player/dashboard.php');
        exit;
    }

    session_destroy();
    header('Location: /public/login.php');
    exit;
}

/* Generar hash Linux de la contraseña para el usuario */
function makeLinuxPasswordHash(string $password): string
{
    $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789./';
    $salt = '';

    for ($i = 0; $i < 16; $i++) {
        $salt .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    $hash = crypt($password, '$6$rounds=656000$' . $salt . '$');

    if (!is_string($hash) || $hash === '' || $hash === '*0' || $hash === '*1') {
        throw new RuntimeException('No se pudo generar el hash Linux de la contraseña.');
    }

    return $hash;
}

/* Restringir acceso a niveles no desbloqueados */
function ensureLevelUnlocked(PDO $pdo, int $userId, int $levelNumber): void
{
    if ($levelNumber <= 1) {
        return;
    }

    $previousLevel = $levelNumber - 1;

    $stmt = $pdo->prepare("
        SELECT completed
        FROM user_level_progress
        WHERE user_id = ? AND level_number = ?
        LIMIT 1
    ");
    $stmt->execute([$userId, $previousLevel]);

    $previousCompleted = (int)($stmt->fetchColumn() ?: 0) === 1;

    if (!$previousCompleted) {
        $_SESSION['level_access_error'] = 'Primero debes completar el nivel ' . $previousLevel . ' para acceder al nivel ' . $levelNumber . '.';
        header('Location: /player/dashboard.php');
        exit;
    }
}

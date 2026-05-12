<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

// Vacía los datos de sesion del usuario actual.
$_SESSION = [];

// Borra la cookie de sesion del navegador si PHP la esta usando.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destruye la sesion en el servidor y vuelve al login.
session_destroy();

header('Location: /public/login.php');
exit;

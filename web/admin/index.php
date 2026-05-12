<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
requireAdmin();

// Entrada del area admin: redirige al panel principal de usuarios.
header('Location: /admin/users.php');
exit;

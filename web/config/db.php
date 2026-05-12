<?php
declare(strict_types=1);

// Carga parametros de conexion desde variables de entorno o usa valores por defecto.
$host = $_ENV['DB_HOST'] ?? 'db';
$db   = $_ENV['DB_NAME'] ?? 'ctf_wifi';
$user = $_ENV['DB_USER'] ?? 'ctf_user';
$pass = $_ENV['DB_PASS'] ?? 'ctf_pass';
$charset = 'utf8mb4';

$dsn = "mysql:host={$host};dbname={$db};charset={$charset}";

// Configura PDO para lanzar excepciones y devolver arrays asociativos.
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    // Conexion compartida por las paginas que incluyen bootstrap.php.
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    http_response_code(500);
    exit('Error de conexión con la base de datos.');
}

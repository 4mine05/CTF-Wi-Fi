<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
requireLogin();

if (($_SESSION['user']['role'] ?? '') !== 'player') {
    http_response_code(403);
    exit('Acceso solo para jugadores.');
}

$status = $_SESSION['user']['status'] ?? '';

if ($status === 'pending_review') {
    http_response_code(403);
    exit('Tu cuenta está pendiente de revisión.');
}

if ($status === 'waitlisted') {
    http_response_code(403);
    exit('Tu cuenta está en lista de espera.');
}

if ($status !== 'approved') {
    http_response_code(403);
    exit('Tu cuenta no tiene acceso al juego.');
}

$alias = $_SESSION['user']['alias'] ?? 'jugador';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Operación Espectro - Introducción</title>
    <link rel="stylesheet" href="/stylesheet/styles.css">
</head>
<body>
<div class="wrapper">
    <div class="topbar">
        <div>Jugador: <strong><?= h((string)$alias) ?></strong></div>
        <div>
            <a href="/player/dashboard.php">Volver al panel</a>
        </div>
    </div>

    <div class="card">
        <div class="eyebrow">Inicio de misión</div>
        <h1>Operación Espectro</h1>

        <p>
            Has sido asignado a una operación de análisis forense inalámbrico. Durante una auditoría
            se ha detectado actividad sospechosa en una infraestructura WiFi no documentada.
            Tu misión consiste en analizar el entorno, identificar redes, observar el comportamiento de los clientes
            y avanzar a través de los distintos retos hasta reconstruir la red oculta.
        </p>
        <p class="muted">
            Avanza con cuidado: el objetivo no es solo completar los retos, sino hacerlo con la mayor puntuación posible.
        </p>

        <div class="rules">
            <h2>Como funciona el juego</h2>
            <ul>
                <li>Cada nivel tiene un objetivo concreto.</li>
                <li>Puedes solicitar pistas si las necesitas.
                <li>Cada pista utilizada reduce la puntuación del nivel.</li>
                <li>Cada intento fallido también aplica penalización.</li>
                <li>Cada reto resuelto desbloqueará el siguiente.</li>
                <li>El objetivo es progresar con la mayor puntuación posible.</li>
            </ul>
            <br>
            <h2>Cuando no sepas cómo continuar</h2>
            <p>Si te bloqueas en un nivel, hay varios caminos que puedes tomar:</p>
            <ul>
                <li>Revisar cuidadosamente el contexto y el objetivo del reto.</li>
                <li>Consultar los comandos sugeridos para ese nivel.</li>
                <li>Leer la documentación o los recursos recomendados.</li>
                <li>Pedir una pista, sabiendo que reducirá la puntuación obtenida.</li>
            </ul>
        </div>
        <br>
        <p>
            Tu entorno de juego está pensado para trabajar dentro de un laboratorio WiFi controlado, con interfaces inalámbricas virtuales, puntos de acceso simulados y clientes de prueba. Avanza con calma, observa bien el tráfico y recuerda que cada nivel está diseñado para enseñarte una técnica o concepto distinto.
        </p>

        <div class="actions">
            <a class="btn btn-primary" href="/player/level1.php">Comenzar nivel 1</a>
            <a class="btn btn-secondary" href="/player/dashboard.php">Volver al panel</a>
        </div>
    </div>
</div>
</body>
</html>

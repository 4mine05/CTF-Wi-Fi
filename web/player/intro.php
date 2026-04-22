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
    <style>
        :root {
            --bg: #0f172a;
            --panel: #111827;
            --panel-2: #1f2937;
            --text: #e5e7eb;
            --muted: #9ca3af;
            --accent: #22c55e;
            --accent-hover: #16a34a;
            --border: #374151;
            --warning: #f59e0b;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: linear-gradient(135deg, #0b1120, #111827);
            color: var(--text);
            min-height: 100vh;
        }

        .wrapper {
            max-width: 900px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
        }

        .topbar a {
            color: var(--muted);
            text-decoration: none;
        }

        .topbar a:hover {
            color: var(--text);
        }

        .card {
            background: rgba(17, 24, 39, 0.95);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 32px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
        }

        .eyebrow {
            display: inline-block;
            font-size: 0.9rem;
            color: var(--warning);
            margin-bottom: 10px;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        h1 {
            margin: 0 0 18px;
            font-size: 2rem;
        }

        p {
            line-height: 1.7;
            color: var(--text);
            margin: 0 0 16px;
        }

        .muted {
            color: var(--muted);
        }

        .rules {
            margin-top: 28px;
            padding: 20px;
            background: var(--panel-2);
            border: 1px solid var(--border);
            border-radius: 12px;
        }

        .rules h2 {
            margin-top: 0;
            font-size: 1.1rem;
        }

        .rules ul {
            margin: 12px 0 0;
            padding-left: 20px;
            line-height: 1.7;
            color: var(--text);
        }

        .actions {
            margin-top: 30px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-block;
            padding: 12px 20px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: bold;
            transition: background 0.2s ease, transform 0.2s ease;
        }

        .btn-primary {
            background: var(--accent);
            color: #052e16;
        }

        .btn-primary:hover {
            background: var(--accent-hover);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: transparent;
            color: var(--text);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: #1f2937;
        }

        @media (max-width: 640px) {
            .card {
                padding: 24px;
            }

            h1 {
                font-size: 1.6rem;
            }

            .topbar {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="topbar">
        <div>Jugador: <strong><?= htmlspecialchars($alias, ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div>
            <a href="/player/dashboard.php">Volver al panel</a>
        </div>
    </div>

    <div class="card">
        <div class="eyebrow">Inicio de misión</div>
        <h1>Operación Espectro</h1>

        <p>
            Has sido asignado a una operación de análisis forense inalámbrico. Durante una auditoría
            rutinaria del espectro se ha detectado actividad sospechosa en una infraestructura WiFi no documentada.
            Tu misión consiste en analizar el entorno, identificar redes, observar el comportamiento de los clientes
            y avanzar a través de distintos retos hasta reconstruir la red oculta.
        </p>

        <p>
            El juego se desarrolla por niveles y de forma progresiva. Cada reto resuelto desbloqueará el siguiente.
            Para ayudarte, podrás solicitar pistas, pero su uso reducirá la puntuación obtenida en el nivel.
            Del mismo modo, los intentos fallidos al enviar una flag también aplicarán penalización.
        </p>

        <p class="muted">
            Avanza con cuidado: el objetivo no es solo completar los retos, sino hacerlo con la mayor puntuación posible.
        </p>

        <div class="rules">
            <h2>Normas básicas</h2>
            <ul>
                <li>Las pistas reducen la puntuación del nivel.</li>
                <li>Los intentos fallidos también restan puntos.</li>
                <li>Solo al completar un nivel se desbloquea el siguiente.</li>
                <li>El objetivo es avanzar con la mayor puntuación posible.</li>
            </ul>
        </div>

        <div class="actions">
            <a class="btn btn-primary" href="/player/level1.php">Comenzar nivel 1</a>
            <a class="btn btn-secondary" href="/player/dashboard.php">Volver al panel</a>
        </div>
    </div>
</div>
</body>
</html>
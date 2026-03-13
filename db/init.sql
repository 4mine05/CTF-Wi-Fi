CREATE DATABASE IF NOT EXISTS ctf_jugadores;
USE ctf_jugadores;

CREATE TABLE IF NOT EXISTS jugadores (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario VARCHAR(50) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS niveles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  descripcion TEXT,
  flag_hash VARCHAR(255) NOT NULL,
  orden_nivel INT NOT NULL,
  activo TINYINT(1) DEFAULT 1
);

CREATE TABLE IF NOT EXISTS progreso (
  id INT AUTO_INCREMENT PRIMARY KEY,
  jugador_id INT NOT NULL,
  nivel_id INT NOT NULL,
  completado TINYINT(1) DEFAULT 0,
  fecha_completado DATETIME NULL,
  UNIQUE (jugador_id, nivel_id),
  FOREIGN KEY (jugador_id) REFERENCES jugadores(id),
  FOREIGN KEY (nivel_id) REFERENCES niveles(id)
);

CREATE TABLE IF NOT EXISTS intentos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  jugador_id INT NOT NULL,
  nivel_id INT NOT NULL,
  respuesta_enviada VARCHAR(255) NOT NULL,
  correcta TINYINT(1) DEFAULT 0,
  fecha_intento TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (jugador_id) REFERENCES jugadores(id),
  FOREIGN KEY (nivel_id) REFERENCES niveles(id)
);

CREATE TABLE IF NOT EXISTS entornos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  jugador_id INT NOT NULL,
  nombre_contenedor VARCHAR(100),
  puerto_ssh INT,
  estado ENUM('pendiente','creado','activo','finalizado') DEFAULT 'pendiente',
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (jugador_id) REFERENCES jugadores(id)
);

CREATE DATABASE IF NOT EXISTS ctf_jugadores;
USE ctf_jugadores;

CREATE TABLE IF NOT EXISTS jugadores (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario VARCHAR(50) UNIQUE NOT NULL,
  password VARCHAR(255) NOT NULL,
  estado VARCHAR(30) NOT NULL DEFAULT 'registrado',
  contenedor VARCHAR(100),
  puerto_ssh INT
);

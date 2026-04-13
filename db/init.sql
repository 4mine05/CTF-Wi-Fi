CREATE DATABASE IF NOT EXISTS ctf_wifi
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE ctf_wifi;

-- =========================================
-- CONFIGURACIÓN GENERAL
-- =========================================
CREATE TABLE app_config (
    config_key VARCHAR(100) PRIMARY KEY,
    config_value VARCHAR(255) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO app_config (config_key, config_value) VALUES
('max_players', '20'),
('registration_open', '1'),
('public_screen_enabled', '0');

-- =========================================
-- USUARIOS
-- =========================================
CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alias VARCHAR(32) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    container_password_hash VARCHAR(255) NULL,
    role ENUM('admin', 'player') NOT NULL DEFAULT 'player',
    status ENUM('pending_review', 'waitlisted', 'approved', 'deleted')
        NOT NULL DEFAULT 'pending_review',
    approved_at DATETIME NULL,
    deleted_at DATETIME NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_alias (alias)
);

-- =========================================
-- CÓDIGOS DE INVITACIÓN
-- =========================================
CREATE TABLE invitation_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(64) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    UNIQUE KEY uq_invitation_codes_code (code)
);
-- =========================================
-- Código de invitación genérico para usuarios nuevos
-- =========================================
INSERT INTO invitation_codes (code, is_active, created_at)
VALUES ('CLASE2026', 1, NOW());
-- =========================================
-- LISTA DE ESPERA
-- =========================================
CREATE TABLE waitlist (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('waiting', 'promoted', 'removed') NOT NULL DEFAULT 'waiting',
    promoted_at DATETIME NULL,
    removed_at DATETIME NULL,
    notes VARCHAR(255) NULL,
    UNIQUE KEY uq_waitlist_user (user_id),
    KEY idx_waitlist_status_joined (status, joined_at),
    CONSTRAINT fk_waitlist_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE
);

-- =========================================
-- ENTORNOS DE JUGADOR
-- =========================================
CREATE TABLE player_envs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    env_status ENUM('not_created', 'pending', 'creating', 'created', 'active', 'finished', 'error')
        NOT NULL DEFAULT 'not_created',
    container_name VARCHAR(100) NULL,
    ssh_host VARCHAR(255) NULL,
    ssh_port INT UNSIGNED NULL,
    container_username VARCHAR(64) NULL,

    initial_password_enc VARBINARY(255) NULL,

    provision_requested_at DATETIME NULL,
    created_at DATETIME NULL,
    activated_at DATETIME NULL,
    finished_at DATETIME NULL,

    UNIQUE KEY uq_player_envs_user (user_id),
    UNIQUE KEY uq_player_envs_container_name (container_name),
    UNIQUE KEY uq_player_envs_ssh_port (ssh_port),

    CONSTRAINT fk_player_envs_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE
);

-- =========================================
-- NIVELES
-- =========================================
CREATE TABLE levels (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    level_order INT UNSIGNED NOT NULL UNIQUE,
    title VARCHAR(100) NOT NULL,
    description TEXT NULL,
    points INT UNSIGNED NOT NULL DEFAULT 0
);


-- =========================================
-- Pistas de los niveles
-- =========================================
CREATE TABLE level_hints (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    level_id INT UNSIGNED NOT NULL,
    hint_order INT UNSIGNED NOT NULL,
    hint_text TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_level_hint_order (level_id, hint_order),
    FOREIGN KEY (level_id) REFERENCES levels(id)
        ON DELETE CASCADE
);

-- =========================================
-- PUNTUACIONES / LEADERBOARD
-- =========================================
CREATE TABLE scores (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    points INT NOT NULL DEFAULT 0,
    levels_completed INT UNSIGNED NOT NULL DEFAULT 0,
    valid_flags INT UNSIGNED NOT NULL DEFAULT 0,
    hints_used INT UNSIGNED NOT NULL DEFAULT 0,
    total_time_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_scores_user (user_id),
    KEY idx_scores_ranking (points DESC, levels_completed DESC, total_time_seconds ASC),
    CONSTRAINT fk_scores_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE
);

-- =========================================
-- CREAR USUARIO ADMINISTRADOR
-- =========================================
INSERT INTO users (alias, password_hash, role, status, approved_at)
VALUES (
  'admin', -- Usuario/Alias
  '$2y$12$bAFgGglV3m1UPbbkioGNreC6LzvrgAJlTsYjUkn8Y4nA/hb7prIIK',  -- Contrasena: admin1234
  'admin',
  'approved',
  NOW()
);  

-- Schema de base de datos para el Sistema de Gestión de Fallas y Conocimiento Técnico de Flota
-- Prefijo de Catálogo: tbc_ (tbc_Usuario)
-- Prefijo de Transacciones: tbo_

CREATE TABLE IF NOT EXISTS tbc_Usuario (
    id_usuario INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    correo VARCHAR(150) NOT NULL UNIQUE,
    google_sub VARCHAR(100) UNIQUE NULL, -- Guardará el ID único de Google (sub claim)
    foto_url VARCHAR(255) NULL,
    es_activo TINYINT(1) DEFAULT 1, -- 1: Activo, 0: Inactivo (Control de Acceso)
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Semilla de datos iniciales
INSERT INTO tbc_Usuario (nombre, correo, es_activo) VALUES 
('Leonardo Rodriguez', 'l.rodriguez@aleservicecenter.com', 1),
('Jetzrael López', 'admin@aleservicecenter.com', 1)
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre);

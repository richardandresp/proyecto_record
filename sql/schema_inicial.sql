-- Crear BD y usuario (ajusta password)
CREATE DATABASE IF NOT EXISTS auditoria_db CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS 'auditoria_user'@'%' IDENTIFIED BY 'TuPasswordFuerte';
GRANT ALL PRIVILEGES ON auditoria_db.* TO 'auditoria_user'@'%';
FLUSH PRIVILEGES;

USE auditoria_db;

-- ZONAS
CREATE TABLE zona (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(120) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uk_zona_nombre (nombre)
) ENGINE=InnoDB;

-- CENTROS DE COSTO
CREATE TABLE centro_costo (
  id INT AUTO_INCREMENT PRIMARY KEY,
  zona_id INT NOT NULL,
  nombre VARCHAR(160) NOT NULL,
  codigo VARCHAR(50) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uk_cc_codigo (codigo),
  KEY idx_cc_zona (zona_id),
  CONSTRAINT fk_cc_zona FOREIGN KEY (zona_id) REFERENCES zona(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- USUARIOS (admin, auditor, lider, lectura)
CREATE TABLE usuario (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(160) NOT NULL,
  email VARCHAR(160) NOT NULL,
  telefono VARCHAR(40) DEFAULT NULL,
  rol ENUM('admin','auditor','lider','lectura') NOT NULL DEFAULT 'lectura',
  clave_hash VARCHAR(255) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uk_usuario_email (email)
) ENGINE=InnoDB;

-- ASIGNACIÓN HISTÓRICA DE LÍDERES A CENTROS (vigencias)
CREATE TABLE lider_centro (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  centro_id INT NOT NULL,
  desde DATE NOT NULL,
  hasta DATE DEFAULT NULL,
  KEY idx_lc_centro (centro_id),
  KEY idx_lc_usuario (usuario_id),
  KEY idx_lc_vigencia (desde, hasta),
  CONSTRAINT fk_lc_usuario FOREIGN KEY (usuario_id) REFERENCES usuario(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_lc_centro FOREIGN KEY (centro_id) REFERENCES centro_costo(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- ASESORES (catálogo)
CREATE TABLE asesor (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(160) NOT NULL,
  documento VARCHAR(40) DEFAULT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uk_asesor_doc (documento)
) ENGINE=InnoDB;

-- HISTÓRICO: asesor asignado a centro (para no perder historia si cambia)
CREATE TABLE asesor_centro (
  id INT AUTO_INCREMENT PRIMARY KEY,
  asesor_id INT NOT NULL,
  centro_id INT NOT NULL,
  desde DATE NOT NULL,
  hasta DATE DEFAULT NULL,
  KEY idx_ac_asesor (asesor_id),
  KEY idx_ac_centro (centro_id),
  CONSTRAINT fk_ac_asesor FOREIGN KEY (asesor_id) REFERENCES asesor(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_ac_centro FOREIGN KEY (centro_id) REFERENCES centro_costo(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- HALLAZGOS (datos del auditor)
CREATE TABLE hallazgo (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fecha DATE NOT NULL,
  zona_id INT NOT NULL,
  centro_id INT NOT NULL,
  asesor_id INT DEFAULT NULL,
  cedula VARCHAR(40) DEFAULT NULL,
  nombre_pdv VARCHAR(200) NOT NULL,
  raspas_faltantes INT NOT NULL DEFAULT 0,
  faltante_dinero DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  sobrante_dinero DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  observaciones TEXT NOT NULL,
  evidencia_url VARCHAR(255) NOT NULL,
  estado ENUM('pendiente','respondido_lider','respondido_admin','vencido') NOT NULL DEFAULT 'pendiente',
  fecha_limite DATETIME NOT NULL,
  creado_por INT NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_hallazgo_fechas (fecha, fecha_limite),
  KEY idx_hallazgo_estado (estado),
  KEY idx_hallazgo_centro (centro_id),
  KEY idx_hallazgo_zona (zona_id),
  CONSTRAINT fk_h_zona FOREIGN KEY (zona_id) REFERENCES zona(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_h_centro FOREIGN KEY (centro_id) REFERENCES centro_costo(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_h_asesor FOREIGN KEY (asesor_id) REFERENCES asesor(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_h_creado_por FOREIGN KEY (creado_por) REFERENCES usuario(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- RESPUESTAS (del líder o del admin)
CREATE TABLE hallazgo_respuesta (
  id INT AUTO_INCREMENT PRIMARY KEY,
  hallazgo_id INT NOT NULL,
  usuario_id INT NOT NULL,
  rol_al_responder ENUM('lider','admin') NOT NULL,
  respuesta TEXT NOT NULL,
  adjunto_url VARCHAR(255) DEFAULT NULL,
  respondido_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_hr_hallazgo (hallazgo_id),
  KEY idx_hr_usuario (usuario_id),
  CONSTRAINT fk_hr_h FOREIGN KEY (hallazgo_id) REFERENCES hallazgo(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_hr_u FOREIGN KEY (usuario_id) REFERENCES usuario(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- NOTIFICACIONES (log interno opcional)
CREATE TABLE notificacion (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  hallazgo_id INT NOT NULL,
  tipo ENUM('nuevo','recordatorio','vencido','resuelto') NOT NULL,
  canal ENUM('app','email','whatsapp') NOT NULL DEFAULT 'app',
  enviado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notif_usuario (usuario_id),
  KEY idx_notif_hallazgo (hallazgo_id),
  CONSTRAINT fk_n_u FOREIGN KEY (usuario_id) REFERENCES usuario(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_n_h FOREIGN KEY (hallazgo_id) REFERENCES hallazgo(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- CONFIGURACIÓN (clave-valor)
CREATE TABLE config (
  clave VARCHAR(50) PRIMARY KEY,
  valor VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

-- Valores base
INSERT INTO config (clave, valor) VALUES ('SLA_HORAS', '48');

-- Datos mínimos de ejemplo
INSERT INTO zona (nombre) VALUES ('Montería'),('Ayapel');
INSERT INTO centro_costo (zona_id, nombre, codigo) VALUES (1,'CC Norte','CCN'),(1,'CC Sur','CCS'),(2,'CC Ayapel','CCA');

-- admin demo: email admin@demo.local / password: admin123 (cámbialo luego)
INSERT INTO usuario (nombre,email,telefono,rol,clave_hash,activo)
VALUES ('Admin Demo','admin@demo.local','', 'admin', PASSWORD('admin123'), 1);


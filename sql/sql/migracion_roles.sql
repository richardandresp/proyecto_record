USE auditoria_db;

-- Ampliamos roles
ALTER TABLE usuario
  MODIFY rol ENUM('admin','auditor','supervisor','lider','auxiliar','lectura')
  NOT NULL DEFAULT 'lectura';

-- Supervisor por ZONA con vigencias
CREATE TABLE IF NOT EXISTS supervisor_zona (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  zona_id INT NOT NULL,
  desde DATE NOT NULL,
  hasta DATE DEFAULT NULL,
  KEY idx_sz_zona (zona_id),
  KEY idx_sz_usuario (usuario_id),
  KEY idx_sz_vigencia (desde, hasta),
  CONSTRAINT fk_sz_usuario FOREIGN KEY (usuario_id) REFERENCES usuario(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_sz_zona FOREIGN KEY (zona_id) REFERENCES zona(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- Auxiliares de ventas por CENTRO con vigencias
CREATE TABLE IF NOT EXISTS auxiliar_centro (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  centro_id INT NOT NULL,
  desde DATE NOT NULL,
  hasta DATE DEFAULT NULL,
  KEY idx_ax_centro (centro_id),
  KEY idx_ax_usuario (usuario_id),
  KEY idx_ax_vigencia (desde, hasta),
  CONSTRAINT fk_ax_usuario FOREIGN KEY (usuario_id) REFERENCES usuario(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_ax_centro FOREIGN KEY (centro_id) REFERENCES centro_costo(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;


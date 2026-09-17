-- Usuarios del panel. Se crean con scripts/crear_usuario.php.
CREATE TABLE IF NOT EXISTS usuarios (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    nombre VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- CAF autorizados. El XML (con la llave privada) queda en var/secrets; aquí
-- solo la ruta. siguiente_folio avanza al reservar folios.
CREATE TABLE IF NOT EXISTS cafs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ambiente ENUM('cert', 'prod', 'test') NOT NULL,
    tipo_dte SMALLINT UNSIGNED NOT NULL,
    folio_desde INT UNSIGNED NOT NULL,
    folio_hasta INT UNSIGNED NOT NULL,
    siguiente_folio INT UNSIGNED NOT NULL,
    fecha_autorizacion DATE NOT NULL,
    archivo VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY cafs_rango (ambiente, tipo_dte, folio_desde),
    KEY cafs_disponibles (ambiente, tipo_dte, siguiente_folio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Un folio se reserva creando su fila; el índice único impide duplicados.
CREATE TABLE IF NOT EXISTS boletas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ambiente ENUM('cert', 'prod', 'test') NOT NULL,
    tipo_dte SMALLINT UNSIGNED NOT NULL,
    folio INT UNSIGNED NOT NULL,
    caf_id INT UNSIGNED NOT NULL,
    estado ENUM('reservada', 'emitida', 'enviada', 'aceptada', 'reparos', 'rechazada') NOT NULL DEFAULT 'reservada',
    fecha_emision DATE NULL,
    monto_total INT UNSIGNED NULL,
    xml MEDIUMBLOB NULL, -- bytes ISO-8859-1 exactos: una columna de texto utf8mb4 corrompe tildes y la firma
    track_id BIGINT UNSIGNED NULL,
    respuesta_sii JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY boletas_folio (ambiente, tipo_dte, folio),
    KEY boletas_track (track_id),
    CONSTRAINT boletas_caf FOREIGN KEY (caf_id) REFERENCES cafs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notas de crédito que anulan una boleta. boleta_id único: una sola por boleta.
CREATE TABLE IF NOT EXISTS notas_credito (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ambiente ENUM('cert', 'prod', 'test') NOT NULL,
    tipo_dte SMALLINT UNSIGNED NOT NULL,
    folio INT UNSIGNED NOT NULL,
    caf_id INT UNSIGNED NOT NULL,
    boleta_id INT UNSIGNED NOT NULL,
    estado ENUM('reservada', 'emitida', 'enviada', 'aceptada', 'reparos', 'rechazada') NOT NULL DEFAULT 'reservada',
    fecha_emision DATE NULL,
    monto_total INT UNSIGNED NULL,
    xml MEDIUMBLOB NULL,
    track_id BIGINT UNSIGNED NULL,
    respuesta_sii JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY notas_credito_folio (ambiente, tipo_dte, folio),
    UNIQUE KEY notas_credito_boleta (boleta_id),
    KEY notas_credito_track (track_id),
    CONSTRAINT notas_credito_caf FOREIGN KEY (caf_id) REFERENCES cafs (id),
    CONSTRAINT notas_credito_boleta FOREIGN KEY (boleta_id) REFERENCES boletas (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

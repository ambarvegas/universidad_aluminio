-- 0002 — Registro de errores (PHP y JavaScript) para el diagnóstico del panel.
-- Cada error distinto es una fila (huella = origen + mensaje + ubicación); las repeticiones
-- solo suman `veces` y actualizan `ultima_vez`.
CREATE TABLE IF NOT EXISTS `errores_log` (
    `id`          INT          NOT NULL AUTO_INCREMENT,
    `huella`      CHAR(40)     NOT NULL,
    `origen`      VARCHAR(10)  NOT NULL,
    `mensaje`     VARCHAR(500) NOT NULL DEFAULT '',
    `detalle`     TEXT,
    `ubicacion`   VARCHAR(255) NOT NULL DEFAULT '',
    `usuario_id`  VARCHAR(50)  NOT NULL DEFAULT '',
    `navegador`   VARCHAR(255) NOT NULL DEFAULT '',
    `version`     VARCHAR(50)  NOT NULL DEFAULT '',
    `ip`          VARCHAR(45)  NOT NULL DEFAULT '',
    `veces`       INT          NOT NULL DEFAULT 1,
    `primera_vez` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ultima_vez`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_el_huella` (`huella`),
    INDEX `idx_el_ultima` (`ultima_vez`),
    INDEX `idx_el_ip` (`ip`, `ultima_vez`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

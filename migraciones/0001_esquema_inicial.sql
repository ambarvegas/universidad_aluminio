-- 0001 — Esquema inicial (punto de partida del control de migraciones).
-- Es el esquema que ya tiene producción: todas las tablas con IF NOT EXISTS, así que en una
-- base existente no cambia nada y en una base vacía la crea completa.

-- Usuarios
CREATE TABLE IF NOT EXISTS `usuarios` (
    `id`               VARCHAR(50)  NOT NULL,
    `nombre`           VARCHAR(255) NOT NULL DEFAULT '',
    `clave`            VARCHAR(255) NOT NULL DEFAULT '12345',
    `rol`              VARCHAR(100) NOT NULL DEFAULT 'participante',
    `estado`           VARCHAR(50)  NOT NULL DEFAULT 'activo',
    `telefono`         VARCHAR(50)  NOT NULL DEFAULT '',
    `email`            VARCHAR(255) NOT NULL DEFAULT '',
    `fecha_nacimiento` VARCHAR(20)  NOT NULL DEFAULT '',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cursos asignados directamente a un usuario
CREATE TABLE IF NOT EXISTS `usuario_asignados` (
    `usuario_id` VARCHAR(50)  NOT NULL,
    `curso_id`   VARCHAR(100) NOT NULL,
    PRIMARY KEY (`usuario_id`, `curso_id`),
    FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Carreras asignadas a un usuario
CREATE TABLE IF NOT EXISTS `usuario_carreras_asignadas` (
    `usuario_id` VARCHAR(50)  NOT NULL,
    `carrera_id` VARCHAR(100) NOT NULL,
    `estado`     VARCHAR(50)  NOT NULL DEFAULT 'Incompleta',
    PRIMARY KEY (`usuario_id`, `carrera_id`),
    FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Certificados de cursos
CREATE TABLE IF NOT EXISTS `usuario_certificados_curso` (
    `usuario_id` VARCHAR(50)  NOT NULL,
    `curso_id`   VARCHAR(100) NOT NULL,
    PRIMARY KEY (`usuario_id`, `curso_id`),
    FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Certificados de carreras
CREATE TABLE IF NOT EXISTS `usuario_certificados_carrera` (
    `usuario_id` VARCHAR(50)  NOT NULL,
    `carrera_id` VARCHAR(100) NOT NULL,
    PRIMARY KEY (`usuario_id`, `carrera_id`),
    FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cursos (imagen en LONGTEXT por base64, módulos normalizados en tablas relacionales)
CREATE TABLE IF NOT EXISTS `cursos` (
    `id`              VARCHAR(100) NOT NULL,
    `titulo`          VARCHAR(255) NOT NULL DEFAULT '',
    `descripcion`     MEDIUMTEXT,
    `tipo`            VARCHAR(50)  NOT NULL DEFAULT 'especializado',
    `imagen`          LONGTEXT,
    `prelacion`       VARCHAR(100) DEFAULT NULL,
    `en_construccion` TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Módulos de un curso (relación normalizada)
CREATE TABLE IF NOT EXISTS `curso_modulos` (
    `id`                   INT          NOT NULL AUTO_INCREMENT,
    `curso_id`             VARCHAR(100) NOT NULL,
    `orden`                INT          NOT NULL DEFAULT 0,
    `titulo`               VARCHAR(500) NOT NULL DEFAULT '',
    `max_intentos`         INT          NOT NULL DEFAULT 0,
    `eval_tipo`            VARCHAR(20)  NOT NULL DEFAULT 'fijo',
    `eval_num_preguntas`   INT          NOT NULL DEFAULT 0,
    `eval_mezclar_opciones` TINYINT(1)  NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    INDEX `idx_cm_curso` (`curso_id`),
    FOREIGN KEY (`curso_id`) REFERENCES `cursos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lecciones de un módulo (relación normalizada)
CREATE TABLE IF NOT EXISTS `curso_lecciones` (
    `id`        INT          NOT NULL AUTO_INCREMENT,
    `modulo_id` INT          NOT NULL,
    `curso_id`  VARCHAR(100) NOT NULL,
    `orden`     INT          NOT NULL DEFAULT 0,
    `titulo`    VARCHAR(500) NOT NULL DEFAULT '',
    `video_id`  VARCHAR(200) DEFAULT NULL,
    `contenido` MEDIUMTEXT,
    `adjunto`   TEXT         DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_cl_modulo` (`modulo_id`),
    INDEX `idx_cl_curso`  (`curso_id`),
    FOREIGN KEY (`modulo_id`) REFERENCES `curso_modulos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Preguntas de evaluación de un módulo (relación normalizada)
CREATE TABLE IF NOT EXISTS `curso_preguntas` (
    `id`        INT          NOT NULL AUTO_INCREMENT,
    `modulo_id` INT          NOT NULL,
    `curso_id`  VARCHAR(100) NOT NULL,
    `orden`     INT          NOT NULL DEFAULT 0,
    `enunciado` TEXT         NOT NULL,
    `imagen`    VARCHAR(500) DEFAULT NULL,
    `opciones`  JSON         NOT NULL,
    `correcta`  TINYINT      NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    INDEX `idx_cp_modulo` (`modulo_id`),
    INDEX `idx_cp_curso`  (`curso_id`),
    FOREIGN KEY (`modulo_id`) REFERENCES `curso_modulos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Carreras (sin columna cursos JSON — normalizado en carrera_cursos)
CREATE TABLE IF NOT EXISTS `carreras` (
    `id`     VARCHAR(100) NOT NULL,
    `nombre` VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Carreras - Cursos (Relación normalizada)
CREATE TABLE IF NOT EXISTS `carrera_cursos` (
    `carrera_id` VARCHAR(100) NOT NULL,
    `curso_id`   VARCHAR(100) NOT NULL,
    `orden`      INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (`carrera_id`, `curso_id`),
    INDEX `idx_cc_curso` (`curso_id`),
    FOREIGN KEY (`carrera_id`) REFERENCES `carreras`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Configuración de roles (sin columnas JSON — normalizadas en rol_permisos, rol_cursos, rol_carreras)
CREATE TABLE IF NOT EXISTS `roles_config` (
    `id`       VARCHAR(100) NOT NULL,
    `nombre`   VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Roles - Permisos (Relación normalizada)
CREATE TABLE IF NOT EXISTS `rol_permisos` (
    `rol_id`  VARCHAR(100) NOT NULL,
    `permiso` VARCHAR(100) NOT NULL,
    PRIMARY KEY (`rol_id`, `permiso`),
    FOREIGN KEY (`rol_id`) REFERENCES `roles_config`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Roles - Cursos asignados (Relación normalizada)
CREATE TABLE IF NOT EXISTS `rol_cursos` (
    `rol_id`   VARCHAR(100) NOT NULL,
    `curso_id` VARCHAR(100) NOT NULL,
    PRIMARY KEY (`rol_id`, `curso_id`),
    INDEX `idx_rc_curso` (`curso_id`),
    FOREIGN KEY (`rol_id`) REFERENCES `roles_config`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Roles - Carreras asignadas (Relación normalizada)
CREATE TABLE IF NOT EXISTS `rol_carreras` (
    `rol_id`     VARCHAR(100) NOT NULL,
    `carrera_id` VARCHAR(100) NOT NULL,
    PRIMARY KEY (`rol_id`, `carrera_id`),
    INDEX `idx_rc_carrera` (`carrera_id`),
    FOREIGN KEY (`rol_id`) REFERENCES `roles_config`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Progreso de Usuario: Lecciones completadas (Relación normalizada)
CREATE TABLE IF NOT EXISTS `usuario_lecciones_completadas` (
    `usuario_id`       VARCHAR(50)  NOT NULL,
    `curso_id`         VARCHAR(100) NOT NULL,
    `leccion_codigo`   VARCHAR(50)  NOT NULL,
    `fecha_completado` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`usuario_id`, `curso_id`, `leccion_codigo`),
    INDEX `idx_ulc_curso` (`curso_id`),
    INDEX `idx_ulc_user` (`usuario_id`),
    FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Progreso de Usuario: Módulos aprobados (Relación normalizada)
CREATE TABLE IF NOT EXISTS `usuario_modulos_aprobados` (
    `usuario_id`     VARCHAR(50)  NOT NULL,
    `curso_id`       VARCHAR(100) NOT NULL,
    `modulo_num`     VARCHAR(50)  NOT NULL,
    `fecha_aprobado` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`usuario_id`, `curso_id`, `modulo_num`),
    INDEX `idx_uma_curso` (`curso_id`),
    INDEX `idx_uma_user` (`usuario_id`),
    FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Progreso de Usuario: Medallas obtenidas (Relación normalizada)
CREATE TABLE IF NOT EXISTS `usuario_medallas` (
    `usuario_id`     VARCHAR(50)  NOT NULL,
    `curso_id`       VARCHAR(100) NOT NULL,
    `medalla_num`    VARCHAR(50)  NOT NULL,
    `fecha_obtenida` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`usuario_id`, `curso_id`, `medalla_num`),
    INDEX `idx_um_curso` (`curso_id`),
    INDEX `idx_um_user` (`usuario_id`),
    FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Progreso de Usuario: Evaluaciones rendidas (Relación normalizada)
CREATE TABLE IF NOT EXISTS `usuario_evaluaciones` (
    `usuario_id`      VARCHAR(50)    NOT NULL,
    `curso_id`        VARCHAR(100)   NOT NULL,
    `modulo_num`      VARCHAR(50)    NOT NULL,
    `calificacion`    DECIMAL(5,2)   NOT NULL DEFAULT 0.00,
    `aprobado`        TINYINT(1)     NOT NULL DEFAULT 1,
    `marcado_manual`  TINYINT(1)     NOT NULL DEFAULT 0,
    `fecha`           VARCHAR(50)    DEFAULT NULL,
    PRIMARY KEY (`usuario_id`, `curso_id`, `modulo_num`),
    INDEX `idx_ue_curso` (`curso_id`),
    INDEX `idx_ue_user` (`usuario_id`),
    FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Progreso de Usuario: Intentos por módulo (Relación normalizada)
CREATE TABLE IF NOT EXISTS `usuario_intentos` (
    `usuario_id` VARCHAR(50)  NOT NULL,
    `curso_id`   VARCHAR(100) NOT NULL,
    `modulo_num` VARCHAR(50)  NOT NULL,
    `intentos`   INT          NOT NULL DEFAULT 1,
    PRIMARY KEY (`usuario_id`, `curso_id`, `modulo_num`),
    INDEX `idx_ui_curso` (`curso_id`),
    INDEX `idx_ui_user` (`usuario_id`),
    FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Solicitudes de registro (nuevos usuarios)
CREATE TABLE IF NOT EXISTS `solicitudes_registro` (
    `id`                   VARCHAR(50)  NOT NULL,
    `nombre`               VARCHAR(255) NOT NULL DEFAULT '',
    `clave`                VARCHAR(255) NOT NULL DEFAULT '',
    `perfil_deseado`       VARCHAR(100) NOT NULL DEFAULT '',
    `fecha`                VARCHAR(50)  NOT NULL DEFAULT '',
    `auto_assign_career_id` VARCHAR(100) DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Solicitudes de acceso a cursos
CREATE TABLE IF NOT EXISTS `solicitudes_cursos` (
    `id`        INT          NOT NULL AUTO_INCREMENT,
    `user_id`   VARCHAR(50)  NOT NULL,
    `user_name` VARCHAR(255) NOT NULL DEFAULT '',
    `curso_id`  VARCHAR(100) NOT NULL,
    `fecha`     VARCHAR(50)  NOT NULL DEFAULT '',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Configuración general (clave-valor)
CREATE TABLE IF NOT EXISTS `configuracion` (
    `clave` VARCHAR(100) NOT NULL,
    `valor` MEDIUMTEXT,
    PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Log de actividad y auditoría
CREATE TABLE IF NOT EXISTS `activity_log` (
    `id`         INT          NOT NULL AUTO_INCREMENT,
    `usuario_id` VARCHAR(50)  NOT NULL DEFAULT '',
    `accion`     VARCHAR(100) NOT NULL DEFAULT '',
    `detalle`    TEXT,
    `ip`         VARCHAR(45)  NOT NULL DEFAULT '',
    `fecha`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX (`usuario_id`),
    INDEX (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rate limiting de intentos de login
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `ip`              VARCHAR(45) NOT NULL,
    `intentos`        INT         NOT NULL DEFAULT 0,
    `bloqueado_hasta` DATETIME DEFAULT NULL,
    `ultima_vez`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tokens de acceso seguro / invitaciones y restablecimiento de contraseña
CREATE TABLE IF NOT EXISTS `tokens_acceso` (
    `token`       VARCHAR(64)  NOT NULL,
    `usuario_id`  VARCHAR(50)  NOT NULL,
    `tipo`        VARCHAR(20)  NOT NULL DEFAULT 'reset',
    `expira`      DATETIME     NOT NULL,
    `usado`       TINYINT(1)   NOT NULL DEFAULT 0,
    `creado_en`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `usado_en`    DATETIME     DEFAULT NULL,
    PRIMARY KEY (`token`),
    INDEX `idx_ta_usuario` (`usuario_id`),
    INDEX `idx_ta_expira` (`expira`),
    INDEX `idx_ta_usado` (`usado`),
    FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Historial de reintentos habilitados por un administrador sobre evaluaciones reprobadas.
-- Cada fila suma `intentos_otorgados` al límite del módulo mientras `activo` = 1; nunca se
-- borra al restablecer progreso (solo se desactiva) para conservar el historial en reportes.
CREATE TABLE IF NOT EXISTS `usuario_habilitaciones` (
    `id`                  INT           NOT NULL AUTO_INCREMENT,
    `usuario_id`          VARCHAR(50)   NOT NULL,
    `curso_id`            VARCHAR(100)  NOT NULL,
    `modulo_num`          VARCHAR(50)   NOT NULL,
    `intentos_otorgados`  INT           NOT NULL DEFAULT 1,
    `intentos_previos`    INT           NOT NULL DEFAULT 0,
    `calificacion_previa` DECIMAL(5,2)  DEFAULT NULL,
    `repasar_lecciones`   TINYINT(1)    NOT NULL DEFAULT 0,
    `motivo`              VARCHAR(500)  NOT NULL DEFAULT '',
    `admin_id`            VARCHAR(50)   NOT NULL DEFAULT '',
    `fecha`               DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `activo`              TINYINT(1)    NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    INDEX `idx_uh_user_curso_mod` (`usuario_id`, `curso_id`, `modulo_num`),
    FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

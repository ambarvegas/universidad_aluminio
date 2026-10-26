<?php

use PHPUnit\Framework\TestCase;

/**
 * Base para pruebas contra la base de datos de prueba.
 * Antes de cada prueba se borran todas las tablas y se recrea el esquema completo.
 */
abstract class BaseDatosTestCase extends TestCase
{
    protected static mysqli $conn;

    public static function setUpBeforeClass(): void
    {
        self::$conn = db_connect();
    }

    protected function setUp(): void
    {
        self::reiniciarEsquema(self::$conn);
    }

    /** Elimina todas las tablas y recrea el esquema de la aplicación. */
    public static function reiniciarEsquema(mysqli $conn): void
    {
        $conn->query('SET FOREIGN_KEY_CHECKS=0');
        $res = $conn->query('SHOW TABLES');
        while ($t = $res->fetch_row()) {
            $conn->query("DROP TABLE `{$t[0]}`");
        }
        $conn->query('SET FOREIGN_KEY_CHECKS=1');
        db_create_tables($conn);
        db_create_activity_log_table($conn);
        db_create_rate_limit_table($conn);
        $conn->query(DB_SQL_USUARIO_HABILITACIONES);
    }

    /**
     * Datos de ejemplo:
     * - Usuarios: 1000 admin, 2000 Ana (asesor), 3000 Beto (asesor). Clave de todos: "x".
     * - C1 (especializado, rol asesor): M0 con 1 pregunta y máx. 2 intentos (L 0-0, 0-1), M1 sin preguntas (L 1-0).
     * - C2 (público, asignado a Ana): M0 con 1 pregunta, intentos ilimitados.
     * - Ana: lecciones 0-0 y 0-1 de C1, y el módulo 0 de C1 reprobado con 2 de 2 intentos (bloqueado).
     * - Configuración con smtp_pass; una solicitud de registro (4000).
     * En todas las preguntas la respuesta correcta es la opción 0.
     */
    public static function sembrarDatosBase(mysqli $conn): void
    {
        $conn->multi_query("
            INSERT INTO usuarios (id,nombre,clave,rol,estado,email) VALUES
              ('1000','Admin','x','admin','activo','admin@test.local'),
              ('2000','Ana Pérez','x','asesor','activo','ana@test.local'),
              ('3000','Beto','x','asesor','activo','');
            INSERT INTO roles_config (id,nombre) VALUES ('asesor','Asesor de Ventas');
            INSERT INTO cursos (id,titulo,tipo,en_construccion) VALUES ('C1','Curso Uno','especializado',0),('C2','Curso Dos','publico',0);
            INSERT INTO curso_modulos (id,curso_id,orden,titulo,max_intentos) VALUES (1,'C1',0,'M1',2),(2,'C1',1,'M2',0),(3,'C2',0,'Unico',0);
            INSERT INTO curso_lecciones (modulo_id,curso_id,orden,titulo) VALUES (1,'C1',0,'L1'),(1,'C1',1,'L2'),(2,'C1',0,'L3'),(3,'C2',0,'L4');
            INSERT INTO curso_preguntas (modulo_id,curso_id,orden,enunciado,opciones,correcta) VALUES
              (1,'C1',0,'¿2+2?','[\"4\",\"5\"]',0),(3,'C2',0,'¿Si?','[\"si\",\"no\"]',0);
            INSERT INTO rol_cursos (rol_id,curso_id) VALUES ('asesor','C1');
            INSERT INTO usuario_asignados (usuario_id,curso_id) VALUES ('2000','C2');
            INSERT INTO usuario_lecciones_completadas (usuario_id,curso_id,leccion_codigo) VALUES ('2000','C1','0-0'),('2000','C1','0-1');
            INSERT INTO usuario_evaluaciones (usuario_id,curso_id,modulo_num,calificacion,aprobado,marcado_manual,fecha) VALUES ('2000','C1','0',0,0,0,'2026-10-01T10:00:00');
            INSERT INTO usuario_intentos (usuario_id,curso_id,modulo_num,intentos) VALUES ('2000','C1','0',2);
            INSERT INTO configuracion (clave,valor) VALUES ('minAprobacion','70'),('smtp_pass','secreto'),('nombreInstitucion','Uni Test');
            INSERT INTO solicitudes_registro (id,nombre,clave,perfil_deseado,fecha) VALUES ('4000','Carla','x','asesor','2026-10-01');
        ");
        while ($conn->more_results() && $conn->next_result()) {
        }
    }

    /** Progreso de un usuario en un curso, como arreglo asociativo (o null si no tiene). */
    protected function progreso(string $usuario, string $curso): ?array
    {
        $p = db_leer_progreso(self::$conn, $usuario)[$usuario][$curso] ?? null;
        return $p === null ? null : json_decode(json_encode($p), true);
    }

    /** Primer valor de la primera fila de una consulta. */
    protected function valor(string $sql)
    {
        $fila = self::$conn->query($sql)->fetch_row();
        return $fila[0] ?? null;
    }

    /** Busca recursivamente el objeto de usuario {id, progreso, ...} dentro de una respuesta. */
    protected static function buscarUsuario($nodo, string $id): ?array
    {
        if (!is_array($nodo)) return null;
        if (($nodo['id'] ?? null) === $id && array_key_exists('progreso', $nodo)) return $nodo;
        foreach ($nodo as $hijo) {
            if ($u = self::buscarUsuario($hijo, $id)) return $u;
        }
        return null;
    }
}

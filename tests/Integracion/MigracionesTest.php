<?php

/** Migraciones del esquema (lib/migraciones.php y la carpeta migraciones/). */
final class MigracionesTest extends BaseDatosTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/unialu_migr_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        self::$conn->query('DROP TABLE IF EXISTS `prueba_migr`');
    }

    private function archivo(string $nombre, string $contenido): void
    {
        file_put_contents("{$this->dir}/$nombre", $contenido);
    }

    private function versiones(): array
    {
        $res = self::$conn->query('SELECT version FROM schema_migraciones ORDER BY version');
        return array_map('intval', array_column($res->fetch_all(MYSQLI_ASSOC), 'version'));
    }

    public function testLaCarpetaDelProyectoDejaElEsquemaAlDia(): void
    {
        // setUp ya aplicó migraciones/ sobre una base vacía
        $estado = db_estado_migraciones(self::$conn);
        $this->assertSame([], $estado['pendientes']);
        $this->assertSame($estado['disponible'], $estado['version']);
        $this->assertGreaterThanOrEqual(1, $estado['version']);

        $tablas = array_column(self::$conn->query('SHOW TABLES')->fetch_all(), 0);
        foreach (['usuarios', 'cursos', 'usuario_habilitaciones', 'tokens_acceso', 'schema_migraciones'] as $t) {
            $this->assertContains($t, $tablas);
        }
        $this->assertSame([], db_migrar(self::$conn), 'una segunda pasada no aplica nada');
    }

    public function testElEsquemaInicialEsSeguroSobreUnaBaseExistente(): void
    {
        // Como producción: tablas con datos pero sin registro de migraciones
        self::sembrarDatosBase(self::$conn);
        self::$conn->query('DROP TABLE `schema_migraciones`');

        $this->assertContains('0001_esquema_inicial.sql', db_migrar(self::$conn));
        $this->assertSame('3', self::$conn->query('SELECT COUNT(*) FROM usuarios')->fetch_row()[0], 'los datos siguen ahí');
    }

    public function testAplicaEnOrdenSqlYPhpUnaSolaVez(): void
    {
        $this->archivo('0002_columna.php', "<?php\nreturn function (mysqli \$c): void {\n"
            . "    \$c->query(\"ALTER TABLE prueba_migr ADD COLUMN b INT NOT NULL DEFAULT 7\");\n};\n");
        $this->archivo('0001_tabla.sql', "-- crea la tabla; con ; en un comentario\nCREATE TABLE prueba_migr (a INT);\n"
            . "INSERT INTO prueba_migr (a) VALUES (1);\n");
        $this->archivo('notas.txt', 'se ignora');
        $this->archivo('3_mal_nombre.sql', 'SELECT nada_que_ver;');
        self::$conn->query('TRUNCATE schema_migraciones');

        $this->assertSame(['0001_tabla.sql', '0002_columna.php'], db_migrar(self::$conn, $this->dir));
        $this->assertSame(['1', '7'], self::$conn->query('SELECT a, b FROM prueba_migr')->fetch_row());
        $this->assertSame([], db_migrar(self::$conn, $this->dir));
        $this->assertSame([1, 2], $this->versiones());
    }

    public function testUnaMigracionFallidaDetieneLasSiguientesYSeReintenta(): void
    {
        self::$conn->query('TRUNCATE schema_migraciones');
        $this->archivo('0001_ok.sql', 'CREATE TABLE prueba_migr (a INT);');
        $this->archivo('0002_falla.sql', 'ALTER TABLE tabla_que_no_existe ADD COLUMN x INT;');
        $this->archivo('0003_despues.sql', 'INSERT INTO prueba_migr (a) VALUES (3);');

        try {
            db_migrar(self::$conn, $this->dir);
            $this->fail('debía fallar');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('0002_falla.sql', $e->getMessage());
        }
        $this->assertSame([1], $this->versiones());
        $this->assertSame('0', self::$conn->query('SELECT COUNT(*) FROM prueba_migr')->fetch_row()[0]);
        $this->assertSame(['0002_falla.sql', '0003_despues.sql'], db_estado_migraciones(self::$conn, $this->dir)['pendientes']);

        // Corregida la migración, el siguiente intento aplica las dos pendientes
        $this->archivo('0002_falla.sql', 'ALTER TABLE prueba_migr ADD COLUMN x INT;');
        $this->assertSame(['0002_falla.sql', '0003_despues.sql'], db_migrar(self::$conn, $this->dir));
    }

    public function testLasDestructivasHacenRespaldoAntesYNoSeAplicanSiFalla(): void
    {
        self::$conn->query('TRUNCATE schema_migraciones');
        self::$conn->query('CREATE TABLE prueba_migr (a INT)');
        $this->archivo('0001_normal.sql', 'INSERT INTO prueba_migr (a) VALUES (1);');
        $this->archivo('0002_borra.sql', "-- @destructiva\nDROP TABLE prueba_migr;");

        $falla = function () { throw new RuntimeException('disco lleno'); };
        try {
            db_migrar(self::$conn, $this->dir, $falla);
            $this->fail('debía fallar el respaldo');
        } catch (RuntimeException $e) {
            $this->assertSame('disco lleno', $e->getMessage());
        }
        $this->assertSame([1], $this->versiones(), 'la normal se aplicó; la destructiva no');
        $this->assertSame('1', self::$conn->query('SELECT COUNT(*) FROM prueba_migr')->fetch_row()[0]);

        $respaldos = 0;
        $ok = function () use (&$respaldos) { $respaldos++; };
        $this->assertSame(['0002_borra.sql'], db_migrar(self::$conn, $this->dir, $ok));
        $this->assertSame(1, $respaldos);
        $this->assertSame(0, self::$conn->query("SHOW TABLES LIKE 'prueba_migr'")->num_rows);
    }

    /** 0003: el antiguo mapa fijo rol → carrera pasa a rol_carreras solo si ambos existen. */
    public function testElMapaFijoDeCarrerasPasaARolCarreras(): void
    {
        self::$conn->query("INSERT INTO roles_config (id, nombre) VALUES ('asesor_ventas','Asesor'),('retail','Retail'),('cristalero','Cristalero')");
        self::$conn->query("INSERT INTO carreras (id, nombre) VALUES ('CAR-ASESOR-VENTAS','A'),('CAR-RETAIL','R'),('CAR-ALMACENISTA','Al')");
        self::$conn->query("INSERT INTO rol_carreras (rol_id, carrera_id) VALUES ('retail','CAR-RETAIL')");   // ya estaba
        self::$conn->query('DELETE FROM schema_migraciones WHERE version = 3');

        $this->assertSame(['0003_rol_carreras_desde_mapa_fijo.sql'], db_migrar(self::$conn));
        $pares = self::$conn->query("SELECT CONCAT(rol_id, '>', carrera_id) FROM rol_carreras ORDER BY 1")->fetch_all();
        // cristalero sin su carrera y almacenista_retail sin rol: no se agregan
        $this->assertSame(['asesor_ventas>CAR-ASESOR-VENTAS', 'retail>CAR-RETAIL'], array_column($pares, 0));
    }

    public function testNumerosRepetidosSonUnError(): void
    {
        $this->archivo('0001_a.sql', 'SELECT 1;');
        $this->archivo('0001_b.sql', 'SELECT 1;');
        $this->expectException(RuntimeException::class);
        db_migrar(self::$conn, $this->dir);
    }

    public function testElDiagnosticoMuestraLaVersionDelEsquema(): void
    {
        $esquema = db_get_health_status(self::$conn)['database']['esquema'];
        $this->assertSame([], $esquema['pendientes']);
        $this->assertSame($esquema['disponible'], $esquema['version']);
    }
}

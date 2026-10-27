<?php

/**
 * Retroalimentación por pregunta: se guarda desde el editor, el administrador la ve, el
 * participante solo la recibe al calificar y solo la del resultado que obtuvo.
 */
final class RetroalimentacionTest extends BaseDatosTestCase
{
    private const BIEN = "¡Correcto! Es «sí» porque…\nSegunda línea.";
    private const MAL = 'Repasa la lección 4: la respuesta era «sí».';

    protected function setUp(): void
    {
        parent::setUp();
        self::sembrarDatosBase(self::$conn);
        // C2: una pregunta (correcta = opción 0) con los dos mensajes; C1 queda sin mensajes
        db_upsert_curso(self::$conn, [
            'id' => 'C2', 'titulo' => 'Curso Dos', 'tipo' => 'publico',
            'modulos' => [[
                'titulo' => 'Unico', 'maxIntentos' => 0,
                'lecciones' => [['titulo' => 'L4']],
                'evaluacion' => ['preguntas' => [[
                    'enunciado' => '¿Si?', 'opciones' => ['si', 'no'], 'correcta' => 0,
                    'retroCorrecta' => '  ' . self::BIEN . '  ', 'retroIncorrecta' => self::MAL,
                ]]],
            ]],
        ]);
    }

    private function preguntaDe(array $cursos, string $cursoId): array
    {
        foreach ($cursos as $c) {
            if ($c['id'] === $cursoId) return $c['modulos'][0]['evaluacion']['preguntas'][0];
        }
        $this->fail("No está el curso $cursoId");
    }

    public function testElAdministradorVeLosMensajesGuardados(): void
    {
        $p = $this->preguntaDe(db_read_all(self::$conn)['cursos'], 'C2');
        $this->assertSame(self::BIEN, $p['retroCorrecta'], 'se guarda sin espacios sobrantes');
        $this->assertSame(self::MAL, $p['retroIncorrecta']);

        $detalle = db_read_curso_detalle(self::$conn, 'C2', true);
        $this->assertSame(self::MAL, $detalle['modulos'][0]['evaluacion']['preguntas'][0]['retroIncorrecta']);

        $sinMensajes = $this->preguntaDe(db_read_all(self::$conn)['cursos'], 'C1');
        $this->assertSame('', $sinMensajes['retroCorrecta']);
    }

    public function testElParticipanteNoRecibeRespuestaNiMensajesAntesDeResponder(): void
    {
        $vistas = [
            'db del participante' => $this->preguntaDe(db_read_for_participant(self::$conn, '2000', 'asesor')['cursos'], 'C2'),
            'detalle de curso'    => db_read_curso_detalle(self::$conn, 'C2', false)['modulos'][0]['evaluacion']['preguntas'][0],
            'acción cursos'       => $this->preguntaDe(db_ocultar_respuestas(db_read_safe(self::$conn)['cursos']), 'C2'),
        ];
        foreach ($vistas as $donde => $p) {
            $this->assertSame('¿Si?', $p['enunciado'], $donde);
            foreach (['correcta', 'retroCorrecta', 'retroIncorrecta'] as $campo) {
                $this->assertArrayNotHasKey($campo, $p, "$donde no debe incluir $campo");
            }
        }
    }

    public function testAlCalificarLlegaSoloElMensajeDelResultado(): void
    {
        $mal = db_evaluar_modulo(self::$conn, '2000', 'C2', 0, [0 => 1])['preguntasDetalle'][0];
        $this->assertFalse($mal['esCorrecta']);
        $this->assertSame(self::MAL, $mal['retroalimentacion']);

        $bien = db_evaluar_modulo(self::$conn, '2000', 'C2', 0, [0 => 0])['preguntasDetalle'][0];
        $this->assertTrue($bien['esCorrecta']);
        $this->assertSame(self::BIEN, $bien['retroalimentacion']);
        $this->assertStringNotContainsString(self::MAL, json_encode($bien, JSON_UNESCAPED_UNICODE));
    }

    public function testSinMensajeNoSeDevuelveNada(): void
    {
        self::$conn->query("UPDATE usuario_intentos SET intentos = 0 WHERE usuario_id = '2000' AND curso_id = 'C1'");
        $p = db_evaluar_modulo(self::$conn, '2000', 'C1', 0, [0 => 1])['preguntasDetalle'][0];
        $this->assertNull($p['retroalimentacion']);
    }

    public function testLosMensajesSobrevivenAUnRespaldo(): void
    {
        $respaldo = json_decode(json_encode(db_read_safe(self::$conn)), true);
        db_write_all(self::$conn, $respaldo);
        $p = $this->preguntaDe(db_read_all(self::$conn)['cursos'], 'C2');
        $this->assertSame(self::BIEN, $p['retroCorrecta']);
        $this->assertSame(self::MAL, $p['retroIncorrecta']);
    }
}

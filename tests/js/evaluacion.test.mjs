// Resultados de una evaluación (js/campus/evaluacion.js): retroalimentación por pregunta.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { cargarScripts, ejecutar } from './entorno.mjs';

function preparar(detalle) {
    const ctx = cargarScripts(['js/core/state.js', 'js/campus/player.js', 'js/campus/evaluacion.js']);
    ctx.showToast = () => {};
    ejecutar(ctx, `sesion = { id: '2000', nombre: 'Ana', rol: 'asesor', progreso: {} };`);
    ctx.cursoActualData = { id: 'C2', modulos: [{ titulo: 'M', maxIntentos: 0, evaluacion: { preguntas: [{ id: 1 }, { id: 2 }] } }] };
    // Respuestas marcadas y zona de preguntas del DOM simulado
    const quizArea = { innerHTML: '' };
    ctx.document.querySelector = (sel) => (sel === '.quiz-area' ? quizArea : sel.includes(':checked') ? { value: '0' } : null);
    ctx.API = {
        async evaluarModulo() {
            return { aprobado: false, calificacion: 50, aciertos: 1, total: 2, minAprobacion: 70, numIntentos: 1, maxIntentos: 0,
                     progreso: {}, preguntasDetalle: detalle };
        },
    };
    return { ctx, quizArea };
}

const pregunta = (extra) => ({ id: 1, enunciado: '¿P?', opciones: ['a', 'b'], correcta: 0, seleccionada: 0, esCorrecta: true, ...extra });

test('muestra el mensaje de cada pregunta según el resultado, escapado y con saltos de línea', async () => {
    const { ctx, quizArea } = preparar([
        pregunta({ retroalimentacion: 'Bien <b>hecho</b>\nsegunda línea' }),
        pregunta({ id: 2, seleccionada: 1, esCorrecta: false, retroalimentacion: 'Repasa la lección 3' }),
    ]);
    await ctx.validarEvaluacionModulo(0);
    const html = quizArea.innerHTML;
    assert.equal((html.match(/quiz-retro/g) || []).length, 2);
    assert.ok(html.includes('Bien &lt;b&gt;hecho&lt;/b&gt;\nsegunda línea'));
    assert.ok(!html.includes('<b>hecho'));
    assert.ok(/border-success[^>]*>.*bi-lightbulb-fill/s.test(html), 'acierto en verde');
    assert.ok(/border-danger[^>]*>.*Repasa la lección 3/s.test(html), 'error en rojo');
});

test('sin mensaje no se dibuja nada extra', async () => {
    const { ctx, quizArea } = preparar([pregunta({ retroalimentacion: null }), pregunta({ id: 2 })]);
    await ctx.validarEvaluacionModulo(0);
    assert.ok(quizArea.innerHTML.includes('¿P?'));
    assert.ok(!quizArea.innerHTML.includes('quiz-retro'));
});

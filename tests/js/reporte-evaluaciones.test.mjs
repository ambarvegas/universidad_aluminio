// Reporte de evaluaciones y reintentos habilitados (js/features/reports.js)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { cargarScripts, ejecutar } from './entorno.mjs';

function preparar() {
    const ctx = cargarScripts(['js/core/state.js', 'js/features/reports.js']);
    ejecutar(ctx, `
        rolesConfig = window.rolesConfig = [];
        cursos = window.cursos = [{ id: 'C1', titulo: 'Curso', modulos: [
            { titulo: 'M1', maxIntentos: 2 }, { titulo: 'M2', maxIntentos: 2 },
            { titulo: 'M3', maxIntentos: 0 }, { titulo: 'M4', maxIntentos: 2 } ] }];
        usuarios = window.usuarios = [{ id: 'U1', nombre: 'Ana <b>', rol: 'x', progreso: { C1: {
            modulosAprobados: ['1', '3'],
            evaluaciones: {
                '0': { calificacion: 40, aprobado: false },
                '1': { calificacion: 90, aprobado: true },
                '2': { calificacion: 10, aprobado: false },
                '3': { calificacion: 100, aprobado: true, marcadoManual: true } },
            intentos: { '0': 3, '1': 3, '2': 5, '3': 1 },
            intentosExtra: { '0': 1 },
            habilitaciones: { '0': [{ intentosOtorgados: 1, intentosPrevios: 2, motivo: 'x"<y', fecha: '2026-10-13 10:00:00', activo: true }] },
        } } }];
    `);
    ctx.renderReporteEvaluaciones();
    const raw = ejecutar(ctx, '_evaluacionesRaw');
    return { ctx, modulo: (n) => raw.find((e) => e.moduloNum === n) };
}

test('reprobado bloqueado con límite base + intentos habilitados', () => {
    const { modulo } = preparar();
    const m = modulo(0);
    assert.equal(m.vecesReprobado, 3);
    assert.equal(m.maxIntentosEfectivo, 3);
    assert.equal(m.bloqueado, true);
    assert.equal(m.puedeHabilitar, true);
});

test('aprobado en línea al 3er intento cuenta 2 reprobaciones', () => {
    const { modulo } = preparar();
    assert.equal(modulo(1).vecesReprobado, 2);
    assert.equal(modulo(1).puedeHabilitar, false);
});

test('intentos ilimitados nunca bloquean', () => {
    const { modulo } = preparar();
    assert.equal(modulo(2).bloqueado, false);
    assert.equal(modulo(2).puedeHabilitar, true);
});

test('acreditación manual de Rectoría no cuenta reprobaciones', () => {
    const { modulo } = preparar();
    assert.equal(modulo(3).marcadoManual, true);
    assert.equal(modulo(3).vecesReprobado, 0);
    assert.equal(modulo(3).puedeHabilitar, false);
});

test('vista agrupada: botón Habilitar con argumentos seguros y motivo escapado', () => {
    const { ctx } = preparar();
    const html = ctx.elementos['contenedor-evaluaciones-agrupadas'].innerHTML;
    assert.ok(html.includes('abrirModalHabilitarReintento(&quot;U1&quot;, &quot;C1&quot;, &quot;0&quot;)'));
    assert.ok(html.includes('Sin intentos') && html.includes('3 reprobados') && html.includes('Habilitado ×1'));
    assert.ok(!html.includes('x"<y'));
});

test('filtro de bloqueados y vista plana', () => {
    const { ctx } = preparar();
    ctx.elementos['filtro-eval-estado'].value = 'bloqueado';
    ctx.filtrarTablaEvaluaciones();
    assert.equal(ejecutar(ctx, '_evaluacionesFiltradas.length'), 1);

    ctx.elementos['filtro-eval-estado'].value = '';
    ctx.filtrarTablaEvaluaciones();
    ctx.cambiarModoVistaEvaluaciones('plana');
    const plana = ctx.elementos['tabla-evaluaciones-body'].innerHTML;
    assert.equal((plana.match(/<tr>/g) || []).length, 4);
    assert.equal((plana.match(/abrirModalHabilitarReintento/g) || []).length, 2);
});

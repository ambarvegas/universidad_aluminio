// Reporte de evaluaciones en el navegador (js/features/reportes-evaluaciones*.js). El cálculo, los filtros y
// la paginación se prueban en PHP (tests/Integracion/ReporteEvaluacionesTest.php); aquí se
// prueba que la pantalla pida lo correcto al servidor y dibuje su respuesta de forma segura.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { cargarScripts, ejecutar, esperar } from './entorno.mjs';

const fila = (extra) => ({
    usuarioId: 'U1', usuarioNombre: 'Ana <b>', usuarioRol: 'x', rolNombre: 'Asesor', cursoId: 'C1',
    cursoTitulo: 'Curso <i>', moduloNum: 0, moduloTitulo: 'Módulo 1: M1', calificacion: 40, aprobado: false,
    marcadoManual: false, intentos: 3, vecesReprobado: 3, maxIntentosBase: 2, maxIntentosEfectivo: 3,
    intentosExtra: 1, habilitaciones: [{ intentosOtorgados: 1, intentosPrevios: 2, motivo: 'x"<y', fecha: '2026-10-13 10:00:00', activo: true }],
    bloqueado: true, reintentoPendiente: false, puedeHabilitar: true, fecha: '2026-10-13 10:00:00', fechaTimestamp: 1,
    ...extra,
});

function respuesta(modo, filas) {
    const grupos = modo === 'agrupada'
        ? [{ usuarioId: 'U1', usuarioNombre: 'Ana <b>', rolNombre: 'Asesor', evaluaciones: filas, totalModulos: filas.length,
             aprobados: 1, reprobados: 1, promedio: 70, tasaAprob: 50 }]
        : [];
    return {
        modo, total: filas.length, totalUsuarios: 1, kpis: { total: 2, promedio: 70, tasaAprob: 50, perfectas: 1 },
        pagina: 1, porPagina: 15, paginas: 3, totalItems: grupos.length || filas.length, desde: 1, hasta: grupos.length || filas.length,
        grupos, evaluaciones: modo === 'plana' ? filas : [],
    };
}

function preparar() {
    const ctx = cargarScripts(['js/core/state.js', 'js/features/reports.js', 'js/features/reportes-evaluaciones.js',
        'js/features/reportes-evaluaciones-exportar.js']);
    const pedidos = [];
    const filas = [fila(), fila({ moduloNum: 1, calificacion: 100, aprobado: true, bloqueado: false, puedeHabilitar: false,
        vecesReprobado: 0, marcadoManual: true, habilitaciones: [] })];
    ctx.API = {
        async cargarReporteEvaluaciones(f) {
            pedidos.push({ ...f });
            return respuesta(f.modo, filas);
        },
    };
    ejecutar(ctx, `
        usuarios = [{ id: 'U1', nombre: 'Ana <b>', rol: 'x' }, { id: 'A', nombre: 'Admin', rol: 'admin' }];
        cursos = [{ id: 'C1', titulo: 'Curso <i>' }];
    `);
    ctx.pedidos = pedidos;
    return ctx;
}

test('pide la primera página con los filtros de la pantalla', async () => {
    const ctx = preparar();
    ctx.document.getElementById('filtro-eval-estado').value = 'bloqueado';
    ctx.document.getElementById('filtro-eval-search').value = '  ana ';
    await ctx.renderReporteEvaluaciones();
    assert.deepEqual(ctx.pedidos.at(-1), {
        search: 'ana', usuario: '', curso: '', estado: 'bloqueado', orden: 'nombre_asc', modo: 'agrupada', page: 1, limit: 15,
    });
});

test('vista agrupada: botón Habilitar con argumentos seguros y textos escapados', async () => {
    const ctx = preparar();
    await ctx.renderReporteEvaluaciones();
    const html = ctx.elementos['contenedor-evaluaciones-agrupadas'].innerHTML;
    assert.ok(html.includes('abrirModalHabilitarReintento(&quot;U1&quot;, &quot;C1&quot;, &quot;0&quot;)'));
    assert.ok(html.includes('Sin intentos') && html.includes('3 reprobados') && html.includes('Habilitado ×1'));
    assert.ok(!html.includes('x"<y') && !html.includes('Ana <b>') && !html.includes('Curso <i>'));
    assert.ok(html.includes('Ana &lt;b&gt;'));
    assert.equal((html.match(/abrirModalHabilitarReintento/g) || []).length, 1, 'solo el reprobado');
});

test('KPIs y paginación vienen del servidor', async () => {
    const ctx = preparar();
    await ctx.renderReporteEvaluaciones();
    assert.equal(ctx.elementos['kpi-eval-promedio'].textContent, '70.0 pts');
    assert.equal(ctx.elementos['kpi-eval-tasa-aprob'].textContent, '50%');
    assert.equal(ctx.elementos['evaluaciones-paginacion-info'].textContent,
        'Mostrando 1 a 1 de 1 colaboradores (2 calificaciones en total)');
    assert.equal(ctx.elementos['btn-eval-next'].disabled, false);

    await ctx.paginaSiguienteEval();
    assert.equal(ctx.pedidos.at(-1).page, 2);
});

test('vista plana y cambio de filas por página vuelven a consultar', async () => {
    const ctx = preparar();
    await ctx.cambiarModoVistaEvaluaciones('plana');
    assert.equal(ctx.pedidos.at(-1).modo, 'plana');
    const plana = ctx.elementos['tabla-evaluaciones-body'].innerHTML;
    assert.equal((plana.match(/<tr>/g) || []).length, 2);

    await ctx.cambiarFilasPorPaginaEval('all');
    assert.deepEqual([ctx.pedidos.at(-1).limit, ctx.pedidos.at(-1).page], ['all', 1]);
});

test('el buscador consulta una sola vez al dejar de escribir', async () => {
    const ctx = preparar();
    ctx.buscarEvaluacionesDebounced();
    ctx.buscarEvaluacionesDebounced();
    assert.equal(ctx.pedidos.length, 0);
    await esperar(400);
    assert.equal(ctx.pedidos.length, 1);
});

test('los selectores de filtro escapan nombres y omiten administradores', async () => {
    const ctx = preparar();
    await ctx.renderReporteEvaluaciones();
    const opciones = ctx.elementos['filtro-eval-usuario'].innerHTML;
    assert.ok(opciones.includes('Ana &lt;b&gt;') && !opciones.includes('Admin'));
    assert.ok(ctx.elementos['filtro-eval-curso'].innerHTML.includes('Curso &lt;i&gt;'));
});

test('exportar pide todas las filas filtradas, sin paginar', async () => {
    const ctx = preparar();
    ctx.XLSX = {
        utils: { book_new: () => ({}), json_to_sheet: (d) => ({ filas: d }), book_append_sheet() {} },
        writeFile() {},
    };
    ctx.alert = () => {};
    ctx.showToast = () => {};
    await ctx.exportarEvaluacionesXLSX();
    assert.equal(ctx.pedidos.at(-1).todo, true);
    assert.equal(ctx.pedidos.at(-1).page, undefined);
});

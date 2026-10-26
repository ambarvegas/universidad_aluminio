// Estado central window.App (js/core/state.js)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { cargarScripts, ejecutar } from './entorno.mjs';

function preparar() {
    const ctx = cargarScripts(['js/core/state.js']);
    const llamadas = [];
    ctx.API = {
        async cargarProgresoUsuarios(ids) {
            llamadas.push(ids ? [...ids] : null);
            const todo = { U1: { C1: { modulosAprobados: ['0'] } }, U2: { C1: { modulosAprobados: [] } } };
            if (!ids) return { progreso: todo };
            return { progreso: Object.fromEntries(ids.filter(id => todo[id]).map(id => [id, todo[id]])) };
        },
    };
    return { ctx, llamadas };
}

const datosLigeros = () => ({
    usuarios: [{ id: 'U1', progreso: {} }, { id: 'U2', progreso: {} }, { id: 'U3', progreso: {} }],
    cursos: [{ id: 'C1' }], carreras: [], rolesConfig: [], solicitudesRegistro: [], solicitudesCursos: [],
    configuracion: { minAprobacion: 80 }, progresoIncluido: false,
});

test('las variables globales históricas leen y escriben en App.estado', () => {
    const { ctx } = preparar();
    ejecutar(ctx, `usuarios = [{ id: 'A' }]; sesion = { id: 'A' }; tempModulos = [1, 2];`);
    assert.equal(ctx.App.estado.usuarios[0].id, 'A');
    assert.equal(ctx.App.get('sesion').id, 'A');
    assert.equal(ctx.App.get('tempModulos').length, 2);

    ctx.App.set('cursos', [{ id: 'C9' }]);
    assert.equal(ejecutar(ctx, 'cursos[0].id'), 'C9');
    ejecutar(ctx, `usuarios = null`);
    assert.deepEqual([...ctx.App.get('usuarios')], [], 'una colección nunca queda en null');
});

test('db es una vista de App.estado con las mismas referencias', () => {
    const { ctx } = preparar();
    ctx.App.cargarDatos(datosLigeros());
    ejecutar(ctx, `db.configuracion.minAprobacion = 90; db.usuarios.push({ id: 'U4' });`);
    assert.equal(ctx.App.get('configuracion').minAprobacion, 90);
    assert.equal(ctx.App.get('usuarios').length, 4);
    assert.equal(ctx.App.get('configuracion').nombreInstitucion, 'Universidad del Aluminio', 'completa con valores por defecto');
    assert.ok(!('progresoIncluido' in ejecutar(ctx, 'db')), 'la marca interna no se exporta en el respaldo');
});

test('suscripciones: datos, cambio:<clave> y cancelación', () => {
    const { ctx } = preparar();
    const eventos = [];
    const off = ctx.App.on('cambio:usuarios', v => eventos.push('usuarios:' + v.length));
    ctx.App.on('datos', () => eventos.push('datos'));
    ejecutar(ctx, `usuarios = [{ id: 'X' }]`);
    ctx.App.cargarDatos(datosLigeros());
    ctx.App.cargarDatos(datosLigeros(), { silencioso: true });
    off();
    ctx.App.set('usuarios', []);
    assert.deepEqual(eventos, ['usuarios:1', 'datos']);
});

test('un suscriptor que falla no impide avisar a los demás', () => {
    const { ctx } = preparar();
    let avisado = false;
    const errorOriginal = console.error;
    console.error = () => {};
    try {
        ctx.App.on('datos', () => { throw new Error('x'); });
        ctx.App.on('datos', () => { avisado = true; });
        ctx.App.cargarDatos(datosLigeros());
    } finally {
        console.error = errorOriginal;
    }
    assert.ok(avisado);
});

test('asegurarProgreso: por usuario, sin repetir, y luego de todos', async () => {
    const { ctx, llamadas } = preparar();
    ctx.App.cargarDatos(datosLigeros());
    assert.equal(ctx.App.get('progresoCompleto'), false);

    await ctx.App.asegurarProgreso(['U1']);
    await ctx.App.asegurarProgreso('U1');
    assert.deepEqual(llamadas, [['U1']], 'el segundo pedido no consulta al servidor');
    assert.deepEqual(ctx.App.buscarUsuario('U1').progreso.C1.modulosAprobados, ['0']);

    await Promise.all([ctx.App.asegurarProgreso(), ctx.App.asegurarProgreso()]);
    assert.equal(llamadas.length, 2, 'dos pedidos simultáneos de todo comparten la descarga');
    assert.equal(llamadas[1], null);
    assert.equal(ctx.App.get('progresoCompleto'), true);
    assert.deepEqual({ ...ctx.App.buscarUsuario('U3').progreso }, {}, 'sin progreso queda vacío');

    await ctx.App.asegurarProgreso(['U2']);
    assert.equal(llamadas.length, 2, 'completo: no vuelve a consultar');
});

test('invalidarProgreso fuerza a recargar solo en el panel (datos ligeros)', async () => {
    const { ctx, llamadas } = preparar();
    ctx.App.cargarDatos(datosLigeros());
    await ctx.App.asegurarProgreso();
    ctx.App.invalidarProgreso('U1');
    await ctx.App.asegurarProgreso(['U1']);
    assert.deepEqual(llamadas, [null, ['U1']]);

    // Campus: el progreso viene completo y nunca se pide aparte
    const campus = preparar();
    campus.ctx.App.cargarDatos({ ...datosLigeros(), progresoIncluido: undefined });
    campus.ctx.App.invalidarProgreso('U1');
    await campus.ctx.App.asegurarProgreso(['U1']);
    assert.deepEqual(campus.llamadas, []);
});

test('cargarDatosDelServidor en el panel pide la carga ligera y no recalcula carreras', async () => {
    const { ctx } = preparar();
    let pedido = null;
    ctx.API.cargarDB = async (opciones) => { pedido = opciones; return datosLigeros(); };
    ejecutar(ctx, `sesion = { id: 'U1', carrerasAsignadas: [{ id: 'K', estado: 'Completada' }] }`);
    let recalculo = false;
    const original = ctx.actualizarEstadoCarrerasUsuario;
    ctx.actualizarEstadoCarrerasUsuario = (...a) => { recalculo = true; return original(...a); };

    assert.equal(await ctx.cargarDatosDelServidor(), true);
    assert.deepEqual({ ...pedido }, { ligero: true });
    assert.equal(recalculo, false);
    assert.equal(ctx.App.get('sesion').carrerasAsignadas[0].estado, 'Completada');
});

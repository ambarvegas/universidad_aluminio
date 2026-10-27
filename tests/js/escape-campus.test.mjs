// Escape de HTML en el campus (player.js, campus.js) y en la configuración (config.js):
// los textos de cursos, lecciones, preguntas, usuarios y respuestas del servidor se dibujan
// como texto, y las URLs con esquemas peligrosos (javascript:, etc.) se descartan.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { cargarScripts, ejecutar, esperar } from './entorno.mjs';

const MALO = '<img src=x onerror=alert(1)>';
const COMILLAS = `x'"); alert(1); //`;

function preparar() {
    const ctx = cargarScripts(['js/core/state.js', 'js/campus/player.js', 'js/campus/campus.js', 'js/features/config.js']);
    ctx.showToast = () => {};
    return ctx;
}

const cursoMalicioso = () => ({
    id: `C1${COMILLAS}`, titulo: `Curso ${MALO}`, tipo: 'libre',
    modulos: [{
        titulo: `Mod ${MALO}`, maxIntentos: 2,
        lecciones: [{
            titulo: `Lec ${MALO}`, contenido: `Texto ${MALO}`, videoID: 'javascript:alert(1)//http',
            adjunto: 'javascript:alert(1)', nombreAdjunto: `a${MALO}`,
        }],
        evaluacion: { preguntas: [{ id: 1, enunciado: `¿P? ${MALO}`, imagen: 'javascript:alert(1)',
            opciones: [{ texto: `Op ${MALO}`, imagen: '" onerror="alert(1)' }, 'B'] }] },
    }],
});

test('jsArg produce un literal seguro dentro de un atributo onclick', () => {
    const ctx = preparar();
    const arg = ctx.jsArg(COMILLAS);
    assert.ok(!arg.includes('"') && !arg.includes("'"), 'sin comillas sin escapar');
    // Lo que el navegador ve tras decodificar el atributo es un literal JSON válido
    const decodificado = arg.replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&');
    assert.equal(JSON.parse(decodificado), COMILLAS);
});

test('urlSegura acepta http(s), data:, blob: y rutas relativas; descarta otros esquemas', () => {
    const ctx = preparar();
    for (const ok of ['https://a.b/c.png', 'http://a.b', 'data:image/png;base64,xx', 'blob:https://a/b', 'uploads/x.png', '/uploads/x.png', 'x.png?a=b:c']) {
        assert.equal(ctx.urlSegura(ok), ok, ok);
    }
    for (const malo of ['javascript:alert(1)', ' JavaScript:alert(1)', 'java\nscript:alert(1)', 'vbscript:x', '\x01javascript:x']) {
        assert.equal(ctx.urlSegura(malo), '', JSON.stringify(malo));
    }
});

test('lección: título, contenido y adjunto escapados; sin URLs javascript:', () => {
    const ctx = preparar();
    ejecutar(ctx, `sesion = { id: 'U1', nombre: 'Ana', rol: 'admin', progreso: {} };`);
    ctx.cursoActualData = ctx.normalizarCurso(cursoMalicioso());
    // normalizarCurso no conserva campos de lección extra: se reponen como vienen del servidor
    ctx.cursoActualData.modulos[0].lecciones = cursoMalicioso().modulos[0].lecciones;
    ctx.seleccionarLeccion(0, 0);
    const html = ctx.elementos['visor-contenido'].innerHTML;
    assert.ok(!html.includes(MALO), 'ningún texto inyectado sin escapar');
    assert.ok(html.includes('Lec &lt;img') && html.includes('Texto &lt;img'));
    assert.ok(!/javascript:/i.test(html), 'ni el adjunto ni el video con javascript:');
    assert.ok(!html.includes('<iframe'), 'video sin URL https ni ID de YouTube: no se incrusta');
    assert.ok(!html.includes('Descargar Adjunto'), 'adjunto con URL insegura: no se muestra');
});

test('lección: un ID de YouTube se incrusta codificado', () => {
    const ctx = preparar();
    ejecutar(ctx, `sesion = { id: 'U1', nombre: 'Ana', rol: 'admin', progreso: {} };`);
    ctx.cursoActualData = { id: 'C', modulos: [{ titulo: 'M', lecciones: [
        { titulo: 'L', videoID: 'dQw4w9WgXcQ', adjunto: 'uploads/guia.pdf', nombreAdjunto: 'guia.pdf' }] }] };
    ctx.seleccionarLeccion(0, 0);
    const html = ctx.elementos['visor-contenido'].innerHTML;
    assert.ok(html.includes('src="https://www.youtube.com/embed/dQw4w9WgXcQ?rel=0&amp;modestbranding=1"'));
    assert.ok(html.includes('href="uploads/guia.pdf"'));
});

test('evaluación: enunciados, opciones e imágenes escapados', () => {
    const ctx = preparar();
    ejecutar(ctx, `sesion = { id: 'U1', nombre: 'Ana', rol: 'asesor', progreso: {} }; cursos = [];`);
    ctx.cursoActualData = cursoMalicioso();
    ctx.mostrarEvaluacionModulo(ctx.cursoActualData.id, 0);
    const html = ctx.elementos['visor-contenido'].innerHTML;
    assert.ok(!html.includes(MALO));
    assert.ok(html.includes('¿P? &lt;img') && html.includes('Op &lt;img'));
    assert.ok(!/javascript:/i.test(html));
    assert.ok(!html.includes('" onerror="'), 'la URL de la opción no rompe el atributo src');
});

test('temario del curso: textos escapados, argumentos seguros e intentos agotados visibles', async () => {
    const ctx = preparar();
    ejecutar(ctx, `sesion = { id: 'U1', nombre: ${JSON.stringify(COMILLAS)}, rol: 'asesor', certificadosCurso: [],
        progreso: {} };`);
    const curso = cursoMalicioso();
    ctx.App.estado.sesion.progreso[curso.id] = { leccionesCompletadas: ['0-0'], modulosAprobados: [], intentos: { 0: 2 } };
    ctx.App.estado.sesion.certificadosCurso.push(curso.id);
    const html = ctx.renderizarCursoTeachlr(curso);
    await esperar(150);   // deja correr la selección automática de lección
    assert.ok(!html.includes(MALO));
    assert.ok(html.includes('Curso &lt;img') && html.includes('Mod &lt;img') && html.includes('Lec &lt;img'));
    assert.ok(html.includes(`descargarCertificado(${ctx.jsArg(COMILLAS)}, &quot;U1&quot;, ${ctx.jsArg(curso.titulo)})`));
    assert.ok(html.includes('Intentos Agotados (Bloqueada)'), '2 de 2 intentos usados: módulo bloqueado');
});

test('galería, carreras y certificados del campus escapados', async () => {
    const ctx = preparar();
    ejecutar(ctx, `
        sesion = { id: 'U1', nombre: ${JSON.stringify(COMILLAS)}, rol: 'asesor', email: ${JSON.stringify(MALO)},
                   progreso: {}, certificadosCurso: [], certificadosCarrera: ['K1'], carrerasAsignadas: [{ id: 'K1' }] };
        carreras = [{ id: 'K1', nombre: ${JSON.stringify('Carrera ' + MALO)}, cursos: ['C2'] }];
    `);
    ctx.cursos.push({ ...cursoMalicioso(), imagen: 'javascript:alert(1)' },
                    { id: 'C2', titulo: 'C2', tipo: 'especializado', prelacion: cursoMalicioso().id, modulos: [] });
    ctx.renderizarGaleria();
    const galeria = ctx.elementos['galeria-cursos-row'].innerHTML;
    assert.ok(!galeria.includes(MALO) && galeria.includes('Curso &lt;img'));
    assert.ok(!/javascript:/i.test(galeria));
    assert.ok(galeria.includes(`href="detalle.php?id=${encodeURIComponent(cursoMalicioso().id)}"`));
    assert.ok(!ctx.elementos['nav-user-info'].innerHTML.includes(`x'"`));

    ctx.renderizarCarreras();
    const car = ctx.elementos['galeria-carreras-row'].innerHTML;
    assert.ok(car.includes('Carrera &lt;img') && car.includes(`descargarDiplomaCarrera(&quot;K1&quot;, ${ctx.jsArg(COMILLAS)}, &quot;U1&quot;)`));

    await ctx.renderizarVitrinaCertificados();
    assert.ok(ctx.elementos['vitrina-certificados-row'].innerHTML.includes('Carrera &lt;img'));

    ctx.document.getElementById('form-cambiar-clave').reset = () => {};
    ctx.abrirModalPerfil();
    assert.ok(ctx.elementos['perfil-resumen-email'].innerHTML.includes('&lt;img src=x'));
});

test('configuración: diagnóstico y respaldos del servidor escapados; colores inválidos ignorados', async () => {
    const ctx = preparar();
    ctx.API = {
        async obtenerEstadoSistema() {
            return { status: 'warning', timestamp: Date.now(), warnings: [MALO],
                database: { host: MALO, name: 'n', latencia_ms: 5 }, storage: {}, php: { extensiones: { [MALO]: true } },
                mailer: { smtp_host: MALO, email_admin: MALO } };
        },
        async obtenerListaRespaldos() { return { respaldos: [{ archivo: MALO, fecha: 'f', tamano: '1 KB', tipo: 't' }] }; },
    };
    await ctx.cargarEstadoSistemaModal();
    assert.ok(!ctx.elementos['modal-diagnostico-body'].innerHTML.includes(MALO));
    await ctx.cargarListaRespaldos();
    assert.ok(ctx.elementos['tabla-respaldos-body'].innerHTML.includes('&lt;img src=x'));

    const props = {};
    ctx.document.documentElement = { style: { setProperty: (k, v) => { props[k] = v; } } };
    ctx.aplicarColoresPlataforma('red;background:url(x)', '#00ff00');
    assert.equal(props['--primary-color'], '#0f2b48');
    assert.equal(props['--accent-color'], '#00ff00');
});

test('los avisos (toast) muestran el mensaje como texto', () => {
    const ctx = cargarScripts(['js/ui/toast.js']);
    const span = { textContent: '' };
    const creados = [];
    ctx.document.createElement = () => {
        const el = { style: {}, classList: { add() {}, remove() {} }, remove() {}, setAttribute() {}, addEventListener() {}, appendChild() {}, querySelector: () => span };
        creados.push(el);
        return el;
    };
    ctx.document.getElementById = () => null;
    ctx.showToast(MALO, 'danger');
    const toast = creados.find((el) => el.innerHTML?.includes('toast-body'));
    assert.ok(toast && !toast.innerHTML.includes(MALO));
    assert.equal(span.textContent, MALO);
});

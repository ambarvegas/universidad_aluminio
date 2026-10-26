// Carga scripts del frontend en un contexto aislado con un DOM mínimo simulado.
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const RAIZ = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');

/**
 * Crea un contexto "navegador" y ejecuta en orden los scripts indicados (rutas relativas al proyecto).
 * Los elementos del DOM se crean al pedirlos por id y se exponen en `ctx.elementos`.
 */
export function cargarScripts(scripts) {
    const elementos = {};
    const elemento = (id) => (elementos[id] ??= {
        id, innerHTML: '', textContent: '', value: '', className: '', disabled: false, style: {},
        classList: { add() {}, remove() {}, contains: () => false },
        setAttribute() {}, addEventListener() {}, querySelector: () => null,
    });
    const ctx = {
        console, setTimeout, clearTimeout, setInterval, clearInterval,
        sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
        localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
        location: { pathname: '/admin.php', href: '' },
        document: {
            getElementById: elemento, querySelector: () => null, querySelectorAll: () => [],
            addEventListener() {}, createElement: () => elemento('_creado_' + Math.random()),
            body: { appendChild() {} }, visibilityState: 'visible',
        },
    };
    ctx.window = ctx;
    vm.createContext(ctx);
    for (const s of scripts) {
        vm.runInContext(fs.readFileSync(path.join(RAIZ, s), 'utf8'), ctx, { filename: s });
    }
    ctx.elementos = elementos;
    return ctx;
}

/** Ejecuta código dentro del contexto (para asignar variables globales como `usuarios`). */
export const ejecutar = (ctx, codigo) => vm.runInContext(codigo, ctx);

export const esperar = (ms) => new Promise((r) => setTimeout(r, ms));

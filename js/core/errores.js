/**
 * js/core/errores.js
 * Envía al servidor los errores de JavaScript de la aplicación (errores no capturados y
 * promesas rechazadas sin .catch) para verlos en el diagnóstico del panel.
 * Se carga antes que el resto de scripts propios para capturar también sus errores de carga.
 * Ignora errores de extensiones del navegador y de scripts de otros dominios.
 */
(function () {
    const MAX_POR_PAGINA = 10;
    const IGNORAR = [/ResizeObserver loop/i, /^Script error\.?$/i];
    const enviados = new Set();
    let total = 0;

    function esScriptPropio(archivo) {
        if (!archivo) return true;   // errores sin archivo (p. ej. promesas): se aceptan
        try {
            return new URL(archivo, location.href).origin === location.origin;
        } catch (e) {
            return false;
        }
    }

    function texto(valor) {
        if (valor instanceof Error) return valor.name + ': ' + valor.message;
        if (typeof valor === 'string') return valor;
        try { return JSON.stringify(valor); } catch (e) { return String(valor); }
    }

    /**
     * Registra un error en el servidor. También se puede llamar a mano:
     * reportarError(err, 'contexto opcional').
     */
    function reportarError(error, contexto = '', archivo = '', linea = 0, columna = 0) {
        try {
            const mensaje = (contexto ? contexto + ' — ' : '') + texto(error);
            if (!mensaje || IGNORAR.some((re) => re.test(texto(error)))) return;
            if (!esScriptPropio(archivo)) return;

            const pila = error && error.stack ? String(error.stack) : '';
            const ubicacion = archivo ? `${archivo.replace(location.origin, '')}:${linea}:${columna}` : '';
            const clave = mensaje + '|' + ubicacion;
            if (enviados.has(clave) || total >= MAX_POR_PAGINA) return;
            enviados.add(clave);
            total++;

            const datos = JSON.stringify({
                mensaje,
                detalle: [ubicacion, pila].filter(Boolean).join('\n'),
                url: location.pathname,
                version: typeof APP_CLIENT_VERSION !== 'undefined' ? APP_CLIENT_VERSION : '',
            });
            fetch('api.php?action=registrar_error', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: datos,
                credentials: 'same-origin',
                keepalive: true,
            }).catch(() => {});
        } catch (e) {
            // Registrar un error nunca debe provocar otro
        }
    }

    window.addEventListener('error', (ev) => {
        // Fallos de carga de recursos (<img>, <script>) no traen ev.error y llegan sin mensaje
        if (!ev.message && !ev.error) return;
        reportarError(ev.error || ev.message, '', ev.filename, ev.lineno, ev.colno);
    });

    window.addEventListener('unhandledrejection', (ev) => {
        reportarError(ev.reason, 'Promesa rechazada sin capturar');
    });

    window.reportarError = reportarError;
})();

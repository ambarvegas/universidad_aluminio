/**
 * js/features/config.js
 * Configuración institucional: nombre, logo, colores de plataforma,
 * mensaje de bienvenida, modo mantenimiento y porcentaje de aprobación.
 */

const DEFAULT_COLORS = {
    primary: '#0f2b48',
    accent:  '#0284c7'
};

// -------------------------------------------------------
// CARGA INICIAL DE CONFIGURACIÓN
// -------------------------------------------------------

/**
 * Aplica toda la configuración guardada al cargar la página.
 * Se debe llamar en DOMContentLoaded.
 */
function cargarConfiguracion() {
    const cfg = (db && db.configuracion) ? db.configuracion : {};

    // Colores
    const primary = cfg.colorPrimario || DEFAULT_COLORS.primary;
    const accent  = cfg.colorAcento   || DEFAULT_COLORS.accent;
    aplicarColoresPlataforma(primary, accent);

    // Nombre institución en navbar/footer si existe
    if (cfg.nombreInstitucion) {
        aplicarNombreInstitucion(cfg.nombreInstitucion);
    }

    // Logo como favicon
    const logoSaved = cfg.logo || localStorage.getItem('aluLogo') || '';
    if (logoSaved) {
        actualizarFavicon(logoSaved);
        mostrarPreviewLogo(logoSaved);
    }

    // Rellenar controles del panel admin si existen en el DOM
    const elNombre = document.getElementById('cfg-nombre-universidad');
    if (elNombre) elNombre.value = cfg.nombreInstitucion || '';

    const elMin = document.getElementById('cfg-min-aprobacion');
    if (elMin) elMin.value = cfg.minAprobacion || 70;

    const elMsg = document.getElementById('cfg-mensaje-bienvenida');
    if (elMsg) elMsg.value = cfg.mensajeBienvenida || '';

    const elMant = document.getElementById('cfg-modo-mantenimiento');
    if (elMant) {
        elMant.checked = !!cfg.modoMantenimiento;
        const alert = document.getElementById('mantenimiento-alert');
        if (alert) alert.style.display = cfg.modoMantenimiento ? 'block' : 'none';
    }

    // Color pickers
    const elPrimario = document.getElementById('cfg-color-primario');
    const elPrimarioHex = document.getElementById('cfg-color-primario-hex');
    if (elPrimario) elPrimario.value = primary;
    if (elPrimarioHex) elPrimarioHex.value = primary;

    const elAcento = document.getElementById('cfg-color-acento');
    const elAcentoHex = document.getElementById('cfg-color-acento-hex');
    if (elAcento) elAcento.value = accent;
    if (elAcentoHex) elAcentoHex.value = accent;

    // Barras de preview
    previewColores();
}

// -------------------------------------------------------
// NOMBRE DE LA UNIVERSIDAD
// -------------------------------------------------------

/**
 * Previsuali­za el nombre en tiempo real mientras el admin escribe.
 */
function previsualizarNombreUniversidad(val) {
    aplicarNombreInstitucion(val.trim() || 'Universidad del Aluminio');
}

/**
 * Actualiza todos los elementos del DOM que muestran el nombre institucional.
 */
function aplicarNombreInstitucion(nombre) {
    // Navbar brand en admin.html y en index.html
    document.querySelectorAll('.navbar-brand span, #nombre-universidad-nav').forEach(el => {
        if (el) el.textContent = nombre + ' | Rectoría';
    });
    // Footer
    document.querySelectorAll('#footer-nombre-uni, .footer-nombre').forEach(el => {
        if (el) el.textContent = nombre;
    });
    // Title del documento
    if (document.title.includes('Panel de Control')) {
        document.title = `Panel de Control Académico — ${nombre}`;
    }
}

/**
 * Guarda el nombre de la institución en db.configuracion.
 */
async function guardarNombreInstitucion(val) {
    if (!db.configuracion) db.configuracion = {};
    db.configuracion.nombreInstitucion = val.trim();
    try {
        await window.API.guardarConfig('nombreInstitucion', val.trim());
    } catch (e) {
        await guardarTodo();
    }
}

// -------------------------------------------------------
// COLORES DE LA PLATAFORMA
// -------------------------------------------------------

/**
 * Aplica variables CSS de color al :root del documento.
 */
function aplicarColoresPlataforma(primary, accent) {
    const root = document.documentElement;
    root.style.setProperty('--primary-color', primary);
    root.style.setProperty('--primary-dark',  shadeColor(primary, -15));
    root.style.setProperty('--primary-light', shadeColor(primary, 20));
    root.style.setProperty('--accent-color',  accent);
    root.style.setProperty('--accent-hover',  shadeColor(accent, -10));
    root.style.setProperty('--shadow-glow',   `0 0 25px ${hexToRgba(accent, 0.25)}`);
}

/**
 * Preview en vivo mientras el usuario mueve el color picker.
 */
function previewColores() {
    const primary = document.getElementById('cfg-color-primario')?.value || DEFAULT_COLORS.primary;
    const accent  = document.getElementById('cfg-color-acento')?.value  || DEFAULT_COLORS.accent;

    // Sincronizar campos de texto hex
    const hexP = document.getElementById('cfg-color-primario-hex');
    const hexA = document.getElementById('cfg-color-acento-hex');
    if (hexP) hexP.value = primary;
    if (hexA) hexA.value = accent;

    // Barras de preview
    const barP = document.getElementById('preview-bar-primary');
    const barA = document.getElementById('preview-bar-accent');
    if (barP) barP.style.background = primary;
    if (barA) barA.style.background = accent;

    // Aplicar al DOM
    aplicarColoresPlataforma(primary, accent);
}

/**
 * Sincroniza el color picker nativo cuando el usuario escribe el hex manualmente.
 */
function sincronizarColorHex(pickerId, hexValue) {
    if (/^#[0-9a-fA-F]{6}$/.test(hexValue)) {
        const picker = document.getElementById(pickerId);
        if (picker) picker.value = hexValue;
        previewColores();
    }
}

/**
 * Restablece los colores a los valores originales del diseño.
 */
function restablecerColores() {
    const elP = document.getElementById('cfg-color-primario');
    const elA = document.getElementById('cfg-color-acento');
    const hexP = document.getElementById('cfg-color-primario-hex');
    const hexA = document.getElementById('cfg-color-acento-hex');

    if (elP) elP.value = DEFAULT_COLORS.primary;
    if (elA) elA.value = DEFAULT_COLORS.accent;
    if (hexP) hexP.value = DEFAULT_COLORS.primary;
    if (hexA) hexA.value = DEFAULT_COLORS.accent;

    previewColores();
    showToast('Colores restablecidos a los valores originales.', 'info');
}

/**
 * Guarda los colores seleccionados en db.configuracion.
 */
async function guardarColoresPlataforma() {
    const primary = document.getElementById('cfg-color-primario')?.value || DEFAULT_COLORS.primary;
    const accent  = document.getElementById('cfg-color-acento')?.value  || DEFAULT_COLORS.accent;

    if (!db.configuracion) db.configuracion = {};
    db.configuracion.colorPrimario = primary;
    db.configuracion.colorAcento   = accent;

    try {
        await window.API.guardarConfig('colorPrimario', primary);
        await window.API.guardarConfig('colorAcento', accent);
    } catch (e) {
        await guardarTodo();
    }
}

// -------------------------------------------------------
// LOGO INSTITUCIONAL
// -------------------------------------------------------

/**
 * Actualiza el favicon del navegador con el logo institucional.
 * Acepta URL relativa del servidor (uploads/...) o base64.
 */
function actualizarFavicon(src) {
    if (!src) return;
    let link = document.querySelector('link[rel="icon"]') || document.querySelector('link[rel="shortcut icon"]');
    if (!link) {
        link = document.createElement('link');
        link.rel = 'icon';
        document.head.appendChild(link);
    }
    link.type = 'image/jpeg';
    link.href = src;
}

async function cargarLogoInstitucion(event) {
    const file = event.target.files[0];
    if (!file) return;

    const label = document.getElementById('btn-cargar-logo');

    await withLoading(label, async () => {
        let logoSrc;

        // Usar subida al servidor si está disponible
        if (typeof subirImagenServidor === 'function') {
            const prevUrl = (db.configuracion && db.configuracion.logo) || '';
            logoSrc = await subirImagenServidor(file, 'logo', 'institucional', prevUrl);
        } else {
            logoSrc = await comprimirImagenBase64(file, 400, 0.85);
            localStorage.setItem('aluLogo', logoSrc);
        }

        if (!db.configuracion) db.configuracion = {};
        db.configuracion.logo = logoSrc;

        mostrarPreviewLogo(logoSrc);
        actualizarFavicon(logoSrc);

        try {
            await window.API.guardarConfig('logo', logoSrc);
        } catch (e) {
            console.warn('Fallback a guardarTodo para logo:', e);
            await guardarTodo();
        }

        showToast('Logo institucional actualizado con éxito.', 'success');
    }, 'Cargando logo...');
}

function mostrarPreviewLogo(src) {
    const container = document.getElementById('logo-preview-container');
    const img = document.getElementById('logo-preview-img');
    if (container && img) {
        img.src = src;
        container.style.display = 'flex';
        container.style.alignItems = 'center';
    }
}

function eliminarLogo() {
    if (!db.configuracion) db.configuracion = {};
    db.configuracion.logo = '';
    localStorage.removeItem('aluLogo');
    const container = document.getElementById('logo-preview-container');
    if (container) container.style.display = 'none';
    const input = document.getElementById('input-logo');
    if (input) input.value = '';
    guardarTodo().then(() => showToast('Logo eliminado.', 'info'));
}

// -------------------------------------------------------
// % MÍNIMO DE APROBACIÓN
// -------------------------------------------------------

async function actualizarMinAprobacionGlobal(val) {
    const num = parseInt(val) || 70;
    if (!db.configuracion) db.configuracion = {};
    db.configuracion.minAprobacion = num;
    try {
        await window.API.guardarConfig('minAprobacion', num);
    } catch (e) {
        await guardarTodo();
    }
    showToast(`Calificación mínima establecida en ${num}%`, 'info');
}

// -------------------------------------------------------
// MENSAJE DE BIENVENIDA
// -------------------------------------------------------

function actualizarMensajeBienvenida(val) {
    if (!db.configuracion) db.configuracion = {};
    db.configuracion.mensajeBienvenida = val;
    // Si hay un elemento hero en la misma página, actualizar en vivo
    const heroMsg = document.getElementById('hero-mensaje-bienvenida');
    if (heroMsg) heroMsg.textContent = val;
}

// -------------------------------------------------------
// MODO MANTENIMIENTO
// -------------------------------------------------------

function actualizarModoMantenimiento(activo) {
    if (!db.configuracion) db.configuracion = {};
    db.configuracion.modoMantenimiento = activo;
    const alertEl = document.getElementById('mantenimiento-alert');
    if (alertEl) alertEl.style.display = activo ? 'block' : 'none';
    guardarTodo().then(() => {
        showToast(
            activo ? 'Modo mantenimiento activado. Nuevos registros suspendidos.' : 'Modo mantenimiento desactivado.',
            activo ? 'warning' : 'success'
        );
    });
}

// -------------------------------------------------------
// GUARDAR TODA LA CONFIGURACIÓN (botón principal)
// -------------------------------------------------------

async function guardarTodasLasConfiguraciones() {
    const btn = document.getElementById('btn-guardar-config');
    await withLoading(btn, async () => {
        // Nombre
        const nombre = document.getElementById('cfg-nombre-universidad')?.value?.trim();
        if (nombre) await guardarNombreInstitucion(nombre);

        // Colores
        await guardarColoresPlataforma();

        // Mensaje bienvenida
        const msg = document.getElementById('cfg-mensaje-bienvenida')?.value;
        if (msg !== undefined) actualizarMensajeBienvenida(msg);

        // Guardar todo a la BD
        await guardarTodo();

        showToast('✅ Configuración guardada correctamente.', 'success');
    }, 'Guardando...');
}

// -------------------------------------------------------
// UTILIDADES DE COLOR
// -------------------------------------------------------

/**
 * Aclara u oscurece un color hex.
 * @param {string} hex  Color en formato #rrggbb
 * @param {number} pct  Porcentaje (+claro / -oscuro)
 */
function shadeColor(hex, pct) {
    let r = parseInt(hex.slice(1, 3), 16);
    let g = parseInt(hex.slice(3, 5), 16);
    let b = parseInt(hex.slice(5, 7), 16);
    r = Math.min(255, Math.max(0, r + Math.round(r * pct / 100)));
    g = Math.min(255, Math.max(0, g + Math.round(g * pct / 100)));
    b = Math.min(255, Math.max(0, b + Math.round(b * pct / 100)));
    return '#' + [r, g, b].map(v => v.toString(16).padStart(2, '0')).join('');
}

function hexToRgba(hex, alpha) {
    const r = parseInt(hex.slice(1, 3), 16);
    const g = parseInt(hex.slice(3, 5), 16);
    const b = parseInt(hex.slice(5, 7), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

// -------------------------------------------------------
// EXPORTS AL SCOPE GLOBAL
// -------------------------------------------------------
window.cargarConfiguracion         = cargarConfiguracion;
window.previsualizarNombreUniversidad = previsualizarNombreUniversidad;
window.cargarLogoInstitucion       = cargarLogoInstitucion;
window.eliminarLogo                = eliminarLogo;
window.actualizarFavicon           = actualizarFavicon;
window.actualizarMinAprobacionGlobal = actualizarMinAprobacionGlobal;
window.previewColores              = previewColores;
window.sincronizarColorHex         = sincronizarColorHex;
window.restablecerColores          = restablecerColores;
window.actualizarMensajeBienvenida = actualizarMensajeBienvenida;
window.actualizarModoMantenimiento = actualizarModoMantenimiento;
window.guardarTodasLasConfiguraciones = guardarTodasLasConfiguraciones;


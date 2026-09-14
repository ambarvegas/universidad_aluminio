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

    // Logo como favicon y en navbars
    const logoSaved = cfg.logo || localStorage.getItem('aluLogo') || '';
    if (logoSaved) {
        actualizarFavicon(logoSaved);
        mostrarPreviewLogo(logoSaved);
        aplicarLogoEnNavbars(logoSaved);
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

    // Rellenar controles SMTP & Alertas
    const elSmtpHost = document.getElementById('cfg-smtp-host');
    if (elSmtpHost) elSmtpHost.value = cfg.smtp_host || '';

    const elSmtpPort = document.getElementById('cfg-smtp-port');
    if (elSmtpPort) elSmtpPort.value = cfg.smtp_port || '587';

    const elSmtpUser = document.getElementById('cfg-smtp-user');
    if (elSmtpUser) elSmtpUser.value = cfg.smtp_user || '';

    const elSmtpPass = document.getElementById('cfg-smtp-pass');
    if (elSmtpPass) elSmtpPass.value = cfg.smtp_pass || '';

    const elSmtpSecure = document.getElementById('cfg-smtp-secure');
    if (elSmtpSecure) elSmtpSecure.value = cfg.smtp_secure || 'tls';

    const elEmailAdmin = document.getElementById('cfg-email-admin');
    if (elEmailAdmin) elEmailAdmin.value = cfg.email_admin || '';

    if (document.getElementById('tabla-respaldos-body')) {
        cargarListaRespaldos();
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
        showToast(`Error al guardar nombre: ${e.message}`, 'danger');
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
        await window.API.guardarConfigBatch({
            colorPrimario: primary,
            colorAcento: accent
        });
    } catch (e) {
        showToast(`Error al guardar colores: ${e.message}`, 'danger');
    }
}

// -------------------------------------------------------
// LOGO INSTITUCIONAL
// -------------------------------------------------------

/**
 * Actualiza el favicon del navegador con el logo institucional.
 */
function actualizarFavicon(src) {
    if (!src) return;
    const resolvedSrc = typeof resolverSrcImagen === 'function' ? resolverSrcImagen(src) : src;
    let link = document.querySelector("link[rel~='icon']");
    if (!link) {
        link = document.createElement('link');
        link.rel = 'icon';
        document.head.appendChild(link);
    }
    link.type = 'image/jpeg';
    link.href = resolvedSrc;
}

/**
 * Aplica el logo en los headers y barras de navegación del campus.
 */
function aplicarLogoEnNavbars(logoSrc) {
    if (!logoSrc) return;
    const resolved = typeof resolverSrcImagen === 'function' ? resolverSrcImagen(logoSrc) : logoSrc;
    document.querySelectorAll('.navbar-brand, .login-brand-header').forEach(brand => {
        let icon = brand.querySelector('.brand-icon-wrapper, .navbar-logo-img');
        if (icon) {
            if (icon.tagName === 'IMG') {
                icon.src = resolved;
            } else {
                const img = document.createElement('img');
                img.className = 'navbar-logo-img me-2 rounded';
                img.src = resolved;
                img.alt = 'Logo';
                img.style.maxHeight = '36px';
                img.style.maxWidth = '120px';
                img.style.objectFit = 'contain';
                icon.replaceWith(img);
            }
        }
    });
}

async function cargarLogoInstitucion(event) {
    const file = event.target.files[0];
    if (!file) return;

    const label = document.getElementById('btn-cargar-logo');

    await withLoading(label, async () => {
        let logoSrc = '';

        // 1. Intentar subir imagen física al servidor PHP
        if (typeof subirImagenServidor === 'function') {
            try {
                const prevUrl = (db.configuracion && db.configuracion.logo) || '';
                logoSrc = await subirImagenServidor(file, 'logo', 'institucional', prevUrl);
            } catch (uploadErr) {
                console.warn('Subida al servidor falló, usando compresión base64:', uploadErr);
            }
        }

        // 2. Si falló la subida física o no está disponible, comprimir a base64
        if (!logoSrc && typeof comprimirImagenBase64 === 'function') {
            logoSrc = await comprimirImagenBase64(file, 400, 0.85);
        }

        if (!logoSrc) {
            throw new Error('No se pudo procesar la imagen seleccionada.');
        }

        if (!db.configuracion) db.configuracion = {};
        db.configuracion.logo = logoSrc;
        localStorage.setItem('aluLogo', logoSrc);

        mostrarPreviewLogo(logoSrc);
        actualizarFavicon(logoSrc);
        aplicarLogoEnNavbars(logoSrc);

        try {
            await window.API.guardarConfig('logo', logoSrc);
            showToast('Logo institucional actualizado con éxito.', 'success');
        } catch (e) {
            showToast(`Error al guardar logo: ${e.message}`, 'danger');
        }
    }, 'Cargando logo...');
}

function mostrarPreviewLogo(src) {
    const container = document.getElementById('logo-preview-container');
    const img = document.getElementById('logo-preview-img');
    if (container && img) {
        if (src) {
            img.src = typeof resolverSrcImagen === 'function' ? resolverSrcImagen(src) : src;
            container.style.display = 'flex';
            container.style.alignItems = 'center';
        } else {
            container.style.display = 'none';
            img.src = '';
        }
    }
}

async function eliminarLogo() {
    if (!db.configuracion) db.configuracion = {};
    db.configuracion.logo = '';
    localStorage.removeItem('aluLogo');
    mostrarPreviewLogo('');
    const input = document.getElementById('input-logo');
    if (input) input.value = '';
    try {
        await window.API.guardarConfig('logo', '');
        showToast('Logo eliminado.', 'info');
    } catch (e) {
        showToast(`Error al eliminar logo: ${e.message}`, 'danger');
    }
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
        showToast(`Calificación mínima establecida en ${num}%`, 'info');
    } catch (e) {
        showToast(`Error al actualizar porcentaje: ${e.message}`, 'danger');
    }
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

async function actualizarModoMantenimiento(activo) {
    if (!db.configuracion) db.configuracion = {};
    db.configuracion.modoMantenimiento = activo;
    const alertEl = document.getElementById('mantenimiento-alert');
    if (alertEl) alertEl.style.display = activo ? 'block' : 'none';
    try {
        await window.API.guardarConfig('modoMantenimiento', activo);
        showToast(
            activo ? 'Modo mantenimiento activado. Nuevos registros suspendidos.' : 'Modo mantenimiento desactivado.',
            activo ? 'warning' : 'success'
        );
    } catch (e) {
        showToast(`Error al guardar modo mantenimiento: ${e.message}`, 'danger');
    }
}

// -------------------------------------------------------
// GUARDAR TODA LA CONFIGURACIÓN (botón principal)
// -------------------------------------------------------

async function guardarTodasLasConfiguraciones() {
    const btn = document.getElementById('btn-guardar-config');
    await withLoading(btn, async () => {
        const nombre  = document.getElementById('cfg-nombre-universidad')?.value?.trim();
        const primary = document.getElementById('cfg-color-primario')?.value || DEFAULT_COLORS.primary;
        const accent  = document.getElementById('cfg-color-acento')?.value  || DEFAULT_COLORS.accent;
        const msg     = document.getElementById('cfg-mensaje-bienvenida')?.value;

        // SMTP & Email
        const smtpHost   = document.getElementById('cfg-smtp-host')?.value?.trim();
        const smtpPort   = document.getElementById('cfg-smtp-port')?.value?.trim();
        const smtpUser   = document.getElementById('cfg-smtp-user')?.value?.trim();
        const smtpPass   = document.getElementById('cfg-smtp-pass')?.value;
        const smtpSecure = document.getElementById('cfg-smtp-secure')?.value;
        const emailAdmin = document.getElementById('cfg-email-admin')?.value?.trim();

        if (!db.configuracion) db.configuracion = {};
        if (nombre) db.configuracion.nombreInstitucion = nombre;
        db.configuracion.colorPrimario = primary;
        db.configuracion.colorAcento   = accent;
        if (msg !== undefined) db.configuracion.mensajeBienvenida = msg;
        if (smtpHost !== undefined) db.configuracion.smtp_host = smtpHost;
        if (smtpPort !== undefined) db.configuracion.smtp_port = smtpPort;
        if (smtpUser !== undefined) db.configuracion.smtp_user = smtpUser;
        if (smtpPass !== undefined) db.configuracion.smtp_pass = smtpPass;
        if (smtpSecure !== undefined) db.configuracion.smtp_secure = smtpSecure;
        if (emailAdmin !== undefined) db.configuracion.email_admin = emailAdmin;

        const batch = {
            colorPrimario: primary,
            colorAcento: accent
        };
        if (nombre) batch.nombreInstitucion = nombre;
        if (msg !== undefined) batch.mensajeBienvenida = msg;
        if (smtpHost !== undefined) batch.smtp_host = smtpHost;
        if (smtpPort !== undefined) batch.smtp_port = smtpPort;
        if (smtpUser !== undefined) batch.smtp_user = smtpUser;
        if (smtpPass !== undefined) batch.smtp_pass = smtpPass;
        if (smtpSecure !== undefined) batch.smtp_secure = smtpSecure;
        if (emailAdmin !== undefined) batch.email_admin = emailAdmin;

        await window.API.guardarConfigBatch(batch);

        if (nombre) {
            aplicarNombreInstitucion(nombre);
            localStorage.setItem('aluNombreInstitucion', nombre);
        }
        aplicarColoresPlataforma(primary, accent);
        if (msg !== undefined) actualizarMensajeBienvenida(msg);

        showToast('✅ Configuración guardada correctamente.', 'success');
    }, 'Guardando...');
}

// -------------------------------------------------------
// SERVIDOR DE CORREO (SMTP) & PRUEBAS
// -------------------------------------------------------

async function probarConfiguracionEmail() {
    const inputDest = document.getElementById('cfg-test-email-dest');
    const emailAdminInput = document.getElementById('cfg-email-admin');
    const resultDiv = document.getElementById('test-email-result');
    const btn = document.getElementById('btn-probar-email');

    const dest = inputDest?.value?.trim() || emailAdminInput?.value?.trim();
    if (!dest) {
        showToast('Ingresa un correo electrónico de destino para la prueba.', 'warning');
        if (inputDest) inputDest.focus();
        return;
    }

    if (resultDiv) {
        resultDiv.style.display = 'block';
        resultDiv.className = 'alert alert-info py-2 px-3 small mt-2 mb-0';
        resultDiv.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Conectando con el servidor SMTP y enviando correo de diagnóstico...';
    }

    await withLoading(btn, async () => {
        try {
            const smtpHost = document.getElementById('cfg-smtp-host')?.value?.trim() || '';
            const smtpPort = document.getElementById('cfg-smtp-port')?.value?.trim() || '587';
            const smtpUser = document.getElementById('cfg-smtp-user')?.value?.trim() || '';
            const smtpPass = document.getElementById('cfg-smtp-pass')?.value || '';
            const smtpSecure = document.getElementById('cfg-smtp-secure')?.value || 'tls';

            if (smtpHost || smtpUser) {
                await window.API.guardarConfigBatch({
                    smtp_host: smtpHost,
                    smtp_port: smtpPort,
                    smtp_user: smtpUser,
                    smtp_pass: smtpPass,
                    smtp_secure: smtpSecure,
                    email_admin: dest
                });
            }

            const res = await window.API.probarEmail(dest);
            if (resultDiv) {
                resultDiv.className = 'alert alert-success py-2 px-3 small mt-2 mb-0';
                resultDiv.innerHTML = `<i class="bi bi-check-circle-fill me-1"></i> ${res.message || 'Correo de prueba enviado satisfactoriamente.'}`;
            }
            showToast('✅ Correo de prueba enviado con éxito.', 'success');
        } catch (e) {
            if (resultDiv) {
                resultDiv.className = 'alert alert-danger py-2 px-3 small mt-2 mb-0';
                resultDiv.innerHTML = `<i class="bi bi-x-circle-fill me-1"></i> ${e.message || 'Fallo en la entrega del correo.'}`;
            }
            showToast(`Error al probar correo: ${e.message}`, 'danger');
        }
    }, 'Enviando...');
}

// -------------------------------------------------------
// GESTIÓN DE RESPALDOS AUTOMATIZADOS
// -------------------------------------------------------

async function cargarListaRespaldos() {
    const tbody = document.getElementById('tabla-respaldos-body');
    const badge = document.getElementById('badge-total-respaldos');
    if (!tbody) return;

    try {
        const data = await window.API.obtenerListaRespaldos();
        const lista = data.respaldos || [];

        if (badge) {
            badge.textContent = `${lista.length} copia${lista.length === 1 ? '' : 's'}`;
        }

        if (lista.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="4" class="text-center py-3 text-muted">
                        <i class="bi bi-info-circle me-1"></i>No hay respaldos generados aún. Haz clic en "Generar Respaldo Ahora".
                    </td>
                </tr>`;
            return;
        }

        tbody.innerHTML = lista.map(b => `
            <tr>
                <td class="fw-semibold text-primary">
                    <i class="bi bi-file-earmark-zip-fill text-warning me-1"></i>${b.archivo}
                </td>
                <td class="text-muted">${b.fecha}</td>
                <td><span class="badge bg-light text-dark border">${b.tamano}</span></td>
                <td><span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25">${b.tipo}</span></td>
            </tr>
        `).join('');
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="4" class="text-center py-3 text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Error al consultar respaldos: ${e.message}</td></tr>`;
    }
}

async function ejecutarBackupManual() {
    const btn = document.getElementById('btn-generar-backup');
    await withLoading(btn, async () => {
        try {
            const res = await window.API.ejecutarRespaldoServidor();
            showToast(`✅ Respaldo generado: ${res.archivo} (${res.tamano}) en ${res.duracion_segundos}s`, 'success');
            await cargarListaRespaldos();
        } catch (e) {
            showToast(`Error al generar respaldo: ${e.message}`, 'danger');
        }
    }, 'Generando...');
}

// -------------------------------------------------------
// ESTADO DE SALUD DEL SISTEMA & DIAGNÓSTICO
// -------------------------------------------------------

function abrirModalDiagnostico() {
    const modalEl = document.getElementById('modalDiagnostico');
    if (!modalEl) return;
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
    cargarEstadoSistemaModal();
}

async function cargarEstadoSistemaModal() {
    const body = document.getElementById('modal-diagnostico-body');
    if (!body) return;

    body.innerHTML = `
        <div class="text-center py-5 text-muted">
            <div class="spinner-border text-primary mb-2"></div>
            <p class="mb-0">Consultando métricas de salud del servidor en tiempo real...</p>
        </div>`;

    try {
        const h = await window.API.obtenerEstadoSistema();
        const db = h.database || {};
        const storage = h.storage || {};
        const php = h.php || {};
        const mailer = h.mailer || {};
        const statusBadge = h.status === 'healthy' 
            ? '<span class="badge bg-success px-3 py-2 fs-6"><i class="bi bi-check-circle-fill me-1"></i>Sistema Saludable & Operativo</span>'
            : (h.status === 'warning'
                ? '<span class="badge bg-warning text-dark px-3 py-2 fs-6"><i class="bi bi-exclamation-triangle-fill me-1"></i>Advertencias Detectadas</span>'
                : '<span class="badge bg-danger px-3 py-2 fs-6"><i class="bi bi-x-circle-fill me-1"></i>Atención Crítica Requerida</span>');

        let warningsHtml = '';
        if (h.warnings && h.warnings.length > 0) {
            warningsHtml = `
                <div class="alert alert-warning mb-3">
                    <h6 class="fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>Recomendaciones / Alertas:</h6>
                    <ul class="mb-0 ps-3 small">
                        ${h.warnings.map(w => `<li>${w}</li>`).join('')}
                    </ul>
                </div>`;
        }

        const extBadges = Object.entries(php.extensiones || {}).map(([ext, active]) => `
            <span class="badge ${active ? 'bg-success bg-opacity-10 text-success border border-success border-opacity-25' : 'bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25'} me-1 mb-1">
                ${active ? '✓' : '✗'} ${ext}
            </span>
        `).join('');

        body.innerHTML = `
            <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom">
                <div>
                    <h5 class="fw-bold text-primary mb-1">Diagnóstico Operativo en Tiempo Real</h5>
                    <p class="text-muted small mb-0">Servidor: <strong>${db.host || 'N/D'}</strong> &bull; Verificado: ${new Date(h.timestamp).toLocaleTimeString()}</p>
                </div>
                <div>${statusBadge}</div>
            </div>

            ${warningsHtml}

            <div class="row g-3">
                <!-- MySQL Database -->
                <div class="col-md-6">
                    <div class="p-3 bg-light rounded-3 border h-100">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="bi bi-database-fill text-primary fs-5"></i>
                            <h6 class="fw-bold mb-0 text-dark">Base de Datos MySQL</h6>
                        </div>
                        <ul class="list-unstyled small mb-0 text-muted">
                            <li class="mb-1"><strong>Base de Datos:</strong> <code class="text-primary">${db.name}</code></li>
                            <li class="mb-1"><strong>Versión Motor:</strong> ${db.server_version}</li>
                            <li class="mb-1"><strong>Latencia Ping:</strong> <span class="badge ${db.latencia_ms < 200 ? 'bg-success' : 'bg-warning'}">${db.latencia_ms} ms</span></li>
                            <li class="mb-1"><strong>Tablas / Registros:</strong> ${db.tablas_total} tablas (~${(db.filas_estimadas || 0).toLocaleString()} filas)</li>
                            <li><strong>Tamaño en Disco:</strong> <span class="fw-semibold text-dark">${db.tamano_formato}</span></li>
                        </ul>
                    </div>
                </div>

                <!-- Storage & Disk -->
                <div class="col-md-6">
                    <div class="p-3 bg-light rounded-3 border h-100">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="bi bi-hdd-network-fill text-info fs-5"></i>
                            <h6 class="fw-bold mb-0 text-dark">Almacenamiento & Respaldos</h6>
                        </div>
                        <ul class="list-unstyled small mb-0 text-muted">
                            <li class="mb-1"><strong>Espacio Libre Servidor:</strong> ${storage.disk_free_formato} / ${storage.disk_total_formato}</li>
                            <li class="mb-1"><strong>Archivos en /uploads/:</strong> ${storage.uploads?.archivos_total || 0} (${storage.uploads?.tamano_formato || '0 B'})</li>
                            <li class="mb-1"><strong>Respaldos Conservados:</strong> ${storage.backups?.archivos_total || 0} (${storage.backups?.tamano_formato || '0 B'})</li>
                            <li><strong>Último Respaldo:</strong> <span class="fw-semibold text-dark">${storage.backups?.ultimo_backup || 'Sin respaldos'}</span></li>
                        </ul>
                    </div>
                </div>

                <!-- PHP Runtime -->
                <div class="col-md-6">
                    <div class="p-3 bg-light rounded-3 border h-100">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="bi bi-code-slash text-purple fs-5"></i>
                            <h6 class="fw-bold mb-0 text-dark">Entorno PHP</h6>
                        </div>
                        <ul class="list-unstyled small mb-2 text-muted">
                            <li class="mb-1"><strong>Versión PHP:</strong> ${php.version} (${php.sapi})</li>
                            <li class="mb-1"><strong>Límites:</strong> Mem: ${php.memory_limit} &bull; Max Exec: ${php.max_execution_time}s &bull; Max Upload: ${php.upload_max_filesize}</li>
                        </ul>
                        <div class="d-flex flex-wrap">${extBadges}</div>
                    </div>
                </div>

                <!-- Mailer Status -->
                <div class="col-md-6">
                    <div class="p-3 bg-light rounded-3 border h-100">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="bi bi-envelope-check-fill text-success fs-5"></i>
                            <h6 class="fw-bold mb-0 text-dark">Servicio de Correo</h6>
                        </div>
                        <ul class="list-unstyled small mb-0 text-muted">
                            <li class="mb-1"><strong>Servidor SMTP:</strong> ${mailer.smtp_host}</li>
                            <li class="mb-1"><strong>Puerto & Cifrado:</strong> Puerto ${mailer.smtp_port} (${mailer.smtp_secure})</li>
                            <li class="mb-1"><strong>Correo de Alertas:</strong> <code class="text-dark">${mailer.email_admin}</code></li>
                            <li><strong>Estado:</strong> <span class="badge ${mailer.smtp_configurado ? 'bg-success' : 'bg-warning'}">${mailer.smtp_configurado ? 'SMTP Configurado' : 'Fallback mail() Activo'}</span></li>
                        </ul>
                    </div>
                </div>
            </div>
        `;
    } catch (e) {
        body.innerHTML = `
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>Error al consultar el diagnóstico de salud: ${e.message}
            </div>`;
    }
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
window.probarConfiguracionEmail    = probarConfiguracionEmail;
window.cargarListaRespaldos        = cargarListaRespaldos;
window.ejecutarBackupManual        = ejecutarBackupManual;
window.abrirModalDiagnostico       = abrirModalDiagnostico;
window.cargarEstadoSistemaModal    = cargarEstadoSistemaModal;



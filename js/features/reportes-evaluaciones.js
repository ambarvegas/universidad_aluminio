/**
 * js/features/reportes-evaluaciones.js
 * Reporte detallado de evaluaciones del Panel Administrativo: filtros, KPIs, vistas agrupada
 * y plana con paginación en el servidor, y habilitación de reintentos.
 */

// ============================================================
// REPORTE DETALLADO DE EVALUACIONES (RELACIONAL)
// ============================================================

// El reporte se calcula, filtra y pagina en el servidor (lib/reportes.php). Aquí solo se
// guarda la página visible y los totales de la última respuesta.
let _evaluacionesFiltradas = [];   // calificaciones de la página visible
let _usuariosAgrupados = [];       // colaboradores de la página visible (vista agrupada)
let _reporteEval = null;           // última respuesta: totales, KPIs y paginación
let _modoVistaEval = 'agrupada';   // 'agrupada' o 'plana'
let _evalCurrentPage = 1;
let _evalPerPage = 15;
let _evalSolicitud = 0;            // descarta respuestas que llegan fuera de orden

function _filtrosEvaluaciones() {
    return {
        search:  (document.getElementById('filtro-eval-search')?.value || '').trim(),
        usuario: document.getElementById('filtro-eval-usuario')?.value || '',
        curso:   document.getElementById('filtro-eval-curso')?.value || '',
        estado:  document.getElementById('filtro-eval-estado')?.value || '',
        orden:   document.getElementById('filtro-eval-orden')?.value || 'nombre_asc',
        modo:    _modoVistaEval,
    };
}

/**
 * Pide al servidor la página actual con los filtros de la pantalla y la dibuja.
 */
async function cargarReporteEvaluaciones() {
    const solicitud = ++_evalSolicitud;
    try {
        const r = await window.API.cargarReporteEvaluaciones({
            ..._filtrosEvaluaciones(), page: _evalCurrentPage, limit: _evalPerPage,
        });
        if (solicitud !== _evalSolicitud) return;
        _reporteEval = r;
        _evalCurrentPage = r.pagina || 1;
        _usuariosAgrupados = Array.isArray(r.grupos) ? r.grupos : [];
        _evaluacionesFiltradas = r.modo === 'agrupada'
            ? _usuariosAgrupados.flatMap(u => u.evaluaciones)
            : (Array.isArray(r.evaluaciones) ? r.evaluaciones : []);
        renderVistaEvaluaciones();
        actualizarKPIsEvaluaciones();
    } catch (err) {
        if (solicitud !== _evalSolicitud) return;
        console.error('Error al cargar el reporte de evaluaciones:', err);
        if (typeof showToast === 'function') showToast('No se pudo cargar el reporte de evaluaciones: ' + err.message, 'danger');
    }
}

/**
 * Todas las calificaciones que cumplen los filtros (sin paginar), para exportar o imprimir.
 * Devuelve { evaluaciones, grupos } o null si no hay datos.
 */
async function _cargarEvaluacionesParaExportar() {
    try {
        const r = await window.API.cargarReporteEvaluaciones({ ..._filtrosEvaluaciones(), todo: true });
        const evaluaciones = Array.isArray(r.evaluaciones) ? r.evaluaciones : [];
        if (evaluaciones.length === 0) {
            alert('No hay evaluaciones filtradas para exportar.');
            return null;
        }
        return { evaluaciones, grupos: Array.isArray(r.grupos) ? r.grupos : [] };
    } catch (err) {
        if (typeof showToast === 'function') showToast('No se pudo obtener el reporte: ' + err.message, 'danger');
        return null;
    }
}

/**
 * Helper para formatear insignias y colores de calificación
 */
function _obtenerBadgeCalificacion(calificacion) {
    const califFormatted = (calificacion % 1 === 0 ? calificacion : calificacion.toFixed(1)) + ' pts';
    if (calificacion >= 100) {
        return {
            badge: `<span class="badge badge-score-perfect px-2 py-1"><i class="bi bi-star-fill text-warning me-1"></i>100 pts</span>`,
            barColor: 'bg-success',
            badgeClass: 'badge-score-perfect'
        };
    } else if (calificacion >= 90) {
        return {
            badge: `<span class="badge badge-score-high px-2 py-1">${califFormatted}</span>`,
            barColor: 'bg-success',
            badgeClass: 'badge-score-high'
        };
    } else if (calificacion >= 70) {
        return {
            badge: `<span class="badge badge-score-pass px-2 py-1">${califFormatted}</span>`,
            barColor: 'bg-primary',
            badgeClass: 'badge-score-pass'
        };
    } else {
        return {
            badge: `<span class="badge badge-score-fail px-2 py-1">${califFormatted}</span>`,
            barColor: 'bg-danger',
            badgeClass: 'badge-score-fail'
        };
    }
}

// ============================================================
// REINTENTOS HABILITADOS (evaluaciones reprobadas)
// ============================================================

function _formatearFechaCorta(fecha) {
    if (!fecha) return '—';
    const f = new Date(String(fecha).replace(' ', 'T'));
    return isNaN(f.getTime()) ? String(fecha) : f.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function _htmlEstadoEvaluacion(e, extraClass = '') {
    if (e.aprobado) {
        return `<span class="badge bg-success text-white ${extraClass} px-2 py-1"><i class="bi bi-check-circle-fill me-1"></i>Aprobado</span>`;
    }
    let html = `<span class="badge bg-danger text-white ${extraClass} px-2 py-1"><i class="bi bi-x-circle-fill me-1"></i>Reprobado</span>`;
    if (e.bloqueado) {
        html += `<div class="mt-1"><span class="badge bg-dark text-white px-2 py-1"><i class="bi bi-lock-fill me-1"></i>Sin intentos</span></div>`;
    } else if (e.reintentoPendiente) {
        html += `<div class="mt-1"><span class="badge bg-info text-dark px-2 py-1"><i class="bi bi-unlock-fill me-1"></i>Reintento habilitado</span></div>`;
    }
    return html;
}

function _htmlIntentosEvaluacion(e) {
    const limite = e.maxIntentosEfectivo > 0 ? ` / ${e.maxIntentosEfectivo}` : '';
    let html = `<span class="badge bg-light text-dark border px-2 py-1" title="Intentos realizados${limite ? ' / límite vigente' : ''}">
        <i class="bi bi-arrow-repeat me-1 text-muted"></i>${e.intentos}${limite}
    </span>`;
    if (e.vecesReprobado > 0) {
        html += `<div class="small text-danger fw-semibold mt-1" style="font-size: 0.72rem;">${e.vecesReprobado} reprobado${e.vecesReprobado !== 1 ? 's' : ''}</div>`;
    }
    if (e.habilitaciones.length > 0) {
        const detalle = e.habilitaciones.map(h =>
            `${_formatearFechaCorta(h.fecha)}: +${h.intentosOtorgados} (tras ${h.intentosPrevios} intento${h.intentosPrevios !== 1 ? 's' : ''})${h.motivo ? ' — ' + h.motivo : ''}${h.activo ? '' : ' [restablecido]'}`
        ).join('\n');
        html += `<div class="mt-1"><span class="badge bg-info bg-opacity-25 text-dark border border-info px-2 py-0" style="font-size: 0.7rem; cursor: help;" title="${escapeHtml(detalle)}">
            <i class="bi bi-unlock me-1"></i>Habilitado ×${e.habilitaciones.length}
        </span></div>`;
    }
    return html;
}

function _htmlAccionesEvaluacion(e) {
    if (!e.puedeHabilitar) return '';
    const args = [e.usuarioId, e.cursoId, String(e.moduloNum)].map(v => escapeHtml(JSON.stringify(String(v)))).join(', ');
    return `<button type="button" class="btn btn-sm ${e.bloqueado ? 'btn-warning' : 'btn-outline-warning'} text-nowrap"
                onclick="event.stopPropagation(); abrirModalHabilitarReintento(${args})"
                title="Permitir volver a cursar y presentar la evaluación (conserva el historial de reprobaciones)">
        <i class="bi bi-unlock-fill me-1"></i>Habilitar
    </button>`;
}

/**
 * Modal para habilitar un nuevo intento sobre un módulo reprobado.
 */
function abrirModalHabilitarReintento(usuarioId, cursoId, moduloNum) {
    const e = _evaluacionesFiltradas.find(x => x.usuarioId === usuarioId && x.cursoId === cursoId && String(x.moduloNum) === String(moduloNum));
    if (!e) return;

    document.getElementById('modal-habilitar-reintento')?.remove();

    const historialHtml = e.habilitaciones.length
        ? `<ul class="small text-muted mb-0 ps-3">${e.habilitaciones.map(h => `
            <li>${escapeHtml(_formatearFechaCorta(h.fecha))}: +${h.intentosOtorgados} intento(s) tras ${h.intentosPrevios} realizado(s)${h.motivo ? ' — ' + escapeHtml(h.motivo) : ''}${h.activo ? '' : ' <em>(restablecido)</em>'}</li>`).join('')}
           </ul>`
        : '<div class="small text-muted">Sin habilitaciones previas.</div>';

    const limiteTxt = e.maxIntentosBase > 0
        ? `${e.intentos} de ${e.maxIntentosEfectivo} intento(s) usados (límite del módulo: ${e.maxIntentosBase}${e.intentosExtra ? ` + ${e.intentosExtra} habilitado(s)` : ''})`
        : `${e.intentos} intento(s) realizados (el módulo permite intentos ilimitados)`;

    const modalDiv = document.createElement('div');
    modalDiv.id = 'modal-habilitar-reintento';
    modalDiv.className = 'modal fade';
    modalDiv.tabIndex = -1;
    modalDiv.innerHTML = `
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem; overflow: hidden;">
                <div class="modal-header bg-warning text-dark border-0 py-3">
                    <h5 class="modal-title fw-bold"><i class="bi bi-unlock-fill me-2"></i>Habilitar reintento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body px-4">
                    <div class="mb-3">
                        <div class="fw-bold text-dark">${escapeHtml(e.usuarioNombre)} <span class="text-muted fw-normal small">(CI: ${escapeHtml(e.usuarioId)})</span></div>
                        <div class="small text-primary">${escapeHtml(e.cursoTitulo)}</div>
                        <div class="small fw-semibold">${escapeHtml(e.moduloTitulo)}</div>
                    </div>
                    <div class="alert alert-light border small mb-3">
                        <div><i class="bi bi-x-circle text-danger me-1"></i>Última calificación: <strong>${Math.round(e.calificacion)} pts</strong> — ${e.vecesReprobado} reprobación(es)</div>
                        <div><i class="bi bi-arrow-repeat me-1"></i>${limiteTxt}</div>
                    </div>
                    ${e.maxIntentosBase > 0 ? `
                    <div class="mb-3">
                        <label class="form-label small fw-semibold" for="hab-intentos">Intentos adicionales a otorgar</label>
                        <input type="number" class="form-control form-control-sm" id="hab-intentos" min="1" max="10" value="1" style="max-width: 120px;">
                    </div>` : ''}
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="hab-repasar" checked>
                        <label class="form-check-label small" for="hab-repasar">
                            Exigir volver a cursar las lecciones del módulo antes de la evaluación
                        </label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold" for="hab-motivo">Motivo / observación (opcional)</label>
                        <textarea class="form-control form-control-sm" id="hab-motivo" rows="2" maxlength="500" placeholder="Ej.: Solicitud del supervisor tras refuerzo presencial"></textarea>
                    </div>
                    <div class="small fw-semibold text-secondary mb-1">Historial de habilitaciones</div>
                    ${historialHtml}
                    <div class="small text-muted mt-3"><i class="bi bi-info-circle me-1"></i>Las reprobaciones e intentos previos se conservan en los reportes.</div>
                </div>
                <div class="modal-footer bg-light border-0 py-3 px-4">
                    <button type="button" class="btn btn-secondary px-4 fw-semibold" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-warning px-4 fw-semibold shadow-sm" id="hab-btn-confirmar">
                        <i class="bi bi-unlock-fill me-1"></i>Habilitar
                    </button>
                </div>
            </div>
        </div>`;
    document.body.appendChild(modalDiv);

    const bsModal = new bootstrap.Modal(modalDiv);
    modalDiv.addEventListener('hidden.bs.modal', () => modalDiv.remove());

    const btn = modalDiv.querySelector('#hab-btn-confirmar');
    btn.addEventListener('click', async () => {
        const payload = {
            usuario_id: e.usuarioId,
            curso_id: e.cursoId,
            modulo_idx: parseInt(e.moduloNum, 10),
            intentos: parseInt(modalDiv.querySelector('#hab-intentos')?.value, 10) || 1,
            repasar_lecciones: modalDiv.querySelector('#hab-repasar').checked,
            motivo: modalDiv.querySelector('#hab-motivo').value.trim()
        };
        try {
            await window.withLoading(btn, () => window.API.habilitarReintento(payload), 'Habilitando...');
            bsModal.hide();
            showToast(`Reintento habilitado para ${e.usuarioNombre}. El historial de reprobaciones se conserva.`, 'success');
            window.App?.invalidarProgreso(e.usuarioId);
            await cargarReporteEvaluaciones();
        } catch (err) {
            showToast('No se pudo habilitar el reintento: ' + err.message, 'danger');
        }
    });

    bsModal.show();
}

/**
 * Inicializa selectores dinámicos de colaboradores y cursos para el filtro
 */
function inicializarFiltrosEvaluaciones() {
    const selU = document.getElementById('filtro-eval-usuario');
    if (selU) {
        const prevU = selU.value;
        const usersList = (usuarios || []).filter(u => u.rol !== 'admin');
        selU.innerHTML = `<option value="">— Colaborador —</option>` +
            usersList.map(u => `<option value="${escapeHtml(u.id)}" ${u.id === prevU ? 'selected' : ''}>${escapeHtml(u.nombre)} (${escapeHtml(u.id)})</option>`).join('');
    }

    const selC = document.getElementById('filtro-eval-curso');
    if (selC) {
        const prevC = selC.value;
        selC.innerHTML = `<option value="">— Curso —</option>` +
            (cursos || []).map(c => `<option value="${escapeHtml(c.id)}" ${c.id === prevC ? 'selected' : ''}>${escapeHtml(c.titulo)}</option>`).join('');
    }
}

/**
 * Aplica los filtros de la pantalla: vuelve a la primera página y consulta al servidor.
 */
function filtrarTablaEvaluaciones() {
    _evalCurrentPage = 1;
    return cargarReporteEvaluaciones();
}

// El buscador de texto consulta al servidor cuando se deja de escribir
const buscarEvaluacionesDebounced = window.debounce(() => filtrarTablaEvaluaciones(), 300);

/**
 * Actualiza las tarjetas resumen de métricas (KPIs) con los totales del servidor
 */
function actualizarKPIsEvaluaciones() {
    const k = _reporteEval?.kpis || { total: 0, promedio: 0, tasaAprob: 0, perfectas: 0 };
    const total = k.total;

    const elTotal = document.getElementById('kpi-eval-total');
    const elProm = document.getElementById('kpi-eval-promedio');
    const elTasa = document.getElementById('kpi-eval-tasa-aprob');
    const elPerf = document.getElementById('kpi-eval-perfectas');
    const elBadge = document.getElementById('evaluaciones-count-badge');

    if (elTotal) elTotal.textContent = total.toLocaleString();
    if (elProm)  elProm.textContent  = `${Number(k.promedio).toFixed(1)} pts`;
    if (elTasa)  elTasa.textContent  = `${k.tasaAprob}%`;
    if (elPerf)  elPerf.textContent  = k.perfectas.toLocaleString();

    if (elBadge) {
        if (_modoVistaEval === 'agrupada') {
            const numU = _reporteEval?.totalUsuarios || 0;
            elBadge.textContent = `${numU} colaborador${numU !== 1 ? 'es' : ''} (${total} calificaciones)`;
        } else {
            elBadge.textContent = `${total} calificación${total !== 1 ? 'es' : ''}`;
        }
        elBadge.className = 'badge bg-primary text-white fw-semibold';
    }
}

/**
 * Despacha el renderizado según el modo activo
 */
function renderVistaEvaluaciones() {
    if (_modoVistaEval === 'agrupada') {
        renderEvaluacionesAgrupadasPaginada();
    } else {
        renderTablaEvaluacionesPaginada();
    }
}

/**
 * Alterna entre la vista agrupada por colaborador y la vista de tabla plana
 */
function cambiarModoVistaEvaluaciones(modo) {
    _modoVistaEval = modo;
    const btnAgrupada = document.getElementById('btn-eval-vista-agrupada');
    const btnPlana    = document.getElementById('btn-eval-vista-plana');
    const contAgrupada = document.getElementById('contenedor-evaluaciones-agrupadas');
    const contPlana    = document.getElementById('contenedor-tabla-evaluaciones');
    const collapseControls = document.getElementById('eval-collapse-controls');

    if (modo === 'agrupada') {
        if (btnAgrupada) btnAgrupada.className = 'btn btn-primary fw-semibold';
        if (btnPlana)    btnPlana.className    = 'btn btn-outline-primary fw-semibold';
        if (contAgrupada) contAgrupada.style.display = 'block';
        if (contPlana)    contPlana.style.display    = 'none';
        if (collapseControls) collapseControls.style.display = 'inline-flex';
    } else {
        if (btnAgrupada) btnAgrupada.className = 'btn btn-outline-primary fw-semibold';
        if (btnPlana)    btnPlana.className    = 'btn btn-primary fw-semibold';
        if (contAgrupada) contAgrupada.style.display = 'none';
        if (contPlana)    contPlana.style.display    = 'block';
        if (collapseControls) collapseControls.style.display = 'none';
    }

    _evalCurrentPage = 1;
    return cargarReporteEvaluaciones();
}

/**
 * Expande o colapsa todas las tarjetas de colaboradores
 */
function expandirTodosColaboradores(expandir) {
    const collapses = document.querySelectorAll('.user-eval-collapse');
    const headers   = document.querySelectorAll('.user-eval-header');
    collapses.forEach(c => {
        if (expandir) c.classList.add('show');
        else c.classList.remove('show');
    });
    headers.forEach(h => {
        if (expandir) {
            h.classList.remove('collapsed');
            h.setAttribute('aria-expanded', 'true');
        } else {
            h.classList.add('collapsed');
            h.setAttribute('aria-expanded', 'false');
        }
    });
}

/**
 * Alterna el acordeón de un colaborador específico
 */
function toggleColaboradorAcordeon(id, headerEl) {
    const collapseEl = document.getElementById(id);
    if (!collapseEl) return;
    const isShown = collapseEl.classList.contains('show');
    if (isShown) {
        collapseEl.classList.remove('show');
        if (headerEl) headerEl.classList.add('collapsed');
    } else {
        collapseEl.classList.add('show');
        if (headerEl) headerEl.classList.remove('collapsed');
    }
}

/**
 * Dibuja las tarjetas de calificaciones AGRUPADAS por colaborador con paginación
 */
function renderEvaluacionesAgrupadasPaginada() {
    const contenedor = document.getElementById('contenedor-evaluaciones-agrupadas');
    if (!contenedor) return;

    if (_usuariosAgrupados.length === 0) {
        contenedor.innerHTML = `
            <div class="text-center py-5 text-muted bg-white rounded-3 border">
                <i class="bi bi-person-x fs-2 d-block mb-2 text-muted opacity-50"></i>
                <h6 class="fw-bold text-secondary mb-1">No se encontraron evaluaciones</h6>
                <p class="small text-muted mb-0">No hay módulos calificados para los filtros aplicados.</p>
            </div>`;
        actualizarControlesPaginacion(0, 0, 0);
        return;
    }

    const usuariosPagina = _usuariosAgrupados;

    let html = '';
    usuariosPagina.forEach((u) => {
        // Id de elemento seguro aunque la cédula traiga caracteres especiales
        const domKey = String(u.usuarioId).replace(/[^\w-]/g, '_');
        const domId = `user-eval-collapse-${domKey}`;
        const iniciales = (u.usuarioNombre || 'U').split(' ').filter(Boolean).map(p => p[0]).slice(0, 2).join('').toUpperCase();
        const badgeInfo = _obtenerBadgeCalificacion(u.promedio);

        let filasModulosHtml = '';
        u.evaluaciones.forEach((e, mIdx) => {
            const mBadgeInfo = _obtenerBadgeCalificacion(e.calificacion);

            const estadoBadgeHtml = _htmlEstadoEvaluacion(e);

            const origenBadgeHtml = e.marcadoManual
                ? `<span class="badge bg-warning text-dark border px-2 py-1"><i class="bi bi-pencil-square me-1"></i>Manual (Rectoría)</span>`
                : `<span class="badge bg-light text-dark border px-2 py-1"><i class="bi bi-laptop me-1 text-primary"></i>Examen Online</span>`;

            let fechaFormat = '—';
            if (e.fecha) {
                try {
                    const fObj = new Date(e.fecha);
                    if (!isNaN(fObj.getTime())) {
                        fechaFormat = fObj.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
                    } else {
                        fechaFormat = escapeHtml(e.fecha);
                    }
                } catch (_) { fechaFormat = escapeHtml(e.fecha); }
            } else if (e.marcadoManual) {
                fechaFormat = '<span class="text-success small fw-semibold"><i class="bi bi-patch-check-fill me-1"></i>Acreditado</span>';
            }

            filasModulosHtml += `
            <tr>
                <td class="text-muted small text-center ps-3">${mIdx + 1}</td>
                <td>
                    <div class="fw-semibold text-primary mb-0" style="max-width: 280px; white-space: normal;">
                        <i class="bi bi-book me-1 opacity-75"></i>${escapeHtml(e.cursoTitulo)}
                    </div>
                    <div class="text-muted small" style="font-size: 0.72rem;">Código: ${escapeHtml(e.cursoId)}</div>
                </td>
                <td>
                    <div class="small fw-semibold text-dark">${escapeHtml(e.moduloTitulo)}</div>
                </td>
                <td>
                    <div class="d-flex flex-column" style="width: 140px;">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            ${mBadgeInfo.badge}
                            <span class="text-muted small fw-semibold" style="font-size: 0.72rem;">${Math.round(e.calificacion)}%</span>
                        </div>
                        <div class="progress" style="height: 5px; border-radius: 4px; background-color: #e2e8f0;">
                            <div class="progress-bar ${mBadgeInfo.barColor}" style="width: ${Math.min(e.calificacion, 100)}%;"></div>
                        </div>
                    </div>
                </td>
                <td>${estadoBadgeHtml}</td>
                <td class="text-center">
                    ${_htmlIntentosEvaluacion(e)}
                </td>
                <td>${origenBadgeHtml}</td>
                <td class="small text-muted" style="white-space: nowrap;">
                    <i class="bi bi-calendar3 me-1 opacity-75"></i>${fechaFormat}
                </td>
                <td class="text-end pe-3">${_htmlAccionesEvaluacion(e)}</td>
            </tr>`;
        });

        html += `
        <div class="card user-eval-card shadow-sm mb-3">
            <div class="card-header user-eval-header p-3 d-flex flex-wrap align-items-center justify-content-between"
                 onclick="toggleColaboradorAcordeon('${domId}', this)"
                 id="user-eval-header-${domKey}"
                 role="button"
                 title="Clic para expandir u ocultar los módulos de este colaborador">
                <div class="d-flex align-items-center gap-3">
                    <div class="user-eval-avatar">
                        ${escapeHtml(iniciales)}
                    </div>
                    <div>
                        <div class="fw-bold text-dark fs-6 d-flex align-items-center gap-2 mb-1">
                            <span>${escapeHtml(u.usuarioNombre)}</span>
                            <span class="badge bg-light text-secondary border px-2 py-0" style="font-size: 0.725rem;">${escapeHtml(u.rolNombre)}</span>
                        </div>
                        <div class="text-muted small d-flex flex-wrap align-items-center gap-2">
                            <span><i class="bi bi-person-vcard me-1"></i>CI: <strong>${escapeHtml(u.usuarioId)}</strong></span>
                            <span>•</span>
                            <span class="text-primary fw-semibold"><i class="bi bi-journal-check me-1"></i>${u.totalModulos} ${u.totalModulos === 1 ? 'módulo cursado' : 'módulos cursados'}</span>
                        </div>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-3 mt-2 mt-sm-0">
                    <div class="text-center text-sm-end me-1">
                        <div class="small text-muted" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px;">Promedio General</div>
                        <span class="badge ${badgeInfo.badgeClass} px-2 py-1 fs-6">${u.promedio} pts</span>
                    </div>

                    <div class="d-none d-md-flex align-items-center gap-2">
                        <span class="badge badge-soft-success px-2 py-1">
                            <i class="bi bi-check-circle-fill me-1"></i>${u.aprobados} Aprobados
                        </span>
                        ${u.reprobados > 0 ? `<span class="badge badge-soft-danger px-2 py-1"><i class="bi bi-x-circle-fill me-1"></i>${u.reprobados} Reprobados</span>` : ''}
                        <span class="badge bg-light text-dark border px-2 py-1">
                            ${u.tasaAprob}% Éxito
                        </span>
                    </div>

                    <div class="chevron-toggle-wrapper ms-2">
                        <button type="button" class="btn btn-sm btn-light border rounded-circle chevron-toggle" style="width: 32px; height: 32px; padding: 0; pointer-events: none;">
                            <i class="bi bi-chevron-down chevron-icon"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div class="collapse show user-eval-collapse" id="${domId}">
                <div class="table-responsive border-top">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light text-muted" style="font-size: 0.735rem; text-transform: uppercase; letter-spacing: 0.5px;">
                            <tr>
                                <th style="width: 45px;" class="text-center ps-3">#</th>
                                <th>Curso</th>
                                <th>Módulo Evaluado</th>
                                <th style="width: 160px;">Calificación</th>
                                <th style="width: 110px;">Estado</th>
                                <th style="width: 90px;" class="text-center">Intentos</th>
                                <th>Modalidad</th>
                                <th>Fecha</th>
                                <th class="text-end pe-3">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${filasModulosHtml}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>`;
    });

    contenedor.innerHTML = html;
    actualizarControlesPaginacion(_reporteEval.desde, _reporteEval.hasta, _reporteEval.totalItems);
}

/**
 * Dibuja las filas de la tabla PLANA con paginación
 */
function renderTablaEvaluacionesPaginada() {
    const tbody = document.getElementById('tabla-evaluaciones-body');
    if (!tbody) return;

    if (_evaluacionesFiltradas.length === 0) {
        tbody.innerHTML = `<tr><td colspan="10" class="text-center py-5 text-muted">
            <i class="bi bi-search fs-3 d-block mb-2 text-muted opacity-50"></i>
            No se encontraron evaluaciones con los filtros aplicados.
        </td></tr>`;
        actualizarControlesPaginacion(0, 0, 0);
        return;
    }

    const startIdx = Math.max(0, (_reporteEval?.desde || 1) - 1);
    const itemsPagina = _evaluacionesFiltradas;

    let html = '';
    itemsPagina.forEach((e, idx) => {
        const globalIdx = startIdx + idx + 1;
        const iniciales = (e.usuarioNombre || 'U').split(' ').filter(Boolean).map(p => p[0]).slice(0, 2).join('').toUpperCase();
        const badgeInfo = _obtenerBadgeCalificacion(e.calificacion);

        const estadoBadgeHtml = _htmlEstadoEvaluacion(e, 'shadow-sm');

        const origenBadgeHtml = e.marcadoManual
            ? `<span class="badge bg-warning text-dark border px-2 py-1"><i class="bi bi-pencil-square me-1"></i>Manual (Rectoría)</span>`
            : `<span class="badge bg-light text-dark border px-2 py-1"><i class="bi bi-laptop me-1 text-primary"></i>Examen Online</span>`;

        let fechaFormat = '—';
        if (e.fecha) {
            try {
                const fObj = new Date(e.fecha);
                if (!isNaN(fObj.getTime())) {
                    fechaFormat = fObj.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
                } else {
                    fechaFormat = escapeHtml(e.fecha);
                }
            } catch (_) { fechaFormat = escapeHtml(e.fecha); }
        } else if (e.marcadoManual) {
            fechaFormat = '<span class="text-success small fw-semibold"><i class="bi bi-patch-check-fill me-1"></i>Acreditado</span>';
        }

        html += `
        <tr>
            <td class="text-muted small fw-semibold text-center">${globalIdx}</td>
            <td>
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle bg-primary bg-opacity-10 text-primary fw-bold d-flex align-items-center justify-content-center" style="width: 34px; height: 34px; font-size: 0.75rem; flex-shrink: 0;">
                        ${escapeHtml(iniciales)}
                    </div>
                    <div>
                        <div class="fw-bold text-dark lh-sm">${escapeHtml(e.usuarioNombre)}</div>
                        <div class="text-muted small d-flex align-items-center gap-1">
                            <span>CI: ${escapeHtml(e.usuarioId)}</span>
                            <span>•</span>
                            <span class="badge bg-light text-secondary border px-1 py-0" style="font-size: 0.7rem;">${escapeHtml(e.rolNombre)}</span>
                        </div>
                    </div>
                </div>
            </td>
            <td>
                <div class="fw-semibold text-primary mb-0" style="max-width: 240px; white-space: normal;">${escapeHtml(e.cursoTitulo)}</div>
                <div class="text-muted small" style="font-size: 0.72rem;">Código: ${escapeHtml(e.cursoId)}</div>
            </td>
            <td>
                <div class="small fw-semibold text-dark">${escapeHtml(e.moduloTitulo)}</div>
            </td>
            <td>
                <div class="d-flex flex-column" style="width: 140px;">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        ${badgeInfo.badge}
                        <span class="text-muted small fw-semibold" style="font-size: 0.72rem;">${Math.round(e.calificacion)}%</span>
                    </div>
                    <div class="progress" style="height: 5px; border-radius: 4px; background-color: #e2e8f0;">
                        <div class="progress-bar ${badgeInfo.barColor}" style="width: ${Math.min(e.calificacion, 100)}%;"></div>
                    </div>
                </div>
            </td>
            <td>${estadoBadgeHtml}</td>
            <td class="text-center">
                ${_htmlIntentosEvaluacion(e)}
            </td>
            <td>${origenBadgeHtml}</td>
            <td class="small text-muted" style="white-space: nowrap;">
                <i class="bi bi-calendar3 me-1 opacity-75"></i>${fechaFormat}
            </td>
            <td class="text-end">${_htmlAccionesEvaluacion(e)}</td>
        </tr>`;
    });

    tbody.innerHTML = html;
    actualizarControlesPaginacion(_reporteEval.desde, _reporteEval.hasta, _reporteEval.totalItems);
}

function actualizarControlesPaginacion(desde, hasta, total) {
    const info = document.getElementById('evaluaciones-paginacion-info');
    const btnPrev = document.getElementById('btn-eval-prev');
    const btnNext = document.getElementById('btn-eval-next');

    if (info) {
        if (total === 0) {
            info.textContent = (_modoVistaEval === 'agrupada') ? 'Mostrando 0 de 0 colaboradores' : 'Mostrando 0 de 0 evaluaciones';
        } else if (_modoVistaEval === 'agrupada') {
            info.textContent = `Mostrando ${desde} a ${hasta} de ${total} colaboradores (${_reporteEval?.total || 0} calificaciones en total)`;
        } else {
            info.textContent = `Mostrando ${desde} a ${hasta} de ${total} evaluaciones`;
        }
    }

    const totalPaginas = _reporteEval?.paginas || 1;
    if (btnPrev) btnPrev.disabled = (_evalCurrentPage <= 1);
    if (btnNext) btnNext.disabled = (_evalCurrentPage >= totalPaginas);
}

function cambiarFilasPorPaginaEval(val) {
    _evalPerPage = val;
    _evalCurrentPage = 1;
    return cargarReporteEvaluaciones();
}

function paginaAnteriorEval() {
    if (_evalCurrentPage > 1) {
        _evalCurrentPage--;
        return cargarReporteEvaluaciones();
    }
}

function paginaSiguienteEval() {
    if (_evalCurrentPage < (_reporteEval?.paginas || 1)) {
        _evalCurrentPage++;
        return cargarReporteEvaluaciones();
    }
}

/**
 * Función Principal para cargar y mostrar el reporte de evaluaciones
 */
function renderReporteEvaluaciones() {
    inicializarFiltrosEvaluaciones();
    return filtrarTablaEvaluaciones();
}


window.renderReporteEvaluaciones     = renderReporteEvaluaciones;
window.abrirModalHabilitarReintento  = abrirModalHabilitarReintento;
window.renderVistaEvaluaciones       = renderVistaEvaluaciones;
window.cambiarModoVistaEvaluaciones  = cambiarModoVistaEvaluaciones;
window.expandirTodosColaboradores    = expandirTodosColaboradores;
window.toggleColaboradorAcordeon     = toggleColaboradorAcordeon;
window.filtrarTablaEvaluaciones      = filtrarTablaEvaluaciones;
window.cambiarFilasPorPaginaEval     = cambiarFilasPorPaginaEval;
window.paginaAnteriorEval            = paginaAnteriorEval;
window.paginaSiguienteEval           = paginaSiguienteEval;
window.buscarEvaluacionesDebounced   = buscarEvaluacionesDebounced;

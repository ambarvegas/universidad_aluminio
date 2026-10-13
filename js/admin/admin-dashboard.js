/**
 * Universidad del Aluminio — Admin Dashboard Module
 * KPIs, tablas de administración (cursos, usuarios, carreras, roles, solicitudes) y filtros en vivo.
 */

window.refrescarDatosAdmin = async (btn) => {
    const icon = btn?.querySelector('i');
    if (icon) icon.classList.add('spin-animation');

    try {
        await cargarDatosDelServidor();
        showToast('Datos re-sincronizados con éxito desde el servidor.', 'success');
    } catch (err) {
        console.error('Error al refrescar datos:', err);
        showToast('Error al refrescar datos: ' + err.message, 'danger');
    } finally {
        if (icon) setTimeout(() => icon.classList.remove('spin-animation'), 600);
    }
};

let usuariosPaginaActual = 1;
let usuariosPorPagina = 25;

// Helpers de render seguro (escapeHtml/debounce definidos en js/core/state.js)
const esc = (v) => window.escapeHtml(v);
// Argumento string para handlers inline: onclick="fn(${jsArg(id)})"
const jsArg = (v) => esc(JSON.stringify(String(v ?? '')));

window.cambiarPaginaUsuarios = (nuevaPagina) => {
    usuariosPaginaActual = nuevaPagina;
    renderTablaUsuariosAdmin();
};

window.cambiarLimiteUsuarios = (nuevoLimite) => {
    usuariosPorPagina = parseInt(nuevoLimite, 10) || 25;
    usuariosPaginaActual = 1;
    renderTablaUsuariosAdmin();
};

// Los filtros en vivo solo re-renderizan su propia tabla, con debounce para no
// reconstruir el DOM en cada pulsación de tecla.
const renderUsuariosDebounced = window.debounce(() => renderTablaUsuariosAdmin(), 150);
const renderCursosDebounced = window.debounce(() => renderTablaCursosAdmin(), 150);

window.filtrarTablaUsuariosAdmin = () => {
    usuariosPaginaActual = 1;
    renderUsuariosDebounced();
};

window.filtrarTablaCursosAdmin = () => {
    renderCursosDebounced();
};

function renderKpisAdmin() {
    const elKpiCursos = document.getElementById('admin-kpi-cursos');
    if (elKpiCursos) elKpiCursos.textContent = (cursos || []).length;

    const elKpiUsuarios = document.getElementById('admin-kpi-usuarios');
    if (elKpiUsuarios) elKpiUsuarios.textContent = (usuarios || []).length;

    const totalSolicitudes = (solicitudesRegistro || []).length + (solicitudesCursos || []).length;
    const elKpiSolicitudes = document.getElementById('admin-kpi-solicitudes');
    if (elKpiSolicitudes) elKpiSolicitudes.textContent = totalSolicitudes;

    const badgeSolTotal = document.getElementById('badge-solicitudes-total');
    if (badgeSolTotal) {
        if (totalSolicitudes > 0) {
            badgeSolTotal.textContent = totalSolicitudes;
            badgeSolTotal.style.display = 'inline-block';
        } else {
            badgeSolTotal.style.display = 'none';
        }
    }

    const totalCertificados = (usuarios || []).reduce(
        (acc, u) => acc + (Array.isArray(u.certificadosCurso) ? u.certificadosCurso.length : 0), 0);
    const elKpiCertificados = document.getElementById('admin-kpi-certificados');
    if (elKpiCertificados) elKpiCertificados.textContent = totalCertificados;
}

function renderTablaCursosAdmin() {
    const tablaCursosBody = document.getElementById('tabla-cursos-body');
    if (!tablaCursosBody) return;

    const searchInputEl = document.getElementById('search-admin-cursos');
    const clearBtnEl = document.getElementById('btn-clear-search-cursos');
    const rawSearchVal = searchInputEl?.value || '';
    const searchCursoQuery = normalizar(rawSearchVal.trim());

    if (clearBtnEl) {
        clearBtnEl.style.display = rawSearchVal ? 'flex' : 'none';
    }

    const listaCursos = cursos || [];
    const cursosPorId = new Map(listaCursos.map(c => [c.id, c]));
    const cursosFiltrados = searchCursoQuery
        ? listaCursos.filter(c => normalizar(c.titulo || '').includes(searchCursoQuery) ||
                                  normalizar(String(c.id || '')).includes(searchCursoQuery))
        : listaCursos;

    if (cursosFiltrados.length === 0) {
        tablaCursosBody.innerHTML = searchCursoQuery
            ? `<tr><td colspan="5" class="text-center text-muted py-4"><i class="bi bi-search me-1"></i>No se encontraron cursos coincidentes con "<strong>${esc(rawSearchVal)}</strong>". <button type="button" class="btn btn-sm btn-link text-primary p-0 ms-2 text-decoration-none" onclick="document.getElementById('search-admin-cursos').value=''; filtrarTablaCursosAdmin();"><i class="bi bi-x-circle me-1"></i>Limpiar búsqueda</button></td></tr>`
            : '<tr><td colspan="5" class="text-center text-muted py-4"><i class="bi bi-journal-x me-1"></i>No hay cursos registrados en el catálogo.</td></tr>';
        return;
    }

    tablaCursosBody.innerHTML = cursosFiltrados.map(c => {
        const badgeTipo = (c.tipo === 'pruebas')
            ? '<span class="badge text-white" style="background-color: #7c3aed;"><i class="bi bi-flask me-1"></i>Pruebas (Admin)</span>'
            : ((c.tipo === 'publico' || c.tipo === 'libre')
                ? '<span class="badge-soft-success"><i class="bi bi-unlock-fill me-1"></i>Acceso Libre</span>'
                : '<span class="badge-soft-warning"><i class="bi bi-mortarboard-fill me-1"></i>Especializado</span>');

        const modulos = c.modulos || [];
        const totalModulos = modulos.length;
        const totalLecciones = modulos.reduce((acc, m) => acc + (m.lecciones || []).length, 0);

        let prelacionTexto = '<span class="text-muted small">Ninguna</span>';
        if (c.prelacion) {
            const cPrev = cursosPorId.get(c.prelacion);
            prelacionTexto = `<span class="badge bg-info bg-opacity-10 text-dark border"><i class="bi bi-diagram-3 me-1"></i>${esc(cPrev ? cPrev.titulo : c.prelacion)}</span>`;
        }

        const imgSrc = c.imagen ? (typeof resolverSrcImagen === 'function' ? resolverSrcImagen(c.imagen) : c.imagen) : '';

        return `
            <tr>
                <td><span class="code-chip">${esc(c.id)}</span></td>
                <td>
                    <div class="d-flex align-items-center gap-3">
                        ${imgSrc ? `<img src="${esc(imgSrc)}" class="rounded border" style="width:48px; height:32px; object-fit:cover;" alt="Portada" loading="lazy">` : `<div class="bg-primary bg-opacity-10 text-primary rounded d-flex align-items-center justify-content-center" style="width:48px; height:32px;"><i class="bi bi-journal-bookmark"></i></div>`}
                        <div>
                            <div class="fw-bold text-dark">${esc(c.titulo)}</div>
                            <div class="mt-1">
                                ${badgeTipo}
                                ${c.enConstruccion ? '<span class="badge bg-warning text-dark ms-1"><i class="bi bi-cone-striped me-1"></i>En Construcción</span>' : ''}
                            </div>
                        </div>
                    </div>
                </td>
                <td>
                    <span class="badge bg-light text-dark border me-1"><i class="bi bi-folder2-open me-1 text-primary"></i>${totalModulos} mód.</span>
                    <span class="badge bg-light text-dark border"><i class="bi bi-play-btn me-1 text-primary"></i>${totalLecciones} lecc.</span>
                </td>
                <td>${prelacionTexto}</td>
                <td class="text-end">
                    <div class="btn-action-group">
                        <button class="btn btn-sm btn-outline-primary" onclick="abrirEditor(${jsArg(c.id)})" title="Editar contenido y temario">
                            <i class="bi bi-pencil-square me-1"></i>Editar
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="eliminarCurso(${jsArg(c.id)})" title="Eliminar curso">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>`;
    }).join('');
}

function renderPaginacionUsuarios(totalPaginas) {
    const pageBtn = (p) =>
        `<li class="page-item ${p === usuariosPaginaActual ? 'active' : ''}"><button class="page-link" onclick="cambiarPaginaUsuarios(${p})">${p}</button></li>`;
    const ellipsis = '<li class="page-item disabled"><span class="page-link">…</span></li>';

    const partes = [`
        <li class="page-item ${usuariosPaginaActual === 1 ? 'disabled' : ''}">
            <button class="page-link" onclick="cambiarPaginaUsuarios(${usuariosPaginaActual - 1})" aria-label="Anterior">
                <i class="bi bi-chevron-left"></i>
            </button>
        </li>`];

    // Ventana de paginación inteligente (hasta 5 botones de páginas visibles)
    let startPage = Math.max(1, usuariosPaginaActual - 2);
    const endPage = Math.min(totalPaginas, startPage + 4);
    if (endPage - startPage < 4) {
        startPage = Math.max(1, endPage - 4);
    }

    if (startPage > 1) {
        partes.push(pageBtn(1));
        if (startPage > 2) partes.push(ellipsis);
    }
    for (let p = startPage; p <= endPage; p++) {
        partes.push(pageBtn(p));
    }
    if (endPage < totalPaginas) {
        if (endPage < totalPaginas - 1) partes.push(ellipsis);
        partes.push(pageBtn(totalPaginas));
    }

    partes.push(`
        <li class="page-item ${usuariosPaginaActual === totalPaginas ? 'disabled' : ''}">
            <button class="page-link" onclick="cambiarPaginaUsuarios(${usuariosPaginaActual + 1})" aria-label="Siguiente">
                <i class="bi bi-chevron-right"></i>
            </button>
        </li>`);

    return partes.join('');
}

function renderTablaUsuariosAdmin() {
    const userTable = document.getElementById('tabla-usuarios-body');
    if (!userTable) return;

    const userPaginationNav = document.getElementById('usuarios-paginacion-nav');
    const userPaginationInfo = document.getElementById('usuarios-info-paginacion');
    const searchUserInputEl = document.getElementById('search-admin-usuarios');
    const clearUserBtnEl = document.getElementById('btn-clear-search-usuarios');
    const rawUserSearchVal = searchUserInputEl?.value || '';
    const searchUserQuery = normalizar(rawUserSearchVal.trim());

    if (clearUserBtnEl) {
        clearUserBtnEl.style.display = rawUserSearchVal ? 'flex' : 'none';
    }

    const listaUsuarios = usuarios || [];
    const usuariosFiltrados = searchUserQuery
        ? listaUsuarios.filter(u => normalizar(u.nombre || '').includes(searchUserQuery) ||
                                    normalizar(String(u.id || '')).includes(searchUserQuery) ||
                                    normalizar(u.rol || '').includes(searchUserQuery))
        : listaUsuarios;

    const totalUsuarios = usuariosFiltrados.length;
    const totalPaginas = Math.max(1, Math.ceil(totalUsuarios / usuariosPorPagina));
    usuariosPaginaActual = Math.max(1, Math.min(usuariosPaginaActual, totalPaginas));

    if (totalUsuarios === 0) {
        userTable.innerHTML = searchUserQuery
            ? `<tr><td colspan="5" class="text-center text-muted py-4"><i class="bi bi-search me-1"></i>No se encontraron colaboradores coincidentes con "<strong>${esc(rawUserSearchVal)}</strong>". <button type="button" class="btn btn-sm btn-link text-primary p-0 ms-2 text-decoration-none" onclick="document.getElementById('search-admin-usuarios').value=''; filtrarTablaUsuariosAdmin();"><i class="bi bi-x-circle me-1"></i>Limpiar búsqueda</button></td></tr>`
            : '<tr><td colspan="5" class="text-center text-muted py-4"><i class="bi bi-people me-1"></i>No hay colaboradores registrados.</td></tr>';
        if (userPaginationInfo) userPaginationInfo.textContent = 'Mostrando 0 de 0';
        if (userPaginationNav) userPaginationNav.innerHTML = '';
        return;
    }

    const inicio = (usuariosPaginaActual - 1) * usuariosPorPagina;
    const fin = Math.min(inicio + usuariosPorPagina, totalUsuarios);
    const usuariosPagina = usuariosFiltrados.slice(inicio, fin);
    const carrerasPorId = new Map((carreras || []).map(c => [c.id, c]));

    userTable.innerHTML = usuariosPagina.map(u => {
        const assignedCareersNames = (u.carrerasAsignadas || []).map(ca => {
            const car = carrerasPorId.get(ca.id);
            const carNombre = car ? car.nombre : (ca.id || 'Desconocida');
            const isComplete = ca.estado === 'Completada';
            return `<span class="badge ${isComplete ? 'bg-success' : 'bg-secondary bg-opacity-25 text-dark border'} me-1 mb-1">${esc(carNombre)} (${esc(ca.estado || 'Incompleta')})</span>`;
        }).join(' ');

        const certCount = Array.isArray(u.certificadosCurso) ? u.certificadosCurso.length : 0;
        const badgeStatus = (u.estado === 'suspendido')
            ? '<span class="badge-soft-danger"><i class="bi bi-slash-circle me-1"></i>Suspendido</span>'
            : '<span class="badge-soft-success"><i class="bi bi-check-circle-fill me-1"></i>Activo</span>';

        const fNac = u.fecha_nacimiento || u.fechaNacimiento || '';
        const contactHtml = (u.email || u.telefono || fNac) ? `
            <div class="small text-muted d-flex flex-wrap gap-2 align-items-center mt-1">
                ${u.email ? `<span><i class="bi bi-envelope me-1 text-primary"></i>${esc(u.email)}</span>` : ''}
                ${u.telefono ? `<span><i class="bi bi-telephone me-1 text-success"></i>${esc(u.telefono)}</span>` : ''}
                ${fNac ? `<span><i class="bi bi-cake2 me-1 text-warning"></i>${esc(fNac)}</span>` : ''}
            </div>` : '';

        const iniciales = typeof obtenerIniciales === 'function' ? obtenerIniciales(u.nombre) : (u.nombre ? u.nombre.charAt(0) : 'U');
        const uid = jsArg(u.id);

        return `
            <tr>
                <td>
                    <div class="d-flex align-items-center gap-3">
                        <div class="user-avatar" style="width: 40px; height: 40px; font-size: 0.9rem;">
                            ${esc(iniciales)}
                        </div>
                        <div>
                            <div class="fw-bold text-dark">${esc(u.nombre)}</div>
                            ${contactHtml}
                            ${certCount > 0 ? `<small class="text-success fw-semibold d-block mt-1"><i class="bi bi-award-fill me-1"></i>${certCount} certificado${certCount !== 1 ? 's' : ''}</small>` : ''}
                        </div>
                    </div>
                </td>
                <td><span class="code-chip">${esc(u.id)}</span></td>
                <td>
                    <span class="badge bg-light text-dark border mb-1">${esc((u.rol || 'Sin Rol').replace(/_/g, ' '))}</span>
                    <div>${assignedCareersNames || '<small class="text-muted fst-italic">Sin carreras asociadas</small>'}</div>
                </td>
                <td>${badgeStatus}</td>
                <td class="text-end">
                    <div class="btn-action-group">
                        <button class="btn btn-sm btn-outline-primary" onclick="abrirEditorUsuario(${uid})" title="Editar datos del usuario">
                            <i class="bi bi-person-gear me-1"></i>Editar
                        </button>
                        <button class="btn btn-sm btn-outline-info" onclick="abrirModalInvitacionAcceso(${uid})" title="Generar / Enviar enlace de acceso e invitación por correo">
                            <i class="bi bi-send-check"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-warning" onclick="abrirRestablecerAvance(${uid})" title="Restablecer progreso">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-success" onclick="abrirMarcarCompletado(${uid})" title="Marcar módulos completados">
                            <i class="bi bi-check2-circle"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="eliminarUsuario(${uid})" title="Eliminar usuario">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>`;
    }).join('');

    if (userPaginationInfo) {
        userPaginationInfo.textContent = `Mostrando ${inicio + 1} - ${fin} de ${totalUsuarios} colaboradores`;
    }
    if (userPaginationNav) {
        userPaginationNav.innerHTML = renderPaginacionUsuarios(totalPaginas);
    }
}

function renderTablaRolesAdmin() {
    const rolesTable = document.getElementById('tabla-roles-body');
    if (!rolesTable || !Array.isArray(rolesConfig)) return;

    rolesTable.innerHTML = rolesConfig.filter(r => r.id !== 'admin').map(r => `
        <tr>
            <td><span class="code-chip">${esc(r.id)}</span></td>
            <td><strong class="text-dark">${esc(r.nombre)}</strong></td>
            <td>
                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 me-1"><i class="bi bi-journal me-1"></i>${(r.cursos || []).length} Cursos</span>
                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25"><i class="bi bi-diagram-3 me-1"></i>${(r.carreras || []).length} Carreras</span>
            </td>
            <td class="text-end">
                <div class="btn-action-group">
                    <button class="btn btn-sm btn-outline-primary" onclick="abrirEditorRol(${jsArg(r.id)})">
                        <i class="bi bi-sliders me-1"></i>Configurar Carga
                    </button>
                    <button class="btn btn-sm btn-outline-danger" onclick="eliminarRol(${jsArg(r.id)})">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </td>
        </tr>`).join('');
}

function renderTablasSolicitudesAdmin() {
    const reqRegTable = document.getElementById('tabla-solicitudes-registro');
    if (reqRegTable) {
        reqRegTable.innerHTML = (solicitudesRegistro || []).map(s => `
            <tr>
                <td><span class="code-chip">${esc(s.id)}</span></td>
                <td><strong class="text-dark">${esc(s.nombre)}</strong></td>
                <td><span class="badge bg-light text-dark border">${esc(s.perfilDeseado)}</span></td>
                <td class="text-end">
                    <div class="btn-action-group">
                        <button class="btn btn-sm btn-success" onclick="gestionarSolicitudRegistro(${jsArg(s.id)}, true)">
                            <i class="bi bi-check-lg me-1"></i>Aprobar
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="gestionarSolicitudRegistro(${jsArg(s.id)}, false)">
                            <i class="bi bi-x-lg me-1"></i>Rechazar
                        </button>
                    </div>
                </td>
            </tr>
        `).join('') || '<tr><td colspan="4" class="text-center text-muted py-3">No hay solicitudes de registro pendientes.</td></tr>';
    }

    const reqCurTable = document.getElementById('tabla-solicitudes-cursos');
    if (reqCurTable) {
        reqCurTable.innerHTML = (solicitudesCursos || []).map(s => `
            <tr>
                <td><strong class="text-dark">${esc(s.userName || s.userId)}</strong></td>
                <td><span class="badge-soft-primary">${esc(s.cursoId)}</span></td>
                <td><small class="text-muted">${esc(s.fecha || 'Reciente')}</small></td>
                <td class="text-end">
                    <div class="btn-action-group">
                        <button class="btn btn-sm btn-success" onclick="gestionarSolicitudCurso(${jsArg(s.userId)}, ${jsArg(s.cursoId)}, true)">
                            <i class="bi bi-check-lg me-1"></i>Aprobar
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="gestionarSolicitudCurso(${jsArg(s.userId)}, ${jsArg(s.cursoId)}, false)">
                            <i class="bi bi-x-lg me-1"></i>Rechazar
                        </button>
                    </div>
                </td>
            </tr>
        `).join('') || '<tr><td colspan="4" class="text-center text-muted py-3">No hay solicitudes de acceso a cursos.</td></tr>';
    }
}

function renderTablaCarrerasAdmin() {
    const careerTable = document.getElementById('tabla-carreras-body');
    if (!careerTable) return;

    careerTable.innerHTML = (carreras || []).map(car => `
        <tr>
            <td><strong class="text-dark">${esc(car.nombre)}</strong></td>
            <td>
                <span class="badge bg-light text-dark border me-2">${(car.cursos || []).length} cursos asignados</span>
                <button class="btn btn-xs btn-outline-secondary" onclick="duplicarCarrera(${jsArg(car.id)})" title="Duplicar">
                    <i class="bi bi-copy me-1"></i>Duplicar
                </button>
            </td>
            <td class="text-end">
                <div class="btn-action-group">
                    <button class="btn btn-sm btn-outline-primary" onclick="abrirEditorCarrera(${jsArg(car.id)})">
                        <i class="bi bi-pencil-square me-1"></i>Editar
                    </button>
                    <button class="btn btn-sm btn-outline-danger" onclick="eliminarCarrera(${jsArg(car.id)})">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </td>
        </tr>`).join('');
}

/**
 * Re-renderiza todo el panel. Usar tras cargar o mutar datos; para filtros y
 * paginación se llama solo al render de la tabla afectada.
 */
function actualizarTablas() {
    if (typeof renderListaCursosCarrera === 'function') {
        renderListaCursosCarrera();
    }
    renderKpisAdmin();
    renderTablaCursosAdmin();
    renderTablaUsuariosAdmin();
    renderTablaRolesAdmin();
    renderTablasSolicitudesAdmin();
    renderTablaCarrerasAdmin();
}
window.actualizarTablas = actualizarTablas;
window.renderTablaCursosAdmin = renderTablaCursosAdmin;
window.renderTablaUsuariosAdmin = renderTablaUsuariosAdmin;

// ============================================================
// REPORTES — Cambio de Vista
// ============================================================

window.cambiarVistaReporte = (vista) => {
    const vistaEval     = document.getElementById('vista-evaluaciones');
    const vistaLearners = document.getElementById('vista-learners');
    const vistaBrechas  = document.getElementById('vista-brechas');
    const btnE = document.getElementById('btn-vista-evaluaciones');
    const btnL = document.getElementById('btn-vista-learners');
    const btnB = document.getElementById('btn-vista-brechas');

    if (vista === 'evaluaciones') {
        if (vistaEval)     vistaEval.style.display     = '';
        if (vistaLearners) vistaLearners.style.display = 'none';
        if (vistaBrechas)  vistaBrechas.style.display  = 'none';
        if (btnE) btnE.className = 'btn btn-sm btn-primary';
        if (btnL) btnL.className = 'btn btn-sm btn-outline-primary';
        if (btnB) btnB.className = 'btn btn-sm btn-outline-primary';
        if (typeof renderReporteEvaluaciones === 'function') renderReporteEvaluaciones();
    } else if (vista === 'learners') {
        if (vistaEval)     vistaEval.style.display     = 'none';
        if (vistaLearners) vistaLearners.style.display = '';
        if (vistaBrechas)  vistaBrechas.style.display  = 'none';
        if (btnE) btnE.className = 'btn btn-sm btn-outline-primary';
        if (btnL) btnL.className = 'btn btn-sm btn-primary';
        if (btnB) btnB.className = 'btn btn-sm btn-outline-primary';
        if (typeof renderTopLearners === 'function') renderTopLearners();
        if (typeof renderCumplimientoCargo === 'function') renderCumplimientoCargo();
    } else {
        if (vistaEval)     vistaEval.style.display     = 'none';
        if (vistaLearners) vistaLearners.style.display = 'none';
        if (vistaBrechas)  vistaBrechas.style.display  = '';
        if (btnE) btnE.className = 'btn btn-sm btn-outline-primary';
        if (btnL) btnL.className = 'btn btn-sm btn-outline-primary';
        if (btnB) btnB.className = 'btn btn-sm btn-primary';
        if (typeof renderBrechasAprendizaje === 'function') {
            if (typeof inicializarFiltroUsuariosBrechas === 'function') inicializarFiltroUsuariosBrechas();
            renderBrechasAprendizaje();
        }
    }
};

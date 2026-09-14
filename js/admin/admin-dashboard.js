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

window.filtrarTablaCursosAdmin = () => {
    actualizarTablas();
};

window.filtrarTablaUsuariosAdmin = () => {
    actualizarTablas();
};

function actualizarTablas() {
    if (typeof renderListaCursosCarrera === 'function') {
        renderListaCursosCarrera();
    }

    // 1. Actualizar Métricas KPI del Dashboard de Administración
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

    let totalCertificados = 0;
    (usuarios || []).forEach(u => {
        if (Array.isArray(u.certificadosCurso)) totalCertificados += u.certificadosCurso.length;
    });
    const elKpiCertificados = document.getElementById('admin-kpi-certificados');
    if (elKpiCertificados) elKpiCertificados.textContent = totalCertificados;

    // 2. Renderizar Tabla de Cursos con Filtro en Vivo
    const tablaCursosBody = document.getElementById('tabla-cursos-body');
    if (tablaCursosBody) {
        tablaCursosBody.innerHTML = '';
        const searchCursoQuery = (document.getElementById('search-admin-cursos')?.value || '').trim().toLowerCase();

        const cursosFiltrados = (cursos || []).filter(c => {
            if (!searchCursoQuery) return true;
            return (c.titulo || '').toLowerCase().includes(searchCursoQuery) ||
                   (c.id || '').toLowerCase().includes(searchCursoQuery);
        });

        if (cursosFiltrados.length === 0) {
            tablaCursosBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4"><i class="bi bi-search me-1"></i>No se encontraron cursos coincidentes.</td></tr>';
        } else {
            cursosFiltrados.forEach(c => {
                const esLibre = c.tipo === 'publico' || c.tipo === 'libre';
                const badgeTipo = esLibre
                    ? '<span class="badge-soft-success"><i class="bi bi-unlock-fill me-1"></i>Acceso Libre</span>'
                    : '<span class="badge-soft-warning"><i class="bi bi-mortarboard-fill me-1"></i>Especializado</span>';
                
                const totalModulos = (c.modulos || []).length;
                let totalLecciones = 0;
                (c.modulos || []).forEach(m => totalLecciones += (m.lecciones || []).length);

                let prelacionTexto = '<span class="text-muted small">Ninguna</span>';
                if (c.prelacion) {
                    const cPrev = cursos.find(cp => cp.id === c.prelacion);
                    prelacionTexto = `<span class="badge bg-info bg-opacity-10 text-dark border"><i class="bi bi-diagram-3 me-1"></i>${cPrev ? cPrev.titulo : c.prelacion}</span>`;
                }

                tablaCursosBody.innerHTML += `
                    <tr>
                        <td><span class="code-chip">${c.id}</span></td>
                        <td>
                            <div class="d-flex align-items-center gap-3">
                                ${c.imagen ? `<img src="${typeof resolverSrcImagen === 'function' ? resolverSrcImagen(c.imagen) : c.imagen}" class="rounded border" style="width:48px; height:32px; object-fit:cover;" alt="Portada">` : `<div class="bg-primary bg-opacity-10 text-primary rounded d-flex align-items-center justify-content-center" style="width:48px; height:32px;"><i class="bi bi-journal-bookmark"></i></div>`}
                                <div>
                                    <div class="fw-bold text-dark">${c.titulo}</div>
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
                                <button class="btn btn-sm btn-outline-primary" onclick="abrirEditor('${c.id}')" title="Editar contenido y temario">
                                    <i class="bi bi-pencil-square me-1"></i>Editar
                                </button>
                                <button class="btn btn-sm btn-outline-danger" onclick="eliminarCurso('${c.id}')" title="Eliminar curso">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>`;
            });
        }
    }

    // 3. Renderizar Tabla de Usuarios con Filtro en Vivo y Avatares
    const userTable = document.getElementById('tabla-usuarios-body');
    if (userTable) {
        userTable.innerHTML = '';
        const searchUserQuery = (document.getElementById('search-admin-usuarios')?.value || '').trim().toLowerCase();

        const usuariosFiltrados = (usuarios || []).filter(u => {
            if (!searchUserQuery) return true;
            return (u.nombre || '').toLowerCase().includes(searchUserQuery) ||
                   (u.id || '').toLowerCase().includes(searchUserQuery) ||
                   (u.rol || '').toLowerCase().includes(searchUserQuery);
        });

        if (usuariosFiltrados.length === 0) {
            userTable.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4"><i class="bi bi-search me-1"></i>No se encontraron colaboradores coincidentes.</td></tr>';
        } else {
            usuariosFiltrados.forEach(u => {
                const assignedCareersNames = (u.carrerasAsignadas || []).map(ca => {
                    const car = carreras.find(c => c.id === ca.id);
                    const carNombre = car ? car.nombre : (ca.id || 'Desconocida');
                    const isComplete = ca.estado === 'Completada';
                    return `<span class="badge ${isComplete ? 'bg-success' : 'bg-secondary bg-opacity-25 text-dark border'} me-1 mb-1">${carNombre} (${ca.estado || 'Incompleta'})</span>`;
                }).join(' ');

                const certCount = Array.isArray(u.certificadosCurso) ? u.certificadosCurso.length : 0;
                const badgeStatus = (u.estado === 'suspendido')
                    ? '<span class="badge-soft-danger"><i class="bi bi-slash-circle me-1"></i>Suspendido</span>'
                    : '<span class="badge-soft-success"><i class="bi bi-check-circle-fill me-1"></i>Activo</span>';

                userTable.innerHTML += `
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-3">
                                <div class="user-avatar" style="width: 40px; height: 40px; font-size: 0.9rem;">
                                    ${typeof obtenerIniciales === 'function' ? obtenerIniciales(u.nombre) : (u.nombre ? u.nombre.charAt(0) : 'U')}
                                </div>
                                <div>
                                    <div class="fw-bold text-dark">${u.nombre}</div>
                                    ${certCount > 0 ? `<small class="text-success fw-semibold"><i class="bi bi-award-fill me-1"></i>${certCount} certificado${certCount !== 1 ? 's' : ''}</small>` : ''}
                                </div>
                            </div>
                        </td>
                        <td><span class="code-chip">${u.id}</span></td>
                        <td>
                            <span class="badge bg-light text-dark border mb-1">${(u.rol || 'Sin Rol').replace('_', ' ')}</span>
                            <div>${assignedCareersNames || '<small class="text-muted fst-italic">Sin carreras asociadas</small>'}</div>
                        </td>
                        <td>${badgeStatus}</td>
                        <td class="text-end">
                            <div class="btn-action-group">
                                <button class="btn btn-sm btn-outline-primary" onclick="abrirEditorUsuario('${u.id}')" title="Editar datos del usuario">
                                    <i class="bi bi-person-gear me-1"></i>Editar
                                </button>
                                <button class="btn btn-sm btn-outline-warning" onclick="abrirRestablecerAvance('${u.id}')" title="Restablecer progreso">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-success" onclick="abrirMarcarCompletado('${u.id}')" title="Marcar módulos completados">
                                    <i class="bi bi-check2-circle"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger" onclick="eliminarUsuario('${u.id}')" title="Eliminar usuario">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>`;
            });
        }
    }

    // 4. Renderizar Roles & Permisos
    const rolesTable = document.getElementById('tabla-roles-body');
    if (rolesTable && Array.isArray(rolesConfig)) {
        rolesTable.innerHTML = '';
        rolesConfig.filter(r => r.id !== 'admin').forEach(r => {
            const totalC = (r.cursos || []).length;
            const totalCar = (r.carreras || []).length;
            rolesTable.innerHTML += `
                <tr>
                    <td><span class="code-chip">${r.id}</span></td>
                    <td><strong class="text-dark">${r.nombre}</strong></td>
                    <td>
                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 me-1"><i class="bi bi-journal me-1"></i>${totalC} Cursos</span>
                        <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25"><i class="bi bi-diagram-3 me-1"></i>${totalCar} Carreras</span>
                    </td>
                    <td class="text-end">
                        <div class="btn-action-group">
                            <button class="btn btn-sm btn-outline-primary" onclick="abrirEditorRol('${r.id}')">
                                <i class="bi bi-sliders me-1"></i>Configurar Carga
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="eliminarRol('${r.id}')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>`;
        });
    }

    // 5. Renderizar Solicitudes de Registro
    const reqRegTable = document.getElementById('tabla-solicitudes-registro');
    if (reqRegTable) {
        reqRegTable.innerHTML = (solicitudesRegistro || []).map(s => `
            <tr>
                <td><span class="code-chip">${s.id}</span></td>
                <td><strong class="text-dark">${s.nombre}</strong></td>
                <td><span class="badge bg-light text-dark border">${s.perfilDeseado}</span></td>
                <td class="text-end">
                    <div class="btn-action-group">
                        <button class="btn btn-sm btn-success" onclick="gestionarSolicitudRegistro('${s.id}', true)">
                            <i class="bi bi-check-lg me-1"></i>Aprobar
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="gestionarSolicitudRegistro('${s.id}', false)">
                            <i class="bi bi-x-lg me-1"></i>Rechazar
                        </button>
                    </div>
                </td>
            </tr>
        `).join('') || '<tr><td colspan="4" class="text-center text-muted py-3">No hay solicitudes de registro pendientes.</td></tr>';
    }

    // 6. Renderizar Solicitudes de Cursos
    const reqCurTable = document.getElementById('tabla-solicitudes-cursos');
    if (reqCurTable) {
        reqCurTable.innerHTML = (solicitudesCursos || []).map(s => `
            <tr>
                <td><strong class="text-dark">${s.userName || s.userId}</strong></td>
                <td><span class="badge-soft-primary">${s.cursoId}</span></td>
                <td><small class="text-muted">${s.fecha || 'Reciente'}</small></td>
                <td class="text-end">
                    <div class="btn-action-group">
                        <button class="btn btn-sm btn-success" onclick="gestionarSolicitudCurso('${s.userId}', '${s.cursoId}', true)">
                            <i class="bi bi-check-lg me-1"></i>Aprobar
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="gestionarSolicitudCurso('${s.userId}', '${s.cursoId}', false)">
                            <i class="bi bi-x-lg me-1"></i>Rechazar
                        </button>
                    </div>
                </td>
            </tr>
        `).join('') || '<tr><td colspan="4" class="text-center text-muted py-3">No hay solicitudes de acceso a cursos.</td></tr>';
    }

    // 7. Renderizar Carreras
    const careerTable = document.getElementById('tabla-carreras-body');
    if (careerTable) {
        careerTable.innerHTML = '';
        (carreras || []).forEach(car => {
            careerTable.innerHTML += `
                <tr>
                    <td><strong class="text-dark">${car.nombre}</strong></td>
                    <td>
                        <span class="badge bg-light text-dark border me-2">${(car.cursos || []).length} cursos asignados</span>
                        <button class="btn btn-xs btn-outline-secondary" onclick="duplicarCarrera('${car.id}')" title="Duplicar">
                            <i class="bi bi-copy me-1"></i>Duplicar
                        </button>
                    </td>
                    <td class="text-end">
                        <div class="btn-action-group">
                            <button class="btn btn-sm btn-outline-primary" onclick="abrirEditorCarrera('${car.id}')">
                                <i class="bi bi-pencil-square me-1"></i>Editar
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="eliminarCarrera('${car.id}')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>`;
        });
    }
}
window.actualizarTablas = actualizarTablas;

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

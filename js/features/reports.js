/**
 * js/features/reports.js
 * Reportes avanzados del Panel Administrativo:
 *  1. Top Learners — Desempeño integral (tabla mejorada)
 *  2. Brechas de Aprendizaje — Árbol expandible: usuario → cursos faltantes
 *     → módulos faltantes → lecciones faltantes
 */

// ============================================================
// UTILIDADES INTERNAS
// ============================================================

function _getProgresoCurso(usuario, cursoId) {
    if (!usuario.progreso || typeof usuario.progreso !== 'object') return {};
    return usuario.progreso[cursoId] || {};
}

function _porcentajeCurso(usuario, curso) {
    if (!curso || !Array.isArray(curso.modulos) || curso.modulos.length === 0) return 0;
    const prog = _getProgresoCurso(usuario, curso.id);
    const completadas = Array.isArray(prog.leccionesCompletadas) ? prog.leccionesCompletadas : [];
    let totalLecciones = 0, completadasCount = 0;
    curso.modulos.forEach(mod => {
        (mod.lecciones || []).forEach(les => {
            totalLecciones++;
            const lesId = `${curso.id}_${mod.id || mod.titulo}_${les.id || les.titulo}`;
            if (completadas.some(id => id === lesId || String(id).includes(les.id || les.titulo))) {
                completadasCount++;
            }
        });
    });
    return totalLecciones === 0 ? 0 : Math.round((completadasCount / totalLecciones) * 100);
}

/**
 * Obtiene todos los IDs de cursos requeridos para un usuario según su ROL,
 * incluyendo cursos directos del rol, carreras asociadas al rol y asignaciones manuales.
 */
function _obtenerCursosDelRol(usuario) {
    const cursosSet = new Set();
    if (!usuario) return [];

    // 1. Cursos asociados al Rol directamente (en rolesConfig)
    const userRoleConfig = (rolesConfig || []).find(r => r.id === usuario.rol);
    if (userRoleConfig) {
        if (Array.isArray(userRoleConfig.cursos)) {
            userRoleConfig.cursos.forEach(cid => { if (cid) cursosSet.add(cid); });
        }
        if (Array.isArray(userRoleConfig.carreras)) {
            userRoleConfig.carreras.forEach(carId => {
                const carrera = (carreras || []).find(c => c.id === carId);
                if (carrera && Array.isArray(carrera.cursos)) {
                    carrera.cursos.forEach(cid => { if (cid) cursosSet.add(cid); });
                }
            });
        }
    }

    // 2. Carrera asociada automáticamente según el rol (getCareerIdFromRole)
    if (typeof getCareerIdFromRole === 'function') {
        const autoCareerId = getCareerIdFromRole(usuario.rol);
        if (autoCareerId) {
            const carreraAuto = (carreras || []).find(c => c.id === autoCareerId);
            if (carreraAuto && Array.isArray(carreraAuto.cursos)) {
                carreraAuto.cursos.forEach(cid => { if (cid) cursosSet.add(cid); });
            }
        }
    }

    // 3. Carreras asignadas expresamente en el perfil del usuario
    if (Array.isArray(usuario.carrerasAsignadas)) {
        usuario.carrerasAsignadas.forEach(ca => {
            const caId = typeof ca === 'string' ? ca : (ca.id || '');
            const carrera = (carreras || []).find(c => c.id === caId);
            if (carrera && Array.isArray(carrera.cursos)) {
                carrera.cursos.forEach(cid => { if (cid) cursosSet.add(cid); });
            }
        });
    }

    // 4. Asignaciones directas del usuario
    if (Array.isArray(usuario.asignados)) {
        usuario.asignados.forEach(cid => { if (cid) cursosSet.add(cid); });
    }

    return Array.from(cursosSet);
}

function _calcularBrechasUsuario(usuario) {
    const todosIds = _obtenerCursosDelRol(usuario);
    const brechas = [];

    todosIds.forEach(cursoId => {
        const curso = (cursos || []).find(c => c.id === cursoId);
        if (!curso) return;

        const prog = _getProgresoCurso(usuario, cursoId);
        const completadas = Array.isArray(prog.leccionesCompletadas) ? prog.leccionesCompletadas : [];
        const modulosAprobados = Array.isArray(prog.modulosAprobados) ? prog.modulosAprobados : [];
        const certificado = Array.isArray(usuario.certificadosCurso) && usuario.certificadosCurso.includes(cursoId);

        const totalModulos = (curso.modulos || []).length;
        if (certificado || (totalModulos > 0 && modulosAprobados.length >= totalModulos)) return; // Curso completado

        const modsFaltantes = [];
        (curso.modulos || []).forEach((mod, modIdx) => {
            const modKey = mod.id || `mod_${modIdx}`;
            const modAprobado = modulosAprobados.includes(modKey) || modulosAprobados.includes(String(modIdx));

            const lecsFaltantes = [];
            (mod.lecciones || []).forEach((les, lesIdx) => {
                const lesKey  = les.id  || `les_${lesIdx}`;
                const lesKeys = [
                    `${cursoId}_${modKey}_${lesKey}`,
                    `${cursoId}_${modIdx}_${lesIdx}`,
                    lesKey,
                    String(lesIdx)
                ];
                const completada = completadas.some(id => lesKeys.some(k => String(id) === k || String(id).includes(lesKey)));
                if (!completada) {
                    lecsFaltantes.push({ titulo: les.titulo || les.nombre || `Lección ${lesIdx + 1}`, id: lesKey });
                }
            });

            if (!modAprobado || lecsFaltantes.length > 0) {
                modsFaltantes.push({
                    titulo: mod.titulo || mod.nombre || `Módulo ${modIdx + 1}`,
                    aprobado: modAprobado,
                    leccionesFaltantes: lecsFaltantes
                });
            }
        });

        const pct = _porcentajeCurso(usuario, curso);
        brechas.push({
            cursoId,
            titulo: curso.titulo || curso.nombre || cursoId,
            porcentaje: pct,
            estado: pct === 0 ? 'sin-iniciar' : 'en-progreso',
            modulos: modsFaltantes
        });
    });

    return brechas;
}

function _esCursoCompletado(usuario, cursoId) {
    if (Array.isArray(usuario.certificadosCurso) && usuario.certificadosCurso.includes(cursoId)) return true;
    const cursoObj = (cursos || []).find(c => c.id === cursoId);
    if (!cursoObj) return false;
    const prog = _getProgresoCurso(usuario, cursoId);
    const modulosAprobados = Array.isArray(prog.modulosAprobados) ? prog.modulosAprobados : [];
    const totalModulos = (cursoObj.modulos || []).length;
    return totalModulos > 0 && modulosAprobados.length >= totalModulos;
}

// ============================================================
// VISTA 1: TOP LEARNERS (tabla rediseñada)
// ============================================================

function renderTopLearners() {
    const container = document.getElementById('tabla-top-learners');
    if (!container) return;

    const usuariosActivos = (usuarios || []).filter(u => u.rol !== 'admin' && u.estado !== 'suspendido');

    const datos = usuariosActivos.map(u => {
        const cursosRolIds = _obtenerCursosDelRol(u);
        const totalCursos = cursosRolIds.length;
        const completadosCursos = cursosRolIds.filter(cid => _esCursoCompletado(u, cid)).length;
        const pctCursos = totalCursos > 0 ? Math.round((completadosCursos / totalCursos) * 100) : 0;

        let totalModulos = 0;
        let modulosAprobadosCount = 0;
        let totalLecciones = 0;
        let leccionesCompletadasCount = 0;
        let sumaCalificaciones = 0;
        let totalEvaluaciones = 0;
        let totalIntentos = 0;

        const uProg = u.progreso || {};
        const todosCursosIds = [...new Set([...cursosRolIds, ...Object.keys(uProg)])];

        todosCursosIds.forEach(cursoId => {
            const cursoObj = (cursos || []).find(c => c.id === cursoId);
            const progCurso = uProg[cursoId] || {};

            if (cursoObj && Array.isArray(cursoObj.modulos)) {
                totalModulos += cursoObj.modulos.length;
                const modsAprob = Array.isArray(progCurso.modulosAprobados) ? progCurso.modulosAprobados.length : 0;
                modulosAprobadosCount += Math.min(modsAprob, cursoObj.modulos.length);

                cursoObj.modulos.forEach(mod => {
                    const lecs = Array.isArray(mod.lecciones) ? mod.lecciones : [];
                    totalLecciones += lecs.length;
                });

                const lecsComp = Array.isArray(progCurso.leccionesCompletadas) ? progCurso.leccionesCompletadas.length : 0;
                leccionesCompletadasCount += Math.min(lecsComp, totalLecciones);
            }

            const evals = progCurso.evaluaciones || {};
            if (typeof evals === 'object') {
                Object.values(evals).forEach(ev => {
                    if (ev && typeof ev.calificacion === 'number') {
                        sumaCalificaciones += ev.calificacion;
                        totalEvaluaciones++;
                    }
                });
            }

            const intentos = progCurso.intentos || {};
            if (typeof intentos === 'object') {
                Object.values(intentos).forEach(val => {
                    totalIntentos += (parseInt(val) || 0);
                });
            }
        });

        const pctModulos = totalModulos > 0 ? Math.round((modulosAprobadosCount / totalModulos) * 100) : 0;
        const pctLecciones = totalLecciones > 0 ? Math.round((leccionesCompletadasCount / totalLecciones) * 100) : 0;
        const promedioEvals = totalEvaluaciones > 0 ? (sumaCalificaciones / totalEvaluaciones).toFixed(1) : "0.0";
        const tasaCompletitud = Math.round((pctModulos + pctLecciones + pctCursos) / 3);

        return {
            usuario: u,
            totalCursos,
            completadosCursos,
            pctCursos,
            totalModulos,
            modulosAprobadosCount,
            pctModulos,
            totalLecciones,
            leccionesCompletadasCount,
            pctLecciones,
            promedioEvals,
            totalIntentos,
            tasaCompletitud
        };
    }).sort((a, b) => b.tasaCompletitud - a.tasaCompletitud || b.completadosCursos - a.completadosCursos);

    if (datos.length === 0) {
        container.innerHTML = `<tr><td colspan="9" class="text-center text-muted py-4"><i class="bi bi-people fs-2 d-block mb-2"></i>Sin colaboradores registrados.</td></tr>`;
        return;
    }

    const getColorPct = pct => pct >= 70 ? 'success' : pct >= 40 ? 'warning' : 'danger';
    const getMedal = (idx) => idx === 0 ? '🥇' : idx === 1 ? '🥈' : idx === 2 ? '🥉' : `#${idx + 1}`;

    container.innerHTML = `
        <thead>
            <tr class="report-thead">
                <th style="width:40px;">#</th>
                <th>Usuario</th>
                <th>Rol / Cargo</th>
                <th class="text-center">Cursos (Rol)</th>
                <th style="min-width:130px;">Módulos <small class="text-light opacity-75 fw-normal">(aprob/total)</small></th>
                <th style="min-width:130px;">Lecciones <small class="text-light opacity-75 fw-normal">(complet/total)</small></th>
                <th class="text-center">Prom. Evals</th>
                <th class="text-center">Intentos</th>
                <th class="text-center">Tasa Completitud</th>
            </tr>
        </thead>
        <tbody>
        ${datos.map((d, i) => {
            const initials = (d.usuario.nombre || '?').split(' ').slice(0, 2).map(n => n[0]).join('').toUpperCase();
            const rolObj = (rolesConfig || []).find(r => r.id === d.usuario.rol);
            const rolNombre = rolObj ? rolObj.nombre : d.usuario.rol;
            const colorMod = getColorPct(d.pctModulos);
            const colorLec = getColorPct(d.pctLecciones);
            const colorTasa = getColorPct(d.tasaCompletitud);

            return `
            <tr class="report-row">
                <td><span class="rank-badge">${getMedal(i)}</span></td>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div class="user-avatar" style="font-size:0.75rem;">${initials}</div>
                        <div>
                            <div class="fw-semibold">${d.usuario.nombre}</div>
                            <div class="text-muted small">${d.usuario.id} ${d.usuario.estado === 'suspendido' || d.usuario.estado === 'inactivo' ? '<span class="badge bg-secondary ms-1">Inactivo</span>' : ''}</div>
                        </div>
                    </div>
                </td>
                <td><span class="badge bg-light text-dark border small">${rolNombre}</span></td>
                <td class="text-center" style="min-width:120px;">
                    <div class="d-inline-block text-start w-100">
                        <div class="d-flex justify-content-between align-items-center small mb-1">
                            <span class="fw-bold text-dark">${d.completadosCursos}/${d.totalCursos}</span>
                            <span class="text-${getColorPct(d.pctCursos)} fw-bold" style="font-size:0.85rem;">${d.pctCursos}%</span>
                        </div>
                        <div class="progress" style="height: 5px; background: #e2e8f0;">
                            <div class="progress-bar bg-${getColorPct(d.pctCursos)}" style="width: ${d.pctCursos}%"></div>
                        </div>
                    </div>
                </td>
                <td style="min-width:130px;">
                    <div class="d-flex justify-content-between align-items-center small mb-1">
                        <span class="fw-bold text-dark">${d.modulosAprobadosCount}/${d.totalModulos}</span>
                        <span class="text-${colorMod} fw-bold" style="font-size:0.85rem;">${d.pctModulos}%</span>
                    </div>
                    <div class="progress" style="height: 5px; background: #e2e8f0;">
                        <div class="progress-bar bg-${colorMod}" style="width: ${d.pctModulos}%"></div>
                    </div>
                </td>
                <td style="min-width:130px;">
                    <div class="d-flex justify-content-between align-items-center small mb-1">
                        <span class="fw-bold text-dark">${d.leccionesCompletadasCount}/${d.totalLecciones}</span>
                        <span class="text-${colorLec} fw-bold" style="font-size:0.85rem;">${d.pctLecciones}%</span>
                    </div>
                    <div class="progress" style="height: 5px; background: #e2e8f0;">
                        <div class="progress-bar bg-${colorLec}" style="width: ${d.pctLecciones}%"></div>
                    </div>
                </td>
                <td class="text-center">
                    <span class="fw-bold fs-6 ${parseFloat(d.promedioEvals) >= 70 ? 'text-success' : parseFloat(d.promedioEvals) >= 60 ? 'text-warning' : 'text-danger'}">
                        ${d.promedioEvals}
                    </span>
                </td>
                <td class="text-center">
                    <span class="badge bg-secondary opacity-75">${d.totalIntentos}</span>
                </td>
                <td class="text-center">
                    <span class="badge badge-soft-${colorTasa} fs-6 fw-bold px-3 py-1">
                        ${d.tasaCompletitud}%
                    </span>
                </td>
            </tr>`;
        }).join('')}
        </tbody>`;
}

function _calcularTasaUsuario(u) {
    const cursosRolIds = _obtenerCursosDelRol(u);
    const totalCursos = cursosRolIds.length;
    if (totalCursos === 0) return 0;

    const completadosCursos = cursosRolIds.filter(cid => _esCursoCompletado(u, cid)).length;
    const pctCursos = Math.round((completadosCursos / totalCursos) * 100);

    let totalModulos = 0;
    let modulosAprobadosCount = 0;
    let totalLecciones = 0;
    let leccionesCompletadasCount = 0;

    const uProg = u.progreso || {};
    cursosRolIds.forEach(cursoId => {
        const cursoObj = (cursos || []).find(c => c.id === cursoId);
        const progCurso = uProg[cursoId] || {};

        if (cursoObj && Array.isArray(cursoObj.modulos)) {
            totalModulos += cursoObj.modulos.length;
            const modsAprob = Array.isArray(progCurso.modulosAprobados) ? progCurso.modulosAprobados.length : 0;
            modulosAprobadosCount += Math.min(modsAprob, cursoObj.modulos.length);

            let cursoLecs = 0;
            cursoObj.modulos.forEach(mod => {
                const lecs = Array.isArray(mod.lecciones) ? mod.lecciones : [];
                cursoLecs += lecs.length;
            });
            totalLecciones += cursoLecs;

            const lecsComp = Array.isArray(progCurso.leccionesCompletadas) ? progCurso.leccionesCompletadas.length : 0;
            leccionesCompletadasCount += Math.min(lecsComp, cursoLecs);
        }
    });

    const pctModulos = totalModulos > 0 ? Math.round((modulosAprobadosCount / totalModulos) * 100) : 0;
    const pctLecciones = totalLecciones > 0 ? Math.round((leccionesCompletadasCount / totalLecciones) * 100) : 0;

    return Math.round((pctCursos + pctModulos + pctLecciones) / 3);
}

// ============================================================
// VISTA 2: CUMPLIMIENTO POR CARGO (chart mejorado)
// ============================================================

function renderCumplimientoCargo() {
    const container = document.getElementById('chart-cumplimiento');
    if (!container) return;

    const rolesValidos = (rolesConfig || []).filter(r => r.id !== 'admin');
    if (rolesValidos.length === 0) { container.innerHTML = '<p class="text-muted small text-center py-3">Sin roles configurados.</p>'; return; }

    const stats = rolesValidos.map(rol => {
        const miembros = (usuarios || []).filter(u => {
            if (u.estado === 'suspendido' || u.rol === 'admin') return false;
            const uRolStr = String(u.rol || '').toLowerCase().trim();
            const rIdStr  = String(rol.id || '').toLowerCase().trim();
            const rNomStr = String(rol.nombre || '').toLowerCase().trim();
            return uRolStr === rIdStr || uRolStr === rNomStr;
        });

        if (miembros.length === 0) return { nombre: rol.nombre, promedio: 0, count: 0 };

        const sumaTasas = miembros.reduce((sum, u) => sum + _calcularTasaUsuario(u), 0);
        const promedio = Math.round(sumaTasas / miembros.length);

        return { nombre: rol.nombre, promedio, count: miembros.length };
    }).filter(s => s.count > 0);

    if (stats.length === 0) { container.innerHTML = '<p class="text-muted small text-center py-3">Sin datos de cumplimiento.</p>'; return; }

    const colores = ['#0284c7', '#9333ea', '#10b981', '#f59e0b', '#ef4444', '#06b6d4'];

    container.innerHTML = `
        <div class="d-flex flex-column gap-3">
        ${stats.map((s, i) => `
            <div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="small fw-semibold text-dark">${s.nombre}</span>
                    <span class="small fw-bold text-primary">${s.promedio}% <span class="text-muted fw-normal">(${s.count})</span></span>
                </div>
                <div class="progress" style="height: 8px; background: #e2e8f0; border-radius: 4px;">
                    <div class="progress-bar" style="width:${s.promedio}%; background:${colores[i % colores.length]}; border-radius: 4px; transition: width 0.6s ease;"></div>
                </div>
            </div>`).join('')}
        </div>`;
}

// ============================================================
// VISTA 3: BRECHAS DE APRENDIZAJE (árbol expandible)
// ============================================================

function renderBrechasAprendizaje() {
    const container = document.getElementById('brechas-container');
    if (!container) return;

    const filtroId = document.getElementById('filtro-usuario-brechas')?.value || '';
    const usuariosTarget = filtroId
        ? (usuarios || []).filter(u => u.id === filtroId)
        : (usuarios || []).filter(u => u.rol !== 'admin' && u.estado !== 'suspendido');

    if (usuariosTarget.length === 0) {
        container.innerHTML = `<div class="text-center text-muted py-5"><i class="bi bi-person-x fs-2 d-block mb-2"></i>No hay colaboradores para mostrar.</div>`;
        return;
    }

    let html = '';
    let totalBrechas = 0;

    usuariosTarget.forEach(u => {
        const brechas = _calcularBrechasUsuario(u);
        totalBrechas += brechas.length;

        const initials = (u.nombre || '?').split(' ').slice(0, 2).map(n => n[0]).join('').toUpperCase();
        const rolObj = (rolesConfig || []).find(r => r.id === u.rol);
        const rolNombre = rolObj ? rolObj.nombre : u.rol;
        const cursosFaltantes = brechas.length;
        const cursosTotal = (Array.isArray(u.asignados) ? u.asignados : []).length;

        html += `
        <div class="brecha-user-card mb-3">
            <div class="brecha-user-header" onclick="this.parentElement.classList.toggle('open')">
                <div class="d-flex align-items-center gap-3">
                    <div class="user-avatar">${initials}</div>
                    <div>
                        <div class="fw-bold">${u.nombre}</div>
                        <div class="text-muted small">${rolNombre} • ID: ${u.id}</div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-3">
                    ${cursosFaltantes === 0
                        ? `<span class="badge-brecha completado"><i class="bi bi-check-circle-fill me-1"></i>Al día</span>`
                        : `<span class="badge-brecha pendiente"><i class="bi bi-exclamation-circle-fill me-1"></i>${cursosFaltantes} curso${cursosFaltantes !== 1 ? 's' : ''} pendiente${cursosFaltantes !== 1 ? 's' : ''}</span>`}
                    <i class="bi bi-chevron-down brecha-chevron"></i>
                </div>
            </div>
            <div class="brecha-user-body">
                ${cursosFaltantes === 0
                    ? `<div class="brecha-empty"><i class="bi bi-trophy-fill text-warning me-2"></i>Este colaborador ha completado todos sus cursos asignados.</div>`
                    : brechas.map(brecha => `
                    <div class="brecha-curso-item">
                        <details class="brecha-details">
                            <summary class="brecha-curso-summary">
                                <div class="d-flex align-items-center gap-2 flex-grow-1">
                                    <i class="bi bi-journal-bookmark-fill text-primary"></i>
                                    <span class="fw-semibold">${brecha.titulo}</span>
                                    <span class="brecha-estado-badge ${brecha.estado}">
                                        ${brecha.estado === 'sin-iniciar' ? '⏳ Sin iniciar' : `🔄 ${brecha.porcentaje}%`}
                                    </span>
                                </div>
                                <i class="bi bi-chevron-right brecha-summary-icon"></i>
                            </summary>
                            <div class="brecha-modulos-list">
                                ${brecha.modulos.length === 0
                                    ? `<div class="brecha-leaf text-muted"><i class="bi bi-check-circle me-1 text-success"></i>Todos los módulos están en progreso o aprobados.</div>`
                                    : brecha.modulos.map(mod => `
                                    <details class="brecha-details ms-3">
                                        <summary class="brecha-modulo-summary">
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="bi bi-stack text-purple"></i>
                                                <span>${mod.titulo}</span>
                                                ${mod.aprobado ? `<span class="badge bg-success bg-opacity-10 text-success small">Aprobado</span>` : `<span class="badge bg-warning bg-opacity-10 text-warning small">Pendiente</span>`}
                                            </div>
                                            <i class="bi bi-chevron-right brecha-summary-icon"></i>
                                        </summary>
                                        <div class="brecha-lecciones-list ms-3">
                                            ${mod.leccionesFaltantes.length === 0
                                                ? `<div class="brecha-leaf text-success"><i class="bi bi-check2-all me-1"></i>Todas las lecciones completadas</div>`
                                                : mod.leccionesFaltantes.map(les => `
                                                <div class="brecha-leaf">
                                                    <i class="bi bi-play-circle text-muted me-2"></i>${les.titulo}
                                                    <span class="badge bg-danger bg-opacity-10 text-danger small ms-2">Falta</span>
                                                </div>`).join('')}
                                        </div>
                                    </details>`).join('')}
                            </div>
                        </details>
                    </div>`).join('')}
            </div>
        </div>`;
    });

    container.innerHTML = html;

    // Actualizar contador
    const counter = document.getElementById('brechas-total-count');
    if (counter) {
        const totalUsuariosConBrechas = usuariosTarget.filter(u => _calcularBrechasUsuario(u).length > 0).length;
        counter.textContent = `${totalUsuariosConBrechas} colaborador${totalUsuariosConBrechas !== 1 ? 'es' : ''} con brechas`;
    }
}

// ============================================================
// INICIALIZACIÓN DEL SELECTOR DE USUARIOS
// ============================================================

function inicializarFiltroUsuariosBrechas() {
    const sel = document.getElementById('filtro-usuario-brechas');
    if (!sel) return;
    const prev = sel.value;
    sel.innerHTML = `<option value="">— Todos los colaboradores —</option>` +
        (usuarios || [])
            .filter(u => u.rol !== 'admin')
            .map(u => `<option value="${u.id}" ${u.id === prev ? 'selected' : ''}>${u.nombre}</option>`)
            .join('');
}

// ============================================================
// EXPORTAR REPORTE EN EXCEL (XLSX)
// ============================================================

function exportarReporteXLSX() {
    if (typeof XLSX === 'undefined') {
        if (typeof showToast === 'function') {
            showToast('Cargando motor de exportación Excel, reintenta en un momento...', 'warning');
        } else {
            alert('Cargando motor de exportación Excel, reintenta en un momento...');
        }
        return;
    }

    const usuariosActivos = (usuarios || []).filter(u => u.rol !== 'admin' && u.estado !== 'suspendido');

    // 1. Datos para Hoja Top Learners (Desempeño General)
    const dataLearners = usuariosActivos.map((u) => {
        const cursosRolIds = _obtenerCursosDelRol(u);
        const totalCursos = cursosRolIds.length;
        const completadosCursos = cursosRolIds.filter(cid => _esCursoCompletado(u, cid)).length;
        const pctCursos = totalCursos > 0 ? Math.round((completadosCursos / totalCursos) * 100) : 0;

        let totalModulos = 0;
        let modulosAprobadosCount = 0;
        let totalLecciones = 0;
        let leccionesCompletadasCount = 0;
        let sumaCalificaciones = 0;
        let totalEvaluaciones = 0;
        let totalIntentos = 0;

        const uProg = u.progreso || {};
        const todosCursosIds = [...new Set([...cursosRolIds, ...Object.keys(uProg)])];

        todosCursosIds.forEach(cursoId => {
            const cursoObj = (cursos || []).find(c => c.id === cursoId);
            const progCurso = uProg[cursoId] || {};

            if (cursoObj && Array.isArray(cursoObj.modulos)) {
                totalModulos += cursoObj.modulos.length;
                const modsAprob = Array.isArray(progCurso.modulosAprobados) ? progCurso.modulosAprobados.length : 0;
                modulosAprobadosCount += Math.min(modsAprob, cursoObj.modulos.length);

                cursoObj.modulos.forEach(mod => {
                    const lecs = Array.isArray(mod.lecciones) ? mod.lecciones : [];
                    totalLecciones += lecs.length;
                });

                const lecsComp = Array.isArray(progCurso.leccionesCompletadas) ? progCurso.leccionesCompletadas.length : 0;
                leccionesCompletadasCount += Math.min(lecsComp, totalLecciones);
            }

            const evals = progCurso.evaluaciones || {};
            if (typeof evals === 'object') {
                Object.values(evals).forEach(ev => {
                    if (ev && typeof ev.calificacion === 'number') {
                        sumaCalificaciones += ev.calificacion;
                        totalEvaluaciones++;
                    }
                });
            }

            const intentos = progCurso.intentos || {};
            if (typeof intentos === 'object') {
                Object.values(intentos).forEach(val => {
                    totalIntentos += (parseInt(val) || 0);
                });
            }
        });

        const pctModulos = totalModulos > 0 ? Math.round((modulosAprobadosCount / totalModulos) * 100) : 0;
        const pctLecciones = totalLecciones > 0 ? Math.round((leccionesCompletadasCount / totalLecciones) * 100) : 0;
        const promedioEvals = totalEvaluaciones > 0 ? (sumaCalificaciones / totalEvaluaciones).toFixed(1) : "0.0";
        const tasaCompletitud = Math.round((pctModulos + pctLecciones + pctCursos) / 3);
        const rolObj = (rolesConfig || []).find(r => r.id === u.rol);

        return {
            'Posición': 0,
            'Cédula / ID': u.id,
            'Nombre del Colaborador': u.nombre,
            'Rol / Cargo': rolObj ? rolObj.nombre : u.rol,
            'Estado': (u.estado || 'activo').toUpperCase(),
            'Cursos Completados (Rol)': `${completadosCursos} / ${totalCursos}`,
            'Progreso Cursos (%)': `${pctCursos}%`,
            'Módulos Aprobados': `${modulosAprobadosCount} / ${totalModulos}`,
            'Progreso Módulos (%)': `${pctModulos}%`,
            'Lecciones Completadas': `${leccionesCompletadasCount} / ${totalLecciones}`,
            'Progreso Lecciones (%)': `${pctLecciones}%`,
            'Promedio Evaluaciones': promedioEvals,
            'Intentos Totales': totalIntentos,
            'Tasa Completitud General (%)': `${tasaCompletitud}%`
        };
    }).sort((a, b) => parseInt(b['Tasa Completitud General (%)']) - parseInt(a['Tasa Completitud General (%)']));

    // Reasignar posiciones ordenadas por promedio
    dataLearners.forEach((row, i) => row['Posición'] = i + 1);

    // 2. Datos para Hoja Brechas de Aprendizaje Detalladas
    const dataBrechas = [];
    usuariosActivos.forEach(u => {
        const brechas = _calcularBrechasUsuario(u);
        const rolObj = (rolesConfig || []).find(r => r.id === u.rol);
        const rolNombre = rolObj ? rolObj.nombre : u.rol;

        if (brechas.length === 0) {
            dataBrechas.push({
                'Cédula / ID': u.id,
                'Colaborador': u.nombre,
                'Cargo': rolNombre,
                'Curso': 'Todos los cursos asignados al día',
                'Estado Curso': 'COMPLETADO',
                '% Avance': '100%',
                'Módulos Pendientes': 'Ninguno',
                'Lecciones Faltantes': 'Ninguna'
            });
        } else {
            brechas.forEach(b => {
                const modsText = b.modulos.map(m => m.titulo).join('; ') || 'Sin módulos pendientes';
                const lecsText = b.modulos.flatMap(m => m.leccionesFaltantes.map(l => `${m.titulo}: ${l.titulo}`)).join(' | ') || 'Sin lecciones pendientes';
                dataBrechas.push({
                    'Cédula / ID': u.id,
                    'Colaborador': u.nombre,
                    'Cargo': rolNombre,
                    'Curso': b.titulo,
                    'Estado Curso': b.estado === 'sin-iniciar' ? 'SIN INICIAR' : 'EN PROGRESO',
                    '% Avance': `${b.porcentaje}%`,
                    'Módulos Pendientes': modsText,
                    'Lecciones Faltantes': lecsText
                });
            });
        }
    });

    try {
        const wb = XLSX.utils.book_new();

        const wsLearners = XLSX.utils.json_to_sheet(dataLearners);
        const wsBrechas  = XLSX.utils.json_to_sheet(dataBrechas);

        // Anchos de columna
        wsLearners['!cols'] = [
            { wch: 10 }, { wch: 16 }, { wch: 28 }, { wch: 22 },
            { wch: 12 }, { wch: 18 }, { wch: 20 }, { wch: 22 }, { wch: 22 }
        ];

        wsBrechas['!cols'] = [
            { wch: 16 }, { wch: 28 }, { wch: 22 }, { wch: 32 },
            { wch: 16 }, { wch: 12 }, { wch: 35 }, { wch: 50 }
        ];

        XLSX.utils.book_append_sheet(wb, wsLearners, "Top Learners (Desempeño)");
        XLSX.utils.book_append_sheet(wb, wsBrechas, "Brechas de Aprendizaje");

        const hoy = new Date().toISOString().slice(0, 10);
        XLSX.writeFile(wb, `Reporte_Academico_Universidad_Aluminio_${hoy}.xlsx`);

        if (typeof showToast === 'function') {
            showToast('📊 Reporte XLSX exportado con éxito.', 'success');
        }
    } catch (err) {
        console.error('Error exportando XLSX:', err);
        if (typeof showToast === 'function') {
            showToast('Error al exportar reporte Excel: ' + err.message, 'danger');
        }
    }
}

// ============================================================
// REPORTE DETALLADO DE EVALUACIONES (RELACIONAL)
// ============================================================

let _evaluacionesRaw = [];
let _evaluacionesFiltradas = [];
let _usuariosAgrupados = [];
let _modoVistaEval = 'agrupada'; // 'agrupada' o 'plana'
let _evalCurrentPage = 1;
let _evalPerPage = 15;

/**
 * Recopila todas las evaluaciones de la base de datos relacional/memoria
 */
function _obtenerTodasEvaluaciones() {
    const lista = [];
    (usuarios || []).forEach(u => {
        const uProg = u.progreso || {};
        const rolObj = (rolesConfig || []).find(r => r.id === u.rol);
        const rolNombre = rolObj ? rolObj.nombre : (u.rol || 'Participante');

        // Iterar únicamente sobre los cursos en los que el colaborador tiene registro de progreso
        Object.keys(uProg).forEach(cursoId => {
            const progCurso = uProg[cursoId] || {};
            const cursoObj = (cursos || []).find(c => c.id === cursoId);
            const cursoTitulo = cursoObj ? (cursoObj.titulo || cursoId) : cursoId;

            // Manejar evaluaciones e intentos de forma robusta (array u objeto)
            const rawEvals = progCurso.evaluaciones || {};
            const evals = {};
            if (Array.isArray(rawEvals)) {
                rawEvals.forEach((ev, idx) => {
                    if (ev && typeof ev === 'object') evals[String(idx)] = { ...ev };
                });
            } else if (typeof rawEvals === 'object') {
                Object.keys(rawEvals).forEach(k => {
                    if (rawEvals[k] && typeof rawEvals[k] === 'object') evals[String(k)] = { ...rawEvals[k] };
                });
            }

            const rawIntentos = progCurso.intentos || {};
            const intentosObj = {};
            if (Array.isArray(rawIntentos)) {
                rawIntentos.forEach((val, idx) => {
                    if (val != null) intentosObj[String(idx)] = val;
                });
            } else if (typeof rawIntentos === 'object') {
                Object.assign(intentosObj, rawIntentos);
            }

            const modsAprobados = Array.isArray(progCurso.modulosAprobados) ? progCurso.modulosAprobados : [];

            // Normalización estricta:
            // 1. SÓLO para módulos efectivamente APROBADOS por el usuario (en modsAprobados).
            // 2. Si el módulo aprobado NO tiene evaluación en evals, se normaliza en 100%.
            // 3. Si el módulo aprobado ya tiene evaluación cargada, se deja tal cual (no se modifica su nota).
            // 4. Los módulos por cursar (no aprobados) NUNCA se cargan; quedan sin calificación.
            modsAprobados.forEach(modRef => {
                const modNumStr = String(modRef);
                const modIdx = parseInt(modRef);
                const yaTieneEval = !!(evals[modNumStr] || (!isNaN(modIdx) && evals[modIdx]));

                if (!yaTieneEval) {
                    evals[modNumStr] = {
                        calificacion: 100,
                        aprobado: true,
                        marcadoManual: true,
                        fecha: ''
                    };
                }
            });

            Object.keys(evals).forEach(modNum => {
                const ev = evals[modNum];
                if (!ev || typeof ev !== 'object') return;

                const modIdx = parseInt(modNum);
                const modNumStr = String(modNum);

                // No incluir evaluaciones artificiales si el módulo no está aprobado (módulos por cursar)
                const estaAprobado = modsAprobados.includes(modNumStr) || (!isNaN(modIdx) && modsAprobados.includes(String(modIdx))) || (!isNaN(modIdx) && modsAprobados.includes(modIdx));
                if (!estaAprobado && ev.marcadoManual && ev.calificacion === 100) {
                    return;
                }

                let modTitulo = `Módulo ${modIdx + 1}`;
                if (cursoObj && Array.isArray(cursoObj.modulos) && cursoObj.modulos[modIdx]) {
                    modTitulo = `Módulo ${modIdx + 1}: ${cursoObj.modulos[modIdx].titulo}`;
                }

                const calif = typeof ev.calificacion === 'number' ? ev.calificacion : (typeof ev.nota === 'number' ? ev.nota : 0);
                const aprobado = typeof ev.aprobado === 'boolean' ? ev.aprobado : (calif >= 70);
                const marcadoManual = !!ev.marcadoManual;
                const intentos = parseInt(intentosObj[modNum]) || 1;
                const fecha = ev.fecha || '';
                const fechaTimestamp = fecha ? new Date(fecha).getTime() : 0;

                lista.push({
                    usuarioId: u.id,
                    usuarioNombre: u.nombre,
                    usuarioRol: u.rol,
                    rolNombre: rolNombre,
                    cursoId: cursoId,
                    cursoTitulo: cursoTitulo,
                    moduloNum: isNaN(modIdx) ? modNum : modIdx,
                    moduloTitulo: modTitulo,
                    calificacion: calif,
                    aprobado: aprobado,
                    marcadoManual: marcadoManual,
                    intentos: intentos,
                    fecha: fecha,
                    fechaTimestamp: isNaN(fechaTimestamp) ? 0 : fechaTimestamp
                });
            });
        });
    });
    return lista;
}

/**
 * Agrupa una lista plana de evaluaciones por cada colaborador
 * y ordena los módulos de cada colaborador por curso y por módulo
 */
function _agruparEvaluacionesPorUsuario(evaluaciones) {
    const map = new Map();
    evaluaciones.forEach(ev => {
        if (!map.has(ev.usuarioId)) {
            map.set(ev.usuarioId, {
                usuarioId: ev.usuarioId,
                usuarioNombre: ev.usuarioNombre,
                usuarioRol: ev.usuarioRol,
                rolNombre: ev.rolNombre,
                evaluaciones: [],
                totalModulos: 0,
                aprobados: 0,
                reprobados: 0,
                sumaNotas: 0,
                promedio: 0,
                tasaAprob: 0,
                ultimaFecha: '',
                ultimaFechaTimestamp: 0
            });
        }
        const u = map.get(ev.usuarioId);
        u.evaluaciones.push(ev);
        u.totalModulos++;
        if (ev.aprobado) u.aprobados++;
        else u.reprobados++;
        u.sumaNotas += ev.calificacion;
        if (ev.fechaTimestamp > u.ultimaFechaTimestamp) {
            u.ultimaFechaTimestamp = ev.fechaTimestamp;
            u.ultimaFecha = ev.fecha;
        }
    });

    const arr = Array.from(map.values());
    arr.forEach(u => {
        // Ordenar las calificaciones de cada usuario por curso y por módulo
        u.evaluaciones.sort((a, b) => {
            const cmpC = (a.cursoTitulo || '').localeCompare(b.cursoTitulo || '');
            if (cmpC !== 0) return cmpC;
            return (parseInt(a.moduloNum) || 0) - (parseInt(b.moduloNum) || 0);
        });
        u.promedio = u.totalModulos > 0 ? parseFloat((u.sumaNotas / u.totalModulos).toFixed(1)) : 0;
        u.tasaAprob = u.totalModulos > 0 ? Math.round((u.aprobados / u.totalModulos) * 100) : 0;
    });

    return arr;
}

/**
 * Ordena la lista de colaboradores agrupados según el filtro de orden activo
 */
function _ordenarUsuariosAgrupados(usuariosArr, ordenVal) {
    usuariosArr.sort((a, b) => {
        if (ordenVal === 'calif_desc') return b.promedio - a.promedio;
        if (ordenVal === 'calif_asc')  return a.promedio - b.promedio;
        if (ordenVal === 'fecha_desc') return b.ultimaFechaTimestamp - a.ultimaFechaTimestamp;
        if (ordenVal === 'curso_asc')  return a.usuarioNombre.localeCompare(b.usuarioNombre);
        // Default o nombre_asc: Por colaborador A-Z
        return a.usuarioNombre.localeCompare(b.usuarioNombre);
    });
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

/**
 * Inicializa selectores dinámicos de colaboradores y cursos para el filtro
 */
function inicializarFiltrosEvaluaciones() {
    const selU = document.getElementById('filtro-eval-usuario');
    if (selU) {
        const prevU = selU.value;
        const usersList = (usuarios || []).filter(u => u.rol !== 'admin');
        selU.innerHTML = `<option value="">— Colaborador —</option>` +
            usersList.map(u => `<option value="${u.id}" ${u.id === prevU ? 'selected' : ''}>${u.nombre} (${u.id})</option>`).join('');
    }

    const selC = document.getElementById('filtro-eval-curso');
    if (selC) {
        const prevC = selC.value;
        selC.innerHTML = `<option value="">— Curso —</option>` +
            (cursos || []).map(c => `<option value="${c.id}" ${c.id === prevC ? 'selected' : ''}>${c.titulo}</option>`).join('');
    }
}

/**
 * Aplica los filtros seleccionados en la UI y actualiza la vista
 */
function filtrarTablaEvaluaciones() {
    const searchVal = (document.getElementById('filtro-eval-search')?.value || '').toLowerCase().trim();
    const userVal   = document.getElementById('filtro-eval-usuario')?.value || '';
    const cursoVal  = document.getElementById('filtro-eval-curso')?.value || '';
    const estadoVal = document.getElementById('filtro-eval-estado')?.value || '';
    const ordenVal  = document.getElementById('filtro-eval-orden')?.value || 'nombre_asc';

    _evaluacionesFiltradas = _evaluacionesRaw.filter(item => {
        if (userVal && item.usuarioId !== userVal) return false;
        if (cursoVal && item.cursoId !== cursoVal) return false;
        if (estadoVal === 'aprobado' && !item.aprobado) return false;
        if (estadoVal === 'reprobado' && item.aprobado) return false;
        if (estadoVal === 'manual' && !item.marcadoManual) return false;

        if (searchVal) {
            const matchSearch = item.usuarioNombre.toLowerCase().includes(searchVal) ||
                                item.usuarioId.toLowerCase().includes(searchVal) ||
                                item.cursoTitulo.toLowerCase().includes(searchVal) ||
                                item.moduloTitulo.toLowerCase().includes(searchVal) ||
                                item.rolNombre.toLowerCase().includes(searchVal);
            if (!matchSearch) return false;
        }
        return true;
    });

    // Ordenamiento lista plana
    _evaluacionesFiltradas.sort((a, b) => {
        if (ordenVal === 'calif_desc') return b.calificacion - a.calificacion;
        if (ordenVal === 'calif_asc')  return a.calificacion - b.calificacion;
        if (ordenVal === 'fecha_desc') return b.fechaTimestamp - a.fechaTimestamp;
        if (ordenVal === 'curso_asc') {
            const cmpC = (a.cursoTitulo || '').localeCompare(b.cursoTitulo || '');
            if (cmpC !== 0) return cmpC;
            const cmpU = (a.usuarioNombre || '').localeCompare(b.usuarioNombre || '');
            if (cmpU !== 0) return cmpU;
            return (parseInt(a.moduloNum) || 0) - (parseInt(b.moduloNum) || 0);
        }
        // nombre_asc o por defecto: Colaborador -> Curso -> Módulo
        const cmpU = (a.usuarioNombre || '').localeCompare(b.usuarioNombre || '');
        if (cmpU !== 0) return cmpU;
        const cmpC = (a.cursoTitulo || '').localeCompare(b.cursoTitulo || '');
        if (cmpC !== 0) return cmpC;
        return (parseInt(a.moduloNum) || 0) - (parseInt(b.moduloNum) || 0);
    });

    // Agrupamiento estructurado por colaborador
    _usuariosAgrupados = _agruparEvaluacionesPorUsuario(_evaluacionesFiltradas);
    _ordenarUsuariosAgrupados(_usuariosAgrupados, ordenVal);

    _evalCurrentPage = 1;
    renderVistaEvaluaciones();
    actualizarKPIsEvaluaciones();
}

/**
 * Actualiza las tarjetas resumen de métricas (KPIs)
 */
function actualizarKPIsEvaluaciones() {
    const total = _evaluacionesFiltradas.length;
    let sumaCalif = 0;
    let totalAprob = 0;
    let totalPerfectas = 0;

    _evaluacionesFiltradas.forEach(e => {
        sumaCalif += e.calificacion;
        if (e.aprobado) totalAprob++;
        if (e.calificacion >= 100) totalPerfectas++;
    });

    const promedio = total > 0 ? (sumaCalif / total).toFixed(1) : "0.0";
    const tasaAprob = total > 0 ? Math.round((totalAprob / total) * 100) : 0;

    const elTotal = document.getElementById('kpi-eval-total');
    const elProm = document.getElementById('kpi-eval-promedio');
    const elTasa = document.getElementById('kpi-eval-tasa-aprob');
    const elPerf = document.getElementById('kpi-eval-perfectas');
    const elBadge = document.getElementById('evaluaciones-count-badge');

    if (elTotal) elTotal.textContent = total.toLocaleString();
    if (elProm)  elProm.textContent  = `${promedio} pts`;
    if (elTasa)  elTasa.textContent  = `${tasaAprob}%`;
    if (elPerf)  elPerf.textContent  = totalPerfectas.toLocaleString();

    if (elBadge) {
        if (_modoVistaEval === 'agrupada') {
            const numU = _usuariosAgrupados.length;
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
    renderVistaEvaluaciones();
    actualizarKPIsEvaluaciones();
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

    const perPage = _evalPerPage === 'all' ? _usuariosAgrupados.length : parseInt(_evalPerPage);
    const totalPaginas = Math.ceil(_usuariosAgrupados.length / perPage) || 1;
    if (_evalCurrentPage > totalPaginas) _evalCurrentPage = totalPaginas;

    const startIdx = (_evalCurrentPage - 1) * perPage;
    const endIdx   = Math.min(startIdx + perPage, _usuariosAgrupados.length);
    const usuariosPagina = _usuariosAgrupados.slice(startIdx, endIdx);

    let html = '';
    usuariosPagina.forEach((u, uIdx) => {
        const globalUserIdx = startIdx + uIdx + 1;
        const iniciales = (u.usuarioNombre || 'U').split(' ').filter(Boolean).map(p => p[0]).slice(0, 2).join('').toUpperCase();
        const badgeInfo = _obtenerBadgeCalificacion(u.promedio);

        let filasModulosHtml = '';
        u.evaluaciones.forEach((e, mIdx) => {
            const mBadgeInfo = _obtenerBadgeCalificacion(e.calificacion);

            const estadoBadgeHtml = e.aprobado
                ? `<span class="badge bg-success text-white px-2 py-1"><i class="bi bi-check-circle-fill me-1"></i>Aprobado</span>`
                : `<span class="badge bg-danger text-white px-2 py-1"><i class="bi bi-x-circle-fill me-1"></i>Reprobado</span>`;

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
                        fechaFormat = e.fecha;
                    }
                } catch (_) { fechaFormat = e.fecha; }
            } else if (e.marcadoManual) {
                fechaFormat = '<span class="text-success small fw-semibold"><i class="bi bi-patch-check-fill me-1"></i>Acreditado</span>';
            }

            filasModulosHtml += `
            <tr>
                <td class="text-muted small text-center ps-3">${mIdx + 1}</td>
                <td>
                    <div class="fw-semibold text-primary mb-0" style="max-width: 280px; white-space: normal;">
                        <i class="bi bi-book me-1 opacity-75"></i>${e.cursoTitulo}
                    </div>
                    <div class="text-muted small" style="font-size: 0.72rem;">Código: ${e.cursoId}</div>
                </td>
                <td>
                    <div class="small fw-semibold text-dark">${e.moduloTitulo}</div>
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
                    <span class="badge bg-light text-dark border px-2 py-1">
                        <i class="bi bi-arrow-repeat me-1 text-muted"></i>${e.intentos}
                    </span>
                </td>
                <td>${origenBadgeHtml}</td>
                <td class="small text-muted pe-3" style="white-space: nowrap;">
                    <i class="bi bi-calendar3 me-1 opacity-75"></i>${fechaFormat}
                </td>
            </tr>`;
        });

        html += `
        <div class="card user-eval-card shadow-sm mb-3">
            <div class="card-header user-eval-header p-3 d-flex flex-wrap align-items-center justify-content-between"
                 onclick="toggleColaboradorAcordeon('user-eval-collapse-${u.usuarioId}', this)"
                 id="user-eval-header-${u.usuarioId}"
                 role="button"
                 title="Clic para expandir u ocultar los módulos de este colaborador">
                <div class="d-flex align-items-center gap-3">
                    <div class="user-eval-avatar">
                        ${iniciales}
                    </div>
                    <div>
                        <div class="fw-bold text-dark fs-6 d-flex align-items-center gap-2 mb-1">
                            <span>${u.usuarioNombre}</span>
                            <span class="badge bg-light text-secondary border px-2 py-0" style="font-size: 0.725rem;">${u.rolNombre}</span>
                        </div>
                        <div class="text-muted small d-flex flex-wrap align-items-center gap-2">
                            <span><i class="bi bi-person-vcard me-1"></i>CI: <strong>${u.usuarioId}</strong></span>
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

            <div class="collapse show user-eval-collapse" id="user-eval-collapse-${u.usuarioId}">
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
                                <th class="pe-3">Fecha</th>
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
    actualizarControlesPaginacion(startIdx + 1, endIdx, _usuariosAgrupados.length);
}

/**
 * Dibuja las filas de la tabla PLANA con paginación
 */
function renderTablaEvaluacionesPaginada() {
    const tbody = document.getElementById('tabla-evaluaciones-body');
    if (!tbody) return;

    if (_evaluacionesFiltradas.length === 0) {
        tbody.innerHTML = `<tr><td colspan="9" class="text-center py-5 text-muted">
            <i class="bi bi-search fs-3 d-block mb-2 text-muted opacity-50"></i>
            No se encontraron evaluaciones con los filtros aplicados.
        </td></tr>`;
        actualizarControlesPaginacion(0, 0, 0);
        return;
    }

    const perPage = _evalPerPage === 'all' ? _evaluacionesFiltradas.length : parseInt(_evalPerPage);
    const totalPaginas = Math.ceil(_evaluacionesFiltradas.length / perPage) || 1;
    if (_evalCurrentPage > totalPaginas) _evalCurrentPage = totalPaginas;

    const startIdx = (_evalCurrentPage - 1) * perPage;
    const endIdx   = Math.min(startIdx + perPage, _evaluacionesFiltradas.length);
    const itemsPagina = _evaluacionesFiltradas.slice(startIdx, endIdx);

    let html = '';
    itemsPagina.forEach((e, idx) => {
        const globalIdx = startIdx + idx + 1;
        const iniciales = (e.usuarioNombre || 'U').split(' ').filter(Boolean).map(p => p[0]).slice(0, 2).join('').toUpperCase();
        const badgeInfo = _obtenerBadgeCalificacion(e.calificacion);

        const estadoBadgeHtml = e.aprobado
            ? `<span class="badge bg-success text-white shadow-sm px-2 py-1"><i class="bi bi-check-circle-fill me-1"></i>Aprobado</span>`
            : `<span class="badge bg-danger text-white shadow-sm px-2 py-1"><i class="bi bi-x-circle-fill me-1"></i>Reprobado</span>`;

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
                    fechaFormat = e.fecha;
                }
            } catch (_) { fechaFormat = e.fecha; }
        } else if (e.marcadoManual) {
            fechaFormat = '<span class="text-success small fw-semibold"><i class="bi bi-patch-check-fill me-1"></i>Acreditado</span>';
        }

        html += `
        <tr>
            <td class="text-muted small fw-semibold text-center">${globalIdx}</td>
            <td>
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle bg-primary bg-opacity-10 text-primary fw-bold d-flex align-items-center justify-content-center" style="width: 34px; height: 34px; font-size: 0.75rem; flex-shrink: 0;">
                        ${iniciales}
                    </div>
                    <div>
                        <div class="fw-bold text-dark lh-sm">${e.usuarioNombre}</div>
                        <div class="text-muted small d-flex align-items-center gap-1">
                            <span>CI: ${e.usuarioId}</span>
                            <span>•</span>
                            <span class="badge bg-light text-secondary border px-1 py-0" style="font-size: 0.7rem;">${e.rolNombre}</span>
                        </div>
                    </div>
                </div>
            </td>
            <td>
                <div class="fw-semibold text-primary mb-0" style="max-width: 240px; white-space: normal;">${e.cursoTitulo}</div>
                <div class="text-muted small" style="font-size: 0.72rem;">Código: ${e.cursoId}</div>
            </td>
            <td>
                <div class="small fw-semibold text-dark">${e.moduloTitulo}</div>
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
                <span class="badge bg-light text-dark border px-2 py-1">
                    <i class="bi bi-arrow-repeat me-1 text-muted"></i>${e.intentos}
                </span>
            </td>
            <td>${origenBadgeHtml}</td>
            <td class="small text-muted" style="white-space: nowrap;">
                <i class="bi bi-calendar3 me-1 opacity-75"></i>${fechaFormat}
            </td>
        </tr>`;
    });

    tbody.innerHTML = html;
    actualizarControlesPaginacion(startIdx + 1, endIdx, _evaluacionesFiltradas.length);
}

function actualizarControlesPaginacion(desde, hasta, total) {
    const info = document.getElementById('evaluaciones-paginacion-info');
    const btnPrev = document.getElementById('btn-eval-prev');
    const btnNext = document.getElementById('btn-eval-next');

    if (info) {
        if (total === 0) {
            info.textContent = (_modoVistaEval === 'agrupada') ? 'Mostrando 0 de 0 colaboradores' : 'Mostrando 0 de 0 evaluaciones';
        } else {
            if (_modoVistaEval === 'agrupada') {
                info.textContent = `Mostrando ${desde} a ${hasta} de ${total} colaboradores (${_evaluacionesFiltradas.length} calificaciones en total)`;
            } else {
                info.textContent = `Mostrando ${desde} a ${hasta} de ${total} evaluaciones`;
            }
        }
    }

    const perPage = _evalPerPage === 'all' ? total : parseInt(_evalPerPage);
    const totalPaginas = Math.ceil(total / perPage) || 1;

    if (btnPrev) btnPrev.disabled = (_evalCurrentPage <= 1);
    if (btnNext) btnNext.disabled = (_evalCurrentPage >= totalPaginas);
}

function cambiarFilasPorPaginaEval(val) {
    _evalPerPage = val;
    _evalCurrentPage = 1;
    renderVistaEvaluaciones();
}

function paginaAnteriorEval() {
    if (_evalCurrentPage > 1) {
        _evalCurrentPage--;
        renderVistaEvaluaciones();
    }
}

function paginaSiguienteEval() {
    const listLen = (_modoVistaEval === 'agrupada') ? _usuariosAgrupados.length : _evaluacionesFiltradas.length;
    const perPage = _evalPerPage === 'all' ? listLen : parseInt(_evalPerPage);
    const totalPaginas = Math.ceil(listLen / perPage) || 1;
    if (_evalCurrentPage < totalPaginas) {
        _evalCurrentPage++;
        renderVistaEvaluaciones();
    }
}

/**
 * Función Principal para cargar y mostrar el reporte de evaluaciones
 */
function renderReporteEvaluaciones() {
    inicializarFiltrosEvaluaciones();
    _evaluacionesRaw = _obtenerTodasEvaluaciones();
    filtrarTablaEvaluaciones();
}

// ============================================================
// EXPORTACIÓN DE EVALUACIONES (XLSX, CSV, IMPRESIÓN/PDF)
// ============================================================

/**
 * Exporta las evaluaciones filtradas a archivo Excel (XLSX)
 * Garantiza orden consecutivo agrupado por Colaborador
 */
function exportarEvaluacionesXLSX() {
    if (typeof XLSX === 'undefined') {
        alert('Cargando librería Excel, por favor reintenta en unos momentos...');
        return;
    }

    if (_evaluacionesFiltradas.length === 0) {
        alert('No hay evaluaciones filtradas para exportar.');
        return;
    }

    // Ordenar por Colaborador -> Curso -> Módulo
    const dataOrdenada = [..._evaluacionesFiltradas].sort((a, b) => {
        const cmpU = a.usuarioNombre.localeCompare(b.usuarioNombre);
        if (cmpU !== 0) return cmpU;
        const cmpC = a.cursoTitulo.localeCompare(b.cursoTitulo);
        if (cmpC !== 0) return cmpC;
        return a.moduloNum - b.moduloNum;
    });

    const dataExcel = dataOrdenada.map((e, idx) => ({
        '#': idx + 1,
        'Cédula / ID': e.usuarioId,
        'Colaborador': e.usuarioNombre,
        'Cargo / Rol': e.rolNombre,
        'Código Curso': e.cursoId,
        'Título Curso': e.cursoTitulo,
        'Módulo': e.moduloTitulo,
        'Calificación (pts)': e.calificacion,
        'Estado': e.aprobado ? 'APROBADO' : 'REPROBADO',
        'Intentos': e.intentos,
        'Modalidad': e.marcadoManual ? 'Marcado Manual (Rectoría)' : 'Examen Online',
        'Fecha': e.fecha || 'Sin registro'
    }));

    try {
        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.json_to_sheet(dataExcel);

        // Anchos de columna óptimos
        ws['!cols'] = [
            { wch: 6 },   // #
            { wch: 16 },  // Cédula
            { wch: 28 },  // Nombre
            { wch: 24 },  // Rol
            { wch: 20 },  // Código Curso
            { wch: 36 },  // Título Curso
            { wch: 32 },  // Módulo
            { wch: 18 },  // Calificación
            { wch: 14 },  // Estado
            { wch: 10 },  // Intentos
            { wch: 26 },  // Modalidad
            { wch: 22 }   // Fecha
        ];

        XLSX.utils.book_append_sheet(wb, ws, "Registro Evaluaciones");
        const hoy = new Date().toISOString().slice(0, 10);
        XLSX.writeFile(wb, `Reporte_Evaluaciones_Universidad_Aluminio_${hoy}.xlsx`);

        if (typeof showToast === 'function') {
            showToast('📊 Reporte de Evaluaciones XLSX exportado exitosamente.', 'success');
        }
    } catch (err) {
        console.error('Error al exportar XLSX:', err);
        alert('Error al exportar Excel: ' + err.message);
    }
}

/**
 * Exporta las evaluaciones filtradas a archivo CSV compatible
 * Ordenado por Colaborador
 */
function exportarEvaluacionesCSV() {
    if (_evaluacionesFiltradas.length === 0) {
        alert('No hay evaluaciones filtradas para exportar.');
        return;
    }

    const dataOrdenada = [..._evaluacionesFiltradas].sort((a, b) => {
        const cmpU = a.usuarioNombre.localeCompare(b.usuarioNombre);
        if (cmpU !== 0) return cmpU;
        const cmpC = a.cursoTitulo.localeCompare(b.cursoTitulo);
        if (cmpC !== 0) return cmpC;
        return a.moduloNum - b.moduloNum;
    });

    const headers = ['#', 'Cedula', 'Colaborador', 'Cargo', 'Codigo_Curso', 'Curso', 'Modulo', 'Calificacion', 'Estado', 'Intentos', 'Modalidad', 'Fecha'];
    const rows = dataOrdenada.map((e, idx) => [
        idx + 1,
        `"${e.usuarioId}"`,
        `"${e.usuarioNombre.replace(/"/g, '""')}"`,
        `"${e.rolNombre.replace(/"/g, '""')}"`,
        `"${e.cursoId}"`,
        `"${e.cursoTitulo.replace(/"/g, '""')}"`,
        `"${e.moduloTitulo.replace(/"/g, '""')}"`,
        e.calificacion,
        e.aprobado ? 'APROBADO' : 'REPROBADO',
        e.intentos,
        e.marcadoManual ? 'Manual' : 'Online',
        `"${(e.fecha || '').replace(/"/g, '""')}"`
    ]);

    const csvContent = '\uFEFF' + [headers.join(','), ...rows.map(r => r.join(','))].join('\r\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    const hoy = new Date().toISOString().slice(0, 10);
    link.setAttribute('href', url);
    link.setAttribute('download', `Reporte_Evaluaciones_Universidad_Aluminio_${hoy}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    if (typeof showToast === 'function') {
        showToast('📄 Archivo CSV descargado con éxito.', 'success');
    }
}

/**
 * Genera vista de impresión / PDF profesional con soporte para formato agrupado
 */
function imprimirReporteEvaluaciones() {
    if (_evaluacionesFiltradas.length === 0) {
        alert('No hay datos para imprimir.');
        return;
    }

    const w = window.open('', '_blank');
    const hoy = new Date().toLocaleDateString('es-ES', { day: '2-digit', month: 'long', year: 'numeric' });

    let bodyHtml = '';

    if (_modoVistaEval === 'agrupada' && _usuariosAgrupados.length > 0) {
        // Modo Agrupado por Colaborador
        _usuariosAgrupados.forEach((u, uIdx) => {
            let modRows = '';
            u.evaluaciones.forEach((e, mIdx) => {
                modRows += `
                <tr>
                    <td style="text-align:center; width:30px;">${mIdx + 1}</td>
                    <td><strong>${e.cursoTitulo}</strong></td>
                    <td>${e.moduloTitulo}</td>
                    <td style="text-align:center; font-weight:bold; color:${e.aprobado ? '#059669' : '#dc2626'};">${e.calificacion.toFixed(1)} pts</td>
                    <td style="text-align:center;">${e.aprobado ? '<span style="color:#059669; font-weight:bold;">Aprobado</span>' : '<span style="color:#dc2626; font-weight:bold;">Reprobado</span>'}</td>
                    <td style="text-align:center;">${e.intentos}</td>
                    <td>${e.marcadoManual ? 'Manual (Rectoría)' : 'Online'}</td>
                    <td style="font-size:11px;">${e.fecha || '—'}</td>
                </tr>`;
            });

            bodyHtml += `
            <div style="margin-bottom: 22px; border: 1px solid #cbd5e1; border-radius: 6px; overflow: hidden; page-break-inside: avoid;">
                <div style="background-color: #f1f5f9; padding: 10px 14px; border-bottom: 1px solid #cbd5e1; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <strong style="font-size: 14px; color: #0f2b48;">${uIdx + 1}. ${u.usuarioNombre}</strong>
                        <span style="color: #64748b; font-size: 12px; margin-left: 8px;">(CI: ${u.usuarioId} • ${u.rolNombre})</span>
                    </div>
                    <div style="font-size: 12px;">
                        <strong>Promedio:</strong> <span style="color: ${u.promedio >= 70 ? '#059669' : '#dc2626'}; font-weight: bold;">${u.promedio} pts</span> •
                        <strong>Módulos cursados:</strong> ${u.totalModulos} (${u.aprobados} aprobados)
                    </div>
                </div>
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background-color: #f8fafc; font-size: 11px; color: #475569; text-transform: uppercase;">
                            <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1;">#</th>
                            <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1;">Curso</th>
                            <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1;">Módulo Evaluado</th>
                            <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1; text-align:center;">Calificación</th>
                            <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1; text-align:center;">Estado</th>
                            <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1; text-align:center;">Intentos</th>
                            <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1;">Modalidad</th>
                            <th style="padding: 6px 8px; border-bottom: 1px solid #cbd5e1;">Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${modRows}
                    </tbody>
                </table>
            </div>`;
        });
    } else {
        // Modo Lista Plana Tradicional
        let rowsHtml = '';
        _evaluacionesFiltradas.forEach((e, idx) => {
            rowsHtml += `
            <tr>
                <td style="text-align:center;">${idx + 1}</td>
                <td><strong>${e.usuarioNombre}</strong><br><small style="color:#666;">CI: ${e.usuarioId} • ${e.rolNombre}</small></td>
                <td>${e.cursoTitulo}</td>
                <td>${e.moduloTitulo}</td>
                <td style="text-align:center; font-weight:bold; color:${e.aprobado ? '#059669' : '#dc2626'};">${e.calificacion.toFixed(1)} pts</td>
                <td style="text-align:center;">${e.aprobado ? '<span style="color:#059669; font-weight:bold;">Aprobado</span>' : '<span style="color:#dc2626; font-weight:bold;">Reprobado</span>'}</td>
                <td style="text-align:center;">${e.intentos}</td>
                <td>${e.marcadoManual ? 'Manual (Rectoría)' : 'Online'}</td>
                <td style="font-size:11px;">${e.fecha || '—'}</td>
            </tr>`;
        });

        bodyHtml = `
        <table>
            <thead>
                <tr>
                    <th style="width:30px; text-align:center;">#</th>
                    <th>Colaborador</th>
                    <th>Curso</th>
                    <th>Módulo</th>
                    <th style="text-align:center;">Calificación</th>
                    <th style="text-align:center;">Estado</th>
                    <th style="text-align:center;">Intentos</th>
                    <th>Modalidad</th>
                    <th>Fecha</th>
                </tr>
            </thead>
            <tbody>
                ${rowsHtml}
            </tbody>
        </table>`;
    }

    const docHtml = `
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Reporte de Evaluaciones — Universidad del Aluminio</title>
        <style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 12px; color: #1e293b; margin: 25px; }
            .header { border-bottom: 2px solid #0f2b48; padding-bottom: 15px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: flex-end; }
            .title { font-size: 20px; font-weight: bold; color: #0f2b48; }
            .subtitle { font-size: 13px; color: #64748b; margin-top: 4px; }
            table { width: 100%; border-collapse: collapse; margin-top: 5px; }
            th { background-color: #0f2b48; color: white; text-align: left; padding: 7px 8px; font-size: 11px; text-transform: uppercase; }
            td { border-bottom: 1px solid #e2e8f0; padding: 6px 8px; }
            tr:nth-child(even) { background-color: #f8fafc; }
            @media print {
                .no-print { display: none; }
                body { margin: 0; }
            }
        </style>
    </head>
    <body>
        <div class="no-print" style="margin-bottom: 15px;">
            <button onclick="window.print()" style="padding: 8px 16px; background: #0f2b48; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">
                🖨️ Imprimir / Guardar como PDF
            </button>
            <span style="margin-left: 10px; color: #64748b;">(Usa Ctrl+P o el botón para guardar como PDF)</span>
        </div>
        <div class="header">
            <div>
                <div class="title">Universidad del Aluminio • Panel Rectoral</div>
                <div class="subtitle">Reporte de Calificaciones ${(_modoVistaEval === 'agrupada' ? 'Agrupado por Colaborador' : 'Detallado')}</div>
            </div>
            <div style="text-align: right; font-size: 11px; color: #64748b;">
                Fecha de Emisión: ${hoy}<br>
                ${_modoVistaEval === 'agrupada' ? `Total Colaboradores: ${_usuariosAgrupados.length} • ` : ''}Total Calificaciones: ${_evaluacionesFiltradas.length}
            </div>
        </div>
        ${bodyHtml}
    </body>
    </html>`;

    w.document.write(docHtml);
    w.document.close();
}

// ============================================================
// FUNCIÓN PRINCIPAL — Llamada desde admin.html
// ============================================================

function renderRobustReports() {
    renderReporteEvaluaciones();
    renderTopLearners();
    renderCumplimientoCargo();
    inicializarFiltroUsuariosBrechas();
    renderBrechasAprendizaje();
}

// Exportar al scope global
window.renderRobustReports            = renderRobustReports;
window.renderReporteEvaluaciones     = renderReporteEvaluaciones;
window.renderVistaEvaluaciones       = renderVistaEvaluaciones;
window.cambiarModoVistaEvaluaciones  = cambiarModoVistaEvaluaciones;
window.expandirTodosColaboradores    = expandirTodosColaboradores;
window.toggleColaboradorAcordeon     = toggleColaboradorAcordeon;
window.filtrarTablaEvaluaciones      = filtrarTablaEvaluaciones;
window.exportarEvaluacionesXLSX      = exportarEvaluacionesXLSX;
window.exportarEvaluacionesCSV       = exportarEvaluacionesCSV;
window.imprimirReporteEvaluaciones   = imprimirReporteEvaluaciones;
window.cambiarFilasPorPaginaEval     = cambiarFilasPorPaginaEval;
window.paginaAnteriorEval            = paginaAnteriorEval;
window.paginaSiguienteEval           = paginaSiguienteEval;
window.renderBrechasAprendizaje      = renderBrechasAprendizaje;
window.renderTopLearners             = renderTopLearners;
window.renderCumplimientoCargo       = renderCumplimientoCargo;
window.exportarReporteXLSX           = exportarReporteXLSX;



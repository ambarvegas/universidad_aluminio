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

// ============================================================
// VISTA 1: TOP LEARNERS (tabla rediseñada)
// ============================================================

function renderTopLearners() {
    const container = document.getElementById('tabla-top-learners');
    if (!container) return;

    const usuariosActivos = (usuarios || []).filter(u => u.rol !== 'admin' && u.estado !== 'suspendido');

    const datos = usuariosActivos.map(u => {
        const cursosAsig = _obtenerCursosDelRol(u);
        const certs = Array.isArray(u.certificadosCurso) ? u.certificadosCurso.length : 0;
        const total = cursosAsig.length;
        let sumPct = 0;
        cursosAsig.forEach(cid => {
            const c = (cursos || []).find(x => x.id === cid);
            if (c) sumPct += _porcentajeCurso(u, c);
        });
        const promedio = total > 0 ? Math.round(sumPct / total) : 0;
        const completados = Array.isArray(u.certificadosCurso) ? u.certificadosCurso.filter(id => cursosAsig.includes(id)).length : 0;
        return { usuario: u, completados, total, promedio, certs };
    }).sort((a, b) => b.promedio - a.promedio || b.completados - a.completados);

    if (datos.length === 0) {
        container.innerHTML = `<tr><td colspan="6" class="text-center text-muted py-4"><i class="bi bi-people fs-2 d-block mb-2"></i>Sin colaboradores registrados.</td></tr>`;
        return;
    }

    const getColorPct = pct => pct >= 75 ? 'success' : pct >= 40 ? 'warning' : 'danger';
    const getMedal = (idx) => idx === 0 ? '🥇' : idx === 1 ? '🥈' : idx === 2 ? '🥉' : `#${idx + 1}`;

    container.innerHTML = `
        <thead>
            <tr class="report-thead">
                <th style="width:50px;">#</th>
                <th>Colaborador</th>
                <th>Rol</th>
                <th>Progreso Promedio</th>
                <th class="text-center">Cursos</th>
                <th class="text-center">Certificados</th>
            </tr>
        </thead>
        <tbody>
        ${datos.map((d, i) => {
            const initials = (d.usuario.nombre || '?').split(' ').slice(0, 2).map(n => n[0]).join('').toUpperCase();
            const rolObj = (rolesConfig || []).find(r => r.id === d.usuario.rol);
            const rolNombre = rolObj ? rolObj.nombre : d.usuario.rol;
            const color = getColorPct(d.promedio);
            return `
            <tr class="report-row">
                <td><span class="rank-badge">${getMedal(i)}</span></td>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div class="user-avatar" style="font-size:0.75rem;">${initials}</div>
                        <div>
                            <div class="fw-semibold">${d.usuario.nombre}</div>
                            <div class="text-muted small">${d.usuario.id}</div>
                        </div>
                    </div>
                </td>
                <td><span class="badge bg-light text-dark border small">${rolNombre}</span></td>
                <td style="min-width:160px;">
                    <div class="d-flex align-items-center gap-2">
                        <div class="flex-grow-1">
                            <div class="progress-modern"><div class="progress-bar bg-${color}" style="width:${d.promedio}%"></div></div>
                        </div>
                        <span class="fw-bold small text-${color === 'success' ? 'success' : color === 'warning' ? 'warning' : 'danger'}">${d.promedio}%</span>
                    </div>
                </td>
                <td class="text-center">
                    <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold">${d.completados}/${d.total}</span>
                </td>
                <td class="text-center">
                    <span class="badge bg-success bg-opacity-10 text-success fw-bold">${d.certs} 🏆</span>
                </td>
            </tr>`;
        }).join('')}
        </tbody>`;
}

// ============================================================
// VISTA 2: CUMPLIMIENTO POR CARGO (chart mejorado)
// ============================================================

function renderCumplimientoCargo() {
    const container = document.getElementById('chart-cumplimiento');
    if (!container) return;

    const rolesValidos = (rolesConfig || []).filter(r => r.id !== 'admin');
    if (rolesValidos.length === 0) { container.innerHTML = '<p class="text-muted small text-center">Sin roles configurados.</p>'; return; }

    const stats = rolesValidos.map(rol => {
        const miembros = (usuarios || []).filter(u => u.rol === rol.id && u.estado !== 'suspendido');
        if (miembros.length === 0) return { nombre: rol.nombre, promedio: 0, count: 0 };
        const promedios = miembros.map(u => {
            const asig = Array.isArray(u.asignados) ? u.asignados : [];
            if (asig.length === 0) return 0;
            return asig.reduce((sum, cid) => {
                const c = (cursos || []).find(x => x.id === cid);
                return sum + (c ? _porcentajeCurso(u, c) : 0);
            }, 0) / asig.length;
        });
        return { nombre: rol.nombre, promedio: Math.round(promedios.reduce((a, b) => a + b, 0) / promedios.length), count: miembros.length };
    }).filter(s => s.count > 0);

    if (stats.length === 0) { container.innerHTML = '<p class="text-muted small text-center">Sin datos de cumplimiento.</p>'; return; }

    const maxPct = Math.max(...stats.map(s => s.promedio), 1);
    const colores = ['#0284c7', '#9333ea', '#10b981', '#f59e0b', '#ef4444', '#06b6d4'];

    container.innerHTML = `
        <div class="d-flex flex-column gap-3">
        ${stats.map((s, i) => `
            <div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="small fw-semibold">${s.nombre}</span>
                    <span class="small text-muted">${s.promedio}% <span class="text-light-emphasis">(${s.count})</span></span>
                </div>
                <div class="progress-modern">
                    <div class="progress-bar" style="width:${s.promedio}%; background:${colores[i % colores.length]};"></div>
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
    const dataLearners = usuariosActivos.map((u, idx) => {
        const cursosAsig = _obtenerCursosDelRol(u);
        const totalCursos = cursosAsig.length;
        let sumPct = 0;
        cursosAsig.forEach(cid => {
            const c = (cursos || []).find(x => x.id === cid);
            if (c) sumPct += _porcentajeCurso(u, c);
        });
        const promedio = totalCursos > 0 ? Math.round(sumPct / totalCursos) : 0;
        const completados = Array.isArray(u.certificadosCurso) ? u.certificadosCurso.filter(id => cursosAsig.includes(id)).length : 0;
        const rolObj = (rolesConfig || []).find(r => r.id === u.rol);

        return {
            'Posición': idx + 1,
            'Cédula / ID': u.id,
            'Nombre del Colaborador': u.nombre,
            'Rol / Cargo': rolObj ? rolObj.nombre : u.rol,
            'Estado': (u.estado || 'activo').toUpperCase(),
            'Cursos Requeridos (Rol)': totalCursos,
            'Cursos Completados': completados,
            'Progreso Promedio (%)': `${promedio}%`,
            'Certificados Obtenidos': Array.isArray(u.certificadosCurso) ? u.certificadosCurso.length : 0
        };
    }).sort((a, b) => parseInt(b['Progreso Promedio (%)']) - parseInt(a['Progreso Promedio (%)']));

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
// FUNCIÓN PRINCIPAL — Llamada desde admin.html
// ============================================================

function renderRobustReports() {
    renderTopLearners();
    renderCumplimientoCargo();
    inicializarFiltroUsuariosBrechas();
    renderBrechasAprendizaje();
}

// Expo al scope global
window.renderRobustReports        = renderRobustReports;
window.renderBrechasAprendizaje   = renderBrechasAprendizaje;
window.renderTopLearners          = renderTopLearners;
window.renderCumplimientoCargo    = renderCumplimientoCargo;
window.exportarReporteXLSX        = exportarReporteXLSX;


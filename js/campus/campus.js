/**
 * Universidad del Aluminio — Campus Module
 * Catálogo de cursos, rutas de carreras del estudiante, vitrina de certificados y perfil del usuario.
 */

let filtroGaleriaCategoria = 'todos';

window.setFiltroGaleria = function(cat) {
    filtroGaleriaCategoria = cat;
    document.querySelectorAll('#filtros-categoria .filter-pill-btn').forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('data-filter') === cat);
    });
    renderizarGaleria();
};

window.filtrarGaleriaCursos = function() {
    renderizarGaleria();
};

function calcularProgresoCurso(cursoId, cursoData) {
    if (!sesion || !sesion.progreso || !sesion.progreso[cursoId]) {
        const totalMod = (cursoData && cursoData.modulos) ? cursoData.modulos.length : 0;
        let totalLec = 0;
        if (cursoData && cursoData.modulos) {
            cursoData.modulos.forEach(m => totalLec += (m.lecciones || []).length);
        }
        return { porcentaje: 0, leccionesCompletadas: 0, totalLecciones: totalLec, modulosCompletados: 0, totalModulos: totalMod, completado: false };
    }
    const prog = sesion.progreso[cursoId];
    const modulos = (cursoData && cursoData.modulos) ? cursoData.modulos : [];
    let totalLecciones = 0;
    modulos.forEach(m => {
        totalLecciones += (m.lecciones || []).length;
    });

    const leccionesComp = Array.isArray(prog.leccionesCompletadas) ? prog.leccionesCompletadas.length : 0;
    const modulosAprob = Array.isArray(prog.modulosAprobados) ? prog.modulosAprobados.length : 0;
    const totalModulos = modulos.length;

    let porcentaje = 0;
    if (totalModulos > 0) {
        porcentaje = Math.round((modulosAprob / totalModulos) * 100);
    } else if (totalLecciones > 0) {
        porcentaje = Math.round((leccionesComp / totalLecciones) * 100);
    }

    const tieneCertificado = Array.isArray(sesion.certificadosCurso) && sesion.certificadosCurso.includes(cursoId);
    const completado = tieneCertificado || porcentaje >= 100;
    if (completado) porcentaje = 100;

    return {
        porcentaje: Math.min(100, porcentaje),
        leccionesCompletadas: leccionesComp,
        totalLecciones,
        modulosCompletados: modulosAprob,
        totalModulos,
        completado
    };
}
window.calcularProgresoCurso = calcularProgresoCurso;

function renderizarGaleria() {
    const galeria = document.getElementById('galeria-cursos-row') || document.querySelector('#galeria-cursos .row');
    
    // 1. Actualizar Header y KPIs del Usuario si están en el DOM
    if (sesion) {
        const navAdminLink = document.getElementById('nav-admin-link');
        if (navAdminLink) {
            navAdminLink.style.display = (sesion.rol === 'admin') ? 'block' : 'none';
        }

        const navUserInfo = document.getElementById('nav-user-info');
        if (navUserInfo) {
            navUserInfo.innerHTML = `
                <button type="button" class="btn btn-link text-decoration-none p-0 d-flex align-items-center gap-2" onclick="abrirModalPerfil()" title="Ver Mi Perfil">
                    <div class="user-avatar" style="width: 32px; height: 32px; font-size: 0.8rem; cursor: pointer; border: 1.5px solid rgba(255,255,255,0.4);">${typeof obtenerIniciales === 'function' ? obtenerIniciales(sesion.nombre) : 'UA'}</div>
                    <div class="d-none d-sm-block text-start lh-1 text-white">
                        <div class="text-white small fw-bold">${sesion.nombre} <i class="bi bi-gear-fill text-white-50 ms-1" style="font-size: 0.7rem;"></i></div>
                        <small class="text-white-50" style="font-size: 0.725rem;">${(sesion.rol || 'Estudiante').replace('_', ' ')}</small>
                    </div>
                </button>
            `;
        }

        const greetingTitle = document.getElementById('user-greeting-title');
        if (greetingTitle) {
            greetingTitle.textContent = `¡Hola, ${sesion.nombre}! 👋`;
        }

        const roleBadge = document.getElementById('user-role-label');
        if (roleBadge) {
            roleBadge.textContent = (sesion.rol === 'admin') ? 'Administrador' : `Cargo: ${(sesion.rol || 'Estudiante').replace('_', ' ')}`;
        }
    }

    if (!galeria) return;

    // 2. Determinar Cursos Visibles según Permisos / Carrera / Rol
    let cursosVisibles = [];
    if (sesion && sesion.rol === 'admin') {
        cursosVisibles = (cursos || []).map(c => ({ ...c, bloqueado: false }));
    } else {
        const configRol = sesion ? (rolesConfig || []).find(r => r.id === sesion.rol) : null;
        const directosDelRol = configRol ? configRol.cursos || [] : [];
        const deCarreras = [];
        if (configRol && configRol.carreras) {
            configRol.carreras.forEach(carId => {
                const carrera = (carreras || []).find(c => c.id === carId);
                if (carrera && Array.isArray(carrera.cursos)) deCarreras.push(...carrera.cursos);
            });
        }
        if (sesion && Array.isArray(sesion.carrerasAsignadas)) {
            sesion.carrerasAsignadas.forEach(ca => {
                const carrera = (carreras || []).find(c => c.id === ca.id);
                if (carrera && Array.isArray(carrera.cursos)) deCarreras.push(...carrera.cursos);
            });
        }
        const porSolicitud = (sesion && sesion.asignados) || [];
        const idsAccesoTotal = [...new Set([...directosDelRol, ...deCarreras, ...porSolicitud])];
        cursosVisibles = (cursos || []).map(c => {
            const esAccesoLibre = c.tipo === 'publico' || c.tipo === 'libre';
            const tieneAcceso = esAccesoLibre || idsAccesoTotal.includes(c.id);
            return { ...c, bloqueado: !tieneAcceso };
        });
    }

    // 3. Calcular Métricas para los KPIs
    let countTotal = cursosVisibles.length;
    let countEnProgreso = 0;
    let countCompletados = 0;

    cursosVisibles.forEach(c => {
        const info = calcularProgresoCurso(c.id, c);
        c._progresoInfo = info;
        if (info.completado) {
            countCompletados++;
        } else if (info.porcentaje > 0) {
            countEnProgreso++;
        }
    });

    const elTotal = document.getElementById('kpi-cursos-total');
    if (elTotal) elTotal.textContent = countTotal;
    const elProg = document.getElementById('kpi-cursos-progreso');
    if (elProg) elProg.textContent = countEnProgreso;
    const elComp = document.getElementById('kpi-cursos-completados');
    if (elComp) elComp.textContent = countCompletados;

    // 4. Aplicar Filtros (Búsqueda y Categoría)
    const query = (document.getElementById('input-buscar-cursos')?.value || '').trim().toLowerCase();
    let cursosFiltrados = cursosVisibles.filter(c => {
        if (query) {
            const matchTitulo = (c.titulo || '').toLowerCase().includes(query);
            const matchId = (c.id || '').toLowerCase().includes(query);
            if (!matchTitulo && !matchId) return false;
        }

        const esLibre = c.tipo === 'publico' || c.tipo === 'libre';
        if (filtroGaleriaCategoria === 'progreso') {
            return c._progresoInfo.porcentaje > 0 && !c._progresoInfo.completado;
        } else if (filtroGaleriaCategoria === 'completados') {
            return c._progresoInfo.completado;
        } else if (filtroGaleriaCategoria === 'libre') {
            return esLibre;
        } else if (filtroGaleriaCategoria === 'especializado') {
            return !esLibre;
        }
        return true;
    });

    if (cursosFiltrados.length === 0) {
        galeria.innerHTML = `
            <div class="col-12 text-center py-5">
                <div class="py-4">
                    <i class="bi bi-search display-3 text-muted opacity-50 mb-3 d-block"></i>
                    <h5 class="fw-bold text-secondary">No se encontraron cursos</h5>
                    <p class="text-muted small">Intenta con otro término de búsqueda o cambia la categoría seleccionada.</p>
                </div>
            </div>`;
        return;
    }

    // 5. Renderizar Tarjetas de Cursos
    let galeriaHTML = '';
    cursosFiltrados.forEach(c => {
        let bloqueadoPorPrelacion = false;
        let mensajePrelacion = "";
        if (c.prelacion) {
            const cursoPrevio = (cursos || []).find(cp => cp.id === c.prelacion);
            if (cursoPrevio) {
                const progresoPrevio = sesion && sesion.progreso ? sesion.progreso[c.prelacion] : null;
                const modulosConEval = (cursoPrevio.modulos || []).filter(m => m.evaluacion && m.evaluacion.preguntas && m.evaluacion.preguntas.length > 0).length;
                const totalModulosPrevio = modulosConEval > 0 ? modulosConEval : (cursoPrevio.modulos || []).length;
                const modulosAprobados = (progresoPrevio && progresoPrevio.modulosAprobados) ? progresoPrevio.modulosAprobados.length : 0;

                if (modulosAprobados < totalModulosPrevio) {
                    bloqueadoPorPrelacion = true;
                    mensajePrelacion = `Requiere: ${cursoPrevio.titulo}`;
                }
            }
        }

        const esAccesoLibre = c.tipo === 'publico' || c.tipo === 'libre';
        const prog = c._progresoInfo;
        const totalModulos = (c.modulos || []).length;
        let totalLecciones = 0;
        (c.modulos || []).forEach(m => totalLecciones += (m.lecciones || []).length);

        // Badge de Estado Superior
        let badgeEstado = '';
        if (c.enConstruccion) {
            badgeEstado = `<span class="badge bg-warning text-dark shadow-sm fw-bold"><i class="bi bi-cone-striped me-1"></i>En Construcción</span>`;
        } else if (prog.completado) {
            badgeEstado = `<span class="badge bg-success text-white shadow-sm"><i class="bi bi-award-fill me-1"></i>Completado</span>`;
        } else if (prog.porcentaje > 0) {
            badgeEstado = `<span class="badge bg-primary text-white shadow-sm"><i class="bi bi-clock-history me-1"></i>En Curso ${prog.porcentaje}%</span>`;
        } else if (esAccesoLibre) {
            badgeEstado = `<span class="badge bg-emerald text-white shadow-sm" style="background:#10b981"><i class="bi bi-unlock-fill me-1"></i>Libre</span>`;
        } else {
            badgeEstado = `<span class="badge bg-dark text-white bg-opacity-75 shadow-sm"><i class="bi bi-mortarboard-fill me-1"></i>Especializado</span>`;
        }

        // Botón de Acción
        let btnAccion = '';
        if (c.enConstruccion) {
            btnAccion = `
                <button class="btn btn-warning text-dark w-100 fw-bold shadow-sm" onclick="showToast('El curso &quot;${(c.titulo || '').replace(/"/g, '&quot;')}&quot; se encuentra actualmente en construcción. ¡Próximamente disponible!', 'warning')" style="font-size:0.875rem;">
                    <i class="bi bi-cone-striped me-1"></i>En Construcción
                </button>`;
        } else if (c.bloqueado || bloqueadoPorPrelacion) {
            btnAccion = `
                <button class="btn btn-outline-secondary w-100" onclick="${bloqueadoPorPrelacion ? "showToast('Debes completar primero: " + mensajePrelacion.replace(/'/g, "\\'") + "', 'warning')" : "solicitarAccesoCurso('" + c.id + "')"}" style="font-size:0.875rem;">
                    <i class="bi ${bloqueadoPorPrelacion ? 'bi-shield-lock-fill' : 'bi-lock-fill'} me-1"></i>
                    ${bloqueadoPorPrelacion ? mensajePrelacion : 'Solicitar Acceso'}
                </button>`;
        } else if (prog.completado) {
            btnAccion = `
                <a href="detalle.php?id=${c.id}" class="btn btn-success w-100" style="font-size:0.875rem;">
                    <i class="bi bi-check2-circle me-1"></i>Repasar / Certificado
                </a>`;
        } else if (prog.porcentaje > 0) {
            btnAccion = `
                <a href="detalle.php?id=${c.id}" class="btn btn-primary w-100" style="font-size:0.875rem;">
                    <i class="bi bi-play-circle-fill me-1"></i>Continuar (${prog.porcentaje}%)
                </a>`;
        } else {
            btnAccion = `
                <a href="detalle.php?id=${c.id}" class="btn btn-primary w-100" style="font-size:0.875rem;">
                    <i class="bi bi-arrow-right-circle-fill me-1"></i>Comenzar Curso
                </a>`;
        }

        const imagenUrl = c.imagen || 'https://images.unsplash.com/photo-1581091226825-a6a2a5aee158?w=600&auto=format&fit=crop&q=80';
        const isLocked = c.bloqueado || bloqueadoPorPrelacion;

        galeriaHTML += `
            <div class="col-sm-6 col-lg-4 mb-4">
                <div class="course-card ${isLocked ? 'opacity-75' : ''}">
                    <div class="course-thumb-container">
                        <img src="${typeof resolverSrcImagen === 'function' ? resolverSrcImagen(imagenUrl) : imagenUrl}" alt="${c.titulo}" style="${isLocked ? 'filter: grayscale(0.85);' : ''}">
                        <div class="course-thumb-overlay"></div>
                        <div class="course-badge-floating">${badgeEstado}</div>
                        ${isLocked ? '<div class="position-absolute top-50 start-50 translate-middle text-center"><i class="bi bi-lock-fill display-5 text-white drop-shadow"></i></div>' : ''}
                    </div>
                    
                    <div class="course-body">
                        <div class="course-meta">
                            <span><i class="bi bi-folder2-open me-1"></i>${totalModulos} módulos</span>
                            <span><i class="bi bi-play-btn me-1"></i>${totalLecciones} lecciones</span>
                            ${c.prelacion ? '<span class="badge-soft-warning"><i class="bi bi-diagram-3-fill me-1"></i>Prelación</span>' : ''}
                        </div>

                        <h5 class="course-title" title="${c.titulo}">${c.titulo}</h5>

                        <div class="course-progress-wrapper">
                            <div class="course-progress-label">
                                <span>Progreso general</span>
                                <span>${prog.porcentaje}%</span>
                            </div>
                            <div class="progress-modern mb-3">
                                <div class="progress-bar ${prog.completado ? 'bg-success' : 'bg-primary'}" style="width: ${prog.porcentaje}%;"></div>
                            </div>
                            ${btnAccion}
                        </div>
                    </div>
                </div>
            </div>`;
    });

    galeria.innerHTML = galeriaHTML;

    // Actualizar conteos en badges de las pestañas del Campus
    if (sesion) {
        const misCarreras = obtenerCarrerasDelUsuario(sesion);
        const certsCarreraIds = Array.isArray(sesion.certificadosCarrera) ? sesion.certificadosCarrera : [];
        const certsCursoIds = Array.isArray(sesion.certificadosCurso) ? sesion.certificadosCurso : [];

        let carrerasCompletas = 0;
        misCarreras.forEach(c => {
            const inf = calcularProgresoCarrera(sesion, c);
            if (inf.completada || certsCarreraIds.includes(c.id)) carrerasCompletas++;
        });

        const totalCerts = certsCursoIds.length + carrerasCompletas;

        const bCarTab = document.getElementById('tab-badge-carreras');
        if (bCarTab) {
            bCarTab.textContent = misCarreras.length;
            bCarTab.style.display = misCarreras.length > 0 ? 'inline-block' : 'none';
        }
        const bCarNav = document.getElementById('nav-badge-carreras');
        if (bCarNav) {
            bCarNav.textContent = misCarreras.length;
            bCarNav.style.display = misCarreras.length > 0 ? 'inline-block' : 'none';
        }

        const bCertTab = document.getElementById('tab-badge-certificados');
        if (bCertTab) {
            bCertTab.textContent = totalCerts;
            bCertTab.style.display = totalCerts > 0 ? 'inline-block' : 'none';
        }
        const bCertNav = document.getElementById('nav-badge-certificados');
        if (bCertNav) {
            bCertNav.textContent = totalCerts;
            bCertNav.style.display = totalCerts > 0 ? 'inline-block' : 'none';
        }
    }
}
window.renderizarGaleria = renderizarGaleria;

function obtenerCarrerasDelUsuario(usuario) {
    if (!usuario) return [];
    const carrerasIds = new Set();
    
    // De su rol
    const configRol = (rolesConfig || []).find(r => r.id === usuario.rol);
    if (configRol && Array.isArray(configRol.carreras)) {
        configRol.carreras.forEach(cid => { if (cid) carrerasIds.add(cid); });
    }
    
    // De asignaciones expresas
    if (Array.isArray(usuario.carrerasAsignadas)) {
        usuario.carrerasAsignadas.forEach(ca => {
            const cid = (typeof ca === 'string') ? ca : (ca.id || '');
            if (cid) carrerasIds.add(cid);
        });
    }

    const lista = [];
    carrerasIds.forEach(id => {
        const car = (carreras || []).find(c => c.id === id);
        if (car) lista.push(car);
    });

    return lista;
}
window.obtenerCarrerasDelUsuario = obtenerCarrerasDelUsuario;

function calcularProgresoCarrera(usuario, carrera) {
    if (!carrera || !Array.isArray(carrera.cursos) || carrera.cursos.length === 0) {
        return { total: 0, completados: 0, porcentaje: 0, completada: false, detalleCursos: [] };
    }
    
    const userProg = (usuario && usuario.progreso) ? usuario.progreso : {};
    const certsCurso = (usuario && Array.isArray(usuario.certificadosCurso)) ? usuario.certificadosCurso : [];
    const certsCarrera = (usuario && Array.isArray(usuario.certificadosCarrera)) ? usuario.certificadosCarrera : [];
    
    let completadosCount = 0;
    const detalleCursos = [];

    carrera.cursos.forEach(cursoId => {
        const cursoObj = (cursos || []).find(c => c.id === cursoId);
        const prog = userProg[cursoId] || {};
        const modulosAprobados = Array.isArray(prog.modulosAprobados) ? prog.modulosAprobados : [];
        const tieneCertificado = certsCurso.includes(cursoId);
        
        let totalModulos = 0;
        if (cursoObj && Array.isArray(cursoObj.modulos)) {
            totalModulos = cursoObj.modulos.length;
        }

        const cursoAprobado = tieneCertificado || (totalModulos > 0 && modulosAprobados.length >= totalModulos);
        if (cursoAprobado) completadosCount++;

        let pctCurso = 0;
        if (cursoAprobado) {
            pctCurso = 100;
        } else if (totalModulos > 0) {
            pctCurso = Math.round((modulosAprobados.length / totalModulos) * 100);
        }

        detalleCursos.push({
            id: cursoId,
            titulo: cursoObj ? cursoObj.titulo : cursoId,
            descripcion: cursoObj ? (cursoObj.descripcion || '') : '',
            imagen: cursoObj ? (cursoObj.imagen || '') : '',
            aprobado: cursoAprobado,
            porcentaje: pctCurso,
            totalModulos: totalModulos,
            modulosAprobados: modulosAprobados.length
        });
    });

    const total = carrera.cursos.length;
    const porcentaje = total > 0 ? Math.round((completadosCount / total) * 100) : 0;
    const completada = certsCarrera.includes(carrera.id) || (total > 0 && completadosCount >= total);

    return {
        total,
        completados: completadosCount,
        porcentaje: completada ? 100 : porcentaje,
        completada,
        detalleCursos
    };
}
window.calcularProgresoCarrera = calcularProgresoCarrera;

window.cambiarVistaCampus = function(vista) {
    const vCursos = document.getElementById('vista-campus-cursos');
    const vCarreras = document.getElementById('vista-campus-carreras');
    const vCertificados = document.getElementById('vista-campus-certificados');

    if (!vCursos || !vCarreras || !vCertificados) return;

    vCursos.style.display = (vista === 'cursos') ? 'block' : 'none';
    vCarreras.style.display = (vista === 'carreras') ? 'block' : 'none';
    vCertificados.style.display = (vista === 'certificados') ? 'block' : 'none';

    ['cursos', 'carreras', 'certificados'].forEach(v => {
        const btn = document.getElementById(`tab-btn-${v}`);
        if (btn) btn.classList.toggle('active', v === vista);
        const navBtn = document.getElementById(`nav-btn-${v}`);
        if (navBtn) navBtn.classList.toggle('active', v === vista);
    });

    if (vista === 'carreras') renderizarCarreras();
    if (vista === 'certificados') renderizarVitrinaCertificados();
};

window.renderizarCarreras = function() {
    const contenedor = document.getElementById('galeria-carreras-row');
    if (!contenedor) return;

    if (!sesion) {
        contenedor.innerHTML = `<div class="col-12 text-center py-4 text-muted">Inicia sesión para consultar tus carreras.</div>`;
        return;
    }

    const misCarreras = obtenerCarrerasDelUsuario(sesion);

    if (misCarreras.length === 0) {
        contenedor.innerHTML = `
            <div class="col-12 text-center py-5">
                <div class="py-4">
                    <i class="bi bi-diagram-3 display-3 text-muted opacity-50 mb-3 d-block"></i>
                    <h5 class="fw-bold text-secondary">No tienes carreras asignadas actualmente</h5>
                    <p class="text-muted small">Las rutas de aprendizaje se asignan automáticamente según tu rol o por la Rectoría Académica.</p>
                </div>
            </div>`;
        return;
    }

    let html = '';
    misCarreras.forEach(car => {
        const info = calcularProgresoCarrera(sesion, car);
        const headerClass = info.completada ? 'career-card-header completed' : 'career-card-header';
        const progressFillClass = info.completada ? 'career-progress-fill gold' : 'career-progress-fill';

        let coursesListHtml = '';
        info.detalleCursos.forEach((cd, idx) => {
            let statusBadge = '';
            let btnAction = '';

            if (cd.aprobado) {
                statusBadge = `<span class="badge bg-success bg-opacity-15 text-success border border-success border-opacity-25 px-2 py-1"><i class="bi bi-check-circle-fill me-1"></i>Aprobado</span>`;
                btnAction = `<a href="detalle.php?id=${encodeURIComponent(cd.id)}" class="btn btn-sm btn-outline-success"><i class="bi bi-arrow-repeat me-1"></i>Repasar</a>`;
            } else if (cd.porcentaje > 0) {
                statusBadge = `<span class="badge bg-primary bg-opacity-15 text-primary border border-primary border-opacity-25 px-2 py-1"><i class="bi bi-hourglass-split me-1"></i>${cd.porcentaje}%</span>`;
                btnAction = `<a href="detalle.php?id=${encodeURIComponent(cd.id)}" class="btn btn-sm btn-primary"><i class="bi bi-play-fill me-1"></i>Continuar</a>`;
            } else {
                statusBadge = `<span class="badge bg-secondary bg-opacity-15 text-secondary border px-2 py-1"><i class="bi bi-circle me-1"></i>Pendiente</span>`;
                btnAction = `<a href="detalle.php?id=${encodeURIComponent(cd.id)}" class="btn btn-sm btn-outline-primary"><i class="bi bi-play-fill me-1"></i>Iniciar</a>`;
            }

            coursesListHtml += `
                <div class="career-course-item">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold ${cd.aprobado ? 'bg-success text-white' : 'bg-light text-muted'}" style="width: 32px; height: 32px; font-size: 0.85rem;">
                            ${cd.aprobado ? '<i class="bi bi-check"></i>' : (idx + 1)}
                        </div>
                        <div>
                            <div class="fw-semibold text-dark mb-0">${cd.titulo}</div>
                            <small class="text-muted">${cd.totalModulos} módulos formativos</small>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        ${statusBadge}
                        ${btnAction}
                    </div>
                </div>`;
        });

        const graduationBanner = info.completada ? `
            <div class="career-graduation-banner mt-4">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                    <div class="d-flex align-items-center gap-3 text-start">
                        <div class="fs-1 text-warning"><i class="bi bi-patch-check-fill"></i></div>
                        <div>
                            <h6 class="fw-bold text-dark mb-1">¡Felicitaciones! Has completado esta Carrera Profesional</h6>
                            <small class="text-muted">Cumpliste satisfactoriamente con la totalidad del plan de estudios.</small>
                        </div>
                    </div>
                    <button type="button" class="btn btn-warning fw-bold text-dark shadow-sm px-4 py-2 rounded-pill" onclick="descargarDiplomaCarrera('${car.id}', '${(sesion.nombre||'').replace(/'/g, "\\'")}', '${sesion.id}')">
                        <i class="bi bi-award-fill me-2"></i>Descargar Diploma Oficial (PDF)
                    </button>
                </div>
            </div>` : '';

        html += `
            <div class="col-lg-12">
                <div class="career-card">
                    <div class="${headerClass}">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <span class="badge bg-white bg-opacity-20 text-white text-uppercase px-3 py-1 mb-2">
                                    <i class="bi bi-mortarboard-fill me-1"></i>Ruta Profesional
                                </span>
                                <h4 class="fw-bold mb-1">${car.nombre}</h4>
                                <small class="text-white-50">Especialización técnica acreditada por la Universidad del Aluminio</small>
                            </div>
                            <div class="text-end">
                                <div class="fs-4 fw-bold">${info.porcentaje}%</div>
                                <small class="text-white-50">${info.completados} de ${info.total} materias aprobadas</small>
                            </div>
                        </div>
                        <div class="career-progress-wrapper">
                            <div class="${progressFillClass}" style="width: ${info.porcentaje}%;"></div>
                        </div>
                    </div>

                    <div class="p-4">
                        <h6 class="fw-bold text-secondary mb-3"><i class="bi bi-list-check me-2"></i>Plan de Estudios y Materias Requeridas:</h6>
                        <div class="border rounded-3 overflow-hidden bg-white mb-3">
                            ${coursesListHtml}
                        </div>
                        ${graduationBanner}
                    </div>
                </div>
            </div>`;
    });

    contenedor.innerHTML = html;
};

window.renderizarVitrinaCertificados = async function() {
    const contenedor = document.getElementById('vitrina-certificados-row');
    if (!contenedor) return;

    if (!sesion) {
        contenedor.innerHTML = `<div class="col-12 text-center py-4 text-muted">Inicia sesión para consultar tus credenciales.</div>`;
        return;
    }

    const misCarreras = obtenerCarrerasDelUsuario(sesion);
    const certsCarreraIds = Array.isArray(sesion.certificadosCarrera) ? sesion.certificadosCarrera : [];
    const certsCursoIds = Array.isArray(sesion.certificadosCurso) ? sesion.certificadosCurso : [];

    const diplomasCarrera = [];
    misCarreras.forEach(car => {
        const info = calcularProgresoCarrera(sesion, car);
        if (info.completada || certsCarreraIds.includes(car.id)) {
            diplomasCarrera.push({
                tipo: 'carrera',
                id: car.id,
                titulo: car.nombre,
                subtitulo: 'Diploma de Graduación Profesional'
            });
        }
    });

    const certificadosCurso = [];
    (cursos || []).forEach(c => {
        const prog = calcularProgresoCurso(c.id, c);
        if (certsCursoIds.includes(c.id) || prog.completado) {
            certificadosCurso.push({
                tipo: 'curso',
                id: c.id,
                titulo: c.titulo,
                subtitulo: 'Certificación Técnica de Curso'
            });
        }
    });

    const totalCredenciales = diplomasCarrera.length + certificadosCurso.length;

    if (totalCredenciales === 0) {
        contenedor.innerHTML = `
            <div class="col-12 text-center py-5">
                <div class="py-4">
                    <div class="seal-watermark mb-3" style="width: 70px; height: 70px; font-size: 1.8rem; background: #e2e8f0; color: #64748b; box-shadow: none;">
                        <i class="bi bi-award"></i>
                    </div>
                    <h5 class="fw-bold text-secondary">Aún no has obtenido certificados</h5>
                    <p class="text-muted small max-w-500 mx-auto mb-4">Completa los módulos y evaluaciones de tus cursos o carreras para desbloquear tus credenciales oficiales con código QR y verificación pública.</p>
                    <button type="button" class="btn btn-primary" onclick="cambiarVistaCampus('cursos')">
                        <i class="bi bi-grid-fill me-1"></i>Explorar Mis Cursos
                    </button>
                </div>
            </div>`;
        return;
    }

    let html = '';

    for (const cred of diplomasCarrera) {
        let codigo = null;
        if (sesion && Array.isArray(sesion.credenciales)) {
            const cr = sesion.credenciales.find(x => x.id === cred.id && x.tipo === 'carrera');
            if (cr) codigo = cr.codigo;
        }
        if (!codigo && typeof generarCodigoCertificadoAsync === 'function') {
            codigo = await generarCodigoCertificadoAsync(sesion.id, cred.id, 'carrera');
        }

        html += `
            <div class="col-md-6 col-xl-4">
                <div class="credential-card career-diploma">
                    <div>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div class="credential-icon gold"><i class="bi bi-mortarboard-fill"></i></div>
                            <span class="badge bg-warning text-dark fw-bold px-2 py-1"><i class="bi bi-star-fill me-1"></i>CARRERA</span>
                        </div>
                        <h5 class="fw-bold text-primary mb-1">${cred.titulo}</h5>
                        <p class="text-muted small mb-3">${cred.subtitulo}</p>
                        
                        <div class="p-2 bg-light rounded-2 mb-3 border">
                            <small class="text-muted d-block" style="font-size: 0.725rem;">CÓDIGO DE AUTENTICIDAD:</small>
                            <span class="code-pill text-primary" style="font-size: 0.85rem;">${codigo || 'UA-VERIF'}</span>
                        </div>
                    </div>

                    <div class="mt-3">
                        <button type="button" class="btn btn-warning fw-bold text-dark w-100 shadow-sm" onclick="descargarDiplomaCarrera('${cred.id}', '${(sesion.nombre||'').replace(/'/g, "\\'")}', '${sesion.id}')">
                            <i class="bi bi-download me-1"></i>Descargar Diploma PDF
                        </button>
                    </div>
                </div>
            </div>`;
    }

    for (const cred of certificadosCurso) {
        let codigo = null;
        if (sesion && Array.isArray(sesion.credenciales)) {
            const cr = sesion.credenciales.find(x => x.id === cred.id && x.tipo === 'curso');
            if (cr) codigo = cr.codigo;
        }
        if (!codigo && typeof generarCodigoCertificadoAsync === 'function') {
            codigo = await generarCodigoCertificadoAsync(sesion.id, cred.id, 'curso');
        }

        html += `
            <div class="col-md-6 col-xl-4">
                <div class="credential-card course-cert">
                    <div>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div class="credential-icon blue"><i class="bi bi-award-fill"></i></div>
                            <span class="badge bg-primary bg-opacity-15 text-primary fw-bold px-2 py-1">CURSO</span>
                        </div>
                        <h5 class="fw-bold text-primary mb-1">${cred.titulo}</h5>
                        <p class="text-muted small mb-3">${cred.subtitulo}</p>
                        
                        <div class="p-2 bg-light rounded-2 mb-3 border">
                            <small class="text-muted d-block" style="font-size: 0.725rem;">CÓDIGO DE AUTENTICIDAD:</small>
                            <span class="code-pill text-primary" style="font-size: 0.85rem;">${codigo || 'UA-VERIF'}</span>
                        </div>
                    </div>

                    <div class="mt-3">
                        <button type="button" class="btn btn-primary w-100 shadow-sm" onclick="descargarCertificado('${(sesion.nombre||'').replace(/'/g, "\\'")}', '${sesion.id}', '${cred.titulo.replace(/'/g, "\\'")}', '${cred.id}')">
                            <i class="bi bi-download me-1"></i>Descargar Certificado PDF
                        </button>
                    </div>
                </div>
            </div>`;
    }

    contenedor.innerHTML = html;
};

// ============================================================
// MODAL MI PERFIL Y CAMBIO SEGURO DE CONTRASEÑA
// ============================================================

window.abrirModalPerfil = function() {
    if (!sesion) {
        if (typeof showToast === 'function') showToast('Inicia sesión para consultar tu perfil.', 'warning');
        return;
    }

    const modalEl = document.getElementById('modal-mi-perfil');
    if (!modalEl) return;

    // 1. Datos personales
    const elAvatar = document.getElementById('perfil-avatar-lg');
    if (elAvatar) elAvatar.textContent = typeof obtenerIniciales === 'function' ? obtenerIniciales(sesion.nombre) : 'UA';

    const elNombre = document.getElementById('perfil-nombre');
    if (elNombre) elNombre.textContent = sesion.nombre;

    const elCedula = document.getElementById('perfil-cedula');
    if (elCedula) elCedula.textContent = sesion.id;

    const elRol = document.getElementById('perfil-rol');
    if (elRol) elRol.textContent = (sesion.rol === 'admin') ? 'Administrador' : (sesion.rol || 'Estudiante').replace('_', ' ');

    // 2. Calcular métricas del colaborador
    const userProg = sesion.progreso || {};
    let countIniciados = 0;
    let countModulosAprobados = 0;

    Object.keys(userProg).forEach(cid => {
        const p = userProg[cid];
        if (p) {
            const mods = Array.isArray(p.modulosAprobados) ? p.modulosAprobados : [];
            const lecs = Array.isArray(p.leccionesCompletadas) ? p.leccionesCompletadas : [];
            if (mods.length > 0 || lecs.length > 0) countIniciados++;
            countModulosAprobados += mods.length;
        }
    });

    const certsCurso = Array.isArray(sesion.certificadosCurso) ? sesion.certificadosCurso : [];
    const certsCarrera = Array.isArray(sesion.certificadosCarrera) ? sesion.certificadosCarrera : [];
    const totalCerts = certsCurso.length + certsCarrera.length;

    const elDisp = document.getElementById('perfil-kpi-disponibles');
    if (elDisp) elDisp.textContent = Array.isArray(cursos) ? cursos.length : 0;

    const elProg = document.getElementById('perfil-kpi-progreso');
    if (elProg) elProg.textContent = countIniciados;

    const elMods = document.getElementById('perfil-kpi-modulos');
    if (elMods) elMods.textContent = countModulosAprobados;

    const elCert = document.getElementById('perfil-kpi-certificados');
    if (elCert) elCert.textContent = totalCerts;

    // Limpiar formulario de clave
    const formClave = document.getElementById('form-cambiar-clave');
    if (formClave) formClave.reset();

    // Activar pestaña resumen por defecto
    const tabResumenBtn = document.getElementById('tab-perfil-resumen-btn');
    if (tabResumenBtn && window.bootstrap && window.bootstrap.Tab) {
        new bootstrap.Tab(tabResumenBtn).show();
    }

    if (window.bootstrap && window.bootstrap.Modal) {
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }
};

window.guardarNuevaClave = async function(event) {
    if (event) event.preventDefault();

    const inputActual = document.getElementById('input-clave-actual');
    const inputNueva = document.getElementById('input-clave-nueva');
    const inputConfirm = document.getElementById('input-clave-confirmar');
    const btnSubmit = document.getElementById('btn-guardar-clave');

    const claveActual = (inputActual?.value || '').trim();
    const claveNueva = (inputNueva?.value || '').trim();
    const claveConfirmar = (inputConfirm?.value || '').trim();

    if (!claveActual) {
        if (typeof showToast === 'function') showToast('Por favor introduce tu contraseña actual.', 'warning');
        if (inputActual) inputActual.focus();
        return;
    }

    if (claveNueva.length < 4) {
        if (typeof showToast === 'function') showToast('La nueva contraseña debe tener al menos 4 caracteres.', 'warning');
        if (inputNueva) inputNueva.focus();
        return;
    }

    if (claveNueva !== claveConfirmar) {
        if (typeof showToast === 'function') showToast('Las nuevas contraseñas no coinciden. Por favor verifica.', 'warning');
        if (inputConfirm) inputConfirm.focus();
        return;
    }

    if (claveActual === claveNueva) {
        if (typeof showToast === 'function') showToast('La nueva contraseña no puede ser idéntica a la anterior.', 'warning');
        if (inputNueva) inputNueva.focus();
        return;
    }

    try {
        if (btnSubmit) {
            btnSubmit.disabled = true;
            btnSubmit.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Actualizando...';
        }

        const res = await window.API.cambiarClave(claveActual, claveNueva);

        if (typeof showToast === 'function') {
            showToast(res.message || 'Contraseña actualizada con éxito.', 'success');
        }

        if (inputActual) inputActual.value = '';
        if (inputNueva) inputNueva.value = '';
        if (inputConfirm) inputConfirm.value = '';

        const modalEl = document.getElementById('modal-mi-perfil');
        if (modalEl && window.bootstrap && window.bootstrap.Modal) {
            const modalInstance = bootstrap.Modal.getInstance(modalEl);
            if (modalInstance) modalInstance.hide();
        }
    } catch (err) {
        console.error('Error al cambiar contraseña:', err);
        if (typeof showToast === 'function') {
            showToast(err.message || 'Error al cambiar contraseña', 'danger');
        }
    } finally {
        if (btnSubmit) {
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = '<i class="bi bi-check2 me-1"></i>Actualizar Contraseña';
        }
    }
};

window.toggleVerClave = function(inputId, btn) {
    const input = document.getElementById(inputId);
    if (!input) return;
    const isPassword = input.type === 'password';
    input.type = isPassword ? 'text' : 'password';
    const icon = btn.querySelector('i');
    if (icon) {
        icon.className = isPassword ? 'bi bi-eye-slash' : 'bi bi-eye';
    }
};

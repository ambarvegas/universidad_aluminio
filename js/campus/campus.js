/**
 * Universidad del Aluminio — Campus Module
 * Catálogo de cursos del estudiante y progreso por curso.
 * Carreras y certificados: carreras.js. Perfil y contraseña: perfil.js.
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
                    <div class="user-avatar" style="width: 32px; height: 32px; font-size: 0.8rem; cursor: pointer; border: 1.5px solid rgba(255,255,255,0.4);">${escapeHtml(typeof obtenerIniciales === 'function' ? obtenerIniciales(sesion.nombre) : 'UA')}</div>
                    <div class="d-none d-sm-block text-start lh-1 text-white">
                        <div class="text-white small fw-bold">${escapeHtml(sesion.nombre)} <i class="bi bi-gear-fill text-white-50 ms-1" style="font-size: 0.7rem;"></i></div>
                        <small class="text-white-50" style="font-size: 0.725rem;">${escapeHtml((sesion.rol || 'Estudiante').replace('_', ' '))}</small>
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
        cursosVisibles = (cursos || []).filter(c => c.tipo !== 'pruebas').map(c => {
            const esAccesoLibre = c.tipo === 'publico' || c.tipo === 'libre';
            const tieneAcceso = esAccesoLibre || idsAccesoTotal.includes(c.id);
            return { ...c, bloqueado: !tieneAcceso };
        });
    }

    // Botón de filtro 'Pruebas' si el usuario es Administrador
    const filtrosContainer = document.getElementById('filtros-categoria');
    if (filtrosContainer && sesion && sesion.rol === 'admin' && !document.querySelector('button[data-filter="pruebas"]')) {
        const btnPruebas = document.createElement('button');
        btnPruebas.type = 'button';
        btnPruebas.className = 'filter-pill-btn';
        btnPruebas.dataset.filter = 'pruebas';
        btnPruebas.innerHTML = '<i class="bi bi-flask me-1"></i>Pruebas';
        btnPruebas.onclick = () => setFiltroGaleria('pruebas');
        filtrosContainer.appendChild(btnPruebas);
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
        if (filtroGaleriaCategoria === 'pruebas') {
            return c.tipo === 'pruebas';
        } else if (filtroGaleriaCategoria === 'progreso') {
            return c._progresoInfo.porcentaje > 0 && !c._progresoInfo.completado;
        } else if (filtroGaleriaCategoria === 'completados') {
            return c._progresoInfo.completado;
        } else if (filtroGaleriaCategoria === 'libre') {
            return esLibre;
        } else if (filtroGaleriaCategoria === 'especializado') {
            return c.tipo === 'especializado';
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
        if (c.tipo === 'pruebas') {
            badgeEstado = `<span class="badge text-white shadow-sm" style="background:#7c3aed"><i class="bi bi-flask me-1"></i>Pruebas (Admin)</span>`;
        } else if (c.enConstruccion) {
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
                <button class="btn btn-warning text-dark w-100 fw-bold shadow-sm" onclick="showToast(${jsArg(`El curso "${c.titulo || ''}" se encuentra actualmente en construcción. ¡Próximamente disponible!`)}, 'warning')" style="font-size:0.875rem;">
                    <i class="bi bi-cone-striped me-1"></i>En Construcción
                </button>`;
        } else if (c.bloqueado || bloqueadoPorPrelacion) {
            btnAccion = `
                <button class="btn btn-outline-secondary w-100" onclick="${bloqueadoPorPrelacion ? `showToast(${jsArg('Debes completar primero: ' + mensajePrelacion)}, 'warning')` : `solicitarAccesoCurso(${jsArg(c.id)})`}" style="font-size:0.875rem;">
                    <i class="bi ${bloqueadoPorPrelacion ? 'bi-shield-lock-fill' : 'bi-lock-fill'} me-1"></i>
                    ${bloqueadoPorPrelacion ? escapeHtml(mensajePrelacion) : 'Solicitar Acceso'}
                </button>`;
        } else if (prog.completado) {
            btnAccion = `
                <a href="detalle.php?id=${encodeURIComponent(c.id)}" class="btn btn-success w-100" style="font-size:0.875rem;">
                    <i class="bi bi-check2-circle me-1"></i>Repasar / Certificado
                </a>`;
        } else if (prog.porcentaje > 0) {
            btnAccion = `
                <a href="detalle.php?id=${encodeURIComponent(c.id)}" class="btn btn-primary w-100" style="font-size:0.875rem;">
                    <i class="bi bi-play-circle-fill me-1"></i>Continuar (${prog.porcentaje}%)
                </a>`;
        } else {
            btnAccion = `
                <a href="detalle.php?id=${encodeURIComponent(c.id)}" class="btn btn-primary w-100" style="font-size:0.875rem;">
                    <i class="bi bi-arrow-right-circle-fill me-1"></i>Comenzar Curso
                </a>`;
        }

        const imagenUrl = c.imagen || 'https://images.unsplash.com/photo-1581091226825-a6a2a5aee158?w=600&auto=format&fit=crop&q=80';
        const isLocked = c.bloqueado || bloqueadoPorPrelacion;

        galeriaHTML += `
            <div class="col-sm-6 col-lg-4 mb-4">
                <div class="course-card ${isLocked ? 'opacity-75' : ''}">
                    <div class="course-thumb-container">
                        <img src="${escapeHtml(urlSegura(typeof resolverSrcImagen === 'function' ? resolverSrcImagen(imagenUrl) : imagenUrl))}" alt="${escapeHtml(c.titulo)}" style="${isLocked ? 'filter: grayscale(0.85);' : ''}">
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

                        <h5 class="course-title" title="${escapeHtml(c.titulo)}">${escapeHtml(c.titulo)}</h5>

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


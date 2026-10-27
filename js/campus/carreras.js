/**
 * Universidad del Aluminio — Carreras y credenciales del campus
 * Carreras asignadas al estudiante, su progreso y la vitrina de certificados y diplomas.
 */

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
                            <div class="fw-semibold text-dark mb-0">${escapeHtml(cd.titulo)}</div>
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
                    <button type="button" class="btn btn-warning fw-bold text-dark shadow-sm px-4 py-2 rounded-pill" onclick="descargarDiplomaCarrera(${jsArg(car.id)}, ${jsArg(sesion.nombre)}, ${jsArg(sesion.id)})">
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
                                <h4 class="fw-bold mb-1">${escapeHtml(car.nombre)}</h4>
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
                        <h5 class="fw-bold text-primary mb-1">${escapeHtml(cred.titulo)}</h5>
                        <p class="text-muted small mb-3">${cred.subtitulo}</p>
                        
                        <div class="p-2 bg-light rounded-2 mb-3 border">
                            <small class="text-muted d-block" style="font-size: 0.725rem;">CÓDIGO DE AUTENTICIDAD:</small>
                            <span class="code-pill text-primary" style="font-size: 0.85rem;">${escapeHtml(codigo || 'UA-VERIF')}</span>
                        </div>
                    </div>

                    <div class="mt-3">
                        <button type="button" class="btn btn-warning fw-bold text-dark w-100 shadow-sm" onclick="descargarDiplomaCarrera(${jsArg(cred.id)}, ${jsArg(sesion.nombre)}, ${jsArg(sesion.id)})">
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
                        <h5 class="fw-bold text-primary mb-1">${escapeHtml(cred.titulo)}</h5>
                        <p class="text-muted small mb-3">${cred.subtitulo}</p>
                        
                        <div class="p-2 bg-light rounded-2 mb-3 border">
                            <small class="text-muted d-block" style="font-size: 0.725rem;">CÓDIGO DE AUTENTICIDAD:</small>
                            <span class="code-pill text-primary" style="font-size: 0.85rem;">${escapeHtml(codigo || 'UA-VERIF')}</span>
                        </div>
                    </div>

                    <div class="mt-3">
                        <button type="button" class="btn btn-primary w-100 shadow-sm" onclick="descargarCertificado(${jsArg(sesion.nombre)}, ${jsArg(sesion.id)}, ${jsArg(cred.titulo)}, ${jsArg(cred.id)})">
                            <i class="bi bi-download me-1"></i>Descargar Certificado PDF
                        </button>
                    </div>
                </div>
            </div>`;
    }

    contenedor.innerHTML = html;
};

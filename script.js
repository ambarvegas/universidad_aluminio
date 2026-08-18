// 1. GESTIÓN DE DATOS
const DB_KEY = 'uniDatabase';

// Estructura inicial por defecto (Tu JSON base)
const initialDB = {
    usuarios: [
        { id: "25482938", clave: "12345", nombre: "Ambar Vegas", rol: "admin", estado: "activo", asignados: [], carreras: [], carrerasAsignadas: [], examenesAprobados: {}, progreso: {}, certificadosCurso: [], certificadosCarrera: [] }
    ],
    cursos: [],
    carreras: [],
    rolesConfig: [
        { id: "admin", nombre: "Administrador", permisos: ["*"] },
        { id: "asesor_ventas", nombre: "Asesor de Ventas", cursos: [], carreras: [] },
        { id: "proyectista", nombre: "Proyectista / Diseño", cursos: [], carreras: [] },
        { id: "participante", nombre: "Participante General", cursos: [], carreras: [] }
    ],
    solicitudesRegistro: [],
    solicitudesCursos: [],
    configuracion: { nombreInstitucion: "Universidad del Aluminio", logo: "", minAprobacion: 70 }
};

let db = JSON.parse(JSON.stringify(initialDB));

let usuarios = db.usuarios || [];
let cursos = db.cursos || [];
let carreras = db.carreras || [];
let rolesConfig = db.rolesConfig || [];
let tempModuloEvaluacion = { preguntas: [] };
let solicitudesRegistro = db.solicitudesRegistro || [];
let solicitudesCursos = db.solicitudesCursos || [];

const getCareerIdFromRole = (roleId) => {
    const careerMap = {
        "asesor_ventas": "CAR-ASESOR-VENTAS",
        "retail": "CAR-RETAIL",
        "almacenista_retail": "CAR-ALMACENISTA",
        "coordinador_retail": "CAR-COORD-RETAIL",
        "cristalero": "CAR-CRISTALERO"
    };
    return careerMap[roleId] || null;
};

function crearEstructuraUsuario(u) {
    if (!u) return null;
    return {
        id: String(u.id || "").trim(),
        nombre: String(u.nombre || "").trim(),
        clave: String(u.clave || "12345"),
        rol: String(u.rol || "participante"),
        estado: String(u.estado || "activo"),
        asignados: Array.isArray(u.asignados) ? u.asignados : [],
        carrerasAsignadas: Array.isArray(u.carrerasAsignadas) ? u.carrerasAsignadas : [],
        progreso: (u.progreso && typeof u.progreso === 'object' && !Array.isArray(u.progreso)) ? u.progreso : {},
        certificadosCurso: Array.isArray(u.certificadosCurso) ? u.certificadosCurso : [],
        certificadosCarrera: Array.isArray(u.certificadosCarrera) ? u.certificadosCarrera : []
    };
}

function actualizarEstadoCarrerasUsuario(usuario) {
    if (!usuario) return;
    usuario.carrerasAsignadas = Array.isArray(usuario.carrerasAsignadas) ? usuario.carrerasAsignadas : [];
    usuario.certificadosCarrera = Array.isArray(usuario.certificadosCarrera) ? usuario.certificadosCarrera : [];
    usuario.certificadosCurso = Array.isArray(usuario.certificadosCurso) ? usuario.certificadosCurso : [];
    if (!usuario.progreso || Array.isArray(usuario.progreso) || typeof usuario.progreso !== 'object') {
        usuario.progreso = {};
    }

    const userRoleConfig = rolesConfig.find(r => r.id === usuario.rol);
    const roleCareerIds = (userRoleConfig && Array.isArray(userRoleConfig.carreras)) ? userRoleConfig.carreras : [];
    
    const autoCareerId = getCareerIdFromRole(usuario.rol);
    const todasCarrerasIds = new Set([...roleCareerIds]);
    if (autoCareerId) todasCarrerasIds.add(autoCareerId);

    todasCarrerasIds.forEach(carId => {
        if (carreras.some(c => c.id === carId)) {
            if (!usuario.carrerasAsignadas.some(ca => ca.id === carId)) {
                usuario.carrerasAsignadas.push({ id: carId, estado: "Incompleta" });
            }
        }
    });

    usuario.carrerasAsignadas.forEach(ca => {
        const carrera = carreras.find(c => c.id === ca.id);
        if (!carrera || !carrera.cursos || carrera.cursos.length === 0) return;

        const todosCursosCompletados = carrera.cursos.every(cId => {
            if (usuario.certificadosCurso && usuario.certificadosCurso.includes(cId)) return true;
            const cursoObj = cursos.find(c => c.id === cId);
            if (!cursoObj) return false;
            const prog = usuario.progreso[cId];
            if (!prog || !prog.modulosAprobados) return false;
            const totalModulos = (cursoObj.modulos || []).length;
            return totalModulos > 0 && prog.modulosAprobados.length >= totalModulos;
        });

        if (todosCursosCompletados) {
            ca.estado = "Completada";
            if (!usuario.certificadosCarrera.includes(ca.id)) {
                usuario.certificadosCarrera.push(ca.id);
            }
        } else {
            ca.estado = "Incompleta";
            usuario.certificadosCarrera = usuario.certificadosCarrera.filter(id => id !== ca.id);
        }
    });
}

async function cargarDatosDelServidor() {
    try {
        const response = await fetch('api.php');
        const data = await response.json();

        if (data && typeof data === 'object' && !Array.isArray(data)) {
            db = data;
        }

        usuarios = db.usuarios || [];
        cursos = db.cursos || [];
        carreras = db.carreras || [];
        rolesConfig = db.rolesConfig || [];
        solicitudesRegistro = db.solicitudesRegistro || [];
        solicitudesCursos = db.solicitudesCursos || [];

        usuarios.forEach(u => {
            u.asignados = Array.isArray(u.asignados) ? u.asignados : [];
            u.carrerasAsignadas = Array.isArray(u.carrerasAsignadas) ? u.carrerasAsignadas : [];
            u.certificadosCurso = Array.isArray(u.certificadosCurso) ? u.certificadosCurso : [];
            u.certificadosCarrera = Array.isArray(u.certificadosCarrera) ? u.certificadosCarrera : [];

            // CRÍTICO: Asegurar que progreso sea SIEMPRE un objeto {} y NUNCA un array []
            if (!u.progreso || Array.isArray(u.progreso) || typeof u.progreso !== 'object') {
                u.progreso = {};
            }

            for (let cursoId in u.progreso) {
                if (Array.isArray(u.progreso[cursoId])) {
                    u.progreso[cursoId] = {
                        leccionesCompletadas: u.progreso[cursoId],
                        modulosAprobados: [],
                        medallas: [],
                        evaluaciones: {},
                        intentos: {}
                    };
                } else if (u.progreso[cursoId] && typeof u.progreso[cursoId] === 'object') {
                    u.progreso[cursoId].leccionesCompletadas = Array.isArray(u.progreso[cursoId].leccionesCompletadas) ? u.progreso[cursoId].leccionesCompletadas : [];
                    u.progreso[cursoId].modulosAprobados = Array.isArray(u.progreso[cursoId].modulosAprobados) ? u.progreso[cursoId].modulosAprobados : [];
                    u.progreso[cursoId].medallas = Array.isArray(u.progreso[cursoId].medallas) ? u.progreso[cursoId].medallas : [];
                    u.progreso[cursoId].evaluaciones = (u.progreso[cursoId].evaluaciones && typeof u.progreso[cursoId].evaluaciones === 'object' && !Array.isArray(u.progreso[cursoId].evaluaciones)) ? u.progreso[cursoId].evaluaciones : {};
                    u.progreso[cursoId].intentos = (u.progreso[cursoId].intentos && typeof u.progreso[cursoId].intentos === 'object' && !Array.isArray(u.progreso[cursoId].intentos)) ? u.progreso[cursoId].intentos : {};
                }
            }

            actualizarEstadoCarrerasUsuario(u);
        });

        // Sincronizar sesión activa si existe
        if (sesion) {
            const usuarioFresco = usuarios.find(u => u.id === sesion.id);
            if (usuarioFresco) {
                sesion = usuarioFresco;
                sessionStorage.setItem('aluSesion', JSON.stringify(sesion));
            }
        }

        if (typeof actualizarTablas === 'function') actualizarTablas();
        if (document.getElementById('cfg-min-aprobacion')) {
            document.getElementById('cfg-min-aprobacion').value = db.configuracion ? (db.configuracion.minAprobacion || 70) : 70;
        }
        renderizarGaleria();
    } catch (err) {
        console.error("Error al conectar con la base de datos del servidor:", err);
    }
}

const guardarTodo = async () => {
    // Asegurar que todos los usuarios en memoria tengan progreso como objeto {}
    usuarios.forEach(u => {
        if (!u.progreso || Array.isArray(u.progreso) || typeof u.progreso !== 'object') {
            u.progreso = {};
        }
    });

    // Sincronizar la variable 'sesion' con el array 'usuarios' antes de guardar.
    if (sesion) {
        if (!sesion.progreso || Array.isArray(sesion.progreso) || typeof sesion.progreso !== 'object') {
            sesion.progreso = {};
        }
        const uIdx = usuarios.findIndex(u => u.id === sesion.id);
        if (uIdx !== -1) {
            usuarios[uIdx] = JSON.parse(JSON.stringify(sesion));
            actualizarEstadoCarrerasUsuario(usuarios[uIdx]);
            sesion = JSON.parse(JSON.stringify(usuarios[uIdx]));
            sessionStorage.setItem('aluSesion', JSON.stringify(sesion));
        }
    }

    db.usuarios = usuarios;
    db.cursos = cursos;
    db.carreras = carreras;
    db.rolesConfig = rolesConfig;
    db.solicitudesRegistro = solicitudesRegistro;
    db.solicitudesCursos = solicitudesCursos;

    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(db)
        });
        if (!response.ok) {
            const errData = await response.json().catch(() => ({ error: 'Error desconocido en servidor' }));
            console.error("Error al guardar en el servidor:", response.status, errData);
            alert(`Atención: No se pudo guardar la información en el servidor (${errData.error || response.statusText}).`);
            return false;
        } else {
            console.log("Sincronizado correctamente con la base de datos del servidor (api.php)");
            return true;
        }
    } catch (err) {
        console.error("Error de conexión al guardar en el servidor:", err);
        alert("Atención: Hubo un problema de conexión al guardar los datos en el servidor.");
        return false;
    }
};

let sesion = JSON.parse(sessionStorage.getItem('aluSesion')) || null;

// ========== FUNCIÓN AUXILIAR PARA GUARDAR PROGRESO ==========
async function guardarProgresoUsuario() {
    if (!sesion) return false;

    actualizarEstadoCarrerasUsuario(sesion);
    sessionStorage.setItem('aluSesion', JSON.stringify(sesion));

    const exito = await guardarTodo();
    if (exito) {
        return true;
    } else {
        alert("No se pudo guardar tu progreso en el servidor. Intenta de nuevo.");
        return false;
    }
}

function renderizarGaleria() {
    const galeria = document.querySelector('#galeria-cursos .row');
    if (galeria) {
        galeria.innerHTML = '';

        let cursosVisibles = [];
        if (sesion && sesion.rol === 'admin') {
            cursosVisibles = cursos.map(c => ({ ...c, bloqueado: false }));
        } else {
            const configRol = sesion ? rolesConfig.find(r => r.id === sesion.rol) : null;
            const directosDelRol = configRol ? configRol.cursos || [] : [];
            const deCarreras = [];
            if (configRol && configRol.carreras) {
                configRol.carreras.forEach(carId => {
                    const carrera = carreras.find(c => c.id === carId);
                    if (carrera && Array.isArray(carrera.cursos)) deCarreras.push(...carrera.cursos);
                });
            }
            if (sesion && Array.isArray(sesion.carrerasAsignadas)) {
                sesion.carrerasAsignadas.forEach(ca => {
                    const carrera = carreras.find(c => c.id === ca.id);
                    if (carrera && Array.isArray(carrera.cursos)) deCarreras.push(...carrera.cursos);
                });
            }
            const porSolicitud = (sesion && sesion.asignados) || [];
            const idsAccesoTotal = [...new Set([...directosDelRol, ...deCarreras, ...porSolicitud])];
            cursosVisibles = cursos.map(c => {
                const esAccesoLibre = c.tipo === 'publico' || c.tipo === 'libre';
                const tieneAcceso = esAccesoLibre || idsAccesoTotal.includes(c.id);
                return { ...c, bloqueado: !tieneAcceso };
            });
        }

        if (cursosVisibles.length === 0) {
            galeria.innerHTML = '<div class="col-12 text-center py-5"><p class="lead text-muted">Aún no tienes cursos asignados.</p></div>';
            return;
        }

        let galeriaHTML = '';
        cursosVisibles.forEach(c => {
            // Verificar prelación
            let bloqueadoPorPrelacion = false;
            let mensajePrelacion = "";
            if (c.prelacion) {
                const cursoPrevio = cursos.find(cp => cp.id === c.prelacion);
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
            const badgeTipo = esAccesoLibre ? '<span class="badge bg-success"><i class="bi bi-unlock-fill me-1"></i>Acceso Libre</span>' : '<span class="badge bg-warning text-dark"><i class="bi bi-mortarboard-fill me-1"></i>Especializado</span>';
            const btnAccion = (c.bloqueado || bloqueadoPorPrelacion)
                ? `<button class="btn btn-outline-secondary w-100" onclick="${bloqueadoPorPrelacion ? "alert('Debes completar primero: " + mensajePrelacion + "')" : "solicitarAccesoCurso('" + c.id + "')"}">${bloqueadoPorPrelacion ? mensajePrelacion : 'Solicitar Acceso'}</button>`
                : `<a href="detalle.html?id=${c.id}" class="btn btn-primary w-100">Ver curso</a>`;

            galeriaHTML += `
                <div class="col-md-4 mb-4 ${(c.bloqueado || bloqueadoPorPrelacion) ? 'opacity-75' : ''}">
                    <div class="card h-100 shadow-sm border-0">
                        <div class="position-relative">
                            <img src="${c.imagen}" class="card-img-top" style="height:200px; object-fit:cover; ${(c.bloqueado || bloqueadoPorPrelacion) ? 'filter: grayscale(1)' : ''}">
                            ${(c.bloqueado || bloqueadoPorPrelacion) ? '<div class="position-absolute top-50 start-50 translate-middle"><i class="bi bi-lock-fill display-4 text-white"></i></div>' : ''}
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between mb-2">
                                ${badgeTipo}
                                ${c.prelacion ? '<span class="badge bg-info text-dark">Con Prelación</span>' : ''}
                            </div>
                            <h5 class="fw-bold">${c.titulo}</h5>
                            ${btnAccion}
                        </div>
                    </div>
                </div>`;
        });
        galeria.innerHTML = galeriaHTML;
    }
}

const guardar = guardarTodo;
const guardarCarreras = guardarTodo;
const guardarUsuarios = guardarTodo;
const guardarRoles = guardarTodo;
const guardarSolicitudes = guardarTodo;
const guardarLogo = async (logo) => {
    if (!db.configuracion) db.configuracion = {};
    db.configuracion.logo = logo;
    await guardarTodo();
};

window.login = (id, clave) => {
    const u = usuarios.find(user => user.id === id && user.clave === clave && (user.estado === 'activo' || !user.estado));
    if (u) {
        sessionStorage.setItem('aluSesion', JSON.stringify(u));
        sesion = u;
        return true;
    }
    return false;
};

window.solicitarRegistro = async (id, nombre, clave, perfilDeseado) => {
    if (usuarios.find(u => u.id === id)) return alert("La cédula ya se encuentra registrada.");
    const nuevaSolicitud = { id, nombre, clave, perfilDeseado, fecha: new Date().toLocaleDateString() };

    const autoAssignCareerMap = {
        "asesor_ventas": "CAR-ASESOR-VENTAS",
        "retail": "CAR-RETAIL",
        "almacenista_retail": "CAR-ALMACENISTA",
        "coordinador_retail": "CAR-COORD-RETAIL",
        "cristalero": "CAR-CRISTALERO"
    };
    if (autoAssignCareerMap[perfilDeseado]) {
        nuevaSolicitud.autoAssignCareerId = autoAssignCareerMap[perfilDeseado];
    }

    solicitudesRegistro.push(nuevaSolicitud);
    await guardarSolicitudes();
    alert("Solicitud enviada. Un administrador revisará su acceso pronto.");
    location.reload();
};

window.gestionarSolicitudRegistro = async (id, aprobado) => {
    const idx = solicitudesRegistro.findIndex(s => s.id === id);
    const sol = solicitudesRegistro[idx];
    if (aprobado && sol) {
        const userCareers = [];
        const autoAssignCareerId = getCareerIdFromRole(sol.perfilDeseado);
        if (autoAssignCareerId) {
            if (carreras.some(c => c.id === autoAssignCareerId)) {
                userCareers.push({ id: autoAssignCareerId, estado: "Incompleta" });
            } else {
                console.warn(`Career ID ${autoAssignCareerId} for role ${sol.perfilDeseado} not found.`);
            }
        }

        const nuevoUsuario = crearEstructuraUsuario({
            id: sol.id,
            nombre: sol.nombre,
            clave: sol.clave,
            rol: sol.perfilDeseado,
            estado: "activo",
            asignados: [],
            carrerasAsignadas: userCareers,
            progreso: {},
            certificadosCurso: [],
            certificadosCarrera: []
        });

        usuarios.push(nuevoUsuario);
        await guardarUsuarios();
        console.log(`Enviando correo a ${sol.id}... Credenciales aprobadas para perfil ${sol.perfilDeseado}`);
    }
    solicitudesRegistro.splice(idx, 1);
    await guardarSolicitudes();
    location.reload();
};

window.solicitarAccesoCurso = async (cursoId) => {
    const yaSolicitado = solicitudesCursos.find(s => s.userId === sesion.id && s.cursoId === cursoId);
    if (yaSolicitado) return alert("Ya tienes una solicitud pendiente para este curso.");

    solicitudesCursos.push({ userId: sesion.id, cursoId, userName: sesion.nombre, fecha: new Date().toLocaleDateString() });
    await guardarSolicitudes();
    alert("Solicitud de acceso enviada al administrador.");
    location.reload();
};

window.gestionarSolicitudCurso = async (userId, cursoId, aprobado) => {
    const idx = solicitudesCursos.findIndex(s => s.userId === userId && s.cursoId === cursoId);
    if (aprobado) {
        const uIdx = usuarios.findIndex(u => u.id === userId);
        if (uIdx !== -1) {
            usuarios[uIdx].asignados = Array.isArray(usuarios[uIdx].asignados) ? usuarios[uIdx].asignados : [];
            if (!usuarios[uIdx].asignados.includes(cursoId)) {
                usuarios[uIdx].asignados.push(cursoId);
            }
            await guardarUsuarios();
        }
    }
    solicitudesCursos.splice(idx, 1);
    await guardarSolicitudes();
    location.reload();
};

window.logout = () => {
    sessionStorage.removeItem('aluSesion');
    window.location.href = 'login.html';
};

const verificarProteccion = () => {
    const path = window.location.pathname;
    if (!sesion && !path.includes('login.html')) {
        window.location.href = 'login.html';
    }
    if (sesion && sesion.rol !== 'admin' && path.includes('admin.html')) {
        window.location.href = 'index.html';
    }
};

let tempModulos = [];
let tempImagenPortada = "";
const normalizar = (texto) => texto ? texto.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase() : "";
let cursoActualData = null;

window.prepararFormulario = (modo) => {
    const form = document.getElementById('form-curso');
    const idActual = document.getElementById('edit-id').value;

    if (tempModulos.length > 0 && !idActual) {
        if (!confirm("Hay un curso en proceso de creación. ¿Deseas borrar los datos actuales y empezar de cero?")) return;
    }

    if (form) form.reset();
    document.getElementById('edit-id').value = '';
    document.getElementById('titulo').value = '';
    document.getElementById('descripcion').value = '';
    document.getElementById('curso-prelacion').value = '';
    if (document.getElementById('curso-tipo')) {
        document.getElementById('curso-tipo').value = 'especializado';
    }
    const fileInput = document.getElementById('input-portada');
    if (fileInput) fileInput.value = '';
    tempModulos = [];
    tempImagenPortada = "";
    mostrarVistaPreviaPortada();
    document.getElementById('modalTitulo').innerText = "Nuevo Curso";

    const selPrelacion = document.getElementById('curso-prelacion');
    if (selPrelacion) {
        selPrelacion.innerHTML = '<option value="">Ninguno</option>' + cursos.map(c => `<option value="${c.id}">${c.titulo}</option>`).join('');
    }

    renderModulosEditor();
};

window.mostrarVistaPreviaPortada = () => {
    const vistaPrev = document.getElementById('vista-previa-portada');
    const imgPrev = document.getElementById('img-vista-previa');
    if (vistaPrev && imgPrev) {
        if (tempImagenPortada) {
            imgPrev.src = tempImagenPortada;
            vistaPrev.style.display = 'block';
        } else {
            vistaPrev.style.display = 'none';
            imgPrev.src = '';
        }
    }
};

window.eliminarImagenPortada = () => {
    tempImagenPortada = "";
    mostrarVistaPreviaPortada();
    const fileInput = document.getElementById('input-portada');
    if (fileInput) fileInput.value = "";
};

window.abrirEditor = (id) => {
    const c = cursos.find(item => item.id === id);
    if (!c) return;

    document.getElementById('edit-id').value = c.id;
    document.getElementById('titulo').value = c.titulo;
    document.getElementById('descripcion').value = c.descripcion;
    if (document.getElementById('curso-prelacion')) {
        document.getElementById('curso-prelacion').value = c.prelacion || '';
    }
    if (document.getElementById('curso-tipo')) {
        document.getElementById('curso-tipo').value = (c.tipo === 'publico' || c.tipo === 'libre') ? 'publico' : 'especializado';
    }
    tempImagenPortada = c.imagen || "";
    mostrarVistaPreviaPortada();

    tempModulos = JSON.parse(JSON.stringify(c.modulos || []));
    renderModulosEditor();

    document.getElementById('modalTitulo').innerText = "Editar Curso";

    const selPrelacion = document.getElementById('curso-prelacion');
    if (selPrelacion) {
        selPrelacion.innerHTML = '<option value="">Ninguno</option>' + cursos.filter(item => item.id !== id).map(c => `<option value="${c.id}">${c.titulo}</option>`).join('');
        selPrelacion.value = c.prelacion || '';
    }

    const modalElement = document.getElementById('cursoModal');
    const bModal = new bootstrap.Modal(modalElement);
    bModal.show();
};

window.eliminarModulo = (idx) => {
    tempModulos.splice(idx, 1);
    renderModulosEditor();
};

window.subirModulo = (idx) => {
    if (idx > 0) {
        [tempModulos[idx], tempModulos[idx - 1]] = [tempModulos[idx - 1], tempModulos[idx]];
        renderModulosEditor();
    }
};

window.bajarModulo = (idx) => {
    if (idx < tempModulos.length - 1) {
        [tempModulos[idx], tempModulos[idx + 1]] = [tempModulos[idx + 1], tempModulos[idx]];
        renderModulosEditor();
    }
};

window.agregarModulo = () => {
    tempModulos.push({ titulo: "Nuevo Módulo", lecciones: [] });
    renderModulosEditor();
};

window.abrirEditorModuloEvaluacion = (mIdx) => {
    const modulo = tempModulos[mIdx];
    tempModuloEvaluacion = JSON.parse(JSON.stringify(modulo.evaluacion || { preguntas: [] }));

    document.getElementById('modalModuloEvaluacionTitulo').innerText = `Evaluación: ${modulo.titulo}`;
    document.getElementById('edit-modulo-idx').value = mIdx;

    renderPreguntasModuloEditor();

    const modalElement = document.getElementById('moduloEvaluacionModal');
    const bModal = new bootstrap.Modal(modalElement);
    bModal.show();
};

window.agregarPreguntaModulo = () => {
    if (!tempModuloEvaluacion.preguntas) tempModuloEvaluacion.preguntas = [];
    tempModuloEvaluacion.preguntas.push({
        enunciado: "Nueva pregunta",
        opciones: ["Opción A", "Opción B"],
        correcta: 0
    });
    renderPreguntasModuloEditor();
};

window.eliminarPreguntaModulo = (idx) => {
    tempModuloEvaluacion.preguntas.splice(idx, 1);
    renderPreguntasModuloEditor();
};

window.guardarEvaluacionModulo = () => {
    const mIdx = document.getElementById('edit-modulo-idx').value;
    tempModulos[mIdx].evaluacion = JSON.parse(JSON.stringify(tempModuloEvaluacion));

    alert("Evaluación del módulo guardada temporalmente.");
    const modalElement = document.getElementById('moduloEvaluacionModal');
    const bModal = bootstrap.Modal.getOrCreateInstance(modalElement);
    bModal.hide();

    const backdrop = document.querySelector('.modal-backdrop');
    if (backdrop) backdrop.remove();
    document.body.classList.remove('modal-open');
    document.body.style.overflow = '';
    document.body.style.paddingRight = '';
};

function eliminarPreguntaOpcionesModulo(idx) {
    tempModuloEvaluacion.preguntas[idx].opciones.pop();
    renderPreguntasModuloEditor();
}

function renderPreguntasModuloEditor() {
    const container = document.getElementById('contenedor-preguntas-modulo-editor');
    if (!container) return;

    container.innerHTML = (tempModuloEvaluacion.preguntas || []).map((p, pIdx) => `
        <div class="card p-3 mb-3 bg-white shadow-sm">
            <div class="d-flex justify-content-between mb-2">
                <input type="text" class="form-control me-2" value="${p.enunciado}" oninput="tempModuloEvaluacion.preguntas[${pIdx}].enunciado = this.value">
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="eliminarPreguntaModulo(${pIdx})">X</button>
            </div>
            ${p.opciones.map((opt, oIdx) => `
                <div class="input-group mb-1">
                    <div class="input-group-text">
                        <input type="radio" name="correcta-mod-${pIdx}" ${p.correcta == oIdx ? 'checked' : ''} onclick="tempModuloEvaluacion.preguntas[${pIdx}].correcta = ${oIdx}">
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="eliminarPreguntaOpcionesModulo(${pIdx})">X</button>
                    </div>
                    <input type="text" class="form-control form-control-sm" value="${opt}" oninput="tempModuloEvaluacion.preguntas[${pIdx}].opciones[${oIdx}] = this.value">
                </div>
            `).join('')}
            <button type="button" class="btn btn-sm btn-link" onclick="tempModuloEvaluacion.preguntas[${pIdx}].opciones.push('Nueva Opción'); renderPreguntasModuloEditor()">+ Añadir Opción</button>
        </div>
    `).join('') + `<button type="button" class="btn btn-outline-dark w-100" onclick="agregarPreguntaModulo()">+ Añadir Pregunta al Examen</button>`;
}

window.agregarLeccion = (mIdx) => {
    tempModulos[mIdx].lecciones.push({ titulo: "Nueva Lección", videoID: "", contenido: "", adjunto: "" });
    renderModulosEditor();
};

window.eliminarLeccion = (mIdx, lIdx) => {
    tempModulos[mIdx].lecciones.splice(lIdx, 1);
    renderModulosEditor();
};

function extraerID(input) {
    const regExp = /^.*(youtu.be\/|v\/|u\/\w\/|embed\/|watch\?v=|\&v=)([^#\&\?]*).*/;
    const match = input.match(regExp);
    return (match && match[2].length === 11) ? match[2] : input;
}

function comprimirImagenBase64(file, maxWidth = 1000, maxQuality = 0.82) {
    return new Promise((resolve, reject) => {
        if (!file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = (e) => resolve(e.target.result);
            reader.onerror = (err) => reject(err);
            reader.readAsDataURL(file);
            return;
        }

        const reader = new FileReader();
        reader.onload = (e) => {
            const img = new Image();
            img.onload = () => {
                const canvas = document.createElement('canvas');
                let width = img.width;
                let height = img.height;

                if (width > maxWidth) {
                    height = Math.round((height * maxWidth) / width);
                    width = maxWidth;
                }

                canvas.width = width;
                canvas.height = height;

                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0, width, height);

                const dataUrl = canvas.toDataURL('image/jpeg', maxQuality);
                resolve(dataUrl);
            };
            img.onerror = (err) => reject(err);
            img.src = e.target.result;
        };
        reader.onerror = (err) => reject(err);
        reader.readAsDataURL(file);
    });
}

window.cargarLogoInstitucion = async (event) => {
    const file = event.target.files[0];
    if (!file) return;
    try {
        const compressed = await comprimirImagenBase64(file, 600, 0.85);
        guardarLogo(compressed);
        alert("Logo de la Universidad actualizado correctamente.");
    } catch (e) {
        console.error("Error al cargar logo:", e);
    }
};

window.exportarBaseDeDatos = () => {
    const dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(db, null, 2));
    const downloadAnchorNode = document.createElement('a');
    downloadAnchorNode.setAttribute("href", dataStr);
    downloadAnchorNode.setAttribute("download", "universidad_aluminio_db.json");
    document.body.appendChild(downloadAnchorNode);
    downloadAnchorNode.click();
    downloadAnchorNode.remove();
};

window.importarBaseDeDatos = (event) => {
    const file = event.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = async (e) => {
        try {
            const importedData = JSON.parse(e.target.result);
            if (!importedData || typeof importedData !== 'object' || !Array.isArray(importedData.usuarios) || !Array.isArray(importedData.cursos)) {
                return alert("Error: El archivo JSON no tiene la estructura requerida para la base de datos.");
            }
            if (confirm("¿Estás seguro? Esto reemplazará toda la información actual por la información del archivo importado.")) {
                db = importedData;
                usuarios = db.usuarios || [];
                cursos = db.cursos || [];
                carreras = db.carreras || [];
                rolesConfig = db.rolesConfig || [];
                solicitudesRegistro = db.solicitudesRegistro || [];
                solicitudesCursos = db.solicitudesCursos || [];

                await guardarTodo();
                alert("Base de datos importada y sincronizada exitosamente con el servidor.");
                location.reload();
            }
        } catch (err) {
            console.error("Error al importar la base de datos:", err);
            alert("Error: El archivo JSON no es válido.");
        }
    };
    reader.readAsText(file);
};

window.cargarImagenPortada = async (event) => {
    const file = event.target.files[0];
    if (!file) return;
    try {
        const compressed = await comprimirImagenBase64(file, 1000, 0.82);
        tempImagenPortada = compressed;
        mostrarVistaPreviaPortada();
    } catch (err) {
        console.error("Error al procesar la imagen de portada:", err);
        alert("No se pudo cargar la imagen de portada.");
    }
};

window.cargarArchivoLeccion = (event, mIdx, lIdx) => {
    const file = event.target.files[0];
    if (!file) return;

    if (file.size > 100 * 1024 * 1024) {
        alert("El archivo es demasiado grande. Por favor, sube archivos de máximo 100MB.");
        event.target.value = "";
        return;
    }

    const reader = new FileReader();
    reader.onload = (e) => {
        tempModulos[mIdx].lecciones[lIdx].adjunto = e.target.result;
        tempModulos[mIdx].lecciones[lIdx].nombreAdjunto = file.name;
        renderModulosEditor();
    };
    reader.readAsDataURL(file);
};

function renderModulosEditor() {
    const container = document.getElementById('contenedor-modulos-editor');
    if (!container) return;
    container.innerHTML = tempModulos.map((mod, mIdx) => `
        <div class="border p-3 mb-3 bg-light rounded shadow-sm">
            <div class="d-flex align-items-center mb-2">
                <div class="btn-group me-3">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="subirModulo(${mIdx})" ${mIdx === 0 ? 'disabled' : ''} title="Subir Módulo"><i class="bi bi-arrow-up"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="bajarModulo(${mIdx})" ${mIdx === tempModulos.length - 1 ? 'disabled' : ''} title="Bajar Módulo"><i class="bi bi-arrow-down"></i></button>
                </div>
                <input type="text" class="form-control fw-bold me-2" value="${mod.titulo}" oninput="tempModulos[${mIdx}].titulo = this.value">
                <div class="input-group me-2" style="max-width: 140px;" title="Intentos máximos para la evaluación (0 o vacío para ilimitados)">
                    <span class="input-group-text"><i class="bi bi-arrow-repeat"></i></span>
                    <input type="number" class="form-control form-control-sm" placeholder="Intentos" value="${mod.maxIntentos || ''}" oninput="tempModulos[${mIdx}].maxIntentos = parseInt(this.value) || 0">
                </div>
                <button type="button" class="btn btn-sm btn-primary me-2" onclick="abrirEditorModuloEvaluacion(${mIdx})">
                    <i class="bi bi-clipboard-check"></i> Evaluación
                </button>
                <button type="button" class="btn btn-sm btn-danger" onclick="eliminarModulo(${mIdx})" title="Eliminar Módulo">
                    <i class="bi bi-trash"></i> Eliminar
                </button>
            </div>
            <div class="ms-4 border-start ps-3">
                ${mod.lecciones.map((lec, lIdx) => `
                    <div class="card p-2 mb-2 bg-white">
                        <input type="text" class="form-control form-control-sm mb-1" placeholder="Título Lección" value="${lec.titulo}" oninput="tempModulos[${mIdx}].lecciones[${lIdx}].titulo = this.value">
                        <div class="row g-2">
                            <div class="col-8">
                                <input type="text" class="form-control form-control-sm mb-1" placeholder="URL de YouTube" value="${lec.videoID ? 'https://www.youtube.com/watch?v=' + lec.videoID : ''}" oninput="tempModulos[${mIdx}].lecciones[${lIdx}].videoID = extraerID(this.value)">
                            </div>
                            <div class="col-4">
                                ${lec.videoID ? `<button type="button" class="btn btn-sm btn-dark w-100" onclick="window.open('https://youtube.com/embed/${lec.videoID}')">Ver</button>` : ''}
                            </div>
                        </div>
                        <textarea class="form-control form-control-sm mb-1" placeholder="Contenido..." oninput="tempModulos[${mIdx}].lecciones[${lIdx}].contenido = this.value">${lec.contenido || ''}</textarea>
                        <div class="d-flex justify-content-between align-items-center">
                            <input type="file" class="form-control form-control-sm" style="max-width: 200px;" onchange="cargarArchivoLeccion(event, ${mIdx}, ${lIdx})">
                            <button type="button" class="btn btn-link btn-sm text-danger" onclick="eliminarLeccion(${mIdx}, ${lIdx})">Eliminar</button>
                        </div>
                        ${lec.nombreAdjunto ? `<div class="small text-success mt-1"><i class="bi bi-paperclip"></i> ${lec.nombreAdjunto}</div>` : ''}
                    </div>
                `).join('')}
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="agregarLeccion(${mIdx})">+ Añadir Lección</button>
            </div>
        </div>
    `).join('') + `<button type="button" class="btn btn-primary w-100 mt-2" onclick="agregarModulo()">+ Añadir Nuevo Módulo</button>`;
}

window.eliminarCurso = async (id) => {
    if (confirm('¿Estás seguro de eliminar este curso y todo su contenido?')) {
        cursos = cursos.filter(c => String(c.id) !== String(id));
        await guardar();
        alert("Curso eliminado con éxito.");
        location.reload();
    }
};

window.prepararFormularioUsuario = () => {
    const form = document.getElementById('form-usuario-integral');
    if (form) form.reset();
    document.getElementById('u-id').disabled = false;
    document.getElementById('userModalTitle').innerText = "Nuevo Colaborador";
    renderSelectRoles();
};

window.abrirEditorUsuario = (id) => {
    const u = usuarios.find(user => user.id === id);
    if (!u) return;

    document.getElementById('userModalTitle').innerText = `Editando: ${u.nombre}`;
    document.getElementById('u-id').value = u.id;
    document.getElementById('u-id').disabled = true;
    document.getElementById('u-nombre').value = u.nombre;

    renderSelectRoles();
    document.getElementById('u-rol').value = u.rol;
    document.getElementById('u-estado').value = u.estado || 'activo';
    document.getElementById('u-clave').value = '';

    const modal = new bootstrap.Modal(document.getElementById('userModal'));
    modal.show();
};

function renderSelectRoles() {
    const select = document.getElementById('u-rol');
    if (select) {
        select.innerHTML = rolesConfig.map(r => `<option value="${r.id}">${r.nombre}</option>`).join('');
    }
}

window.guardarUsuario = async (e) => {
    if (e) e.preventDefault();
    const id = document.getElementById('u-id').value;
    const nombre = document.getElementById('u-nombre').value;
    const rol = document.getElementById('u-rol').value;
    const estado = document.getElementById('u-estado').value;
    const claveNueva = document.getElementById('u-clave').value;

    const autoAssignCareerId = getCareerIdFromRole(rol);
    const userCareers = [];
    if (autoAssignCareerId) {
        if (carreras.some(c => c.id === autoAssignCareerId)) {
            userCareers.push({ id: autoAssignCareerId, estado: "Incompleta" });
        } else {
            console.warn(`Career ID ${autoAssignCareerId} for role ${rol} not found.`);
        }
    }

    const idx = usuarios.findIndex(u => u.id === id);
    if (idx !== -1) {
        usuarios[idx].nombre = nombre;
        usuarios[idx].rol = rol;
        usuarios[idx].estado = estado;
        if (claveNueva) usuarios[idx].clave = claveNueva;
        if (autoAssignCareerId && !usuarios[idx].carrerasAsignadas.some(ca => ca.id === autoAssignCareerId)) {
            usuarios[idx].carrerasAsignadas.push({ id: autoAssignCareerId, estado: "Incompleta" });
        }
        usuarios[idx] = crearEstructuraUsuario(usuarios[idx]);
    } else {
        if (usuarios.find(u => u.id === id)) return alert("ID ya registrado");
        const nuevoUsuario = crearEstructuraUsuario({
            id,
            nombre,
            clave: claveNueva || "12345",
            rol,
            estado,
            asignados: [],
            carrerasAsignadas: userCareers,
            progreso: {},
            certificadosCurso: [],
            certificadosCarrera: []
        });
        usuarios.push(nuevoUsuario);
    }

    await guardarUsuarios();
    location.reload();
};

window.actualizarMinAprobacionGlobal = async (val) => {
    if (!db.configuracion) db.configuracion = {};
    db.configuracion.minAprobacion = parseInt(val) || 70;
    await guardarTodo();
};

window.eliminarUsuario = async (id) => {
    if (id === '25482938') return alert("No se puede eliminar al administrador principal.");
    if (confirm('¿Eliminar acceso para este usuario?')) {
        usuarios = usuarios.filter(u => u.id !== id);
        await guardarUsuarios();
        location.reload();
    }
};

window.prepararFormularioRol = () => {
    const form = document.getElementById('form-rol-integral');
    if (form) form.reset();
    document.getElementById('r-id').disabled = false;
    document.getElementById('roleModalTitle').innerText = "Nuevo Rol";
    renderContenidoRol([], []);
};

window.abrirEditorRol = (id) => {
    const rol = rolesConfig.find(r => r.id === id);
    if (!rol) return;

    document.getElementById('roleModalTitle').innerText = `Editando Rol: ${rol.nombre}`;
    document.getElementById('r-id').value = rol.id;
    document.getElementById('r-id').disabled = true;
    document.getElementById('r-nombre').value = rol.nombre;
    renderContenidoRol(rol.cursos || [], rol.carreras || []);

    const modal = new bootstrap.Modal(document.getElementById('roleModal'));
    modal.show();
};

function renderContenidoRol(cursosActuales = [], carrerasActuales = []) {
    const cursosCont = document.getElementById('r-lista-cursos');
    if (!cursosCont) return;

    cursosCont.innerHTML = cursos.map(c => `
        <div class="form-check small">
            <input class="form-check-input check-r-curso" type="checkbox" value="${c.id}" ${cursosActuales.includes(c.id) ? 'checked' : ''}>
            <label class="form-check-label">${c.titulo}</label>
        </div>`).join('') || '<p class="text-muted small">No hay cursos disponibles.</p>';
}

window.guardarRol = async (e) => {
    e.preventDefault();
    const id = document.getElementById('r-id').value;
    const nombre = document.getElementById('r-nombre').value;
    const cursosSel = Array.from(document.querySelectorAll('.check-r-curso:checked')).map(cb => cb.value);
    const carrerasSel = Array.from(document.querySelectorAll('.check-r-carrera:checked')).map(cb => cb.value);

    const idx = rolesConfig.findIndex(r => r.id === id);
    if (idx !== -1) {
        rolesConfig[idx].nombre = nombre;
        rolesConfig[idx].cursos = cursosSel;
        rolesConfig[idx].carreras = carrerasSel;
    } else {
        if (rolesConfig.find(r => r.id === id)) return alert("ID de rol ya registrado.");
        rolesConfig.push({ id, nombre, cursos: cursosSel, carreras: carrerasSel });
    }
    await guardarRoles();
    location.reload();
};

window.eliminarRol = async (id) => {
    const usuariosConRol = usuarios.filter(u => u.rol === id);
    if (usuariosConRol.length > 0) {
        return alert(`No se puede eliminar el rol "${id}" porque tiene ${usuariosConRol.length} usuario(s) asignados.`);
    }
    if (confirm('¿Estás seguro de eliminar este rol? Los usuarios con este rol podrían perder acceso a cursos.')) {
        rolesConfig = rolesConfig.filter(r => r.id !== id);
        await guardarRoles();
        location.reload();
    }
};

window.crearCarrera = async (e) => {
    e.preventDefault();
    const idEdit = document.getElementById('edit-career-id').value;
    const nombre = document.getElementById('career-name').value;
    const selectedCursos = Array.from(document.querySelectorAll('.curso-check:checked')).map(cb => cb.value);

    if (idEdit) {
        const idx = carreras.findIndex(c => c.id === idEdit);
        carreras[idx].nombre = nombre;
        carreras[idx].cursos = selectedCursos;
    } else {
        carreras.push({
            id: "CAR-" + Date.now(),
            nombre,
            cursos: selectedCursos
        });
    }
    await guardarCarreras();
    location.reload();
};

window.filtrarCursosCarrera = (busqueda) => {
    const termo = normalizar(busqueda);
    const items = document.querySelectorAll('.curso-item-carrera');
    items.forEach(item => {
        const texto = normalizar(item.innerText);
        item.style.display = texto.includes(termo) ? "block" : "none";
    });
};

function renderListaCursosCarrera() {
    const container = document.getElementById('career-courses-list');
    if (!container) return;
    container.innerHTML = cursos.map(c => `
        <div class="form-check curso-item-carrera">
            <input class="form-check-input curso-check" type="checkbox" value="${c.id}" id="chk-${c.id}">
            <label class="form-check-label" for="chk-${c.id}">${c.titulo}</label>
        </div>
    `).join('');
}

window.abrirEditorCarrera = (id) => {
    const car = carreras.find(c => c.id === id);
    document.getElementById('edit-career-id').value = car.id;
    document.getElementById('career-name').value = car.nombre;
    renderListaCursosCarrera();
    car.cursos.forEach(cId => {
        const chk = document.getElementById(`chk-${cId}`);
        if (chk) chk.checked = true;
    });
};

window.eliminarCarrera = async (id) => {
    carreras = carreras.filter(c => c.id !== id);
    await guardarCarreras();
    location.reload();
};

document.addEventListener('DOMContentLoaded', async () => {
    await cargarDatosDelServidor();

    verificarProteccion();

    const urlParams = new URLSearchParams(window.location.search);
    const cursoId = urlParams.get('id');
    if (document.getElementById('contenido-curso') && cursoId) {
        mostrarDetalleCurso(cursoId);
    }

    const tablaBody = document.getElementById('tabla-cursos-body');
    const tablaReportes = document.getElementById('tabla-reportes-body');
    renderListaCursosCarrera();

    if (tablaBody) {
        tablaBody.innerHTML = '';
        cursos.forEach(c => {
            const esLibre = c.tipo === 'publico' || c.tipo === 'libre';
            const badgeTipo = esLibre 
                ? '<span class="badge bg-success ms-2"><i class="bi bi-unlock-fill me-1"></i>Acceso Libre</span>' 
                : '<span class="badge bg-warning text-dark ms-2"><i class="bi bi-mortarboard-fill me-1"></i>Especializado</span>';
            tablaBody.innerHTML += `
                <tr>
                    <td><small class="text-muted">${c.id}</small></td>
                    <td><strong>${c.titulo}</strong> ${badgeTipo}</td>
                    <td class="text-center">
                        <button class="btn btn-sm btn-outline-primary me-2" onclick="abrirEditor('${c.id}')"><i class="bi bi-pencil me-1"></i>Editar</button>
                        <button class="btn btn-sm btn-outline-danger" onclick="eliminarCurso('${c.id}')"><i class="bi bi-trash me-1"></i>Borrar</button>
                    </td>
                </tr>`;
        });

        const rolesTable = document.getElementById('tabla-roles-body');
        if (rolesTable) {
            rolesTable.innerHTML = '';
            rolesConfig.filter(r => r.id !== 'admin').forEach(r => {
                const totalC = (r.cursos || []).length;
                const totalCar = (r.carreras || []).length;
                rolesTable.innerHTML += `
                    <tr>
                        <td><code class="fw-bold">${r.id}</code></td>
                        <td>${r.nombre}</td>
                        <td>
                            <span class="badge bg-primary">${totalC} Cursos</span>
                            <span class="badge bg-success">${totalCar} Carreras</span>
                        </td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary me-1" onclick="abrirEditorRol('${r.id}')">Configurar Carga</button>
                            <button class="btn btn-sm btn-outline-danger" onclick="eliminarRol('${r.id}')"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>`;
            });
        }

        const reqRegTable = document.getElementById('tabla-solicitudes-registro');
        const reqCurTable = document.getElementById('tabla-solicitudes-cursos');

        if (reqRegTable) {
            reqRegTable.innerHTML = solicitudesRegistro.map(s => `
                <tr>
                    <td>${s.id}</td>
                    <td>${s.nombre}</td>
                    <td><span class="badge bg-secondary">${s.perfilDeseado}</span></td>
                    <td>
                        <button class="btn btn-sm btn-success" onclick="gestionarSolicitudRegistro('${s.id}', true)">Aprobar</button>
                        <button class="btn btn-sm btn-danger" onclick="gestionarSolicitudRegistro('${s.id}', false)">Rechazar</button>
                    </td>
                </tr>
            `).join('') || '<tr><td colspan="4" class="text-center text-muted py-3">No hay solicitudes de registro pendientes.</td></tr>';
        }

        if (reqCurTable) {
            reqCurTable.innerHTML = solicitudesCursos.map(s => `
                <tr>
                    <td>${s.userName}</td>
                    <td>${s.cursoId}</td>
                    <td>${s.fecha}</td>
                    <td>
                        <button class="btn btn-sm btn-success" onclick="gestionarSolicitudCurso('${s.userId}', '${s.cursoId}', true)">Aprobar</button>
                        <button class="btn btn-sm btn-danger" onclick="gestionarSolicitudCurso('${s.userId}', '${s.cursoId}', false)">Rechazar</button>
                    </td>
                </tr>
            `).join('') || '<tr><td colspan="4" class="text-center text-muted py-3">No hay solicitudes de acceso a cursos.</td></tr>';
        }

        const userTable = document.getElementById('tabla-usuarios-body');
        const careerTable = document.getElementById('tabla-carreras-body');

        if (userTable) {
            userTable.innerHTML = '';
            usuarios.forEach(u => {
                const assignedCareersNames = (u.carrerasAsignadas || []).map(ca => {
                    const car = carreras.find(c => c.id === ca.id);
                    const carNombre = car ? car.nombre : (ca.id || 'Desconocida');
                    const badgeClass = ca.estado === 'Completada' ? 'bg-success' : 'bg-secondary';
                    return `<span class="me-1">${carNombre} <span class="badge ${badgeClass}">${ca.estado || 'Incompleta'}</span></span>`;
                }).join('<br>');
                userTable.innerHTML += `
                    <tr>
                        <td><code class="fw-bold">${u.id}</code></td>
                        <td>${u.nombre}</td>
                        <td><span class="badge border text-dark bg-light mb-1">${(u.rol || '').replace('_', ' ')}</span><br><small class="text-muted">${assignedCareersNames || 'Sin carrera'}</small></td>
                        <td><span class="badge ${u.estado === 'suspendido' ? 'bg-danger' : 'bg-success'}">${u.estado || 'activo'}</span></td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary me-1" onclick="abrirEditorUsuario('${u.id}')">Editar Perfil</button>
                            <button class="btn btn-sm btn-outline-warning me-1" onclick="abrirRestablecerAvance('${u.id}')"><i class="bi bi-arrow-counterclockwise"></i> Restablecer</button>
                            <button class="btn btn-sm btn-outline-success me-1" onclick="abrirMarcarCompletado('${u.id}')"><i class="bi bi-check-circle"></i> Completar</button>
                            <button class="btn btn-sm btn-outline-danger" onclick="eliminarUsuario('${u.id}')"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>`;
            });
        }

        if (careerTable) {
            carreras.forEach(car => {
                careerTable.innerHTML += `
                    <tr>
                        <td>${car.nombre}</td>
                        <td>
                            <small>${car.cursos.length} cursos</small>
                            <button class="btn btn-sm btn-outline-secondary ms-2" onclick="duplicarCarrera('${car.id}')">Duplicar</button>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary" onclick="abrirEditorCarrera('${car.id}')">Editar</button>
                            <button class="btn btn-sm btn-outline-danger" onclick="eliminarCarrera('${car.id}')">X</button>
                        </td>
                    </tr>`;
            });
        }

        const tabReportes = document.querySelector('button[data-bs-target="#tab-reportes"]');
        if (tabReportes) {
            tabReportes.addEventListener('shown.bs.tab', () => {
                renderRobustReports();
            });
        }
    }
});

function renderRobustReports() {
    const topLearnersTable = document.getElementById('tabla-top-learners');
    const performanceByRole = document.getElementById('chart-cumplimiento');
    const chartCumplimiento = document.getElementById('chart-cumplimiento');

    if (!topLearnersTable) return;

    const activeUsers = usuarios.filter(u => u.rol !== 'admin' && u.estado === 'activo');

    const userPerformanceData = activeUsers.map(user => {
        const userProgress = user.progreso || {};
        const cursosIds = Object.keys(userProgress);

        let totalModulosCurso = 0;
        let modulosAprobados = 0;
        let totalLecciones = 0;
        let leccionesCompletadas = 0;
        let promedioEvaluaciones = 0;
        let evaluacionesTotales = 0;
        let sumaCalificaciones = 0;
        let totalIntentos = 0;

        cursosIds.forEach(cursoId => {
            const progresoCurso = userProgress[cursoId];
            const cursoInfo = cursos.find(c => c.id === cursoId);

            if (cursoInfo && cursoInfo.modulos) {
                const totalModulosCursoActual = cursoInfo.modulos.length;
                totalModulosCurso += totalModulosCursoActual;

                const modulosAprobadosActual = progresoCurso?.modulosAprobados?.length || 0;
                modulosAprobados += modulosAprobadosActual;

                const totalLeccionesCurso = cursoInfo.modulos.reduce((sum, modulo) =>
                    sum + (modulo.lecciones?.length || 0), 0);
                totalLecciones += totalLeccionesCurso;

                const leccionesCompletadasActual = progresoCurso?.leccionesCompletadas?.length || 0;
                leccionesCompletadas += leccionesCompletadasActual;

                const evaluaciones = progresoCurso?.evaluaciones || {};
                const evaluacionesCurso = Object.values(evaluaciones);
                evaluacionesTotales += evaluacionesCurso.length;
                sumaCalificaciones += evaluacionesCurso.reduce((sum, ev) => sum + (ev.calificacion || 0), 0);

                const intentos = progresoCurso?.intentos || {};
                Object.values(intentos).forEach(val => {
                    totalIntentos += parseInt(val) || 0;
                });
            }
        });

        const porcentajeModulos = totalModulosCurso > 0 ? (modulosAprobados / totalModulosCurso) * 100 : 0;
        const porcentajeLecciones = totalLecciones > 0 ? (leccionesCompletadas / totalLecciones) * 100 : 0;
        promedioEvaluaciones = evaluacionesTotales > 0 ? sumaCalificaciones / evaluacionesTotales : 0;

        const tasaCompletitud = (porcentajeModulos + porcentajeLecciones) / 2;

        return {
            nombre: user.nombre,
            rol: user.rol,
            cursosTomados: cursosIds.length,
            modulosAprobados: modulosAprobados,
            totalModulos: totalModulosCurso,
            porcentajeModulos: porcentajeModulos.toFixed(1),
            leccionesCompletadas: leccionesCompletadas,
            totalLecciones: totalLecciones,
            porcentajeLecciones: porcentajeLecciones.toFixed(1),
            promedioEvaluaciones: promedioEvaluaciones.toFixed(1),
            tasaCompletitud: tasaCompletitud.toFixed(1),
            totalIntentos: totalIntentos,
            estado: user.estado
        };
    }).sort((a, b) => b.tasaCompletitud - a.tasaCompletitud);

    topLearnersTable.innerHTML = `
        <thead>
            <tr class="table-dark">
                <th>Usuario</th>
                <th>Rol</th>
                <th>Cursos</th>
                <th>Módulos <br><small>(aprob/total)</small></th>
                <th>Lecciones <br><small>(complet/total)</small></th>
                <th>Promedio <br>Evaluaciones</th>
                <th>Intentos <br>Totales</th>
                <th>Tasa <br>Completitud</th>
            </tr>
        </thead>
        <tbody>
            ${userPerformanceData.map(u => `
                <tr>
                    <td>
                        <strong>${u.nombre}</strong>
                        ${u.estado === 'inactivo' ? '<span class="badge bg-secondary ms-2">Inactivo</span>' : ''}
                    </td>
                    <td><span class="badge bg-info text-dark">${u.rol.replace('_', ' ')}</span></td>
                    <td class="text-center">${u.cursosTomados}</td>
                    <td>
                        <div class="d-flex justify-content-between small">
                            <span>${u.modulosAprobados}/${u.totalModulos}</span>
                            <span class="text-${u.porcentajeModulos >= 70 ? 'success' : 'warning'}">${u.porcentajeModulos}%</span>
                        </div>
                        <div class="progress" style="height: 5px;">
                            <div class="progress-bar bg-${u.porcentajeModulos >= 70 ? 'success' : u.porcentajeModulos >= 40 ? 'warning' : 'danger'}" 
                                 style="width: ${u.porcentajeModulos}%"></div>
                        </div>
                    </td>
                    <td>
                        <div class="d-flex justify-content-between small">
                            <span>${u.leccionesCompletadas}/${u.totalLecciones}</span>
                            <span class="text-${u.porcentajeLecciones >= 70 ? 'success' : 'warning'}">${u.porcentajeLecciones}%</span>
                        </div>
                        <div class="progress" style="height: 5px;">
                            <div class="progress-bar bg-${u.porcentajeLecciones >= 70 ? 'success' : u.porcentajeLecciones >= 40 ? 'warning' : 'danger'}" 
                                 style="width: ${u.porcentajeLecciones}%"></div>
                        </div>
                    </td>
                    <td class="text-center">
                        <span class="fw-bold ${u.promedioEvaluaciones >= 70 ? 'text-success' : u.promedioEvaluaciones >= 60 ? 'text-warning' : 'text-danger'}">
                            ${u.promedioEvaluaciones}
                        </span>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-secondary">
                            ${u.totalIntentos}
                        </span>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-${u.tasaCompletitud >= 70 ? 'success' : u.tasaCompletitud >= 40 ? 'warning' : 'danger'} fs-6">
                            ${u.tasaCompletitud}%
                        </span>
                    </td>
                </tr>
            `).join('')}
        </tbody>
    `;

    const rolePerformance = rolesConfig
        .filter(role => role.id !== 'admin')
        .map(role => {
            const usersInRole = userPerformanceData.filter(u => u.rol === role.id);

            if (usersInRole.length === 0) {
                return {
                    rol: role.id,
                    nombre: role.nombre,
                    usuarios: 0,
                    promedioModulos: 0,
                    promedioLecciones: 0,
                    promedioEvaluaciones: 0,
                    promedioCompletitud: 0,
                    cursosAsignados: role.cursos?.length || 0,
                    carrerasAsignadas: role.carreras?.length || 0,
                    detalleUsuarios: []
                };
            }

            const totalUsuarios = usersInRole.length;
            const sumModulos = usersInRole.reduce((sum, u) => sum + parseFloat(u.porcentajeModulos), 0);
            const sumLecciones = usersInRole.reduce((sum, u) => sum + parseFloat(u.porcentajeLecciones), 0);
            const sumEvaluaciones = usersInRole.reduce((sum, u) => sum + parseFloat(u.promedioEvaluaciones), 0);
            const sumCompletitud = usersInRole.reduce((sum, u) => sum + parseFloat(u.tasaCompletitud), 0);

            const rendimientoBajo = usersInRole.filter(u => u.tasaCompletitud < 40).length;
            const rendimientoMedio = usersInRole.filter(u => u.tasaCompletitud >= 40 && u.tasaCompletitud < 70).length;
            const rendimientoAlto = usersInRole.filter(u => u.tasaCompletitud >= 70).length;

            return {
                rol: role.id,
                nombre: role.nombre,
                usuarios: totalUsuarios,
                promedioModulos: (sumModulos / totalUsuarios).toFixed(1),
                promedioLecciones: (sumLecciones / totalUsuarios).toFixed(1),
                promedioEvaluaciones: (sumEvaluaciones / totalUsuarios).toFixed(1),
                promedioCompletitud: (sumCompletitud / totalUsuarios).toFixed(1),
                cursosAsignados: role.cursos?.length || 0,
                carrerasAsignadas: role.carreras?.length || 0,
                rendimientoBajo: rendimientoBajo,
                rendimientoMedio: rendimientoMedio,
                rendimientoAlto: rendimientoAlto,
                detalleUsuarios: usersInRole.map(u => ({
                    nombre: u.nombre,
                    completitud: u.tasaCompletitud
                }))
            };
        })
        .sort((a, b) => b.promedioCompletitud - a.promedioCompletitud);

    if (performanceByRole) {
        performanceByRole.innerHTML = `
            <thead>
                <tr class="table-dark">
                    <th>Rol</th>
                    <th>Usuarios</th>
                    <th>Módulos <br><small>(% promedio)</small></th>
                    <th>Lecciones <br><small>(% promedio)</small></th>
                    <th>Evaluaciones <br><small>(promedio)</small></th>
                    <th>Tasa <br>Completitud</th>
                    <th>Distribución</th>
                </tr>
            </thead>
            <tbody>
                ${rolePerformance.map(r => `
                    <tr>
                        <td>
                            <strong>${r.nombre}</strong>
                            <div class="small text-muted">${r.cursosAsignados} cursos | ${r.carrerasAsignadas} carreras</div>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-secondary">${r.usuarios}</span>
                        </td>
                        <td>
                            <div class="progress" style="height: 5px;">
                                <div class="progress-bar bg-info" style="width: ${r.promedioModulos}%"></div>
                            </div>
                            <div class="small text-center">${r.promedioModulos}%</div>
                        </td>
                        <td>
                            <div class="progress" style="height: 5px;">
                                <div class="progress-bar bg-info" style="width: ${r.promedioLecciones}%"></div>
                            </div>
                            <div class="small text-center">${r.promedioLecciones}%</div>
                        </td>
                        <td class="text-center">
                            <span class="fw-bold ${r.promedioEvaluaciones >= 70 ? 'text-success' : 'text-warning'}">
                                ${r.promedioEvaluaciones}
                            </span>
                        </td>
                        <td>
                            <div class="progress" style="height: 8px;">
                                <div class="progress-bar bg-danger" style="width: ${(r.rendimientoBajo / r.usuarios) * 100}%"></div>
                                <div class="progress-bar bg-warning" style="width: ${(r.rendimientoMedio / r.usuarios) * 100}%"></div>
                                <div class="progress-bar bg-success" style="width: ${(r.rendimientoAlto / r.usuarios) * 100}%"></div>
                            </div>
                            <div class="small text-center mt-1">
                                <span class="text-success">▲${r.rendimientoAlto}</span> / 
                                <span class="text-warning">●${r.rendimientoMedio}</span> / 
                                <span class="text-danger">▼${r.rendimientoBajo}</span>
                            </div>
                        </td>
                    </tr>
                `).join('')}
            </tbody>
        `;
    }

    if (chartCumplimiento) {
        chartCumplimiento.innerHTML = rolePerformance.map(role => `
            <div class="mb-4">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        <strong>${role.nombre}</strong>
                        <span class="badge bg-secondary ms-2">${role.usuarios} usuarios</span>
                    </div>
                    <span class="h5 mb-0 fw-bold text-${role.promedioCompletitud >= 70 ? 'success' : role.promedioCompletitud >= 40 ? 'warning' : 'danger'}">
                        ${role.promedioCompletitud}%
                    </span>
                </div>
                <div class="progress mb-2" style="height: 20px;">
                    <div class="progress-bar bg-success" style="width: ${role.promedioCompletitud}%">
                        ${role.promedioCompletitud}%
                    </div>
                </div>
                <div class="row small text-muted">
                    <div class="col-4">
                        <i class="fas fa-check-circle text-success"></i> Módulos: ${role.promedioModulos}%
                    </div>
                    <div class="col-4">
                        <i class="fas fa-book-open text-info"></i> Lecciones: ${role.promedioLecciones}%
                    </div>
                    <div class="col-4">
                        <i class="fas fa-star text-warning"></i> Evaluaciones: ${role.promedioEvaluaciones}
                    </div>
                </div>
                ${role.detalleUsuarios.length > 0 ? `
                    <div class="mt-2">
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#roleDetail-${role.rol.replace(/[^a-zA-Z0-9]/g, '')}">
                            <i class="fas fa-users"></i> Ver usuarios (${role.usuarios})
                        </button>
                        <div class="collapse mt-2" id="roleDetail-${role.rol.replace(/[^a-zA-Z0-9]/g, '')}">
                            <div class="card card-body bg-light">
                                ${role.detalleUsuarios.map(u => `
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span>${u.nombre}</span>
                                        <div class="progress flex-grow-1 mx-2" style="height: 5px; max-width: 200px;">
                                            <div class="progress-bar bg-${u.completitud >= 70 ? 'success' : u.completitud >= 40 ? 'warning' : 'danger'}" 
                                                 style="width: ${u.completitud}%"></div>
                                        </div>
                                        <span class="small fw-bold">${u.completitud}%</span>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    </div>
                ` : ''}
            </div>
            ${role !== rolePerformance[rolePerformance.length - 1] ? '<hr>' : ''}
        `).join('');
    }
}

window.guardarCurso = async (e) => {
    if (e) e.preventDefault();

    const btnSubmit = document.querySelector('#form-curso button[type="submit"]');
    let originalText = "Guardar Todo";
    if (btnSubmit) {
        if (btnSubmit.disabled) return;
        originalText = btnSubmit.innerHTML;
        btnSubmit.disabled = true;
        btnSubmit.innerHTML = `<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Guardando...`;
    }

    const resetSubmitBtn = () => {
        if (btnSubmit) {
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = originalText;
        }
    };

    const idEdit = document.getElementById('edit-id').value;

    if (tempModulos.length === 0) {
        alert("Error: El curso debe tener al menos un módulo.");
        resetSubmitBtn();
        return;
    }
    const todasTienenLecciones = tempModulos.every(m => m.lecciones && m.lecciones.length > 0);
    if (!todasTienenLecciones) {
        alert("Error: Cada módulo debe contener al menos una lección.");
        resetSubmitBtn();
        return;
    }

    const tipo = document.getElementById('curso-tipo') ? document.getElementById('curso-tipo').value : 'especializado';

    const nuevoCurso = {
        id: idEdit ? idEdit : "CUR-" + Date.now(),
        titulo: document.getElementById('titulo').value,
        tipo: tipo || 'especializado',
        imagen: tempImagenPortada,
        descripcion: document.getElementById('descripcion').value,
        prelacion: document.getElementById('curso-prelacion').value,
        modulos: tempModulos
    };

    try {
        if (idEdit) {
            const idx = cursos.findIndex(c => c.id == idEdit);
            cursos[idx] = nuevoCurso;
        } else {
            cursos.push(nuevoCurso);
        }
        await guardarTodo();
        alert("Curso guardado con éxito.");
        location.reload();
    } catch (err) {
        console.error("Error de almacenamiento:", err);
        alert("No se pudo guardar el curso. Revisa los datos ingresados o la conexión al servidor.");
        resetSubmitBtn();
    }
};

// ============================================================
// FUNCIONES DE PROGRESO - CORREGIDAS CON GUARDADO EN BD
// ============================================================

window.marcarLeccionCompletada = async (mIdx, lIdx) => {
    const cursoID = cursoActualData.id;
    const lecID = `${mIdx}-${lIdx}`;

    // Inicializar progreso del curso
    if (!sesion.progreso[cursoID]) {
        sesion.progreso[cursoID] = {
            leccionesCompletadas: [],
            modulosAprobados: [],
            medallas: [],
            evaluaciones: {},
            intentos: {}
        };
    }
    if (Array.isArray(sesion.progreso[cursoID])) {
        sesion.progreso[cursoID] = {
            leccionesCompletadas: sesion.progreso[cursoID],
            modulosAprobados: [],
            medallas: [],
            evaluaciones: {},
            intentos: {}
        };
    }

    // Agregar lección completada si no existe
    if (!sesion.progreso[cursoID].leccionesCompletadas.includes(lecID)) {
        sesion.progreso[cursoID].leccionesCompletadas.push(lecID);
    }

    const currentModule = cursoActualData.modulos[mIdx];
    const tieneLecciones = currentModule && currentModule.lecciones && currentModule.lecciones.length > 0;
    const allLessonsInModuleCompleted = tieneLecciones && currentModule.lecciones.every((_, index) =>
        sesion.progreso[cursoID].leccionesCompletadas.includes(`${mIdx}-${index}`)
    );

    const tieneEvaluacion = currentModule.evaluacion && currentModule.evaluacion.preguntas && currentModule.evaluacion.preguntas.length > 0;

    // Si el módulo no tiene evaluación y se completaron todas sus lecciones, aprobar automáticamente el módulo
    if (allLessonsInModuleCompleted && !tieneEvaluacion) {
        if (!sesion.progreso[cursoID].modulosAprobados) sesion.progreso[cursoID].modulosAprobados = [];
        if (!sesion.progreso[cursoID].modulosAprobados.includes(String(mIdx))) {
            sesion.progreso[cursoID].modulosAprobados.push(String(mIdx));
        }

        if (!sesion.progreso[cursoID].medallas) sesion.progreso[cursoID].medallas = [];
        if (!sesion.progreso[cursoID].medallas.includes(String(mIdx))) {
            sesion.progreso[cursoID].medallas.push(String(mIdx));
        }

        // Comprobar si completó todos los módulos del curso
        const totalModulosCurso = (cursoActualData.modulos || []).length;
        if (sesion.progreso[cursoID].modulosAprobados.length >= totalModulosCurso) {
            sesion.certificadosCurso = sesion.certificadosCurso || [];
            if (!sesion.certificadosCurso.includes(cursoID)) {
                sesion.certificadosCurso.push(cursoID);
            }
        }
    }

    // Actualizar carreras
    actualizarEstadoCarrerasUsuario(sesion);

    // GUARDAR EN EL USUARIO Y EN EL SERVIDOR
    await guardarProgresoUsuario();

    // Renderizar de nuevo
    document.getElementById('contenido-curso').innerHTML = renderizarCursoTeachlr(cursoActualData);

    // Si todas las lecciones completadas y hay evaluación, mostrar evaluación
    if (allLessonsInModuleCompleted &&
        !sesion.progreso[cursoID].modulosAprobados.includes(String(mIdx)) &&
        tieneEvaluacion) {
        alert(`¡Módulo "${currentModule.titulo}" finalizado! Procede a la evaluación.`);
        mostrarEvaluacionModulo(cursoID, mIdx);
        return;
    }

    seleccionarLeccion(mIdx, lIdx);
};

window.navegarLeccion = (dir, m, l) => {
    let newM = m, newL = l;
    if (dir === 'next') {
        newL++;
        if (newL >= cursoActualData.modulos[m].lecciones.length) {
            newM++;
            newL = 0;
        }
    } else {
        newL--;
        if (newL < 0) {
            newM--;
            if (newM >= 0) newL = cursoActualData.modulos[newM].lecciones.length - 1;
        }
    }
    if (cursoActualData.modulos[newM] && cursoActualData.modulos[newM].lecciones[newL]) {
        seleccionarLeccion(newM, newL);
    }
};

window.seleccionarLeccion = (mIdx, lIdx) => {
    if (!verificarAccesoLeccion(mIdx, lIdx)) return;
    const leccion = cursoActualData.modulos[mIdx].lecciones[lIdx];
    const visor = document.getElementById('visor-contenido');

    document.querySelectorAll('.btn-leccion').forEach(btn => btn.classList.remove('active'));
    const btnActivo = document.getElementById(`btn-l-${mIdx}-${lIdx}`);
    if (btnActivo) btnActivo.classList.add('active');

    const lecID = `${mIdx}-${lIdx}`;
    const progreso = sesion.progreso[cursoActualData.id];
    const estaCompletada = progreso && (Array.isArray(progreso) ?
        progreso.includes(lecID) :
        (progreso.leccionesCompletadas && progreso.leccionesCompletadas.includes(lecID)));

    let haySiguiente = false;
    let proximoM = mIdx, proximoL = lIdx + 1;
    if (proximoL >= cursoActualData.modulos[mIdx].lecciones.length) {
        proximoM = mIdx + 1;
        proximoL = 0;
    }
    haySiguiente = cursoActualData.modulos[proximoM] && cursoActualData.modulos[proximoM].lecciones[proximoL];
    const sigAcceso = estaCompletada;

    visor.innerHTML = `
        <div class="row g-3">
            <div class="col-xl-8">
                <h3 class="fw-bold mb-3">${leccion.titulo}</h3>
                <div class="video-container shadow-sm rounded overflow-hidden">
                    <iframe src="https://www.youtube.com/embed/${leccion.videoID}" frameborder="0" allowfullscreen></iframe>
                </div>
                <div class="d-flex justify-content-between mt-3">
                    <button class="btn btn-outline-secondary btn-nav-lesson" ${(mIdx === 0 && lIdx === 0) ? 'disabled' : ''} onclick="navegarLeccion('prev', ${mIdx}, ${lIdx})">
                        <i class="bi bi-chevron-left"></i> Anterior
                    </button>
                    <button class="btn btn-primary btn-nav-lesson" ${(!haySiguiente || !sigAcceso) ? 'disabled' : ''} onclick="navegarLeccion('next', ${mIdx}, ${lIdx})">
                        Siguiente <i class="bi bi-chevron-right"></i>
                    </button>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card border-0 shadow-sm p-4 h-100">
                    <h5 class="fw-bold"><i class="bi bi-info-circle me-2"></i>Material de apoyo</h5>
                    <hr>
                    <div class="text-secondary mb-4 leccion-texto" style="white-space: pre-wrap;">${leccion.contenido || 'No hay descripción disponible para esta lección.'}</div>
                    <div class="mt-auto">
                        ${!estaCompletada ?
            `<button class="btn btn-success w-100 mb-2 py-2" onclick="marcarLeccionCompletada(${mIdx}, ${lIdx})"><i class="bi bi-check-lg"></i> Finalizar Lección</button>` :
            `<button class="btn btn-outline-success w-100 mb-2" disabled><i class="bi bi-check-all"></i> Lección Completada</button>`}
                        
                        ${leccion.adjunto ? `
                            <a href="${leccion.adjunto}" download="${leccion.nombreAdjunto || 'recurso'}" class="btn btn-outline-primary w-100 mt-2">
                                <i class="bi bi-file-earmark-arrow-down-fill me-2"></i>Descargar: ${leccion.nombreAdjunto || 'Archivo'}
                            </a>` : ''}
                    </div>
                </div>
            </div>
        </div>
    `;
};

window.mostrarEvaluacionModulo = (cursoID, mIdx) => {
    const curso = cursos.find(c => c.id === cursoID);
    const modulo = curso.modulos[mIdx];
    const visor = document.getElementById('visor-contenido');

    if (!sesion.progreso[curso.id]) sesion.progreso[curso.id] = { leccionesCompletadas: [], modulosAprobados: [] };
    const progreso = sesion.progreso[curso.id];
    const modulosAprobados = Array.isArray(progreso) ? [] : (progreso.modulosAprobados || []);

    const moduloYaAprobado = modulosAprobados.includes(String(mIdx));

    if (moduloYaAprobado) {
        visor.innerHTML = `
            <div class="card p-5 shadow-sm text-center border-0">
                <i class="bi bi-patch-check-fill text-success display-1 mb-3"></i>
                <h2 class="fw-bold">¡Módulo "${modulo.titulo}" Aprobado!</h2>
                <p class="lead">Has completado con éxito todas las lecciones y la evaluación de este módulo.</p>
                <button class="btn btn-outline-primary mt-3" onclick="location.reload()">Volver al Curso</button>
            </div>`;
        return;
    }

    visor.innerHTML = `
        <div class="card p-5 shadow-sm">
            <h2 class="fw-bold text-center mb-4">Evaluación de Módulo: ${modulo.titulo}</h2>
            <p class="text-center text-muted">Debes aprobar para continuar con el siguiente contenido.</p>
            <div class="quiz-area mb-4">
                ${modulo.evaluacion.preguntas.map((p, i) => `
                    <div class="mb-4 p-3 border rounded">
                        <h6 class="fw-bold">${i + 1}. ${p.enunciado}</h6>
                        ${p.opciones.map((opt, oIdx) => `
                            <div class="form-check py-1">
                                <input class="form-check-input" type="radio" name="q${i}" value="${oIdx}" id="q${i}o${oIdx}">
                                <label class="form-check-label" for="q${i}o${oIdx}">${opt}</label>
                            </div>
                        `).join('')}
                    </div>
                `).join('')}
            </div>
            <button id="btn-enviar-evaluacion" class="btn btn-primary btn-lg w-100" onclick="validarEvaluacionModulo(${mIdx})">Enviar Respuestas</button>
            <div id="feedback" class="mt-4"></div>
        </div>`;
};

window.validarEvaluacionModulo = async (mIdx) => {
    const modulo = cursoActualData.modulos[mIdx];
    const preguntas = (modulo.evaluacion && modulo.evaluacion.preguntas) || [];
    let aciertos = 0;
    const feedback = document.getElementById('feedback');

    // Validar que se respondieron todas las preguntas
    let todasRespondidas = true;
    for (let i = 0; i < preguntas.length; i++) {
        const sel = document.querySelector(`input[name="q${i}"]:checked`);
        if (!sel) {
            todasRespondidas = false;
            break;
        }
    }

    if (!todasRespondidas) {
        alert("Por favor, responde todas las preguntas antes de enviar.");
        return;
    }

    preguntas.forEach((p, i) => {
        const sel = document.querySelector(`input[name="q${i}"]:checked`);
        if (sel && sel.value == p.correcta) aciertos++;
    });

    const porcentaje = Math.round((aciertos / preguntas.length) * 100);
    const min = (db.configuracion && db.configuracion.minAprobacion) || 70;

    // Inicializar progreso del curso
    if (!sesion.progreso[cursoActualData.id]) {
        sesion.progreso[cursoActualData.id] = {
            leccionesCompletadas: [],
            modulosAprobados: []
        };
    }
    if (Array.isArray(sesion.progreso[cursoActualData.id])) {
        sesion.progreso[cursoActualData.id] = {
            leccionesCompletadas: sesion.progreso[cursoActualData.id],
            modulosAprobados: []
        };
    }
    const progreso = sesion.progreso[cursoActualData.id];

    // Incrementar intentos
    if (!progreso.intentos) progreso.intentos = {};
    if (!progreso.intentos[mIdx]) progreso.intentos[mIdx] = 0;
    progreso.intentos[mIdx]++;

    const numIntentos = progreso.intentos[mIdx];
    const maxIntentos = modulo.maxIntentos || 0;

    // Guardar evaluación
    if (!progreso.evaluaciones) progreso.evaluaciones = {};
    progreso.evaluaciones[mIdx] = {
        calificacion: porcentaje,
        aprobado: porcentaje >= min
    };

    // Renderizar feedback
    const quizArea = document.querySelector('.quiz-area');
    if (quizArea) {
        document.querySelectorAll('.quiz-area input').forEach(input => input.disabled = true);
        quizArea.innerHTML = preguntas.map((p, i) => {
            const selectedInput = document.querySelector(`input[name="q${i}"]:checked`);
            const selectedVal = selectedInput ? parseInt(selectedInput.value) : -1;
            const isCorrect = selectedVal === p.correcta;
            return `
                <div class="mb-4 p-3 border rounded ${isCorrect ? 'border-success bg-success bg-opacity-10' : 'border-danger bg-danger bg-opacity-10'}">
                    <h6 class="fw-bold">
                        ${i + 1}. ${p.enunciado} 
                        ${isCorrect ? '<span class="text-success ms-2"><i class="bi bi-check-circle-fill"></i> Correcto</span>' : '<span class="text-danger ms-2"><i class="bi bi-x-circle-fill"></i> Incorrecto</span>'}
                    </h6>
                    ${p.opciones.map((opt, oIdx) => {
                let optionClass = "";
                let icon = "";
                if (oIdx === p.correcta) {
                    optionClass = "text-success fw-bold";
                    icon = '<i class="bi bi-check2 text-success me-1"></i>';
                } else if (oIdx === selectedVal) {
                    optionClass = "text-danger text-decoration-line-through";
                    icon = '<i class="bi bi-x text-danger me-1"></i>';
                }
                return `
                            <div class="py-1 d-flex align-items-center">
                                <input class="form-check-input me-2" type="radio" name="q_feedback_${i}" value="${oIdx}" ${oIdx === selectedVal ? 'checked' : ''} disabled>
                                <span class="${optionClass}">${icon}${opt}</span>
                            </div>`;
            }).join('')}
                </div>`;
        }).join('');
    }

    // Ocultar botón
    const submitBtn = document.getElementById('btn-enviar-evaluacion');
    if (submitBtn) submitBtn.style.display = 'none';

    // Procesar Aprobado / Reprobado
    if (porcentaje >= min) {
        sesion.certificadosCurso = sesion.certificadosCurso || [];
        if (!progreso.modulosAprobados) progreso.modulosAprobados = [];
        if (!progreso.modulosAprobados.includes(String(mIdx))) {
            progreso.modulosAprobados.push(String(mIdx));
        }

        const modulosConEvaluacion = cursoActualData.modulos.filter(m =>
            m.evaluacion && m.evaluacion.preguntas && m.evaluacion.preguntas.length > 0
        ).length;
        if (progreso.modulosAprobados.length === modulosConEvaluacion) {
            if (!sesion.certificadosCurso.includes(cursoActualData.id)) {
                sesion.certificadosCurso.push(cursoActualData.id);
            }
        }

        // Medalla
        if (!progreso.medallas) progreso.medallas = [];
        if (!progreso.medallas.includes(String(mIdx))) {
            progreso.medallas.push(String(mIdx));
        }

        // GUARDAR EN EL USUARIO Y EN EL SERVIDOR
        await guardarProgresoUsuario();

        feedback.innerHTML = `
            <div class="alert alert-success text-center p-4">
                <i class="bi bi-patch-check-fill display-4 text-success"></i>
                <h4 class="mt-3">¡Módulo Aprobado con ${porcentaje}%!</h4>
                <p>Calificación: <strong>${porcentaje}%</strong> | Intentos realizados: <strong>${numIntentos}</strong></p>
                <p>Has ganado una medalla por completar este módulo.</p>
                <div class="display-1 mb-3">🏆</div>
                <p>Ahora puedes continuar con el siguiente contenido.</p>
                <button class="btn btn-primary" onclick="window.location.reload()">Continuar</button>
            </div>`;
    } else {
        // Reprobado
        let puedeReintentar = true;
        if (maxIntentos > 0 && numIntentos >= maxIntentos) {
            puedeReintentar = false;
        }

        if (puedeReintentar) {
            // GUARDAR PROGRESO (intentos incrementados)
            await guardarProgresoUsuario();

            let intentosMsg = `Intento realizado: <strong>${numIntentos}</strong>`;
            if (maxIntentos > 0) {
                intentosMsg += ` / <strong>${maxIntentos}</strong> máximo`;
            }

            feedback.innerHTML = `
                <div class="alert alert-danger text-center p-4">
                    <i class="bi bi-x-circle-fill display-4 text-danger"></i>
                    <h4 class="mt-3">Módulo No Aprobado: ${porcentaje}%</h4>
                    <p>Mínimo requerido: ${min}% | ${intentosMsg}</p>
                    <p class="mt-3 fw-bold">¿Deseas volver a intentarlo?</p>
                    <div class="d-flex justify-content-center gap-3 mt-2">
                        <button class="btn btn-warning" onclick="reintentarEvaluacion('${cursoActualData.id}', ${mIdx})">Sí, intentar de nuevo</button>
                        <button class="btn btn-secondary" onclick="window.location.reload()">No, volver al curso</button>
                    </div>
                </div>`;
        } else {
            // Superó intentos - reiniciar lecciones
            progreso.intentos[mIdx] = 0;
            if (progreso.leccionesCompletadas) {
                progreso.leccionesCompletadas = progreso.leccionesCompletadas.filter(
                    lecId => !lecId.startsWith(mIdx + '-')
                );
            }

            // GUARDAR REINICIO
            await guardarProgresoUsuario();

            feedback.innerHTML = `
                <div class="alert alert-danger text-center p-4">
                    <i class="bi bi-exclamation-triangle-fill display-4 text-warning"></i>
                    <h4 class="mt-3">Superaste el límite de intentos</h4>
                    <p>Has realizado ${numIntentos} de ${maxIntentos} intentos permitidos y no lograste aprobar.</p>
                    <p class="text-muted">Como consecuencia, debes volver a ver todas las lecciones de este módulo para poder realizar la evaluación de nuevo.</p>
                    <button class="btn btn-primary mt-2" onclick="window.location.reload()">Entendido, ir a las lecciones</button>
                </div>`;
        }
    }
};

window.reintentarEvaluacion = (cursoID, mIdx) => {
    mostrarEvaluacionModulo(cursoID, mIdx);
};

window.descargarCertificado = (nombre, cedula, curso) => {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
    const logo = localStorage.getItem('aluLogo');

    doc.setDrawColor(43, 61, 79);
    doc.setLineWidth(2);
    doc.rect(10, 10, 277, 190);
    doc.setDrawColor(255, 215, 0);
    doc.rect(12, 12, 273, 186);

    if (logo) {
        try { doc.addImage(logo, 'PNG', 20, 20, 40, 40); } catch (e) { console.error("Error al cargar logo", e); }
    }

    doc.setFont("helvetica", "bold");
    doc.setFontSize(30);
    doc.text("UNIVERSIDAD DEL ALUMINIO", 148, 45, { align: "center" });

    doc.setFontSize(20);
    doc.setFont("helvetica", "normal");
    doc.text("Otorga el presente certificado a:", 148, 80, { align: "center" });

    doc.setFontSize(35);
    doc.text(nombre.toUpperCase(), 148, 100, { align: "center" });
    doc.setFontSize(16);
    doc.text(`Cédula de Identidad: ${cedula}`, 148, 110, { align: "center" });

    doc.setFontSize(18);
    doc.text("Por haber cumplido con los requisitos académicos del curso:", 148, 135, { align: "center" });
    doc.setFont("helvetica", "bold");
    doc.text(curso, 148, 150, { align: "center" });

    doc.setFont("helvetica", "italic");
    doc.setFontSize(12);
    doc.text(`Fecha de emisión: ${new Date().toLocaleDateString()}`, 148, 180, { align: "center" });

    doc.save(`Certificado_${curso}_${nombre}.pdf`);
};

window.duplicarCarrera = async (originalCareerId) => {
    if (!confirm('¿Estás seguro de duplicar esta carrera? Se crearán nuevos cursos y módulos.')) return;

    const originalCareer = carreras.find(c => c.id === originalCareerId);
    if (!originalCareer) {
        alert('Carrera original no encontrada.');
        return;
    }

    const newCareerId = "CAR-" + Date.now() + "-DUP";
    const newCareer = { ...originalCareer, id: newCareerId, nombre: `Copia de ${originalCareer.nombre}` };
    newCareer.cursos = [];

    originalCareer.cursos.forEach(courseId => {
        const originalCourse = cursos.find(c => c.id === courseId);
        if (originalCourse) {
            const newCourseId = "CUR-" + Date.now() + "-" + Math.random().toString(36).substr(2, 5);
            const newCourse = JSON.parse(JSON.stringify({ ...originalCourse, id: newCourseId, titulo: `Copia de ${originalCourse.titulo}` }));
            cursos.push(newCourse);
            newCareer.cursos.push(newCourseId);
        }
    });

    carreras.push(newCareer);
    await guardarTodo();
    location.reload();
};

function verificarAccesoLeccion(mIdx, lIdx) {
    if (mIdx === 0 && lIdx === 0) return true;

    const progreso = sesion.progreso[cursoActualData.id];
    if (!progreso) return false;

    if (Array.isArray(progreso)) {
        sesion.progreso[cursoActualData.id] = { leccionesCompletadas: progreso, modulosAprobados: [] };
    }

    const prog = sesion.progreso[cursoActualData.id];

    if (lIdx === 0 && mIdx > 0) {
        return prog.modulosAprobados && prog.modulosAprobados.includes(String(mIdx - 1));
    }

    let prevM = mIdx, prevL = lIdx - 1;
    if (prevL < 0) {
        prevM = mIdx - 1;
        if (prevM < 0) return true;
        prevL = cursoActualData.modulos[prevM].lecciones.length - 1;
    }
    const prevLecID = `${prevM}-${prevL}`;
    return prog.leccionesCompletadas && prog.leccionesCompletadas.includes(prevLecID);
}

function normalizarCurso(curso) {
    curso.modulos = Array.isArray(curso.modulos) ? curso.modulos.map(mod => ({
        titulo: mod.titulo || 'Módulo sin título',
        lecciones: Array.isArray(mod.lecciones) ? mod.lecciones : [],
        evaluacion: mod.evaluacion && Array.isArray(mod.evaluacion.preguntas) ? { preguntas: mod.evaluacion.preguntas } : { preguntas: [] }
    })) : [];
    return curso;
}

function renderizarCursoTeachlr(curso) {
    curso = normalizarCurso(curso);
    cursoActualData = curso;

    if (!sesion.progreso[curso.id]) sesion.progreso[curso.id] = { leccionesCompletadas: [], modulosAprobados: [] };
    if (Array.isArray(sesion.progreso[curso.id])) {
        sesion.progreso[curso.id] = { leccionesCompletadas: sesion.progreso[curso.id], modulosAprobados: [] };
    }

    const leccionesCompletadas = sesion.progreso[curso.id].leccionesCompletadas || [];
    const modulosAprobados = sesion.progreso[curso.id].modulosAprobados || [];
    const modulosList = curso.modulos || [];
    const totalLecciones = modulosList.reduce((acc, m) => acc + (m.lecciones ? m.lecciones.length : 0), 0);
    const completadas = leccionesCompletadas.length;
    const progreso = totalLecciones > 0 ? Math.round((completadas / totalLecciones) * 100) : 0;

    const yaTieneCertificado = sesion.certificadosCurso && sesion.certificadosCurso.includes(curso.id);

    return `
        <div class="row mt-2 g-4">
            <div class="col-md-4 col-lg-3">
                <div class="card shadow-sm p-3 mb-3">
                    <h5 class="fw-bold mb-2 text-primary">${curso.titulo}</h5>
                    <div class="progress"><div class="progress-bar" style="width: ${progreso}%"></div></div>
                    <div class="d-flex justify-content-between align-items-center mt-1">
                        <small class="text-muted">${progreso}% completado</small>
                        ${yaTieneCertificado ? '<span class="badge bg-success">Certificado</span>' : ''}
                    </div>
                    
                    <div class="mt-3">
                        <h6 class="small fw-bold text-muted text-uppercase mb-2">Medallas Obtenidas</h6>
                        <div class="d-flex flex-wrap gap-2">
                            ${modulosAprobados.length > 0 ? modulosAprobados.map(m => {
        const modIndex = parseInt(m);
        const moduloNombre = curso.modulos[modIndex] ? curso.modulos[modIndex].titulo : `Módulo ${modIndex + 1}`;
        return `
                                    <div class="badge bg-light text-dark border p-2 d-flex align-items-center" title="${moduloNombre} Aprobado">
                                        <span class="fs-5 me-1">🏆</span>
                                        <small>${moduloNombre}</small>
                                    </div>
                                `;
    }).join('') : '<small class="text-muted italic">Aún no has ganado medallas</small>'}
                        </div>
                    </div>
                    ${yaTieneCertificado ? `
                        <button class="btn btn-sm btn-dark w-100 mt-3" onclick="descargarCertificado('${sesion.nombre}', '${sesion.id}', '${curso.titulo}')">
                            <i class="bi bi-download"></i> Descargar PDF
                        </button>
                    ` : ''}
                </div>
                <div class="accordion sidebar-modulos shadow-sm" id="accordionModulos">
                    ${modulosList.map((mod, idx) => {
        const tieneLecciones = mod.lecciones && mod.lecciones.length > 0;
        const todasLeccionesMod = tieneLecciones && mod.lecciones.every((_, lIdx) => leccionesCompletadas.includes(`${idx}-${lIdx}`));
        const moduloAprobado = modulosAprobados.includes(String(idx));
        const tieneEvaluacion = mod.evaluacion && mod.evaluacion.preguntas && mod.evaluacion.preguntas.length > 0;
        const estaEnCurso = tieneLecciones && mod.lecciones.some((_, lIdx) => leccionesCompletadas.includes(`${idx}-${lIdx}`)) && !moduloAprobado;

        let estadoEvaluacion = 'pendiente';
        if (moduloAprobado) estadoEvaluacion = 'aprobado';
        else if (!tieneLecciones || !todasLeccionesMod) estadoEvaluacion = 'bloqueado';
        else if (tieneEvaluacion) estadoEvaluacion = 'disponible';

        return `
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button ${idx === 0 ? '' : 'collapsed'}" type="button" data-bs-toggle="collapse" data-bs-target="#mod${idx}">
                                    ${moduloAprobado ? '<i class="bi bi-check-circle-fill lesson-completed-icon me-2"></i>' : (estaEnCurso ? '<i class="bi bi-play-circle-fill text-primary me-2"></i>' : '<i class="bi bi-folder me-2"></i>')} 
                                    Módulo ${idx + 1}: ${mod.titulo}
                                </button>
                            </h2>
                            <div id="mod${idx}" class="accordion-collapse collapse ${idx === 0 ? 'show' : ''}" data-bs-parent="#accordionModulos">
                                <div class="list-group list-group-flush">
                                    ${tieneLecciones ? mod.lecciones.map((lec, lIdx) => {
            const lecID = `${idx}-${lIdx}`;
            const estaCompletada = leccionesCompletadas.includes(lecID);
            const estaBloqueada = !verificarAccesoLeccion(idx, lIdx);
            return `
                                        <button class="list-group-item list-group-item-action btn-leccion ${estaBloqueada ? 'lesson-locked' : ''}"
                                                ${estaBloqueada ? 'disabled' : ''} 
                                                id="btn-l-${idx}-${lIdx}"
                                                onclick="seleccionarLeccion(${idx}, ${lIdx})">
                                            <div class="d-flex justify-content-between">
                                                <span><i class="bi ${estaCompletada ? 'bi-check-circle-fill text-success' : 'bi-play-circle'} me-2"></i> ${lec.titulo}</span>
                                                ${estaBloqueada ? '<i class="bi bi-lock-fill"></i>' : ''}
                                            </div>
                                        </button>
                                    `}).join('') : `
                                        <div class="list-group-item text-muted text-center py-3">
                                            <i class="bi bi-exclamation-triangle me-2"></i>Este módulo no tiene lecciones
                                        </div>
                                    `}
                                    <button class="list-group-item list-group-item-action text-center py-2 fw-bold 
                                        ${estadoEvaluacion === 'aprobado' ? 'bg-success bg-opacity-10 text-success' : ''}
                                        ${estadoEvaluacion === 'disponible' ? 'bg-primary bg-opacity-10 text-primary' : ''}
                                        ${estadoEvaluacion === 'bloqueado' ? 'bg-secondary bg-opacity-10 text-secondary disabled opacity-50' : ''}
                                        ${estadoEvaluacion === 'pendiente' && !tieneEvaluacion ? 'bg-warning bg-opacity-10 text-warning' : ''}"
                                        onclick="${estadoEvaluacion === 'disponible' ? `event.stopPropagation(); mostrarEvaluacionModulo('${curso.id}', ${idx})` : 'return false'}"
                                        ${estadoEvaluacion === 'bloqueado' || (estadoEvaluacion === 'pendiente' && !tieneEvaluacion) ? 'disabled' : ''}>
                                        <i class="bi ${estadoEvaluacion === 'aprobado' ? 'bi-check-circle-fill' :
                estadoEvaluacion === 'disponible' ? 'bi-clipboard-check' :
                    estadoEvaluacion === 'bloqueado' ? 'bi-lock-fill' : 'bi-hourglass-split'
            } me-2"></i> 
                                        ${estadoEvaluacion === 'aprobado' ? 'Evaluación Aprobada' :
                estadoEvaluacion === 'disponible' ? 'Realizar Evaluación' :
                    estadoEvaluacion === 'bloqueado' ? (tieneLecciones ? 'Completa las lecciones primero' : 'Agrega lecciones a este módulo') :
                        !tieneEvaluacion ? 'Sin evaluación disponible' : 'Evaluación bloqueada'
            }
                                    </button>
                                </div>
                            </div>
                        </div>
                    `}).join('')}
                </div>
            </div>
            <div class="col-md-8 col-lg-9" id="visor-contenido">
                <div class="text-center py-5 text-muted bg-white rounded shadow-sm">
                    <div class="spinner-border text-primary mb-3"></div>
                    <p>Selecciona una lección para comenzar</p>
                </div>
            </div>
        </div>
    `;
}

function mostrarDetalleCurso(cursoId) {
    const curso = cursos.find(c => c.id === cursoId);
    const contenidoCursoDiv = document.getElementById('contenido-curso');

    if (curso) {
        contenidoCursoDiv.innerHTML = renderizarCursoTeachlr(normalizarCurso(curso));
        seleccionarLeccion(0, 0);
    } else {
        contenidoCursoDiv.innerHTML = `
            <div class="alert alert-warning text-center" role="alert">
                Curso no encontrado.
            </div>`;
    }
}

// ============================================================
// FUNCIONES DE ADMINISTRACIÓN - RESTABLECER Y MARCAR COMPLETADO
// ============================================================

window.abrirRestablecerAvance = (userId) => {
    const u = usuarios.find(user => user.id === userId);
    if (!u) return;

    document.getElementById('restablecer-user-id').value = u.id;
    document.getElementById('restablecer-user-nombre').value = u.nombre;

    const select = document.getElementById('restablecer-curso-select');
    select.innerHTML = '<option value="">-- Seleccionar Curso --</option>';

    const cursosIds = Object.keys(u.progreso || {});

    cursosIds.forEach(cursoId => {
        const curso = cursos.find(c => c.id === cursoId);
        if (curso) {
            select.innerHTML += `<option value="${curso.id}">${curso.titulo}</option>`;
        }
    });

    document.getElementById('restablecer-modulos-container').style.display = 'none';

    const modalEl = document.getElementById('restablecerAvanceModal');
    const bModal = new bootstrap.Modal(modalEl);
    bModal.show();
};

window.cambiarCarreraRestablecer = () => {
    const cursoId = document.getElementById('restablecer-curso-select').value;
    const container = document.getElementById('restablecer-modulos-container');
    const body = document.getElementById('restablecer-modulos-body');
    const checkAll = document.getElementById('restablecer-select-all');

    if (!body) {
        console.error('Elemento restablecer-modulos-body no encontrado en el DOM');
        return;
    }

    if (checkAll) checkAll.checked = false;

    if (!cursoId) {
        if (container) container.style.display = 'none';
        body.innerHTML = '';
        return;
    }

    const curso = cursos.find(c => c.id === cursoId);
    if (!curso) {
        if (container) container.style.display = 'none';
        body.innerHTML = '';
        return;
    }

    if (container) container.style.display = 'block';
    body.innerHTML = '';

    let tieneModulos = false;

    if (curso.modulos && curso.modulos.length > 0) {
        curso.modulos.forEach((mod, mIdx) => {
            tieneModulos = true;
            body.innerHTML += `
                <tr>
                    <td style="width: 40px;" class="text-center">
                        <input type="checkbox" class="form-check-input modulo-restablecer-checkbox" data-course-id="${curso.id}" data-module-idx="${mIdx}">
                    </td>
                    <td>
                        <strong>${curso.titulo}</strong>
                    </td>
                    <td>
                        ${mod.titulo}
                    </td>
                </tr>
            `;
        });
    }

    if (!tieneModulos) {
        body.innerHTML = '<tr><td colspan="3" class="text-center text-muted">Este curso no tiene módulos.</td></tr>';
    }
};

window.seleccionarTodosModulosRestablecer = (check) => {
    document.querySelectorAll('.modulo-restablecer-checkbox').forEach(cb => {
        cb.checked = check;
    });
};

window.confirmarRestablecerAvance = async (completa) => {
    const userId = document.getElementById('restablecer-user-id').value;
    const cursoId = document.getElementById('restablecer-curso-select').value;
    const uIdx = usuarios.findIndex(u => u.id === userId);
    if (uIdx === -1) return;
    const u = usuarios[uIdx];

    if (!cursoId) {
        alert("Por favor selecciona un curso.");
        return;
    }

    if (completa) {
        if (!confirm(`¿Estás seguro de restablecer por completo el avance del curso seleccionado para el usuario ${u.nombre}?`)) return;

        const curso = cursos.find(c => c.id === cursoId);
        if (!curso) return;

        if (u.progreso && u.progreso[cursoId]) {
            delete u.progreso[cursoId];
        }

        if (u.certificadosCurso) {
            u.certificadosCurso = u.certificadosCurso.filter(id => id !== cursoId);
        }
    } else {
        const checked = Array.from(document.querySelectorAll('.modulo-restablecer-checkbox:checked'));
        if (checked.length === 0) {
            alert("Selecciona al menos un módulo para restablecer.");
            return;
        }
        if (!confirm(`¿Estás seguro de restablecer el avance de los ${checked.length} módulos seleccionados para el usuario ${u.nombre}?`)) return;

        checked.forEach(cb => {
            const courseId = cb.getAttribute('data-course-id');
            const mIdx = parseInt(cb.getAttribute('data-module-idx'));

            if (u.progreso && u.progreso[courseId]) {
                const prog = u.progreso[courseId];

                if (prog.modulosAprobados) {
                    prog.modulosAprobados = prog.modulosAprobados.filter(idx => parseInt(idx) !== mIdx);
                }

                if (prog.medallas) {
                    prog.medallas = prog.medallas.filter(idx => parseInt(idx) !== mIdx);
                }

                if (prog.evaluaciones && prog.evaluaciones[mIdx]) {
                    delete prog.evaluaciones[mIdx];
                }

                if (prog.intentos && prog.intentos[mIdx]) {
                    delete prog.intentos[mIdx];
                }

                if (prog.leccionesCompletadas) {
                    prog.leccionesCompletadas = prog.leccionesCompletadas.filter(lecId => {
                        return !lecId.startsWith(mIdx + '-');
                    });
                }
            }

            if (u.progreso && u.progreso[courseId]) {
                const prog = u.progreso[courseId];
                if (prog.modulosAprobados && prog.modulosAprobados.length === 0) {
                    if (u.certificadosCurso) {
                        u.certificadosCurso = u.certificadosCurso.filter(id => id !== courseId);
                    }
                }
            }
        });
    }

    actualizarEstadoCarrerasUsuario(u);
    await guardarTodo();
    alert("Avance restablecido con éxito.");

    const modalEl = document.getElementById('restablecerAvanceModal');
    const bModal = bootstrap.Modal.getOrCreateInstance(modalEl);
    bModal.hide();

    const backdrop = document.querySelector('.modal-backdrop');
    if (backdrop) backdrop.remove();
    document.body.classList.remove('modal-open');
    document.body.style.overflow = '';
    document.body.style.paddingRight = '';

    location.reload();
};

window.abrirMarcarCompletado = (userId) => {
    const u = usuarios.find(user => user.id === userId);
    if (!u) return;

    document.getElementById('marcar-user-id').value = u.id;
    document.getElementById('marcar-user-nombre').value = u.nombre;

    const select = document.getElementById('marcar-curso-select');
    select.innerHTML = '<option value="">-- Seleccionar Curso --</option>';

    cursos.forEach(curso => {
        select.innerHTML += `<option value="${curso.id}">${curso.titulo}</option>`;
    });

    document.getElementById('marcar-modulos-container').style.display = 'none';

    const modalEl = document.getElementById('marcarCompletadoModal');
    const bModal = new bootstrap.Modal(modalEl);
    bModal.show();
};

window.cargarModulosParaMarcar = () => {
    const cursoId = document.getElementById('marcar-curso-select').value;
    const userId = document.getElementById('marcar-user-id').value;
    const container = document.getElementById('marcar-modulos-container');
    const body = document.getElementById('marcar-modulos-body');
    const checkAll = document.getElementById('marcar-select-all');

    if (!body) {
        console.error('Elemento marcar-modulos-body no encontrado en el DOM');
        return;
    }

    if (checkAll) checkAll.checked = false;

    if (!cursoId) {
        if (container) container.style.display = 'none';
        body.innerHTML = '';
        return;
    }

    const curso = cursos.find(c => c.id === cursoId);
    const usuario = usuarios.find(u => u.id === userId);

    if (!curso || !usuario) {
        if (container) container.style.display = 'none';
        body.innerHTML = '';
        return;
    }

    if (container) container.style.display = 'block';
    body.innerHTML = '';

    let tieneModulos = false;

    if (curso.modulos && curso.modulos.length > 0) {
        if (!usuario.progreso) usuario.progreso = {};
        if (!usuario.progreso[cursoId]) {
            usuario.progreso[cursoId] = {
                leccionesCompletadas: [],
                modulosAprobados: [],
                medallas: [],
                evaluaciones: {},
                intentos: {}
            };
        }

        const modulosAprobados = usuario.progreso[cursoId]?.modulosAprobados || [];

        curso.modulos.forEach((mod, mIdx) => {
            tieneModulos = true;
            const yaAprobado = modulosAprobados.includes(String(mIdx));
            const estadoActual = yaAprobado ?
                '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Completado</span>' :
                '<span class="badge bg-secondary"><i class="bi bi-hourglass"></i> Pendiente</span>';

            const tieneEvaluacion = mod.evaluacion && mod.evaluacion.preguntas && mod.evaluacion.preguntas.length > 0;
            const badgeEvaluacion = tieneEvaluacion ?
                '<span class="badge bg-info ms-2">Con Evaluación</span>' :
                '<span class="badge bg-secondary ms-2">Sin Evaluación</span>';

            const totalLecciones = mod.lecciones ? mod.lecciones.length : 0;
            const leccionesInfo = totalLecciones > 0 ? `${totalLecciones} lección(es)` : 'Sin lecciones';

            body.innerHTML += `
                <tr>
                    <td style="width: 40px;" class="text-center">
                        <input type="checkbox" class="form-check-input modulo-marcar-checkbox" 
                               data-course-id="${curso.id}" 
                               data-module-idx="${mIdx}"
                               ${yaAprobado ? 'disabled' : ''}>
                    </td>
                    <td>
                        <strong>${curso.titulo}</strong>
                        <br>
                        <small class="text-muted">ID: ${curso.id}</small>
                    </td>
                    <td>
                        <strong>${mod.titulo}</strong>
                        ${badgeEvaluacion}
                        <br>
                        <small class="text-muted">${leccionesInfo}</small>
                    </td>
                    <td class="text-center">
                        ${estadoActual}
                    </td>
                </tr>
            `;
        });
    }

    if (!tieneModulos) {
        body.innerHTML = '<tr><td colspan="4" class="text-center text-muted">Este curso no tiene módulos definidos. Crea módulos primero.</td></tr>';
    } else {
        const totalModulos = curso.modulos.length;
        const modulosAprobados = usuario.progreso[cursoId]?.modulosAprobados || [];
        const completados = modulosAprobados.length;
        const progressPercent = totalModulos > 0 ? Math.round((completados / totalModulos) * 100) : 0;

        const progressRow = document.createElement('tr');
        progressRow.innerHTML = `
            <td colspan="4" class="bg-light">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="small fw-bold">Progreso actual del usuario:</span>
                    <span class="small fw-bold">${completados}/${totalModulos} módulos (${progressPercent}%)</span>
                </div>
                <div class="progress" style="height: 8px;">
                    <div class="progress-bar bg-success" style="width: ${progressPercent}%"></div>
                </div>
            </td>
        `;
        body.insertBefore(progressRow, body.firstChild);
    }
};

window.seleccionarTodosModulosParaMarcar = (check) => {
    document.querySelectorAll('.modulo-marcar-checkbox:not(:disabled)').forEach(cb => {
        cb.checked = check;
    });
};

window.confirmarMarcarCompletado = async () => {
    const userId = document.getElementById('marcar-user-id').value;
    const cursoId = document.getElementById('marcar-curso-select').value;
    const uIdx = usuarios.findIndex(u => u.id === userId);

    if (uIdx === -1) {
        alert("Usuario no encontrado.");
        return;
    }

    const usuario = usuarios[uIdx];

    if (!cursoId) {
        alert("Por favor selecciona un curso.");
        return;
    }

    const checked = Array.from(document.querySelectorAll('.modulo-marcar-checkbox:checked'));
    if (checked.length === 0) {
        alert("Selecciona al menos un módulo para marcar como completado.");
        return;
    }

    const curso = cursos.find(c => c.id === cursoId);
    if (!curso) {
        alert("Curso no encontrado.");
        return;
    }

    const moduloNombres = checked.map(cb => {
        const mIdx = parseInt(cb.getAttribute('data-module-idx'));
        return curso.modulos[mIdx]?.titulo || `Módulo ${mIdx + 1}`;
    }).join('\n• ');

    if (!confirm(`⚠️ ¿Estás seguro de marcar como COMPLETADOS los siguientes módulos del curso "${curso.titulo}" para el usuario ${usuario.nombre}?\n\n• ${moduloNombres}\n\n✅ Esto:\n• Aprobará automáticamente sus evaluaciones\n• Otorgará las medallas correspondientes\n• Marcará todas las lecciones como completadas\n• Actualizará su progreso general\n\nEsta acción NO se puede deshacer fácilmente.`)) {
        return;
    }

    if (!usuario.progreso || Array.isArray(usuario.progreso) || typeof usuario.progreso !== 'object') {
        usuario.progreso = {};
    }
    if (!usuario.progreso[cursoId] || Array.isArray(usuario.progreso[cursoId]) || typeof usuario.progreso[cursoId] !== 'object') {
        usuario.progreso[cursoId] = {
            leccionesCompletadas: [],
            modulosAprobados: [],
            medallas: [],
            evaluaciones: {},
            intentos: {}
        };
    }

    const progreso = usuario.progreso[cursoId];
    const minAprobacion = (db.configuracion && db.configuracion.minAprobacion) || 70;

    let marcadosExitosos = 0;
    let errores = [];

    for (const cb of checked) {
        const mIdx = parseInt(cb.getAttribute('data-module-idx'));
        const modulo = curso.modulos[mIdx];

        if (!modulo) {
            errores.push(`Módulo índice ${mIdx} no encontrado`);
            continue;
        }

        try {
            if (!progreso.modulosAprobados.includes(String(mIdx))) {
                progreso.modulosAprobados.push(String(mIdx));
            }

            if (!progreso.medallas) progreso.medallas = [];
            if (!progreso.medallas.includes(String(mIdx))) {
                progreso.medallas.push(String(mIdx));
            }

            if (modulo.evaluacion && modulo.evaluacion.preguntas && modulo.evaluacion.preguntas.length > 0) {
                if (!progreso.evaluaciones) progreso.evaluaciones = {};
                if (!progreso.evaluaciones[mIdx]) {
                    progreso.evaluaciones[mIdx] = {
                        calificacion: minAprobacion,
                        aprobado: true,
                        marcadoManual: true,
                        fecha: new Date().toISOString()
                    };
                } else if (!progreso.evaluaciones[mIdx].aprobado) {
                    progreso.evaluaciones[mIdx].calificacion = minAprobacion;
                    progreso.evaluaciones[mIdx].aprobado = true;
                    progreso.evaluaciones[mIdx].marcadoManual = true;
                    progreso.evaluaciones[mIdx].fecha = new Date().toISOString();
                }
            }

            if (!progreso.leccionesCompletadas) progreso.leccionesCompletadas = [];

            if (modulo.lecciones && modulo.lecciones.length > 0) {
                for (let lIdx = 0; lIdx < modulo.lecciones.length; lIdx++) {
                    const lecId = `${mIdx}-${lIdx}`;
                    if (!progreso.leccionesCompletadas.includes(lecId)) {
                        progreso.leccionesCompletadas.push(lecId);
                    }
                }
            }

            if (!progreso.intentos) progreso.intentos = {};
            progreso.intentos[mIdx] = 1;

            marcadosExitosos++;

        } catch (err) {
            errores.push(`Módulo "${modulo.titulo}": ${err.message}`);
        }
    }

    const modulosConEvaluacion = curso.modulos.filter(m => m.evaluacion && m.evaluacion.preguntas && m.evaluacion.preguntas.length > 0).length;
    const modulosRequeridosParaCertificado = modulosConEvaluacion > 0 ? modulosConEvaluacion : curso.modulos.length;

    if (progreso.modulosAprobados.length >= modulosRequeridosParaCertificado) {
        if (!usuario.certificadosCurso) usuario.certificadosCurso = [];
        if (!usuario.certificadosCurso.includes(cursoId)) {
            usuario.certificadosCurso.push(cursoId);
            console.log(`✅ Certificado otorgado para el curso: ${curso.titulo} a ${usuario.nombre}`);
        }
    }

    // Actualizar carreras del usuario
    actualizarEstadoCarrerasUsuario(usuario);

    await guardarTodo();

    let mensaje = `✅ Se han marcado ${marcadosExitosos} módulo(s) como completados exitosamente para ${usuario.nombre}.`;
    if (errores.length > 0) {
        mensaje += `\n\n⚠️ Errores:\n• ${errores.join('\n• ')}`;
    }

    const totalModulosCurso = curso.modulos.length;
    const progresoActual = (progreso.modulosAprobados || []).length;
    if (progresoActual >= totalModulosCurso) {
        mensaje += `\n\n🎉 ¡FELICIDADES! El usuario ha completado TODOS los módulos del curso "${curso.titulo}". Se ha generado su certificado.`;
    }

    // Verificar si alguna carrera se completó
    const carrerasCompletas = (usuario.carrerasAsignadas || []).filter(ca => ca.estado === 'Completada').map(ca => {
        const cObj = carreras.find(c => c.id === ca.id);
        return cObj ? cObj.nombre : ca.id;
    });
    if (carrerasCompletas.length > 0) {
        mensaje += `\n\n🎓 ¡CARRERA COMPLETADA! ${usuario.nombre} ha completado la carrera: ${carrerasCompletas.join(', ')}.`;
    }

    alert(mensaje);

    const modalEl = document.getElementById('marcarCompletadoModal');
    const bModal = bootstrap.Modal.getOrCreateInstance(modalEl);
    bModal.hide();

    const backdrop = document.querySelector('.modal-backdrop');
    if (backdrop) backdrop.remove();
    document.body.classList.remove('modal-open');
    document.body.style.overflow = '';
    document.body.style.paddingRight = '';

    location.reload();
};
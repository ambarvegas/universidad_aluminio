/**
 * js/core/state.js — Universidad del Aluminio
 * Módulo central de estado global reactivo y utilidades compartidas.
 */

(function () {
    'use strict';

    const DB_KEY = 'uniDatabase';

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

    window.initialDB = initialDB;
    window.db = window.db || JSON.parse(JSON.stringify(initialDB));
    window.usuarios = window.usuarios || window.db.usuarios || [];
    window.cursos = window.cursos || window.db.cursos || [];
    window.carreras = window.carreras || window.db.carreras || [];
    window.rolesConfig = window.rolesConfig || window.db.rolesConfig || [];
    window.solicitudesRegistro = window.solicitudesRegistro || window.db.solicitudesRegistro || [];
    window.solicitudesCursos = window.solicitudesCursos || window.db.solicitudesCursos || [];

    try {
        window.sesion = JSON.parse(sessionStorage.getItem('aluSesion')) || null;
    } catch (_) {
        window.sesion = null;
    }

    window.tempModulos = window.tempModulos || [];
    window.tempModuloEvaluacion = window.tempModuloEvaluacion || { preguntas: [] };
    window.tempImagenPortada = window.tempImagenPortada || "";
    window.cursoActualData = window.cursoActualData || null;
    window.moduloActualIdx = window.moduloActualIdx || 0;
    window.leccionActualIdx = window.leccionActualIdx || 0;

    // ------------------------------------------------------------
    // UTILIDADES DE BOTÓN Y LOADING
    // ------------------------------------------------------------

    window.handleButtonLoading = function (btn, loading, textLoading = 'Procesando...', textOriginal = null) {
        if (typeof btn === 'string') btn = document.querySelector(btn);
        if (!btn) return null;

        if (!btn.dataset.originalHtml && !loading) {
            btn.dataset.originalHtml = btn.innerHTML;
            btn.dataset.originalText = btn.textContent.trim();
        }

        if (loading) {
            btn.disabled = true;
            btn.dataset.originalHtml = btn.dataset.originalHtml || btn.innerHTML;
            btn.innerHTML = `
                <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                ${textLoading}
            `;
        } else {
            btn.disabled = false;
            if (textOriginal) {
                btn.innerHTML = textOriginal;
            } else if (btn.dataset.originalHtml) {
                btn.innerHTML = btn.dataset.originalHtml;
            }
        }
        return btn;
    };

    window.withLoading = async function (btnElement, asyncFn, textLoading = 'Procesando...') {
        try {
            if (btnElement) window.handleButtonLoading(btnElement, true, textLoading);
            return await asyncFn();
        } finally {
            if (btnElement) window.handleButtonLoading(btnElement, false);
        }
    };

    // ------------------------------------------------------------
    // MODELOS Y MAPEO DE CARRERAS
    // ------------------------------------------------------------

    window.getCareerIdFromRole = function (roleId) {
        const careerMap = {
            "asesor_ventas": "CAR-ASESOR-VENTAS",
            "retail": "CAR-RETAIL",
            "almacenista_retail": "CAR-ALMACENISTA",
            "coordinador_retail": "CAR-COORD-RETAIL",
            "cristalero": "CAR-CRISTALERO"
        };
        return careerMap[roleId] || null;
    };

    window.crearEstructuraUsuario = function (u) {
        if (!u) return null;
        const res = {
            id: String(u.id || "").trim(),
            nombre: String(u.nombre || "").trim(),
            rol: String(u.rol || "participante"),
            estado: String(u.estado || "activo"),
            asignados: Array.isArray(u.asignados) ? u.asignados : [],
            carrerasAsignadas: Array.isArray(u.carrerasAsignadas) ? u.carrerasAsignadas : [],
            certificadosCurso: Array.isArray(u.certificadosCurso) ? u.certificadosCurso : [],
            certificadosCarrera: Array.isArray(u.certificadosCarrera) ? u.certificadosCarrera : [],
            progreso: (u.progreso && typeof u.progreso === 'object' && !Array.isArray(u.progreso)) ? u.progreso : {}
        };
        if (u.clave) {
            res.clave = String(u.clave).trim();
        }
        return res;
    };

    window.actualizarEstadoCarrerasUsuario = function (usuario) {
        if (!usuario) return;
        usuario.carrerasAsignadas = Array.isArray(usuario.carrerasAsignadas) ? usuario.carrerasAsignadas : [];
        usuario.certificadosCarrera = Array.isArray(usuario.certificadosCarrera) ? usuario.certificadosCarrera : [];
        usuario.certificadosCurso = Array.isArray(usuario.certificadosCurso) ? usuario.certificadosCurso : [];
        if (!usuario.progreso || Array.isArray(usuario.progreso) || typeof usuario.progreso !== 'object') {
            usuario.progreso = {};
        }

        const userRoleConfig = (window.rolesConfig || []).find(r => r.id === usuario.rol);
        const roleCareerIds = (userRoleConfig && Array.isArray(userRoleConfig.carreras)) ? userRoleConfig.carreras : [];
        const autoCareerId = window.getCareerIdFromRole(usuario.rol);
        const todasCarrerasIds = new Set([...roleCareerIds]);
        if (autoCareerId) todasCarrerasIds.add(autoCareerId);

        todasCarrerasIds.forEach(carId => {
            if ((window.carreras || []).some(c => c.id === carId)) {
                if (!usuario.carrerasAsignadas.some(ca => ca.id === carId)) {
                    usuario.carrerasAsignadas.push({ id: carId, estado: "Incompleta" });
                }
            }
        });

        usuario.carrerasAsignadas.forEach(ca => {
            const carrera = (window.carreras || []).find(c => c.id === ca.id);
            if (!carrera || !carrera.cursos || carrera.cursos.length === 0) return;

            const todosCursosCompletados = carrera.cursos.every(cId => {
                if (usuario.certificadosCurso && usuario.certificadosCurso.includes(cId)) return true;
                const cursoObj = (window.cursos || []).find(c => c.id === cId);
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
    };

    window.normalizar = function (texto) {
        return texto ? texto.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase() : "";
    };

    window.extraerID = function (input) {
        const regExp = /^.*(youtu.be\/|v\/|u\/\w\/|embed\/|watch\?v=|\&v=)([^#\&\?]*).*/;
        const match = input.match(regExp);
        return (match && match[2].length === 11) ? match[2] : input;
    };

    window.comprimirImagenBase64 = function (file, maxWidth = 1000, maxQuality = 0.82) {
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
    };

    window.obtenerIniciales = function (nombre) {
        if (!nombre) return "AL";
        const parts = nombre.trim().split(/\s+/);
        if (parts.length === 1) return parts[0].substring(0, 2).toUpperCase();
        return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    };

    // ------------------------------------------------------------
    // GUARDAR PROGRESO ATÓMICO DEL USUARIO EN CURSO
    // ------------------------------------------------------------

    window.guardarProgresoUsuario = async function () {
        if (!window.sesion || !window.cursoActualData) return false;
        window.actualizarEstadoCarrerasUsuario(window.sesion);
        sessionStorage.setItem('aluSesion', JSON.stringify(window.sesion));

        const cursoId = window.cursoActualData.id;
        const prog = window.sesion.progreso?.[cursoId] || {};

        try {
            await window.API.guardarProgreso({
                usuario_id:            window.sesion.id,
                curso_id:              cursoId,
                leccionesCompletadas:  prog.leccionesCompletadas  || [],
                modulosAprobados:      prog.modulosAprobados      || [],
                medallas:              prog.medallas              || [],
                evaluaciones:          prog.evaluaciones          || {},
                intentos:              prog.intentos              || {},
                certificadosCurso:     window.sesion.certificadosCurso || []
            });
            return true;
        } catch (err) {
            console.error('Error al guardar_progreso:', err.message);
            return false;
        }
    };

    // ------------------------------------------------------------
    // CARGA INICIAL DESDE EL SERVIDOR
    // ------------------------------------------------------------

    window.cargarDatosDelServidor = async function () {
        const loadingScreen = document.getElementById('admin-loading-screen');
        if (loadingScreen) {
            loadingScreen.classList.remove('hidden');
        }

        try {
            const isAdminPage = window.location.pathname.includes('admin.html');
            let data;
            if (isAdminPage) {
                data = await window.API.cargarDB();
            } else {
                try {
                    data = await window.API.cargarCatalogo();
                } catch (catErr) {
                    console.warn('Fallback a cargarDB por error en catalogo:', catErr.message);
                    data = await window.API.cargarDB();
                }
            }

            if (data && typeof data === 'object' && !Array.isArray(data)) {
                window.db = data;
            }

            window.usuarios = window.db.usuarios || [];
            window.cursos = window.db.cursos || [];
            window.carreras = window.db.carreras || [];
            window.rolesConfig = window.rolesConfig || [];
            window.solicitudesRegistro = window.db.solicitudesRegistro || [];
            window.solicitudesCursos = window.db.solicitudesCursos || [];

            // Sincronizar sesión si está autenticado
            if (window.sesion) {
                const updatedUser = window.usuarios.find(u => u.id === window.sesion.id);
                if (updatedUser) {
                    window.actualizarEstadoCarrerasUsuario(updatedUser);
                    window.sesion = JSON.parse(JSON.stringify(updatedUser));
                    sessionStorage.setItem('aluSesion', JSON.stringify(window.sesion));
                }
            }

            return true;
        } catch (err) {
            console.error('Error al sincronizar datos:', err);
            return false;
        } finally {
            if (loadingScreen) {
                loadingScreen.classList.add('hidden');
            }
        }
    };

    // Deprecated alias
    window.guardarTodo = async function () {
        console.warn("[DEPRECATED] guardarTodo() ha sido reemplazado por mutaciones atómicas vía window.API. Usando importarBackupDB.");
        return window.API.importarBackupDB(window.db);
    };
    window.guardar = window.guardarTodo;
    window.guardarCarreras = window.guardarTodo;
    window.guardarUsuarios = window.guardarTodo;
    window.guardarRoles = window.guardarTodo;
    window.guardarSolicitudes = window.guardarTodo;
})();

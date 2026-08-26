/**
 * js/api.js
 * Módulo centralizado de llamadas al servidor.
 * Todas las interacciones con api.php pasan por aquí.
 * 
 * USO: importar este archivo con <script src="js/api.js"></script>
 * Las funciones se exponen globalmente vía window.API
 */

window.API = (() => {
    const BASE = 'api.php';

    // Indicador de guardado en UI
    let _saveTimer = null;
    function _setSaving(saving) {
        let ind = document.getElementById('save-indicator');
        if (!ind) return;
        if (saving) {
            ind.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Guardando...';
            ind.className = 'save-indicator saving';
        } else {
            ind.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Guardado';
            ind.className = 'save-indicator saved';
            clearTimeout(_saveTimer);
            _saveTimer = setTimeout(() => {
                ind.className = 'save-indicator';
            }, 2500);
        }
    }

    async function _post(action, body) {
        const url = action ? `${BASE}?action=${action}` : BASE;
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
        return data;
    }

    async function _get(action) {
        const url = action ? `${BASE}?action=${action}` : BASE;
        const res = await fetch(url);
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
        return data;
    }

    return {
        // ---- AUTH ----
        async login(id, clave) {
            return _post('login', { id, clave });
        },

        async solicitarRegistro(payload) {
            return _post('solicitar_registro', payload);
        },

        // ---- DB COMPLETA ----
        async cargarDB() {
            return _get(null);
        },

        async guardarDB(db) {
            _setSaving(true);
            try {
                const r = await _post(null, db);
                _setSaving(false);
                return r;
            } catch (e) {
                _setSaving(false);
                throw e;
            }
        },

        // ---- PROGRESO (granular) ----
        async guardarProgreso(payload) {
            _setSaving(true);
            try {
                const r = await _post('guardar_progreso', payload);
                _setSaving(false);
                return r;
            } catch (e) {
                _setSaving(false);
                throw e;
            }
        },

        // ---- USUARIOS ----
        async guardarUsuario(usuario) {
            _setSaving(true);
            try {
                const r = await _post('guardar_usuario', usuario);
                _setSaving(false);
                return r;
            } catch (e) { _setSaving(false); throw e; }
        },

        async eliminarUsuario(id) {
            return _post('eliminar_usuario', { id });
        },

        // ---- CURSOS ----
        async guardarCurso(curso) {
            _setSaving(true);
            try {
                const r = await _post('guardar_curso', curso);
                _setSaving(false);
                return r;
            } catch (e) { _setSaving(false); throw e; }
        },

        async eliminarCurso(id) {
            return _post('eliminar_curso', { id });
        },

        // ---- CARRERAS ----
        async guardarCarrera(carrera) {
            _setSaving(true);
            try {
                const r = await _post('guardar_carrera', carrera);
                _setSaving(false);
                return r;
            } catch (e) { _setSaving(false); throw e; }
        },

        async eliminarCarrera(id) {
            return _post('eliminar_carrera', { id });
        },

        // ---- ROLES ----
        async guardarRol(rol) {
            _setSaving(true);
            try {
                const r = await _post('guardar_rol', rol);
                _setSaving(false);
                return r;
            } catch (e) { _setSaving(false); throw e; }
        },

        async eliminarRol(id) {
            return _post('eliminar_rol', { id });
        },

        // ---- SOLICITUDES ----
        async solicitarAccesoCurso(userId, userName, cursoId) {
            return _post('solicitar_acceso_curso', {
                userId, userName, cursoId,
                fecha: new Date().toLocaleDateString()
            });
        },

        async eliminarSolicitudRegistro(id) {
            return _post('eliminar_solicitud_registro', { id });
        },

        async eliminarSolicitudCurso(userId, cursoId) {
            return _post('eliminar_solicitud_curso', { usuario_id: userId, curso_id: cursoId });
        },

        // ---- CONFIGURACIÓN ----
        async guardarConfig(clave, valor) {
            return _post('guardar_config', { clave, valor });
        },

        // ---- HEALTH CHECK ----
        async ping() {
            return _get('ping');
        }
    };
})();

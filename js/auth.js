/**
 * js/auth.js
 * Gestión de autenticación, control de sesión y protección de rutas
 */

let sesion = JSON.parse(sessionStorage.getItem('aluSesion')) || null;

/**
 * Autenticación en el servidor (bcrypt)
 */
async function login(id, clave) {
    try {
        const data = await window.API.login(id, clave);
        if (data.usuario) {
            sessionStorage.setItem('aluSesion', JSON.stringify(data.usuario));
            window.sesion = data.usuario;
            return true;
        }
        return false;
    } catch (err) {
        console.error('Error durante el login:', err);
        return false;
    }
}

/**
 * Cierre de sesión seguro
 */
function logout() {
    sessionStorage.removeItem('aluSesion');
    window.sesion = null;
    window.location.href = 'login.html';
}

/**
 * Verificación de permisos y protección de páginas
 */
function verificarProteccion() {
    const path = window.location.pathname;
    const currentSesion = JSON.parse(sessionStorage.getItem('aluSesion')) || null;

    if (!currentSesion && !path.includes('login.html')) {
        window.location.href = 'login.html';
        return;
    }

    if (currentSesion && currentSesion.rol !== 'admin' && path.includes('admin.html')) {
        window.location.href = 'index.html';
        return;
    }
}

window.login = login;
window.logout = logout;
window.verificarProteccion = verificarProteccion;
window.sesion = sesion;

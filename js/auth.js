/**
 * js/auth.js
 * Gestión de autenticación, control de sesión y protección de rutas
 */

window.sesion = JSON.parse(sessionStorage.getItem('aluSesion')) || null;

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
async function logout() {
    if (window.API && typeof window.API.logout === 'function') {
        await window.API.logout();
    }
    sessionStorage.removeItem('aluSesion');
    window.sesion = null;
    window.location.href = 'login.php';
}

/**
 * Verificación de permisos y protección de páginas
 */
function verificarProteccion() {
    const path = window.location.pathname;
    const currentSesion = JSON.parse(sessionStorage.getItem('aluSesion')) || null;

    if (!currentSesion && !path.includes('login.php') && !path.includes('login.html')) {
        window.location.href = 'login.php';
        return;
    }

    if (currentSesion && currentSesion.rol !== 'admin' && (path.includes('admin.php') || path.includes('admin.html'))) {
        window.location.href = 'index.php';
        return;
    }
}

window.login = login;
window.logout = logout;
window.verificarProteccion = verificarProteccion;

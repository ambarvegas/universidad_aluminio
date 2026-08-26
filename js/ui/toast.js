/**
 * js/ui/toast.js
 * Sistema de notificaciones Toast no intrusivas con Bootstrap 5
 */

function createToastContainer() {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
        container.style.zIndex = '9999';
        document.body.appendChild(container);
    }
    return container;
}

function showToast(message, type = 'success', duration = 3000) {
    const toastContainer = createToastContainer();

    const toast = document.createElement('div');
    toast.className = `toast align-items-center text-white bg-${type} border-0 shadow-lg`;
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');
    toast.setAttribute('aria-atomic', 'true');
    toast.style.borderRadius = '0.75rem';

    const iconMap = {
        success: 'bi-check-circle-fill',
        danger: 'bi-x-circle-fill',
        warning: 'bi-exclamation-triangle-fill',
        info: 'bi-info-circle-fill'
    };
    const icon = iconMap[type] || 'bi-bell-fill';

    toast.innerHTML = `
        <div class="d-flex align-items-center">
            <div class="toast-body d-flex align-items-center">
                <i class="bi ${icon} me-2 fs-5"></i>
                <span>${message}</span>
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    `;

    toastContainer.appendChild(toast);
    const bsToast = new bootstrap.Toast(toast, { delay: duration });
    bsToast.show();

    toast.addEventListener('hidden.bs.toast', () => {
        toast.remove();
    });
}

window.showToast = showToast;
window.createToastContainer = createToastContainer;

/**
 * js/ui/modal.js
 * Modal de confirmación estilizado con Bootstrap 5.
 * Reemplaza el confirm() nativo del navegador con una experiencia moderna y asíncrona.
 *
 * USO:
 *   const ok = await showConfirmModal({
 *       title: '¿Eliminar Curso?',
 *       message: 'Esta acción no se puede deshacer.',
 *       confirmText: 'Sí, eliminar',
 *       confirmVariant: 'danger'
 *   });
 *   if (!ok) return;
 */

function showConfirmModal({
    title = '¿Estás seguro?',
    message = 'Esta acción no se puede deshacer.',
    confirmText = 'Confirmar',
    cancelText = 'Cancelar',
    confirmVariant = 'danger',
    icon = 'bi-exclamation-triangle-fill'
} = {}) {
    return new Promise((resolve) => {
        // Remover modal previo si existe
        const existing = document.getElementById('global-confirm-modal');
        if (existing) existing.remove();

        const modalDiv = document.createElement('div');
        modalDiv.id = 'global-confirm-modal';
        modalDiv.className = 'modal fade';
        modalDiv.tabIndex = -1;
        modalDiv.setAttribute('aria-hidden', 'true');

        // Formatear saltos de línea a párrafos si viene texto plano con \n
        const formattedMsg = message.includes('<') 
            ? message 
            : message.split('\n').filter(Boolean).map(p => `<p class="mb-2">${p}</p>`).join('');

        modalDiv.innerHTML = `
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow-lg" style="border-radius: 1rem; overflow: hidden;">
                    <div class="modal-header bg-${confirmVariant === 'danger' ? 'danger text-white' : confirmVariant === 'warning' ? 'warning text-dark' : 'primary text-white'} border-0 py-3">
                        <h5 class="modal-title d-flex align-items-center fw-bold">
                            <i class="bi ${icon} me-2 fs-5"></i>
                            ${title}
                        </h5>
                        <button type="button" class="btn-close ${confirmVariant !== 'warning' ? 'btn-close-white' : ''}" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body py-4 px-4 fs-6 text-secondary">
                        ${formattedMsg}
                    </div>
                    <div class="modal-footer bg-light border-0 py-3 px-4">
                        <button type="button" class="btn btn-secondary px-4 fw-semibold" id="confirm-modal-btn-cancel" data-bs-dismiss="modal">
                            ${cancelText}
                        </button>
                        <button type="button" class="btn btn-${confirmVariant} px-4 fw-semibold shadow-sm" id="confirm-modal-btn-ok">
                            ${confirmText}
                        </button>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(modalDiv);

        const bsModal = new bootstrap.Modal(modalDiv, { backdrop: 'static', keyboard: false });
        let confirmed = false;

        document.getElementById('confirm-modal-btn-ok').addEventListener('click', () => {
            confirmed = true;
            bsModal.hide();
        });

        modalDiv.addEventListener('hidden.bs.modal', () => {
            modalDiv.remove();
            resolve(confirmed);
        });

        bsModal.show();
    });
}

// Exponer globalmente
window.showConfirmModal = showConfirmModal;

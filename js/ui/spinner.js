/**
 * js/ui/spinner.js
 * Control de estados de carga (spinners) en botones y acciones asíncronas
 */

function handleButtonLoading(btn, loading, textLoading = 'Procesando...', textOriginal = null) {
    if (typeof btn === 'string') {
        btn = document.querySelector(btn);
    }
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
        btn.classList.add('opacity-75', 'btn-loading');
    } else {
        btn.disabled = false;
        if (btn.dataset.originalHtml) {
            btn.innerHTML = btn.dataset.originalHtml;
        } else if (textOriginal) {
            btn.innerHTML = textOriginal;
        }
        btn.classList.remove('opacity-75', 'btn-loading');
    }
    return btn;
}

async function withLoading(btn, asyncFn, loadingText = 'Procesando...', onError = null) {
    const btnElement = typeof btn === 'string' ? document.querySelector(btn) : btn;
    if (!btnElement) {
        // Si no hay botón, ejecutar la función directamente
        try {
            await asyncFn();
        } catch (error) {
            console.error('Error en operación:', error);
            if (onError) onError(error);
            else if (typeof showToast === 'function') showToast('Error: ' + error.message, 'danger');
        }
        return;
    }

    if (btnElement.disabled) return;

    try {
        handleButtonLoading(btnElement, true, loadingText);
        await asyncFn();
    } catch (error) {
        console.error('Error en operación:', error);
        if (onError) {
            onError(error);
        } else if (typeof showToast === 'function') {
            showToast('Error: ' + error.message, 'danger');
        }
    } finally {
        handleButtonLoading(btnElement, false);
    }
}

window.handleButtonLoading = handleButtonLoading;
window.withLoading = withLoading;

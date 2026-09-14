<?php
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

if (!function_exists('v_asset')) {
    function v_asset($path) {
        $file = __DIR__ . '/' . ltrim($path, '/');
        $v = file_exists($file) ? filemtime($file) : '1';
        return $path . '?v=' . $v;
    }
}
$tokenParam = htmlspecialchars(trim($_GET['token'] ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restablecer Contraseña — Universidad del Aluminio</title>
    <meta name="theme-color" content="#0f2b48">
    <link rel="icon" type="image/png" href="icon-192.png" id="favicon-link">
    <!-- Google Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= v_asset('style.css') ?>">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #091a2c 0%, #0f2b48 50%, #1e3a8a 100%);
            margin: 0;
            padding: 1.5rem;
            position: relative;
            overflow-x: hidden;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        body::before {
            content: '';
            position: absolute;
            top: -20%;
            right: -10%;
            width: 500px;
            height: 500px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(2, 132, 199, 0.15) 0%, rgba(2, 132, 199, 0) 70%);
            pointer-events: none;
        }

        body::after {
            content: '';
            position: absolute;
            bottom: -20%;
            left: -10%;
            width: 500px;
            height: 500px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(14, 165, 233, 0.12) 0%, rgba(14, 165, 233, 0) 70%);
            pointer-events: none;
        }

        .recovery-card {
            width: 100%;
            max-width: 460px;
            background: #ffffff;
            border-radius: var(--radius-xl, 16px);
            padding: 2.5rem 2rem;
            box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(255, 255, 255, 0.1);
            position: relative;
            z-index: 10;
        }

        .brand-icon-wrapper {
            width: 64px;
            height: 64px;
            border-radius: 18px;
            background: linear-gradient(135deg, #0f2b48, #0284c7);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin: 0 auto 1.25rem;
            box-shadow: 0 10px 20px -5px rgba(15, 43, 72, 0.3);
        }

        .input-group-text {
            background-color: #f8fafc;
            border-color: #e2e8f0;
            color: #64748b;
        }

        .form-control:focus + .input-group-text,
        .input-group-text:focus-within {
            border-color: #0284c7;
        }

        .btn-toggle-pwd {
            border-left: none;
            background-color: #f8fafc;
            color: #64748b;
            cursor: pointer;
            transition: color 0.15s ease;
        }

        .btn-toggle-pwd:hover {
            color: #0f2b48;
        }

        .password-strength-bar {
            height: 4px;
            border-radius: 2px;
            background-color: #e2e8f0;
            transition: width 0.3s ease, background-color 0.3s ease;
        }
    </style>
</head>

<body>
    <div class="recovery-card">
        <!-- Logo Institucional -->
        <div class="text-center mb-4">
            <div class="brand-icon-wrapper">
                <i class="bi bi-shield-lock-fill"></i>
            </div>
            <h3 class="fw-bold text-primary mb-1" style="color: #0f2b48 !important;">Universidad del Aluminio</h3>
            <p class="text-muted small mb-0" id="card-subtitle">Gestión Segura de Contraseña</p>
        </div>

        <!-- Estado 1: Verificando Token (Cargando) -->
        <div id="state-loading" class="text-center py-4">
            <div class="spinner-border text-primary mb-3" style="width: 2.5rem; height: 2.5rem;" role="status">
                <span class="visually-hidden">Verificando...</span>
            </div>
            <p class="text-muted small mb-0">Validando enlace de acceso seguro...</p>
        </div>

        <!-- Estado 2: Error de Token Inválido o Expirado -->
        <div id="state-error" class="text-center py-2" style="display: none;">
            <div class="alert alert-danger py-3 px-3 small text-center mb-4 border-0 shadow-sm" style="background-color: #fef2f2; color: #991b1b; border-left: 4px solid #dc2626 !important;">
                <i class="bi bi-exclamation-triangle-fill fs-4 d-block mb-2 text-danger"></i>
                <div class="fw-bold fs-6 mb-1" id="error-title">Enlace no válido</div>
                <div id="error-message">El enlace para restablecer tu contraseña ha expirado o ya fue utilizado.</div>
            </div>
            <div class="d-grid gap-2">
                <a href="login.php" class="btn btn-primary py-2 fw-bold shadow-sm" style="background-color: #0f2b48; border-color: #0f2b48;">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Ir al Inicio de Sesión
                </a>
            </div>
        </div>

        <!-- Estado 3: Formulario para ingresar nueva clave -->
        <div id="state-form" style="display: none;">
            <div class="alert alert-info py-2 px-3 small mb-3 border-0" style="background-color: #eff6ff; color: #1e40af; border-left: 4px solid #0284c7 !important;">
                <div class="fw-bold" id="user-greeting">Hola, Colaborador</div>
                <div class="text-muted small" id="user-subtext">Cédula: <span id="user-id-chip" class="fw-bold"></span></div>
            </div>

            <form id="form-reset-password">
                <div class="mb-3">
                    <label class="form-label small fw-bold text-secondary">Nueva Contraseña</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-key-fill"></i></span>
                        <input type="password" id="nueva-clave" class="form-control border-end-0" required minlength="4" placeholder="Mínimo 4 caracteres" autocomplete="new-password">
                        <button class="btn btn-outline-secondary btn-toggle-pwd" type="button" onclick="toggleVisibility('nueva-clave', 'icon-pwd-1')" title="Mostrar u ocultar">
                            <i class="bi bi-eye" id="icon-pwd-1"></i>
                        </button>
                    </div>
                    <div class="progress mt-1" style="height: 4px;">
                        <div id="pwd-strength" class="progress-bar bg-danger" style="width: 0%;"></div>
                    </div>
                    <small id="pwd-feedback" class="text-muted" style="font-size: 11px;">Mínimo 4 caracteres.</small>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-bold text-secondary">Confirmar Nueva Contraseña</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-check-circle-fill"></i></span>
                        <input type="password" id="confirmar-clave" class="form-control border-end-0" required minlength="4" placeholder="Repite la contraseña" autocomplete="new-password">
                        <button class="btn btn-outline-secondary btn-toggle-pwd" type="button" onclick="toggleVisibility('confirmar-clave', 'icon-pwd-2')" title="Mostrar u ocultar">
                            <i class="bi bi-eye" id="icon-pwd-2"></i>
                        </button>
                    </div>
                    <small id="pwd-match-feedback" class="text-danger" style="display: none; font-size: 11px;">
                        <i class="bi bi-x-circle me-1"></i> Las contraseñas no coinciden.
                    </small>
                </div>

                <div id="submit-error" class="alert alert-danger py-2 px-3 small text-center mb-3" style="display: none;"></div>

                <button type="submit" id="btn-submit" class="btn btn-primary w-100 py-2 fw-bold shadow-sm" style="background-color: #0f2b48; border-color: #0f2b48;">
                    <i class="bi bi-check-lg me-1"></i> Guardar Nueva Contraseña
                </button>
            </form>
        </div>

        <!-- Estado 4: Éxito -->
        <div id="state-success" class="text-center py-3" style="display: none;">
            <div class="mb-3">
                <i class="bi bi-check-circle-fill text-success" style="font-size: 3.5rem;"></i>
            </div>
            <h4 class="fw-bold text-dark mb-2">¡Contraseña Actualizada!</h4>
            <p class="text-muted small mb-4">Tu contraseña ha sido establecida exitosamente. Ya puedes acceder con tus nuevas credenciales.</p>
            <div class="d-grid gap-2">
                <a href="login.php" class="btn btn-primary py-2 fw-bold shadow-sm" style="background-color: #0f2b48; border-color: #0f2b48;">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Iniciar Sesión Ahora
                </a>
            </div>
        </div>

        <div class="text-center mt-4 pt-3 border-top">
            <a href="login.php" class="text-decoration-none text-muted small">
                <i class="bi bi-arrow-left me-1"></i> Regresar al Inicio de Sesión
            </a>
        </div>
    </div>

    <!-- Scripts Esenciales -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const TOKEN = <?= json_encode($tokenParam) ?>;
        let tokenData = null;

        function toggleVisibility(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (!input || !icon) return;
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        }

        async function validarToken() {
            if (!TOKEN) {
                mostrarError("Enlace Inválido", "No se proporcionó ningún token de seguridad en el enlace.");
                return;
            }

            try {
                const res = await fetch(`api.php?action=verificar_token_acceso&token=${encodeURIComponent(TOKEN)}`);
                const data = await res.json().catch(() => ({}));

                if (res.ok && data.valid) {
                    tokenData = data;
                    mostrarFormulario(data);
                } else {
                    mostrarError("Enlace Expirado o Inválido", data.error || "El enlace no es válido o ha expirado.");
                }
            } catch (e) {
                mostrarError("Error de Conexión", "No se pudo conectar con el servidor. Revisa tu conexión a internet.");
            }
        }

        function mostrarError(titulo, mensaje) {
            document.getElementById('state-loading').style.display = 'none';
            document.getElementById('state-form').style.display = 'none';
            document.getElementById('state-success').style.display = 'none';

            document.getElementById('error-title').innerText = titulo;
            document.getElementById('error-message').innerText = mensaje;
            document.getElementById('state-error').style.display = 'block';
        }

        function mostrarFormulario(data) {
            document.getElementById('state-loading').style.display = 'none';
            document.getElementById('state-error').style.display = 'none';
            document.getElementById('state-success').style.display = 'none';

            const greeting = document.getElementById('user-greeting');
            const userChip = document.getElementById('user-id-chip');
            if (greeting) greeting.innerText = `Hola, ${data.nombre || 'Colaborador'}`;
            if (userChip) userChip.innerText = data.usuario_id || '';

            if (data.tipo === 'invitacion') {
                document.getElementById('card-subtitle').innerText = "Activación de Cuenta y Definición de Contraseña";
            }

            document.getElementById('state-form').style.display = 'block';
            setTimeout(() => document.getElementById('nueva-clave')?.focus(), 200);
        }

        // Evaluar fortaleza de contraseña
        const inputNueva = document.getElementById('nueva-clave');
        const inputConf = document.getElementById('confirmar-clave');
        const barStrength = document.getElementById('pwd-strength');
        const fbStrength = document.getElementById('pwd-feedback');
        const fbMatch = document.getElementById('pwd-match-feedback');

        inputNueva?.addEventListener('input', () => {
            const val = inputNueva.value;
            let score = 0;
            if (val.length >= 4) score += 30;
            if (val.length >= 8) score += 30;
            if (/[0-9]/.test(val) && /[a-zA-Z]/.test(val)) score += 20;
            if (/[^A-Za-z0-9]/.test(val)) score += 20;

            barStrength.style.width = score + '%';
            if (score < 40) {
                barStrength.className = 'progress-bar bg-danger';
                fbStrength.innerText = 'Contraseña corta o básica';
            } else if (score < 80) {
                barStrength.className = 'progress-bar bg-warning';
                fbStrength.innerText = 'Contraseña de seguridad media';
            } else {
                barStrength.className = 'progress-bar bg-success';
                fbStrength.innerText = 'Contraseña segura y robusta';
            }

            if (inputConf.value) checkMatch();
        });

        function checkMatch() {
            if (inputNueva.value !== inputConf.value) {
                fbMatch.style.display = 'block';
                return false;
            } else {
                fbMatch.style.display = 'none';
                return true;
            }
        }

        inputConf?.addEventListener('input', checkMatch);

        // Envío del formulario
        document.getElementById('form-reset-password')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const clave = inputNueva.value.trim();
            const conf = inputConf.value.trim();
            const errEl = document.getElementById('submit-error');
            const btn = document.getElementById('btn-submit');

            errEl.style.display = 'none';

            if (clave !== conf) {
                errEl.innerText = 'Las contraseñas no coinciden. Por favor verifícalas.';
                errEl.style.display = 'block';
                return;
            }

            if (clave.length < 4) {
                errEl.innerText = 'La contraseña debe contener al menos 4 caracteres.';
                errEl.style.display = 'block';
                return;
            }

            btn.disabled = true;
            const origHtml = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Actualizando...';

            try {
                const res = await fetch('api.php?action=ejecutar_recuperacion', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token: TOKEN, clave_nueva: clave })
                });

                const data = await res.json().catch(() => ({}));

                if (res.ok && data.success) {
                    document.getElementById('state-form').style.display = 'none';
                    document.getElementById('state-success').style.display = 'block';
                } else {
                    btn.disabled = false;
                    btn.innerHTML = origHtml;
                    errEl.innerText = data.error || 'Ocurrió un error al actualizar la contraseña.';
                    errEl.style.display = 'block';
                }
            } catch (err) {
                btn.disabled = false;
                btn.innerHTML = origHtml;
                errEl.innerText = 'Error de red al intentar actualizar la contraseña.';
                errEl.style.display = 'block';
            }
        });

        // Iniciar validación
        validarToken();
    </script>
</body>

</html>

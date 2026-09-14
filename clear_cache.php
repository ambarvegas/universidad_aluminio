<?php
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$destino = trim($_GET['to'] ?? 'admin.php');
if (!in_array($destino, ['admin.php', 'index.php', 'login.php'], true)) {
    $destino = 'admin.php';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Actualización Integral del Sistema — Universidad del Aluminio</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #0f2b48 0%, #1a4971 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            color: #fff;
            padding: 20px;
        }
        .reset-card {
            background: rgba(255, 255, 255, 0.96);
            color: #1e293b;
            border-radius: 16px;
            padding: 36px 30px;
            max-width: 480px;
            width: 100%;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.35);
            text-align: center;
        }
        .step-item {
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
            color: #475569;
        }
    </style>
</head>
<body>
    <div class="reset-card">
        <div class="spinner-border text-primary mb-3" style="width: 3.5rem; height: 3.5rem;" role="status" id="spinner">
            <span class="visually-hidden">Procesando...</span>
        </div>
        <h4 class="fw-bold text-primary mb-1">Restablecimiento y Actualización</h4>
        <p class="text-muted small mb-4">Purgando cachés del navegador, desregistrando versiones anteriores y sincronizando la última versión de la Universidad del Aluminio.</p>
        
        <div class="bg-light p-3 rounded text-start mb-4 border">
            <div class="step-item" id="step-sw"><i class="bi bi-arrow-repeat text-primary spinner-border spinner-border-sm" style="width: 14px; height: 14px;"></i> Desregistrando Service Workers antiguos...</div>
            <div class="step-item" id="step-cache"><i class="bi bi-clock text-muted"></i> Purgando almacenamiento de Caché...</div>
            <div class="step-item" id="step-storage"><i class="bi bi-clock text-muted"></i> Limpiando almacenamiento temporal...</div>
            <div class="step-item" id="step-done"><i class="bi bi-clock text-muted"></i> Redirigiendo a la plataforma...</div>
        </div>

        <button type="button" class="btn btn-primary w-100 fw-bold" onclick="ejecutarHardReset()">
            <i class="bi bi-lightning-charge me-1"></i>Forzar Recarga Inmediata
        </button>
    </div>

    <script>
    async function ejecutarHardReset() {
        const destino = <?= json_encode($destino) ?>;
        
        // 1. Service Workers
        try {
            if ('serviceWorker' in navigator) {
                const regs = await navigator.serviceWorker.getRegistrations();
                for (const reg of regs) {
                    await reg.unregister();
                }
            }
            document.getElementById('step-sw').innerHTML = '<i class="bi bi-check-circle-fill text-success"></i> Service Workers desregistrados';
        } catch(e) {
            console.warn(e);
        }

        // 2. Caches
        try {
            if ('caches' in window) {
                const keys = await caches.keys();
                for (const key of keys) {
                    await caches.delete(key);
                }
            }
            document.getElementById('step-cache').innerHTML = '<i class="bi bi-check-circle-fill text-success"></i> Almacenamiento Caché purgado al 100%';
        } catch(e) {
            console.warn(e);
        }

        // 3. Storage
        try {
            sessionStorage.clear();
            localStorage.removeItem('unialuminio_build_ver');
            document.getElementById('step-storage').innerHTML = '<i class="bi bi-check-circle-fill text-success"></i> Almacenamiento temporal limpio';
        } catch(e) {
            console.warn(e);
        }

        // 4. Redirección
        document.getElementById('step-done').innerHTML = '<i class="bi bi-check-circle-fill text-success"></i> ¡Listo! Redirigiendo...';
        setTimeout(() => {
            window.location.href = destino + '?v_reset=' + Date.now();
        }, 500);
    }

    // Ejecución automática al cargar la página
    ejecutarHardReset();
    </script>
</body>
</html>

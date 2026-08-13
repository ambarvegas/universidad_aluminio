const express = require('express');
const fs = require('fs');
const path = require('path');
const cors = require('cors');

const app = express();
const PORT = 3000;
const DB_PATH = path.join(__dirname, 'db.json');
const BAK_PATH = path.join(__dirname, 'db.json.bak');
const TMP_PATH = path.join(__dirname, 'db.json.tmp');
const BACKUPS_DIR = path.join(__dirname, 'backups');

// Middleware
app.use(cors());
app.use(express.json({ limit: '15mb' })); // Soporte para datos pesados (imágenes base64)

const initialStructure = {
    usuarios: [],
    cursos: [],
    carreras: [],
    rolesConfig: [],
    solicitudesRegistro: [],
    solicitudesCursos: [],
    configuracion: { nombreInstitucion: "Universidad del Aluminio", logo: "", minAprobacion: 70 }
};

const validateDBStructure = (data) => {
    if (!data || typeof data !== 'object') return false;
    const requiredKeys = ['usuarios', 'cursos', 'carreras', 'rolesConfig', 'solicitudesRegistro', 'solicitudesCursos'];
    for (const key of requiredKeys) {
        if (!Array.isArray(data[key])) return false;
    }
    return true;
};

// Leer base de datos de manera segura con auto-recuperación
const readDB = () => {
    if (fs.existsSync(DB_PATH)) {
        try {
            const data = fs.readFileSync(DB_PATH, 'utf8');
            const parsed = JSON.parse(data);
            if (validateDBStructure(parsed)) return parsed;
        } catch (error) {
            console.error("Error al leer db.json, intentando restaurar desde backup:", error);
        }
    }

    // Auto-recuperación desde backup
    if (fs.existsSync(BAK_PATH)) {
        try {
            const bakData = fs.readFileSync(BAK_PATH, 'utf8');
            const parsedBak = JSON.parse(bakData);
            if (validateDBStructure(parsedBak)) {
                console.log("Restaurando db.json desde db.json.bak");
                fs.writeFileSync(DB_PATH, bakData, 'utf8');
                return parsedBak;
            }
        } catch (bakError) {
            console.error("Error al leer db.json.bak:", bakError);
        }
    }

    return initialStructure;
};

// Escribir base de datos de forma atómica y con respaldos
const writeDB = (data) => {
    if (!validateDBStructure(data)) {
        throw new Error("Estructura JSON inválida o faltan propiedades requeridas");
    }

    // 1. Crear respaldo si db.json existe y es válido
    if (fs.existsSync(DB_PATH)) {
        try {
            const currentData = fs.readFileSync(DB_PATH, 'utf8');
            if (validateDBStructure(JSON.parse(currentData))) {
                fs.copyFileSync(DB_PATH, BAK_PATH);

                if (!fs.existsSync(BACKUPS_DIR)) {
                    fs.mkdirSync(BACKUPS_DIR, { recursive: true });
                }
                const now = new Date();
                const timestamp = now.toISOString().replace(/[:.]/g, '-');
                fs.copyFileSync(DB_PATH, path.join(BACKUPS_DIR, `db_${timestamp}.json`));
            }
        } catch (e) {
            console.warn("Advertencia creando copia de seguridad:", e.message);
        }
    }

    // 2. Escritura atómica a archivo temporal y renombrado
    const jsonStr = JSON.stringify(data, null, 2);
    fs.writeFileSync(TMP_PATH, jsonStr, 'utf8');
    fs.renameSync(TMP_PATH, DB_PATH);
};

// Endpoints
app.get('/api/db', (req, res) => {
    res.json(readDB());
});

app.post('/api/db', (req, res) => {
    try {
        writeDB(req.body);
        res.json({ message: 'Base de datos guardada correctamente' });
    } catch (error) {
        res.status(400).json({ error: error.message || 'Error al guardar la base de datos' });
    }
});

app.listen(PORT, () => {
    console.log(`Servidor backend corriendo en http://localhost:${PORT}`);
});
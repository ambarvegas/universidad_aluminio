const express = require('express');
const fs = require('fs');
const path = require('path');
const cors = require('cors');

const app = express();
const PORT = 3000;
const DB_PATH = path.join(__dirname, 'db.json');

// Middleware
app.use(cors());
app.use(express.json({ limit: '15mb' })); // Soporte para datos pesados (imágenes base64)

// Leer base de datos
const readDB = () => {
    try {
        const data = fs.readFileSync(DB_PATH, 'utf8');
        return JSON.parse(data);
    } catch (error) {
        console.error("Error leyendo db.json:", error);
        return { usuarios: [], cursos: [], carreras: [], rolesConfig: [], solicitudesRegistro: [], solicitudesCursos: [] };
    }
};

// Escribir base de datos
const writeDB = (data) => {
    fs.writeFileSync(DB_PATH, JSON.stringify(data, null, 2));
};

// Endpoints
app.get('/api/db', (req, res) => {
    res.json(readDB());
});

app.post('/api/db', (req, res) => {
    writeDB(req.body);
    res.json({ message: 'Base de datos guardada correctamente' });
});

app.listen(PORT, () => {
    console.log(`Servidor backend corriendo en http://localhost:${PORT}`);
});
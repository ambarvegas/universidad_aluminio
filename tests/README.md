# Pruebas automáticas

## Ejecutar

1. Enciende **MySQL** en el panel de XAMPP.
2. La primera vez, instala las dependencias de desarrollo: `composer install`
3. Ejecuta todo: `composer test`

También por partes: `composer test:php` (PHP) o `composer test:js` (JavaScript, solo requiere Node).
Para una sola clase o prueba: `vendor/bin/phpunit --filter HabilitacionesTest`.

## En GitHub (antes de publicar)

Cada push ejecuta la suite completa en GitHub Actions (`.github/workflows/pruebas.yml`), con una
base MariaDB desechable. El resultado aparece junto a cada commit en GitHub (✓ verde o ✗ roja).

**Regla para publicar:** hacer "Update from Remote" en cPanel solo cuando el último commit de
`main` tenga ✓. Si aparece ✗, el detalle está en la pestaña *Actions* del repositorio.

## Base de datos de prueba

Las pruebas usan una base **local y desechable** (`universidad_test`), que se crea sola y se
vacía antes de cada prueba. Nunca usan las credenciales de `config.local.php`: aunque exista,
la suite se niega a correr contra un servidor que no sea `127.0.0.1`/`localhost` o contra una
base cuyo nombre no contenga `test`.

Por defecto se conecta como `root` sin contraseña (lo habitual en XAMPP). Para cambiarlo:

```
TEST_DB_HOST=127.0.0.1  TEST_DB_USER=root  TEST_DB_PASS=  TEST_DB_NAME=universidad_test  TEST_DB_PORT=3306
```

## Qué cubre cada carpeta

| Carpeta | Qué prueba |
|---|---|
| `Unidad/` | Funciones puras: códigos de certificado (fijados para no invalidar QR emitidos), filtro de configuración pública, etc. |
| `Integracion/` | Capa de datos (`lib/`) contra la base de prueba: progreso, evaluación, reintentos habilitados, restablecer, lecturas admin/login/campus, carga ligera del panel, reporte de evaluaciones (cálculo, filtros, paginación), importación de respaldos. |
| `Api/` | `api.php` por HTTP con un servidor PHP local: enrutado, permisos por rol, CORS, flujos completos (reprobar → habilitar → aprobar). |
| `js/` | Frontend con el ejecutor de Node, un DOM y una API simulados: estado central `App` (y las variables históricas), progreso bajo demanda, escape de HTML, tablas y paginación del panel, reporte de evaluaciones. |

`support/` contiene la clase base (`BaseDatosTestCase`, con los datos de ejemplo descritos en
`sembrarDatosBase()`), el servidor HTTP de pruebas y la conexión segura a la base de prueba.

## Al agregar funcionalidad

- Nueva función en `lib/` → prueba en `Integracion/` extendiendo `BaseDatosTestCase`.
- Nueva acción en la API → `case` en `endpoints/<área>.php`, línea en `API_RUTAS` y prueba en `Api/`
  (`ApiTest::testCadaRutaTieneSuCase` verifica que la ruta y el `case` coincidan).

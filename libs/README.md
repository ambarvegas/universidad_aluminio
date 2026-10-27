# Librerías de terceros

Todas las librerías y fuentes de la interfaz se sirven desde esta carpeta, en lugar de un CDN. Así la plataforma no depende de servicios externos, el modo sin conexión (Service Worker) funciona completo y los navegadores de los usuarios no consultan servidores de terceros.

| Carpeta | Versión | Origen | Licencia |
|---|---|---|---|
| `bootstrap-5.3.2/` | 5.3.2 | npm `bootstrap` (`dist/css`, `dist/js`) | MIT |
| `bootstrap-icons-1.11.0/` | 1.11.0 | npm `bootstrap-icons` (`font/`) | MIT |
| `jspdf-2.5.1/` | 2.5.1 | npm `jspdf` (`dist/jspdf.umd.min.js`) | MIT |
| `qrious-4.0.2/` | 4.0.2 | npm `qrious` (`dist/qrious.min.js`) | GPL-3.0 |
| `xlsx-0.18.5/` | 0.18.5 | npm `xlsx` (`dist/xlsx.full.min.js`) | Apache-2.0 |
| `fuentes/` | — | Google Fonts: Inter y Plus Jakarta Sans | SIL OFL 1.1 |

Cada paquete de npm se descargó del registro oficial y se verificó contra la firma `sha512` que publica el registro (`dist.integrity`). Los archivos son idénticos byte a byte a los que se usaban antes desde jsDelivr y cdnjs.

## Actualizar una librería

1. Crea una carpeta nueva con la versión en el nombre, por ejemplo `bootstrap-5.3.3/`. No sobrescribas la anterior: como la ruta cambia, los navegadores no pueden quedarse con una copia vieja en caché.
2. Copia los archivos desde el paquete de npm de esa versión, junto con su `LICENSE`.
3. Cambia las rutas en las páginas (`admin.php`, `index.php`, `detalle.php`, `login.php`, `recuperar.php`, `verificar.php`, `clear_cache.php`) y en `STATIC_ASSETS` de `sw.js`. Sube la versión de la caché en `sw.js` y `APP_CLIENT_VERSION` en `script.js`.
4. Prueba las pantallas que usan la librería: Bootstrap en todas, jsPDF y QRious en los certificados, XLSX en las exportaciones del panel. Después borra la carpeta vieja.

`fuentes/fuentes.css` es el CSS que Google Fonts entrega a un navegador moderno, con las URLs cambiadas a los `.woff2` locales.

# Migraciones de la base de datos

Cada cambio en la estructura de la base (tablas, columnas, índices, datos que hay que transformar) es un archivo numerado en esta carpeta. Se aplica **una sola vez**, en orden, y queda registrado en la tabla `schema_migraciones`.

## Cómo se aplican

- **En producción, solas.** Después de «Update from Remote» en cPanel, la primera petición a `api.php` aplica las pendientes. Un bloqueo (`GET_LOCK`) evita que dos peticiones las apliquen a la vez.
- **Si una falla,** se detiene ahí. Esa migración y las siguientes quedan pendientes, el error va al log del servidor con el prefijo `[migraciones]` y se reintenta en la siguiente petición. El panel lo avisa en *Diagnóstico del sistema* («Esquema: N pendiente(s)»).
- **A mano (local o con terminal):** `php tools/migrar.php`, o `php tools/migrar.php --estado` para solo consultar.
- **Pruebas:** la base de prueba se crea aplicando esta carpeta completa, así que una migración rota hace fallar las pruebas en GitHub antes de publicar.

## Cómo agregar una

1. Usa el siguiente número libre: `0002_agregar_columna_x.sql`. Usa cuatro dígitos, guion bajo y una descripción corta. Nunca reutilices ni renumeres un archivo ya publicado.
2. Elige el formato:
   - **`.sql`**: sentencias SQL. Cada una termina en `;` al final de la línea, y los comentarios van en líneas propias con `--`.
   - **`.php`**: para cambios condicionales o transformaciones de datos. El archivo devuelve una función:
     ```php
     <?php
     return function (mysqli $conn): void {
         $col = $conn->query("SHOW COLUMNS FROM `cursos` LIKE 'nueva'");
         if ($col->num_rows === 0) {
             $conn->query("ALTER TABLE `cursos` ADD COLUMN `nueva` INT NOT NULL DEFAULT 0");
         }
     };
     ```
3. Si **borra** tablas, columnas o datos, escribe `@destructiva` en la cabecera, por ejemplo `-- @destructiva: elimina usuario_progreso`. Antes de aplicarla se genera un respaldo completo (base de datos y `uploads/`) en `backups/`. Si el respaldo falla, la migración no se aplica.
4. Ejecuta `composer test` y publica cuando GitHub muestre ✓.

Las sentencias DDL (`CREATE`, `ALTER`, `DROP`) hacen *commit* implícito en MySQL, así que una migración `.sql` con varias sentencias no es atómica. Si falla a mitad, el error indica qué sentencia falló. Conviene escribir sentencias que se puedan repetir sin daño (`IF NOT EXISTS`, `IF EXISTS`) o dividir el cambio en varias migraciones pequeñas.

Los scripts de `tools/migrations/` son de la migración original desde `db.json` y de la normalización de 2026. Son históricos y no forman parte de este sistema.

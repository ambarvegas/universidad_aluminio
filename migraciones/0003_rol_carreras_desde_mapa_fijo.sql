-- 0003 — Pasa a datos el mapa fijo rol → carrera que vivía en el código (getCareerIdFromRole
-- en js/core/state.js y otro igual en js/features/solicitudes.js).
-- Cada par se agrega a rol_carreras solo si el rol y la carrera existen; si ya estaba, no cambia
-- nada. A partir de aquí la relación se administra únicamente desde el panel (Roles).
INSERT IGNORE INTO `rol_carreras` (`rol_id`, `carrera_id`)
SELECT r.`id`, c.`id`
FROM (
    SELECT 'asesor_ventas' AS rol, 'CAR-ASESOR-VENTAS' AS carrera
    UNION ALL SELECT 'retail',             'CAR-RETAIL'
    UNION ALL SELECT 'almacenista_retail', 'CAR-ALMACENISTA'
    UNION ALL SELECT 'coordinador_retail', 'CAR-COORD-RETAIL'
    UNION ALL SELECT 'cristalero',         'CAR-CRISTALERO'
) AS mapa
JOIN `roles_config` r ON r.`id` = mapa.rol
JOIN `carreras` c ON c.`id` = mapa.carrera;

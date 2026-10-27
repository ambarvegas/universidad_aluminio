-- 0004 — Retroalimentación opcional por pregunta de evaluación: un mensaje para cuando el
-- participante acierta y otro para cuando falla. Solo se envían al navegador después de
-- calificar (db_evaluar_modulo), nunca junto con la evaluación.
ALTER TABLE `curso_preguntas`
    ADD COLUMN IF NOT EXISTS `retro_correcta`   TEXT DEFAULT NULL AFTER `correcta`,
    ADD COLUMN IF NOT EXISTS `retro_incorrecta` TEXT DEFAULT NULL AFTER `retro_correcta`;

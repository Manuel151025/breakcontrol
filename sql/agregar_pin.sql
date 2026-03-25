-- BreakControl: Agregar columna PIN de recuperación
-- Ejecutar en phpMyAdmin o MySQL CLI

ALTER TABLE `usuario`
  ADD COLUMN `pin_recuperacion` VARCHAR(255) NULL DEFAULT NULL
  COMMENT 'PIN hasheado para recuperar contraseña'
  AFTER `contrasena_hash`;

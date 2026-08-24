-- RS Connect 36.6.7 — Severidade operacional e diagnóstico da IA
-- Permite registrar explicitamente ausência de evidência sem transformá-la em alerta.

ALTER TABLE system_health_checks
    MODIFY COLUMN status ENUM('ok','warning','down','unknown') NOT NULL DEFAULT 'warning';

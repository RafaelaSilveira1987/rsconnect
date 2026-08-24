-- RS Connect Hotfix 18.3 — Pré-agendamento: captura confiável de preferência e limpeza visual
-- Execute após aplicar o Hotfix 18.3.

-- Se algum pré-agendamento já foi confirmado em versões anteriores, ele deixa de ser exibido como pré-agendamento.
UPDATE calendar_appointments
SET is_pre_schedule = 0,
    pre_schedule_source = COALESCE(pre_schedule_source, 'converted'),
    title = CASE
        WHEN title LIKE 'Pré-agendamento - %' THEN REPLACE(title, 'Pré-agendamento - ', 'Agendamento - ')
        ELSE title
    END,
    updated_at = CURRENT_TIMESTAMP
WHERE is_pre_schedule = 1
  AND status = 'confirmed';

-- Se existir pré-agendamento pendente sem dia/horário, ele fica explicitamente pendente.
UPDATE calendar_appointments
SET status = 'pre_scheduled',
    approval_status = 'pending',
    updated_at = CURRENT_TIMESTAMP
WHERE is_pre_schedule = 1
  AND status IN ('awaiting_approval', 'rescheduled')
  AND (
      preferred_day_text IS NULL OR preferred_day_text = ''
      OR preferred_time_text IS NULL OR preferred_time_text = ''
  );

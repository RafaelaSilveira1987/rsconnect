-- RS Connect Hotfix 18.2 — Pré-agendamento: preferência obrigatória e conversão para agendamento
-- Execute após aplicar o Hotfix 18.2.

-- Compromissos pré-agendados já aprovados em versões anteriores deixam de aparecer como pré-agendamento.
-- Observação: esta correção não tenta adivinhar dia/horário de registros antigos sem preferência.
UPDATE calendar_appointments
SET is_pre_schedule = 0,
    pre_schedule_source = COALESCE(pre_schedule_source, 'converted'),
    updated_at = CURRENT_TIMESTAMP
WHERE is_pre_schedule = 1
  AND status = 'confirmed'
  AND (approval_status = 'approved' OR approval_status IS NULL);

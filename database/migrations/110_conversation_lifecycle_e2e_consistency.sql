-- RS Connect 36.30.8 — consistência do ciclo central de atendimento
-- Normaliza dados legados sem apagar histórico de conversas, CRM, agenda ou contatos.

-- Conversa encerrada nunca deve permanecer como item ativo de fila, nem carregar
-- não lidas/responsável após o fechamento do ciclo.
UPDATE conversations
SET assigned_user_id = NULL,
    assigned_at = NULL,
    assignment_source = 'released',
    assignment_released_at = COALESCE(assignment_released_at, UTC_TIMESTAMP()),
    attendance_mode = 'paused',
    operational_status = 'resolved',
    unread_count = 0
WHERE status = 'closed'
  AND (
      assigned_user_id IS NOT NULL
      OR assigned_at IS NOT NULL
      OR COALESCE(assignment_source, '') <> 'released'
      OR attendance_mode <> 'paused'
      OR operational_status <> 'resolved'
      OR unread_count <> 0
  );

-- Uma resposta pós-horário pertencente a atendimento já encerrado não pode ser
-- retomada posteriormente e responder uma mensagem de um ciclo antigo.
UPDATE ai_after_hours_pending p
INNER JOIN conversations c
        ON c.id = p.conversation_id
       AND c.tenant_id = p.tenant_id
SET p.status = 'cancelled',
    p.next_attempt_at = NULL,
    p.recovery_source = 'closed_cycle_cleanup',
    p.last_error = NULL
WHERE c.status = 'closed'
  AND p.status IN ('pending', 'processing', 'blocked_plan', 'blocked_human', 'error');

-- Diagnóstico de clientes com agendamentos ativos sobrepostos.
-- Apenas consulta; não altera dados.

SELECT
    a1.tenant_id,
    a1.contact_id,
    ct.name AS cliente,
    a1.id AS agendamento_1_id,
    a1.title AS agendamento_1,
    a1.starts_at AS inicio_1,
    a1.ends_at AS fim_1,
    u1.name AS profissional_1,
    a2.id AS agendamento_2_id,
    a2.title AS agendamento_2,
    a2.starts_at AS inicio_2,
    a2.ends_at AS fim_2,
    u2.name AS profissional_2
FROM calendar_appointments a1
INNER JOIN calendar_appointments a2
    ON a2.tenant_id = a1.tenant_id
   AND a2.contact_id = a1.contact_id
   AND a2.id > a1.id
   AND a1.starts_at < a2.ends_at
   AND a1.ends_at > a2.starts_at
LEFT JOIN contacts ct ON ct.id = a1.contact_id AND ct.tenant_id = a1.tenant_id
LEFT JOIN users u1 ON u1.id = a1.owner_user_id AND u1.tenant_id = a1.tenant_id
LEFT JOIN users u2 ON u2.id = a2.owner_user_id AND u2.tenant_id = a2.tenant_id
WHERE a1.contact_id IS NOT NULL
  AND (
        a1.status IN ('scheduled', 'confirmed')
        OR (
            a1.status IN ('pre_scheduled', 'awaiting_approval')
            AND (
                COALESCE(a1.pre_schedule_source, '') = 'manual'
                OR (
                    COALESCE(a1.preferred_day_text, '') <> ''
                    AND COALESCE(a1.preferred_time_text, '') <> ''
                )
                OR COALESCE(a1.chosen_availability_slot_id, 0) > 0
                OR COALESCE(a1.availability_status, '') IN ('slot_selected', 'validated')
            )
        )
      )
  AND (
        a2.status IN ('scheduled', 'confirmed')
        OR (
            a2.status IN ('pre_scheduled', 'awaiting_approval')
            AND (
                COALESCE(a2.pre_schedule_source, '') = 'manual'
                OR (
                    COALESCE(a2.preferred_day_text, '') <> ''
                    AND COALESCE(a2.preferred_time_text, '') <> ''
                )
                OR COALESCE(a2.chosen_availability_slot_id, 0) > 0
                OR COALESCE(a2.availability_status, '') IN ('slot_selected', 'validated')
            )
        )
      )
ORDER BY a1.tenant_id, cliente, a1.starts_at;

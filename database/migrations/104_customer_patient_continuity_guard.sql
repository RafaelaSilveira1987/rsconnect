-- RS Connect 36.28.2 — garante continuidade de clientes/pacientes já conhecidos.
-- Idempotente: pode ser executada novamente sem criar duplicidades.

-- Regra antiga não pode obrigar cliente/paciente atual a repetir demanda antes da agenda.
UPDATE ai_agent_group_rules
SET require_demand_before_pre_schedule = 0,
    updated_at = CURRENT_TIMESTAMP
WHERE contact_group IN ('customer', 'patient')
  AND require_demand_before_pre_schedule <> 0;

-- Estados antigos ou recriados pela configuração anterior deixam de travar agenda por demanda.
UPDATE conversation_flow_states fs
INNER JOIN contacts ct
        ON ct.id = fs.contact_id
       AND ct.tenant_id = fs.tenant_id
SET fs.demand_summary = CASE
        WHEN fs.demand_status = 'pending'
             AND (fs.demand_summary IS NULL OR fs.demand_summary = '')
            THEN 'Contato já conhecido; nova triagem de demanda não é necessária para continuidade do atendimento.'
        ELSE fs.demand_summary
    END,
    fs.stage = CASE
        WHEN fs.demand_status = 'pending'
             AND fs.stage IN ('identifying_contact', 'understanding_demand', 'collecting_demand')
            THEN 'ready_for_scheduling'
        ELSE fs.stage
    END,
    fs.demand_status = CASE
        WHEN fs.demand_status = 'pending' THEN 'not_required'
        ELSE fs.demand_status
    END,
    fs.updated_at = CURRENT_TIMESTAMP
WHERE (ct.status = 'customer' OR ct.contact_group IN ('customer', 'patient'))
  AND fs.demand_status = 'pending';

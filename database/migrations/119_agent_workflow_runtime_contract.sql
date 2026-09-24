-- RS Connect 36.36.20
-- Contrato de execução do workflow: o vínculo entre etapa e informação passa a morar
-- no banco/configuração, não em mapas de negócio hardcoded no PHP.

UPDATE tenant_agent_workflow_steps
SET config_json = JSON_SET(COALESCE(config_json, JSON_OBJECT()), '$.field_keys', JSON_ARRAY('requester_name'))
WHERE step_key = 'identify_intent';

UPDATE tenant_agent_workflow_steps
SET config_json = JSON_SET(COALESCE(config_json, JSON_OBJECT()), '$.field_keys', JSON_ARRAY('is_for_self', 'patient_name'))
WHERE step_key = 'identify_subject';

UPDATE tenant_agent_workflow_steps
SET config_json = JSON_SET(COALESCE(config_json, JSON_OBJECT()), '$.field_keys', JSON_ARRAY('patient_age'))
WHERE step_key = 'collect_age';

UPDATE tenant_agent_workflow_steps
SET config_json = JSON_SET(COALESCE(config_json, JSON_OBJECT()), '$.field_keys', JSON_ARRAY('modality'))
WHERE step_key = 'collect_modality';

UPDATE tenant_agent_workflow_steps
SET config_json = JSON_SET(COALESCE(config_json, JSON_OBJECT()), '$.field_keys', JSON_ARRAY('brief_demand'))
WHERE step_key = 'collect_demand';

UPDATE tenant_agent_workflow_steps
SET config_json = JSON_SET(COALESCE(config_json, JSON_OBJECT()), '$.field_keys', JSON_ARRAY('preferred_schedule'))
WHERE step_key = 'collect_schedule';

UPDATE tenant_agent_workflow_steps
SET config_json = JSON_SET(COALESCE(config_json, JSON_OBJECT()), '$.field_keys', JSON_ARRAY('contact_source'))
WHERE step_key = 'collect_source';

UPDATE tenant_agent_workflow_steps
SET config_json = JSON_SET(COALESCE(config_json, JSON_OBJECT()), '$.field_keys', JSON_ARRAY('service'))
WHERE step_key = 'collect_service';

UPDATE tenant_agent_workflow_steps
SET config_json = JSON_SET(COALESCE(config_json, JSON_OBJECT()), '$.field_keys', JSON_ARRAY('professional'))
WHERE step_key = 'collect_professional';

UPDATE tenant_agent_workflow_steps
SET config_json = JSON_SET(COALESCE(config_json, JSON_OBJECT()), '$.field_keys', JSON_ARRAY('requester_name', 'is_for_self', 'modality', 'preferred_schedule'))
WHERE step_key = 'triage';

UPDATE tenant_agent_workflow_steps
SET config_json = JSON_SET(COALESCE(config_json, JSON_OBJECT()), '$.field_keys', JSON_ARRAY('requester_name', 'service', 'preferred_schedule'))
WHERE step_key = 'qualify';

UPDATE tenant_agent_workflow_steps
SET config_json = JSON_SET(COALESCE(config_json, JSON_OBJECT()), '$.policy_key', 'eligibility')
WHERE step_key = 'eligibility';

UPDATE tenant_agent_workflow_steps
SET config_json = JSON_SET(COALESCE(config_json, JSON_OBJECT()), '$.action_key', 'calendar.pre_schedule')
WHERE step_key = 'calendar';

UPDATE tenant_agent_workflow_steps
SET config_json = JSON_SET(COALESCE(config_json, JSON_OBJECT()), '$.handoff_key', 'human_approval')
WHERE step_key = 'human_approval';

-- Atualiza os modelos-base para que reaplicações futuras preservem o mesmo contrato.
UPDATE agent_blueprint_versions v
JOIN agent_blueprints b ON b.id = v.blueprint_id
SET v.config_json = JSON_SET(
    v.config_json,
    '$.workflow[0].config', JSON_OBJECT('field_keys', JSON_ARRAY('requester_name')),
    '$.workflow[1].config', JSON_OBJECT('field_keys', JSON_ARRAY('is_for_self','patient_name')),
    '$.workflow[2].config', JSON_OBJECT('field_keys', JSON_ARRAY('patient_age')),
    '$.workflow[3].config', JSON_OBJECT('policy_key','eligibility'),
    '$.workflow[4].config', JSON_OBJECT('field_keys', JSON_ARRAY('modality')),
    '$.workflow[5].config', JSON_OBJECT('field_keys', JSON_ARRAY('brief_demand')),
    '$.workflow[6].config', JSON_OBJECT('field_keys', JSON_ARRAY('preferred_schedule')),
    '$.workflow[7].config', JSON_OBJECT('action_key','calendar.pre_schedule'),
    '$.workflow[8].config', JSON_OBJECT('handoff_key','human_approval')
)
WHERE b.code = 'psychology-intake-v1';

UPDATE agent_blueprint_versions v
JOIN agent_blueprints b ON b.id = v.blueprint_id
SET v.config_json = JSON_SET(
    v.config_json,
    '$.workflow[0].config', JSON_OBJECT('field_keys', JSON_ARRAY('requester_name')),
    '$.workflow[1].config', JSON_OBJECT('field_keys', JSON_ARRAY('service')),
    '$.workflow[2].config', JSON_OBJECT('field_keys', JSON_ARRAY('professional')),
    '$.workflow[3].config', JSON_OBJECT('field_keys', JSON_ARRAY('preferred_schedule')),
    '$.workflow[4].config', JSON_OBJECT('action_key','calendar.pre_schedule')
)
WHERE b.code = 'beauty-scheduling-v1';

UPDATE agent_blueprint_versions v
JOIN agent_blueprints b ON b.id = v.blueprint_id
SET v.config_json = JSON_SET(
    v.config_json,
    '$.workflow[0].config', JSON_OBJECT('field_keys', JSON_ARRAY('requester_name','is_for_self','modality','preferred_schedule')),
    '$.workflow[1].config', JSON_OBJECT('action_key','calendar.pre_schedule'),
    '$.workflow[2].config', JSON_OBJECT('handoff_key','human_approval')
)
WHERE b.code = 'health-clinic-intake-v1';

UPDATE agent_blueprint_versions v
JOIN agent_blueprints b ON b.id = v.blueprint_id
SET v.config_json = JSON_SET(
    v.config_json,
    '$.workflow[0].config', JSON_OBJECT('field_keys', JSON_ARRAY('requester_name','service','preferred_schedule')),
    '$.workflow[1].config', JSON_OBJECT('action_key','calendar.pre_schedule')
)
WHERE b.code = 'services-scheduling-v1';

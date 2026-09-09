-- RS Connect 36.28.0 — Arquitetura de agentes por nicho, blueprints, triagem e Policy Engine
-- Execute após a migration 102.

SET NAMES utf8mb4;

DELIMITER $$
DROP PROCEDURE IF EXISTS rs_add_column_if_missing$$
CREATE PROCEDURE rs_add_column_if_missing(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table
    ) AND NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS business_niches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(80) NOT NULL,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(500) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    position SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_business_niches_code (code),
    INDEX idx_business_niches_active (active, position, name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS agent_blueprints (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    niche_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(100) NOT NULL,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(700) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_agent_blueprints_niche FOREIGN KEY (niche_id) REFERENCES business_niches(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_agent_blueprints_code (code),
    INDEX idx_agent_blueprints_niche (niche_id, active, name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS agent_blueprint_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    blueprint_id BIGINT UNSIGNED NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    version_label VARCHAR(80) NULL,
    config_json JSON NOT NULL,
    prompt_guidance TEXT NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_agent_blueprint_versions_blueprint FOREIGN KEY (blueprint_id) REFERENCES agent_blueprints(id) ON DELETE CASCADE,
    UNIQUE KEY uq_agent_blueprint_version (blueprint_id, version_no),
    INDEX idx_agent_blueprint_current (blueprint_id, is_current, version_no)
) ENGINE=InnoDB;

CALL rs_add_column_if_missing(
    'tenants',
    'business_niche_id',
    'BIGINT UNSIGNED NULL AFTER segment'
);

CALL rs_add_column_if_missing(
    'tenants',
    'agent_blueprint_id',
    'BIGINT UNSIGNED NULL AFTER business_niche_id'
);

CALL rs_add_column_if_missing(
    'tenants',
    'agent_blueprint_version_id',
    'BIGINT UNSIGNED NULL AFTER agent_blueprint_id'
);

CREATE TABLE IF NOT EXISTS tenant_agent_profiles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    niche_id BIGINT UNSIGNED NULL,
    blueprint_id BIGINT UNSIGNED NULL,
    blueprint_version_id BIGINT UNSIGNED NULL,
    interaction_mode ENUM('hybrid','form','prompt') NOT NULL DEFAULT 'hybrid',
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    config_json JSON NULL,
    customized TINYINT(1) NOT NULL DEFAULT 0,
    applied_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_tenant_agent_profiles_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_tenant_agent_profiles_niche FOREIGN KEY (niche_id) REFERENCES business_niches(id) ON DELETE SET NULL,
    CONSTRAINT fk_tenant_agent_profiles_blueprint FOREIGN KEY (blueprint_id) REFERENCES agent_blueprints(id) ON DELETE SET NULL,
    CONSTRAINT fk_tenant_agent_profiles_version FOREIGN KEY (blueprint_version_id) REFERENCES agent_blueprint_versions(id) ON DELETE SET NULL,
    UNIQUE KEY uq_tenant_agent_profiles_tenant (tenant_id),
    INDEX idx_tenant_agent_profiles_blueprint (blueprint_id, blueprint_version_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tenant_agent_capabilities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    agent_id BIGINT UNSIGNED NULL,
    capability_key VARCHAR(120) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    config_json JSON NULL,
    source ENUM('blueprint','tenant','agent') NOT NULL DEFAULT 'blueprint',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_tenant_agent_capabilities_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_tenant_agent_capabilities_agent FOREIGN KEY (agent_id) REFERENCES ai_agents(id) ON DELETE CASCADE,
    INDEX idx_tenant_agent_capabilities_lookup (tenant_id, capability_key, enabled),
    INDEX idx_tenant_agent_capabilities_agent (tenant_id, agent_id, capability_key)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tenant_triage_fields (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    field_key VARCHAR(100) NOT NULL,
    label VARCHAR(160) NOT NULL,
    field_type ENUM('text','number','boolean','select','date','time','textarea') NOT NULL DEFAULT 'text',
    prompt_text VARCHAR(1000) NULL,
    options_json JSON NULL,
    required_before_schedule TINYINT(1) NOT NULL DEFAULT 0,
    required_for_completion TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    position SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    source ENUM('blueprint','tenant') NOT NULL DEFAULT 'blueprint',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_tenant_triage_fields_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uq_tenant_triage_field (tenant_id, field_key),
    INDEX idx_tenant_triage_fields_order (tenant_id, active, position)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tenant_agent_policies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    policy_key VARCHAR(120) NOT NULL,
    policy_type ENUM('boolean','number','string','json') NOT NULL DEFAULT 'string',
    value_json JSON NULL,
    action_key VARCHAR(120) NOT NULL DEFAULT 'block',
    customer_message VARCHAR(1200) NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    source ENUM('blueprint','tenant') NOT NULL DEFAULT 'blueprint',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_tenant_agent_policies_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uq_tenant_agent_policy (tenant_id, policy_key),
    INDEX idx_tenant_agent_policies_order (tenant_id, enabled, priority)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tenant_agent_workflow_steps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    step_key VARCHAR(120) NOT NULL,
    label VARCHAR(180) NOT NULL,
    step_type ENUM('collect','policy','action','handoff','complete') NOT NULL DEFAULT 'collect',
    config_json JSON NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    position SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    source ENUM('blueprint','tenant') NOT NULL DEFAULT 'blueprint',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_tenant_agent_workflow_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uq_tenant_agent_workflow_step (tenant_id, step_key),
    INDEX idx_tenant_agent_workflow_order (tenant_id, active, position)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS conversation_triage_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    status ENUM('collecting','ready','blocked','completed','handoff') NOT NULL DEFAULT 'collecting',
    eligibility_status ENUM('unknown','pending','eligible','blocked','not_required') NOT NULL DEFAULT 'unknown',
    block_reason VARCHAR(120) NULL,
    current_field_key VARCHAR(100) NULL,
    collected_json JSON NULL,
    missing_json JSON NULL,
    last_intent VARCHAR(80) NULL,
    last_evaluated_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_conversation_triage_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_triage_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_triage_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    UNIQUE KEY uq_conversation_triage_session (tenant_id, conversation_id),
    INDEX idx_conversation_triage_status (tenant_id, status, eligibility_status, updated_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS conversation_policy_decisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NULL,
    policy_key VARCHAR(120) NOT NULL,
    decision ENUM('allow','block','collect','handoff','warn') NOT NULL,
    reason_code VARCHAR(120) NULL,
    evidence_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_conversation_policy_decision_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_policy_decision_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_policy_decision_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
    INDEX idx_conversation_policy_decisions_lookup (tenant_id, conversation_id, created_at),
    INDEX idx_conversation_policy_decisions_policy (tenant_id, policy_key, decision, created_at)
) ENGINE=InnoDB;

INSERT INTO business_niches (code, name, description, position) VALUES
('psychology', 'Psicologia', 'Consultórios, clínicas e profissionais de psicologia.', 10),
('beauty', 'Barbearia / Salão', 'Barbearias, salões, estética e serviços com agenda por procedimento.', 20),
('health_clinic', 'Clínica / Consultório', 'Clínicas e consultórios de saúde com triagem administrativa.', 30),
('services', 'Serviços em geral', 'Prestadores de serviços que precisam qualificar e agendar.', 40)
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), position = VALUES(position), active = 1;

INSERT INTO agent_blueprints (niche_id, code, name, description)
SELECT id, 'psychology-intake-v1', 'Psicologia — Triagem e aprovação humana', 'Triagem estruturada, elegibilidade, agenda protegida e confirmação humana.'
FROM business_niches WHERE code = 'psychology'
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), active = 1;

INSERT INTO agent_blueprints (niche_id, code, name, description)
SELECT id, 'beauty-scheduling-v1', 'Barbearia / Salão — Serviço e agenda', 'Coleta serviço/profissional, valida disponibilidade e permite confirmação automática configurável.'
FROM business_niches WHERE code = 'beauty'
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), active = 1;

INSERT INTO agent_blueprints (niche_id, code, name, description)
SELECT id, 'health-clinic-intake-v1', 'Clínica / Consultório — Triagem administrativa', 'Coleta dados administrativos e protege a agenda com regras configuráveis.'
FROM business_niches WHERE code = 'health_clinic'
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), active = 1;

INSERT INTO agent_blueprints (niche_id, code, name, description)
SELECT id, 'services-scheduling-v1', 'Serviços — Qualificação e agenda', 'Qualificação simples, coleta do serviço e agendamento protegido.'
FROM business_niches WHERE code = 'services'
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), active = 1;

-- Psicologia: regras críticas são estruturadas e não ficam dependentes do prompt livre.
INSERT INTO agent_blueprint_versions (blueprint_id, version_no, version_label, config_json, prompt_guidance, is_current)
SELECT b.id, 1, '1.0', JSON_OBJECT(
    'interaction_mode', 'hybrid',
    'capabilities', JSON_OBJECT(
        'triage.enabled', true,
        'eligibility.enabled', true,
        'calendar.read', true,
        'calendar.pre_schedule', true,
        'calendar.confirm', false,
        'calendar.human_approval', true,
        'handoff.audio', true,
        'policy.fail_closed', true
    ),
    'triage_fields', JSON_ARRAY(
        JSON_OBJECT('key','requester_name','label','Nome de quem entrou em contato','type','text','prompt','Antes de continuarmos, como você se chama?','required_before_schedule',true,'required_for_completion',true,'position',10),
        JSON_OBJECT('key','is_for_self','label','Atendimento é para a própria pessoa?','type','boolean','prompt','O atendimento seria para você mesmo?','required_before_schedule',true,'required_for_completion',true,'position',20),
        JSON_OBJECT('key','patient_name','label','Nome da pessoa que será atendida','type','text','prompt','Qual é o nome da pessoa que será atendida?','required_before_schedule',false,'required_for_completion',true,'position',30),
        JSON_OBJECT('key','patient_age','label','Idade da pessoa que será atendida','type','number','prompt','Qual é a idade da pessoa que será atendida?','required_before_schedule',true,'required_for_completion',true,'position',40),
        JSON_OBJECT('key','modality','label','Modalidade','type','select','prompt','Você prefere atendimento online ou presencial?','options',JSON_ARRAY('online','presencial'),'required_before_schedule',true,'required_for_completion',true,'position',50),
        JSON_OBJECT('key','brief_demand','label','Motivo resumido do contato','type','textarea','prompt','Tem acontecido alguma coisa que fez você perceber que seria importante iniciar a psicoterapia?','required_before_schedule',false,'required_for_completion',true,'position',60),
        JSON_OBJECT('key','preferred_schedule','label','Preferência de dia/horário','type','text','prompt','Qual dia e período ou horário ficam melhores para você?','required_before_schedule',false,'required_for_completion',true,'position',70),
        JSON_OBJECT('key','contact_source','label','Origem do contato','type','text','prompt','Só para registrarmos, como você conheceu o trabalho do consultório?','required_before_schedule',false,'required_for_completion',false,'position',80)
    ),
    'policies', JSON_ARRAY(
        JSON_OBJECT('key','minimum_age','type','number','value',14,'action','block_schedule','message','No momento, este profissional não realiza atendimento para pessoas menores de {{minimum_age}} anos. Se desejar, posso registrar o pedido para indicação de profissionais que atendem esse público.','priority',10),
        JSON_OBJECT('key','couple_service_allowed','type','boolean','value',false,'action','block_schedule','message','No momento, não há atendimento de casal disponível. Posso registrar o contato para lista de espera ou indicação, conforme a orientação da equipe.','priority',20),
        JSON_OBJECT('key','scheduling_requires_eligibility','type','boolean','value',true,'action','block_schedule','message','Antes de consultar a agenda, preciso concluir algumas informações de triagem.','priority',30),
        JSON_OBJECT('key','confirmation_requires_human','type','boolean','value',true,'action','human_approval','message','Registrei a preferência. A confirmação depende da equipe responsável.','priority',40)
    ),
    'workflow', JSON_ARRAY(
        JSON_OBJECT('key','identify_intent','label','Identificar intenção','type','collect','position',10),
        JSON_OBJECT('key','identify_subject','label','Identificar quem será atendido','type','collect','position',20),
        JSON_OBJECT('key','collect_age','label','Coletar idade','type','collect','position',30),
        JSON_OBJECT('key','eligibility','label','Validar elegibilidade','type','policy','position',40),
        JSON_OBJECT('key','collect_modality','label','Coletar modalidade','type','collect','position',50),
        JSON_OBJECT('key','collect_demand','label','Coletar demanda resumida','type','collect','position',60),
        JSON_OBJECT('key','collect_schedule','label','Coletar preferência de agenda','type','collect','position',70),
        JSON_OBJECT('key','calendar','label','Consultar agenda','type','action','position',80),
        JSON_OBJECT('key','human_approval','label','Aguardar aprovação humana','type','handoff','position',90)
    )
),
'Use o Prompt Studio para tom, identidade, acolhimento e forma de perguntar. Regras de idade, elegibilidade, confirmação e acesso à agenda são controladas pelo RS Connect e não podem ser alteradas pelo texto livre.', 1
FROM agent_blueprints b WHERE b.code = 'psychology-intake-v1'
ON DUPLICATE KEY UPDATE config_json = VALUES(config_json), prompt_guidance = VALUES(prompt_guidance), is_current = 1;

-- Barbearia / salão: fluxo orientado a serviço, profissional e slot.
INSERT INTO agent_blueprint_versions (blueprint_id, version_no, version_label, config_json, prompt_guidance, is_current)
SELECT b.id, 1, '1.0', JSON_OBJECT(
    'interaction_mode', 'hybrid',
    'capabilities', JSON_OBJECT(
        'triage.enabled', true,
        'eligibility.enabled', false,
        'calendar.read', true,
        'calendar.pre_schedule', true,
        'calendar.confirm', true,
        'calendar.human_approval', false,
        'policy.fail_closed', true
    ),
    'triage_fields', JSON_ARRAY(
        JSON_OBJECT('key','requester_name','label','Nome do cliente','type','text','prompt','Como você se chama?','required_before_schedule',true,'required_for_completion',true,'position',10),
        JSON_OBJECT('key','service','label','Serviço desejado','type','text','prompt','Qual serviço você gostaria de agendar?','required_before_schedule',true,'required_for_completion',true,'position',20),
        JSON_OBJECT('key','professional','label','Profissional desejado','type','text','prompt','Tem preferência por algum profissional?','required_before_schedule',false,'required_for_completion',false,'position',30),
        JSON_OBJECT('key','preferred_schedule','label','Dia e horário','type','text','prompt','Qual o melhor dia e horário para você?','required_before_schedule',true,'required_for_completion',true,'position',40)
    ),
    'policies', JSON_ARRAY(
        JSON_OBJECT('key','service_required','type','boolean','value',true,'action','collect','message','Antes de consultar a agenda, preciso saber qual serviço você deseja.','priority',10),
        JSON_OBJECT('key','confirmation_requires_human','type','boolean','value',false,'action','allow_confirm','message','O horário pode ser confirmado automaticamente quando houver disponibilidade real.','priority',20)
    ),
    'workflow', JSON_ARRAY(
        JSON_OBJECT('key','identify_intent','label','Identificar intenção','type','collect','position',10),
        JSON_OBJECT('key','collect_service','label','Coletar serviço','type','collect','position',20),
        JSON_OBJECT('key','collect_professional','label','Coletar profissional','type','collect','position',30),
        JSON_OBJECT('key','collect_schedule','label','Coletar dia e horário','type','collect','position',40),
        JSON_OBJECT('key','calendar','label','Consultar agenda','type','action','position',50),
        JSON_OBJECT('key','confirm','label','Confirmar horário válido','type','complete','position',60)
    )
),
'Use o Prompt Studio para tom e linguagem. Nunca invente disponibilidade. Serviço, duração, profissional e agenda são validados pelo backend antes de qualquer confirmação.', 1
FROM agent_blueprints b WHERE b.code = 'beauty-scheduling-v1'
ON DUPLICATE KEY UPDATE config_json = VALUES(config_json), prompt_guidance = VALUES(prompt_guidance), is_current = 1;

-- Clínica genérica.
INSERT INTO agent_blueprint_versions (blueprint_id, version_no, version_label, config_json, prompt_guidance, is_current)
SELECT b.id, 1, '1.0', JSON_OBJECT(
    'interaction_mode','hybrid',
    'capabilities',JSON_OBJECT('triage.enabled',true,'eligibility.enabled',true,'calendar.read',true,'calendar.pre_schedule',true,'calendar.confirm',false,'calendar.human_approval',true,'policy.fail_closed',true),
    'triage_fields',JSON_ARRAY(
        JSON_OBJECT('key','requester_name','label','Nome','type','text','prompt','Como você se chama?','required_before_schedule',true,'required_for_completion',true,'position',10),
        JSON_OBJECT('key','is_for_self','label','Atendimento para quem','type','boolean','prompt','O atendimento seria para você mesmo?','required_before_schedule',true,'required_for_completion',true,'position',20),
        JSON_OBJECT('key','modality','label','Modalidade','type','select','prompt','Você prefere atendimento online ou presencial?','options',JSON_ARRAY('online','presencial'),'required_before_schedule',false,'required_for_completion',false,'position',30),
        JSON_OBJECT('key','preferred_schedule','label','Preferência de agenda','type','text','prompt','Qual o melhor dia e horário para você?','required_before_schedule',true,'required_for_completion',true,'position',40)
    ),
    'policies',JSON_ARRAY(JSON_OBJECT('key','scheduling_requires_eligibility','type','boolean','value',true,'action','block_schedule','message','Antes de consultar a agenda, preciso concluir a triagem administrativa.','priority',10)),
    'workflow',JSON_ARRAY(JSON_OBJECT('key','triage','label','Triagem administrativa','type','collect','position',10),JSON_OBJECT('key','calendar','label','Consultar agenda','type','action','position',20),JSON_OBJECT('key','human_approval','label','Aguardar equipe','type','handoff','position',30))
),
'Use o prompt para linguagem e informações institucionais. O backend controla elegibilidade, agenda e confirmação.', 1
FROM agent_blueprints b WHERE b.code = 'health-clinic-intake-v1'
ON DUPLICATE KEY UPDATE config_json = VALUES(config_json), prompt_guidance = VALUES(prompt_guidance), is_current = 1;

-- Serviços genéricos.
INSERT INTO agent_blueprint_versions (blueprint_id, version_no, version_label, config_json, prompt_guidance, is_current)
SELECT b.id, 1, '1.0', JSON_OBJECT(
    'interaction_mode','hybrid',
    'capabilities',JSON_OBJECT('triage.enabled',true,'eligibility.enabled',false,'calendar.read',true,'calendar.pre_schedule',true,'calendar.confirm',true,'calendar.human_approval',false,'policy.fail_closed',true),
    'triage_fields',JSON_ARRAY(
        JSON_OBJECT('key','requester_name','label','Nome','type','text','prompt','Como você se chama?','required_before_schedule',true,'required_for_completion',true,'position',10),
        JSON_OBJECT('key','service','label','Serviço','type','text','prompt','Qual serviço você precisa?','required_before_schedule',true,'required_for_completion',true,'position',20),
        JSON_OBJECT('key','preferred_schedule','label','Dia e horário','type','text','prompt','Qual dia e horário ficam melhores para você?','required_before_schedule',true,'required_for_completion',true,'position',30)
    ),
    'policies',JSON_ARRAY(),
    'workflow',JSON_ARRAY(JSON_OBJECT('key','qualify','label','Qualificar pedido','type','collect','position',10),JSON_OBJECT('key','calendar','label','Consultar agenda','type','action','position',20),JSON_OBJECT('key','confirm','label','Confirmar','type','complete','position',30))
),
'Use o prompt para linguagem. Ações externas e agenda só são executadas após validação do backend.', 1
FROM agent_blueprints b WHERE b.code = 'services-scheduling-v1'
ON DUPLICATE KEY UPDATE config_json = VALUES(config_json), prompt_guidance = VALUES(prompt_guidance), is_current = 1;

-- Vincula apenas o nicho sugerido de tenants existentes; a cópia do blueprint é feita pelo serviço na primeira utilização.
UPDATE tenants t
JOIN business_niches n ON n.code = 'psychology'
SET t.business_niche_id = n.id
WHERE t.business_niche_id IS NULL
  AND LOWER(COALESCE(t.segment, '')) REGEXP 'psicolog|psicoterap';

UPDATE tenants t
JOIN business_niches n ON n.code = 'beauty'
SET t.business_niche_id = n.id
WHERE t.business_niche_id IS NULL
  AND LOWER(COALESCE(t.segment, '')) REGEXP 'barbear|salao|salão|cabeleire|estetica|estética';

UPDATE tenants t
JOIN business_niches n ON n.code = 'health_clinic'
SET t.business_niche_id = n.id
WHERE t.business_niche_id IS NULL
  AND LOWER(COALESCE(t.segment, '')) REGEXP 'clinica|clínica|consultorio|consultório|medic|odont';

UPDATE tenants t
JOIN business_niches n ON n.code = 'services'
SET t.business_niche_id = n.id
WHERE t.business_niche_id IS NULL
  AND LOWER(COALESCE(t.segment, '')) REGEXP 'servic|prestador';

DROP PROCEDURE IF EXISTS rs_add_column_if_missing;

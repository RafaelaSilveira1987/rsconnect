USE rs_connect;

INSERT INTO tenants
    (name, legal_name, slug, document, email, phone, website, segment, plan, status, onboarding_step)
VALUES
    ('Empresa Demonstração', 'Empresa Demonstração Ltda.', 'empresa-demonstracao', '00.000.000/0001-00',
     'contato@demo.local', '(11) 99999-9999', 'https://example.com', 'Serviços', 'pro', 'active', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active';

SET @demo_tenant_id = (SELECT id FROM tenants WHERE slug = 'empresa-demonstracao' LIMIT 1);

-- Senha dos usuários de demonstração: Admin@123
INSERT INTO users (tenant_id, name, email, password_hash, role, status)
VALUES
(NULL, 'Administrador RS', 'admin@rsconnect.local', '$2y$12$BTlf5MTlUXL4ZZProLBQweY9CPUdupF9LVoKnNfhqo7FUbvqxcb2S', 'super_admin', 'active'),
(@demo_tenant_id, 'Cliente Demonstração', 'cliente@demo.local', '$2y$12$BTlf5MTlUXL4ZZProLBQweY9CPUdupF9LVoKnNfhqo7FUbvqxcb2S', 'client_admin', 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active';

INSERT INTO permissions (permission_key, name, description, category) VALUES
('dashboard.view', 'Visualizar dashboard', 'Acessar os indicadores principais da empresa.', 'Painel'),
('company.view', 'Visualizar empresa', 'Consultar os dados cadastrais da própria empresa.', 'Empresa'),
('company.manage', 'Editar empresa', 'Alterar os dados cadastrais da própria empresa.', 'Empresa'),
('users.view', 'Visualizar usuários', 'Consultar os usuários da própria empresa.', 'Usuários'),
('users.manage', 'Gerenciar usuários', 'Cadastrar, editar, inativar e redefinir senha de usuários.', 'Usuários'),
('permissions.view', 'Visualizar permissões', 'Consultar a matriz de permissões dos perfis.', 'Usuários'),
('onboarding.manage', 'Executar onboarding', 'Configurar empresa, instância e primeiro agente.', 'Implantação'),
('instances.view', 'Visualizar instâncias', 'Consultar conexões da Evolution da própria empresa.', 'WhatsApp'),
('instances.manage', 'Gerenciar instâncias', 'Cadastrar instâncias e enviar mensagens de teste.', 'WhatsApp'),
('agents.view', 'Visualizar agentes', 'Consultar configurações dos agentes de IA.', 'Inteligência artificial'),
('agents.manage', 'Gerenciar agentes', 'Cadastrar, ativar e definir o agente padrão.', 'Inteligência artificial'),
('conversations.view', 'Visualizar conversas', 'Acessar a caixa de entrada, histórico e dados básicos dos contatos.', 'Atendimento'),
('conversations.manage', 'Gerenciar conversas', 'Enviar mensagens, assumir atendimentos, pausar IA e atualizar contatos.', 'Atendimento'),
('contacts.view', 'Visualizar contatos', 'Consultar contatos, clientes e histórico básico do relacionamento.', 'CRM'),
('contacts.manage', 'Gerenciar contatos', 'Cadastrar e editar contatos da empresa.', 'CRM'),
('crm.view', 'Visualizar CRM', 'Acessar o funil de vendas, negócios, notas e indicadores.', 'CRM'),
('crm.manage', 'Gerenciar CRM', 'Cadastrar negócios, movimentar o funil e registrar notas.', 'CRM'),
('tasks.view', 'Visualizar tarefas', 'Consultar tarefas, ligações, reuniões e follow-ups.', 'CRM'),
('tasks.manage', 'Gerenciar tarefas', 'Cadastrar, concluir e cancelar tarefas e follow-ups.', 'CRM')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), category = VALUES(category);

DELETE FROM role_permissions WHERE tenant_id IS NULL AND role IN ('client_admin', 'client_user');

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_admin', id, 1 FROM permissions;

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_user', id, 1
FROM permissions
WHERE permission_key IN (
    'dashboard.view', 'company.view', 'permissions.view',
    'instances.view', 'agents.view',
    'conversations.view', 'conversations.manage',
    'contacts.view', 'contacts.manage', 'crm.view', 'crm.manage',
    'tasks.view', 'tasks.manage'
);

INSERT INTO crm_pipelines (tenant_id, name, is_default)
VALUES (@demo_tenant_id, 'Funil comercial', 1)
ON DUPLICATE KEY UPDATE is_default = 1;

SET @demo_pipeline_id = (
    SELECT id FROM crm_pipelines
    WHERE tenant_id = @demo_tenant_id AND name = 'Funil comercial'
    LIMIT 1
);

INSERT INTO crm_stages (tenant_id, pipeline_id, name, stage_type, color_key, position, probability) VALUES
(@demo_tenant_id, @demo_pipeline_id, 'Novo', 'open', 'blue', 1, 10),
(@demo_tenant_id, @demo_pipeline_id, 'Qualificação', 'open', 'cyan', 2, 25),
(@demo_tenant_id, @demo_pipeline_id, 'Proposta', 'open', 'violet', 3, 50),
(@demo_tenant_id, @demo_pipeline_id, 'Negociação', 'open', 'amber', 4, 75),
(@demo_tenant_id, @demo_pipeline_id, 'Ganho', 'won', 'green', 5, 100),
(@demo_tenant_id, @demo_pipeline_id, 'Perdido', 'lost', 'slate', 6, 0)
ON DUPLICATE KEY UPDATE
    stage_type = VALUES(stage_type),
    color_key = VALUES(color_key),
    probability = VALUES(probability);

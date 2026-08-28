USE rs_connect;

-- ============================================================================
-- Snapshot de dados de referência v36.20.16
-- Reconciliação idempotente para instalações novas ou recuperação controlada.
-- ============================================================================

INSERT INTO permissions (permission_key, name, description, category) VALUES
    ('agents.manage', 'Gerenciar agentes', 'Cadastrar, ativar e definir o agente padrão.', 'Inteligência artificial'),
    ('agents.view', 'Visualizar agentes', 'Consultar configurações dos agentes de IA.', 'Inteligência artificial'),
    ('ai_credentials.manage', 'Gerenciar credenciais de IA', 'Cadastrar credenciais de IA por empresa/agente no painel RS.', 'Inteligência artificial'),
    ('automations.view', 'Visualizar automações', 'Acessar logs e status das automações de IA.', 'Inteligência artificial'),
    ('billing.manage', 'Gerenciar cobrança', 'Gerenciar planos, assinaturas e cobranças do SaaS.', 'Financeiro SaaS'),
    ('billing.reminders.manage', 'Gerenciar régua de cobrança', 'Criar regras e processar notificações financeiras.', 'Financeiro SaaS'),
    ('billing.reminders.view', 'Visualizar régua de cobrança', 'Consultar regras e histórico de notificações financeiras.', 'Financeiro SaaS'),
    ('billing.view', 'Visualizar assinatura', 'Consultar plano, uso e cobranças da empresa.', 'Financeiro SaaS'),
    ('calendar.manage', 'Gerenciar agenda', 'Criar, confirmar, concluir e cancelar agendamentos.', 'Agenda'),
    ('calendar.view', 'Visualizar agenda', 'Acessar compromissos, reuniões e retornos agendados.', 'Agenda'),
    ('campaigns.manage', 'Gerenciar campanhas', 'Criar campanhas, gerar audiência, aprovar e disparar lotes controlados.', 'Marketing'),
    ('campaigns.view', 'Visualizar campanhas', 'Acessar campanhas, destinatários, status de disparo e resultados.', 'Marketing'),
    ('company.manage', 'Editar empresa', 'Alterar os dados cadastrais da própria empresa.', 'Empresa'),
    ('company.view', 'Visualizar empresa', 'Consultar os dados cadastrais da própria empresa.', 'Empresa'),
    ('contacts.manage', 'Gerenciar contatos', 'Cadastrar e editar contatos da empresa.', 'CRM'),
    ('contacts.view', 'Visualizar contatos', 'Consultar contatos, clientes e histórico básico do relacionamento.', 'CRM'),
    ('conversations.manage', 'Gerenciar conversas', 'Enviar mensagens, assumir atendimentos, pausar IA e atualizar contatos.', 'Atendimento'),
    ('conversations.view', 'Visualizar conversas', 'Acessar a caixa de entrada, histórico e dados básicos dos contatos.', 'Atendimento'),
    ('crm.manage', 'Gerenciar CRM', 'Cadastrar negócios, movimentar o funil e registrar notas.', 'CRM'),
    ('crm.view', 'Visualizar CRM', 'Acessar o funil de vendas, negócios, notas e indicadores.', 'CRM'),
    ('dashboard.view', 'Visualizar dashboard', 'Acessar os indicadores principais da empresa.', 'Painel'),
    ('implementation.manage', 'Gerenciar implantação', 'Recalcular e marcar itens do checklist de implantação.', 'Implantação'),
    ('implementation.view', 'Visualizar implantação', 'Acessar checklist comercial de implantação dos clientes.', 'Implantação'),
    ('implementations.manage', 'Gerenciar implantações', 'Atualizar checklist técnico de implantação dos clientes.', 'Implantação'),
    ('implementations.view', 'Visualizar implantações', 'Acompanhar checklist de implantação dos clientes.', 'Implantação'),
    ('instances.manage', 'Gerenciar instâncias', 'Cadastrar instâncias e enviar mensagens de teste.', 'WhatsApp'),
    ('instances.view', 'Visualizar instâncias', 'Consultar conexões da Evolution da própria empresa.', 'WhatsApp'),
    ('n8n.manage', 'Gerenciar fluxos n8n', 'Cadastrar, testar e inativar webhooks n8n por empresa.', 'Integrações'),
    ('n8n.view', 'Visualizar fluxos n8n', 'Consultar integrações n8n configuradas por empresa.', 'Integrações'),
    ('notifications.manage', 'Gerenciar notificações', 'Marcar notificações como lidas e administrar avisos da conta.', 'Atendimento'),
    ('notifications.view', 'Visualizar notificações', 'Consultar alertas e avisos da conta cliente.', 'Atendimento'),
    ('onboarding.manage', 'Executar onboarding', 'Acessar e concluir o onboarding guiado da empresa.', 'Implantação'),
    ('operations.backup_automation', 'Gerenciar backup automático', 'Configurar e testar rotina de backup automático via n8n.', 'Operação'),
    ('payments.manage', 'Gerenciar gateways de pagamento', 'Cadastrar gateways e gerar links de cobrança.', 'Financeiro SaaS'),
    ('payments.view', 'Visualizar gateways de pagamento', 'Consultar gateways, links e webhooks de pagamento.', 'Financeiro SaaS'),
    ('permissions.view', 'Visualizar permissões', 'Consultar a matriz de permissões dos perfis.', 'Usuários'),
    ('pre_schedule.manage', 'Gerenciar pré-agendamentos', 'Aprovar, recusar e remarcar pré-agendamentos.', 'Agenda'),
    ('pre_schedule.view', 'Visualizar pré-agendamentos', 'Acessar solicitações de pré-agendamento na agenda.', 'Agenda'),
    ('privacy.manage', 'Gerenciar privacidade/LGPD', 'Editar políticas, registrar solicitações, concluir pedidos e configurar retenção.', 'Privacidade'),
    ('privacy.view', 'Visualizar privacidade/LGPD', 'Acessar central de privacidade, políticas, solicitações e aceites.', 'Privacidade'),
    ('queue.manage', 'Gerenciar fila de atendimento', 'Criar setores, distribuir conversas, alterar prioridade e status operacional.', 'Atendimento'),
    ('queue.view', 'Visualizar fila de atendimento', 'Acessar a visão operacional de conversas por status, setor e responsável.', 'Atendimento'),
    ('reports.schedule.manage', 'Gerenciar relatórios automáticos', 'Criar, pausar, gerar e enviar relatórios automáticos da própria empresa.', 'Relatórios'),
    ('reports.team.view_all', 'Ver indicadores da equipe', 'Visualizar indicadores consolidados de todos os profissionais da empresa.', 'Relatórios'),
    ('reports.team.view_own', 'Ver próprios indicadores', 'Visualizar indicadores de atendimento e agenda vinculados ao próprio usuário.', 'Relatórios'),
    ('reports.view', 'Visualizar relatórios', 'Acessar dashboards, métricas e exportações operacionais.', 'Relatórios'),
    ('security.manage', 'Gerenciar segurança', 'Revogar sessões e aplicar controles de segurança.', 'Segurança'),
    ('security.view', 'Visualizar segurança', 'Acessar painel de segurança, auditoria e sessões.', 'Segurança'),
    ('tasks.manage', 'Gerenciar tarefas', 'Cadastrar, concluir e cancelar tarefas e follow-ups.', 'CRM'),
    ('tasks.view', 'Visualizar tarefas', 'Consultar tarefas, ligações, reuniões e follow-ups.', 'CRM'),
    ('users.manage', 'Gerenciar usuários', 'Cadastrar, editar, inativar e redefinir senha de usuários.', 'Usuários'),
    ('users.view', 'Visualizar usuários', 'Consultar os usuários da própria empresa.', 'Usuários')
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    category = VALUES(category);

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_admin', p.id, 1
FROM permissions p
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.tenant_id IS NULL AND rp.role = 'client_admin' AND rp.permission_id = p.id
);

INSERT INTO role_permissions (tenant_id, role, permission_id, allowed)
SELECT NULL, 'client_user', p.id, 1
FROM permissions p
WHERE p.permission_key IN (
    'dashboard.view', 'company.view', 'permissions.view',
    'instances.view', 'agents.view',
    'conversations.view', 'conversations.manage',
    'contacts.view', 'contacts.manage', 'crm.view', 'crm.manage',
    'tasks.view', 'tasks.manage', 'calendar.view',
    'reports.view', 'notifications.view', 'billing.view',
    'pre_schedule.view', 'queue.view', 'privacy.view'
)
AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.tenant_id IS NULL AND rp.role = 'client_user' AND rp.permission_id = p.id
);

INSERT INTO saas_plans
    (plan_key, name, description, monthly_price, own_ai_monthly_price, rs_ai_monthly_price,
     commitment_discounts_json, limits_json, features_json, status, is_default, sort_order)
VALUES
('starter', 'Inicial', 'Plano inicial para automatizar um canal com atendimento, CRM, agenda e IA.',
 99.00, 69.00, 99.00, JSON_OBJECT('3', 0, '6', 8, '12', 15),
 JSON_OBJECT('users', 3, 'instances', 1, 'agents', 1, 'n8n_flows', 1, 'contacts_month', 300, 'conversations_month', 300, 'messages_month', 1500, 'ai_replies_month', 600, 'appointments_month', 100, 'crm_leads_month', 300),
 JSON_ARRAY('1 canal WhatsApp', '1 agente de IA', 'CRM essencial', 'Agenda interna', '1 automação integrada'),
 'active', 1, 10),
('pro', 'Profissional', 'Plano recomendado para equipes com dois canais, agenda, CRM e automações.',
 179.00, 129.00, 179.00, JSON_OBJECT('3', 0, '6', 8, '12', 15),
 JSON_OBJECT('users', 6, 'instances', 2, 'agents', 2, 'n8n_flows', 5, 'contacts_month', 1500, 'conversations_month', 1200, 'messages_month', 8000, 'ai_replies_month', 3500, 'appointments_month', 500, 'crm_leads_month', 1200),
 JSON_ARRAY('2 canais WhatsApp', '2 agentes de IA', 'CRM completo', 'Agenda + Google Calendar', '5 automações integradas'),
 'active', 0, 20),
('business', 'Empresarial', 'Plano para operações com vários números, áreas e agentes especializados.',
 349.00, 259.00, 349.00, JSON_OBJECT('3', 0, '6', 8, '12', 15),
 JSON_OBJECT('users', 15, 'instances', 5, 'agents', 5, 'n8n_flows', 15, 'contacts_month', 8000, 'conversations_month', 5000, 'messages_month', 30000, 'ai_replies_month', 12000, 'appointments_month', 2000, 'crm_leads_month', 5000),
 JSON_ARRAY('5 canais WhatsApp', '5 agentes de IA', 'Automações avançadas', 'Relatórios operacionais', '15 automações integradas'),
 'active', 0, 30),
('custom', 'Personalizado', 'Plano sob medida para múltiplas unidades, volumes ou integrações próprias.',
 0.00, 0.00, 0.00, JSON_OBJECT('3', 0, '6', 8, '12', 15),
 JSON_OBJECT('users', NULL, 'instances', NULL, 'agents', NULL, 'n8n_flows', NULL, 'contacts_month', NULL, 'conversations_month', NULL, 'messages_month', NULL, 'ai_replies_month', NULL, 'appointments_month', NULL, 'crm_leads_month', NULL),
 JSON_ARRAY('Limites personalizados', 'Condições comerciais sob medida'),
 'active', 0, 99)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    monthly_price = VALUES(monthly_price),
    own_ai_monthly_price = VALUES(own_ai_monthly_price),
    rs_ai_monthly_price = VALUES(rs_ai_monthly_price),
    commitment_discounts_json = VALUES(commitment_discounts_json),
    limits_json = VALUES(limits_json),
    features_json = VALUES(features_json),
    status = VALUES(status),
    sort_order = VALUES(sort_order);

INSERT INTO tenant_subscriptions
    (tenant_id, plan_id, billing_cycle, ai_billing_mode, commitment_months, commitment_ends_at,
     billing_status, starts_at, current_period_starts_at, current_period_ends_at,
     next_billing_at, amount, notes)
SELECT
    t.id,
    COALESCE(sp.id, sp_default.id),
    'monthly',
    'rs_connect',
    3,
    DATE_SUB(DATE_ADD(CURDATE(), INTERVAL 3 MONTH), INTERVAL 1 DAY),
    CASE WHEN t.status = 'suspended' THEN 'suspended' ELSE 'active' END,
    CURDATE(),
    DATE_FORMAT(CURDATE(), '%Y-%m-01'),
    LAST_DAY(CURDATE()),
    DATE_ADD(LAST_DAY(CURDATE()), INTERVAL 1 DAY),
    COALESCE(sp.rs_ai_monthly_price, sp.monthly_price, sp_default.rs_ai_monthly_price, sp_default.monthly_price),
    'Assinatura inicial criada pelo snapshot v36.20.16.'
FROM tenants t
LEFT JOIN saas_plans sp ON sp.plan_key = CONVERT(t.plan USING utf8mb4) COLLATE utf8mb4_unicode_ci
INNER JOIN saas_plans sp_default ON sp_default.plan_key = 'starter'
WHERE NOT EXISTS (SELECT 1 FROM tenant_subscriptions ts WHERE ts.tenant_id = t.id);

INSERT IGNORE INTO billing_reminder_rules
    (id, label, days_from_due, event_key, channel, auto_mark_overdue, auto_suspend, message_template, status)
VALUES
    (1, '3 dias antes do vencimento', -3, 'billing.reminder.before_due', 'n8n', 0, 0,
     'Olá, {empresa}. A cobrança {invoice_number} no valor de {valor} vence em {vencimento}. Link: {link_pagamento}', 'active'),
    (2, 'Aviso no dia do vencimento', 0, 'billing.reminder.due_today', 'n8n', 0, 0,
     'Olá, {empresa}. Sua cobrança {invoice_number} vence hoje no valor de {valor}. Link: {link_pagamento}', 'active'),
    (3, '2 dias após o vencimento', 2, 'billing.reminder.overdue', 'n8n', 1, 0,
     'Olá, {empresa}. A cobrança {invoice_number} está em aberto há {dias} dia(s). Link: {link_pagamento}', 'active'),
    (4, '7 dias após o vencimento — suspender', 7, 'billing.subscription.suspended', 'n8n', 1, 1,
     'Olá, {empresa}. Sua assinatura foi sinalizada para suspensão pela cobrança {invoice_number}. Link: {link_pagamento}', 'inactive');

INSERT INTO admin_crm_stages (stage_key, name, stage_type, color_key, position, probability) VALUES
('new', 'Novo contato', 'open', 'blue', 1, 10),
('demo', 'Demonstração', 'open', 'cyan', 2, 25),
('proposal', 'Proposta enviada', 'open', 'violet', 3, 50),
('negotiation', 'Negociação', 'open', 'amber', 4, 75),
('implementation', 'Aguardando implantação', 'won', 'indigo', 5, 90),
('active', 'Cliente ativo', 'active', 'green', 6, 100),
('risk', 'Em risco', 'open', 'red', 7, 60),
('cancelled', 'Cancelado', 'lost', 'slate', 8, 0)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    stage_type = VALUES(stage_type),
    color_key = VALUES(color_key),
    position = VALUES(position),
    probability = VALUES(probability);

-- SEED_SNAPSHOT_THROUGH: 089_schema_migrations_registry.sql


-- RS Connect 36.6.39
-- Diagnóstico da assinatura humana realmente enviada ao WhatsApp.

SELECT
    t.id AS tenant_id,
    t.name AS empresa,
    t.whatsapp_human_signature_enabled AS assinatura_geral_ativa,
    t.whatsapp_human_signature_format AS formato
FROM tenants t
ORDER BY t.name;

SELECT
    u.id AS user_id,
    u.tenant_id,
    CASE WHEN u.tenant_id IS NULL THEN 'Equipe RS / global' ELSE t.name END AS empresa,
    u.role,
    u.status,
    u.name AS nome_interno,
    u.whatsapp_display_name AS nome_enviado,
    u.whatsapp_role_label AS funcao_enviada,
    u.whatsapp_signature_enabled AS assinatura_usuario_ativa
FROM users u
LEFT JOIN tenants t ON t.id = u.tenant_id
ORDER BY u.tenant_id IS NULL DESC, empresa, u.name;

SELECT
    m.id,
    m.tenant_id,
    m.conversation_id,
    m.sender_user_id,
    m.sender_display_name,
    m.sender_role_label,
    m.content AS texto_original,
    m.delivered_content AS texto_enviado_evolution,
    m.sent_at
FROM conversation_messages m
WHERE m.direction = 'outgoing'
  AND m.sender_type = 'user'
ORDER BY m.id DESC
LIMIT 30;

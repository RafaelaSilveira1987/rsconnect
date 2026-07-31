-- RS Connect 36.10.6 — Auditoria do relatório por profissional
-- Consulta somente leitura. Não altera dados.

-- 1. Resumo exato das primeiras respostas humanas por empresa e profissional.
SELECT
    sc.tenant_id,
    COALESCE(NULLIF(u.whatsapp_display_name, ''), u.name, 'Usuário removido') AS profissional,
    COUNT(*) AS respostas_medidas,
    ROUND(AVG(GREATEST(0, TIMESTAMPDIFF(SECOND, sc.first_incoming_at, sc.first_response_at)))) AS media_segundos,
    MIN(GREATEST(0, TIMESTAMPDIFF(SECOND, sc.first_incoming_at, sc.first_response_at))) AS menor_segundos,
    MAX(GREATEST(0, TIMESTAMPDIFF(SECOND, sc.first_incoming_at, sc.first_response_at))) AS maior_segundos
FROM conversation_service_cycles sc
LEFT JOIN users u ON u.id = sc.first_response_user_id
WHERE sc.first_incoming_at IS NOT NULL
  AND sc.first_response_at IS NOT NULL
  AND sc.first_response_user_id IS NOT NULL
GROUP BY sc.tenant_id, sc.first_response_user_id, profissional
ORDER BY sc.tenant_id, media_segundos;

-- 2. Ciclos recentes usados na métrica, exibidos em UTC e no horário de São Paulo.
SELECT
    sc.tenant_id,
    sc.conversation_id,
    sc.cycle_number,
    COALESCE(NULLIF(ct.name, ''), ct.phone, 'Cliente') AS cliente,
    COALESCE(NULLIF(u.whatsapp_display_name, ''), u.name, 'Usuário removido') AS profissional,
    sc.first_incoming_at AS entrada_utc,
    CONVERT_TZ(sc.first_incoming_at, '+00:00', '-03:00') AS entrada_sao_paulo,
    sc.first_response_at AS resposta_utc,
    CONVERT_TZ(sc.first_response_at, '+00:00', '-03:00') AS resposta_sao_paulo,
    GREATEST(0, TIMESTAMPDIFF(SECOND, sc.first_incoming_at, sc.first_response_at)) AS resposta_segundos,
    sc.cycle_status,
    sc.source
FROM conversation_service_cycles sc
INNER JOIN conversations c ON c.id = sc.conversation_id AND c.tenant_id = sc.tenant_id
INNER JOIN contacts ct ON ct.id = c.contact_id AND ct.tenant_id = sc.tenant_id
LEFT JOIN users u ON u.id = sc.first_response_user_id
WHERE sc.first_incoming_at IS NOT NULL
  AND sc.first_response_at IS NOT NULL
ORDER BY sc.first_response_at DESC
LIMIT 100;

-- 3. Pendências reais: ciclo ativo que recebeu cliente e ainda não teve resposta humana.
SELECT
    sc.tenant_id,
    sc.conversation_id,
    sc.cycle_number,
    COALESCE(NULLIF(ct.name, ''), ct.phone, 'Cliente') AS cliente,
    sc.first_incoming_at,
    c.assigned_user_id,
    sc.source
FROM conversation_service_cycles sc
INNER JOIN conversations c ON c.id = sc.conversation_id AND c.tenant_id = sc.tenant_id
INNER JOIN contacts ct ON ct.id = c.contact_id AND ct.tenant_id = sc.tenant_id
WHERE sc.cycle_status = 'active'
  AND sc.first_incoming_at IS NOT NULL
  AND sc.first_response_at IS NULL
ORDER BY sc.first_incoming_at;

-- 4. Inconsistências que devem retornar zero registros.
SELECT
    sc.*
FROM conversation_service_cycles sc
WHERE sc.first_incoming_at IS NOT NULL
  AND sc.first_response_at IS NOT NULL
  AND sc.first_response_at < sc.first_incoming_at;

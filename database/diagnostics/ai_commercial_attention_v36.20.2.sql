-- Diagnóstico RS Connect 36.20.2 — clientes que precisam de atenção.

SELECT CASE
    WHEN EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'tenant_ai_commercial_attention_tracking'
    ) THEN 'OK'
    ELSE 'FALTA TABELA tenant_ai_commercial_attention_tracking'
END AS estrutura_lista_clientes_atencao;

SELECT status,
       COUNT(*) AS empresas,
       MIN(due_at) AS proxima_revisao
FROM tenant_ai_commercial_attention_tracking
GROUP BY status
ORDER BY FIELD(status, 'open', 'reviewing', 'waiting', 'resolved');

SELECT t.name AS empresa,
       a.status AS situacao,
       a.due_at AS proxima_revisao,
       a.note AS anotacao,
       a.updated_at AS atualizado_em
FROM tenant_ai_commercial_attention_tracking a
INNER JOIN tenants t ON t.id = a.tenant_id
ORDER BY FIELD(a.status, 'open', 'reviewing', 'waiting', 'resolved'), a.due_at, t.name;

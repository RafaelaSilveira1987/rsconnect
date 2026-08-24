-- RS Connect 36.6.10 — reparo da franquia de interações de IA
-- Corrige planos em que a migration 052 deixou ai_interactions_month ausente/nulo.
-- Preserva valor já configurado; tenta recuperar chaves legadas; por fim usa o
-- valor comercial original dos planos padrão (Starter 1500, Pro 8000, Business 30000).

UPDATE saas_plans
SET limits_json = JSON_SET(
    COALESCE(limits_json, JSON_OBJECT()),
    '$.ai_interactions_month',
    CASE
        WHEN JSON_UNQUOTE(JSON_EXTRACT(limits_json, '$.ai_interactions_month')) REGEXP '^[0-9]+$'
            THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(limits_json, '$.ai_interactions_month')) AS UNSIGNED)
        WHEN JSON_UNQUOTE(JSON_EXTRACT(limits_json, '$.messages_month')) REGEXP '^[0-9]+$'
            THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(limits_json, '$.messages_month')) AS UNSIGNED)
        WHEN JSON_UNQUOTE(JSON_EXTRACT(limits_json, '$.ai_replies_month')) REGEXP '^[0-9]+$'
            THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(limits_json, '$.ai_replies_month')) AS UNSIGNED)
        WHEN plan_key = 'starter' THEN 1500
        WHEN plan_key = 'pro' THEN 8000
        WHEN plan_key = 'business' THEN 30000
        ELSE NULL
    END
);

-- Remove as chaves comerciais antigas somente depois de reparar a nova franquia.
UPDATE saas_plans
SET limits_json = JSON_REMOVE(
    COALESCE(limits_json, JSON_OBJECT()),
    '$.messages_month',
    '$.ai_replies_month'
);

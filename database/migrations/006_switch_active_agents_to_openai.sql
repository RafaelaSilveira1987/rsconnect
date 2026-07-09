-- RS Connect - opcional: trocar agentes ativos para OpenAI
-- Execute somente depois de configurar OPENAI_API_KEY no EasyPanel.

UPDATE ai_agents
SET model_provider = 'openai',
    model_name = CASE
        WHEN model_name IS NULL OR model_name = '' OR model_name LIKE 'gemini%' THEN 'gpt-4o-mini'
        ELSE model_name
    END,
    updated_at = CURRENT_TIMESTAMP
WHERE status = 'active'
  AND auto_reply_enabled = 1;

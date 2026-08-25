-- RS Connect 36.19.1 — atribuição de custo de IA por empresa/assistente.
-- Backfill para eventos OpenAI já registrados sem custo estimado.
-- Tarifas padrão: snapshot oficial OpenAI em 2026-08-25.
-- AI_COST_RATES_JSON continua tendo prioridade para eventos novos e pode sobrescrever estas tarifas.

UPDATE ai_usage_events
SET estimated_cost = ROUND(
        (
            (GREATEST(COALESCE(input_tokens,0) - LEAST(COALESCE(input_tokens,0), COALESCE(cached_tokens,0)), 0) / 1000000) *
            CASE
                WHEN LOWER(model) LIKE 'gpt-4o-mini%' THEN 0.15
                WHEN LOWER(model) LIKE 'gpt-4o%' THEN 2.50
                WHEN LOWER(model) LIKE 'gpt-4.1-nano%' THEN 0.10
                WHEN LOWER(model) LIKE 'gpt-4.1-mini%' THEN 0.40
                WHEN LOWER(model) = 'gpt-4.1' OR LOWER(model) LIKE 'gpt-4.1-20%' THEN 2.00
                WHEN LOWER(model) LIKE 'gpt-5.6-luna%' THEN 0.20
                WHEN LOWER(model) LIKE 'gpt-5.6-terra%' THEN 2.00
                WHEN LOWER(model) LIKE 'gpt-5.6-sol%' OR LOWER(model) = 'gpt-5.6' OR LOWER(model) LIKE 'gpt-5.6-20%' THEN 4.00
                WHEN LOWER(model) LIKE 'gpt-5-mini%' THEN 0.25
                WHEN LOWER(model) = 'gpt-5' OR LOWER(model) LIKE 'gpt-5-20%' THEN 1.25
                ELSE 0
            END
        ) +
        (
            (LEAST(COALESCE(input_tokens,0), COALESCE(cached_tokens,0)) / 1000000) *
            CASE
                WHEN LOWER(model) LIKE 'gpt-4o-mini%' THEN 0.075
                WHEN LOWER(model) LIKE 'gpt-4o%' THEN 1.25
                WHEN LOWER(model) LIKE 'gpt-4.1-nano%' THEN 0.025
                WHEN LOWER(model) LIKE 'gpt-4.1-mini%' THEN 0.10
                WHEN LOWER(model) = 'gpt-4.1' OR LOWER(model) LIKE 'gpt-4.1-20%' THEN 0.50
                WHEN LOWER(model) LIKE 'gpt-5.6-luna%' THEN 0.02
                WHEN LOWER(model) LIKE 'gpt-5.6-terra%' THEN 0.20
                WHEN LOWER(model) LIKE 'gpt-5.6-sol%' OR LOWER(model) = 'gpt-5.6' OR LOWER(model) LIKE 'gpt-5.6-20%' THEN 0.40
                WHEN LOWER(model) LIKE 'gpt-5-mini%' THEN 0.025
                WHEN LOWER(model) = 'gpt-5' OR LOWER(model) LIKE 'gpt-5-20%' THEN 0.125
                ELSE 0
            END
        ) +
        (
            (COALESCE(output_tokens,0) / 1000000) *
            CASE
                WHEN LOWER(model) LIKE 'gpt-4o-mini%' THEN 0.60
                WHEN LOWER(model) LIKE 'gpt-4o%' THEN 10.00
                WHEN LOWER(model) LIKE 'gpt-4.1-nano%' THEN 0.40
                WHEN LOWER(model) LIKE 'gpt-4.1-mini%' THEN 1.60
                WHEN LOWER(model) = 'gpt-4.1' OR LOWER(model) LIKE 'gpt-4.1-20%' THEN 8.00
                WHEN LOWER(model) LIKE 'gpt-5.6-luna%' THEN 1.20
                WHEN LOWER(model) LIKE 'gpt-5.6-terra%' THEN 12.00
                WHEN LOWER(model) LIKE 'gpt-5.6-sol%' OR LOWER(model) = 'gpt-5.6' OR LOWER(model) LIKE 'gpt-5.6-20%' THEN 20.00
                WHEN LOWER(model) LIKE 'gpt-5-mini%' THEN 2.00
                WHEN LOWER(model) = 'gpt-5' OR LOWER(model) LIKE 'gpt-5-20%' THEN 10.00
                ELSE 0
            END
        ),
        8
    ),
    estimated_cost_currency = 'USD'
WHERE provider = 'openai'
  AND estimated_cost IS NULL
  AND COALESCE(provider_calls,0) > 0
  AND (COALESCE(input_tokens,0) > 0 OR COALESCE(output_tokens,0) > 0)
  AND LOWER(model) NOT LIKE '%tts%'
  AND LOWER(model) NOT LIKE '%transcribe%'
  AND LOWER(model) NOT LIKE '%realtime%'
  AND LOWER(model) NOT LIKE '%audio%'
  AND LOWER(model) NOT LIKE '%image%'
  AND LOWER(model) NOT LIKE '%sora%'
  AND (
        LOWER(model) LIKE 'gpt-4o-mini%'
     OR LOWER(model) LIKE 'gpt-4o%'
     OR LOWER(model) LIKE 'gpt-4.1-nano%'
     OR LOWER(model) LIKE 'gpt-4.1-mini%'
     OR LOWER(model) = 'gpt-4.1'
     OR LOWER(model) LIKE 'gpt-4.1-20%'
     OR LOWER(model) LIKE 'gpt-5.6-luna%'
     OR LOWER(model) LIKE 'gpt-5.6-terra%'
     OR LOWER(model) LIKE 'gpt-5.6-sol%'
     OR LOWER(model) = 'gpt-5.6'
     OR LOWER(model) LIKE 'gpt-5.6-20%'
     OR LOWER(model) LIKE 'gpt-5-mini%'
     OR LOWER(model) = 'gpt-5'
     OR LOWER(model) LIKE 'gpt-5-20%'
  );

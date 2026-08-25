# RS Connect — v36.19.0

Esta versão transforma a camada de IA em uma área de gestão: o painel OpenAI passa a cruzar consumo oficial com a telemetria do RS Connect, enquanto as conversas ganham memória progressiva para reduzir contexto repetido sem perder continuidade.

## Destaques

- Painel **OpenAI 2.0** com custo oficial, projeção mensal, orçamento, conversão de referência para reais e comparação oficial x RS Connect.
- Filtros internos por empresa e assistente, ranking de consumo e medição de chamadas/tokens evitados.
- Indicadores de respostas locais, cache exato, custo estimado por conversa e atualizações de memória.
- **Resumo progressivo por conversa**, atualizado somente a cada intervalo configurável de mensagens.
- **Memória estruturada por contato** com fatos confirmados, interesses, preferências, pendências, compromissos, restrições, última intenção e próxima ação, reaproveitável em novas conversas.
- Contexto recente ainda mais enxuto quando uma memória válida já existe.
- Configuração da memória por assistente e visualização da memória dentro da conversa.
- Modelos opcionais e mais econômicos para a tarefa de resumo, sem trocar o modelo principal do assistente.
- Guias técnicos, operacionais e comerciais atualizados (ponto 9 do projeto).

## Atualização

A partir da v36.18.6, execute obrigatoriamente:

```text
database/migrations/080_ai_memory_and_usage_intelligence.sql
```

Depois valide:

```text
database/diagnostics/ai_memory_usage_v36.19.0.sql
```

Não existem novas variáveis obrigatórias. Para aproveitar o painel gerencial e otimizar a memória, podem ser configuradas:

```dotenv
OPENAI_MONTHLY_BUDGET_USD=
OPENAI_USAGE_USD_BRL=
AI_MEMORY_MODEL_OPENAI=
AI_MEMORY_MODEL_GOOGLE=
```

A consulta oficial da organização continua usando `OPENAI_ADMIN_API_KEY` e, opcionalmente, `OPENAI_USAGE_PROJECT_IDS`.

Consulte `INSTRUCOES-v36.19.0.md` e `docs/guias/README.md`.

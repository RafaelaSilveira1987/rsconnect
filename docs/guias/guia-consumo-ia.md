# Guia de consumo, economia e memória de IA

## Visão executiva

A tela **Consumo OpenAI** passou a combinar duas fontes:

1. **Uso oficial da OpenAI** — tokens, chamadas, modelos e custo retornados pela Usage API administrativa.
2. **Telemetria do RS Connect** — consumo atribuído a empresas, assistentes e conversas, incluindo chamadas e tokens evitados.

Essa separação é importante: a conta OpenAI pode conter chamadas realizadas por outros sistemas, chaves ou projetos que não passaram pelo RS Connect.

## Indicadores principais

- custo oficial no período;
- projeção de custo para o fechamento do mês;
- orçamento mensal e percentual utilizado;
- estimativa em reais quando `OPENAI_USAGE_USD_BRL` estiver configurada;
- chamadas ao modelo;
- chamadas evitadas por regras locais e cache;
- tokens efetivamente processados;
- tokens de entrada estimados como evitados;
- média de tokens por resposta da IA;
- custo interno estimado por conversa;
- ranking de consumo por empresa e assistente;
- cobertura da telemetria interna comparada ao consumo oficial.

## Configuração opcional de gestão

```dotenv
OPENAI_MONTHLY_BUDGET_USD=100
OPENAI_USAGE_USD_BRL=5.50
```

O câmbio é apenas uma referência administrativa configurada pela RS Connect; o custo oficial continua sendo apresentado em dólar.

## Memória progressiva

A partir da v36.19.0, cada assistente pode manter uma memória resumida da conversa.

O funcionamento é:

```text
mensagens recentes
      +
resumo progressivo
      +
fatos estruturados
      +
trechos relevantes da base
      ↓
resposta da IA
```

A memória não é atualizada a cada turno. Por padrão, é renovada a cada 8 mensagens para que o custo da própria sumarização não anule a economia obtida.

Ela preserva, quando explicitamente presentes na conversa:

- interesses;
- preferências;
- fatos importantes;
- pendências;
- compromissos;
- restrições;
- última intenção;
- próximo passo.

Mensagens recentes sempre prevalecem sobre a memória caso exista divergência.

## Modelo usado para a memória

Por padrão, o sistema reutiliza o modelo do assistente. Opcionalmente, é possível definir um modelo econômico específico:

```dotenv
AI_MEMORY_MODEL_OPENAI=
AI_MEMORY_MODEL_GOOGLE=
```

A atualização da memória é registrada como consumo técnico do tipo `summary` e nunca conta como uma resposta automática entregue ao cliente.

## Boas práticas

- Comece no modo **Equilibrado**.
- Use memória progressiva ativa e atualização a cada 8 mensagens.
- Mantenha respostas locais para saudações e comandos simples.
- Ative cache exato somente após homologação.
- Compare custo médio por conversa antes e depois das otimizações.
- Não altere modelo, prompt e política de contexto simultaneamente durante a primeira medição.

A memória estruturada também é consolidada por contato, permitindo continuidade em novas conversas sem reenviar o histórico antigo.

## Atribuição de custo por empresa — v36.19.1

A OpenAI devolve o custo oficial da organização/projeto, mas não conhece o identificador interno da empresa (`tenant_id`) do RS Connect. Por isso, o ranking por empresa e assistente usa a telemetria registrada em `ai_usage_events` para atribuir cada chamada ao cliente correto.

A partir da v36.19.1, o custo não depende mais de `AI_COST_RATES_JSON` estar preenchido para os principais modelos OpenAI de texto. O sistema possui um catálogo padrão com snapshot de 25/08/2026 para modelos conhecidos, incluindo GPT-4o mini, GPT-4o, GPT-4.1, GPT-5 e GPT-5.6 Luna/Terra/Sol.

`AI_COST_RATES_JSON` continua disponível e tem prioridade sobre o catálogo interno quando a RS Connect quiser usar uma tarifa negociada, uma tarifa futura ou um modelo não contemplado.

A migration `081_ai_cost_attribution.sql` recalcula os eventos históricos já registrados pelo RS Connect que tinham tokens, mas estavam sem custo estimado. Ela não consegue atribuir a uma empresa chamadas antigas que existam somente no painel oficial da OpenAI e nunca tenham passado pela telemetria do RS Connect.

Na tela, compare sempre:

- **Custo oficial**: fonte OpenAI, consolidado pela organização/projeto;
- **Consumo por empresa**: fonte RS Connect, atribuído por `tenant_id`;
- **Cobertura**: quanto do consumo oficial também aparece na telemetria interna;
- **Diferença não atribuída**: chamadas de outros sistemas, períodos anteriores à telemetria, outros fluxos ou uso externo ao RS Connect.

## Governança por empresa — v36.19.2

Depois que a v36.19.1 passou a atribuir custo por empresa, a v36.19.2 adiciona uma camada de proteção financeira. Cada empresa pode ter orçamento próprio para IA custeada pela RS Connect, com níveis de atenção, crítico e limite.

A política é deliberadamente isolada da operação humana. Mesmo quando `block_rs_ai` estiver ativo, atendimento humano, regras locais, cache e credenciais próprias continuam funcionando.

Para configuração detalhada, consulte `guia-governanca-orcamento-ia.md`.


## Margem comercial por empresa — v36.19.3

O painel passa a cruzar custo técnico com a receita de referência da empresa. A origem pode ser a assinatura mensal equivalente ou um valor manual. Também é possível informar outros custos mensais e margem-alvo. Consulte `guia-margem-comercial-ia.md` para interpretação e boas práticas.


## Rentabilidade histórica — v36.20.0

A tela **Rentabilidade IA** usa a mesma telemetria atribuída por empresa para acompanhar evolução mensal, MRR de referência, contribuição conhecida e simulação de planos. Consulte `guia-rentabilidade-historica-ia.md` para a metodologia completa.

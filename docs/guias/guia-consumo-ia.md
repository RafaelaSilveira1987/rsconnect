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

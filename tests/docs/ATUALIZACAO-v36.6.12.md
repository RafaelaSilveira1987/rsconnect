# Atualização RS Connect 36.6.12

## Objetivo

Separar claramente o que é **volume de mensagens**, **interação comercial de IA**, **chamada ao provedor**, **tokens** e **franquia de IA custeada pela RS Connect**.

## Migration obrigatória

Depois do deploy, aplique:

```sql
SOURCE database/migrations/054_ai_metrics_and_delivery_telemetry.sql;
```

A migration 054 adiciona em `ai_usage_events`:

- `delivery_status`;
- `provider_calls`;
- `total_tokens`;
- `cached_tokens`;
- `estimated_cost_currency`;
- novos tipos técnicos de uso para futuras automações.

Ela também marca os eventos históricos `success` de `auto_reply` como entregues e preenche `total_tokens` quando possível.

## Regra comercial

A franquia do plano continua sendo `ai_interactions_month`.

Uma unidade da franquia só é confirmada quando:

1. a IA gera a resposta;
2. a conversa continua elegível para automação;
3. a Evolution aceita o envio;
4. a mensagem de saída é persistida no RS Connect.

Portanto:

- mensagem recebida: não consome franquia;
- resposta humana: não consome franquia;
- resposta fixa/ausência: não consome franquia;
- IA com credencial própria: é medida, mas não reduz franquia RS;
- falha do provedor: não consome interação comercial;
- resposta gerada e descartada por takeover humano: não consome interação comercial;
- falha na Evolution após geração: não consome interação comercial;
- resposta entregue usando IA custeada pela RS: consome 1 interação.

## Telemetria técnica

O Super Admin passa a acompanhar, por empresa e assistente:

- chamadas ao provedor;
- tokens de entrada;
- tokens de saída;
- tokens totais;
- tokens em cache, quando informados;
- falhas técnicas;
- custo estimado opcional, separado entre custo potencial da RS Connect e uso em credencial própria do cliente.

Esses indicadores não alteram a franquia comercial.

## Custo estimado opcional

O pacote **não fixa preços de OpenAI/Gemini no código**, porque tarifas dos provedores podem mudar.

Para habilitar estimativa, configure `AI_COST_RATES_JSON` com as tarifas vigentes na sua conta. Estrutura:

```text
AI_COST_RATES_JSON={"openai":{"NOME_DO_MODELO":{"input_per_million":VALOR,"cached_input_per_million":VALOR,"output_per_million":VALOR,"currency":"USD"}},"google":{"NOME_DO_MODELO":{"input_per_million":VALOR,"output_per_million":VALOR,"currency":"USD"}}}
```

Use valores numéricos reais no lugar de `VALOR` e o nome exato do modelo usado no RS Connect.

Se a variável ficar vazia, chamadas e tokens continuam sendo registrados; apenas o custo estimado aparece como não configurado. Quando houver tarifa, a visão administrativa separa o custo associado à credencial RS do custo de referência da credencial própria do cliente.

## Telas

### Cliente — Assinatura e uso

Passa a mostrar separadamente:

- Mensagens movimentadas;
- Interações automáticas de IA;
- Franquia de IA RS;
- IA com credencial própria;
- recebidas, enviadas pela equipe e saídas automáticas.

### Super Admin — Planos e cobrança → Ver uso e histórico

Além da visão comercial, ganha **Telemetria técnica da IA** com detalhamento por assistente/provedor/modelo.

## Ordem recomendada

1. Deploy da 36.6.12.
2. Aplicar migration 054.
3. Abrir Status do sistema e confirmar **Telemetria técnica da IA = OK**.
4. Fazer uma resposta automática com credencial RS.
5. Fazer uma resposta automática com credencial própria.
6. Gerar uma sugestão de resposta.
7. Revisar os totais em Assinatura e uso e no painel Super Admin.

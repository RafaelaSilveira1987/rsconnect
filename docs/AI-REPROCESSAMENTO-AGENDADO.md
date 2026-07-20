# Reprocessamento agendado da fila da IA

## Onde fica no painel

Acesse **Central de operação > Fila da IA**.

Para uma empresa específica, acesse **Empresas > Saúde e IA**. O botão **Reprocessar agora** também aparece no diagnóstico quando há uma mensagem realmente pendente.

## Regra de segurança

A rotina não dispara mensagens em massa e não reinicia conversas já respondidas. Uma mensagem só é elegível quando:

1. a empresa, o assistente e a resposta automática estão ativos;
2. a conversa está em modo IA e não está encerrada;
3. a tentativa está em `ai.cooldown`, `ai.failed`, teve entrega marcada como `failed` pela Evolution ou ficou sem registro porque a execução foi interrompida;
4. a tentativa está vinculada à mensagem recebida correspondente;
5. não existe mensagem de saída posterior à mensagem recebida;
6. reações são ignoradas quando o assistente está configurado para não respondê-las.

Execuções simultâneas são bloqueadas no MySQL para evitar duplicidade. Uma falha continua na fila, mas é tentada apenas uma vez por execução geral.

## Banco de dados

Execute:

```sql
SOURCE database/migrations/043_ai_reprocess_schedule.sql;
SOURCE database/migrations/044_ai_pending_failures_message_link.sql;
```

Ou importe o arquivo pelo gerenciador MySQL usado no ambiente.

## Variável de ambiente

Adicione ao `.env`:

```env
AI_REPROCESS_CRON_TOKEN=COLOQUE_UM_TOKEN_FORTE_E_ALEATORIO
```

Depois faça o redeploy/restart da aplicação.

## Opção 1: cron executando o PHP

Configure o servidor para chamar o comando a cada 5 minutos. O sistema verifica o horário salvo no painel e só executa uma vez por dia.

```cron
*/5 * * * * cd /caminho/do/rs-connect && php bin/ai-reprocess.php >> storage/logs/ai-reprocess-cron.log 2>&1
```

## Opção 2: n8n ou cron por URL

Também está incluído o arquivo importável `docs/n8n_templates/template-ai-reprocessamento-agendado.json`.

Faça uma requisição GET a cada 5 minutos:

```text
https://SEU_DOMINIO/webhooks/ai-reprocess/run?token=SEU_TOKEN
```

Também é aceito o cabeçalho:

```text
X-RS-AI-Reprocess-Token: SEU_TOKEN
```

Mesmo sendo consultado várias vezes, o endpoint só executa depois do horário configurado e no máximo uma vez por dia.

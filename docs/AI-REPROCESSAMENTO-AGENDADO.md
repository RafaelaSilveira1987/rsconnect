# Fila da IA — reprocessamento rápido e contingência diária

## Onde fica no painel
Acesse **Central de operação > Fila da IA**.

Para uma empresa específica, acesse **Empresas > Saúde e IA**. O botão **Reprocessar agora** também aparece quando existe uma mensagem realmente pendente.

## Duas rotinas diferentes

### 1. Fila rápida — a cada 1 minuto
É a rotina usada para respeitar `cooldown_seconds` sem perder mensagens.

Quando uma mensagem chega antes de terminar o intervalo mínimo:
- ela é armazenada;
- recebe `ai.cooldown`;
- a fila rápida reavalia periodicamente;
- somente responde depois que o intervalo já terminou.

Endpoint:

```text
GET /webhooks/ai-reprocess/queue
X-RS-AI-Reprocess-Token: SEU_TOKEN
```

Use o template n8n **Fila rápida da IA**. Ao baixá-lo pelo RS Connect, `APP_URL` e `AI_REPROCESS_CRON_TOKEN` são injetados automaticamente.

### 2. Contingência diária
Mantém a rotina histórica de varredura para falhas antigas, execuções interrompidas e recuperação geral.

Endpoint:

```text
GET /webhooks/ai-reprocess/run
X-RS-AI-Reprocess-Token: SEU_TOKEN
```

O endpoint diário só executa depois do horário configurado no painel e no máximo uma vez por dia.

## Regra de segurança
A fila não dispara mensagens em massa e não reinicia conversas já respondidas. Uma mensagem só é elegível quando:

1. empresa, assistente e resposta automática estão ativos;
2. conversa está em modo IA e não está encerrada;
3. existe `ai.cooldown`, `ai.failed`, `ai.quota.blocked`, falha de entrega ou execução interrompida;
4. não existe mensagem de saída posterior à mensagem recebida;
5. takeover Humano/Pausado continua bloqueando automação;
6. reações são ignoradas quando configurado;
7. no caso do cooldown, a execução automática **não ignora** o intervalo. Somente a ação manual explícita pode fazer bypass.

Execuções simultâneas são protegidas por locks MySQL.

## Banco de dados
A fila rápida da 36.6.9 não exige migration nova. Para instalações antigas, mantenha aplicadas as migrations da fila e a 052 da linha atual.

## Variáveis de ambiente

```env
AI_REPROCESS_CRON_TOKEN=COLOQUE_UM_TOKEN_FORTE_E_ALEATORIO
AI_REPROCESS_QUEUE_LIMIT=50
```

Depois faça redeploy/restart da aplicação.

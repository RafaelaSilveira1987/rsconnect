# Atualização RS Connect 36.6.10

## Objetivo

Hotfix de validação da rotina entregue na 36.6.9.

Corrige dois pontos encontrados na homologação:

1. o workflow **Fila rápida da IA** existia, porém estava armazenado com o nome legado `template-ai-reprocessamento-agendado.json`, dificultando sua identificação no ZIP;
2. algumas instalações exibiam **Interações de IA/mês — Sem limite definido**, mesmo quando o plano possuía franquia comercial.

## 1. Migration obrigatória

Aplique:

```sql
SOURCE database/migrations/053_ai_quota_limit_repair.sql;
```

A migration:

- preserva `ai_interactions_month` quando já estiver válido;
- tenta recuperar `messages_month` ou `ai_replies_month` em instalações legadas;
- se esses valores já tiverem sido removidos, restaura os valores comerciais originais dos planos padrão:
  - Starter: 1.500;
  - Profissional (`pro`): 8.000;
  - Business: 30.000;
- mantém planos Custom sem limite quando não houver valor definido.

Depois, abra **Planos e cobrança → Assinatura e uso** e confirme que `Interações de IA/mês` mostra um limite numérico para os planos padrão.

## 2. Template n8n renomeado

Acesse:

**n8n → Templates → Fila rápida da IA**

Baixe novamente o template.

O arquivo agora se chama:

```text
template-fila-rapida-ia.json
```

O workflow contém:

- Schedule Trigger: a cada 1 minuto;
- HTTP Request: `POST`;
- endpoint: `/webhooks/ai-reprocess/queue`;
- header: `X-RS-AI-Reprocess-Token`;
- token injetado a partir de `AI_REPROCESS_CRON_TOKEN`.

Se `APP_URL` ou `AI_REPROCESS_CRON_TOKEN` não estiverem configurados, o RS Connect bloqueia o download com uma mensagem explícita.

## 3. Validação do intervalo mínimo

O cron automático usa a origem `queue_cron` e **não** ativa `bypass_cooldown`.

Somente uma ação manual explícita pode ignorar o intervalo.

Fluxo esperado com `cooldown_seconds=60`:

```text
IA respondeu às 10:00:00
cliente escreveu às 10:00:20
→ mensagem salva
→ ai.cooldown registrado
→ nenhuma resposta antes de 10:01:00
→ fila de 1 minuto reavalia após o prazo
→ uma única resposta é gerada
```

## 4. Conversas sem voltar ao topo

O envio humano continua assíncrono:

- não recarrega a página;
- limpa o textarea;
- mantém foco no campo;
- posiciona o histórico na mensagem mais recente;
- muda visualmente a conversa para Humano.

Sem JavaScript, o fallback retorna ao `#conversation-composer`.

## 5. Variáveis de ambiente

```env
AI_REPROCESS_CRON_TOKEN=TOKEN_FORTE_E_ALEATORIO
AI_REPROCESS_QUEUE_LIMIT=50
```

Faça redeploy/restart após alterar o ambiente.

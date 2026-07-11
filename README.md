# RS Connect — ZIP 06

## IA Comercial, Regras de Atendimento e Credenciais por Cliente

Esta etapa consolida a IA para uso comercial no SaaS.

### Inclui

- credenciais de IA por empresa/cliente no painel Super Admin;
- credenciais criptografadas com `APP_KEY`;
- fallback para chave global da RS no `.env`;
- suporte OpenAI e Gemini;
- prioridade de credencial:
  1. credencial específica do agente;
  2. credencial padrão da empresa;
  3. chave global da RS no `.env`;
- regras de atendimento por agente;
- horário de atendimento;
- mensagem fora do horário;
- palavras-chave para transferência humana;
- ação ao transferir: pausar IA ou assumir humano;
- cooldown anti-loop para evitar respostas repetidas em sequência;
- hotfixes do webhook Evolution + OpenAI já incorporados;
- menu Super Admin: **Credenciais de IA**.

## Atualização a partir do ZIP 05

1. Faça commit/push dos arquivos no GitHub.
2. No EasyPanel, faça redeploy do serviço `rsconnect`.
3. No Adminer, execute:

```text
database/migrations/007_ai_commercial_rules.sql
```

Se você ainda não tinha executado o hotfix OpenAI anterior, execute também:

```text
database/migrations/006_switch_active_agents_to_openai.sql
```

## Variáveis recomendadas no EasyPanel

```env
OPENAI_API_KEY=SUA_CHAVE_GLOBAL_DA_RS_OU_VAZIO
OPENAI_API_BASE_URL=https://api.openai.com/v1
GEMINI_API_KEY=
GEMINI_API_BASE_URL=https://generativelanguage.googleapis.com/v1beta
AI_AUTOREPLY_ENABLED=true
AI_HTTP_TIMEOUT=28
AI_MAX_OUTPUT_TOKENS=420
AI_MAX_REPLY_CHARS=1400
```

A chave global da RS é opcional se você cadastrar uma credencial por empresa no painel **Credenciais de IA**.

## Como cadastrar a chave do cliente

Entre como Super Admin RS:

```text
Credenciais de IA → Nova credencial
```

Preencha:

- Empresa;
- opcionalmente, agente específico;
- provedor: OpenAI ou Google Gemini;
- API Key;
- modelo padrão, por exemplo `gpt-4o-mini`;
- marque como padrão.

A API Key será criptografada e não será exibida novamente.

## Teste recomendado

1. Envie mensagem do lead pelo WhatsApp.
2. Confirme que a mensagem entra em **Conversas**.
3. Confirme que a IA responde.
4. Peça “quero falar com atendente” e confirme se a IA pausa/transfere.
5. Ative horário de atendimento e teste a mensagem fora do horário.

## Consultas úteis

```sql
SELECT id, label, provider, default_model, status, is_default
FROM ai_provider_credentials
ORDER BY id DESC;
```

```sql
SELECT event, status, response_preview, error_message, created_at
FROM ai_automation_logs
ORDER BY id DESC
LIMIT 20;
```


## ZIP 07 — Conversas Pro + CRM Automático

Inclui CRM automático vindo do WhatsApp, card comercial dentro da conversa, tags automáticas, sugestão de resposta com IA e reprocessamento manual da IA.

Migration necessária:

```text
database/migrations/008_conversations_pro_crm_auto.sql
```


## ZIP 08 — Agenda e Google Calendar

Inclui agenda comercial, vínculo com contatos/conversas/CRM, exportação `.ics`, link para Google Calendar e webhook opcional para n8n.

## ZIP 09 — n8n por empresa

A partir do ZIP 09, integrações n8n devem ser cadastradas no painel **Fluxos n8n**, por empresa. O uso de `N8N_WEBHOOK_URL` no `.env` permanece apenas como fallback legado.

Consulte `README-ZIP-09.md` e o documento `Manual_RS_Connect_Funcionalidades_e_Implantacao.docx`.


## ZIP 10 — Templates n8n por Segmento

Adiciona templates JSON importáveis no n8n, tela Super Admin para download e payloads de exemplo, além de callback `/webhooks/n8n/callback` para registrar sucesso/erro dos fluxos por empresa.

## ZIP 11 — Planos, assinaturas e cobrança SaaS

Adiciona controle comercial do SaaS com planos, limites, assinatura por empresa, cobranças manuais e tela do cliente para acompanhar uso.

Migration:

```text
database/migrations/012_saas_billing_plans.sql
```

---

## ZIP 12 — Gateways de pagamento

Adiciona integração inicial de cobrança com Asaas, Mercado Pago e Stripe, com geração de links de pagamento para as cobranças do SaaS e webhooks de retorno.

Execute:

```text
database/migrations/013_payment_gateways.sql
```

Depois acesse como Super Admin:

```text
Gateways de pagamento
```

---

## ZIP 13 — PagBank + Régua de cobrança

Adiciona PagBank aos gateways de pagamento e cria a tela **Régua de cobrança** para avisos antes/no/depois do vencimento, envio de eventos `billing.*` para n8n por empresa e endpoint opcional de cron.

Migration:

```text
database/migrations/014_pagbank_billing_reminders.sql
```


## ZIP 14 — Templates de cobrança, notificações e UI

Inclui templates n8n de cron e disparo de cobrança, central de notificações do cliente, badge no menu e remoção de emojis no layout principal. Execute `database/migrations/015_notifications_frontend_billing_templates.sql`.

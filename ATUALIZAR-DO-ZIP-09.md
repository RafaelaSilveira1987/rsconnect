# RS Connect — ZIP 10: Templates n8n por Segmento

Esta etapa adiciona uma área de templates para n8n e um endpoint de callback para que cada fluxo externo devolva o resultado ao RS Connect.

## O que mudou

- Novo menu Super Admin: **Templates n8n**.
- Downloads de modelos JSON para importar no n8n.
- Endpoint `/webhooks/n8n/callback` para registrar sucesso/erro do fluxo.
- Payloads de exemplo na tela para facilitar a implantação por cliente.
- `AutomationWebhookService` passa a enviar `callback.url` e `callback.token` no corpo do evento.

## Como atualizar do ZIP 09

1. Suba os arquivos no GitHub.
2. Faça redeploy no EasyPanel.
3. No Adminer, execute:

```text
database/migrations/011_n8n_templates_callbacks.sql
```

4. Opcionalmente adicione no `.env`:

```env
N8N_CALLBACK_TOKEN=um-token-longo-para-callbacks
```

5. Entre como Super Admin e acesse:

```text
Templates n8n
```

## Fluxo comercial recomendado

A Evolution deve enviar mensagens somente para o RS Connect. O RS Connect registra conversas, CRM e IA. O n8n entra depois como integração externa por empresa, por exemplo: Google Calendar, Google Sheets, notificações e follow-up.

## Templates incluídos

- `template-agenda-google-calendar.json`
- `template-crm-google-sheets.json`
- `template-followup-alerta.json`

Cada template deve ser importado no n8n do cliente/da RS, receber uma URL própria e depois ser cadastrado em **Fluxos n8n** para a empresa correta.

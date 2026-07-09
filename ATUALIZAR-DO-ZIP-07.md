# Atualizar do ZIP 07 para o ZIP 08

## O que muda

O ZIP 08 adiciona a agenda comercial do RS Connect, com compromissos vinculados ao contato, conversa e CRM.

## Passo a passo

1. Faça backup do banco.
2. Suba os arquivos no GitHub.
3. Faça Redeploy do serviço `rsconnect` no EasyPanel.
4. No Adminer, selecione o banco `rs_connect`.
5. Importe apenas:

```text
database/migrations/009_calendar_appointments.sql
```

6. Acesse o menu **Agenda**.

## Variável opcional

Para usar n8n como ponte para Google Calendar, adicione:

```env
N8N_CALENDAR_WEBHOOK_URL=
```

Quando preenchida, o RS Connect envia um JSON para o n8n sempre que um agendamento é criado ou tem status alterado.

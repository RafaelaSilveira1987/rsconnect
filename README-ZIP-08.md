# RS Connect — ZIP 08 Agenda e Google Calendar

Esta etapa adiciona uma agenda operacional para o atendimento e CRM.

## Principais recursos

- Menu **Agenda**.
- Cadastro de agendamentos vinculados a contato, conversa ou negócio do CRM.
- Status: agendado, confirmado, concluído, cancelado e não compareceu.
- Responsável interno pelo compromisso.
- Link de reunião, local presencial ou telefone.
- Exportação `.ics` para Google Calendar, Outlook e Apple Calendar.
- Link rápido para criar o evento no Google Calendar.
- Integração opcional com n8n por `N8N_CALENDAR_WEBHOOK_URL` para fluxos avançados.
- Permissões `calendar.view` e `calendar.manage`.

## Atualização

1. Suba os arquivos no GitHub.
2. Faça Redeploy no EasyPanel.
3. No Adminer, execute:

```text
 database/migrations/009_calendar_appointments.sql
```

4. Se quiser sincronizar com n8n/Google Calendar, configure no `.env`:

```env
N8N_CALENDAR_WEBHOOK_URL=https://seu-n8n/webhook/agenda-rsconnect
```

Se esse valor ficar vazio, a agenda funciona normalmente e os eventos ficam apenas no RS Connect.

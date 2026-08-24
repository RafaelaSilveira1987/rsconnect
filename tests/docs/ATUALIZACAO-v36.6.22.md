# RS Connect 36.6.22 — Homologação alinhada à migration 056

## O que corrige

- O painel de Status do sistema agora exibe `Migration base 056` e `056_n8n_agenda_event_contract.sql`.
- O número da migration deixou de ser fixo no HTML e passa a ser derivado do pacote.
- O checklist técnico orienta migrations pendentes até a 056.
- Novo check valida se o fluxo ativo **Agenda Google Calendar por Empresa** aceita somente `calendar.appointment.created`.

## Banco

Não há migration nova na 36.6.22. A migration exigida continua sendo:

```sql
SOURCE database/migrations/056_n8n_agenda_event_contract.sql;
```

Se já foi aplicada, não é necessário reaplicá-la apenas por causa deste hotfix.

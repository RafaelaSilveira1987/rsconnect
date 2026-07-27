# RS Connect 36.6.23 — Google Agenda somente após confirmação real

## Motivo
Foi identificado um segundo caminho de integração da Agenda que não depende de `n8n_tenant_flows`: as URLs técnicas da agenda ficam em `tenant_calendar_availability_settings`. Por isso, mesmo com o writer por empresa desativado, o ciclo do Google ainda podia ser acionado por manutenção/sincronização direta.

## Correções
- Evento Google só pode ser criado/atualizado quando o compromisso está efetivamente confirmado.
- A manutenção automática não considera mais `scheduled`; somente `confirmed`.
- Pré-agendamento exige aprovação antes de qualquer sincronização automática posterior.
- O fluxo `Ciclo completo` recebe contrato `calendar_confirmed_sync_v1` e rejeita payload sem confirmação, appointment_id, título, início ou fim.
- O writer genérico também exige `appointment.status=confirmed` e aprovação de pré-agendamento.
- Removido título fallback no ciclo completo.

## Banco
Não há migration nova. A base exigida continua:

```sql
SOURCE database/migrations/056_n8n_agenda_event_contract.sql;
```

## n8n
Depois do deploy, baixe novamente e reimporte preferencialmente:
- **Agenda Google Calendar** (`v36.6.23`)
- **Google Agenda — Ciclo completo** (`v36.6.23`)

O backend já bloqueia sincronização de itens não confirmados, mas os templates atualizados formam uma segunda barreira de segurança.

## Diagnóstico importante
`n8n_tenant_flows` não armazena as URLs técnicas de disponibilidade/ciclo do Google. Para confirmar que a empresa possui integração técnica de Agenda configurada, use a tela **Agenda → Disponibilidade → Integração n8n** ou consulte apenas os indicadores abaixo:

```sql
SELECT
  tenant_id,
  enabled,
  availability_mode,
  use_n8n,
  free_slots_webhook_url_encrypted IS NOT NULL AS has_free_slots_url,
  marked_events_webhook_url_encrypted IS NOT NULL AS has_marked_events_url,
  calendar_event_webhook_url_encrypted IS NOT NULL AS has_event_cycle_url,
  create_google_event_on_confirm,
  maintenance_enabled
FROM tenant_calendar_availability_settings;
```

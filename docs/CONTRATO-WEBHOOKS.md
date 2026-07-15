# Contrato dos webhooks — ZIP 28

## Workflow 1 — espaços livres

**Entrada:** `POST /webhook/rsconnect-agenda-google-espacos-livres`

Campos mínimos:

```json
{
  "tenant_id": 2,
  "date": "2026-07-18",
  "work_start": "08:00",
  "work_end": "18:00",
  "duration_minutes": 60,
  "interval_minutes": 0,
  "calendar_id": "primary",
  "callback_url": "https://rsconnect.rsautomacaodigital.cloud/webhooks/calendar/availability"
}
```

## Workflow 2 — eventos VAGO

**Entrada:** `POST /webhook/rsconnect-agenda-google-eventos-vago`

Ações:

- `search`: busca eventos com títulos configurados;
- `hold`: transforma VAGO em PRÉ-RESERVADO;
- `confirm`: transforma PRÉ-RESERVADO em AGENDADO;
- `release`: restaura o título VAGO e `transparent`.

## Callback de disponibilidade

```json
{
  "event": "calendar.availability.result",
  "ok": true,
  "tenant_id": 2,
  "request_id": "agenda-001",
  "source": "google_marked_slots",
  "slots": [
    {
      "start": "2026-07-18T14:00:00-03:00",
      "end": "2026-07-18T15:00:00-03:00",
      "label": "14:00",
      "modality": "online",
      "google_event_id": "abc123",
      "google_calendar_id": "primary"
    }
  ]
}
```

## Callback de atualização

```json
{
  "event": "calendar.marked_slot.updated",
  "ok": true,
  "tenant_id": 2,
  "request_id": "agenda-001",
  "action": "hold",
  "state": "held",
  "google_event_id": "abc123",
  "google_calendar_id": "primary",
  "modality": "online",
  "current_summary": "PRÉ-RESERVADO — ONLINE — Maria",
  "transparency": "opaque"
}
```

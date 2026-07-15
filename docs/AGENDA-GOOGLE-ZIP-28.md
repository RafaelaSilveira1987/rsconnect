# Guia técnico — Agenda Google no ZIP 28

## Contrato comum de busca

O RS Connect envia `calendar.availability.requested` com:

- `tenant_id`, `appointment_id`, `request_id` e `request_token`;
- `availability_mode`;
- janela de busca;
- expediente, duração, intervalo, buffer e antecedência;
- calendário Google e títulos VAGO;
- URL de callback.

Os dois workflows retornam o mesmo evento:

```json
{
  "event": "calendar.availability.result",
  "request_id": 25,
  "request_token": "TOKEN",
  "source": "google_free_slots",
  "slots": []
}
```

No segundo modo, `source` será `google_marked_slots` e cada slot inclui `google_event_id`, `google_event_etag`, modalidade, título e transparência.

## Estados do evento VAGO

```text
available -> held -> confirmed
     ^          |         |
     +----------+---------+
             release
```

- `available`: evento VAGO localizado.
- `held`: evento alterado para PRÉ-RESERVADO.
- `confirmed`: evento alterado para AGENDADO.
- `released`: título e disponibilidade originais restaurados.

## Ações enviadas ao workflow VAGO

```text
search
hold
confirm
release
```

Antes de `hold`, o workflow relê o evento e verifica se ainda corresponde ao título VAGO e, quando configurado, se continua `transparent`.

Antes de `confirm` e `release`, ele confere as propriedades privadas gravadas pelo RS Connect para evitar alterar uma reserva de outra empresa ou outro pré-agendamento.

## Callback de atualização

```json
{
  "event": "calendar.marked_slot.updated",
  "action": "hold",
  "state": "held",
  "request_id": 25,
  "request_token": "TOKEN",
  "appointment_id": 15,
  "google_event_id": "EVENT_ID",
  "google_calendar_id": "primary",
  "modality": "online"
}
```

## Credenciais

Os templates usam o tipo de credencial n8n `googleCalendarOAuth2Api`. Depois da importação, abra cada nó Google e selecione a credencial correspondente. O JSON não inclui Client ID, Client Secret, refresh token ou qualquer credencial do usuário.

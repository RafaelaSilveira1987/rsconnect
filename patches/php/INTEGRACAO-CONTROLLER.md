# Integração no controller do ZIP 27

O código-fonte completo do ZIP 27 não estava anexado à conversa. Por isso, este pacote não sobrescreve controllers existentes. Aplique as regras abaixo no serviço que hoje chama o webhook configurado em **Agenda inteligente**.

## Buscar disponibilidade

1. Carregue `availability_mode` da empresa.
2. Use `SmartCalendarGooglePayloadFactory::buildAvailabilityPayload()`.
3. Se o modo for `free_slots`, envie para `free_slots_webhook_url`.
4. Se o modo for `marked_events`, envie para `marked_events_webhook_url`.
5. Mantenha o fallback interno do ZIP 27 quando o n8n falhar.

## Usar este horário no modo VAGO

Ao clicar em **Usar este horário**, o slot terá `google_event_id`. Envie `action=hold` para o mesmo webhook de eventos VAGO. Só grave a pré-reserva como concluída após receber `calendar.marked_slot.updated` com `state=held`.

## Aprovar

Antes de aprovar, envie `action=confirm`. O fluxo relê o evento, valida o título e usa o `etag` para impedir atualização concorrente. Depois do callback com `state=confirmed`, finalize a aprovação no RS Connect.

## Recusar/cancelar/remarcar

Quando a configuração `restore_on_cancel` estiver ativa, envie `action=release`. O evento volta para `VAGO — ONLINE` ou `VAGO — PRESENCIAL` e para `transparency=transparent`.

Para remarcar:

1. `release` no evento antigo;
2. nova busca;
3. `hold` no novo evento;
4. após aprovação, `confirm`.

## Callback

Amplie `/webhooks/calendar/availability` para aceitar:

- `event=calendar.availability.result`: salva a lista de slots;
- `event=calendar.marked_slot.updated`: atualiza `google_event_id`, estado e vínculo do pré-agendamento.

Use `CalendarAvailabilityCallbackMapper` como referência de normalização.

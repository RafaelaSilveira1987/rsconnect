-- RS Connect 36.6.20
-- Corrige cadastros legados do fluxo que grava compromissos no Google Calendar.
-- Esse fluxo não pode ficar inscrito em "*" nem em message.received/status updates,
-- pois cada chamada gera efeito colateral externo (criação de evento).

UPDATE n8n_tenant_flows
SET events_json = JSON_ARRAY('calendar.appointment.created')
WHERE status = 'active'
  AND (
    template_key = 'agenda-google-calendar'
    OR flow_key IN ('agenda-google-calendar', 'agenda-google-calendar-por-empresa')
    OR LOWER(name) LIKE 'rs connect - agenda google calendar por empresa%'
  );

# Manifesto do ZIP 28

Base utilizada: RS Connect ZIP 27 — Agenda Inteligente + Disponibilidade n8n.

## Arquivos integrados/alterados

- `app/Controllers/CalendarAvailabilityController.php`
- `app/Controllers/CalendarController.php`
- `app/Controllers/N8nTemplateController.php`
- `app/Services/AppVersionService.php`
- `app/Services/AutomationWebhookService.php`
- `app/Services/CalendarAvailabilityService.php`
- `app/Views/calendar/index.php`
- `app/Views/calendar_availability/index.php`
- `routes/web.php`
- `README.md`

## Arquivos novos

- `database/migrations/030_google_calendar_availability_modes.sql`
- `database/migrations/030_google_calendar_availability_modes_rollback.sql`
- `docs/n8n_templates/template-agenda-google-espacos-livres.json`
- `docs/n8n_templates/template-agenda-google-eventos-vago.json`
- `docs/n8n_templates/payloads-agenda-google-exemplo.json`
- `tests/calendar-google-free-slots-callback.json`
- `tests/calendar-google-marked-slots-callback.json`
- `tests/calendar-google-marked-update-callback.json`
- `README-ZIP-28.md`
- `ATUALIZAR-DO-ZIP-27.md`
- `docs/AGENDA-GOOGLE-ZIP-28.md`

## Validações realizadas antes do empacotamento

- sintaxe de todos os arquivos PHP com `php -l`;
- validade de todos os JSONs com `jq`;
- referências entre nós dos workflows;
- sintaxe dos nós Code com `node --check`;
- simulação local da lógica de espaços livres, filtro VAGO e pré-reserva;
- busca por padrões comuns de credenciais acidentalmente incluídas.

A execução real do OAuth Google, dos webhooks n8n e da migration depende do ambiente de produção/homologação.

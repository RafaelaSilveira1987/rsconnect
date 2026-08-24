# Homologação — RS Connect 36.6.17

## Template

- [ ] Em **n8n → Templates** existe **Manutenção automática da agenda**.
- [ ] O download gera `template-calendar-maintenance.json`.
- [ ] O JSON baixado contém a URL pública real do RS Connect.
- [ ] O JSON baixado não contém `SEU_CALENDAR_MAINTENANCE_TOKEN`.
- [ ] O workflow importado possui o gatilho **A cada 10 minutos**.

## Endpoint

- [ ] Sem token, `POST /webhooks/calendar/maintenance/run` retorna 403.
- [ ] Com `X-RS-Calendar-Maintenance-Token` correto, retorna JSON da manutenção.
- [ ] A execução automática aparece como sucesso no n8n.

## Regressão

- [ ] Manutenção manual da agenda continua funcionando com login/CSRF.
- [ ] Fila rápida da IA, Monitor operacional e Backup continuam ativos.
- [ ] Nenhuma migration nova é necessária.

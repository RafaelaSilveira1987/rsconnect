# RS Connect 36.6.17 — Manutenção automática da agenda

## Motivo

O painel já recomendava `CALENDAR_MAINTENANCE_TOKEN`, mas o pacote não entregava um template n8n específico para executar essa rotina automaticamente.

## O que foi incluído

- `docs/n8n_templates/template-calendar-maintenance.json`;
- card **Manutenção automática da agenda** em `n8n → Templates`;
- execução a cada 10 minutos;
- `POST /webhooks/calendar/maintenance/run`;
- autenticação por `X-RS-Calendar-Maintenance-Token`;
- injeção automática de `APP_URL` e `CALENDAR_MAINTENANCE_TOKEN` ao baixar o JSON pela plataforma.

## Configuração

No ambiente do RS Connect:

```env
CALENDAR_MAINTENANCE_TOKEN=gere_um_token_forte_e_exclusivo
```

Reinicie/reimplante a aplicação. Depois abra **n8n → Templates → Manutenção automática da agenda**, baixe o JSON, importe no n8n e publique/ative o workflow.

Não é necessário editar o token manualmente no JSON baixado pela plataforma.

## Banco

Não há migration nova. A última migration obrigatória continua sendo `055_multi_whatsapp_agent_routing.sql`.

# RS Connect

Pacote consolidado até o HOTFIX 36.1.2.

## Última etapa incluída

HOTFIX 36.1.2 — Persistência do webhook antes do processamento e fila resiliente.

## Atualização principal

Execute as migrations em ordem. Para atualizar a base mais recente, mantenha as migrations anteriores aplicadas e execute:

```text
database/migrations/043_ai_reprocess_schedule.sql
database/migrations/044_ai_pending_failures_message_link.sql
database/migrations/045_ai_webhook_ingestion_resilience.sql
```

Consulte `docs/AI-REPROCESSAMENTO-AGENDADO.md` para configurar o horário, o cron ou o workflow n8n.

## Módulos principais

- Multiempresa.
- WhatsApp/Evolution.
- Conversas com IA e atendimento humano.
- CRM.
- Agenda.
- n8n por empresa.
- Planos, cobranças e gateways.
- Régua de cobrança.
- Notificações.
- Relatórios e conversas com atualização automática.
- QR Code da Evolution nas instâncias.
- Onboarding e prompt guiado.
- Checklist de implantação RS.
- Fila de atendimento e distribuição por equipe.
- Campanhas e disparos controlados.

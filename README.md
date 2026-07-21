# RS Connect

Pacote consolidado até o ZIP 36.2 com HOTFIX 36.2.4.

## Última etapa incluída

HOTFIX 36.2.4 — nova preferência reinicia a disponibilidade sem reutilizar opções antigas e sem acionar a IA.

HOTFIX 36.2.2 — exclusão sincronizada: remove o evento vinculado do Google Agenda antes de apagar o registro local.

ZIP 36.2 — agenda conversacional com alternativas reais, escolha do contato, pré-reserva e aprovação profissional.

HOTFIX 36.1.3 — resposta crítica antes das integrações externas e cooldown por mensagem.

HOTFIX 36.1.2 — Persistência do webhook antes do processamento e fila resiliente.

## Atualização principal

Execute as migrations em ordem. Para atualizar a base mais recente, mantenha as migrations anteriores aplicadas e execute:

```text
database/migrations/043_ai_reprocess_schedule.sql
database/migrations/044_ai_pending_failures_message_link.sql
database/migrations/045_ai_webhook_ingestion_resilience.sql
database/migrations/046_calendar_conversational_slot_selection.sql
```

Consulte `README-HOTFIX-36.2.4.md` para corrigir novas preferências e opções antigas sem alterar o workflow Eventos VAGO.

Consulte `README-ZIP-36.2.md` para instalar e validar a agenda conversacional.

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

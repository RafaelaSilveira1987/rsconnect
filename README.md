# RS Connect

Pacote consolidado até o RS Connect 36.6.1 — Evolution ao vivo e backup n8n sem variáveis de ambiente internas.

## Última etapa incluída

RS Connect 36.6.1 — validação ao vivo da Evolution na fila da IA e workflow de backup sem `$env` no n8n.

RS Connect 36.6.0 — estabilização operacional: backup via bash/SSH, revisão de incidentes sincronizada e fila da IA consciente de instância Evolution desconectada.

RS Connect 36.5.9 — hamburger fixado diretamente ao viewport, Central de operação reorganizada, listas extensas compactadas e diagnóstico da fila por instância Evolution.

RS Connect 36.5.8 — reorganização da Administração RS, módulo n8n agrupado, Central de operação aprimorada e navegação global em páginas longas.

RS Connect 36.5.7 — reforço da identificação de novos contatos, resposta tátil no mobile e ativação segura do cron de cobrança.

RS Connect 36.5.6 — correções encontradas na homologação final: classificação de clientes, takeover humano da IA, reprocessamento, cron de cobrança e mobile.

Checkpoint de homologação: `docs/HOMOLOGACAO-FINAL-v36.6.1.md`.

RS Connect 36.5.5 — alinhamento do diagnóstico Beta com a migration 048 e refinamento visual do formulário de endereço em Minha empresa.

RS Connect 36.5.4 — Equipe e acessos em drawer, com cadastro e edição no padrão de Contatos.

RS Connect 36.5.3 — dados mestres compactos e preenchimento automático de endereço por CEP em Minha empresa.

RS Connect 36.3.0 — rotina de backup com job real, callback idempotente, timeout, arquivo verificado e histórico operacional.

HOTFIX 36.2.5 — validação da demanda encerra o fluxo de agenda sem deixar a IA reutilizar opções antigas.

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
database/migrations/047_backup_automation_reliability.sql
database/migrations/048_reporting_metrics_foundation.sql
```

Consulte `README-RS-CONNECT-36.3.0.md` para instalar e validar a rotina de backup.

Consulte `MAPEAMENTO-RELATORIOS-RS-CONNECT-v36.3.0.md` e a migration `048_reporting_metrics_foundation.sql` para a base agregada dos relatórios executivos.

Consulte `README-HOTFIX-36.2.5.md` para validar a etapa de demanda antes da agenda sem alterar o workflow Eventos VAGO.

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

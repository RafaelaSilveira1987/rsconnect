# Diagnóstico ENT-026 — saneamento da pasta de testes

## Resumo executivo

- Arquivo de origem: `rs-connect-vps-ready(5).zip`.
- Versão funcional encontrada na raiz: `RS Connect 36.20.11`.
- Snapshot duplicado encontrado em `tests/app`: `RS Connect 36.18.4`.
- Arquivos originais dentro de `tests/`: **682**.
- Arquivos com contraparte na aplicação: **570**.
- Cópias idênticas: **465**.
- Cópias divergentes: **105**.
- Artefatos reais sem contraparte na raiz: **112** (**83** smoke tests e **29** JSONs de contrato/cenário).
- Tamanho descompactado antes: aproximadamente **18 MB**.
- Tamanho descompactado depois: aproximadamente **9,5 MB**.

## Decisão técnica

A raiz do projeto foi tratada como fonte de verdade porque contém a versão 36.20.11 e funcionalidades posteriores à cópia 36.18.4 existente em `tests/`. Nenhum arquivo exclusivo da aplicação foi encontrado apenas dentro dos diretórios espelhados de `tests/`; portanto, a remoção não elimina módulo funcional necessário.

O backup lógico da árvore removida está em `docs/diagnostics/ENT-026-tests-original-manifest.json`, com caminho, tamanho, hash SHA-256, classificação e contraparte de cada arquivo. O ZIP original permanece como backup binário autoritativo.

## Estrutura final

```text
 tests/
 ├── Unit/
 ├── Integration/
 ├── Feature/
 ├── Contract/
 │   └── Fixtures/
 └── Support/
```

- `Feature/`: 84 smoke tests, incluindo a validação nova da ENT-026.
- `Contract/Fixtures/`: 29 payloads e cenários JSON.
- `Support/`: executor central da suíte.
- `Unit/` e `Integration/`: reservados para a implementação da ENT-032.

## Diferenças relevantes encontradas

1. A cópia de `tests/app/Services/AppVersionService.php` identificava o pacote como **36.18.4**, enquanto a raiz estava em **36.20.11**.
2. A cópia antiga não continha módulos posteriores, como rentabilidade de IA, margem comercial, atenção comercial, cálculo de custos e ajuda contextual.
3. Controllers críticos estavam divergentes: `ConversationController`, `EvolutionWebhookController`, `InstanceController`, `BillingController`, `PaymentGatewayController`, `AgentController` e `OpenAiUsageController`.
4. Views, rotas, CSS e JavaScript dentro de `tests/` estavam desatualizados em relação ao código de produção.
5. `tests/tests/` continha uma segunda cópia de 92 testes históricos, criando recursão e risco de executar validações antigas.
6. Cópias de schema, migrations, documentação, Docker e arquivos `.env.example` estavam dentro de `tests/`, podendo induzir deploy ou manutenção na árvore errada.

## Inventário dos 105 arquivos divergentes removidos

| Arquivo removido | Contraparte mantida |
|---|---|
| `tests/.env.example` | `.env.example` |
| `tests/.env.local.example` | `.env.local.example` |
| `tests/.env.vps.example` | `.env.vps.example` |
| `tests/README.md` | `README.md` |
| `tests/app/Controllers/AgentController.php` | `app/Controllers/AgentController.php` |
| `tests/app/Controllers/BillingController.php` | `app/Controllers/BillingController.php` |
| `tests/app/Controllers/BillingReminderController.php` | `app/Controllers/BillingReminderController.php` |
| `tests/app/Controllers/ConversationController.php` | `app/Controllers/ConversationController.php` |
| `tests/app/Controllers/EvolutionWebhookController.php` | `app/Controllers/EvolutionWebhookController.php` |
| `tests/app/Controllers/InstanceController.php` | `app/Controllers/InstanceController.php` |
| `tests/app/Controllers/OpenAiUsageController.php` | `app/Controllers/OpenAiUsageController.php` |
| `tests/app/Controllers/PaymentGatewayController.php` | `app/Controllers/PaymentGatewayController.php` |
| `tests/app/Services/AiAfterHoursRecoveryService.php` | `app/Services/AiAfterHoursRecoveryService.php` |
| `tests/app/Services/AiAutomationService.php` | `app/Services/AiAutomationService.php` |
| `tests/app/Services/AiContextBuilder.php` | `app/Services/AiContextBuilder.php` |
| `tests/app/Services/AiModelService.php` | `app/Services/AiModelService.php` |
| `tests/app/Services/AiUsageService.php` | `app/Services/AiUsageService.php` |
| `tests/app/Services/AppVersionService.php` | `app/Services/AppVersionService.php` |
| `tests/app/Services/CommercialBetaService.php` | `app/Services/CommercialBetaService.php` |
| `tests/app/Services/ConversationOwnershipService.php` | `app/Services/ConversationOwnershipService.php` |
| `tests/app/Services/OnboardingGuideService.php` | `app/Services/OnboardingGuideService.php` |
| `tests/app/Services/OpenAiOrganizationUsageService.php` | `app/Services/OpenAiOrganizationUsageService.php` |
| `tests/app/Services/OperationalLanguageService.php` | `app/Services/OperationalLanguageService.php` |
| `tests/app/Services/OperationalPlaybookService.php` | `app/Services/OperationalPlaybookService.php` |
| `tests/app/Services/OperationsService.php` | `app/Services/OperationsService.php` |
| `tests/app/Services/SubscriptionService.php` | `app/Services/SubscriptionService.php` |
| `tests/app/Views/agents/index.php` | `app/Views/agents/index.php` |
| `tests/app/Views/ai_credentials/index.php` | `app/Views/ai_credentials/index.php` |
| `tests/app/Views/billing/index.php` | `app/Views/billing/index.php` |
| `tests/app/Views/billing/subscription.php` | `app/Views/billing/subscription.php` |
| `tests/app/Views/billing/subscription_admin.php` | `app/Views/billing/subscription_admin.php` |
| `tests/app/Views/billing_reminders/index.php` | `app/Views/billing_reminders/index.php` |
| `tests/app/Views/calendar_availability/index.php` | `app/Views/calendar_availability/index.php` |
| `tests/app/Views/campaigns/index.php` | `app/Views/campaigns/index.php` |
| `tests/app/Views/communications/index.php` | `app/Views/communications/index.php` |
| `tests/app/Views/companies/settings.php` | `app/Views/companies/settings.php` |
| `tests/app/Views/conversations/index.php` | `app/Views/conversations/index.php` |
| `tests/app/Views/crm/admin_setup.php` | `app/Views/crm/admin_setup.php` |
| `tests/app/Views/crm/pipeline.php` | `app/Views/crm/pipeline.php` |
| `tests/app/Views/docs/beta.php` | `app/Views/docs/beta.php` |
| `tests/app/Views/docs/index.php` | `app/Views/docs/index.php` |
| `tests/app/Views/docs/status.php` | `app/Views/docs/status.php` |
| `tests/app/Views/instances/index.php` | `app/Views/instances/index.php` |
| `tests/app/Views/layouts/app.php` | `app/Views/layouts/app.php` |
| `tests/app/Views/n8n_flows/hub.php` | `app/Views/n8n_flows/hub.php` |
| `tests/app/Views/n8n_flows/index.php` | `app/Views/n8n_flows/index.php` |
| `tests/app/Views/n8n_templates/index.php` | `app/Views/n8n_templates/index.php` |
| `tests/app/Views/onboarding/index.php` | `app/Views/onboarding/index.php` |
| `tests/app/Views/openai_usage/index.php` | `app/Views/openai_usage/index.php` |
| `tests/app/Views/operations/ai_reprocess.php` | `app/Views/operations/ai_reprocess.php` |
| `tests/app/Views/operations/backup_automation.php` | `app/Views/operations/backup_automation.php` |
| `tests/app/Views/payment_gateways/index.php` | `app/Views/payment_gateways/index.php` |
| `tests/app/Views/privacy/admin.php` | `app/Views/privacy/admin.php` |
| `tests/app/Views/reports/admin.php` | `app/Views/reports/admin.php` |
| `tests/app/Views/reports/automatic.php` | `app/Views/reports/automatic.php` |
| `tests/app/Views/reports/team.php` | `app/Views/reports/team.php` |
| `tests/app/Views/security/index.php` | `app/Views/security/index.php` |
| `tests/app/Views/white_label/index.php` | `app/Views/white_label/index.php` |
| `tests/database/schema.sql` | `database/schema.sql` |
| `tests/database/vps_fresh_install.sql` | `database/vps_fresh_install.sql` |
| `tests/public/assets/css/app.css` | `public/assets/css/app.css` |
| `tests/public/assets/js/app.js` | `public/assets/js/app.js` |
| `tests/routes/web.php` | `routes/web.php` |
| `tests/tests/admin-executive-report-v36140-smoke.php` | `tests/admin-executive-report-v36140-smoke.php` |
| `tests/tests/after-hours-human-queue-v36184-smoke.php` | `tests/after-hours-human-queue-v36184-smoke.php` |
| `tests/tests/after-hours-queue-display-v36183-smoke.php` | `tests/after-hours-queue-display-v36183-smoke.php` |
| `tests/tests/agent-instance-linking-v36182-smoke.php` | `tests/agent-instance-linking-v36182-smoke.php` |
| `tests/tests/ai-efficiency-phase2-v36180-smoke.php` | `tests/ai-efficiency-phase2-v36180-smoke.php` |
| `tests/tests/ai-efficiency-v36170-smoke.php` | `tests/ai-efficiency-v36170-smoke.php` |
| `tests/tests/calendar-visualization-smoke.php` | `tests/calendar-visualization-smoke.php` |
| `tests/tests/client-executive-report-v36150-smoke.php` | `tests/client-executive-report-v36150-smoke.php` |
| `tests/tests/contact-schedule-overlap-smoke.php` | `tests/contact-schedule-overlap-smoke.php` |
| `tests/tests/conversation-attachments-smoke.php` | `tests/conversation-attachments-smoke.php` |
| `tests/tests/conversation-cycle-status-sync-smoke.php` | `tests/conversation-cycle-status-sync-smoke.php` |
| `tests/tests/conversation-explicit-selection-smoke.php` | `tests/conversation-explicit-selection-smoke.php` |
| `tests/tests/conversation-hours-ai-usage-smoke.php` | `tests/conversation-hours-ai-usage-smoke.php` |
| `tests/tests/conversation-status-colors-smoke.php` | `tests/conversation-status-colors-smoke.php` |
| `tests/tests/evolution-client-admin-v36161-smoke.php` | `tests/evolution-client-admin-v36161-smoke.php` |
| `tests/tests/evolution-instance-management-v36160-smoke.php` | `tests/evolution-instance-management-v36160-smoke.php` |
| `tests/tests/executive-metrics-consistency-smoke.php` | `tests/executive-metrics-consistency-smoke.php` |
| `tests/tests/human-signature-delivery-smoke.php` | `tests/human-signature-delivery-smoke.php` |
| `tests/tests/instance-create-layout-v36171-smoke.php` | `tests/instance-create-layout-v36171-smoke.php` |
| `tests/tests/login-greeting-message-direction-smoke.php` | `tests/login-greeting-message-direction-smoke.php` |
| `tests/tests/meta-ai-lid-protection-smoke.php` | `tests/meta-ai-lid-protection-smoke.php` |
| `tests/tests/openai-organization-usage-v36162-smoke.php` | `tests/openai-organization-usage-v36162-smoke.php` |
| `tests/tests/openai-usage-menu-v36163-smoke.php` | `tests/openai-usage-menu-v36163-smoke.php` |
| `tests/tests/operational-friendly-language-smoke.php` | `tests/operational-friendly-language-smoke.php` |
| `tests/tests/operational-history-metrics-smoke.php` | `tests/operational-history-metrics-smoke.php` |
| `tests/tests/operational-monitoring-alerts-smoke.php` | `tests/operational-monitoring-alerts-smoke.php` |
| `tests/tests/professional-assignment-smoke.php` | `tests/professional-assignment-smoke.php` |
| `tests/tests/professional-calendar-smoke.php` | `tests/professional-calendar-smoke.php` |
| `tests/tests/prompt-studio-smoke.php` | `tests/prompt-studio-smoke.php` |
| `tests/tests/public-uuid-routing-smoke.php` | `tests/public-uuid-routing-smoke.php` |
| `tests/tests/reports-consistency-v36151r3-smoke.php` | `tests/reports-consistency-v36151r3-smoke.php` |
| `tests/tests/reports-pdf-brand-v36151r4-smoke.php` | `tests/reports-pdf-brand-v36151r4-smoke.php` |
| `tests/tests/reports-pdf-logo-v36151r6-smoke.php` | `tests/reports-pdf-logo-v36151r6-smoke.php` |
| `tests/tests/reports-pdf-polish-v36151r5-smoke.php` | `tests/reports-pdf-polish-v36151r5-smoke.php` |
| `tests/tests/scheduled-reports-pdo-placeholders-smoke.php` | `tests/scheduled-reports-pdo-placeholders-smoke.php` |
| `tests/tests/scheduled-reports-v36151-smoke.php` | `tests/scheduled-reports-v36151-smoke.php` |
| `tests/tests/security-session-webhook-hardening-smoke.php` | `tests/security-session-webhook-hardening-smoke.php` |
| `tests/tests/service-cycle-recovery-smoke.php` | `tests/service-cycle-recovery-smoke.php` |
| `tests/tests/team-metrics-provenance-smoke.php` | `tests/team-metrics-provenance-smoke.php` |
| `tests/tests/team-professional-reports-smoke.php` | `tests/team-professional-reports-smoke.php` |
| `tests/tests/team-report-audit-smoke.php` | `tests/team-report-audit-smoke.php` |
| `tests/tests/tenant-isolation-hardening-smoke.php` | `tests/tenant-isolation-hardening-smoke.php` |

## Validação de regressão

- Antes da alteração: **74 aprovados / 9 reprovados / 83 total**.
- Depois da alteração: **75 aprovados / 9 reprovados / 84 total**.
- O teste adicional aprovado é `ent026-tests-sanitization-smoke.php`.
- As nove falhas restantes são as mesmas do baseline e não foram ampliadas pela reorganização.

### Falhas históricas preservadas

- `after-hours-human-queue-v36184-smoke.php`: marcador esperado no webhook não corresponde ao código atual.
- `ai-efficiency-phase2-v36180-smoke.php`: detecta `app/Controllers.tmp` e `app/Controllers.tmp.php`; remoção de temporários pertence à ENT-041.
- cinco testes de versões antigas dependem de arquivos `INSTRUCOES-v*.md` ausentes no pacote original.
- `instance-delete-without-replacement-v36209-smoke.php`: além da instrução ausente, mantém expectativa de cache/versão histórica.
- `openai-usage-menu-v36163-smoke.php`: depende de documentação histórica ausente.

## Escopo não alterado

Não houve alteração em regras comerciais, banco de dados, migrations, rotas funcionais, permissões, multitenancy, Evolution API, conversas, fila fora do horário, cobrança, agenda ou integrações externas.

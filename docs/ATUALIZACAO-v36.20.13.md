# Atualização v36.20.13 — ENT-028 / PA-002

## Escopo

Blindagem dos webhooks de pagamento, Evolution API e n8n, com autenticação específica, timestamp quando suportado, proteção contra replay, idempotência e logs sanitizados.

Também foi concluída a configuração de cobrança PagBank/PagSeguro existente no projeto, preservando a arquitetura incremental e as regras comerciais atuais.

## Arquivos centrais

- `app/Services/WebhookSecurityService.php`;
- `database/migrations/087_webhook_security_events.sql`;
- `app/Controllers/EvolutionWebhookController.php`;
- `app/Controllers/N8nTemplateController.php`;
- `app/Controllers/PaymentGatewayController.php`;
- `app/Controllers/InstanceController.php`;
- `app/Services/PaymentGatewayService.php`;
- `app/Views/payment_gateways/index.php`;
- `public/assets/js/app.js`.

## Compatibilidade

Nenhuma rota comercial, permissão, tenant, conversa, agenda, plano ou regra de cobrança foi removida. A alteração exige configuração dos segredos e execução da migration 087 antes do deploy.

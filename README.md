# RS Connect — v36.20.13.3


## Hotfix v36.20.13.3 — persistência de cobrança PagBank

- corrige o erro `Unknown column payment_status_checked_at` ao salvar um Checkout criado;
- adiciona a migration idempotente `088_payment_reconciliation_schema_compat.sql`;
- restaura as colunas operacionais previstas na migration 042 quando o banco recebeu uma atualização parcial;
- mantém fallback compatível para não perder o link de cobrança durante a janela entre deploy e migration;
- não altera valores, planos, assinaturas ou regras de cobrança.

Esta versão consolida a **ENT-028 / PA-002 — Blindagem dos webhooks** e aplica hotfixes de autenticação e dados do comprador no **PagBank / PagSeguro**.

A base homologada da v36.20.12 foi preservada: `tests/` continua sem uma segunda cópia da aplicação.

## Principais alterações

- produção opera em modo fail-closed, independentemente de `SECURITY_WEBHOOK_STRICT=false`;
- segredos ausentes ou com valor de exemplo geram erro de configuração;
- Evolution API usa token exclusivamente no header, sem token na URL;
- callback do n8n exige token, timestamp e HMAC-SHA256;
- PagBank valida `x-authenticity-token` com o Token da API e o payload bruto;
- Stripe valida `Stripe-Signature` e rejeita timestamps expirados;
- Mercado Pago valida `x-signature` e `x-request-id`;
- Asaas valida `asaas-access-token`;
- eventos críticos usam idempotência persistente em `webhook_security_events`;
- payloads completos, tokens e dados financeiros deixam de ser gravados nos logs de gateway;
- o painel de meios de pagamento orienta a configuração PagBank/PagSeguro;
- o Checkout PagBank gera links para Pix, boleto ou cartão, conforme o método selecionado.
- URL base PagBank é normalizada para o host oficial, removendo `/checkouts` duplicado;
- Token PagBank aceita colagem com `Bearer` ou `Authorization:` e salva apenas a credencial;
- ambiente Sandbox/Produção é validado antes da chamada externa;
- erros de autenticação da infraestrutura AWS são convertidos em orientação operacional clara.
- CPF/CNPJ é validado pelos dígitos verificadores antes do envio ao PagBank;
- documento inválido é omitido do checkout editável para o comprador informar o dado correto;
- recusa específica de `customer.tax_id` aciona uma única nova tentativa sem o documento.

## Migration obrigatória

Execute **antes de publicar o código**:

```text
database/migrations/087_webhook_security_events.sql
```

Sem a migration, os webhooks críticos retornam erro `503` em vez de operar sem proteção contra replay.

## Configuração mínima de produção

```dotenv
APP_ENV=production
SECURITY_WEBHOOK_STRICT=true
SECURITY_WEBHOOK_ALLOW_INSECURE_LOCAL=false
SECURITY_WEBHOOK_MAX_AGE_SECONDS=300
SECURITY_WEBHOOK_PROCESSING_STALE_SECONDS=900

EVOLUTION_WEBHOOK_TOKEN=GERE_UM_TOKEN_FORTE_COM_32_OU_MAIS_CARACTERES
EVOLUTION_WEBHOOK_MAX_AGE_SECONDS=86400

N8N_CALLBACK_TOKEN=GERE_OUTRO_TOKEN_FORTE_COM_32_OU_MAIS_CARACTERES
N8N_WEBHOOK_MAX_AGE_SECONDS=300
```

## Testes

```bash
php tests/Feature/ent026-tests-sanitization-smoke.php
php tests/Feature/ent028-webhook-security-smoke.php
php tests/Feature/ent028-pagbank-auth-hotfix-smoke.php
php tests/Feature/ent028-pagbank-taxid-hotfix-smoke.php
php tests/Support/run-smoke-tests.php
```

## Implantação

Consulte:

- `INSTRUCOES-v36.20.13.2.md`;
- `INSTRUCOES-v36.20.13.3.md`;
- `docs/ATUALIZACAO-v36.20.13.2.md`;
- `docs/ATUALIZACAO-v36.20.13.3.md`;
- `docs/diagnostics/ENT-028-WEBHOOK-SECURITY-v36.20.13.md`;
- `docs/diagnostics/ENT-028-VALIDATION-v36.20.13.md`.

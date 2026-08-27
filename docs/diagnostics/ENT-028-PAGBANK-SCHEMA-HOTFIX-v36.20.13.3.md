# Validação técnica — ENT-028 PagBank schema hotfix v36.20.13.3

## Causa raiz

`PaymentGatewayService::saveInvoiceGatewayResult()` atualizava sempre `payment_status_checked_at`, mas o banco informado pela produção não possuía essa coluna. O endpoint remoto já havia respondido com sucesso; a falha acontecia somente na persistência local.

## Arquivos principais

- `app/Services/PaymentGatewayService.php`
- `app/Services/AppVersionService.php`
- `database/migrations/088_payment_reconciliation_schema_compat.sql`
- `tests/Feature/ent028-pagbank-schema-hotfix-smoke.php`

## Estratégia

1. Corrigir definitivamente o banco por migration idempotente.
2. Montar os trechos opcionais do SQL somente quando a coluna existe.
3. Aplicar o mesmo cuidado às colunas históricas `external_imported_at` e `access_released_at`.
4. Não criar nem alterar valores financeiros.

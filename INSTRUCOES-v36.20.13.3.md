# Implantação — RS Connect v36.20.13.3

## Objetivo

Corrigir a persistência local do Checkout PagBank quando o banco de produção não possui a coluna `tenant_invoices.payment_status_checked_at` prevista na migration histórica 042.

## Ordem segura de implantação

1. Faça backup do banco e da pasta atual da aplicação.
2. Importe a migration:

```text
database/migrations/088_payment_reconciliation_schema_compat.sql
```

3. Confirme as colunas:

```sql
SHOW COLUMNS FROM tenant_invoices WHERE Field IN (
  'external_imported_at',
  'payment_status_checked_at',
  'access_released_at'
);
```

Devem ser retornadas três linhas.

4. Preserve `.env`, `storage`, uploads e volumes persistentes.
5. Publique a aplicação v36.20.13.3.
6. Faça rebuild/redeploy para limpar OPcache.
7. Gere novamente o link da cobrança.

## Comportamento de compatibilidade

A aplicação detecta a existência de `payment_status_checked_at` antes de montar o `UPDATE`. Isso evita perder um Checkout PagBank válido durante a janela entre a publicação do código e a aplicação da migration.

A migration continua obrigatória para manter conciliação, auditoria e liberação de acesso completas.

## Validação funcional

1. Abra **Planos e cobranças**.
2. Selecione uma cobrança aberta.
3. Gere o link PagBank.
4. Confirme que a tela retorna o link externo, sem erro de coluna.
5. No banco, consulte:

```sql
SELECT
  id,
  invoice_number,
  gateway_provider,
  external_payment_id,
  external_checkout_url,
  external_status,
  payment_status_checked_at
FROM tenant_invoices
ORDER BY id DESC
LIMIT 5;
```

6. Abra o link em janela anônima e confirme a página do PagBank.

## Migration anterior

A migration `087_webhook_security_events.sql` deve continuar aplicada. A v36.20.13.3 adiciona somente a `088_payment_reconciliation_schema_compat.sql`.

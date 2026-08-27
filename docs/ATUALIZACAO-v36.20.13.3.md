# Atualização v36.20.13.3 — schema de conciliação PagBank

## Problema

O Checkout era criado no PagBank, mas a RS Connect falhava ao salvar o retorno localmente:

```text
SQLSTATE[42S22]: Column not found: 1054
Unknown column 'payment_status_checked_at' in 'field list'
```

A coluna já fazia parte da migration 042, porém o banco de produção estava com essa atualização incompleta.

## Correção

- migration idempotente 088 para recompor as três colunas de conciliação;
- índice de busca por provedor e identificador externo;
- fallback de compatibilidade no `PaymentGatewayService`;
- proteção equivalente para importação externa, atualização por webhook e liberação de acesso;
- versão interna atualizada para 36.20.13.3.

Nenhum valor financeiro, plano, assinatura ou status existente é alterado pela migration.

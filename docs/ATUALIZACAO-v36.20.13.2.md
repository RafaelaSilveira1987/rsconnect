# Atualização v36.20.13.2 — PagBank customer.tax_id

## Correção

- valida CPF e CNPJ por dígitos verificadores;
- não envia documento vazio ou inválido ao Checkout PagBank;
- mantém `customer_modifiable=true` para preenchimento pelo comprador;
- repete uma única vez sem `customer.tax_id` se o provedor recusar especificamente esse campo;
- não altera banco, planos, cobrança, webhook ou demais gateways.

## Migration

Nenhuma nova. Permanece `087_webhook_security_events.sql`.

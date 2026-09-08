# RS Connect 36.27.23 — Webhook Asaas: confirmação, recusa e checkoutSession

## Correções

- Eventos `PAYMENT_CONFIRMED` e `PAYMENT_RECEIVED` continuam sendo tratados como pagamento confirmado.
- Eventos `PAYMENT_CREDIT_CARD_CAPTURE_REFUSED`, `PAYMENT_REPROVED_BY_RISK_ANALYSIS` e `PAYMENT_ANTIFRAUD_REPROVED` são tratados como recusa.
- Eventos de análise/autorização permanecem como cobrança aberta/pendente.
- O webhook Asaas agora reconhece `payment.checkoutSession`, usado pelo Asaas em eventos `PAYMENT_*` do Checkout.
- O `payment.id` continua sendo tratado como identificador da cobrança, evitando confundi-lo com o ID do checkout.
- A conciliação financeira tenta primeiro a referência da cobrança e também o `external_payment_id`.
- Inscrições ainda não provisionadas passam para `failed` quando o pagamento é recusado, sem criar a conta.
- Cobranças de assinaturas já provisionadas passam a registrar a recusa como `cancelled`.

## Verificação

Foram executados os testes de regressão do webhook Asaas e os smoke tests do cadastro público/checkout Pix.

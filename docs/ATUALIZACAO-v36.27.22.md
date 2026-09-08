# RS Connect 36.27.22 — Webhook Asaas com confirmação e recusa

## Correções

- `PAYMENT_CONFIRMED` e `PAYMENT_RECEIVED` continuam sendo tratados como `paid`.
- `PAYMENT_CREDIT_CARD_CAPTURE_REFUSED` e `PAYMENT_REPROVED_BY_RISK_ANALYSIS` passam a ser tratados como `cancelled`.
- `PAYMENT_AWAITING_RISK_ANALYSIS`, `PAYMENT_APPROVED_BY_RISK_ANALYSIS`, `PAYMENT_AUTHORIZED` e `PAYMENT_CREATED` passam a ser tratados como `open` enquanto não houver confirmação/recebimento.
- O vínculo da cobrança recebida pelo webhook tenta tanto `externalReference/invoice_number` quanto `payment.id`, evitando perda de conciliação quando a referência externa estiver ausente ou divergente.
- A idempotência continua usando o `id` do evento Asaas, conforme o contrato oficial do provedor.

## Configuração obrigatória no Asaas

O Asaas envia somente os eventos selecionados no Webhook. Configure o endpoint:

`POST /webhooks/payments/asaas`

E selecione, no mínimo:

- `PAYMENT_CREATED`
- `PAYMENT_AWAITING_RISK_ANALYSIS`
- `PAYMENT_APPROVED_BY_RISK_ANALYSIS`
- `PAYMENT_REPROVED_BY_RISK_ANALYSIS`
- `PAYMENT_AUTHORIZED`
- `PAYMENT_CONFIRMED`
- `PAYMENT_RECEIVED`
- `PAYMENT_CREDIT_CARD_CAPTURE_REFUSED`
- `PAYMENT_OVERDUE`
- `PAYMENT_DELETED`

O token configurado no Asaas deve ser o mesmo token salvo no campo de segredo/token do gateway Asaas no RS Connect.

O endpoint deve ser público e responder HTTP 200 após o processamento. Não configure a API Key do Asaas como `authToken`; use um token próprio com pelo menos 32 caracteres.

## Validação

O teste `tests/Feature/payment-asaas-webhook-status-mapping.php` cobre os eventos de confirmação, recebimento, análise, recusa e a conciliação pelo `payment.id`.

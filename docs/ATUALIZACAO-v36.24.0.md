# Atualização v36.24.0

Implementa a jornada pública `login → cadastro → Checkout Asaas → trial → onboarding`, restrita ao Plano Inicial.

## Rotas públicas

- `GET /signup`
- `POST /signup`
- `GET /signup/success`
- `GET /signup/cancelled`
- `GET /signup/expired`
- `GET /signup/status`

## Administração

- `GET /settings/public-signup`
- `POST /settings/public-signup/save`

## Webhook

A integração reutiliza `POST /webhooks/payments/asaas` e processa eventos de Checkout, assinatura e cobrança com o mecanismo de idempotência já existente.

## Eventos Asaas mínimos

Configure o webhook público `/webhooks/payments/asaas` com o mesmo token salvo no gateway e selecione eventos de Checkout, assinaturas e cobranças. O provisionamento usa `CHECKOUT_PAID` ou `SUBSCRIPTION_CREATED`; cobranças futuras sincronizam o estado da assinatura pelos eventos `PAYMENT_*`.

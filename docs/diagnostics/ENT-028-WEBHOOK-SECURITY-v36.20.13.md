# Diagnóstico técnico — ENT-028 / PA-002

## Problemas encontrados

1. `SECURITY_WEBHOOK_STRICT=false` permitia aceitar webhooks quando o segredo estava vazio.
2. A Evolution recebia o token tanto por header quanto por query string.
3. O callback do n8n aceitava token no corpo e não assinava o payload.
4. Os webhooks de pagamento usavam um token genérico e não validavam os mecanismos nativos dos provedores.
5. Não existia uma chave persistente central para deduplicar eventos.
6. Logs financeiros armazenavam payload e corpo bruto.
7. A configuração PagBank não explicava que o Token da API também valida a autenticidade da notificação.

## Solução

Foi criada uma camada central para:

- tornar produção sempre fail-closed;
- validar segredos e rejeitar placeholders;
- validar HMAC interno do n8n;
- validar assinaturas PagBank, Stripe e Mercado Pago;
- validar token oficial Asaas;
- registrar eventos antes da regra de negócio;
- deduplicar por `source + event_key`;
- detectar conflito entre mesma chave e payload diferente;
- permitir retomada controlada de eventos falhos ou travados;
- mascarar credenciais, documentos, dados financeiros e payloads em logs.

## PagBank/PagSeguro

O Checkout usa `POST /checkouts`, Bearer Token e envia `notification_urls` e `payment_notification_urls`. A autenticidade da notificação é comparada com `SHA-256(token + "-" + payload bruto)` e o header `x-authenticity-token`.

Referências oficiais consultadas:

- https://developer.pagbank.com.br/reference/criar-checkout
- https://developer.pagbank.com.br/reference/confirmar-autenticidade-da-notificacao
- https://docs.stripe.com/webhooks
- https://www.mercadopago.com.br/developers/en/docs/your-integrations/notifications/webhooks
- https://docs.asaas.com/docs/sobre-os-webhooks

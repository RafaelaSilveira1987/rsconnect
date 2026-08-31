# RS Connect v36.24.4 — rate limit seguro da inscrição pública

## Problema corrigido

O cadastro público contava todas as sessões criadas na última hora, inclusive tentativas que falharam por erro técnico do Asaas. Além disso, o hash de IP era criado diretamente de `REMOTE_ADDR`, que em produção pode ser o IP interno do proxy do EasyPanel/Traefik.

Com isso:

- cinco erros técnicos bloqueavam o mesmo e-mail;
- todos os visitantes atrás do mesmo proxy podiam compartilhar o mesmo limite;
- abrir aba anônima não resolvia, pois o IP público não muda.

## Comportamento novo

- Usa `RequestSecurity::clientIp()` e respeita `X-Forwarded-For`, `X-Real-IP` ou `CF-Connecting-IP` somente quando o proxy é confiável.
- Sessões com status `failed`, `expired` ou `cancelled` não entram no limite.
- Apenas checkouts realmente iniciados contam: `started`, `checkout_created` e `checkout_completed`.
- O limite é separado por e-mail e por IP.
- Padrões:
  - 5 checkouts por e-mail/hora;
  - 20 checkouts por IP/hora.

## Variáveis opcionais

```env
PUBLIC_SIGNUP_EMAIL_LIMIT_PER_HOUR=5
PUBLIC_SIGNUP_IP_LIMIT_PER_HOUR=20
```

## Atualização

Não há migration nova. Substitua os arquivos e reinicie o serviço.

As tentativas técnicas antigas com status `failed` deixam de bloquear imediatamente após a publicação.

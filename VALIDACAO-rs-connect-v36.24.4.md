# Validação — RS Connect v36.24.4

## Correção

- O cadastro público passa a usar `RequestSecurity::clientIp()` para obter o IP real atrás do EasyPanel/Traefik.
- Cabeçalhos de proxy só são aceitos quando `REMOTE_ADDR` pertence a uma rede configurada como confiável.
- Tentativas com status `failed`, `expired` ou `cancelled` não entram mais no rate limit.
- O limite considera somente sessões que realmente iniciaram checkout: `started`, `checkout_created` e `checkout_completed`.
- Limites separados por e-mail e por IP/rede.
- Padrões configuráveis: 5 por e-mail/hora e 20 por IP/hora.

## Validações executadas

- 109 de 109 testes smoke aprovados.
- 351 arquivos PHP com sintaxe válida.
- 4 arquivos JavaScript com sintaxe válida.
- Manifesto com 101 migrations validado.
- Parser SQL reconheceu 1.988 instruções.
- Nenhuma migration nova.

## Resultado esperado

As tentativas anteriores que falharam por erro técnico do Asaas deixam de bloquear o cadastro imediatamente após a publicação do hotfix, sem limpeza manual no banco.

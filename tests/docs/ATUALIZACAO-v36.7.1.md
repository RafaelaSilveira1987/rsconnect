# RS Connect v36.7.1 — Saudação neutra e notificações por direção

Esta atualização complementa a homologação do atendimento por profissional sem alterar suas regras de atribuição, bloqueio ou liberação.

## Saudação após o login

A mensagem fixa com gênero foi removida. O sistema passa a considerar o horário configurado em `APP_TIMEZONE` e o primeiro nome do usuário autenticado.

Exemplos:

```text
Bom dia, Rafaela!
Boa tarde, João!
Boa noite, Pedro!
```

Faixas usadas:

```text
05:00 até 11:59 → Bom dia
12:00 até 17:59 → Boa tarde
18:00 até 04:59 → Boa noite
```

## Notificações das conversas

A atualização em tempo real agora separa as mensagens pela direção registrada no banco:

```text
direction = incoming → Nova mensagem recebida
direction = outgoing → não dispara alerta de recebimento
```

Quando o usuário envia pelo compositor da conversa, a confirmação continua sendo:

```text
Mensagem enviada.
```

Mensagens de saída adicionadas ao histórico pelo próprio atendente, por outro usuário, pela IA ou por automações não são apresentadas como se tivessem sido recebidas do cliente.

## Instalação

Não há migration nova. Aplique o patch sobre a `v36.7.0`, faça o deploy da branch de homologação e atualize o navegador com `Ctrl + F5`.

## Homologação sugerida

1. Entre pela manhã, tarde e noite e confirme a saudação correspondente com o primeiro nome.
2. Envie uma mensagem manual e confirme apenas `Mensagem enviada.`.
3. Aguarde uma mensagem real do contato e confirme `Nova mensagem recebida.`.
4. Envie uma resposta por IA e confirme que não aparece alerta de mensagem recebida.
5. Faça outro usuário responder e confirme que a atualização da conversa não é classificada como recebimento do cliente.

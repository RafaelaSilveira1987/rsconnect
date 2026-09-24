# Hotfix 36.36.12 — retomada pós-horário

## Problema reproduzido

1. Cliente envia mensagens fora do expediente.
2. RS Connect envia apenas o aviso operacional de ausência.
3. Na abertura, a primeira resposta conversacional não apresenta o assistente.
4. Em algumas execuções concorrentes, a mesma pergunta de triagem pode ser enviada novamente sem nova mensagem do cliente.

## Causas

- O aviso `after_hours_message` era contado como resposta anterior, desativando a apresentação da primeira resposta real.
- O monitor usava o log mais recente da mensagem para decidir se a recuperação terminou. Logs auxiliares gravados depois de `ai.replied` podiam ocultar o evento terminal.
- O envio final da IA não possuía uma última barreira de deduplicação entre monitor pós-horário e reprocessamento.

## Resultado esperado

Com horário de abertura às 09:00:

- 08:31: cliente envia `Olá bom dia`.
- 08:31: sistema envia somente o aviso de ausência.
- 08:32: cliente complementa o pedido.
- após 09:00: a primeira resposta conversacional deve conter a apresentação configurada da atendente e a pergunta correta da triagem.
- executar novamente o monitor sem nova mensagem do cliente não pode reenviar a mesma pergunta.
- após nova resposta do cliente, o fluxo segue para o próximo campo normalmente.

## Banco de dados

Não há migration nova.

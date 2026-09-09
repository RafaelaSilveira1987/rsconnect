# RS Connect 36.28.8 — Continuidade após restrições de agenda

Esta atualização corrige um caso em que uma conversa podia continuar aparecendo como aguardando resposta depois de uma regra como idade mínima ter bloqueado apenas a agenda.

## Causa

A triagem reutilizava até oito mensagens recebidas antigas para detectar intenção. Assim, depois de `quero agendar` + `idade abaixo do limite`, uma mensagem posterior como `quero uma indicação` ainda podia herdar a intenção antiga de agenda.

Além disso, o serviço de mensagens automáticas deduplicava um texto igual por 90 segundos mesmo quando já havia chegado uma nova mensagem do cliente. Quando o fluxo era terminal para aquele turno, isso podia deixar a nova entrada sem qualquer saída registrada.

## Correções

- O contexto determinístico considera somente o bloco de mensagens recebidas após a última saída da conversa.
- Uma sessão com restrição apenas de agenda volta para `conversation` em mensagens comuns posteriores.
- Uma nova tentativa explícita de agenda continua bloqueada pela regra, inclusive quando o lead informa apenas dia/horário.
- A deduplicação só elimina uma segunda tentativa do mesmo processamento; uma nova entrada do cliente pode receber novamente a resposta necessária.
- O botão `Tentar respostas novamente` pode reprocessar as conversas que ficaram pendentes antes desta correção.

Não há migration de banco nesta versão.

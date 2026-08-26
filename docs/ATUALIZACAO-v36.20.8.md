# Atualização v36.20.8 — prévia de exclusão sem bloqueio

A consulta periódica do status da Evolution mantinha a sessão PHP bloqueada durante a chamada externa. Quando o usuário abria a exclusão nesse intervalo, a prévia podia ficar aguardando a liberação da sessão e o botão permanecia desabilitado.

A correção libera a sessão antes das chamadas externas, pausa o polling com a gaveta aberta e exibe timeout/erros de forma visível.

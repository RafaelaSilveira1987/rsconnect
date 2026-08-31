# RS Connect v36.21.2 — Retomada automática da IA

## Problema corrigido

Quando o assistente possuía **Tempo de espera da IA** maior que zero, a mensagem era preservada como pendente e dependia da chamada frequente ao endpoint da fila rápida. Sem essa rotina externa ativa, a primeira resposta podia funcionar, mas as mensagens seguintes só eram atendidas depois de clicar em **Reprocessar**.

## Correção

- o webhook continua respeitando o tempo configurado para agrupar mensagens;
- a Evolution recebe HTTP 200 antes da espera e da chamada ao provedor;
- um worker CLI interno retoma automaticamente a conversa;
- se outra mensagem chegar durante a espera, o worker anterior é descartado e somente a mais recente continua;
- respostas humanas, takeover, mudança para modo humano e respostas já enviadas continuam bloqueando a IA;
- a fila rápida externa permanece como recuperação adicional, mas deixa de ser obrigatória para o atendimento normal.

## Publicação

Não existe nova migration. Substitua os arquivos pelo pacote v36.21.2 e reinicie/reimplante o serviço da aplicação.

Valide com um assistente configurado com 10 a 15 segundos de espera:

1. envie uma mensagem;
2. aguarde o tempo configurado;
3. confirme a resposta automática;
4. envie duas mensagens seguidas;
5. confirme que existe somente uma resposta, considerando as duas mensagens.

# Hotfix 36.36.7-r2 — triagem de idade e identificação inicial

Correções focadas no atendimento conversacional. Não há migration nova.

## Corrigido

- quando o campo atual da triagem é **idade**, respostas curtas como `30` passam a ser interpretadas como idade válida;
- respostas aproximadas como `mais de 30` não são convertidas em uma idade inventada: o sistema pede a **idade exata** uma única vez, com mensagem específica, em vez de repetir a pergunta genérica;
- a barreira final da IA também impede a repetição mecânica da pergunta de idade quando o cliente informou apenas uma faixa aproximada;
- na primeira resposta automática da conversa, quando a política de saudação está ativa, o assistente passa a se identificar usando o **nome público configurado no Agente**;
- a mesma identificação é aplicada às saudações locais que evitam chamada ao provedor de IA;
- o nome do contato não é confundido com o nome do assistente quando são parecidos, por exemplo `Rafa` e `Rafaela`.

## Banco de dados

Nenhuma migration nova. Permanece a migration exigida pelo pacote atual.

## Homologação rápida

1. Inicie uma conversa nova com `Bom dia` e uma pergunta de atendimento.
2. Confirme que a primeira resposta contém a identificação do assistente configurado.
3. Entre no fluxo de agenda até a pergunta de idade.
4. Responda `Mais de 30`.
5. Confirme que a resposta pede a **idade exata**, sem repetir literalmente a pergunta anterior.
6. Responda `30`.
7. Confirme que o fluxo avança para o próximo campo, sem perguntar a idade novamente.

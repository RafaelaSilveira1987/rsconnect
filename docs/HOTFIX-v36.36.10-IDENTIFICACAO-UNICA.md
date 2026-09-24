# RS Connect 36.36.10 — apresentação única do assistente

## Problema observado

Quando o cadastro do agente usava um nome descritivo como `Rafa, Assistente da psicóloga Mariana Bernardes`, mas a saudação configurada já dizia `Aqui é a Rafa, assistente do consultório...`, a camada determinística comparava apenas o texto completo do campo `name`. Como a frase não continha o cadastro inteiro, o sistema acrescentava uma segunda apresentação.

## Correção

`FirstAutomatedReplyService` agora:

- reconhece o nome completo com fronteiras de palavra;
- extrai a parte nominal antes de vírgula/separador para a fala automática;
- reconhece apresentações como `Eu sou Rafa`, `Aqui é a Rafa` e `Rafa, assistente...`;
- não confunde nomes semelhantes (`Rafa` não casa com `Rafaela`);
- mantém a apresentação automática apenas quando nenhuma identificação já existe.

## Homologação sugerida

1. Configure o agente como `Rafa, Assistente da psicóloga Mariana Bernardes`.
2. Configure a saudação como `Olá! Aqui é a Rafa, assistente do consultório da psicóloga Mariana Bernardes.`
3. Inicie uma conversa nova e envie uma mensagem que leve à triagem automática.
4. A primeira resposta deve conter a saudação configurada uma única vez e seguir para a pergunta de triagem, sem adicionar `Eu sou Rafa...` novamente.
5. Teste também um contato chamado `Rafaela`; a assistente ainda deve se apresentar normalmente.

Não há migration nova.

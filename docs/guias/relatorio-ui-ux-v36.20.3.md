# Relatório de revisão de UI/UX — v36.20.3

## Critério adotado

A interface deve ser compreensível e utilizável por uma pessoa iniciante, inclusive um adolescente, sem depender de conhecimento técnico.

## Problemas encontrados

- checkboxes recebendo `width: 100%` dentro de gavetas;
- formulários com muitas colunas em uma única linha;
- botões saindo visualmente do card;
- textos de ajuda muito pequenos;
- espaçamento vertical irregular entre campos;
- barra de salvar usando margem negativa e podendo cobrir conteúdo;
- falta de foco visível consistente para navegação por teclado;
- formulários que não reorganizavam todos os campos em telas menores.

## Correções aplicadas

- tamanho fixo e acessível para checkbox e radio;
- componentes `check-card` para opções importantes;
- grade responsiva comum para gavetas administrativas;
- barra de ações fixa no final da gaveta, sem sobreposição;
- formulários de acompanhamento com ação em linha separada;
- rótulos e textos auxiliares com contraste e tamanho melhores;
- foco visível em botões, campos, seletores e resumos;
- aumento de legibilidade nos painéis de resultado e custo.

## Áreas beneficiadas pelo padrão compartilhado

- chaves de IA;
- meios de pagamento;
- usuários e empresas;
- automações externas;
- conexões do WhatsApp;
- assistentes;
- clientes que precisam de atenção;
- resultados por cliente;
- demais formulários que usam `drawer-form-grid`, `check-field` e `drawer-savebar`.

## Regra para novas telas

1. No máximo duas colunas em formulários comuns de desktop.
2. Uma coluna em telas menores que 900 px.
3. Checkbox nunca deve ocupar o espaço de um campo de texto.
4. O botão principal deve ficar dentro da área visual do formulário.
5. Textos de ajuda não devem depender de fonte minúscula.
6. Toda ação deve ter foco visível por teclado.
7. O usuário deve entender o resultado da ação antes de salvar.

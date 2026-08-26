# Relatório de revisão de UI/UX — v36.20.4

## Problemas observados

As capturas reais do ambiente mostraram quatro padrões principais:

1. barras de salvar fixas cobrindo campos durante a rolagem;
2. formulários internos herdando grades antigas e colocando textos sobre botões;
3. painéis laterais reduzindo demais a área principal;
4. ações pequenas ou sem rótulo claro em cobranças e configurações.

## Soluções aplicadas

### Rodapés das gavetas

A barra de ações passou a fazer parte do fluxo normal do formulário. A pessoa percorre os campos e encontra os botões ao final, sem conteúdo escondido.

### Formulário de acompanhamento

A grade antiga aplicada ao formulário foi neutralizada. Os campos ficam em uma grade própria, a anotação usa a largura completa e as ações ficam abaixo.

### Funil comercial

A agenda foi movida para cima do funil, preservando a largura das etapas e evitando que a coluna de negociação fique parcialmente escondida.

### Situação da cobrança

A ação passou a exibir o rótulo **Situação da cobrança**, um campo maior e o botão **Salvar situação**.

### Responsividade

Em telas menores:

- os formulários ficam em uma coluna;
- os botões ocupam a largura disponível;
- filtros são empilhados;
- o funil mantém rolagem horizontal sem competir com um painel lateral;
- as gavetas ocupam a tela toda.

## Regra para novas telas

Toda nova tela deve ser revisada com estes critérios:

- nenhuma ação pode cobrir um campo;
- campos relacionados devem permanecer visualmente próximos;
- o botão principal deve ter texto que descreva a ação;
- controles devem possuir rótulo visível;
- o uso em 1366 px, 768 px e 390 px deve ser considerado;
- a pessoa deve compreender a ação sem conhecer termos técnicos.

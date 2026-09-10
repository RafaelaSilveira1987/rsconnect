# RS Connect v36.29.6 — Padronização de formulários e responsividade

## Objetivo
Uniformizar a apresentação de formulários, checkboxes, radios, filtros e ações nas telas administrativas e do cliente, preservando integralmente o comportamento existente.

## Alterações
- Padronização de altura, borda, foco, estados `disabled`/`readonly` e placeholders dos campos nativos.
- Checkboxes e radios com dimensões e alinhamento consistentes, mantendo componentes customizados e `accent-color` da marca.
- Grupos de checkbox e opções de onboarding preparados para uma coluna em mobile.
- Formulários de duas/três colunas passam para uma coluna em telas menores.
- Barras de filtro quebram de forma progressiva e ficam empilhadas em mobile.
- Ações de formulário passam a ocupar a largura disponível no mobile.
- Tabelas ficam contidas na viewport com rolagem horizontal quando necessário.
- Popovers e painéis de edição recebem limite de largura baseado na viewport.
- Ajustes de padding, cabeçalho e botões para telas pequenas.

## Segurança funcional
A revisão foi feita prioritariamente em CSS. Não foram renomeados/removidos `name`, `id`, `data-*`, `action`, `method`, campos de formulário ou seletores JavaScript. O JavaScript da aplicação não foi alterado.

## Cache
Os layouts `app`, `guest` e `restricted` passam a carregar `app.css?v=36.29.6`.

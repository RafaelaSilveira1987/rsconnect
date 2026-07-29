# RS Connect v36.8.2 — Visualização de calendário

## Objetivo

Facilitar a leitura da agenda com vários profissionais sem remover a lista operacional existente.

## Visualizações

- **Lista:** pesquisa, detalhes e ações administrativas.
- **Dia:** sequência cronológica de compromissos do dia.
- **Semana:** sete colunas, ideal para barbearias, clínicas e equipes.
- **Mês:** visão geral com até três compromissos por dia e indicador de excedentes.

## Filtros e navegação

Os filtros por empresa, status e profissional são preservados em todas as visualizações. Dia, Semana e Mês possuem período anterior, Hoje e próximo período.

## Preferência

A última visualização escolhida é salva no navegador por cookie e localStorage. Empresas com agenda por profissional iniciam em Semana; as demais iniciam em Lista.

## Segurança operacional

O calendário é somente uma camada visual. Alterações, transferências, confirmações, cancelamentos e exclusões continuam acontecendo na lista, mantendo as validações existentes no backend.

## Banco de dados

Não há migration nova. A migration mínima permanece `066_contact_schedule_overlap_guard_compat.sql`.

## Teste sugerido

1. Abrir Agenda e alternar Lista, Dia, Semana e Mês.
2. Filtrar um profissional.
3. Navegar para o período anterior e seguinte.
4. Selecionar um compromisso e abrir os detalhes.
5. Usar “Abrir na lista” e confirmar o posicionamento no registro.
6. Sair e entrar novamente para validar a preferência salva.

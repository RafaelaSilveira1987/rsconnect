# RS Connect v36.10.2 — Status visual das conversas e recuperação dos ciclos

## Objetivo

Consolidar em um único deploy a migration 069 de recuperação dos ciclos de atendimento e a diferenciação visual das conversas pelo status.

## Comportamento visual

- Aberta: verde.
- Pendente: amarelo.
- Encerrada: cinza.

A cor aparece na lista e no painel selecionado. O rótulo textual permanece visível para acessibilidade e o polling atualiza o estado sem recarregar a página.

## Banco de dados

Execute somente `database/migrations/069_service_cycle_recovery_compat.sql`. Não há migration adicional para o ajuste visual.

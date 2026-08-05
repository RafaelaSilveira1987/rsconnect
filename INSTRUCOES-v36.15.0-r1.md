# RS Connect v36.15.0-r1 — Consistência dos indicadores executivos

## Objetivo

Corrigir divergências entre o painel executivo da RS Admin e o painel da empresa cliente quando ambos analisam a mesma empresa e o mesmo período.

## Regras aplicadas

- primeira resposta executiva usa somente ciclos operacionais;
- `migration_snapshot` e `migration_069_recovery` ficam restritos à auditoria histórica;
- participação da IA usa `IA / (IA + respostas humanas)`;
- mensagens de sistema continuam visíveis separadamente;
- RS Admin usa o nome **Incidentes operacionais**;
- empresa cliente usa o nome **Itens que precisam de atenção**.

## Banco

Não existe migration nova. A última continua sendo:

`074_conversation_message_attachments.sql`

## Validação do caso homologado

Para a empresa 2 no período de 06/07/2026 a 04/08/2026, o retorno esperado é:

- conversas iniciadas: 4;
- primeiras respostas operacionais: 3;
- média: 54 segundos;
- menor: 8 segundos;
- maior: 139 segundos;
- respostas humanas: 39;
- respostas da IA: 17;
- participação da IA: 30,4%.

Execute:

`database/diagnostics/executive_metrics_consistency_v36.15.0-r1.sql`

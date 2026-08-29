# Atualização v36.23.1

Hotfix da migration `092_notification_orchestration.sql`.

## Causa

No `INSERT ... SELECT`, o MySQL podia interpretar o `ON` como cláusula do `CROSS JOIN`, gerando erro 1064 próximo de `KEY UPDATE`.

## Correção

Foi adicionada uma cláusula neutra `WHERE 1 = 1` antes de `ON DUPLICATE KEY UPDATE`, removendo a ambiguidade do parser.

A migration continua idempotente e pode ser executada mesmo quando as duas tabelas já existem.

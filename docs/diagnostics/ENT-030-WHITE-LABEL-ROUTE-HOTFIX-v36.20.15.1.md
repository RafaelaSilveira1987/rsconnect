# ENT-030 — Hotfix White Label v36.20.15.1

## Problema

O `WhiteLabelController` e a view `app/Views/white_label/index.php` estavam presentes, mas `routes/web.php` não importava o controller nem registrava as rotas. Os endereços `/white-label` e `/white_label` caíam no 404.

## Correção

- importado `WhiteLabelController`;
- registrada rota canônica `/white-label`;
- registrado alias legado `/white_label`;
- restauradas as rotas de salvamento e pré-visualização;
- incluído o item `Marca dos clientes` no menu do Super Admin;
- mantida proteção `auth + super_admin`;
- mantido CSRF no salvamento;
- preservadas as restrições de upload da ENT-030.

## Validação

- 317 arquivos PHP sem erro de sintaxe;
- 3 arquivos JavaScript sem erro;
- teste do hotfix: 8 aprovações;
- teste ENT-030: 24 aprovações;
- `/white_label` sem login: HTTP 302 para `/login`, confirmando que a rota existe;
- `/white-label` sem login: HTTP 302 para `/login`, confirmando que a rota existe;
- suíte completa: 83 aprovados e 9 falhas históricas.

## Banco

Nenhuma migration nova. A migration vigente permanece `088_payment_reconciliation_schema_compat.sql`.

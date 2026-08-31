# RS Connect v36.21.1 — Hotfix do executor de migrations

## Problema corrigido

O comando `php bin/migrate.php up` podia falhar com o erro MySQL 2014 quando uma migration usava `PREPARE/EXECUTE` e o SQL dinâmico retornava uma linha de controle (`SELECT 1`). O executor agora consome e fecha todos os result sets antes de seguir para a próxima instrução.

## Publicação

1. Substitua os arquivos da aplicação pelo pacote v36.21.1.
2. Não execute `baseline`; o banco já possui histórico válido.
3. Execute:

```bash
php bin/migrate.php verify
php bin/migrate.php status
php bin/migrate.php up
php bin/migrate.php status
```

## Resultado esperado

```text
[OK] 1 migration(s) executada(s):
- 090_crm_conversation_automation.sql

Resumo: 97 aplicada(s), 0 pendente(s), 0 divergente(s).
```

A migration 090 é idempotente. Caso a tentativa anterior tenha criado parcialmente tabelas, colunas ou índices antes do erro, a nova execução valida a existência de cada estrutura e conclui o registro normalmente.

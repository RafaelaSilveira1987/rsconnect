# Migrations da RS Connect

A ordem oficial das migrations não é inferida apenas pelo prefixo numérico. O arquivo `manifest.php` é a fonte de verdade porque o histórico contém números repetidos.

## Regras

1. Nunca edite uma migration que já tenha sido aplicada em produção.
2. Toda migration nova deve ser adicionada no final de `manifest.php`, com uma sequência única e crescente.
3. Arquivos de rollback devem permanecer em `rollbacks` e não podem entrar no fluxo de subida.
4. Novas migrations devem ser idempotentes sempre que possível.
5. Evite `USE nome_do_banco`; o executor trabalha sempre sobre `DB_DATABASE`.
6. Antes de publicar, execute:

```bash
php bin/migrate.php verify
php bin/migrate.php status
php bin/migrate.php up --dry-run
```

## Comandos

```bash
php bin/migrate.php verify
php bin/migrate.php status
php bin/migrate.php install --yes
php bin/migrate.php baseline --through=088 --yes
php bin/migrate.php up
php bin/migrate.php seed --yes
```

- `install`: somente banco vazio.
- `baseline`: adota um banco existente depois de validar sua estrutura.
- `up`: executa somente migrations pendentes.
- `seed`: reconcilia dados de referência depois de todas as migrations.

## Observação MySQL

DDL em MySQL pode realizar commit implícito. Em caso de falha no meio de uma migration, não repita cegamente: confira a instrução indicada, valide o schema e somente então execute novamente.

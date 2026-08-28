# ENT-027 / PA-005 — Normalização das migrations

## Problema confirmado

O diretório possuía 97 arquivos SQL, sendo 96 migrations de subida e um rollback. O histórico continha prefixos repetidos em 017, 018, 019, 020, 021, 022, 023 e 063. O Docker executava apenas `schema.sql`, `seed.sql` e quatro migrations antigas, deixando instalações novas inconsistentes.

Também não existia tabela oficial de controle. O sistema dependia de verificações dispersas de tabelas e de execução manual de arquivos.

## Solução

### Manifesto canônico

`database/migrations/manifest.php` define uma sequência única de 1 a 96. Os nomes históricos não foram alterados.

### Registro persistente

A migration `089_schema_migrations_registry.sql` cria `schema_migrations` com:

- sequência canônica;
- nome do arquivo;
- SHA-256;
- lote;
- origem;
- tempo de execução;
- executor;
- data UTC.

### Executor

`bin/migrate.php` oferece:

- `verify`;
- `status`;
- `install`;
- `baseline`;
- `up`;
- `seed`;
- `bootstrap`.

O executor utiliza `GET_LOCK` no MySQL, recusa checksum ou sequência divergente, não muda de banco por comandos `USE` e para na primeira falha.

### Banco existente

O baseline automático é aceito apenas para estruturas na migration 085 ou superior. Para a produção atual, `--through=088` verifica as tabelas centrais, as colunas comerciais da 086, a tabela de webhook da 087 e as colunas/índice de conciliação da 088.

### Banco vazio

O snapshot executável representa o núcleo até a migration 004. A instalação cria o tenant de demonstração, executa 005–088 na ordem canônica, registra 089 e só então reconcilia os dados de referência.

### Docker

O MySQL não importa mais migrations isoladas via `/docker-entrypoint-initdb.d`. O serviço `migrate` é a única autoridade de instalação e precisa terminar antes do serviço web.

### Readiness

`/health/ready` passa a exigir todas as entradas do manifesto registradas. A resposta pública continua sem detalhes internos.

## Segurança e preservação

- nenhuma migration histórica foi renomeada;
- nenhum dado real foi alterado durante o desenvolvimento;
- a migration 089 é aditiva;
- o rollback 030 continua isolado;
- arquivos aplicados não podem ser editados sem gerar drift;
- o baseline não executa novamente as migrations antigas.

# RS Connect v36.20.16 — ENT-027 / PA-005

## Normalização das migrations

Esta versão cria uma ordem canônica, registra checksums e impede que o Docker inicie a aplicação antes de concluir a preparação do banco.

## Atualização da produção atual — banco já na migration 088

1. Faça backup completo do banco.
2. Preserve `.env`, `storage`, uploads e volumes persistentes.
3. Publique o código da v36.20.16.
4. Abra o Shell do contêiner no EasyPanel.
5. Execute:

```bash
cd /var/www/html
php bin/migrate.php verify
php bin/migrate.php baseline --through=088 --yes
php bin/migrate.php status
```

Resultado esperado:

```text
96 aplicada(s), 0 pendente(s), 0 divergente(s)
```

O comando `baseline` não reaplica as migrations 002–088. Ele valida tabelas, colunas e índices da versão atual, cria `schema_migrations` e registra o histórico existente.

Se o baseline recusar alguma tabela, coluna ou índice, não force o registro. Corrija a estrutura indicada antes de continuar.

## Instalação em banco vazio

```bash
php bin/migrate.php install --yes
php bin/migrate.php status
```

O instalador executa:

```text
schema base até 004
→ seed inicial
→ migrations 005 a 088 na ordem do manifesto
→ registro 089
→ reconciliação final dos dados de referência
```

## Atualizações futuras

```bash
php bin/migrate.php verify
php bin/migrate.php status
php bin/migrate.php up --dry-run
php bin/migrate.php up
php bin/migrate.php status
```

## Docker Compose local

O serviço `migrate` executa `bootstrap --yes` e precisa terminar com sucesso antes do serviço `app` iniciar.

O MySQL não recebe mais dezenas de arquivos em `/docker-entrypoint-initdb.d`; a instalação passa por um único executor.

## Readiness

Enquanto o registro não estiver completo, `/health/ready` retorna HTTP 503 com resposta pública mínima. O liveness `/health/live` continua disponível.

## Rollback

A migration 089 é apenas aditiva. Em rollback de código:

1. restaure a pasta da versão anterior;
2. preserve o banco e a tabela `schema_migrations`;
3. não apague dados nem execute o arquivo de rollback 030 automaticamente;
4. restaure o banco apenas se uma migration futura tiver alterado dados e houver backup validado.

## Comandos de diagnóstico

```bash
php bin/migrate.php verify
php bin/migrate.php status
```

Para reconciliar somente dados de referência após uma instalação interrompida:

```bash
php bin/migrate.php seed --yes
```

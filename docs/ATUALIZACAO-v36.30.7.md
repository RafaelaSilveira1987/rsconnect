# Atualização RS Connect v36.30.7 — infraestrutura de instalação

Esta versão não altera regras de atendimento nem o banco. Ela corrige a infraestrutura de distribuição do projeto para que uma instalação limpa seja reproduzível.

## Corrigido

- `composer.json` volta a ser JSON válido;
- `.env.example`, `.env.local.example` e `.env.vps.example` voltam a ser arquivos de ambiente;
- `.dockerignore` e `.gitignore` voltam a ser listas de exclusão;
- `docker-compose.yml` volta a subir aplicação + migration runner + MySQL;
- `manifest.json` volta a ser JSON válido;
- `build-full-release.sh` volta a ser um script Bash executável;
- Dockerfile instala as extensões PHP necessárias e valida o manifesto de migrations no build.

## Banco oficial

O RS Connect utiliza **MySQL/MariaDB** por meio de `pdo_mysql`. O DSN canônico da aplicação é `mysql:`. PostgreSQL não faz parte da infraestrutura atual e não deve ser configurado no deploy desta versão.

## Migration

Não há migration nova. A última obrigatória continua sendo:

```text
109_evolution_instance_identity_cleanup.sql
```

## Atualização de uma instalação existente

Preserve o `.env` real e o volume/pasta `storage`. Depois do deploy:

```bash
php bin/check-requirements.php
php bin/migrate.php verify
php bin/migrate.php status
php bin/migrate.php up
php bin/migrate.php status
```

## Instalação limpa

Consulte `docs/INSTALACAO-E-TESTES.md`.

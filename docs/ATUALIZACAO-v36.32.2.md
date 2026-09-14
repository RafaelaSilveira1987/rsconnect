# Atualização para RS Connect 36.32.2

Esta versão corrige somente o cálculo do SLA executivo. Não há migration nova.

## Aplicar
```bash
php bin/migrate.php verify
php bin/migrate.php up
php bin/migrate.php verify
docker compose restart app
```

O manifesto deve continuar com 120 migrations.

## Motivo técnico
O PDO MySQL do projeto usa `PDO::ATTR_EMULATE_PREPARES => false`. A query anterior reutilizava `:sla_seconds` em duas expressões do mesmo statement, causando `HY093` em runtime. A 36.32.2 usa placeholders distintos e não deixa uma falha de duração/espera zerar o SLA.

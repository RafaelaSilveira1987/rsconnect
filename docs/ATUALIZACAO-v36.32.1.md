# RS Connect 36.32.1 — Hotfix de relatório e SLA humano

Esta atualização corrige dois problemas encontrados na homologação real da Fase A:

1. o filtro do relatório tratava as datas informadas pelo usuário como UTC, fazendo interações noturnas do Brasil aparecerem somente ao incluir o dia seguinte;
2. o card de tempo médio podia contar um ciclo com `first_response_at`, enquanto o SLA exigia também `first_response_user_id`, produzindo situações como “1 resposta medida” e “SLA 0/0”.

## Atualização

```bash
php bin/migrate.php verify
php bin/migrate.php up
php bin/migrate.php verify
docker compose restart app
```

O manifesto esperado após o update é **120 migrations**.

## Migration

`113_human_first_response_report_consistency.sql`

Ela repara ciclos a partir da primeira mensagem humana atribuída e adiciona proteção para a corrida entre o webhook da Evolution e o envio humano do painel.

## Validação

Siga `TESTE_DA_VERSAO.md`. O teste principal é manter o filtro terminando no próprio dia local e confirmar que tempo médio e SLA usam o mesmo denominador.

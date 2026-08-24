# Atualização RS Connect 36.6.7

## Objetivo
Refinar a leitura operacional para diferenciar decisão de configuração, ausência de evidência e falha real.

## Alterações principais
- Assistente inativo ou com resposta automática desligada passa a ser informativo quando a configuração foi intencional.
- O diagnóstico tenta identificar se a alteração foi feita pelo cliente, pela equipe RS ou se a autoria não está disponível.
- Falhas históricas continuam visíveis, mas não deixam o assistente crítico enquanto ele estiver desligado manualmente.
- Assistente habilitado com falhas consecutivas continua crítico e passa a mostrar `Indisponível por erro`.
- `N8N_CALLBACK_TOKEN` e `AI_REPROCESS_CRON_TOKEN` passam a ser recomendações contextuais, não falsos alertas de indisponibilidade.
- Gateway ativo sem eventos recentes passa a `Sem evidência`, deixando claro que ausência de transação não comprova falha.

## Banco de dados
Se a 36.6.6 já foi aplicada, execute apenas:

```sql
SOURCE database/migrations/051_operational_evidence_status.sql;
```

Se estiver vindo da 36.6.5 ou anterior, aplique primeiro a correção anterior e depois esta migration:

```sql
SOURCE database/migrations/050_human_takeover_customer_context.sql;
SOURCE database/migrations/051_operational_evidence_status.sql;
```

A migration 051 apenas amplia o ENUM de `system_health_checks.status` com o valor `unknown`.

## Após aplicar
1. Abra Operação RS e execute **Verificar sistema agora**.
2. Abra a Saúde da empresa e execute **Verificar agora**.
3. Confira um assistente desativado manualmente e outro ativo.

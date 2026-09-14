# ROLLBACK — RS Connect 36.34.1

A 36.34.1 não cria migration nova. Para rollback de aplicação, restaure os arquivos da 36.34.0 e reinicie o container/app.

A migration `115_sla_operational_policy.sql` deve permanecer aplicada, pois pertence à Fase C e é compartilhada entre 36.34.0 e 36.34.1.

## Procedimento sugerido

```bash
# restaure os arquivos da 36.34.0
docker compose restart app
php bin/migrate.php verify
```

Não altere manualmente o ENUM `ai_agents.handoff_action`: os valores válidos continuam sendo `paused` e `human`.

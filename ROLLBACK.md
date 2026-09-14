# Rollback — RS Connect 36.34.3

A 36.34.3 não cria migration. Para rollback de aplicação, restaure os arquivos da 36.34.2 e reinicie o app. A migration `116_sla_trigger_mysql_compat.sql` deve permanecer aplicada.

> Atenção: voltar para 36.34.2 reintroduz a incompatibilidade de leitura do expediente compacto do onboarding.

---

# Rollback — RS Connect 36.34.2

A migration `116_sla_trigger_mysql_compat.sql` corrige um trigger que bloqueia o recebimento de mensagens no MySQL. **Não é recomendado remover a migration 116 enquanto a Fase C estiver ativa.** Se for necessário voltar os arquivos para 36.34.1, mantenha a migration 116 aplicada no banco para não reintroduzir o erro 1221.

---

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

# ROLLBACK — RS Connect 36.35.0

A 36.35.0 não possui migration nova. O banco permanece compatível com a 36.34.4.

## Retorno

1. Faça backup dos arquivos atuais.
2. Restaure os arquivos da 36.34.4.
3. Execute:

```bash
php bin/migrate.php verify
docker compose restart app
```

Não execute rollback de banco: a última migration continua sendo `116_sla_trigger_mysql_compat.sql`.

A tela **Carga operacional** deixa de existir ao restaurar a 36.34.4; conversas, responsáveis e métricas de SLA não são alterados por esse rollback.


---

# Histórico de rollback das versões anteriores

# Rollback — RS Connect 36.34.4

A 36.34.4 não cria migration. Para rollback de aplicação, restaure os arquivos da 36.34.3 e reinicie o app. A migration `116_sla_trigger_mysql_compat.sql` deve permanecer aplicada.

> Atenção: voltar para 36.34.3 reintroduz o risco de o LLM repetir uma mensagem histórica de ausência mesmo quando `AgentOperatingPolicyService` já considera o expediente aberto.

---

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

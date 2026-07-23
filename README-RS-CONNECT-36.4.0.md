# RS Connect 36.4.0 — Fundação dos Relatórios Executivos

Esta etapa prepara o motor de dados dos novos relatórios sem alterar o layout atual.

## O que foi implantado

- `database/migrations/048_reporting_metrics_foundation.sql`
- `app/Services/ReportingAggregationService.php`
- `app/Services/TenantExecutiveReportService.php`
- revisão de `app/Services/AdminExecutiveReportService.php`
- refatoração segura de `app/Controllers/ReportController.php`
- `scripts/reporting-metrics.php`
- `database/diagnostics/diagnostic_reporting_metrics_048.sql`

A fonte de verdade continua nas tabelas operacionais. `report_daily_metrics` é uma tabela derivada e pode ser reconstruída.

## Correções de métricas incluídas

1. O filtro administrativo de empresa usa `tenants.id`; não tenta mais consultar `tenants.tenant_id`.
2. Uso por empresa considera conversas iniciadas dentro do período selecionado.
3. Atendimento humano considera mensagens de saída com `sender_type = user` dentro do período.
4. Participação da IA considera respostas de saída da IA.
5. Conversão do CRM do cliente usa a mesma coorte: oportunidades criadas no período e quantas dessas estão ganhas.
6. Status de agenda continua vindo da tabela operacional para refletir a situação atual do compromisso.
7. Séries de mensagens passam a poder ser atendidas pela camada agregada diária.

## Aplicação na VPS

Depois do deploy do código:

```bash
MYSQL_CONTAINER="$(docker ps --filter label=com.docker.swarm.service.name=sites_mysql --format '{{.ID}}' | head -n 1)"
MIGRATION="/etc/easypanel/projects/sites/rsconnect/code/database/migrations/048_reporting_metrics_foundation.sql"

docker exec -i "$MYSQL_CONTAINER" sh -lc '
DB="${MYSQL_DATABASE:-rs_connect}"
if [ -n "${MYSQL_ROOT_PASSWORD:-}" ]; then
    export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
    exec mysql -uroot "$DB"
fi
export MYSQL_PWD="$MYSQL_PASSWORD"
exec mysql -u"$MYSQL_USER" "$DB"
' < "$MIGRATION"
```

## Backfill inicial recomendado

Primeiro faça 30 dias, que é o período padrão do relatório:

```bash
docker exec $(docker ps --filter label=com.docker.swarm.service.name=sites_rsconnect --format '{{.ID}}' | head -n 1) \
  php /var/www/html/scripts/reporting-metrics.php \
  --start="$(date -d '29 days ago' +%F)" \
  --end="$(date +%F)"
```

O retorno é JSON e deve trazer `"ok":true`.

Para uma empresa específica:

```bash
docker exec $(docker ps --filter label=com.docker.swarm.service.name=sites_rsconnect --format '{{.ID}}' | head -n 1) \
  php /var/www/html/scripts/reporting-metrics.php \
  --start="2026-07-01" \
  --end="2026-07-31" \
  --tenant=2
```

## Validação SQL

```bash
MYSQL_CONTAINER="$(docker ps --filter label=com.docker.swarm.service.name=sites_mysql --format '{{.ID}}' | head -n 1)"
DIAG="/etc/easypanel/projects/sites/rsconnect/code/database/diagnostics/diagnostic_reporting_metrics_048.sql"

docker exec -i "$MYSQL_CONTAINER" sh -lc '
export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
mysql -uroot rs_connect --table
' < "$DIAG"
```

Confirme:

- tabela `report_daily_metrics` existente;
- índice único `tenant_id,metric_date`;
- linhas para o período processado;
- mensagens, conversas e contatos maiores ou iguais a zero;
- `refreshed_at` atualizado.

## Validação funcional

Abra `/reports` como cliente e como Super Admin.

Nesta etapa o visual deve permanecer praticamente igual, mas:

- números de mensagens passam a usar o cache diário quando disponível;
- filtro por empresa do Super Admin não deve gerar aviso de coluna inexistente;
- uso por empresa respeita o período;
- atendimento humano respeita o período;
- filtros continuam isolados por `tenant_id`.

## Rollback

O código possui fallback para as consultas operacionais caso a migration 048 ainda não esteja aplicada.

Para remover somente a camada derivada:

```sql
DROP TABLE IF EXISTS report_daily_metrics;
```

Não remova nem altere as tabelas operacionais durante um rollback do módulo de relatórios.

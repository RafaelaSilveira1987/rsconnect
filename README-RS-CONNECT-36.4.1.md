# RS Connect 36.4.1 — Relatórios Executivos Visuais

A v36.4.1 é a camada visual e analítica construída sobre a fundação de métricas da v36.4.0 / migration 048.

## Cliente

A rota `/reports` passa a exibir:

- KPIs com comparação ao período anterior;
- gráfico de linhas com mensagens totais, recebidas e respostas da IA;
- mapa de calor por dia da semana e horário;
- distribuição IA x equipe;
- respostas humanas por responsável;
- funil de CRM;
- funil de agenda: solicitações → horários oferecidos → escolhidos → confirmados → concluídos;
- insights automáticos por regras, sem custo de IA generativa.

## Super Admin RS

A mesma rota, para Super Admin, mostra:

- saúde consolidada das empresas;
- KPIs do SaaS com comparação de período;
- gráfico diário de mensagens;
- ranking das empresas com maior uso;
- clientes com pouco uso;
- tendência de falhas de IA, n8n e Google Agenda;
- receita e cobranças;
- agenda;
- pipeline comercial RS;
- insights executivos.

## Segurança e privacidade

O relatório do cliente continua preso ao `tenant_id` autenticado.
O relatório administrativo trabalha com indicadores agregados e não mostra o conteúdo das mensagens das conversas.

## Banco

Não existe migration 049 para esta versão. A estrutura necessária é a migration:

```text
048_reporting_metrics_foundation.sql
```

Valide antes do deploy visual:

```sql
SELECT COUNT(*) AS linhas FROM report_daily_metrics;
```

O backfill de 30 dias pode ser atualizado com:

```bash
docker exec $(docker ps --filter label=com.docker.swarm.service.name=sites_rsconnect --format '{{.ID}}' | head -n1) \
  php /var/www/html/scripts/reporting-metrics.php \
  --start="$(date -d '29 days ago' +%F)" \
  --end="$(date +%F)"
```

## Validação funcional

1. Abrir `/reports` como cliente.
2. Alterar o período e confirmar que cards e gráficos mudam.
3. Confirmar que o mapa de calor mostra somente dados da empresa autenticada.
4. Abrir `/reports` como Super Admin.
5. Filtrar uma empresa e depois voltar para Toda a operação.
6. Confirmar ausência de erros no `storage/logs/app.log`.
7. Testar impressão do relatório.

## Arquivos principais alterados

- `app/Services/TenantExecutiveReportService.php`
- `app/Services/AdminExecutiveReportService.php`
- `app/Views/reports/index.php`
- `app/Views/reports/admin.php`
- `public/assets/js/reports.js`
- `public/assets/css/reports.css`
- `CHANGELOG.md`


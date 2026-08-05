# RS Connect v36.15.1 — Relatórios automáticos, PDF e WhatsApp

Esta versão inclui a correção v36.15.0-r1 de consistência dos indicadores e adiciona geração manual e programada de relatórios em PDF.

## Migration

Execute:

`database/migrations/075_scheduled_reports_and_deliveries.sql`

A migration cria as programações, destinatários, arquivos gerados e histórico de entregas.

## Armazenamento persistente

Configure no EasyPanel:

```env
SCHEDULED_REPORTS_PATH=/var/www/html/storage/generated-reports
SCHEDULED_REPORTS_CRON_TOKEN=gere_um_token_forte
SCHEDULED_REPORTS_WHATSAPP_TIMEOUT=45
```

Monte um volume persistente em:

`/var/www/html/storage/generated-reports`

## Execução automática

Importe no n8n:

`docs/n8n_templates/template-relatorios-automaticos.json`

Substitua o domínio e o token. Execute a cada 15 minutos. O endpoint possui deduplicação por programação e período.

Alternativa pela linha de comando:

```bash
php /var/www/html/bin/scheduled-reports.php
```

## Homologação

1. Abra **Relatórios → Relatórios automáticos**.
2. Gere um PDF manual e confira identidade, período e indicadores.
3. Gere a versão RS Admin e a versão da empresa.
4. Cadastre um destinatário de homologação e envie pelo WhatsApp.
5. Cadastre programações diária, semanal e mensal.
6. Execute o endpoint duas vezes e confirme que o mesmo período não gera duplicidade.
7. Teste visualizar, baixar e reenviar.
8. Confirme que uma empresa não acessa o PDF de outra.

## Banco

A última migration passa a ser `075_scheduled_reports_and_deliveries.sql`.

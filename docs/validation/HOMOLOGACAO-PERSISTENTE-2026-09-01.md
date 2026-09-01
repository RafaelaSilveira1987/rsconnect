# Homologação persistente — 2026-09-01

Este pacote incorpora ao código-fonte as correções validadas na VPS.

## Correções persistidas

1. `AiReprocessService::runScheduledIfDue()`
   - não grava `last_scheduled_run_on` antes da execução;
   - usa `last_scheduled_claimed_at` durante a reivindicação;
   - limpa a reivindicação quando a fila está ocupada;
   - limpa a reivindicação em exceções;
   - grava a data somente após `runAll()` concluir.

2. `scripts/rsconnect-backup.sh`
   - `finish_error()` passa a encerrar com `exit 1`;
   - cron, n8n e monitoramento podem detectar falhas pelo status do processo.

## Pós-deploy

```bash
cd /var/www/html
bash scripts/verify-deploy-hardening.sh /var/www/html
```

## Fora do escopo

Testes dependentes da instância `gestaodetempo`.
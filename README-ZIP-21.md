# RS Connect — ZIP 21

## Backup, Monitoramento e Recuperação

Este pacote adiciona uma camada operacional para o Super Admin RS acompanhar saúde do sistema, backup e incidentes.

## O que foi incluído

- Novo menu Super Admin: **Monitoramento**.
- Rotas:
  - `/operations`
  - `/monitoramento`
- Painel com verificação de:
  - Banco de dados;
  - Evolution API;
  - n8n;
  - OpenAI/IA;
  - Webhooks recentes;
  - Pagamentos;
  - Cron da régua de cobrança;
  - Último backup.
- Registro manual de backup.
- Webhook opcional para registrar backup externo:
  - `/webhooks/operations/backups?token=SEU_TOKEN`
- Histórico de backups.
- Incidentes operacionais.
- Planos rápidos de recuperação para falhas comuns.

## Migration

Execute no Adminer:

```sql
database/migrations/023_operations_monitoring_backup.sql
```

## Variáveis opcionais

```env
OPERATIONS_BACKUP_TOKEN=um-token-longo-para-o-webhook-de-backup
OPERATIONS_BACKUP_MAX_AGE_HOURS=24
N8N_BASE_URL=https://n8n.seudominio.com
```

Se `OPERATIONS_BACKUP_TOKEN` ficar vazio, o webhook de registro externo de backup retorna 403.

## Payload de backup externo

```json
{
  "status": "success",
  "backup_type": "automatic",
  "file_name": "rs-connect-2026-07-13.sql.gz",
  "location": "s3://bucket/rs-connect-2026-07-13.sql.gz",
  "size_bytes": 1234567,
  "checksum": "sha256...",
  "notes": "Backup diário concluído"
}
```

## Observação

O painel registra/monitora backups. A execução real do backup pode ser feita pelo provedor, VPS, EasyPanel, cron externo ou n8n.

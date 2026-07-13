# Atualizar do ZIP 20 para o ZIP 21

1. Suba os arquivos do ZIP 21 para o GitHub.
2. Faça redeploy no EasyPanel.
3. No Adminer, execute:

```text
database/migrations/023_operations_monitoring_backup.sql
```

4. Opcionalmente configure no `.env`:

```env
OPERATIONS_BACKUP_TOKEN=um-token-longo-para-o-webhook-de-backup
OPERATIONS_BACKUP_MAX_AGE_HOURS=24
N8N_BASE_URL=https://n8n.rsautomacaodigital.cloud
```

5. Acesse como Super Admin:

```text
/operations
```

ou:

```text
/monitoramento
```

6. Clique em **Verificar agora** para popular os primeiros status de saúde.

Não há alteração de senha, permissão de clientes ou acesso dos usuários existentes.

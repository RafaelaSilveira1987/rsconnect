# RS Connect 36.3.0 — Backup operacional confiável

Esta atualização refaz a rotina de backup para que o painel só mostre **Concluído** depois da criação e validação de um arquivo real.

## O que foi corrigido

- A resposta inicial do n8n agora significa apenas **solicitação aceita**.
- `last_success_at` não é atualizado no recebimento inicial.
- O job permanece `running` até o callback final.
- O callback exige job válido, tamanho real, SHA-256 e `verified=true`.
- Callbacks repetidos não criam backups duplicados.
- Jobs antigos presos são marcados como `timeout`.
- Rotinas inativas duplicadas são arquivadas da tela principal.
- O histórico mostra duração, arquivo, tamanho, validação e detalhes.
- A página atualiza os jobs automaticamente.
- O agendamento do n8n consulta o RS Connect a cada 15 minutos; o horário salvo na rotina é a fonte de verdade.

## 1. Segurança obrigatória

A senha do banco apareceu no workflow antigo. Troque essa senha antes de reativar a automação e remova ou desative o fluxo antigo.

Não grave senha de banco em nodes do n8n. O novo script usa a senha interna já existente no container MySQL.

## 2. Aplicar a migration

Execute no banco `rs_connect`:

```text
database/migrations/047_backup_automation_reliability.sql
```

Depois confira:

```sql
SHOW COLUMNS FROM operations_backup_jobs;
SHOW COLUMNS FROM system_backups;
```

## 3. Publicar o script na VPS

O arquivo está em:

```text
scripts/rsconnect-backup.sh
```

No projeto do EasyPanel, confirme o caminho e a permissão:

```bash
cd /etc/easypanel/projects/sites/rsconnect/code
chmod +x scripts/rsconnect-backup.sh
bash -n scripts/rsconnect-backup.sh
```

Teste manual sem registrar callback:

```bash
./scripts/rsconnect-backup.sh /backups/rs-connect 5 rs_connect
```

A saída deve ser um JSON com `status: success`, arquivo, tamanho, checksum e quantidade de tabelas.

## 4. Importar o novo workflow do n8n

Importe:

```text
docs/n8n_templates/template-backup-rsconnect.json
```

No node **Executar backup na VPS**, selecione a credencial SSH da VPS.

### Variáveis recomendadas no n8n

```env
RS_CONNECT_BACKUP_TOKEN=mesmo_valor_do_OPERATIONS_BACKUP_TOKEN
RS_CONNECT_BACKUP_DISPATCH_URL=https://rsconnect.rsautomacaodigital.cloud/webhooks/operations/backups/dispatch
RS_CONNECT_BACKUP_CALLBACK_URL=https://rsconnect.rsautomacaodigital.cloud/webhooks/operations/backups
RS_CONNECT_N8N_WEBHOOK_TOKEN=token_exclusivo_para_entrada_do_webhook_n8n
RS_CONNECT_BACKUP_SCRIPT=/etc/easypanel/projects/sites/rsconnect/code/scripts/rsconnect-backup.sh
```

`RS_CONNECT_N8N_WEBHOOK_TOKEN` deve ser o mesmo valor salvo no campo **Token de entrada do fluxo n8n** da rotina no RS Connect.

Ative o workflow somente depois de selecionar a credencial SSH e configurar as variáveis.

## 5. Configurar no RS Connect

Acesse:

```text
Central de operação > Backups
```

Na rotina:

- URL: URL de produção do node **Webhook RS Connect**;
- token de entrada: mesmo `RS_CONNECT_N8N_WEBHOOK_TOKEN`;
- frequência: diário;
- horário: 03:00;
- fuso: America/Sao_Paulo;
- retenção: 5 dias;
- destino: `/backups/rs-connect`.

Use primeiro **Testar conexão com n8n**. Esse teste não cria job nem arquivo.

Depois use **Executar backup agora**.

## 6. Resultado esperado

Sequência correta:

```text
requested -> running -> success
```

No banco:

```sql
SELECT id, routine_id, execution_uuid, status, started_at, finished_at,
       callback_received_at, backup_id, file_name, file_size_bytes, verified
FROM operations_backup_jobs
ORDER BY id DESC
LIMIT 10;
```

O job concluído precisa ter:

- `status = success`;
- `finished_at` preenchido;
- `callback_received_at` preenchido;
- `backup_id` preenchido;
- `file_size_bytes` maior que 1024;
- `verified = 1`.

Confira o backup:

```sql
SELECT id, routine_id, backup_job_id, execution_uuid, status, file_name,
       location, size_bytes, checksum, verified_at, finished_at
FROM system_backups
ORDER BY id DESC
LIMIT 10;
```

## 7. Teste de restauração

Nunca restaure sobre produção.

```bash
docker exec $(docker ps --filter label=com.docker.swarm.service.name=sites_mysql --format '{{.ID}}' | head -n1) \
  sh -lc 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "DROP DATABASE IF EXISTS rs_connect_restore_test; CREATE DATABASE rs_connect_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"'
```

```bash
gunzip -c /backups/rs-connect/NOME_REAL.sql.gz | \
docker exec -i $(docker ps --filter label=com.docker.swarm.service.name=sites_mysql --format '{{.ID}}' | head -n1) \
  sh -lc 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot rs_connect_restore_test'
```

Valide e remova o banco temporário após o teste.

# RS Connect v36.12.0 — Monitoramento e alertas operacionais

## Base necessária

Aplicar sobre a v36.11.2 homologada, preferencialmente na branch `hardening/beta-1.1` ou em uma nova branch `feature/monitoramento-alertas`.

## Banco de dados

Execute uma vez:

`database/migrations/073_operational_monitoring_alert_delivery.sql`

A migration é idempotente e adiciona reconhecimento de incidentes, histórico das execuções, lembretes recorrentes, auditoria dos canais e entrega externa dos comunicados aos clientes.

Não é necessário alterar `log_bin_trust_function_creators`, pois a migration não cria triggers.

## Instalação

1. Confirme o backup e o ponto de restauração.
2. Extraia o patch na raiz do projeto.
3. Confira os arquivos:

```powershell
git status --short
Test-Path database\migrations\073_operational_monitoring_alert_delivery.sql
```

4. Grave e envie:

```powershell
git add .
git commit -m "feat: adicionar monitoramento e alertas operacionais"
git push origin hardening/beta-1.1
```

5. Execute a migration 073 no Adminer.
6. Faça o rebuild no EasyPanel.
7. Atualize o navegador com `Ctrl + F5`.

## Variáveis do `.env`

Revise e ajuste os valores reais, sem colocar tokens no GitHub:

```dotenv
OPERATIONS_MONITOR_TOKEN=gere_um_token_forte
OPERATIONS_WEBHOOK_INACTIVITY_HOURS=24
OPERATIONS_N8N_CONSECUTIVE_ERRORS_CRITICAL=3

OPERATIONS_DISK_PATH=/var/www/html
OPERATIONS_DISK_WARNING_PERCENT=20
OPERATIONS_DISK_CRITICAL_PERCENT=10
OPERATIONS_DISK_MIN_FREE_GB=2

OPERATIONS_MESSAGE_PENDING_MINUTES=15
OPERATIONS_MESSAGE_QUEUE_WARNING=10
OPERATIONS_MESSAGE_QUEUE_CRITICAL=50

OPERATIONS_ALERT_TIMEOUT_SECONDS=20
OPERATIONS_ALERT_SSL_VERIFY=true

OPERATIONS_ALERT_EVOLUTION_URL=https://sua-evolution
OPERATIONS_ALERT_EVOLUTION_API_KEY=sua_api_key
OPERATIONS_ALERT_EVOLUTION_INSTANCE=instancia_administrativa

OPERATIONS_ALERT_EMAIL_WEBHOOK_URL=
OPERATIONS_ALERT_EMAIL_WEBHOOK_TOKEN=
OPERATIONS_ALERT_EMAIL_NATIVE=false
OPERATIONS_ALERT_EMAIL_FROM=
```

A instância administrativa da Evolution é usada somente para alertas da RS e comunicados administrativos. Não reutilize uma instância de cliente sem autorização.

Para e-mail, configure um webhook de transporte ou habilite o envio nativo somente quando o servidor estiver preparado para isso.

## Execução automática

### Opção recomendada: n8n

No RS Connect, baixe o template **Monitor operacional RS Connect**, configure `OPERATIONS_MONITOR_TOKEN` e execute a cada 15 minutos. O endpoint usado é:

`POST /webhooks/operations/checks/run`

### Opção por cron no servidor

```bash
*/15 * * * * docker exec CONTAINER_RS_CONNECT php /var/www/html/bin/operations-monitor.php >> /var/log/rsconnect-monitor.log 2>&1
```

Use o nome ou ID estável do container adotado na sua operação. Em Docker Swarm, prefira o n8n ou um agendador externo, pois o ID da réplica pode mudar após deploys.

## Homologação

1. Abra **Operação → Status do sistema** e execute uma verificação manual.
2. Abra **Operação → Alertas operacionais**.
3. Salve as preferências e use **Testar canais**.
4. Confirme o alerta interno.
5. Quando habilitados, confirme WhatsApp e e-mail.
6. Gere um cenário controlado de aviso, reconheça o incidente e depois resolva.
7. Em **Comunicação**, envie um comunicado de teste para uma empresa de homologação e confira os estados de cada canal.
8. Execute:

`database/diagnostics/operational_monitoring_v36.12.0.sql`

O resumo final esperado é:

```text
estruturas_encontradas = 7
duplicidades_entrega = 0
```

## Observações de segurança

- não envie API Keys ou tokens em comunicados;
- use uma empresa de homologação para testar canais externos;
- mantenha `OPERATIONS_MONITOR_TOKEN` fora do repositório;
- valide o destinatário do WhatsApp e do e-mail antes de habilitar alertas críticos;
- falha no canal externo não impede a criação do alerta interno na plataforma.

# Atualização — RS Connect 36.6.5

## 1. Banco

Aplique as migrations anteriores e, por último:

```sql
SOURCE database/migrations/049_operational_resolution_communications.sql;
```

A migration acrescenta contexto de empresa aos incidentes, preferências e entregas de alertas, notificações operacionais do Super Admin e as tabelas de Comunicados.

## 2. Ambiente

Defina um token forte:

```env
OPERATIONS_MONITOR_TOKEN=troque-por-um-token-longo-e-secreto
```

Se a variável não existir, o endpoint aceita `OPERATIONS_BACKUP_TOKEN`/`BACKUP_WEBHOOK_TOKEN` como fallback para compatibilidade, mas o recomendado é usar um token exclusivo.

Depois de alterar o ambiente, reinicie os containers/processos da aplicação.

## 3. Monitor automático no n8n

1. Abra **n8n → Templates** no Super Admin.
2. Baixe **Monitor operacional RS Connect**.
3. Importe o JSON no n8n.
4. Ative o workflow.

O arquivo baixado já recebe a `APP_URL` HTTPS e o `OPERATIONS_MONITOR_TOKEN` do ambiente e executa a verificação a cada 15 minutos.

## 4. Validação rápida

- **Operação RS → Painel operacional**: execute uma verificação e abra **Resolver problema**.
- **Central de operação**: confirme o Assistente de correção contextual.
- **Alertas operacionais**: salve preferências e valide sino, cooldown e recuperação.
- **Comunicados**: envie um comunicado de teste e confirme-o no sino do cliente.

WhatsApp e e-mail administrativos estão modelados no mesmo motor, porém permanecem como `pending_configuration` até um provedor de envio externo ser conectado. A versão não registra esses canais como enviados sem confirmação real.

# RS Connect — HOTFIX 36.1.2

## Causa confirmada pelos logs

O deploy do Docker foi concluído, porém o endpoint da Evolution continuou retornando **HTTP 422**. Nesse cenário, a mensagem pode falhar antes de ser persistida, portanto não existe registro para a Fila da IA recuperar.

O build do EasyPanel também não executa migrations automaticamente.

## Correções

- Persiste contato, conversa e mensagem antes de CRM, agenda, notificações, n8n e IA.
- Falhas em módulos auxiliares não apagam a mensagem recebida.
- Após a mensagem ser salva, o webhook responde HTTP 200 mesmo quando uma etapa posterior falha.
- Registra erros em `storage/logs/evolution-webhook.log` com fase, conversa e mensagem.
- Ignora status, broadcasts, grupos e newsletters sem responder 422.
- Fila funciona em modo de compatibilidade mesmo sem a coluna `incoming_message_id`.
- Falhas de entrega com banco antigo usam status `pending` como fallback seguro.
- Somente saídas `sent`, `delivered` ou `read` contam como resposta válida.
- Reprocessamento respeita bloqueio comercial/acesso da empresa.
- Migration 045 garante coluna, índices e ENUM de status.

## Atualização

1. Publique os arquivos deste hotfix.
2. Execute:

```text
database/migrations/045_ai_webhook_ingestion_resilience.sql
```

3. Faça novo deploy.
4. Envie duas mensagens seguidas.
5. Se houver nova falha, consulte:

```text
storage/logs/evolution-webhook.log
storage/logs/app.log
```

## Resultado esperado

- Nenhum `MESSAGES_UPSERT` válido deve retornar HTTP 422.
- A segunda mensagem deve aparecer na conversa mesmo quando a IA falhar.
- Após dois minutos, mensagem sem resposta entra na Fila da IA.
- Falha de entrega da Evolution mantém a mensagem pendente para nova tentativa.

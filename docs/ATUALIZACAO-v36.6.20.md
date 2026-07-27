# RS Connect 36.6.20 — Agenda por intenção real e callback de backup resiliente

## Objetivo

Esta versão corrige dois comportamentos observados em produção:

1. o fluxo **Agenda Google Calendar por Empresa** podia receber eventos genéricos e criar vários eventos `Compromisso RS Connect` durante conversas comuns;
2. o backup podia ser concluído na VPS e falhar somente no último callback com `403 Token inválido`.

## Banco de dados

Aplicar após o deploy:

```sql
SOURCE database/migrations/056_n8n_agenda_event_contract.sql;
```

A migration não altera estrutura. Ela apenas corrige a inscrição de fluxos legados chamados **RS Connect - Agenda Google Calendar por Empresa** para receber exclusivamente:

```text
calendar.appointment.created
```

## Agenda

### Nova regra de intenção

Um novo pré-agendamento só pode nascer de uma solicitação explícita, por exemplo:

- `Quero agendar amanhã.`
- `Preciso de um horário para sexta.`
- `Gostaria de marcar uma reunião.`
- `Quero remarcar minha consulta.`

Não iniciam agenda sozinhas:

- `Tenho uma reunião amanhã às 10h.`
- `Consulta sexta à tarde.`
- `Vou configurar isso hoje à noite.`
- `Qual o horário de atendimento?`

Uma preferência curta como `terça às 15h` continua válida quando a conversa já está em contexto real de agenda.

### Google Calendar

Há três proteções acumuladas:

1. o RS Connect bloqueia eventos incompatíveis para o fluxo `Agenda Google Calendar por Empresa`, mesmo se um cadastro antigo estiver com `events_json = ["*"]`;
2. a migration 056 corrige o cadastro existente;
3. o próprio template n8n possui o gate **É compromisso real?** e só cria evento quando:
   - `event = calendar.appointment.created`;
   - existe início;
   - existe fim.

Eventos sem contrato válido recebem resposta `ignored=true` e não chegam ao node do Google.

### Importante

Eventos antigos `Compromisso RS Connect` que já foram gravados no Google Calendar são dados externos anteriores à correção e **não são apagados automaticamente**. Revise/remova esses eventos antigos manualmente depois de confirmar que não são compromissos reais.

## Backup

O endpoint `/webhooks/operations/backups` aceita agora:

```text
X-RS-Backup-Token
```

mantendo compatibilidade com:

```text
X-RS-Connect-Token
Authorization: Bearer ...
```

A validação do token aceita os aliases configurados:

- `OPERATIONS_BACKUP_TOKEN`
- `BACKUP_WEBHOOK_TOKEN`
- `RS_CONNECT_BACKUP_TOKEN`

Além disso, callbacks de jobs já criados podem ser autenticados pela identidade da execução:

```text
backup_job_id + execution_uuid
```

O `processCallback()` valida novamente essa identidade sob lock e continua idempotente.

## n8n

Depois do deploy, recomenda-se baixar novamente:

- **Agenda Google Calendar**;
- **Backup automático RS Connect**.

No template da Agenda haverá o node **É compromisso real?**.

No template de Backup, o callback envia tanto `X-RS-Backup-Token` quanto o header legado `X-RS-Connect-Token`.

## Teste rápido

### Agenda

Enviar mensagens comuns:

```text
Tenho uma reunião amanhã às 10h.
Vou configurar isso hoje à noite.
```

Resultado esperado: nenhuma execução do fluxo Google Calendar de criação e nenhum novo `Compromisso RS Connect`.

Depois enviar:

```text
Quero marcar uma reunião amanhã às 10h.
```

Resultado esperado: abertura do fluxo de agenda/pré-agendamento conforme as regras cadastradas.

### Backup

Executar o backup automático e conferir que o node **Callback RS Connect** retorna 2xx. O job deve terminar como `success` e atualizar `last_success_at`.

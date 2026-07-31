# RS Connect v36.10.4 — Padronização de datas e fuso horário

## Objetivo

Eliminar a diferença de três horas observada entre eventos gerados pelo MySQL e mensagens gravadas pelo PHP.

O contrato desta versão é:

- datas técnicas são persistidas em UTC;
- filtros e relatórios usam o fuso da empresa;
- mensagens da Evolution são convertidas diretamente do Unix timestamp para UTC;
- horários de compromissos continuam no horário local indicado pela coluna `timezone` do agendamento.

## Migration obrigatória

Importe no Adminer:

`database/migrations/071_utc_datetime_contract_compat.sql`

A criação dos triggers requer temporariamente:

```sql
SET GLOBAL log_bin_trust_function_creators = ON;
```

Esse comando deve ser executado pelo usuário root do MySQL, pelo terminal da VPS. Depois da migration, a variável pode voltar para `OFF`.

A migration é idempotente. A normalização histórica é executada uma única vez e fica registrada em `rs_datetime_contract.historical_normalized_at_utc`.

## O que é normalizado

- `conversation_messages.sent_at`;
- marcos de mensagem em `conversations`;
- primeira entrada, última entrada e primeira resposta em `conversation_service_cycles`;
- snapshots antigos de atribuição criados pela migration histórica.

A migration usa o `google_utc_offset` da empresa quando disponível. Na ausência dele, usa `-03:00`, compatível com a configuração padrão `America/Sao_Paulo`.

## Agenda

`calendar_appointments.starts_at` e `ends_at` não são deslocados. Eles representam o horário local do compromisso e continuam associados à coluna `timezone`.

## Relatórios

O relatório de equipe converte o período escolhido para UTC antes da consulta e volta a agrupar os eventos pelo dia local da empresa. Atividades recentes são exibidas no fuso da empresa.

## Diagnóstico

Execute:

`database/diagnostics/utc_datetime_contract_v36.10.4.sql`

Confirme:

- `storage_timezone = UTC`;
- 10 triggers `trg_rs_%`;
- ausência de respostas anteriores à primeira entrada;
- `@@SESSION.time_zone = +00:00` nas conexões da aplicação.

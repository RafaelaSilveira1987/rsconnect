# RS Connect v36.8.1 — Conflito do cliente na agenda

Esta atualização complementa a agenda por profissional da v36.8.0.

## Regra principal

Quando a proteção estiver ativa, o mesmo contato não poderá ocupar dois atendimentos sobrepostos, mesmo que os profissionais sejam diferentes.

Exemplo:

```text
João + Caco — 14:00 às 15:00
Pedro + Caco — 14:00 às 15:00
→ bloqueado
```

Horários diferentes continuam permitidos.

## Configuração

Em **Agenda → Disponibilidade → Agenda por profissional**:

```text
[x] Impedir dois horários para o mesmo cliente
```

A regra fica ativada por padrão após a migration 066, mas pode ser desligada pela empresa em operações que realmente permitam atendimentos simultâneos para o mesmo cadastro.

## Onde a validação foi aplicada

- criação manual de agendamento;
- criação manual de pré-agendamento;
- confirmação de pré-agendamento;
- escolha de horário na disponibilidade interna;
- escolha de evento VAGO pelo Google Agenda;
- pré-agendamento iniciado pela conversa/IA;
- geração de sugestões pela Agenda interna.

São considerados ocupados os status:

- pré-agendado;
- aguardando aprovação;
- agendado;
- confirmado.

Cancelados, recusados, concluídos e faltas não bloqueiam novos horários.

## Migration

Importe no Adminer:

```text
database/migrations/066_contact_schedule_overlap_guard_compat.sql
```

Mensagem esperada:

```text
Migration 066 aplicada: conflito de horário do mesmo cliente bloqueado por padrão e configurável por empresa.
```

## Diagnóstico

Para localizar duplicidades antigas, execute somente para consulta:

```text
database/diagnostics/contact_schedule_overlap_v36.8.1.sql
```

A consulta não altera dados.

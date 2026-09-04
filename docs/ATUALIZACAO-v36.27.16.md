# RS Connect 36.27.16 — confirmação real da agenda pela conversa

## Problema corrigido

Quando o cliente escolhia um horário real encontrado pela agenda interna, a conversa podia chegar a uma resposta como **“Confirmado”** sem que o pré-agendamento fosse convertido tecnicamente em um compromisso confirmado no `calendar_appointments`.

O efeito era contraditório: o WhatsApp dizia que o horário estava marcado, porém o compromisso não aparecia como confirmado na Agenda do RS Connect.

## Correção

A confirmação da agenda deixa de ser apenas linguagem da IA e passa a ser uma transição técnica obrigatória:

1. o RS Connect consulta disponibilidade real;
2. um slot é aplicado ao pré-agendamento;
3. quando `ai_can_confirm = 1` e `require_human_approval = 0`, o cliente recebe uma pergunta objetiva de confirmação;
4. respostas como `sim`, `pode` ou `pode confirmar` são interceptadas **antes da IA**;
5. o sistema revalida conflitos, disponibilidade e Google Agenda quando aplicável;
6. somente após sucesso grava `status = confirmed`, `approval_status = approved` e `is_pre_schedule = 0`;
7. a mensagem final de confirmação é enviada somente depois dessa persistência.

Se a empresa exigir aprovação humana, uma resposta positiva do cliente não confirma tecnicamente o horário: o sistema informa que a preferência continua aguardando validação da equipe.

## Identidade do agente

As mensagens determinísticas da agenda geradas por `CalendarConversationService` agora usam a identidade do agente efetivamente roteado, inclusive `sender_display_name` quando a coluna estiver disponível e a assinatura no WhatsApp.

## Google Agenda

A confirmação conversacional reutiliza as mesmas barreiras técnicas da confirmação pelo painel:

- `CalendarAvailabilityService::canApprove()`;
- confirmação de evento marcado quando aplicável;
- `CalendarGoogleLifecycleService::syncConfirmedAppointment()`;
- `require_google_sync_on_confirm` continua fail-closed.

## Banco de dados

Não há migration nova. A migration obrigatória continua sendo:

```text
database/migrations/101_agent_scheduling_specialist_routing.sql
```

## Comportamento esperado

Exemplo:

```text
Ana: Encontrei terça-feira às 16:00.
Cliente: 16h
Ana: Perfeito! Separei terça-feira, 08/09/2026 às 16:00. Posso confirmar o agendamento?
Cliente: Pode
Ana: Seu agendamento foi confirmado para 08/09/2026 às 16:00.
```

Após a última mensagem, o mesmo compromisso deve existir na Agenda do RS Connect com `status = confirmed` e `is_pre_schedule = 0`.

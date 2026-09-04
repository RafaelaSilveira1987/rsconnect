# RS Connect 36.27.17 — Persistência segura da agenda conversacional

## Diagnóstico corrigido

A homologação real mostrou mensagens com intenção explícita de agenda, `tenant_pre_schedule_settings.enabled = 1` e detector `has_intent = 1`, porém sem novo registro em `calendar_appointments` e sem eventos `calendar.pre_scheduled`. A conversa seguia para a IA livre.

A correção atua em dois pontos:

1. pedidos comerciais como “agendar uma demonstração” e “agendar uma reunião” passam a usar a própria finalidade como demanda coletada, sem exigir uma pergunta artificial de motivo; pedidos genéricos como “quero agendar” e o fluxo clínico genérico “agendar uma consulta” preservam a regra de coleta quando configurada;
2. se a camada determinística da agenda falhar na Fila rápida, a falha fica registrada como `calendar.pre_schedule_error`/`ai.failed` e a IA livre não pode continuar fingindo disponibilidade ou confirmação sem persistência.

## Configuração de aprovação

Esta versão não altera preferências do cliente. Se `require_human_approval = 1` ou `ai_can_confirm = 0`, o comportamento correto continua sendo aguardar aprovação humana. Para confirmação totalmente automática, ajuste conscientemente essas opções na configuração de pré-agendamento.

## Banco

Não há migration nova. A última obrigatória continua sendo `101_agent_scheduling_specialist_routing.sql`.

## Homologação

Para uma empresa comercial com agenda automática habilitada:

1. envie “Quero agendar uma demonstração.”;
2. confirme que nasce um `calendar_appointments` com `conversation_id` da conversa atual e `is_pre_schedule = 1`;
3. confirme evento `calendar.pre_scheduled`;
4. prossiga por modalidade, disponibilidade e seleção;
5. se aprovação humana estiver ativa, a IA deve informar que aguarda aprovação;
6. se confirmação automática estiver habilitada, somente depois de persistir `status = confirmed` e `is_pre_schedule = 0` a IA pode dizer “confirmado”.

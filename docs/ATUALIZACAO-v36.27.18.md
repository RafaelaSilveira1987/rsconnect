# RS Connect 36.27.18 — Agenda persistida antes da resposta da IA

## Problema comprovado em produção

Uma intenção explícita de agenda atualizava `conversation_flow_states` para `stage=scheduling`, mas em determinados caminhos da Fila rápida a resposta livre da IA ainda podia ser enviada sem qualquer linha nova em `calendar_appointments`. O contato recebia frases de registro/confirmacao, porém o calendário permanecia vazio.

## Correção

- `AiAutomationService::handleIncoming()` reexecuta a camada determinística da agenda depois da janela de espera e antes de regra local, cache ou provedor de IA.
- Se Agenda consumir a mensagem, a IA livre não é chamada.
- Se houver intenção explícita, pré-agendamento habilitado e a camada não criar/atualizar o compromisso, a execução falha fechada e registra `calendar.pre_schedule_unhandled` + `ai.failed`.
- Respostas de continuação como modalidade, data/hora e confirmação também passam pela mesma guarda antes do modelo.
- Corrigido ainda um `prepare()` duplicado no reset de uma preferência de disponibilidade existente.

## Banco

Não há migration nova. A última migration obrigatória continua sendo `101_agent_scheduling_specialist_routing.sql`.

## Evidência exigida para homologação

Após enviar uma nova intenção de agenda, deve existir uma linha em `calendar_appointments` vinculada à conversa antes de qualquer mensagem textual que afirme registro, pré-reserva ou confirmação.

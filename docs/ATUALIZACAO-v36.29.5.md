# RS Connect 36.29.5 — Fluxo editável e regras do atendimento no cliente

## O que mudou

- A ordem do atendimento deixa de ser apenas visual: ela passa a influenciar qual informação pendente a triagem pede primeiro.
- A sequência pode ser reorganizada por empresa usando os botões de mover etapa.
- As regras operacionais passam a ficar disponíveis também em **Assistentes**, para administradores do próprio cliente.
- Segmento, modelo-base, versão, credenciais, provedor/modelo de IA e integrações técnicas continuam sob controle do RS Admin quando uma alteração puder interromper a operação.
- O cliente pode ajustar perguntas, obrigatoriedade, políticas de negócio, mensagens, capacidades operacionais, horários, comportamento e ordem do fluxo.
- A proteção `policy.fail_closed` continua bloqueada para clientes e não pode ser desligada fora do RS Admin.
- O layout da sequência foi trocado por um editor responsivo em cards, sem corredor horizontal.

## Origem da sequência

O fluxo inicial vem do `workflow` da versão do modelo de atendimento aplicada ao segmento. Na aplicação do modelo ele é copiado para `tenant_agent_workflow_steps`. Depois disso, a empresa pode personalizar sua própria cópia sem alterar o modelo global.

## Segurança

Mudar a ordem não remove as validações do Policy Engine. Regras como idade mínima, elegibilidade e confirmação humana continuam sendo verificadas antes das ações técnicas.

## Banco de dados

Não há migration nova. A migration atual continua sendo:

`106_agent_message_grouping_context_priority.sql`

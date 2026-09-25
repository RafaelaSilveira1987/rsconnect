# Validação — Ordem do atendimento por turno — 36.36.23

## Objetivo
Garantir que a conversa use a ordem configurada mesmo quando o cliente faz apenas uma pergunta informativa e que estados antigos de agenda não contaminem uma conversa nova.

## Cenário principal
1. Inicie uma conversa nova.
2. Envie: `Bom dia` e `Gostaria de saber como funciona o atendimento`.
3. A assistente pode responder à pergunta, mas a pergunta de coleta seguinte deve ser **somente a primeira etapa pendente da Ordem do atendimento**.
4. Se a primeira etapa for “Identificar quem será atendido”, a próxima pergunta precisa corresponder a essa etapa.
5. Responda: `Sim, estou passando por uma perda muito importante`.
6. O sistema deve registrar apenas a resposta da etapa atual e avançar para a etapa seguinte (por exemplo, idade). Não deve tratar essa frase como confirmação de agenda nem pular para demanda.

## Agenda isolada por conversa
- Um pré-agendamento antigo pertencente ao mesmo contato não pode consumir `sim`, `ok` ou outra resposta de uma conversa nova.
- Seleção/confirmação só considera pré-agendamento vinculado à conversa atual.

## Regressões preservadas
- demanda obrigatória antes da agenda;
- modalidade antes da disponibilidade;
- opções reais de agenda;
- retomada fora do horário e correção UTC;
- apresentação única;
- SLA opcional;
- respostas por escopo do turno.

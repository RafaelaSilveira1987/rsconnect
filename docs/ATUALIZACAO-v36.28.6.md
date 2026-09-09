# RS Connect 36.28.6 — regras sem bloquear a conversa

## Problema corrigido

Uma política configurada como **Não permitir agenda** estava sendo tratada como bloqueio da conversa inteira. Em psicologia, por exemplo, ao detectar idade abaixo do mínimo, o sistema registrava a decisão como bloqueio terminal e mantinha a intenção de agendamento ativa. Com isso, mensagens seguintes podiam ser barradas repetidamente.

## Novo comportamento

A ação da política agora define o escopo real:

- **Não permitir agenda**: informa a mensagem configurada, impede consulta/pré-reserva/confirmação, mas mantém a conversa ativa;
- **Bloquear a ação**: continua sendo um bloqueio completo quando essa for realmente a configuração escolhida;
- **Passar para atendimento humano**: continua encerrando a automação e entregando a conversa à equipe.

Quando a restrição é de agenda, a sessão passa a registrar a elegibilidade como bloqueada para calendário, encerra a intenção de agendamento e permite que o cliente continue conversando para receber indicação, esclarecer dúvidas ou seguir outro fluxo permitido.

## Exemplo

Configuração:

- Idade mínima: 14 anos
- Ação: Não permitir agenda
- Mensagem: "No momento, este profissional não realiza atendimento para pessoas menores de 14 anos..."

Cliente informa 8 anos:

1. RS Connect detecta a idade;
2. envia a mensagem configurada;
3. não consulta a agenda;
4. não cria pré-reserva;
5. mantém a conversa aberta;
6. se o cliente perguntar sobre indicação, a IA pode responder sem repetir o bloqueio.

## Auditoria

O histórico de segurança passa a distinguir uma restrição de calendário de um bloqueio geral, mostrando **Agenda não liberada** quando o escopo for somente a agenda.

## Banco

Não há migration nova. A migration obrigatória atual continua sendo:

`104_customer_patient_continuity_guard.sql`

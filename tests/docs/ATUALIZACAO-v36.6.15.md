# RS Connect 36.6.15 — Confiabilidade das regras do agente

## Objetivo

Esta versão corrige desvios em que automações conversacionais podiam agir fora do horário configurado ou transformar menções casuais de data/hora em fluxo de agenda.

A regra central passa a ser:

**Configuração operacional do RS Connect → modo da conversa → cadastro do contato → contexto do fluxo → prompt livre do agente.**

O prompt continua definindo personalidade, objetivo e abordagem, mas não pode furar uma trava técnica da plataforma.

## Horário do agente

Na configuração do assistente, o campo foi explicitado como **Responder somente no horário configurado**.

Quando estiver ativo:

- IA principal não continua a conversa fora do expediente;
- Pré-agendamento não responde fora do expediente;
- seleção de opções da agenda não responde fora do expediente;
- callback tardio do n8n/Google não envia nem pré-reserva horário fora do expediente;
- n8n conversacional disparado pelo webhook de mensagem não é executado fora do expediente;
- recuperação pós-horário usa a mesma regra de dias, horários e timezone.

A mensagem de ausência configurada pode ser enviada uma vez e a demanda fica preservada para recuperação posterior.

Quando a trava estiver desativada, o agente pode operar 24h conforme o seu prompt e demais configurações.

## Agenda

A intenção de agenda foi endurecida.

Não devem mais iniciar agendamento isoladamente expressões como:

- hoje;
- tarde;
- noite;
- hora;
- atendimento;
- “vou tentar configurar hoje à tarde/noite”.

Perguntas como **“Qual o horário de atendimento?”** são informação de funcionamento, não pedido de agenda.

A agenda inicia por intenção explícita, por exemplo:

- “Quero agendar amanhã”;
- “Preciso de um horário amanhã”;
- “Quero remarcar minha consulta”;
- “Me confirma meu horário de hoje?”.

Uma resposta curta como **“Pode ser terça às 15h”** só é tratada como preferência de agenda quando a conversa já estiver em contexto recente de agendamento.

## Cliente, Paciente, grupo e tags

Classificação e cadastro passam a ser reforçados como fonte de verdade.

Um contato já classificado como **Cliente** ou **Paciente atual** não deve:

- voltar a ser tratado como lead novo;
- repetir motivo/queixa como condição para atendimento;
- reiniciar qualificação já concluída.

Grupo, tags e observações do contato são incluídos no contexto estruturado da IA.

## Prompt sem viés de agenda

As configurações e instruções específicas de pré-agendamento só são anexadas ao prompt quando a conversa realmente estiver em intenção/etapa de agenda.

Em conversa geral, o prompt recebe uma regra explícita para não conduzir o atendimento à agenda por iniciativa própria.

## Diagnóstico visível em Conversas

No painel lateral da conversa foi adicionada a seção **Validação efetiva / Regras aplicadas agora**, contendo:

- agente efetivo;
- modo IA/Humano/Pausado;
- situação do horário operacional;
- classificação do contato;
- grupo;
- tags;
- última intenção detectada;
- etapa do fluxo;
- indicação se a conversa está ou não em contexto real de agenda.

Essa seção é a referência principal durante a homologação.

## Banco de dados

Não há migration nova nesta versão.

A última migration obrigatória continua sendo:

```text
database/migrations/055_multi_whatsapp_agent_routing.sql
```

## Pós-deploy

1. Faça `Ctrl+F5`.
2. Abra o assistente e confirme os dias, horário e timezone.
3. Marque **Responder somente no horário configurado** caso queira bloquear automações fora do expediente.
4. Abra uma conversa e confira **Validação efetiva / Regras aplicadas agora**.
5. Execute os testes descritos em `HOMOLOGACAO-FINAL-v36.6.15.md`.

# Atualização — RS Connect 36.6.6

## Objetivo

Corrige dois comportamentos observados em produção:

1. atendimento automático de agenda entrando em conversa já assumida por humano;
2. cliente/paciente já identificado sendo tratado novamente como novo interessado e obrigado a informar motivo/queixa antes da agenda.

## Banco

Depois das migrations anteriores, execute:

```sql
SOURCE database/migrations/050_human_takeover_customer_context.sql;
```

A migration não cria novas tabelas. Ela apenas normaliza regras e estados já existentes de Cliente/Paciente atual para que demanda não seja novamente obrigatória.

## O que mudou

- `Humano` e `Pausado` agora bloqueiam também o pré-agendamento e as mensagens automáticas da agenda, e não somente a resposta da OpenAI/Gemini.
- respostas assíncronas da agenda também revalidam o modo da conversa antes de enviar WhatsApp;
- ao enviar uma mensagem manual pelo painel, a conversa é assumida pelo humano **antes** da chamada à Evolution;
- classificação `Cliente` e grupos `Cliente atual`/`Paciente atual` passam a dispensar nova triagem de demanda;
- estados antigos `pending/collecting_demand` são normalizados pela migration;
- o prompt reforça que cliente/paciente atual deve receber continuidade, sem voltar ao roteiro de lead.

## Teste rápido

### Takeover humano

1. Abra uma conversa em modo IA.
2. Mude para **Humano** ou envie uma mensagem manual pelo painel.
3. Peça pelo WhatsApp algo relacionado a horário/agenda.
4. Confirme que não é enviada resposta automática da IA nem da agenda.

### Cliente atual

1. Marque o contato como **Cliente** e grupo **Paciente atual** ou **Cliente atual**.
2. Devolva a conversa para **IA**.
3. Pergunte por horário, confirmação ou remarcação.
4. Confirme que o sistema não pergunta novamente motivo do atendimento/principal queixa.

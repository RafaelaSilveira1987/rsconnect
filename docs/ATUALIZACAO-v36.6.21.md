# RS Connect 36.6.21 — Horário confiável e Agenda com contrato forte

## Por que esta versão existe

Dois desvios foram observados em produção:

1. uma conversa recebeu a mensagem de “fora do horário” em uma segunda-feira às 10:46, apesar do assistente estar configurado para 08:00–17:00;
2. o workflow **Agenda Google Calendar por Empresa** continuou criando eventos genéricos durante conversas comuns.

A correção trata os dois como regras técnicas de plataforma, não como prompt.

## Horário operacional

- o roteamento de automação passa a preferir um agente vinculado ao canal que esteja **dentro do próprio expediente**;
- se um especialista estiver fechado, mas o agente principal estiver disponível, o canal continua atendendo pelo agente disponível;
- “fora do horário” só é aplicado quando nenhum agente elegível do canal está operacional;
- a política é revalidada imediatamente antes do envio externo da resposta;
- **Conversas → Dados da conversa → Validação efetiva** passa a mostrar agente efetivo, hora local e faixa realmente aplicada.

## Google Calendar

A proteção agora existe em camadas:

1. fluxos cadastrados como **Agenda Google Calendar por Empresa** só aceitam `calendar.appointment.created`;
2. uma URL legada configurada diretamente em **Integração externa deste assistente** também respeita esse contrato e não pode receber `ai.replied`/`message.received`;
3. o Admin não consegue salvar esse writer como wildcard;
4. o workflow exige `contract.type = calendar_appointment_v1`, `appointment_id`, título, início e fim;
5. foi removido o fallback de título `Compromisso RS Connect` para payload sem compromisso real.

## Importante após o deploy

### 1. Banco

Não há migration nova na 36.6.21. A migration 056 da versão anterior continua recomendada/obrigatória caso ainda não tenha sido aplicada:

```sql
SOURCE database/migrations/056_n8n_agenda_event_contract.sql;
```

### 2. Reimporte o template da Agenda

Baixe novamente em **n8n → Templates → Agenda Google Calendar** e substitua o workflow antigo. O novo workflow possui contrato forte e não cria evento sem `appointment_id` real.

### 3. Revise o assistente

Em **Assistentes de IA**, confira o campo **Integração externa deste assistente**.

O webhook **Agenda Google Calendar por Empresa não deve ser colocado nesse campo**. A Agenda deve permanecer cadastrada em **n8n → Fluxos por empresa**.

Mesmo se uma configuração antiga continuar gravada, a 36.6.21 bloqueia o disparo indevido em runtime.

## Testes mínimos

1. Segunda-feira, 10:46, assistente 08:00–17:00: deve aparecer **Dentro do expediente**.
2. Enviar “Tenho uma reunião amanhã às 10h”: não deve criar evento Google.
3. Enviar uma conversa comum e deixar a IA responder: `ai.replied` não deve acionar o writer Google Calendar.
4. Criar um compromisso real pelo RS Connect: aí sim `calendar.appointment.created` deve executar o workflow e criar o evento.

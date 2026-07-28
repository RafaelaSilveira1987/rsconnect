# RS Connect 36.6.32 — Recuperação pós-horário da Agenda

## Objetivo

Corrigir pedidos de agenda preservados fora do expediente que eram retomados pela IA geral com uma mensagem como “vou verificar a disponibilidade”, mas não criavam nem enviavam a solicitação real de disponibilidade.

## Diagnóstico confirmado

O workflow `RS Connect - Agenda Google Calendar por Empresa` é um **writer de evento confirmado**, não um fluxo de busca de disponibilidade.

Quando ele recebe:

```text
event = ai.replied
```

sem `calendar_appointment_v1`, `appointment_id`, título, início e fim, a saída correta é:

```text
Ignorar evento sem agenda
```

A falha estava antes dele: a recuperação pós-horário chamava a IA geral antes da máquina determinística da Agenda e o campo legado de integração do assistente ainda podia enviar `ai.replied` diretamente ao writer.

## Correções

- A recuperação pós-horário processa Agenda antes do provedor de IA.
- Mensagens fragmentadas da mesma janela fechada são reunidas em ordem.
- Modalidade, dia e horário existentes são reutilizados.
- `requestAvailabilityIfNeeded` passa a registrar o resultado no log da recuperação.
- A Fila rápida repara pré-agendamentos prontos que ficaram sem request atual ou com request falho.
- O endpoint `/webhook/rsconnect-agenda-cliente` bloqueia `ai.replied` e `message.received`, mesmo sem registro em `n8n_tenant_flows`.
- O formulário do assistente impede salvar esse writer como integração externa.

## Banco

Não há migration nova. A base permanece na migration 059.

## Antes/depois do deploy

1. Em **Assistentes de IA**, remova do campo `Integração externa deste assistente` qualquer URL terminada em:

```text
/webhook/rsconnect-agenda-cliente
```

2. Mantenha o workflow `Agenda Google Calendar por Empresa` publicado somente como writer de compromisso real.
3. Em **Agenda → Integração técnica**, a busca de disponibilidade deve apontar para o workflow correspondente ao modo usado, por exemplo:

```text
/webhook/rsconnect-agenda-google-eventos-vago
```

ou o fluxo de espaços livres/disponibilidade configurado para a empresa.
4. Mantenha **Fila rápida da IA** publicada.
5. Faça o deploy da 36.6.32.

## Recuperação da conversa já travada

A Fila rápida agora procura pré-agendamentos conversacionais que:

- possuem modalidade válida;
- possuem data/hora;
- ainda não enviaram opções;
- não possuem request atual ou possuem request com falha.

Após o deploy, ela tenta criar o request ausente sem exigir nova mensagem do contato. O intervalo padrão de recuperação é de aproximadamente 2 minutos.

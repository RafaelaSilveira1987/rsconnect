# Homologação — RS Connect 36.6.21

## 1. Horário exatamente como o caso observado

Configuração do agente:

- dias: Seg–Sex;
- início: 08:00;
- fim: 17:00;
- fuso: America/Sao_Paulo;
- responder somente no horário: ativo.

Em uma segunda-feira entre 10:00 e 11:00, envie uma mensagem comum.

Esperado:

- nenhuma mensagem “fora do horário”;
- em **Validação efetiva**, `Dentro do expediente`;
- hora local compatível com a tela;
- faixa aplicada `08:00–17:00`;
- agente efetivo identificado.

## 2. Múltiplos agentes no mesmo WhatsApp

Se houver especialista + agente principal:

- feche o horário do especialista;
- mantenha o principal dentro do expediente;
- envie uma mensagem que normalmente rotearia para o especialista.

Esperado: o sistema usa um agente elegível disponível e não declara o canal inteiro fora do horário.

## 3. Conversa que menciona reunião

Envie:

> Tenho uma reunião amanhã às 10h.

Esperado:

- não iniciar pré-agendamento;
- não executar criação de evento Google;
- nenhum novo “Compromisso RS Connect”.

## 4. Integração externa do assistente

Se o campo do assistente contiver o mesmo webhook de **Agenda Google Calendar por Empresa**:

- uma resposta `ai.replied` deve ser bloqueada pelo contrato em runtime;
- ao tentar salvar novamente essa URL no assistente, a tela deve orientar a removê-la.

## 5. Compromisso real

Crie um compromisso válido no módulo Agenda do RS Connect.

Esperado:

- evento `calendar.appointment.created`;
- payload com `contract.type = calendar_appointment_v1`;
- `appointment_id` real;
- título, início e fim preenchidos;
- exatamente um evento criado no Google Calendar.

## 6. Template n8n

Reimporte o template **Agenda Google Calendar** da 36.6.21.

Esperado no node Normalizar Agenda:

- exigir contrato `calendar_appointment_v1`;
- exigir `appointment_id`;
- exigir título;
- exigir início/fim;
- não usar `Compromisso RS Connect` como título fallback.

## 7. Smoke tests do pacote

```bash
php tests/Feature/agent-policy-reliability-smoke.php
php tests/Feature/agenda-backup-contract-smoke.php
```

Ambos devem retornar `OK`.

# RS Connect 36.6.24 — Modalidade antes da disponibilidade

## Objetivo

A Agenda deixa de inferir modalidade. Antes de consultar disponibilidade, o RS Connect precisa saber se o atendimento será **Online** ou **Presencial**.

Fluxo esperado:

1. cliente manifesta intenção real de agendamento;
2. se a modalidade não estiver clara, o RS Connect pergunta `Online ou presencial?`;
3. depois coleta/completa dia e horário, quando necessário;
4. somente com modalidade + preferência de dia/horário é criada a consulta de disponibilidade;
5. no modo Eventos VAGO, `online` consulta somente os títulos online e `presencial` somente os presenciais;
6. se a modalidade mudar, a consulta anterior é invalidada e uma nova busca é feita.

## Migration obrigatória

Após o deploy, execute:

```sql
SOURCE database/migrations/057_calendar_modality_before_availability.sql;
```

A migration:

- permite `indefinida` em `calendar_appointments.location_type`;
- torna `indefinida` o padrão para novos pré-agendamentos sem modalidade;
- adiciona `tenant_pre_schedule_settings.modality_message`;
- preserva/backfill apenas modalidades antigas inequívocas.

## Configuração

Em **Minha empresa / Empresa → Agenda → Pré-agendamento e mensagens**, revise:

- **Mensagem para escolher modalidade**;
- **Mensagem para pedir dia e horário**.

Padrão da modalidade:

> Antes de consultar os horários, você prefere atendimento online ou presencial?

## n8n

Baixe novamente o template:

**n8n → Templates → Agenda Google — eventos VAGO**

O template atualizado recusa `search` se `requested_modality` não for `online` ou `presencial`.

## Regras de segurança

Mesmo que um workflow antigo devolva horários misturados, o backend do RS Connect filtra novamente os slots pela modalidade solicitada.

A chamada de disponibilidade também é recusada no backend enquanto `appointment_modality` estiver indefinida.

## Sem novas variáveis de ambiente

A 36.6.24 não adiciona variáveis `.env`.

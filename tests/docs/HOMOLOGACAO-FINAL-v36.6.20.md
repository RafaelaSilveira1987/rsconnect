# Homologação — RS Connect 36.6.20

## Agenda — não criar eventos em conversa comum

- [ ] Aplicar migration 056.
- [ ] Rebaixar/importar o template **Agenda Google Calendar**.
- [ ] Confirmar no workflow o node **É compromisso real?**.
- [ ] `Tenho uma reunião amanhã às 10h` não cria evento Google.
- [ ] `Consulta sexta à tarde` não cria evento Google.
- [ ] `Qual o horário de atendimento?` não cria evento Google.
- [ ] `Quero marcar uma reunião amanhã às 10h` entra no fluxo de agenda.
- [ ] `terça às 15h`, dentro de um fluxo de agenda já aberto, continua como preferência válida.
- [ ] Mudança de status de um compromisso não cria outro evento duplicado.
- [ ] Não surgem novos eventos com título fallback `Compromisso RS Connect`.

## Backup — callback

- [ ] O node **Callback RS Connect** retorna HTTP 2xx.
- [ ] O template novo contém `X-RS-Backup-Token`.
- [ ] O template mantém `X-RS-Connect-Token` por compatibilidade.
- [ ] O job termina como `success`.
- [ ] `last_success_at` da rotina é atualizado.
- [ ] Um callback repetido do mesmo job é tratado de forma idempotente.

## Regressão

- [ ] Horário comercial continua respeitado.
- [ ] Tempo de espera/cooldown continua respeitado.
- [ ] Cliente/Paciente não volta para triagem indevida.
- [ ] Fila rápida da IA permanece ativa.
- [ ] Manutenção automática da agenda permanece ativa.

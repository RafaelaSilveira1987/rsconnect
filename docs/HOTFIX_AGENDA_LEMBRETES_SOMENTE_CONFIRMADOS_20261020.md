# RS Connect — Hotfix de lembretes somente após confirmação (20/10/2026)

## Incidente

A interface mostra um pré-agendamento aguardando validação da equipe, mas o paciente recebeu uma mensagem automática de lembrete. Isso é incompatível com o fluxo que exige aprovação humana.

## Diagnóstico confirmado no código

Existem dois mecanismos de notificação:

1. `CalendarClientCommunicationService` — lembrete e confirmação de presença do paciente; já consultava `status=confirmed`, mas não validava consistentemente `approval_status` e o marcador de pré-agendamento em todos os caminhos.
2. `NotificationOrchestratorService`/`NotificationDeliveryService` — notificação e lembrete operacional da equipe. O worker aceitava `scheduled` como lembrete e a rotina de pré-reserva chamava a função de agendamento de lembrete; por isso uma reserva ainda não aprovada podia entrar na fila geral.

Nenhuma dessas filas deve considerar `scheduled`, `pre_scheduled` ou `awaiting_approval` como confirmação definitiva. A notificação **interna para aprovação** é preservada.

## Correção

- `AppointmentReminderEligibilityService::isConfirmed()` centraliza a elegibilidade: exige `status=confirmed`; se houver `approval_status`, precisa ser `approved`; um `is_pre_schedule=1` sem aprovação explícita é rejeitado.
- O orquestrador consulta a linha real de `calendar_appointments` antes de enfileirar lembretes.
- O worker da fila `notification_jobs` consulta novamente estado e aprovação **imediatamente antes** do envio.
- O worker da fila `calendar_client_message_jobs` exige a mesma elegibilidade para lembrete, confirmação e pedido de presença.
- O agendador de mensagens ao paciente não cria jobs de lembrete ou presença para pré-reservas não confirmadas.
- A criação de um compromisso em `scheduled` ou `pre_scheduled` deixa de agendar lembrete; a transição manual para `confirmed` agenda após a confirmação persistida.
- `notifyProfessional()` mantém alerta para equipe, sem agendar lembrete enquanto aguarda aprovação.

## Arquivos

- `app/Services/AppointmentReminderEligibilityService.php` (novo)
- `app/Services/NotificationOrchestratorService.php`
- `app/Services/NotificationDeliveryService.php`
- `app/Services/CalendarClientCommunicationService.php`
- `app/Services/CalendarConversationService.php`
- `app/Controllers/CalendarController.php`
- `tests/Feature/calendar-reminder-requires-confirmation-20261020.php` (novo)

## Instalação

Faça backup dos arquivos e do banco. Para uma instalação no pacote **Hotfix WhatsApp — Lista de Agendamentos** (sem a correção de triagem de 20/10), aplique o **patch cumulativo**, que contém a correção Sim/Não + o bloqueio de lembretes. Para instalações em que a correção Sim/Não já foi instalada, utilize o **patch incremental de lembretes**. Não aplique ambos.

Não exige migrations e não altera registros de agenda. Reinicie o PHP/OPcache se a implantação utilizar cache de classes.

### Consultas somente-leitura (opcional, MySQL)

Antes de consultar dados reais, restrinja a empresa e o intervalo desejado. Exemplo com placeholders do seu cliente SQL:

```sql
SELECT id, status, is_pre_schedule, approval_status, starts_at
FROM calendar_appointments
WHERE tenant_id = ? AND id = ?;

SELECT id, event_key, status, scheduled_at
FROM calendar_client_message_jobs
WHERE tenant_id = ? AND appointment_id = ?
ORDER BY id DESC LIMIT 30;

SELECT id, event_key, channel, status, scheduled_at
FROM notification_jobs
WHERE tenant_id = ? AND entity_type = 'appointment' AND entity_id = ?
ORDER BY id DESC LIMIT 30;
```

Jobs já criados e ainda não enviados serão descartados pelo worker se o compromisso não estiver confirmado. Não é necessário limpar filas nem apagar compromissos.

## Homologação

1. Criar pré-agendamento com aprovação humana: `pre_scheduled`/`awaiting_approval`; deixar chegar o horário do lembrete em ambiente de teste. **Nenhuma mensagem de lembrete/presença ao paciente**; o aviso interno à equipe permanece.
2. Aprovar a reserva: `confirmed` e aprovação `approved`. **Lembretes passam a ser elegíveis** nos períodos futuros.
3. Cancelar/rejeitar/remarcar antes do lembrete: o worker deve descartar jobs antigos.
4. Testar compromisso legado `scheduled` sem confirmação: não deve disparar lembrete.
5. Verificar que `CalendarClientCommunicationService` não envia mensagens de presença para pré-agendamentos pendentes.
6. Validar concorrência com MySQL, worker e Evolution API antes de produção.

## Limites

Testes de código e simulações foram executados sem banco de produção ou Evolution API. **Automações externas no n8n ou no próprio Google Calendar não são controladas por este patch** e precisam conferir a mesma regra de confirmação. O worker atual revalida imediatamente antes de tentar o envio, mas não substitui uma auditoria de concorrência com múltiplos workers no ambiente real.

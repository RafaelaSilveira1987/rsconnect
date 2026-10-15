# RS Connect — Agenda / Etapa 2: segurança das reservas

Data: 15/10/2026. Base: `rs-connect-agenda-etapa1-operacoes-completo.zip`.

## Escopo implementado

- `InternalCalendarSlotService`: serialização de pré-reserva, confirmação e liberação do modo **published** com capacidade, por transações InnoDB. Trava o compromisso (`calendar_appointments`) e o horário publicado (`calendar_internal_slots`) com `SELECT ... FOR UPDATE`.
- Capacidade 1/N: a contagem de `calendar_internal_slot_allocations` exclui reservas expiradas e o próprio compromisso, e é feita enquanto o horário está travado. Há recusa ao atingir `capacity_total`.
- Idempotência **por compromisso e slot**: a unique key `(slot_id, appointment_id)` existente na migration 124 e o lock do compromisso evitam reserva duplicada em retries locais. Tentativas idênticas em um horário já confirmado não criam outra vaga. Reservas confirmadas em outro horário impedem novo hold (exige fluxo autorizado de remarcação).
- Confirmação: verifica novamente o horário, rejeita bloqueios e faz a conversão `held → booked` com condição SQL de validade (`expires_at >= NOW()`). Uma chamada repetida já confirmada não ocupa lugar adicional.
- Liberação: remove as alocações do compromisso e atualiza a ocupação dos horários na mesma transação, travando os horários em ordem crescente de ID.
- Expiração: limpeza por horário com lock do respectivo slot e transação, em vez de `DELETE` global seguido de sincronização de status sem trava.
- `PublishedSlotCapacityPolicy`: regra pura de capacidade e idempotência, com verificações executáveis.

## Limites importantes desta entrega

1. **A garantia transacional acima vale para vagas publicadas (`internal_availability_strategy=published`) quando a migration 124 foi aplicada e a tabela de alocações existe.** Agendas calculadas (`calculated`) e Google Calendar seguem serviços diferentes; elas **não** ganharam um lock distribuído nesta etapa.
2. A seleção em `CalendarAvailabilityService::applySlot` ainda efetua a gravação final dos metadados da escolha *depois* do hold. Uma falha entre etapas pode deixar um hold órfão até a expiração. A operação falha e não pode ser apresentada como confirmação.
3. Há limpeza legada de compromissos inativos em caminhos separados, ainda a ser incorporada ao mesmo protocolo transacional num próximo ciclo.
4. Para integração Google, `CalendarGoogleLifecycleService` já implementa `sync_status`, `google_event_state`, callback e manutenção de falhas. Enviar uma requisição não significa sincronização concluída. Não foi alterado o fluxo de criação/deleção Google nesta etapa.
5. Não está habilitado cancelamento ou reagendamento automático pelo agente nesta entrega.
6. **Não foi realizada homologação concorrente usando duas conexões reais MySQL/InnoDB.** Os testes são de política, contratos do código e regressões. A produção requer testes integrados.

## Homologação obrigatória

1. Fazer backup do banco e do projeto; aplicar primeiro em empresa de testes e verificar migration 124.
2. Abrir dois dispositivos/sessões e solicitar a última vaga do mesmo horário simultaneamente (capacidade 1). Somente uma alocação ativa pode permanecer.
3. Capacidade 4: quatro agendamentos distintos podem ocupar a turma; a quinta tentativa deve ser rejeitada.
4. Repetir a mesma requisição para o mesmo compromisso/slot: ocupação total não pode aumentar.
5. Testar expiração do hold antes de confirmar: confirmação deve falhar, sem `booked` indevido.
6. Simular confirmação e liberação concorrentes do mesmo compromisso: somente um estado final coerente deve persistir.
7. Simular falha na gravação após o hold, verificar a recuperação por expiração e a apresentação de erro ao cliente.
8. Simular indisponibilidade/timeout do Google e callback tardio, verificando `sync_status`, filas de manutenção e mensagens finais (sem declarar confirmação não verificada).
9. Verificar novamente permissões de aprovação humana, antecedência, modalidade/dias presenciais, regras fora do expediente e fila.

## Validação automática disponível

```bash
php tests/Feature/agenda-reservation-safety-phase2-smoke.php
php tests/Feature/calendar-ambiguity-capacity-v36420-smoke.php
php tests/Feature/calendar-capacity-live-availability-v36431-smoke.php
php tests/Feature/appointment-operation-policy-phase1-smoke.php
```

Não são exigidas migrations novas nesta etapa: a capacidade e chave única necessárias já estão na migration 124. Se ela não foi executada, o modo publicado recorre ao fluxo antigo de vaga individual, sem as garantias de capacidade N.

**Próxima etapa proposta:** consolidar a escolha do slot e seus metadados em uma única transação local, fechar os caminhos de limpeza legada e executar a bateria MySQL concorrente, antes de permitir cancelamento/reagendamento automáticos.

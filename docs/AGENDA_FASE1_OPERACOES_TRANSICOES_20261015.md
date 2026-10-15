# RS Connect — Agenda, etapa 1: autorização e transições

Base: `rs-connect-modalidades-fonte-unica-completo.zip`. Runtime existente PHP 8.2+/PDO/MySQL (não Laravel).

## Escopo efetivamente implementado

1. Criado `App\Services\AppointmentOperationPolicyService` (puro, sem escrita no banco, sem LLM). O contrato `evaluate(operation, tenantId, appointment, settings, reschedulingEnabled)` recebe estado e permissões e devolve `{allowed, code, message}`.
2. Fluxo de confirmação via WhatsApp (`CalendarConversationService`) consulta a política antes de permitir confirmação; **reconsulta o agendamento persistido** e as configurações imediatamente antes de executar a confirmação. Aprovação humana prevalece sobre `ai_can_confirm`. A disponibilidade, profissional e Google Calendar continuam validados pelos serviços anteriores.
3. Consultas e solicitações de cancelamento/remarcação (`ExistingAppointmentConversationService`) consultam a política. Pedidos não executam cancelamento nem liberam vaga; solicitações de remarcação respeitam a capability existente e preservam a reserva original.
4. Alteração manual pelo painel (`CalendarController::updateStatus`) valida transições antes de editar link e chamar integrações. A atualização SQL final passou a exigir `status = :expected_status` para detectar status modificado por outra operação.
5. Sem migrações novas, sem alterações visuais, sem reconfigurar empresas existentes, sem alterar preço, capacidade, fila fora do expediente ou agenda publicada.

## Transições do painel

- `pre_scheduled`: `awaiting_approval`, `scheduled`, `confirmed`, `rejected`, `cancelled`.
- `awaiting_approval`: `pre_scheduled`, `scheduled`, `confirmed`, `rejected`, `cancelled`.
- `scheduled`: `confirmed`, `completed`, `cancelled`, `rescheduled`, `no_show`, `rejected`.
- `confirmed`: `completed`, `cancelled`, `rescheduled`, `no_show`.
- Estados finais `completed`, `cancelled`, `rejected`, `no_show` não podem ser reativados por troca simples de status.

## Limitações e riscos restantes

- **Não foi executado teste concorrente real em MySQL**, nem teste conectado ao Google Calendar. O `status = :expected_status` evita sobrescrita silenciosa no final da alteração, mas **não torna atômicas** as chamadas externas que acontecem antes do UPDATE. Se o estado mudar entre a consulta e a sincronização Google, ainda pode haver efeitos externos e compensação necessária.
- Confirmação automática ainda utiliza os serviços legados para selecionar/reservar capacidade; este patch não implementa locking de última vaga ou uma outbox transacional. Não afirmar concorrência totalmente resolvida.
- Cancelamento automático e remarcação automática de compromissos confirmados **não foram liberados**. O cliente continua solicitando, equipe decide. Esse comportamento impede afirmações falsas de cancelamento.
- A aprovação manual de pré-agendamentos via painel ainda usa os validadores legados; o patch adicionou validação de transições, mas não substitui esses validadores.
- A regra de `lookup` do serviço legado e a fila fora do horário são preservadas.
- A camada de configuração por empresa já existente foi preservada; o trabalho de unificação das fontes de dados continua sendo dependência da homologação.

## Testes locais executáveis

```bash
php tests/Feature/appointment-operation-policy-phase1-smoke.php
php tests/Feature/calendar-capacity-live-availability-v36431-smoke.php
php tests/Feature/calendar-ambiguity-capacity-v36420-smoke.php
php tests/Feature/calendar-client-continuity-v36390-smoke.php
php tests/Feature/calendar-google-confirmed-only-smoke.php
php tests/Feature/calendar-modality-before-availability-smoke.php
php tests/Feature/after-hours-calendar-recovery-smoke.php
```

Esses testes verificam lógica pura e contratos estáticos. Não substituem integração real.

## Homologação antes de produção

1. Backup de banco e configuração. Implantar em uma empresa de teste; confirmar que não há migrations pendentes.
2. Com aprovação humana ativa, contato envia “confirmo”: deve manter `awaiting_approval`, sem confirmação automática ou notificação falsa.
3. Com confirmação automática ativa, triagem concluída e slot reservado: validar mudança para `confirmed`, capacidade, Google e mensagem real. Repetir a mesma mensagem e conferir duplicidades.
4. Executar duas confirmações simultâneas da última vaga com dois contatos e com um mesmo compromisso. Garantir que não ultrapasse capacidade e não produza eventos Google órfãos.
5. Solicitar cancelamento via WhatsApp: apenas marcar `cancel_requested`, manter vaga ocupada até a equipe executar o cancelamento. Aprovação manual deve liberar vaga uma única vez.
6. Solicitar reagendamento: manter horário antigo enquanto o novo não for confirmado e sincronizado; falha de Google não deve deixar ambas as vagas liberadas.
7. Simular alteração concorrente de status feita pela equipe entre leitura e gravação; a segunda gravação deve ser rejeitada. Verificar possíveis compensações de Google.
8. Testar callback atrasado fora do expediente, capacidade compartilhada (4 pessoas/hora), modalidades e antecedência mínima.

## Próxima etapa de código

Implementar transações e reservas concorrentes no MySQL com `SELECT ... FOR UPDATE` / locks por slot; usar idempotência por evento, outbox para comunicação e compensação Google. Depois conectar cancelamento e reagendamento efetivos sob autorização explícita e homologar ponta a ponta.

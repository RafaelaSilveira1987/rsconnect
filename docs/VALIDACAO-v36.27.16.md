# Validação — RS Connect 36.27.16

## 1. Validação estática

```bash
php -l app/Services/CalendarConversationService.php
php -l app/Services/AiModelService.php
php -l app/Services/AppVersionService.php
php tests/Feature/calendar-conversational-confirmation-v362716-smoke.php
php tests/Feature/agent-compact-scheduling-routing-v362715-smoke.php
```

## 2. Configuração esperada para confirmação pela IA

Na empresa de teste:

- pré-agendamento ativo;
- `IA pode confirmar` ativo;
- `Aprovação humana obrigatória` desativada;
- disponibilidade interna ativa;
- agente de agendamento corretamente roteado.

## 3. Teste E2E obrigatório

1. Inicie uma conversa nova por outro número de WhatsApp.
2. Peça um agendamento.
3. Escolha um dos horários retornados pela agenda interna.
4. Confirme com uma resposta curta, por exemplo `Pode`.
5. Verifique que a mensagem final só ocorre após a confirmação técnica.
6. Abra **Agenda > Mês/Semana** e confirme que o compromisso aparece na data/hora selecionada.
7. Valide no banco:

```sql
SELECT id, tenant_id, conversation_id, contact_id, title,
       starts_at, ends_at, status, is_pre_schedule,
       approval_status, availability_status,
       chosen_availability_slot_id, updated_at
FROM calendar_appointments
WHERE conversation_id = <ID_DA_CONVERSA>
ORDER BY id DESC
LIMIT 5;
```

Resultado esperado para o compromisso concluído:

```text
status=confirmed
is_pre_schedule=0
approval_status=approved
chosen_availability_slot_id > 0
```

## 4. Cenário com aprovação humana

Ative `Aprovação humana obrigatória`. Repita o teste e responda `Pode`.

Esperado:

- a IA não deve afirmar que o agendamento está confirmado;
- `status` permanece `awaiting_approval`;
- `is_pre_schedule` permanece `1`;
- a mensagem informa que a validação final depende da equipe.

## 5. Não aprovação

Quando a confirmação pela IA estiver liberada, responda `Não` à pergunta final.

Esperado:

- o horário não fica confirmado;
- a seleção é liberada quando aplicável;
- o cliente é convidado a informar outra preferência;
- nenhuma mensagem de confirmação final é enviada.

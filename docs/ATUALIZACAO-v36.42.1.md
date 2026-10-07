# RS Connect 36.42.1 — Confirmação e lembretes isolados por agendamento

Esta atualização corrige o cenário em que a confirmação de um agendamento atual podia, no mesmo processamento, disparar lembretes ou pedidos de presença pertencentes a outro compromisso do mesmo cliente/empresa.

## Causa identificada

Ao alterar um compromisso para `confirmed`, o serviço criava a mensagem de confirmação correta, mas em seguida chamava o processador geral da fila usando apenas o `tenant_id`. Com isso, qualquer job antigo já vencido e ainda `pending/retry` da mesma empresa também podia ser enviado naquele instante. Foi exatamente o padrão observado: mensagens de um compromisso antigo de 02/10 apareciam junto da confirmação válida de 08/10.

Além disso, a validação da fila verificava status e horário esperado, mas não descartava explicitamente lembrete/confirmação de presença depois que o compromisso já tinha iniciado. Um job acumulado por indisponibilidade do cron podia, portanto, sobreviver por dias.

## Correções aplicadas

- o processamento imediato após criação/confirmação/cancelamento/remarcação agora recebe também o `appointment_id` e só entrega jobs desse compromisso;
- a recuperação de jobs travados em `processing` usa o mesmo escopo quando a chamada é transacional;
- `appointment.reminder`, `appointment.presence_request` e `appointment.confirmed` são ignorados se o horário do compromisso já começou;
- o worker geral continua processando a fila normalmente, mas jobs históricos vencidos passam para `skipped` em vez de chegar ao WhatsApp;
- `{{local}}` foi separado de `meeting_url`: em atendimento online sem URL a mensagem mostra `Atendimento: Online`; quando existe link no próprio compromisso, mostra `Link: ...` desse mesmo registro;
- o campo de link informado no ato da confirmação continua sendo persistido antes da comunicação ao cliente.

## Banco de dados

Não existe migration nova nesta versão. Permanece obrigatória:

`124_calendar_slot_capacity_mode.sql`

## Validação realizada

O teste `calendar-client-appointment-isolation-v36421-smoke.php` cobre:

- descarte de lembrete vencido;
- descarte de pedido de presença vencido;
- preservação de lembrete futuro;
- filtro do processamento imediato por `appointment_id`;
- confirmação online usando apenas o `meeting_url` do compromisso atual;
- ausência de link inventado/reaproveitado quando o compromisso atual não possui URL;
- persistência do link antes do envio da confirmação.

A suíte smoke completa foi comparada com o ZIP original: o pacote original apresentava 57 testes históricos já reprovados; após esta correção continuam exatamente os mesmos 57, sem nova regressão, e foi adicionado um novo teste aprovado para este hotfix.

## Cenário de homologação recomendado

1. mantenha um compromisso antigo confirmado para o mesmo contato, inclusive com link próprio;
2. crie/pré-reserve um novo horário futuro;
3. informe um link diferente no novo compromisso;
4. confirme o novo agendamento;
5. valide que o WhatsApp recebe somente a data/hora do novo compromisso e somente o link salvo nele;
6. execute o processador de notificações e confirme que jobs de compromissos já iniciados são ignorados, não enviados.

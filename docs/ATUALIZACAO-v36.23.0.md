# Atualização v36.23.0

A versão cria um motor central para notificações de agenda e orçamento.

## Eventos iniciais

- `calendar.appointment.created`
- `calendar.appointment.confirmed`
- `calendar.appointment.cancelled`
- `calendar.appointment.rescheduled`
- `calendar.appointment.reminder`
- `commercial.quote.requested`
- `commercial.quote.overdue`

## Canais

- central interna do RS Connect;
- WhatsApp para a equipe pela Evolution API.

## Segurança operacional

- deduplicação por evento, entidade, canal e ciclo;
- quatro tentativas com intervalos progressivos;
- recuperação de jobs presos em processamento;
- descarte de lembretes cancelados ou desatualizados;
- descarte de orçamento atrasado quando a pendência já foi resolvida;
- bloqueio de envio para o próprio número conectado.

## Rotina

```bash
php bin/process-notifications.php
```

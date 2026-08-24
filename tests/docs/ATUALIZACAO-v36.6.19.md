# RS Connect 36.6.19 — Retry confiável de backup

## Problema corrigido

O monitor operacional alertava backup com mais de 24h, mas o endpoint `/webhooks/operations/backups/dispatch` podia retornar `dispatched: 0` depois de uma tentativa falha no mesmo dia.

A causa era o uso de `last_requested_at` para decidir se o ciclo diário já havia sido atendido. Uma solicitação não significa backup concluído.

## Nova regra

- `last_success_at`: fonte de verdade para saber se o backup foi realmente concluído.
- `last_requested_at`: somente controle de cooldown entre tentativas.
- Erro/timeout: rotina volta a ficar elegível após 30 minutos por padrão.
- Backup acima de `max_age_hours`: permanece elegível até existir novo sucesso real.
- Job `requested/running`: impede duplicidade enquanto estiver ativo.

## Configuração opcional

```env
OPERATIONS_BACKUP_RETRY_MINUTES=30
```

Faixa aceita: 5 a 240 minutos. Se omitido, usa 30 minutos.

## Dispatcher auditável

O retorno agora inclui `routines_checked`, `eligible` e `evaluated`. Motivos possíveis incluem `backup_overdue`, `schedule_due`, `retry_cooldown`, `covered`, `before_schedule`, `active_job` e `manual_frequency`.

## Banco

Não há migration nova.

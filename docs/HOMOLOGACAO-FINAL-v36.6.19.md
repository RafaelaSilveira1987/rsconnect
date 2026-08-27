# Homologação — RS Connect 36.6.19

1. Confirmar que a rotina de backup está ativa e possui horário/fuso corretos.
2. Executar o endpoint de dispatch e conferir `evaluated`.
3. Com backup vencido e sem job ativo, esperar `reason=backup_overdue` e `due=true`.
4. Após uma tentativa falha recente, esperar `reason=retry_cooldown`, `due=false` e `next_retry_at`.
5. Após o cooldown, executar novamente: a rotina deve voltar a ficar elegível no mesmo dia.
6. Após callback de sucesso real, executar novamente: esperar `reason=covered` e nenhum novo disparo.
7. Confirmar que o monitor operacional deixa de alertar após o novo backup verificado.
8. Rodar `php tests/Feature/backup-dispatch-retry-smoke.php`.

# RS Connect 36.36.11 — retomada pós-horário

## Sintoma

A empresa abre às 09:00, mas conversas recebidas antes do expediente permanecem em **Aguardando horário**. O detalhe pode mostrar `Mensagem aguardando o tempo configurado após a última interação antes da resposta da IA.` mesmo vários minutos depois da abertura.

## Causa

O banco persiste timestamps técnicos em UTC. A regra de cooldown lia esses valores sem declarar o fuso e o PHP os interpretava em `APP_TIMEZONE` (por exemplo, `America/Sao_Paulo`). Assim, uma mensagem de 08:32 local, gravada como 11:32 UTC, podia ser interpretada como 11:32 local e parecer estar no futuro. O monitor também fazia a mesma leitura incorreta em `last_run_at`.

## Correção

- `AiReplyTimingService` interpreta timestamps de mensagens em UTC antes de calcular o silêncio restante.
- `AfterHoursMonitorService` interpreta `last_run_at` em UTC.
- `AiAfterHoursRecoveryService` usa UTC na expiração e no fallback de busca de tentativas.
- A deduplicação do aviso de ausência converte UTC para o dia local somente depois da leitura.

## Homologação sugerida

1. Configure quarta-feira com abertura 09:00.
2. Envie 2 ou 3 mensagens antes das 09:00 e confirme que ficam em **Aguardando horário**.
3. Aguarde a abertura e a próxima execução do monitor pós-horário.
4. Confirme que a fila é retomada e a IA responde sem permanecer presa em `ai.cooldown`.
5. Envie uma mensagem poucos segundos antes das 09:00 e confirme que o sistema respeita somente o restante real do tempo de silêncio.

> A retomada depende da rotina `bin/ai-after-hours-recovery.php`/cron estar ativa. O intervalo configurado no monitor define o atraso máximo entre a abertura e a tentativa automática.

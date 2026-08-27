# RS Connect v36.20.10 — fila fora do horário

## Correções

### Aviso de ausência

O webhook agora registra a pendência e envia a mensagem fixa de ausência antes de qualquer tentativa de IA. Isso vale para conversas em modo `ai`, `human` ou `paused`.

A deduplicação utiliza `ai_after_hours_pending.ack_sent_at` e um lock por conversa, evitando duas respostas quando várias mensagens chegam simultaneamente.

### Retomada na abertura

`next_attempt_at` passa a receber a próxima abertura real calculada por `AgentOperatingPolicyService::nextOpeningAt()`, convertida para UTC. A rotina deixa de depender de janelas aproximadas de 15 minutos.

### Responsabilidade humana

Pendências em modo humano permanecem como `blocked_human`. O aviso de ausência pode ser enviado, mas a IA não assume nem responde a fila enquanto o modo não voltar para `ai`.

## Dependência operacional

A retomada contínua depende do workflow n8n **Fila rápida da IA**, executado a cada minuto contra `/webhooks/ai-reprocess/queue`.

## Migration

Não há migration nova.

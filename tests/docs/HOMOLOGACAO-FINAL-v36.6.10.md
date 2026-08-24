# Homologação final — RS Connect 36.6.10

## A. Versão e banco

- [ ] O Status do sistema mostra **RS Connect 36.6.10**.
- [ ] A migration `053_ai_quota_limit_repair.sql` foi aplicada.
- [ ] O check **Franquia de IA nos planos** aparece OK.

## B. Franquia

- [ ] Starter mostra 1.500 interações IA/mês.
- [ ] Profissional mostra 8.000 interações IA/mês.
- [ ] Business mostra 30.000 interações IA/mês.
- [ ] Custom sem valor definido continua ilimitado.
- [ ] Mensagem humana não aumenta consumo.
- [ ] Mensagem recebida não aumenta consumo.
- [ ] Resposta automática com credencial RS aumenta em 1.
- [ ] Resposta automática com credencial Cliente não reduz franquia RS.

## C. Template da fila rápida

- [ ] Em **n8n → Templates** existe o cartão **Fila rápida da IA**.
- [ ] O download gera `template-fila-rapida-ia.json`.
- [ ] O workflow importado possui Schedule Trigger de 1 minuto.
- [ ] O HTTP Request usa POST.
- [ ] A URL termina em `/webhooks/ai-reprocess/queue`.
- [ ] O header é `X-RS-AI-Reprocess-Token`.
- [ ] O workflow está ativo.

## D. Cooldown

Configure temporariamente 60 segundos.

- [ ] Faça a IA responder uma vez.
- [ ] Envie outra mensagem 10–20 segundos depois.
- [ ] Ela é armazenada, mas a IA não responde antes de completar 60 segundos desde a última saída IA.
- [ ] Envie mais mensagens durante a espera.
- [ ] Após o prazo + próxima execução da fila, ocorre somente uma resposta com o contexto acumulado.
- [ ] Em modo Humano/Pausado, a fila não responde.

## E. Conversas

- [ ] Envie mensagem humana pelo campo de digitação.
- [ ] A página não volta ao topo.
- [ ] O campo é limpo.
- [ ] O cursor permanece no campo.
- [ ] A mensagem aparece no fim do histórico.
- [ ] O modo passa para Humano.

## F. Pós-horário

- [ ] Mensagens fora do expediente ficam preservadas.
- [ ] No próximo horário válido são reavaliadas.
- [ ] Se Humano assumir antes, nenhuma resposta automática é enviada.
- [ ] Se a franquia RS estiver esgotada, permanece aguardando franquia.

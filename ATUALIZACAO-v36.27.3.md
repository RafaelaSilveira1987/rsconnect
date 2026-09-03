# RS Connect 36.27.3 — Handoff multiagente identificável

## Correções

- Transferência IA→IA passa a gerar contexto explícito para o novo especialista.
- O novo agente assume a conversa sem reiniciar o atendimento e pode se identificar na primeira resposta.
- A IA anterior não deve afirmar que uma transferência automatizada ocorreu se o motor não confirmou a troca.
- Novas mensagens automáticas exibem autoria por agente: `IA - Digi`, `IA - Carlos`, etc.
- A atualização em tempo real usa o mesmo nome gravado no histórico.

## Compatibilidade

- Sem migration nova.
- Mantém migration obrigatória `099_ai_agent_round_robin_routing.sql`.
- Mantém round-robin, especialista, pinning, horário e takeover humano.

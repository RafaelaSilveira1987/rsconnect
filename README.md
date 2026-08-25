# RS Connect — v36.18.5

Esta versão fecha o comportamento operacional do takeover humano e entrega a primeira consolidação completa da documentação do projeto.

## Destaques

- Fila fora do horário removida imediatamente quando a equipe assume.
- Cancelamento atômico da recuperação automática.
- Proteção contra corrida entre o atendente e o worker.
- Resposta humana em texto ou anexo encerra pendências remanescentes.
- Atribuição e transferência preservam bloqueio exclusivo e retiram a conversa da fila automática.
- Liberação devolve à fila humana sem reativar IA automaticamente.
- Textos da interface explicam responsabilidade, bloqueio e liberação.
- Pacote de documentação em `docs/guias`.

## Atualização

- Não exige migration nova.
- Última migration obrigatória: `079_ai_efficiency_phase2_and_report_cleanup.sql`.
- Diagnóstico opcional: `database/diagnostics/after_hours_takeover_v36.18.5.sql`.
- Faça rebuild/redeploy e limpe o cache do navegador.

Consulte `INSTRUCOES-v36.18.5.md` e `docs/guias/README.md`.

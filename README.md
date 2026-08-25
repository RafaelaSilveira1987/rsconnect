# RS Connect — v36.20.0

Esta versão adiciona **rentabilidade histórica e simulação comercial da IA** ao Super Admin. O RS Connect passa a acompanhar MRR de referência, contribuição conhecida, margem mês a mês e cenários de plano por capacidade e margem-alvo.

## Destaques

- Nova tela **Rentabilidade IA**.
- MRR de referência e MRR sob revisão.
- Histórico mensal por empresa e da carteira.
- Receita histórica baseada em fatura, assinatura ou política manual.
- Snapshots mensais para preservar a leitura calculada.
- Tendência de receita, custo de IA e margem.
- Simulação dos planos ativos considerando capacidade real de uso.
- Mensalidade customizada simulável sem alterar contratos.
- Recomendação comercial explicável: manter, otimizar primeiro, revisar plano ou usar condição customizada.
- Script CLI opcional para snapshot diário.
- Documentação do ponto 9 atualizada.

## Atualização

Execute:

```text
database/migrations/084_ai_profitability_history.sql
```

Depois valide:

```text
database/diagnostics/ai_profitability_history_v36.20.0.sql
```

O primeiro resultado deve retornar `OK`.

Não existem novas variáveis obrigatórias no `.env`.

Consulte `INSTRUCOES-v36.20.0.md` e `docs/guias/README.md`.

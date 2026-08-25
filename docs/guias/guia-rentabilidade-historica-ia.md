# Guia — Rentabilidade histórica e simulação comercial da IA

## Objetivo

A tela **Rentabilidade IA** transforma a telemetria técnica em visão comercial mês a mês. Ela não substitui a contabilidade: os números representam receita de referência, custos conhecidos e contribuição conhecida.

## Indicadores do portfólio

- **MRR de referência:** soma da receita mensal de referência das empresas configuradas.
- **IA projetada:** custo mensal projetado da IA custeada pela RS Connect.
- **Contribuição conhecida:** receita menos IA projetada e outros custos informados.
- **Margem consolidada:** contribuição conhecida dividida pela receita.
- **MRR sob revisão:** receita das empresas abaixo da margem-alvo configurada.

## Histórico mensal

A leitura histórica prioriza estas fontes de receita:

1. faturas do período, alocadas proporcionalmente quando cobrem mais de um mês;
2. assinatura vigente no período, convertida para equivalente mensal;
3. política manual de receita, quando configurada.

O custo de IA vem de `ai_usage_events` com `credential_owner = rs_connect`. Os snapshots mensais são gravados em `tenant_ai_profitability_snapshots` para preservar a leitura histórica.

## Qualidade da receita

- **Faturado/pago:** existe faturamento pago usado como referência.
- **Contratado:** faturamento aberto/atrasado, assinatura ou política histórica contratada.
- **Estimado:** foi necessário usar a política comercial atual para um período anterior sem histórico próprio.
- **Sem base:** não foi encontrada receita confiável para o período.

## Simulação de plano

A simulação compara:

- preço mensal do plano;
- custos conhecidos projetados;
- margem-alvo da empresa;
- uso atual contra os limites do plano.

Um plano só aparece como referência recomendada quando cobre **margem e capacidade**. O RS Connect não troca o plano automaticamente.

## Mensalidade customizada

É possível informar um valor hipotético para visualizar a margem correspondente. A simulação não altera assinatura, cobrança nem contrato.

## Snapshot recomendado

A tela atualiza o mês corrente ao ser aberta. Para preservar snapshots sem depender de acesso manual, pode-se agendar:

```bash
php bin/ai-profitability-snapshot.php
```

Para recalcular um mês específico:

```bash
php bin/ai-profitability-snapshot.php 2026-08
```

Uma execução diária é suficiente.

## Cuidados

- Não chame a margem exibida de lucro líquido.
- Cadastre outros custos mensais atribuíveis ao cliente para melhorar a leitura.
- Revise planos apenas após validar custo, volume e capacidade operacional.
- A recomendação do sistema é apoio à decisão; não é reajuste automático.

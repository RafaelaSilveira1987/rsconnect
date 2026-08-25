# RS Connect — v36.19.3

Esta versão transforma o consumo de IA por empresa em **gestão comercial da franquia**. O Super Admin passa a comparar receita de referência, custo projetado, demais custos informados e margem de contribuição conhecida por cliente.

## Destaques

- Receita de referência baseada na assinatura atual ou em valor manual.
- Conversão de assinaturas trimestrais, semestrais e anuais para equivalente mensal.
- Custo atual e projeção de IA custeada pela RS em USD e BRL.
- Campo opcional para outros custos mensais atribuídos ao cliente.
- Margem-alvo e margem de atenção configuráveis por empresa.
- Identificação visual de operação saudável, em atenção, com margem baixa ou prejuízo conhecido.
- Cálculo da receita mínima de referência para sustentar a margem-alvo.
- Cotação USD/BRL por empresa com fallback para `OPENAI_USAGE_USD_BRL`.
- Integração com orçamento e governança da v36.19.2 sem alteração automática de planos/preços.
- Auditoria da política comercial.
- Documentação operacional e comercial atualizada (ponto 9).

## Atualização

Atualizando a partir da v36.19.2, execute:

```text
database/migrations/083_ai_commercial_margin.sql
```

Depois valide:

```text
database/diagnostics/ai_commercial_margin_v36.19.3.sql
```

O primeiro resultado deve retornar `OK`.

Não existem novas variáveis obrigatórias no `.env`. Para valores em reais, mantenha `OPENAI_USAGE_USD_BRL` configurada ou informe uma cotação específica na política da empresa.

## Importante

A margem apresentada é uma **margem de contribuição conhecida**, não lucro líquido. Custos não informados no campo de outros custos mensais não entram no cálculo.

Consulte `INSTRUCOES-v36.19.3.md` e `docs/guias/README.md`.

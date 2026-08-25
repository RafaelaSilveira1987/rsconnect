# Guia de margem comercial da franquia de IA

## Objetivo

A partir da v36.19.3, o RS Connect cruza o custo técnico de IA atribuído a cada empresa com a receita comercial de referência. O objetivo é mostrar se a operação está confortável, em atenção ou exigindo revisão de preço/custo.

A análise não é lucro líquido. O indicador principal é uma **margem de contribuição conhecida**:

```text
Receita de referência
- custo projetado da IA custeada pela RS
- demais custos mensais informados
= contribuição conhecida
```

## Receita de referência

Existem duas opções:

1. **Assinatura atual**: utiliza o valor da assinatura da empresa. Ciclos trimestrais, semestrais e anuais são convertidos para equivalente mensal.
2. **Valor manual**: permite informar quanto da receita mensal deve ser considerado na análise daquela operação.

Use o valor manual quando o contrato tiver composição especial, descontos, serviços adicionais ou quando apenas uma parcela da mensalidade deva sustentar a camada de IA.

## Outros custos mensais

O campo é opcional e pode receber custos atribuíveis ao cliente, por exemplo:

- infraestrutura dedicada;
- suporte contratado;
- números/serviços de WhatsApp;
- automações externas;
- serviços de terceiros.

Quanto mais completa for essa informação, mais próxima a margem conhecida ficará da contribuição real. Ainda assim, o painel não substitui contabilidade ou DRE.

## Câmbio

O custo da OpenAI é registrado em USD. Para a visão comercial em reais, o sistema usa nesta ordem:

1. cotação específica da empresa, quando preenchida;
2. `OPENAI_USAGE_USD_BRL` do servidor.

Sem cotação, tokens e custo em dólar continuam disponíveis, mas a margem em BRL fica sem cálculo financeiro confiável.

## Margens

- **Margem alvo**: objetivo comercial usado para calcular a receita mínima de referência.
- **Margem de atenção**: abaixo desse nível a empresa é destacada para revisão.
- **Prejuízo conhecido**: ocorre quando os custos conhecidos projetados superam a receita de referência.

A receita mínima de referência é calculada por:

```text
Custos conhecidos projetados / (1 - margem alvo)
```

Ela não é uma recomendação automática de reajuste contratual. É um ponto de referência para análise comercial.

## Projeção de IA

O painel usa o custo do mês até o momento e projeta o fechamento pelo ritmo médio diário. Nos primeiros dias do mês ou em operações sazonais, a projeção pode oscilar e deve ser interpretada junto do histórico.

## Boas práticas

- Configure primeiro o câmbio de referência.
- Confira se o valor da assinatura corresponde ao ciclo comercial real.
- Use receita manual em contratos customizados.
- Registre custos adicionais que sejam materialmente relevantes.
- Não reajuste um cliente por um único pico diário; valide tendência e causa.
- Cruze a margem com qualidade do atendimento, chamadas evitadas e volume de conversas.
- Revise planos com margem baixa recorrente, não apenas consumo alto isolado.

## Relação com orçamento

**Orçamento de IA** e **margem comercial** são controles diferentes:

- orçamento protege quanto a RS aceita gastar tecnicamente com IA;
- margem compara custo e receita para avaliar a sustentabilidade comercial.

Uma empresa pode estar dentro do orçamento e ainda ter margem ruim se o contrato for barato, assim como pode consumir bastante IA e continuar saudável se a receita cobrir o custo.

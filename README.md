# RS Connect — v36.20.2

Esta versão transforma os dados de custo, margem, plano e limite de gasto em uma lista simples de clientes que precisam de atenção.

## Nova área

```text
WhatsApp e inteligência artificial
└── Clientes que precisam de atenção
```

A tela mostra:

- quem precisa de revisão;
- por que o cliente aparece na lista;
- qual ação é sugerida;
- prioridade em palavras simples;
- anotação e data da próxima revisão;
- situação do acompanhamento.

## Prioridades

- **Ver agora**
- **Revisar nesta semana**
- **Acompanhar**
- **Concluído**

## Exemplos de motivos

- O valor mensal pode estar abaixo do necessário.
- O custo da IA aumentou.
- O gasto está perto do limite.
- O plano atual pode não comportar o uso.
- Faltam informações para calcular o resultado.

Nenhum preço, plano ou contrato é alterado automaticamente.

## Atualização obrigatória

Execute:

```text
database/migrations/085_ai_commercial_attention_queue.sql
```

Depois:

```text
database/diagnostics/ai_commercial_attention_v36.20.2.sql
```

Consulte `INSTRUCOES-v36.20.2.md` e `docs/guias/guia-clientes-que-precisam-atencao.md`.

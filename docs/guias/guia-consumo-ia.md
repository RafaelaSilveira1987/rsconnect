# Guia de consumo e economia de IA

## Consumo oficial

A tela **Consumo OpenAI** consulta a organização usando uma Admin API Key armazenada no servidor. Ela mostra tokens, chamadas, cache, modelos e custo oficial.

## Consumo interno

O RS Connect registra por empresa, assistente e conversa:

- tokens de entrada e saída;
- chamadas ao provedor;
- respostas locais;
- cache exato;
- tokens estimados evitados;
- custo técnico estimado.

## Interpretação

O consumo oficial pode incluir chamadas externas ao RS Connect. Compare os dois painéis antes de atribuir custo a uma empresa.

## Boas práticas

- Comece no modo Equilibrado.
- Mantenha respostas locais para saudações e comandos simples.
- Ative cache somente após homologação.
- Meça custo médio por conversa e taxa de transferência humana.
- Não troque modelo e contexto simultaneamente durante a medição inicial.

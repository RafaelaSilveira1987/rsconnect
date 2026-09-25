# Validação 36.36.22 — Escopo estrito por turno

## Objetivo

Garantir que a opção **Responder só ao que foi perguntado e avançar 1 etapa** seja uma regra efetiva do runtime, e não apenas uma sugestão no prompt.

## Cenário principal

Cliente envia:

- `bom dia`
- `Gostaria de saber como funciona o atendimento`

Com valor/pagamento cadastrados no agente, a resposta pode explicar apenas o funcionamento solicitado e, em seguida, fazer **uma única** próxima pergunta da Ordem do atendimento. O valor não deve ser informado porque não foi perguntado.

Quando o cliente perguntar `Qual o valor?`, os dados configurados de valor/pagamento voltam a ser disponibilizados ao modelo.

## Proteções

1. O prompt operacional não recebe o valor configurado em turno que não pediu preço/pagamento.
2. A resposta gerada passa por uma guarda determinística antes do envio.
3. Cache exato passa pela mesma guarda.
4. O modo estrito mantém no máximo uma pergunta de coleta por turno.
5. Conteúdo de negócio continua vindo do banco/configuração; o código só aplica a política de escopo.

## Não alterado

- Ordem do atendimento e contrato da migration 119.
- Regras de agenda e disponibilidade.
- Retomada fora do horário.
- SLA operacional.

# RS Connect — v36.20.3

Esta versão revisa a experiência visual da plataforma diretamente no código, com foco em formulários, gavetas laterais, checkboxes, barras de ação, legibilidade e uso em telas menores.

## Principais correções

- formulário de acompanhamento comercial reorganizado;
- botão de salvar mantido dentro do card e alinhado;
- tela de edição das chaves de IA reorganizada;
- correção global dos checkboxes gigantes;
- campos, rótulos, textos de ajuda e foco com padrão único;
- barras de ação das gavetas sem sobrepor o conteúdo;
- fontes pequenas dos painéis financeiros ampliadas;
- comportamento responsivo para desktop, tablet e celular;
- navegação por teclado e foco visível melhorados.

## Banco de dados

Não há migration nova. A última migration obrigatória continua sendo:

```text
database/migrations/085_ai_commercial_attention_queue.sql
```

Consulte `INSTRUCOES-v36.20.3.md` e `docs/guias/relatorio-ui-ux-v36.20.3.md`.

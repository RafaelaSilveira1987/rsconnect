# RS Connect — v36.20.5

Esta versão acrescenta ajuda contextual, onboarding com linguagem simples e recursos de acessibilidade para tornar a plataforma mais fácil de aprender.

## Principais melhorias

- botão de ajuda específico para cada página;
- atalho `?` para abrir a ajuda;
- passo a passo, dicas e explicação de termos;
- texto maior e redução de movimentos;
- navegação por teclado e foco visível;
- link para pular ao conteúdo principal;
- Primeiros passos com nomes mais claros;
- atalhos documentados na Central de ajuda.

## Banco de dados

Não há migration nova. A última migration obrigatória continua sendo:

```text
database/migrations/085_ai_commercial_attention_queue.sql
```

Consulte `INSTRUCOES-v36.20.5.md`, `docs/guias/guia-ajuda-contextual-acessibilidade.md` e `docs/guias/relatorio-usabilidade-v36.20.5.md`.

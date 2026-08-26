# RS Connect — v36.20.4

Esta versão corrige problemas estruturais de UI/UX encontrados no uso real da plataforma, principalmente barras de ação cobrindo campos, formulários desalinhados e painéis que desperdiçavam espaço.

## Principais correções

- rodapés de gavetas deixam de cobrir os campos;
- formulários administrativos usam rolagem e espaçamento previsíveis;
- acompanhamento de clientes reorganizado sem textos ou botões sobrepostos;
- agenda comercial movida para não reduzir a largura do funil;
- alteração de situação da cobrança com rótulo e botão claros;
- meios de pagamento com nomes mais simples;
- filtros e botões reorganizados para desktop, tablet e celular;
- foco, tamanhos de controles e textos de ajuda mantidos consistentes.

## Banco de dados

Não há migration nova. A última migration obrigatória continua sendo:

```text
database/migrations/085_ai_commercial_attention_queue.sql
```

Consulte `INSTRUCOES-v36.20.4.md` e `docs/guias/relatorio-ui-ux-v36.20.4.md`.

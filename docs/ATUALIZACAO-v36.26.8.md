# RS Connect 36.26.8

## Central de Monitoramento

- Nova navegação interna: **Visão geral**, **Rotinas** e **Histórico**.
- A tela inicial exibe somente situações que exigem ação.
- O total de verificações foi renomeado para **rotinas monitoradas**.
- Inclusão de gráfico de situações abertas e resolvidas nos últimos sete dias.
- Inclusão de indicador percentual da saúde das rotinas.
- Histórico com filtros por aberto/resolvido e carregamento progressivo de oito registros.
- Situações normalizadas passam a exibir o selo **Normalizado**, independentemente da severidade original.
- Rotinas normais ficam ocultas por padrão na aba Rotinas, mas continuam acessíveis pelo filtro **Todas**.

## Financeiro

O aviso financeiro foi reescrito para deixar claro que:

- houve uma falha registrada;
- ainda não apareceu uma operação bem-sucedida posterior;
- isso não comprova, isoladamente, que o Asaas continua indisponível.

## Compatibilidade

Nenhuma migration nova foi criada. A migration obrigatória permanece:

```text
098_operational_queue_release.sql
```

As ações **Resolver e liberar fila** e o silenciamento de alertas de conexões desconectadas continuam preservados.

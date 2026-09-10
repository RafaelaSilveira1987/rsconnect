# RS Connect v36.29.7 — Segunda rodada de consistência visual

## Objetivo
Aproximar visualmente os módulos que evoluíram em momentos diferentes, mantendo o padrão existente do RS Connect e reforçando a experiência em tablet e mobile.

## Telas revisadas
- **Agentes:** cabeçalho e ações, seletor de empresa, guia de roteamento, cards de assistente, áreas expansíveis, regras operacionais e barras de salvamento.
- **Instâncias:** hero e resumo, filtros, cards de conexão, métricas, ações, opções de comportamento/webhook, drawers e modal de QR Code.
- **Onboarding:** hero de progresso, KPIs, roteiro de etapas, destaque da etapa atual, formulários, escolha de agenda, disponibilidade interna e histórico.
- **Configurações:** hero, cards de perfil, accordions, switches, módulos visíveis e barra de salvamento do cliente, além do formulário de empresa usado pelo Super Admin.
- **RS Admin:** hero executivo, KPIs, ações rápidas, clientes que precisam de atenção, saúde da plataforma, atividade e empresas recentes.

## Responsividade
Foram reforçados pontos de quebra em 1024px, 820px, 680px e 440px. Em telas menores, ações passam a ocupar a largura disponível, cards deixam de forçar múltiplas colunas e blocos densos são reorganizados para leitura vertical.

## Segurança funcional
Esta rodada é CSS-first. Nenhum `name`, `id`, `data-*`, `action`, `method`, rota, campo de formulário ou seletor JavaScript foi renomeado/removido. O JavaScript funcional dos módulos não foi alterado.

## Migration
Não existe migration nova. A migration obrigatória atual continua sendo:

`106_agent_message_grouping_context_priority.sql`

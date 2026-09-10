# RS Connect 36.29.10 — Fechamento visual e QA responsivo

Esta versão fecha a sequência de padronização visual iniciada na 36.29.6. O objetivo é reduzir diferenças entre módulos antigos e novos sem reescrever regras de negócio.

## Laboratório de Assistentes

- CSS que estava embutido em `app/Views/agent_tests/index.php` foi movido para `public/assets/css/app.css`;
- seletor de empresa/assistente, modo de teste, conversa, diagnóstico, cenários e histórico passam a seguir os mesmos raios, espaçamentos e breakpoints do restante do sistema;
- no mobile, seletor, modos, composer e ações passam para uma coluna;
- nenhum `data-lab-*`, endpoint, formulário ou evento JavaScript foi alterado.

## Operação e monitoramento

- CSS local de `operations/alerts.php` foi consolidado no stylesheet global;
- resumo, canais, incidentes, diagnóstico amigável, ações e execuções automáticas ficam consistentes com a Central de Operação;
- formulários de resolução/assunção passam a ocupar a largura disponível no mobile sem alterar suas actions.

## Fila, equipe e distribuição

- criada camada visual dedicada para `/queue`;
- métricas e filtros adaptam-se a desktop, tablet e celular;
- a tabela operacional mantém formato tabular em desktop e vira cards legíveis abaixo de 720px;
- setores, prioridade, status, responsável, última interação e ação continuam vindo dos mesmos campos e rotas;
- nenhuma regra de atribuição, prioridade ou status foi modificada.

## Superfícies remanescentes

Foram feitos ajustes finais de consistência em Disponibilidade da Agenda, Permissões, Usuários, Privacidade/LGPD, Signup administrativo, Segurança e templates n8n. O foco foi quebra de texto, área de toque, alinhamento e foco por teclado.

## Compatibilidade

- nenhuma migration nova;
- migration obrigatória permanece `106_agent_message_grouping_context_priority.sql`;
- nenhuma rota, `name`, `id`, `data-*`, método/action de formulário ou seletor JavaScript funcional foi removido;
- cache principal atualizado para `36.29.10`.

Após o deploy, reinicie PHP-FPM/container para limpar OPcache e faça recarregamento completo do navegador.

# RS Connect 36.29.9 — Agenda, Campanhas, Relatórios e Cobranças

Esta versão conclui a próxima rodada de padronização visual das telas operacionais, mantendo intactas as regras de negócio, rotas, nomes de campos, IDs funcionais e eventos JavaScript existentes.

## Correção solicitada — permissões da automação

O bloco **O que a automação pode fazer** da tela de Assistentes foi corrigido para que todos os cards usem a mesma estrutura visual: checkbox na primeira coluna e título/descrição alinhados à esquerda logo ao lado. Cards ativos, inativos e protegidos pela plataforma continuam usando os mesmos inputs e regras; a mudança é apenas de apresentação e responsividade.

## Agenda

- cabeçalho, métricas, alternância Lista/Dia/Semana/Mês e filtros receberam a mesma hierarquia visual;
- métricas passam de cinco colunas para três, duas e uma conforme a largura disponível;
- formulário de novo agendamento respeita a viewport móvel;
- linhas da lista ganharam contorno/card consistente sem alterar ações de aprovação, conclusão, remarcação, cancelamento, exclusão ou integração Google;
- visualizações semana/mês mantêm rolagem horizontal controlada quando o conteúdo precisa de largura mínima.

## Campanhas

- criado um layout visual próprio para histórico/fila e criação de campanha;
- cards de campanha mostram status, progresso e contexto com alinhamento previsível;
- formulário lateral permanece sticky em desktop e volta ao fluxo normal em tablet/mobile;
- detalhes, mensagem, resumo, lote e ações foram reorganizados para quebrar sem sobreposição;
- tabela de destinatários mantém rolagem horizontal segura em telas pequenas;
- nenhuma action (`audience`, `approve`, `dispatch`, `status`) foi alterada.

## Relatórios

- `reports.css` recebeu uma camada final de harmonização para painel executivo, relatório do cliente, equipe e relatórios automáticos;
- filtros e barras de ação passam a ter o mesmo acabamento dos demais módulos;
- checkboxes de relatórios automáticos têm área de toque, borda e estado selecionado consistentes;
- KPIs e ações passam a duas/uma coluna em telas estreitas;
- tabelas permanecem funcionais com rolagem horizontal em vez de comprimir conteúdo crítico;
- cache de `reports.css` atualizado para `36.29.9` nas quatro views de relatório.

## Cobranças e assinatura

- cards de assinatura, faturas, uso e detalhes financeiros receberam o mesmo padrão de raio, sombra e espaçamento;
- ações de fatura e formulários de status reorganizam-se para uma coluna no mobile;
- conteúdos longos passam a quebrar corretamente sem estourar o card;
- telas do cliente e RS Admin permanecem com os mesmos formulários e regras de pagamento.

## Compatibilidade

- nenhuma migration nova;
- migration obrigatória permanece `106_agent_message_grouping_context_priority.sql`;
- nenhuma rota ou regra de negócio foi alterada;
- nenhum `name`, `id`, `data-*`, método ou action de formulário funcional foi removido.

Após o deploy, reinicie PHP-FPM/container para limpar OPcache e force atualização do cache do navegador caso alguma estação ainda apresente o CSS antigo.

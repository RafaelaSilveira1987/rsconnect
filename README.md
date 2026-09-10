# RS Connect 36.29.10

Organização inteligente de contatos, continuidade de cliente/paciente e terceira rodada visual em telas operacionais, preservando o fluxo editável dos Assistentes.

Principais alterações desta versão:

- a ordem do atendimento agora pode ser reorganizada por empresa;
- a sequência configurada passa a influenciar qual informação pendente será solicitada primeiro;
- o cliente pode ajustar regras do dia a dia diretamente em **Assistentes**;
- perguntas obrigatórias, mensagens, políticas de negócio e permissões operacionais ficam disponíveis ao administrador do cliente;
- segmento, modelo-base, versão, provedor/modelo de IA, credenciais, integrações externas e proteções estruturais ficam no RS Admin quando puderem interromper a operação;
- `policy.fail_closed` não pode ser desligado pelo cliente;
- o editor da sequência foi redesenhado em cards responsivos, sem rolagem horizontal;
- regras de segurança e Policy Engine continuam valendo independentemente da ordem visual;
- agrupamento de mensagens e prioridade do turno atual da versão 36.29.4 foram preservados.

## Ajustes visuais da base 36.29.6

- campos de texto, select, textarea, data/hora e uploads com padrão visual comum;
- checkboxes e radios alinhados e com alvo de toque consistente;
- formulários de duas/três colunas adaptados para uma coluna em mobile;
- filtros, ações, tabelas e popovers preparados para viewport pequena;
- nenhuma alteração em `name`, `id`, `data-*`, rotas, actions de formulário ou lógica JavaScript;
- nenhuma migration nova.

## Segunda rodada visual — 36.29.7

- Agentes com cabeçalho, seletor de empresa, cards, ações e áreas expansíveis mais uniformes;
- Instâncias com resumo, filtros, cards, opções e drawers harmonizados;
- Onboarding com roteiro lateral, etapa atual, cards de agenda e formulários mais legíveis;
- Configurações da empresa com cards, accordions, switches e barra de salvamento no mesmo padrão;
- RS Admin com KPIs, ações rápidas, prioridades e saúde da plataforma ajustados para tablet/mobile;
- pontos de quebra revisados para 1024px, 820px, 680px e 440px;
- nenhuma alteração funcional em rotas, campos, IDs, `data-*` ou JavaScript;
- nenhuma migration nova.

## Terceira rodada operacional — 36.29.8

- correções pontuais em prioridade do roteamento, alinhamento de Menu/Acesso, cabeçalho de Conversa natural e ordem vertical do atendimento;
- Base de Contatos com tabela mais legível, drawer reorganizado e cards responsivos no mobile;
- Organização do contato passa a gerar perfil operacional usado pela IA em Contatos e Conversas;
- paciente e cliente atual recebem continuidade de atendimento e não voltam à qualificação de novo lead;
- CRM deixa de criar oportunidade automática para conversas rotineiras de relacionamento atual, preservando oportunidades existentes e novas intenções comerciais explícitas;
- nenhuma migration nova.

## Quarta rodada visual — 36.29.9

- Agenda com métricas, filtros, alternância de visualização, cartões de compromisso e ações mais consistentes em desktop, tablet e celular;
- Campanhas com layout próprio para histórico, criação, progresso, detalhes, ações e destinatários, incluindo adaptação completa para mobile;
- Relatórios executivo, equipe e automáticos com cards, filtros, tabelas e checkboxes harmonizados, mantendo a folha `reports.css` separada;
- Cobranças e assinatura com hierarquia visual e comportamento responsivo reforçados para faturas, uso, status e ações;
- bloco **O que a automação pode fazer** corrigido para alinhar checkbox, título e descrição à esquerda em todos os cards;
- nenhuma rota, `name`, `id`, `data-*`, action de formulário, evento JavaScript ou regra de negócio foi removida;
- nenhuma migration nova.

Migration obrigatória atual:

`106_agent_message_grouping_context_priority.sql`

Não existe migration nova nesta versão.

Depois do deploy, execute `php bin/migrate.php status` e reinicie o PHP-FPM/container para limpar OPcache.

## Quinta rodada de fechamento — 36.29.10

- Laboratório de Assistentes deixou de manter CSS inline e passou a usar a camada visual compartilhada, com seletor, simulador, diagnósticos, cenários e histórico responsivos;
- Avisos Operacionais também tiveram o CSS local consolidado no `app.css`, reduzindo divergência entre telas administrativas;
- Fila/Equipe recebeu hierarquia visual completa, métricas, filtros, setores e tabela que vira cards no celular;
- Disponibilidade da Agenda, Permissões, Usuários, Privacidade/LGPD, Signup administrativo, Segurança e templates n8n receberam ajustes finais de consistência;
- foco por teclado reforçado nos módulos revisados, sem alterar submits, rotas ou eventos JavaScript;
- nenhuma migration nova; a obrigatória permanece `106_agent_message_grouping_context_priority.sql`.


# RS Connect 36.29.7

Fluxo de atendimento editável e regras operacionais disponíveis no módulo de Assistentes.

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

Migration obrigatória atual:

`106_agent_message_grouping_context_priority.sql`

Não existe migration nova nesta versão.

Depois do deploy, execute `php bin/migrate.php status` e reinicie o PHP-FPM/container para limpar OPcache.

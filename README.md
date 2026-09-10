# RS Connect 36.30.4


## Instâncias resilientes — 36.30.4

- cada conexão pode ter um **número autorizado**; quando outro número é detectado, entradas e saídas ficam bloqueadas por segurança;
- se nenhuma autorização tiver sido informada, o primeiro número confirmado pela Evolution é adotado automaticamente;
- tela de Instâncias exibe número autorizado, número conectado e estado da recuperação automática;
- ações **Diagnosticar** e **Recuperar conexão** foram adicionadas ao fluxo administrativo;
- o diagnóstico consulta estado, identidade, webhook e configurações remotas sem expor a API Key;
- o monitor operacional pode reiniciar quedas técnicas de instâncias gerenciadas, respeitando logout, QR Code e divergência de identidade;
- a proteção de saída foi centralizada no `EvolutionService`, cobrindo texto e mídia enviados por Conversas, IA, Agenda, notificações, relatórios e demais serviços que usam a Evolution;
- migration obrigatória: `108_evolution_instance_resilience.sql`.

## Indicadores de atendimento — 36.30.3

- meta configurável de SLA da primeira resposta humana (5 a 1440 minutos);
- SLA calculado sobre ciclos operacionais persistidos, com quantidade dentro e fora da meta;
- tempo médio do ciclo de atendimento entre abertura e encerramento;
- fila atual de conversas aguardando primeira resposta, com espera média, maior espera e violações de SLA;
- relatório por profissional com SLA, duração média, espera atual e setores vinculados;
- quando Fila/Setores estiver habilitada, relatório exibe carga operacional atual por setor sem inventar atribuição histórica;
- exportação CSV da equipe e auditoria de primeira resposta incluem os novos campos;
- nenhuma migration nova; permanece obrigatória `107_service_department_memberships.sql`.


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

`107_service_department_memberships.sql`

A versão 36.30.0 adiciona `107_service_department_memberships.sql` para o vínculo usuário ↔ setor.

Depois do deploy, execute `php bin/migrate.php status` e reinicie o PHP-FPM/container para limpar OPcache.

## Quinta rodada de fechamento — 36.29.10

- Laboratório de Assistentes deixou de manter CSS inline e passou a usar a camada visual compartilhada, com seletor, simulador, diagnósticos, cenários e histórico responsivos;
- Avisos Operacionais também tiveram o CSS local consolidado no `app.css`, reduzindo divergência entre telas administrativas;
- Fila/Equipe recebeu hierarquia visual completa, métricas, filtros, setores e tabela que vira cards no celular;
- Disponibilidade da Agenda, Permissões, Usuários, Privacidade/LGPD, Signup administrativo, Segurança e templates n8n receberam ajustes finais de consistência;
- foco por teclado reforçado nos módulos revisados, sem alterar submits, rotas ou eventos JavaScript;
- nenhuma migration nova; a obrigatória permanece `106_agent_message_grouping_context_priority.sql`.


## Distribuição operacional — 36.30.0

- módulo **Fila e setores** ativado nas rotas e no menu conforme permissões `queue.view`/`queue.manage`;
- vínculo persistente entre usuários e setores por `service_department_members`;
- administração da equipe de cada setor dentro da própria Fila;
- Conversas passam a exibir o setor atual e permitem transferência para setor;
- ao transferir para um setor, o responsável anterior é liberado, a IA é pausada e a conversa entra em espera para a equipe;
- profissionais só podem assumir/receber uma conversa de setor quando pertencem ao setor, salvo administradores;
- distribuição direta pela Fila valida tenant, setor, profissional, prioridade e status operacional;
- o setor operacional passa a integrar o contexto estruturado fornecido à IA;
- joins sensíveis da Fila e do contexto da IA foram reforçados com `tenant_id`;
- migration obrigatória: `107_service_department_memberships.sql`.


## Fila opcional e frontend operacional — 36.30.1

- `Fila e setores` passa a ser um recurso operacional opcional por empresa.
- Quando desligado, o menu e as rotas da fila ficam indisponíveis para o cliente e o atendimento continua direto por IA/usuário.
- Quando ligado em **Minha empresa**, o menu é liberado automaticamente e as regras de setor/equipe entram em ação.
- Regras antigas de setor deixam de restringir atendimento quando o recurso está desligado.
- A tela da fila foi redesenhada: tabela sem deslocamento horizontal desnecessário, ações consistentes e distribuição em drawer lateral.
- O painel de setores ganhou cadastro recolhível e edição de equipe por setor sem checkboxes comprimidos.
- Não há migration nova nesta versão; a migration necessária para vínculo usuário ↔ setor continua sendo `107_service_department_memberships.sql`.


## Notas internas da conversa — 36.30.2

- cria a experiência operacional de notas privadas por conversa usando a tabela já existente `conversation_internal_notes`;
- cada nota registra autor e horário e fica disponível no drawer de dados da conversa;
- o conteúdo da nota não é enviado ao WhatsApp, não entra em `contacts.notes` e não é incluído no contexto da IA;
- `contacts.notes` passa a ser apresentado como **Contexto do contato**, deixando explícito que é informação persistente que pode ser usada pela IA;
- gravação respeita tenant, permissão `conversations.manage` e trava de responsabilidade do atendimento;
- nenhuma migration nova; continua obrigatória `107_service_department_memberships.sql`.

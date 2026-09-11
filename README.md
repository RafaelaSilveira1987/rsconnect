# RS Connect 36.30.9

## Dados da conversa aprimorados — 36.30.9

- confirma o QA de reabertura da v36.30.8: os prints de atendimento humano e de novo ciclo representam momentos diferentes e os estados estão coerentes;
- renomeia a ação **Dados do lead** para **Dados da conversa**, adequada também a clientes e pacientes;
- adiciona cabeçalho com avatar, contato, telefone e conexão do WhatsApp;
- cria uma **Visão rápida** com situação da conversa, modo de atendimento, responsável, assistente e relacionamento;
- adiciona navegação curta entre Resumo, Fila/Responsável quando aplicável, IA, Notas, Contato, Fluxo e CRM;
- reorganiza **Regras aplicadas agora** em cartões legíveis, com intenção traduzida, etapa do fluxo, faixa de horário, contexto de agenda e tags;
- amplia o drawer no desktop e preserva layout de duas colunas compacto no mobile;
- não altera rotas, nomes de campos, submits, regras de negócio nem banco de dados;
- nenhuma migration nova; permanece obrigatória `110_conversation_lifecycle_e2e_consistency.sql`.

## QA do fluxo central — 36.30.8

- valida o encadeamento **Contato → IA → Conversa → Fila opcional → Atendente → Agenda/CRM → Encerramento → Relatórios**;
- uma mensagem recebida após o encerramento inicia um ciclo limpo em IA; uma saída direta pelo WhatsApp reabre pausada, sem risco de a IA assumir por engano;
- estado transitório de triagem e fluxo é reiniciado somente no novo ciclo, preservando cadastro, relacionamento, CRM, agenda e histórico de mensagens;
- pendências pós-horário do ciclo anterior são canceladas para evitar resposta tardia a uma mensagem antiga;
- encerramento sempre limpa responsável, não lidas e fila operacional, mesmo quando a atribuição profissional opcional está desligada;
- reabertura manual também deixa de depender da configuração de responsável exclusivo;
- pedidos explícitos de atendimento humano passam a refletir o estágio `human_handoff`;
- decisões do Policy Engine com ação `handoff` pausam a IA e colocam efetivamente a conversa em espera da equipe;
- deduplicação de avisos de política passa a respeitar o ciclo ativo, mantendo o histórico de decisões anteriores;
- migration obrigatória: `110_conversation_lifecycle_e2e_consistency.sql`.

## Infraestrutura de instalação reproduzível — 36.30.7

- restaura `composer.json` como JSON válido e Composer-compatible;
- restaura `.env.example`, `.env.local.example` e `.env.vps.example` com as variáveis realmente usadas pela aplicação;
- restaura `.dockerignore` e `.gitignore` para impedir publicação de segredos e arquivos de execução;
- restaura `docker-compose.yml` com MySQL 8.4, serviço de migrations e health check;
- normaliza o `Dockerfile` PHP 8.3/Apache, extensões necessárias, OPcache e validação offline das migrations;
- restaura `build-full-release.sh` como script Bash de validação, checksums e empacotamento;
- restaura `manifest.json` como JSON de release;
- atualiza o guia de instalação para o banco realmente usado: **MySQL/MariaDB**;
- nenhuma migration nova; permanece obrigatória `109_evolution_instance_identity_cleanup.sql`.

## Hotfix do alerta de identidade — 36.30.6

- corrige a mensagem vermelha que podia permanecer visível mesmo com Número autorizado e Número conectado iguais;
- força elementos de erro com `hidden` a permanecerem realmente ocultos, evitando conflito com `.message-error { display: block; }`;
- o frontend deixa de exibir códigos HTTP/resíduos como `· 200` em instâncias saudáveis;
- o estado visual de identidade passa a ser derivado dos números atuais, evitando badge verificado junto de aviso de divergência;
- a proteção de entrada/saída considera a verificação atual como fonte de verdade quando um `identity_status=mismatch` antigo ficou persistido;
- nenhuma migration nova; permanece obrigatória `109_evolution_instance_identity_cleanup.sql`.

## Correção de identidade e layouts — 36.30.5

- remove marcadores CSS que estavam sendo renderizados como texto antes do login e dentro da aplicação;
- ignora códigos HTTP e valores curtos como `200` ao identificar o telefone conectado;
- consulta `instance/fetchInstances` quando `connectionState` não informa `ownerJid/number`;
- atualiza em tempo real os campos Número autorizado/Número conectado no card da instância;
- adiciona a migration `109_evolution_instance_identity_cleanup.sql` para limpar identidades inválidas já persistidas.



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

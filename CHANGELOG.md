## 36.4.7 - Relatórios com cor e preenchimento controlado

- Restaura barras preenchidas nos funis de CRM, disponibilidade, agenda e comercial.
- Usa cores semânticas e gradientes suaves para facilitar leitura de volume.
- Evolução diária passa a usar linhas coloridas com áreas translúcidas independentes.
- O preenchimento do gráfico não é aplicado ao path da linha, evitando a antiga mancha preta.
- Mantém responsividade e o layout clean introduzido na 36.4.6.
- Atualiza cache-busting de `reports.css` e `reports.js` para 36.4.7.

## v36.4.6 — Refinamento visual dos relatórios

- Redesenha os relatórios do cliente e do Super Admin com a identidade clara do RS Connect.
- Remove o preenchimento indevido dos gráficos de linha e reduz espessura, pontos e peso visual.
- Adiciona tooltip discreto no gráfico diário e exibe pontos apenas onde existe movimento.
- Deixa KPIs, filtros, seções, rankings, insights, funis, donut e heatmap mais leves e consistentes.
- Reduz gradientes e sombras, priorizando fundo branco, bordas suaves e acentos em teal/azul/roxo.
- Atualiza cache-busting de CSS/JS para `36.4.6` nas visões cliente e administrador.
- Não exige migration nem novo backfill.

## v36.4.5 — Correção da série diária do relatório

- Corrige erro SQL no carregamento da evolução diária: o alias `system` foi substituído por `system_messages`, pois `SYSTEM` é palavra reservada no MySQL.
- Corrige o mesmo alias na consulta de fallback sobre `conversation_messages`.
- Mantém o gráfico com Total, Recebidas e IA usando os dados já agregados em `report_daily_metrics`.
- Registra no log do PHP a exceção real do motor agregado para facilitar diagnósticos futuros.
- Não exige nova migration nem novo backfill.

## v36.4.4 — Correção da evolução diária nos relatórios

- Corrige o transporte da série diária dos gráficos do relatório do cliente.
- As séries JSON passam a ser entregues ao JavaScript em Base64, evitando corrupção por escaping HTML.
- Mantém fallback para o formato `data-series` anterior.
- Atualiza o cache-busting de `reports.css` e `reports.js` para `36.4.4`.
- Em caso de erro de parse, o navegador registra a causa no console em vez de falhar silenciosamente.

## 36.4.3 - 2026-07-23

- Corrige o cálculo do tempo médio da primeira resposta para ignorar mensagens `system` e considerar a primeira resposta de IA/equipe posterior à entrada do contato.
- Renomeia “Atendimento pela IA” para “Respostas feitas pela IA”.
- Renomeia o insight de agenda para “Pré-agendamentos rejeitados”.
- Torna os avisos de indisponibilidade do relatório menos técnicos e registra o detalhe no log do servidor.

# RS Connect 36.4.2 — Correção semântica dos relatórios

- separa respostas de IA, equipe e automação/sistema;
- corrige a interpretação da Agenda, separando disponibilidade de resultado dos compromissos;
- inclui status rejeitado no resumo da Agenda;
- padroniza o CRM agregado pela coorte de leads criados no período;
- sobe `metrics_version` para 2 e força reconstrução segura do cache legado;
- mantém a migration 048 sem alteração de esquema.

# RS Connect 36.4.1 — Relatórios executivos visuais e insights

- Evolui os relatórios do cliente e do Super Admin para dashboards executivos com gráficos reais em SVG, sem dependência externa.
- Adiciona comparação automática com o período anterior nos KPIs principais.
- Cliente: gráfico diário de atendimento, mapa de calor por dia/horário, IA x equipe, funil de CRM e funil de agenda.
- Cliente: insights por regras sobre crescimento, participação da IA, horário de pico, falhas e oportunidades de confirmação.
- Admin RS: saúde da base em gráfico de distribuição, ranking de uso, tendência diária de mensagens, tendência de falhas e insights executivos.
- Mantém isolamento por tenant_id e não expõe conteúdo das conversas nos relatórios administrativos.
- Reutiliza a fundação `report_daily_metrics` da migration 048; não exige nova migration.
- Mantém fallback para tabelas operacionais quando a camada agregada não estiver disponível.

# RS Connect 36.3.0 — Backup operacional confiável

- Separa solicitação aceita de backup realmente concluído.
- Mantém jobs em execução até o callback final do n8n.
- Exige arquivo real, tamanho mínimo, SHA-256 e `verified=true` para concluir.
- Vincula `system_backups` ao job e à rotina.
- Impede callbacks duplicados por job.
- Marca jobs sem callback como `timeout`.
- Arquiva rotinas inativas duplicadas na tela principal.
- Adiciona teste de conexão com n8n sem criar backup.
- Atualiza a tela de Backups com KPIs reais, histórico detalhado e atualização automática.
- Adiciona script seguro para VPS/EasyPanel sem senha de banco gravada no workflow.
- Atualiza o workflow n8n para resposta imediata, execução SSH, callback de sucesso/erro e despacho das rotinas vencidas.
- Adiciona migration `047_backup_automation_reliability.sql`.

# HOTFIX 36.2.5 — Validação da demanda isolada da IA e das opções antigas

- Mantém a regra que exige entender a demanda antes do pré-agendamento.
- Quando a agenda é bloqueada por essa validação, o RS Connect responde com uma pergunta objetiva sobre a demanda.
- Impede que o assistente geral reutilize horários antigos do histórico enquanto a demanda está pendente.
- Marca a mensagem como `ai.skipped` vinculada ao `incoming_message_id`, evitando reprocessamento indevido.
- Registra `calendar.pre_schedule_blocked` na conversa para diagnóstico.
- Depois que a demanda é informada, uma nova mensagem com dia e horário cria o pré-agendamento e consulta o n8n normalmente.
- Não altera regras de tags, grupos, aprovação humana, disponibilidade, cooldown ou workflow n8n.
- Não exige migration.

# HOTFIX 36.2.4 — Nova preferência sem reutilizar opções antigas

- Reinicia a consulta de disponibilidade quando o contato informa outro dia/horário.
- Invalida opções e callbacks antigos antes de criar a nova solicitação.
- Preserva uma pré-reserva ativa até que o novo horário seja realmente escolhido.
- Impede a IA de responder com horários antigos quando a consulta automática falha ou ainda não foi executada.
- Só aceita respostas numéricas vinculadas à consulta atual do pré-agendamento.
- Registra falhas de disparo ao n8n no log e nas notificações da Agenda.
- Não altera o workflow n8n nem exige migration.

# HOTFIX 36.2.3 — Callback da agenda sem falso timeout

- Resposta HTTP do callback enviada antes das tarefas lentas de conversa.
- Proteção contra rebaixamento de `received/empty` para `sent/failed`.
- Timeout específico e configurável para eventos de calendário.
- Timeout de transporte passa a aguardar callback em vez de marcar falha definitiva.
- Manutenção ignora solicitações que já possuem `responded_at`.

# HOTFIX 36.2.2 — Excluir remove evento do Google Agenda

- A ação **Excluir** não restaura mais eventos VAGO como disponíveis.
- Eventos vinculados a `google_marked_slots` recebem a ação `delete` antes da exclusão local.
- O registro do RS Connect só é apagado depois que o callback do n8n confirma `state: deleted`.
- Falhas no Google/n8n preservam o agendamento local e exibem o motivo no painel.
- Workflow Eventos VAGO atualizado para aceitar `delete` e remover o evento do Google Calendar.
- Nenhuma mensagem é enviada ao contato e não requer migration nova.

# HOTFIX 36.2.1 — Seleção de horário não aciona IA

- Eventos `SEND_MESSAGE` da Evolution agora são tratados como eco de saída e não entram no fluxo de mensagens recebidas.
- Respostas consumidas pela agenda (`1`, `opção 2`, `14h`, etc.) encerram o processamento antes da IA e dos fluxos genéricos de `message.received`.
- Cada seleção tratada ganha um marcador `ai.skipped` vinculado à mensagem recebida, impedindo reprocessamento posterior pela fila.
- Mantidas as validações da agenda, pré-reserva, aprovação profissional, tags, grupos, cooldown e proteção contra duplicidade.
- Não requer migration nova.

# ZIP 36.2 — Agenda conversacional e pré-reserva com aprovação

- Horário ocupado passa a gerar alternativas reais do Google Agenda pelo WhatsApp.
- Contato pode escolher por número, ordinal, horário ou dia/horário.
- Escolha é validada contra a busca atual antes de qualquer pré-reserva.
- Horário livre solicitado pode ser pré-reservado automaticamente.
- Evento VAGO é pré-reservado pelo n8n e permanece aguardando aprovação profissional.
- Callbacks antigos e comunicações duplicadas são bloqueados.
- Falha de concorrência remove o horário ocupado e reapresenta apenas opções restantes.
- Mensagens da agenda podem ser personalizadas por empresa.
- Tela de disponibilidade mostra opções enviadas, validade, posição e origem da escolha.
- Migration `046_calendar_conversational_slot_selection.sql`.

# HOTFIX 36.1.3 — Resposta imediata sem depender de reprocessamento

- Nova mensagem recebida e persistida não é mais descartada pelo cooldown destinado a execuções repetidas.
- Proteção contra duplicidade passa a validar todas as mensagens pelo `incoming_message_id`.
- IA/confirmação de preferência responde antes de chamadas lentas do n8n e Google Agenda.
- Lock da conversa é liberado antes do evento `ai.replied` ser enviado ao n8n.
- Falha do n8n após uma resposta enviada não cria mais um falso `ai.failed`.
- Busca automática de disponibilidade e evento de pré-agendamento são disparados somente após a resposta crítica.
- Não requer nova migration; mantém a migration 045 como estrutura mínima recomendada.

# HOTFIX 36.1.2 — Persistência antes do processamento e fim do 422

- Salva a mensagem antes de CRM, agenda, n8n e IA.
- Impede que falhas auxiliares removam a entrada recebida.
- Registra diagnóstico em `storage/logs/evolution-webhook.log`.
- Ignora broadcasts/status/newsletters sem HTTP 422.
- Fila compatível com bancos sem `incoming_message_id`.
- Considera apenas `sent`, `delivered` e `read` como resposta válida.
- Migration `045_ai_webhook_ingestion_resilience.sql`.

# HOTFIX 36.1.1 — Pendências reais da IA

- Vincula cada tentativa da IA à mensagem recebida correspondente.
- Mensagens com `ai.failed` passam a permanecer visíveis na **Fila da IA**.
- Respostas que a Evolution marcar depois como `failed` retornam à fila com segurança.
- Detecta mensagens gravadas cujo processamento foi interrompido antes do registro do log.
- Mantém a proteção contra reenvio quando já existe qualquer saída posterior.
- Corrige o reprocessamento para assistentes padrão ou sem conexão fixa.
- Evita repetir várias vezes a mesma falha durante uma única execução geral.
- Migration `044_ai_pending_failures_message_link.sql`.

# ZIP 36.1 — Fila da IA e reprocessamento agendado

- Novo acesso **Central de operação > Fila da IA** para o Super Admin RS.
- Configuração de horário diário, fuso e limite de mensagens por execução.
- Verificação de todas as empresas sem reenviar conversas já respondidas.
- Reprocessamento restrito a mensagens realmente presas após `ai.cooldown`.
- Locks no MySQL para impedir execuções simultâneas e respostas duplicadas.
- Histórico de execuções, total pendente e empresas que precisam de atenção.
- Acesso **Saúde e IA** adicionado diretamente nos cards de empresas.
- Endpoint, comando CLI e template n8n para acionamento periódico.
- Migration `043_ai_reprocess_schedule.sql`.

# HOTFIX 36.0.2 — Contatos com rolagem

- Corrige o painel lateral de edição para rolar dentro da altura visível.
- Mantém a ação de salvar acessível.
- Adiciona rolagem ao formulário de novo contato.
- Preserva o comportamento responsivo em tablet e celular.

## ZIP 36.0 — Pagamentos reais e conciliação
- PagBank com consulta manual de checkout e webhooks.
- Importação de cobranças existentes da InfinitePay/outros provedores.
- Deduplicação de links e identificadores externos.
- Renovação de vigência e desbloqueio após pagamento.

## ZIP 35.0.1 — 2026-07-18

- Corrige filtro de Eventos VAGO para aceitar aliases.
- Torna a transparência do Google uma regra opcional e mais clara.
- Melhora diagnóstico de eventos rejeitados.


## 34.5.4 — Monitoramento sincronizado

- O botão **Verificar agora** recarrega automaticamente a Central de operação com os cards atualizados.
- O processamento manual da régua de cobrança atualiza o card **Cron de cobrança** antes do redirecionamento.
- O heartbeat interno da régua não aparece mais como serviço duplicado.
- Corrigido parâmetro duplicado no registro das verificações.

## ZIP 33.1 — Vigência e menus do cliente

- Edição direta da vigência de assinaturas, inclusive para empresas bloqueadas.
- Recalculo imediato do acesso após atualização comercial.
- Motivo restante exibido quando uma fatura ainda mantém o bloqueio.
- Atalhos e área exclusiva do Admin RS para mostrar/ocultar módulos do cliente.
- Configurações de módulos preservadas ao cliente salvar o perfil empresarial.

## HOTFIX 32.1.3 — Dashboard: vínculo de dados corrigido

- Corrige colisão da chave `data` com o parâmetro interno de `View::render()`.
- Dashboard administrativo passa a receber o retorno real do serviço.
- Atualiza identificação para `32.1.3-view-binding`.

# HOTFIX 32.1.1 — Dashboard Admin com dados reais

- Removida dependência de `information_schema` nos indicadores do Admin.
- Consultas dos cards executadas diretamente nas tabelas de origem.
- Adicionados horário da atualização e botão para atualizar dados.
- Reforçado o layout da gaveta Cadastrar empresa em zoom 100%.

# ZIP 32.1 — Dashboard Admin confiável + acompanhamento de empresas

- indicadores administrativos consultados diretamente nas tabelas de origem;
- aviso visível quando uma consulta não puder ser atualizada, evitando zeros presumidos;
- acompanhamento manual: atenção, visualizada, corrigida e análise automática;
- falhas antigas de IA e integrações podem ser reconhecidas após correção;
- botões diretos para inativar e reativar empresas;
- histórico focado em correções e atualizações administrativas;
- últimas empresas cadastradas com data de cadastro;
- gaveta de cadastro corrigida para zoom 100% e mobile;
- migration 035 para acompanhamento administrativo.

# ZIP 32.0 — Admin RS: Dashboard executivo + Empresas

- novo dashboard executivo exclusivo do Super Admin RS;
- indicadores de empresas, implantação, assinaturas, receita, mensagens e incidentes;
- saúde dos serviços e clientes priorizados por necessidade de atenção;
- atalhos operacionais e histórico administrativo recente;
- listagem de empresas com filtros e classificação de saúde;
- cadastro de empresa em gaveta lateral responsiva;
- nova visão geral por empresa com plano, uso, implantação, equipe, falhas e acessos rápidos;
- telas do cliente preservadas;
- sem migration nova.

# ZIP 31.3 — UX do cliente: Privacidade, Assinatura e Acessos

- reformulação da tela Privacidade e dados com linguagem simples e configuração em etapas;
- nova experiência para solicitações LGPD, aceites e exportação de dados;
- reformulação de Minha assinatura com uso do plano e cobranças responsivas;
- botão de WhatsApp para solicitar melhoria do plano à equipe RS Connect;
- reformulação de Permissões para Acessos da equipe no lado do cliente;
- perfis e acessos apresentados sem chaves técnicas;
- telas do Super Admin RS preservadas;
- sem migration nova.

# ZIP 31.2 — Central de ajuda como manual dos módulos

- Central do cliente reduzida aos manuais dos módulos existentes.
- Cada módulo abre resumo, funções, passo a passo e orientações em uma gaveta lateral.
- Botão interno para acessar diretamente o módulo.
- Ordem recomendada e diagnóstico rápido mantidos somente para Super Admin RS.
- Layout responsivo para desktop, tablet e celular.
- Sem migration nova.

# ZIP 31.1 — Notificações configuráveis e sininho em tempo real

- Preferências por empresa para mensagens, IA, automações, agenda, financeiro e avisos importantes.
- Notificação de nova mensagem recebida.
- Alertas amigáveis para falhas da IA e ausência de assistente ativo.
- Alertas para falhas de n8n, webhooks e integrações externas.
- Notificações de pré-agendamentos e mudanças da agenda.
- Contador do sininho atualizado automaticamente sem recarregar a página.
- Toast discreto ao chegar novo aviso.
- Migration 034 para persistir preferências da empresa.

# ZIP 31.0 — UX cliente: Assistentes + Perfil da empresa

- reformulação da gaveta de criação de assistentes;
- cadastro guiado e linguagem voltada para usuários leigos;
- prompt inicial montado a partir de objetivo, tom, mensagem e regras;
- informações empresariais reaproveitadas na criação do assistente;
- reformulação da tela de dados da empresa no lado do cliente;
- novos campos comerciais, endereço e contexto para atendimento;
- barra fixa para salvar e indicador de preenchimento;
- responsividade para desktop, tablet e celular;
- preservação do layout atual do Super Admin RS;
- migration 033 para os novos campos do perfil empresarial.

## HOTFIX 31.0.1
- Corrigido o painel de criação de assistente em zoom 100%.
- Impedido que regras antigas de `.conversation-details` transformem a gaveta em grid de três colunas.
- Atualizado cache-busting dos assets para `31.0.1`.

## ZIP 32.2 — Credenciais de IA

- Nova tela administrativa de credenciais de IA.
- Cadastro e edição por gaveta lateral responsiva.
- Remoção do formulário fixo lateral e do formulário embutido na tabela.
- Cards, indicadores e filtros para facilitar a gestão.
- Linguagem simplificada e melhor orientação sobre chave, modelo e escopo.


## ZIP 32.3 — Centros administrativos
- WhatsApp, n8n, cobrança, gateways, régua, usuários e permissões redesenhados com cards, filtros e gavetas responsivas.
- Formulários laterais permanentes removidos do Admin RS.
- Experiência do cliente preservada.

## ZIP 33.0 — Segurança comercial e controle de acesso

- bloqueio por fim de vigência da assinatura;
- bloqueio por fatura vencida há mais de cinco dias;
- bloqueio temporário por tentativas incorretas de login;
- desbloqueio automático após regularização;
- painel Segurança validado com dados reais;
- sessões com expiração e encerramento registrados;
- mensagens preservadas durante bloqueio, sem IA e automações;
- tela específica de acesso temporariamente limitado.

## ZIP 34.0 — 2026-07-17

- CRM comercial exclusivo do Admin RS.
- Funil de prospecção, demonstração, proposta, negociação, implantação, cliente ativo, risco e cancelamento.
- Atividades, notas, responsáveis, valor e conversão em empresa.
- Relatórios executivos de crescimento, receita, uso, IA, n8n, agenda e comercial.
- Exportações administrativas em CSV.
- Migration `037_admin_commercial_crm_reports.sql`.

## ZIP 34.1

- Corrige a apresentação dos relatórios executivos com CSS e JavaScript isolados.
- Adiciona fallback visual para impedir relatório sem formatação.
- Implementa arrastar e soltar no CRM administrativo e no CRM do cliente.
- Salva a mudança de etapa por AJAX, sem refresh.
- Mantém seletor de etapa como alternativa acessível/mobile, também sem refresh.
- Cria relatório gerencial do cliente com métricas de atendimento, IA, equipe, CRM e agenda.
- Atualiza a versão dos assets para 34.1.

## ZIP 34.2
- Relatórios administrativos e gerenciais sem abas: todos os conteúdos aparecem em cards na mesma página.
- Índice visual com atalhos para Crescimento, Receita, Uso, IA, Agenda e Comercial.
- CRM mantém arrastar e soltar sem refresh, sem a faixa informativa permanente.

## ZIP 34.3.1

- Mantém o intervalo mínimo entre respostas configurável por assistente.
- Mensagens recebidas durante o intervalo permanecem salvas e registradas como pendentes.
- Salvar configurações ou instruções reavalia automaticamente a última pendência de cooldown.
- Reprocessamento manual ignora somente a trava de intervalo, preservando as demais regras.
- Proteção contra resposta duplicada antes do reprocessamento.
- Reações do WhatsApp são ignoradas por padrão e podem ser habilitadas por assistente.
- Migration 038 não altera valores existentes de `cooldown_seconds`.


## ZIP 34.4 — Saúde do cliente e diagnóstico por empresa

- nova página de saúde por empresa, exclusiva do Super Admin;
- snapshots de WhatsApp, IA, n8n, agenda, assinatura e segurança;
- consulta da conexão e webhook diretamente na Evolution;
- incidentes deduplicados com visualização, acompanhamento e resolução;
- resolução automática quando a falha deixa de existir;
- histórico completo de incidentes;
- integração com Dashboard, Empresas e Checklist de implantação;
- execução manual, por CLI ou webhook protegido;
- migration `039_tenant_health_diagnostics.sql`.

## ZIP 34.4.1 — Configurações completas por empresa

- botão **Ver todas as configurações** na saúde do cliente;
- inventário central de empresa, assinatura, WhatsApp, IA, n8n, agenda, usuários, menus, notificações e privacidade;
- prompts e bases de conhecimento consultáveis em painéis recolhíveis;
- chaves, tokens e senhas sempre protegidos;
- busca, índice, expansão em massa e cópia de resumo técnico;
- gaveta responsiva, sem rolagem horizontal.

## ZIP 34.5 — Fluxo seguro de atendimento e administração de assistentes

- Corrige a tela de Assistentes de IA do Super Admin, com seleção da empresa e carregamento das configurações corretas.
- Links da Saúde e diagnóstico passam a abrir `/agents` já filtrado pela empresa.
- Adiciona grupo de atendimento aos contatos: não identificado, interessado, paciente atual, familiar, casal e outro.
- Salva a etapa atual da conversa, a situação da demanda e seu resumo.
- Envia grupo, status, tags, etapa e demanda para o contexto da IA.
- Bloqueia a criação de novo pré-agendamento enquanto a demanda não estiver coletada, recusada ou dispensada.
- Permite remarcação de paciente atual sem repetir a queixa quando a regra do grupo autorizar.
- Adiciona regras configuráveis por grupo em cada assistente.
- Permite revisar manualmente grupo, etapa e demanda na gaveta da conversa.
- Migration `040_conversation_flow_contact_groups.sql`.

## 34.5.1 — Horário local e pendências reais da IA

- Converte datas do diagnóstico do fuso da sessão MySQL para `APP_TIMEZONE`.
- Substitui a contagem de logs `ai.cooldown` pela contagem real de conversas sem resposta posterior.
- Separa conversas pendentes de mensagens acumuladas.
- Altera o status do assistente para Atenção quando há conversa aguardando resposta.
- Adiciona o botão Reprocessar agora na Saúde do cliente.
- Inclui diagnóstico SQL de timezone e pendências.

## ZIP 34.5.2

- adiciona ocorrências reais de IA e integrações à Saúde e diagnóstico;
- alinha a tela de diagnóstico aos badges “ainda não revisadas” da listagem de empresas;
- inclui filtros, detalhes técnicos e links para correção;
- permite revisar o lote atual ou marcar a empresa como corrigida;
- torna os badges de falha da listagem de empresas clicáveis;
- após Verificar agora, direciona para a seção de ocorrências;
- atualiza cache visual para 34.5.2.
## ZIP 34.5.3

- Corrigido parâmetro ausente `:seen_at` no sincronismo de incidentes da saúde do cliente.
- Monitoramento reconhece credenciais de IA por empresa e heartbeat real da régua de cobrança.
- Ações operacionais adicionadas aos avisos de backup, cobrança, IA, n8n e Evolution.
- Status técnicos traduzidos para linguagem do usuário.


## ZIP 35.0 — Agenda Google: ciclo completo e rotinas automáticas

- Cria o evento no Google Agenda ao confirmar um horário no modo Espaços livres.
- Usa chave idempotente por compromisso para reduzir risco de evento duplicado.
- Atualiza ou remove o evento vinculado em remarcações, cancelamentos e exclusões.
- Adiciona callback `calendar.free_slot.updated` com estados criado, atualizado, removido ou falha.
- Bloqueia opcionalmente a aprovação enquanto o Google não confirmar a operação.
- Libera automaticamente pré-reservas VAGO vencidas.
- Encerra solicitações sem callback e tenta novamente sincronizações pendentes.
- Adiciona execução manual, CLI e webhook protegido da manutenção.
- Inclui painel de manutenção na Agenda e novos indicadores na Saúde do cliente.
- Adiciona migration `041_calendar_google_full_cycle.sql` e template n8n de ciclo completo.

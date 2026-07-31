# RS Connect v36.10.4 — Datas técnicas em UTC e relatórios no fuso da empresa

- define a sessão PDO do MySQL como `+00:00`;
- adiciona `App\Core\Clock` para escrita UTC e conversão de apresentação;
- grava mensagens manuais, automáticas e recebidas pela Evolution em UTC;
- normaliza uma única vez mensagens e marcos históricos pela migration 071;
- recria os 10 triggers operacionais com `UTC_TIMESTAMP()`;
- converte filtros, série diária e histórico recente do relatório para o fuso da empresa;
- preserva `starts_at`/`ends_at` da agenda como horário local do compromisso;
- adiciona diagnóstico `utc_datetime_contract_v36.10.4.sql`.

# RS Connect v36.10.3 — Sincronização resiliente do status e dos ciclos

- corrige conversas encerradas na interface cujo ciclo permanecia `active`;
- fecha o ciclo no backend antes de liberar o responsável atual;
- garante ciclo ativo ao reabrir uma conversa, inclusive por envio de mensagem;
- recria `trg_rs_conversations_after_update_history` com sincronização idempotente;
- utiliza `status_changed_by_user_id` e o responsável anterior para preservar quem encerrou;
- repara ciclos divergentes já existentes sem duplicar o histórico;
- adiciona a migration `070_conversation_cycle_status_sync_compat.sql`;
- adiciona diagnóstico `conversation_cycle_status_sync_v36.10.3.sql`;
- mantém as cores por status entregues na v36.10.2.

# RS Connect v36.10.2 — Status visual das conversas

- diferencia conversas abertas, pendentes e encerradas por cor;
- atualiza o estado visual pelo polling em tempo real;
- mantém junto a recuperação resiliente da migration 069.

# RS Connect v36.10.1 — Recuperação resiliente dos ciclos de atendimento

- corrige conversas abertas que receberam mensagens, mas ficaram sem registro em `conversation_service_cycles` durante a janela entre o snapshot e a criação dos triggers;
- recupera os ciclos ausentes sem duplicar ciclos já existentes;
- reconstrói primeira entrada, última entrada, primeira resposta humana e atendente usando as mensagens reais;
- torna `trg_rs_messages_after_insert_metrics` autocorretivo: ao encontrar conversa sem ciclo ativo, cria o ciclo antes de atualizar as métricas;
- mantém os campos atuais da conversa coerentes com o ciclo reparado;
- adiciona resultado dinâmico à migration para não declarar sucesso quando o trigger não foi criado;
- adiciona diagnóstico `database/diagnostics/service_cycle_recovery_v36.10.1.sql`;
- exige a migration `069_service_cycle_recovery_compat.sql`.

# RS Connect v36.10.0 — Relatórios de equipe e profissionais

- cria a nova área `Relatórios → Equipe e profissionais`;
- separa profissional preferido, responsável pela conversa e profissional do agendamento;
- respeita os escopos `reports.team.view_own` e `reports.team.view_all`;
- permite ao Super Admin selecionar uma empresa por UUID público;
- permite filtrar período e profissional sem expor IDs numéricos no navegador;
- mostra conversas respondidas, mensagens humanas, primeira resposta, encerramentos, transferências e conversas abertas;
- mostra clientes preferenciais, agendamentos, confirmados, concluídos, cancelados e não comparecimentos;
- calcula resultado da agenda e taxa de comparecimento por profissional;
- adiciona evolução diária, comparativo da equipe, carga operacional e histórico recente;
- adiciona exportação CSV respeitando o mesmo escopo de permissão;
- adiciona diagnóstico `database/diagnostics/team_professional_reports_v36.10.0.sql`;
- adiciona a migration compatível `068_conversation_service_cycles_compat.sql`;
- preserva cada ciclo aberto/reaberto para não perder a primeira resposta ao encerrar e reabrir uma conversa;
- exige as migrations 067 e 068 aplicadas em sequência.

# RS Connect v36.9.1 — Base histórica e métricas por profissional

- registra atribuições, transferências e liberações de conversas em histórico próprio;
- registra abertura, reabertura, pendência e encerramento de cada conversa;
- identifica a primeira mensagem recebida, a primeira resposta humana e o usuário responsável;
- registra criação, status, troca de profissional, reagendamento e exclusão da agenda;
- mantém confirmação, conclusão, cancelamento e não comparecimento como marcos separados;
- adiciona permissões distintas para indicadores próprios e para toda a equipe;
- utiliza triggers para cobrir painel, webhook, IA, n8n e manutenção automática;
- recupera métricas antigas a partir das mensagens reais quando possível e cria snapshots idempotentes do estado atual;
- adiciona a migration compatível `067_operational_history_metrics_compat.sql`;
- prepara a base para `Relatórios > Equipe e profissionais` sem ainda alterar a interface do relatório.

# RS Connect v36.9.0 — Identificadores públicos UUID

- substitui IDs numéricos sequenciais nas URLs por UUIDs públicos opacos, autenticados e vinculados ao tipo do registro;
- mantém chaves primárias e estrangeiras numéricas somente no banco e no backend, evitando uma migração estrutural de alto risco;
- converte automaticamente links gerados pelo Router para `tenant_uuid`, `contact_uuid`, `conversation_uuid`, `appointment_uuid` e demais aliases públicos;
- redireciona links numéricos antigos para a URL canônica com UUID, preservando favoritos e links já distribuídos;
- rejeita UUID inválido, adulterado ou usado para o tipo de entidade errado com resposta 404;
- protege também o webhook da Evolution e os links ao vivo da tela de Conversas;
- preserva slugs legítimos como `/login?tenant=empresa-slug`;
- depende da `APP_KEY` estável já usada pelo RS Connect e não exige migration nova;
- adiciona verificação de saúde e teste de fumaça específico para roteamento UUID.

# RS Connect v36.8.2 — Visualizações de calendário da agenda

- mantém a visão operacional em Lista e adiciona Dia, Semana e Mês;
- permite filtrar por empresa, profissional e status em todas as visualizações;
- salva a última visualização escolhida por usuário no navegador;
- abre detalhes do compromisso em modal e mantém as ações administrativas na Lista;
- não adiciona arrastar e soltar nesta etapa.

# RS Connect v36.8.1 — Conflito do cliente na agenda

- impede que o mesmo contato tenha dois atendimentos sobrepostos, mesmo com profissionais diferentes;
- mantém profissionais diferentes disponíveis no mesmo horário para clientes diferentes;
- adiciona configuração por empresa, ativada por padrão;
- valida criação manual, pré-agendamento, confirmação, troca de profissional e escolha de horário;
- bloqueia conflitos também em pré-agendamentos iniciados pela conversa;
- remove da Agenda interna sugestões que já estejam ocupadas pelo próprio cliente;
- considera pré-agendado, aguardando aprovação, agendado e confirmado como horários ocupados;
- adiciona a migration compatível `066_contact_schedule_overlap_guard_compat.sql`;
- adiciona diagnóstico de duplicidades antigas e teste de fumaça.

# RS Connect v36.8.0 — Agenda opcional por profissional

- adiciona ativação independente da agenda por profissional, desativada por padrão;
- mantém o reaproveitamento automático do responsável da conversa como opção separada e desligada por padrão;
- permite horários, duração, intervalos, margem e antecedência individual por usuário;
- permite pausar novos agendamentos de um profissional sem inativar seu acesso;
- permite informar um Google Agenda diferente para cada profissional;
- filtra disponibilidade e conflitos pelo profissional selecionado;
- permite definir ou trocar o profissional na Agenda e na fila de pré-agendamentos;
- libera pré-reservas e limpa vínculos antigos antes de trocar a agenda responsável;
- bloqueia transferência para um profissional com conflito no mesmo horário;
- envia nome, usuário e calendar ID do profissional nos payloads do n8n;
- adiciona a migration compatível `065_professional_calendar_profiles_compat.sql`;
- adiciona diagnóstico e teste de fumaça da agenda individual.

# RS Connect v36.7.1 — Saudação neutra e notificações por direção

- substitui a mensagem fixa “Bem-vinda” por uma saudação neutra de acordo com o horário;
- identifica o usuário pelo primeiro nome após o login;
- contabiliza separadamente mensagens recebidas e enviadas na atualização em tempo real;
- mostra “Nova mensagem recebida” somente para mensagens realmente recebidas do contato;
- mantém “Mensagem enviada” como confirmação do envio feito pela plataforma;
- evita que mensagens humanas, da IA ou de automações sejam tratadas visualmente como recebidas;
- não exige migration nova.

# RS Connect v36.7.0 — Atendimento opcional por profissional

- adiciona ativação independente por empresa, desativada por padrão;
- permite definir um profissional preferido no contato sem obrigar atribuição automática;
- mantém a atribuição automática como opção separada e desligada por padrão;
- permite assumir, atribuir, transferir e liberar conversas ativas;
- bloqueia no backend a interferência de outro usuário enquanto houver responsável;
- libera o responsável ao encerrar a conversa;
- evita dupla atribuição simultânea com transação e `SELECT ... FOR UPDATE`;
- exibe profissional preferido e responsável atual nas telas de Contatos e Conversas;
- adiciona a migration compatível `064_professional_conversation_assignment_compat.sql`;
- preserva a v36.6.39 para empresas que mantiverem o recurso desligado;
- não inclui ainda agenda individual por profissional.

# RS Connect v36.6.39 — Assinatura humana entregue ao WhatsApp

- corrige a assinatura quando o atendimento é realizado por um Super Admin global;
- permite habilitar nome público e função também para usuários da Equipe RS;
- envia ao contato o nome do atendente em negrito na primeira linha da mensagem;
- mantém respostas da IA e automações sem assinatura humana;
- registra em auditoria e na resposta JSON se a assinatura foi realmente aplicada;
- não exige migration nova.

# RS Connect v36.6.38 — Status Evolution em tempo real

- Remove o status manual do cadastro de conexões.
- Força consulta quando `connection_state` está vazio.
- Mapeia `close` para `disconnected`.
- Usa UPDATE simples e distinto para conexão aberta ou fechada.
- Expõe `source_version` no feed para validar o deploy.
- Impede novos cadastros duplicados da mesma instância na mesma Evolution.

## 36.6.36 — Governança de mensagens e Evolution em tempo real

- adiciona nome público, função pública e controle de assinatura por usuário;
- permite assinar mensagens humanas com nome, nome e função ou nome e empresa;
- preserva o texto original no RS Connect e registra separadamente o conteúdo entregue ao WhatsApp;
- adiciona políticas de retenção completa, reduzida e efêmera por empresa;
- remove automaticamente conteúdo e payloads antigos sem apagar métricas, remetente, horário e status;
- inclui execução manual e template n8n diário para a política de retenção;
- registra eventos de conexão da Evolution e atualiza QR Code, conexão, perfil e motivo de desconexão por webhook;
- acompanha o status na tela sem recarregar, com polling leve e reconciliação direta quando o webhook estiver atrasado;
- configura automaticamente os eventos `MESSAGES_UPSERT`, `MESSAGES_UPDATE`, `CONNECTION_UPDATE`, `QRCODE_UPDATED` e `CONTACTS_UPSERT` ao criar/reconectar a instância;
- adiciona a migration `063_message_governance_evolution_realtime.sql`.

## 36.6.35 — Prompt Studio e versionamento de instruções

- questionário guiado para criação do primeiro prompt;
- geração determinística sem consumo obrigatório de API;
- validação de conflitos com horário, agenda e confirmação humana;
- preview editável antes de criar o assistente;
- rascunho por empresa/usuário;
- histórico de versões por assistente;
- restauração segura de versões anteriores;
- auditoria de geração, edição e restauração.

## 36.6.34.2 — Agenda interna e liberação da Agenda inteligente

- Etapa 4 do onboarding passa a exigir a escolha entre Sem agenda, Agenda interna e Agenda inteligente integrada.
- Agenda interna configura disponibilidade no próprio RS Connect, com horários por dia e sem n8n/Google Calendar.
- Agenda inteligente só fica selecionável após liberação e homologação pelo Super Admin.
- Super Admin controla o status técnico na tela da empresa.
- Mantém integralmente o teste gratuito e o hotfix de login da 36.6.34.1.

## 36.6.34.1 — Hotfix de login e sessão

- corrige o redirecionamento após CSRF expirado no formulário de login;
- impede gerar URLs como `/https://dominio/login` quando o referer já é absoluto;
- redireciona o login expirado diretamente para `/login`, com novo token de formulário;
- aceita URL absoluta apenas quando pertence ao mesmo domínio configurado em `APP_URL`;
- bloqueia redirecionamento externo e URLs iniciadas por `//`;
- recupera automaticamente URLs antigas malformadas que ainda estejam abertas no navegador;
- sem migration e sem alteração nas regras de teste gratuito ou onboarding.

## 36.6.34 — Teste gratuito e primeiro acesso guiado

- adiciona teste gratuito por quantidade de dias, com último dia e primeira cobrança calculados automaticamente;
- impede criação manual de cobrança enquanto o teste estiver ativo;
- permite definir a transição pós-teste: aguardar contratação com tolerância, ativar ou suspender;
- exibe dias restantes e regra de transição dentro da conta do cliente;
- reorganiza o primeiro acesso em Cadastro, LGPD, Atendimento, Agenda, WhatsApp, Agente e Teste final;
- libera as telas progressivamente e retoma sempre na etapa pendente;
- guarda as regras de atendimento antes da criação do agente e as aplica quando ele for criado;
- preserva o acesso de empresas que já possuíam operação ativa antes da atualização;
- adiciona a migration `060_free_trial_guided_first_access.sql`.

## 36.6.33 — Busca funcional na Base de contatos

- Corrige o uso repetido do mesmo placeholder SQL em uma conexão PDO com prepares nativos.
- Pesquisa nome, telefone normalizado, e-mail, empresa, observações e tags.
- Permite termos compostos e aplica todos os termos informados.
- Adiciona filtro automático durante a digitação e atualização imediata ao trocar selects.
- Exibe um estado vazio claro quando não houver correspondências.
- Não altera CRM, IA, Agenda, contatos cadastrados ou conversas.

## 36.6.32 — Recuperação pós-horário da Agenda

- A retomada pós-horário reentra na máquina determinística de Agenda antes de chamar o provedor de IA.
- Pedidos como “quero agendar” + “quarta 13h” + “online” são reunidos na mesma janela pendente.
- Quando a Agenda assume a retomada, a IA geral não gera uma promessa solta de consulta.
- O resultado de `requestAvailabilityIfNeeded` passa a ficar observável nos logs da recuperação.
- A pendência pós-horário é concluída quando a Agenda envia a pergunta/ack ou inicia corretamente a disponibilidade.
- O endpoint conhecido `rsconnect-agenda-cliente` bloqueia `ai.replied` e `message.received` mesmo sem registro em `n8n_tenant_flows`.
- O formulário do assistente também impede salvar esse writer como integração externa legada.
- Sem migration nova.

## 36.6.31 — Novo atendimento em drawer

- Corrige o recorte do formulário `+ Nova` em telas de Conversas com coluna estreita.
- O novo atendimento passa a abrir em drawer próprio sobre a interface, sem alterar a largura da Caixa de Entrada.
- Mantém busca preventiva de contato, detecção de conversa existente, seleção de instância e primeiro envio.
- Fechar/cancelar preserva a posição da lista de conversas; o drawer também fecha com Esc.
- Layout responsivo ocupa a tela inteira em dispositivos estreitos e usa painel lateral em desktop.
- Sem migration e sem alteração nas regras de IA, Agenda, CRM ou atendimento.

## 36.6.30 — Busca inicial, horários por dia e telemetria clara

- Nova conversa pesquisa contatos existentes antes do primeiro envio e sinaliza conversa já existente.
- Horário operacional do agente passa a permitir uma faixa diferente para cada dia, incluindo sábado reduzido.
- Refino visual de aceite LGPD, drawer de contato e cards de consumo.
- Franquia de IA passa a exigir `delivery_status=delivered`, alinhando o limite comercial à entrega real.
- Tela da assinatura passa a explicar chamadas ao provedor e exibir tokens registrados, sem confundir requests com interações comerciais.

## 36.6.29 — Busca confiável e avatar do contato

- Corrige a busca da tela Conversas e centraliza a mesma regra no carregamento inicial e no polling.
- Busca por nome, telefone, e-mail, empresa, última mensagem e qualquer mensagem do histórico da conversa.
- Normaliza números digitados com máscara, espaços, parênteses ou hífen antes de comparar com o telefone armazenado.
- Adiciona pesquisa instantânea com debounce sem recarregar a página; Enter/Filtrar continuam compatíveis.
- Sincroniza a lista durante o polling, removendo resultados que não atendem ao filtro atual.
- Passa a consumir a foto de perfil pelo endpoint de perfil da Evolution quando disponível.
- Processa `contacts.upsert` para atualizar avatar e mantém iniciais como fallback.
- Foto é enriquecimento visual: falha ou privacidade do WhatsApp nunca bloqueia mensagens nem atendimento.
- Não adiciona migration; reutiliza `contacts.avatar_url`, existente desde a migration 003.

## 36.6.28 — Polimento visual da Central de comunicação

- Mantém intacta a lógica funcional da Central de comunicação da 36.6.27.
- Redesenha o formulário do Super Admin em três etapas visuais: Conteúdo, Destino e interação, Entrega.
- Estiliza inputs, selects, textarea, validade e estados de foco no padrão visual do RS Connect.
- Apresenta empresas destinatárias em cards selecionáveis e canais de entrega em cartões com estado visual.
- Reforça a hierarquia do cabeçalho, indicadores e abas do módulo.
- Refina o preview em tempo real e o bloco de boas práticas.
- Atualiza o cache-busting de CSS/JS para 36.6.28.
- Não adiciona migration nem altera regras de envio, leitura ou resposta.

## 36.6.27 — Central de comunicação refinada

- Comunicados institucionais deixam de depender da permissão/visibilidade do módulo Notificações.
- Caixa de mensagem do cliente passa a ser renderizada pelo servidor quando já existe comunicado não lido.
- JavaScript hidrata o inbox a partir do payload inicial e mantém polling como atualização, não como única forma de exibição.
- Links do sininho com `communication_id` abrem diretamente o drawer correto.
- Admin reorganizado em abas: Novo comunicado, Histórico e Respostas.
- Formulário dividido por Conteúdo, Destino e interação e Entrega.
- Histórico substitui tabela densa por cartões operacionais com leitura, respostas e canais.
- Respostas administrativas ganham visão mais clara e resposta contextual.
- Mantido padrão visual sem emojis, apenas ícones vetoriais.
- Sem migration nova; a base continua na 059.

## 36.6.26 — Agenda resiliente e identidade confiável

- Fila rápida recupera callbacks de disponibilidade já salvos que ficaram sem resposta ao cliente.
- Solicitações de disponibilidade sem callback são reenviadas com o mesmo request/token após cooldown configurável.
- Resultado antigo de disponibilidade não pode sobrescrever a consulta atual da conversa.
- Novo nome automático do WhatsApp exige duas observações consistentes antes de substituir o fallback por telefone.
- Colisão do mesmo pushName entre números diferentes impede promoção automática e limpa nomes automáticos contaminados.
- Nomes manuais nunca são sobrescritos pelo webhook.
- Migration 059 adiciona rastreio de origem/candidato de nome.

## 36.6.25 — Central de comunicação in-app

- Evolui Comunicados para uma central administrativa RS e empresa cliente.
- Adiciona caixa flutuante somente para mensagens não lidas e drawer com histórico.
- Fechar/minimizar não marca leitura; a leitura ocorre somente ao abrir.
- Adiciona prioridade, validade opcional, confirmação de leitura e resposta do cliente.
- Respostas do cliente alertam o Super Admin; respostas da RS tornam o tópico não lido novamente.
- Mantém WhatsApp administrativo/e-mail preparados, sem falso status de entrega.
- Nova migration `058_client_communication_center.sql`.
- Interface nova usa ícones vetoriais, sem emojis.

## 36.6.24 — Modalidade antes da disponibilidade

- O pré-agendamento pergunta **Online ou Presencial** antes de consultar a Agenda Google quando a modalidade ainda não estiver definida.
- Respostas curtas como `online` e `presencial` passam a continuar corretamente um fluxo de agenda já aberto.
- A consulta automática não é enviada ao n8n enquanto a modalidade estiver indefinida.
- O payload de disponibilidade passa a usar `appointment_modality` como fonte de verdade.
- O callback do Google é filtrado novamente no backend: horários de outra modalidade são descartados.
- Alterar a modalidade invalida a consulta anterior e força nova busca; um hold anterior é liberado quando possível.
- O template **Eventos VAGO** recusa buscas sem modalidade e usa a modalidade como filtro obrigatório.
- Adiciona mensagem configurável para perguntar a modalidade e a migration `057_calendar_modality_before_availability.sql`.

## 36.6.23 — Google Agenda somente após confirmação real

- Corrige a fonte paralela da Agenda Google: integrações do calendário usam URLs próprias em `tenant_calendar_availability_settings`, independentemente de `n8n_tenant_flows`.
- `CalendarGoogleLifecycleService` deixa de criar/atualizar evento para registros apenas `scheduled`, `pre_scheduled` ou `awaiting_approval`.
- A manutenção automática passa a tentar eventos somente para `status=confirmed` e pré-agendamento aprovado.
- O ciclo completo n8n exige contrato `calendar_confirmed_sync_v1`, appointment_id, título, início e fim; remove fallback de título.
- O writer genérico também exige `appointment.status=confirmed` e aprovação quando aplicável.
- Mantém a migration base 056; não há SQL novo.

## 36.6.22 — Homologação alinhada à migration 056

- Atualiza `AppVersionService::REQUIRED_MIGRATION` para `056_n8n_agenda_event_contract.sql`.
- Remove o número `028` hardcoded do card "Migration base"; o número passa a ser derivado do arquivo exigido pelo pacote.
- Checklist "Migrations centrais" passa a orientar aplicação até a 056.
- Adiciona check "Contrato da Agenda Google" para detectar fluxos ativos do writer ainda inscritos em eventos genéricos.
- Atualiza cache-busting e metadados do pacote para 36.6.22.

## 36.6.21 — Horário confiável e Agenda com contrato forte

- Roteamento conversacional passa a preferir agente vinculado que esteja dentro do próprio expediente; um especialista fechado não declara o canal inteiro fora do horário quando o agente principal está disponível.
- Horário é revalidado imediatamente antes do envio da resposta da IA.
- Validação efetiva da conversa mostra agente realmente aplicado, hora local e faixa de expediente usada.
- URLs legadas configuradas diretamente no agente passam a respeitar o contrato do fluxo cadastrado; `ai.replied`/`message.received` não podem atingir o writer do Google Calendar.
- Cadastro do writer `Agenda Google Calendar por Empresa` força `calendar.appointment.created`, mesmo se alguém tentar marcar wildcard no Admin.
- Template de Agenda exige contrato `calendar_appointment_v1`, `appointment_id`, título, início e fim; remove o fallback perigoso `Compromisso RS Connect`.
- Campo de integração externa do agente passa a orientar explicitamente que o webhook de Agenda não deve ser cadastrado ali.

## 36.6.20 — Agenda por intenção real e callback de backup resiliente

- impede o fluxo **Agenda Google Calendar por Empresa** de receber `message.received`, `appointment.pre_scheduled` sem contrato válido ou mudanças de status que antes podiam criar eventos duplicados;
- adiciona gate no próprio workflow: só `calendar.appointment.created` com início e fim definidos chega ao node que cria evento no Google;
- remove o fallback perigoso em que uma conversa comum podia virar `Compromisso RS Connect`;
- nova intenção de agenda exige pedido explícito; apenas mencionar reunião/consulta + data/horário não abre pré-agendamento;
- preferências curtas continuam válidas quando a conversa já está em contexto real de agenda;
- migration 056 corrige cadastros legados do fluxo Google Calendar que estavam inscritos em `*`;
- callback do backup passa a aceitar `X-RS-Backup-Token` e qualquer alias de token de backup configurado;
- callbacks já em voo sobrevivem a rotação/redeploy de token quando `backup_job_id + execution_uuid` conferem;
- template do backup envia header dedicado sem remover a compatibilidade com `X-RS-Connect-Token`.

## 36.6.19 — Retry confiável de backup

- Corrige o dispatcher automático que usava `last_requested_at` como se fosse sucesso do ciclo.
- O vencimento passa a considerar `last_success_at` e a idade máxima do backup real/verificado.
- Falhas e timeouts não bloqueiam novas tentativas até o dia seguinte.
- `last_requested_at` passa a servir somente como cooldown de retry (30 min por padrão).
- Adiciona `OPERATIONS_BACKUP_RETRY_MINUTES` opcional (5–240 min; padrão 30).
- `/webhooks/operations/backups/dispatch` passa a retornar `evaluated`, `reason`, `eligible` e `next_retry_at`.
- Tela de Backup informa a janela de nova tentativa e esclarece que erro/timeout não encerra o ciclo.

## 36.6.18 — Manutenção da agenda auditável

- deixa explícito que **Executar manutenção agora** roda diretamente no RS Connect e não depende do n8n;
- mantém o n8n apenas como agendador automático da manutenção, autenticado por header;
- corrige o card de callbacks para contar somente requisições realmente pendentes (`responded_at IS NULL`) há mais de 30 minutos, usando o mesmo critério da rotina que as encerra;
- execuções automáticas globais passam a registrar também um resultado por empresa, evitando que o painel de um tenant mostre uma execução global que não o processou;
- mostra origem, status e resultados do último ciclo no painel;
- o template n8n identifica a origem como `n8n`;
- sem migration nova; mantém a 055 como última migration obrigatória.

## 36.6.17 — Manutenção automática da agenda

- adiciona o template n8n `template-calendar-maintenance.json`, ausente nas versões anteriores;
- o workflow roda a cada 10 minutos e chama `POST /webhooks/calendar/maintenance/run`;
- o download pelo RS Connect injeta `APP_URL` e `CALENDAR_MAINTENANCE_TOKEN` automaticamente;
- o token passa a ser enviado no header `X-RS-Calendar-Maintenance-Token`, evitando segredo na URL;
- o endpoint mantém compatibilidade com token em query/body e passa a aceitar também header e Bearer;
- a tela da agenda passa a orientar o uso do header seguro;
- sem migration nova; mantém a 055 como última migration obrigatória.

## 36.6.16 — Conversas compactas, aviso diário e tempo de interação

- mantém a lista de conversas e o histórico em painéis com scroll próprio no desktop;
- mantém cabeçalho, estado e campo de digitação visíveis enquanto o histórico rola;
- exibe quando uma demanda foi preservada fora do horário e a próxima abertura do agente;
- corrige a deduplicação da mensagem de ausência: agora é no máximo uma vez por **dia local**, permitindo novo aviso em 26/07 mesmo que tenha havido aviso em 25/07;
- redefine `cooldown_seconds` na interface como **Tempo de espera da IA**: conta a partir da última mensagem recebida, inclusive na primeira interação;
- nova mensagem durante a espera reinicia o relógio, agrupando o contexto antes de responder;
- a camada de Agenda/Pré-agendamento é adiada durante a espera e reavaliada pela Fila rápida depois do prazo;
- recuperação automática pós-horário deixa de usar `bypass_cooldown` e passa a respeitar o mesmo tempo;
- adiciona `AiReplyTimingService`, `AfterHoursAcknowledgementPolicyService` e smoke test específico;
- sem migration nova; mantém a 055 como última migration obrigatória.

## 36.6.15 — Confiabilidade das regras do agente

- Cria `AgentOperatingPolicyService` como fonte única para a restrição de horário dos agentes.
- Quando **Responder somente no horário configurado** estiver ativo, a regra técnica prevalece sobre prompt livre, pré-agendamento, seleção de horários, callbacks de agenda e n8n conversacional.
- Corrige o pipeline do webhook: a política de horário é avaliada antes da Agenda/Pré-agendamento.
- Callbacks tardios de disponibilidade revalidam o expediente antes de oferecer ou pré-reservar horários; fora do horário, a demanda é devolvida à recuperação pós-horário.
- Reduz falsos gatilhos de agenda: palavras casuais como hoje, tarde, noite, hora ou atendimento não iniciam agendamento sem intenção explícita ou contexto recente real de agenda.
- Perguntas sobre horário de funcionamento deixam de ser confundidas com pedido de agendamento.
- Estados antigos de pré-agendamento deixam de sequestrar conversas gerais indefinidamente.
- Contexto de agenda só é injetado no prompt quando a conversa realmente está em intenção/etapa de agenda.
- Classificação Cliente/Paciente, grupo, tags e cadastro passam a ser reforçados como fonte de verdade e não devem reabrir triagem já concluída.
- O drawer de Conversas ganha **Validação efetiva / Regras aplicadas agora**, mostrando agente, modo, horário, classificação, grupo, tags e intenção atual.
- Validação do cadastro do agente impede horário inválido, período invertido, restrição sem dias selecionados e timezone inválido.
- Adiciona smoke test e cenários de homologação para horário, agenda, cliente/paciente, takeover humano e callback tardio.
- Sem migration nova; mantém a 055 como última migration obrigatória.

## 36.6.14 — Planos comerciais claros

- Reformulada visualmente a aba Planos em Planos e cobrança.
- Starter passa a ser apresentado comercialmente como Essencial sem alterar a chave interna.
- Profissional ganha destaque de plano recomendado e Business posicionamento para escala.
- Custom com valor zero agora aparece como Sob consulta.
- Cards explicam Canais WhatsApp, Agentes de IA, Usuários e Franquia IA RS sem duplicidades.
- Recursos n8n são comunicados como automações integradas.
- Incluída seção explicativa dos limites e descrições no formulário de edição.
- Sem migration nova.

# Changelog

## 36.6.13 — Canais WhatsApp e roteamento de agentes

- Centraliza todos os números WhatsApp da empresa em uma única tela **Canais WhatsApp**.
- Cada número passa a ser tratado comercialmente como um canal do plano.
- Cria vínculo N:N: vários agentes podem atuar no mesmo canal e o mesmo agente pode atuar em vários canais.
- Cada canal pode definir um agente principal e especialistas com palavras de roteamento e prioridade.
- A primeira mensagem de uma conversa ainda sem agente pode escolher um especialista por assunto; sem regra específica, usa o principal.
- Depois da escolha, `conversations.ai_agent_id` mantém a conversa no mesmo agente para evitar troca de personalidade/contexto.
- O operador pode trocar manualmente o agente no drawer da conversa.
- Um canal pode ficar sem IA e continuar funcionando para atendimento humano.
- Agenda, pré-agendamento, falhas de entrega, saúde operacional e fila de reprocessamento passam a considerar o agente roteado da conversa.
- Mantém `ai_agents.instance_id` apenas como compatibilidade legada; a configuração normal passa a usar `ai_agent_instance_bindings`.
- Ajusta a linguagem dos planos: **Canais WhatsApp**, **Agentes especializados de IA** e **Automações integradas**, sem alterar preços ou limites atuais.
- Requer `database/migrations/055_multi_whatsapp_agent_routing.sql`.

## 36.6.12 — Métricas completas de IA e franquia RS

- Separa definitivamente cinco conceitos: mensagens trafegadas, interações automáticas entregues, chamadas ao provedor, tokens e franquia comercial da RS Connect.
- Mensagens recebidas e enviadas passam a aparecer como métrica de volume operacional, sem consumir franquia de IA.
- Uma interação comercial só é confirmada quando a resposta automática da IA é efetivamente entregue ao cliente. Falha de provedor, timeout, takeover humano ou falha na Evolution não consomem a interação comercial.
- Credencial própria do cliente continua contabilizando interações e telemetria técnica, mas não reduz a franquia RS.
- Registra chamadas ao provedor, tokens de entrada, saída, total e cache quando o provedor disponibiliza esses dados.
- Falhas e respostas descartadas passam a preservar a telemetria técnica já consumida, sem transformar custo técnico em cobrança comercial.
- O painel do cliente mostra mensagens movimentadas, interações de IA, franquia RS e uso com credencial própria em blocos separados.
- O Super Admin ganha telemetria por empresa e por assistente, com chamadas, tokens, falhas e custo estimado opcional.
- O custo estimado não usa preço hardcoded: só é calculado quando `AI_COST_RATES_JSON` estiver configurado, evitando preços de provedor desatualizados no código.
- Adiciona `delivery_status`, `provider_calls`, `total_tokens`, `cached_tokens` e moeda do custo estimado em `ai_usage_events`.
- Requer `database/migrations/054_ai_metrics_and_delivery_telemetry.sql`.

## 36.6.11 — Uso total de IA, notificações contínuas e menus do cliente

- O painel passa a destacar o total de respostas automáticas de IA no mês, somando credencial RS Connect e credencial própria do cliente.
- A franquia comercial continua consumida somente por respostas automáticas custeadas pela RS Connect.
- Os cards de limites deixam de expor chaves técnicas e passam a explicar o que cada indicador contabiliza.
- A Central de notificações mostra o histórico antes das preferências; as configurações ficam no final da página.
- Corrige a navegação do cliente para respeitar as marcações de Menu feitas pelo Admin RS em Privacidade/LGPD, Notificações, Assinatura, Usuários e Permissões.
- As abas internas de Minha empresa também passam a respeitar a visibilidade configurada.
- Sem migration nova; mantém 053 como última migration obrigatória.


## 36.6.10 — Validação da fila rápida e reparo da franquia

- Renomeia o workflow n8n para `template-fila-rapida-ia.json`, mantendo o título **Fila rápida da IA**.
- Define explicitamente `POST /webhooks/ai-reprocess/queue` no template e mantém o token em `X-RS-AI-Reprocess-Token`.
- Corrige a tela de uso para recuperar o limite legado de IA quando `ai_interactions_month` ainda não existir.
- Adiciona a migration 053 para reparar planos cujo limite ficou nulo após a migration 052.
- Revalida que o cron automático não usa `bypass_cooldown`; somente reprocessamento manual pode ignorar o intervalo.
- Mantém o envio humano assíncrono na tela de Conversas, sem reload e com foco no campo de digitação.

## 36.6.9 — Intervalo real e conversa contínua

- Corrige a exceção criada no 36.1.3 que fazia mensagens novas persistidas ignorarem `cooldown_seconds`; o intervalo mínimo volta a valer para toda resposta automática, exceto reprocessamento manual explícito.
- Mensagens recebidas durante o intervalo continuam salvas e vinculadas a `ai.cooldown`; a fila escolhe a demanda mais recente e o contexto inclui as mensagens acumuladas, evitando respostas duplicadas.
- Adiciona `GET/POST /webhooks/ai-reprocess/queue`, protegido por `AI_REPROCESS_CRON_TOKEN`, para reavaliar a fila rápida sem depender da execução diária de contingência.
- Atualiza o template n8n **Fila rápida da IA** para rodar a cada 1 minuto e baixa-lo já com `APP_URL` e `AI_REPROCESS_CRON_TOKEN` injetados pelo RS Connect.
- A Central de operação passa a mostrar separadamente o endpoint da fila rápida e a rotina diária, além de atalhos para franquia, custeio RS × cliente, pós-horário e alertas.
- O menu do cliente ganha **Assinatura e uso**, deixando visível o consumo da franquia de IA.
- O envio humano em Conversas passa a ocorrer por requisição assíncrona: a página não volta ao topo, o campo é limpo e mantém o foco, e a conversa continua posicionada nas mensagens mais recentes.
- Como fallback sem JavaScript, o redirecionamento volta diretamente ao `#conversation-composer`.
- Não requer migration nova; mantém a migration 052 como estrutura mínima desta linha.

## 36.6.8 — Consumo de IA e recuperação pós-horário

- Converte o antigo limite comercial `messages_month` em `ai_interactions_month`: no Starter, por exemplo, as 1.500 unidades passam a significar **1.500 respostas automáticas de IA** por mês.
- Mensagens recebidas, mensagens fixas de automação e respostas enviadas por atendentes humanos não consomem a franquia de IA.
- Cada resposta automática efetivamente enviada reserva/conclui um evento de consumo, com proteção de concorrência e liberação de reservas interrompidas.
- Quando o provedor informa usage, o evento guarda tokens de entrada e saída para auditoria interna; sugestões manuais também são registradas, mas não reduzem a franquia comercial.
- Diferencia `Custeio RS Connect` de `Custeio cliente` nas credenciais de IA. Credencial RS/global consome a franquia; chave própria é registrada separadamente e não reduz o limite RS.
- O painel da assinatura passa a separar uso faturável da RS Connect e uso com credencial própria, com renovação mensal da franquia, independentemente do ciclo financeiro da assinatura.
- Adiciona alertas deduplicados em 80%, 95% e 100% da franquia para cliente e Super Admin. Ao atingir 100%, somente novas respostas automáticas custeadas pela RS são pausadas; WhatsApp e atendimento humano continuam funcionando.
- Mensagens recebidas fora do horário passam a formar uma pendência por conversa. Várias mensagens na mesma janela são preservadas juntas e a mensagem de ausência é enviada no máximo uma vez.
- O Monitor operacional tenta recuperar essas conversas a cada execução, somente dentro do próximo horário válido, respeitando modo Humano/Pausado, resposta manual já realizada, assistente ativo, conexão WhatsApp e franquia disponível.
- Pendências bloqueadas por franquia permanecem armazenadas e voltam a ser elegíveis após renovação/aumento do plano; pendências já tratadas por humano são encerradas sem intervenção da IA.
- A Fila da IA mostra quantidade e detalhes das pendências pós-horário, com situação, próxima tentativa e atalho para a conversa.
- Requer `database/migrations/052_ai_usage_and_after_hours_recovery.sql`.

## 36.6.7 — Severidade operacional e diagnóstico da IA

- Diferencia assistente **desativado manualmente** de assistente **indisponível por erro**, evitando falso crítico quando cliente/equipe RS opta por desligar a automação.
- Usa auditoria para indicar, quando disponível, se a alteração veio do cliente ou da equipe RS, com autor e horário.
- Mantém falhas históricas visíveis sem tratá-las como indisponibilidade atual enquanto o assistente estiver desligado por configuração.
- Assistentes habilitados continuam críticos para ausência de credencial/WhatsApp ou falhas consecutivas, agora com estado operacional e motivo explícitos.
- `N8N_CALLBACK_TOKEN` e `AI_REPROCESS_CRON_TOKEN` passam de **Revisar** para **Recomendado** nos cenários em que representam endurecimento/acionamento externo, sem afirmar falha do serviço.
- Gateway ativo sem eventos recentes passa a **Sem evidência**, pois ausência de transação não comprova falha.
- Adiciona `unknown` ao status persistido de `system_health_checks` pela migration 051.
- Requer `database/migrations/051_operational_evidence_status.sql`.

## 36.6.6 — Takeover humano e continuidade de clientes

- Corrige o bypass encontrado no pré-agendamento: conversas em **Humano** ou **Pausado** agora bloqueiam também mensagens automáticas da agenda, não apenas a resposta do provedor de IA.
- Revalida o modo da conversa antes de confirmações, bloqueios e respostas assíncronas de agenda, evitando entrada tardia depois que a equipe assumiu o atendimento.
- Ao enviar mensagem manual pelo painel, o RS Connect assume a conversa como **Humano antes** de chamar a Evolution, fechando a janela em que outra automação poderia responder durante o HTTP.
- Classificação **Cliente** e grupos **Cliente atual/Paciente atual** passam a representar continuidade real: não exigem nova queixa/demanda para consultar, marcar ou remarcar horário.
- Normaliza estados antigos de fluxo que ainda estavam em `pending/collecting_demand` para clientes/pacientes existentes.
- O prompt reforça que cliente/paciente atual não deve voltar ao roteiro de novo lead nem receber novamente a pergunta de motivo/principal queixa.
- Requer `database/migrations/050_human_takeover_customer_context.sql`.

## 36.6.5 — Resolução e comunicação operacional

- O Painel operacional passa a abrir a Central de operação já contextualizada no problema detectado, por `diagnostico` e empresa afetada.
- Adiciona playbooks específicos para Evolution, n8n/callback, OpenAI 401/403/429/quota, backup (permissão, espaço, mysqldump e callback), banco, migrations, agenda, pagamentos, cron, fila da IA e relatórios.
- Cria Alertas operacionais do Super Admin com preferências por categoria, canal interno, cooldown de lembrete e aviso de recuperação.
- Adiciona `POST /webhooks/operations/checks/run` protegido por `OPERATIONS_MONITOR_TOKEN`, permitindo que n8n/cron faça a verificação sem depender de clique manual.
- Inclui o template n8n **Monitor operacional RS Connect**, com agenda de 15 minutos e injeção segura de `APP_URL` + `OPERATIONS_MONITOR_TOKEN` no download.
- Converte instâncias Evolution desconectadas com mensagens pendentes em incidentes por empresa, com alerta, cooldown e recuperação.
- O sino do Super Admin passa a exibir alertas operacionais e direcionar para a nova central de alertas.
- WhatsApp e e-mail entram no mesmo motor de alertas como canais preparados, sem registrar falso envio enquanto não existir provedor administrativo configurado.
- Cria o módulo Comunicados para uma, várias ou todas as empresas, com entrega imediata no sininho do cliente, associação opcional a incidente e histórico de leitura.
- WhatsApp administrativo e e-mail do cliente ficam registrados no mesmo comunicado como canais preparados para a etapa de integração externa.
- Problemas com empresa identificada ganham ação direta “Avisar cliente”, sem expor detalhes técnicos internos.
- Requer `database/migrations/049_operational_resolution_communications.sql`.

## 36.6.4 — Dados operacionais corrigidos

- Corrige uma colisão no `View::render`: a variável interna do renderer se chamava `$data` e, com `EXTR_SKIP`, impedia que controllers entregassem uma variável de view também chamada `$data`.
- O Painel operacional passa a receber de fato o resultado de `OperationalHealthService`, incluindo verificação, KPIs, problemas ativos, serviços, rotinas, empresas e histórico.
- A correção também normaliza telas diretas que já utilizavam o contrato `data => ...`, como Monitoramento, Backup automático, Status/Beta e Implantação, sem alterar a Central de operação baseada em partials.
- Mantém a regra conservadora do painel: evidência ausente continua sendo `Sem evidência`, nunca um falso verde.
- Não exige migration.

## 36.6.3 — Saúde operacional por evidência

- Mantém a Central de operação antiga intacta e evolui somente o Painel operacional paralelo.
- Cria `OperationalHealthService` como fonte única da nova visão, normalizando serviço, estado, evidência, validade, impacto, ação recomendada e detalhes técnicos.
- Um serviço só aparece como **Operando** quando existe evidência positiva ainda válida; ausência de erro ou evidência antiga deixa de gerar falso verde.
- Adiciona estado explícito **Sem evidência** e diferencia **Crítico**, **Atenção**, **Bloqueio externo**, **Operando** e **Não configurado**.
- O topo passa a informar a conclusão em linguagem direta e a situação da verificação: completa, parcial ou ainda não verificada.
- Adiciona KPIs compactos de serviços disponíveis, críticos, atenções, bloqueios externos, itens sem evidência e empresas afetadas.
- Reestrutura **Problemas ativos** para responder o que aconteceu, impacto, ação recomendada, evidência e atalhos; detalhes HTTP/HTML ficam recolhidos.
- Adiciona matriz de **Saúde dos serviços** com estado, evidência, idade da validação e acesso à ferramenta correspondente.
- Adiciona painel de **Rotinas automáticas** com última execução, resultado e próxima execução esperada para cobrança, Fila da IA, backup e relatórios.
- Adiciona histórico compacto das evidências das últimas 24 horas.
- A visão por empresa deixa de tratar configuração como funcionamento: WhatsApp, IA e Agenda exigem evidência recente para aparecerem como operacionais.
- Mensagens preservadas por Evolution desconectada continuam classificadas como bloqueio externo, sem transformar a Fila da IA em falsa falha interna.
- Mantém `OperationalOverviewService` apenas como fachada de compatibilidade para evitar duas lógicas concorrentes.
- Não exige migration.

## 36.6.2 — Painel operacional paralelo

- Cria uma segunda visão de operação em `/painel-operacional`, sem alterar a Central de operação existente.
- Adiciona o menu **Painel operacional** em Operação RS, mantendo **Central de operação** como ferramenta técnica e fallback.
- Reaproveita os diagnósticos já existentes de monitoramento, Fila da IA e backups para apresentar uma leitura orientada à ação.
- A nova tela destaca primeiro problemas críticos, atenções e bloqueios externos, deixando itens saudáveis compactos em segundo plano.
- Mensagens presas por WhatsApp desconectado aparecem como dependência externa, com empresa afetada, impacto e ação direta para Conexões.
- Adiciona resumo das rotinas essenciais: cobrança, Fila da IA, backup e relatórios.
- Adiciona visão rápida por empresa de WhatsApp, IA, Agenda e cobrança, com empresas que precisam de revisão posicionadas primeiro.
- Mantém acesso explícito à Central de operação para logs, históricos e detalhes técnicos.
- Não exige migration.

## 36.6.1 — Evolution ao vivo e backup n8n sem `$env`

- Corrige a causa do erro `access to env vars denied`: o template de backup deixa de ler `$env`/`process.env` em qualquer node do n8n.
- O download do template injeta a URL pública e o `OPERATIONS_BACKUP_TOKEN`; callback, token e caminho do script seguem dentro do payload enviado pelo próprio RS Connect.
- O backup passa a usar o token global ponta a ponta, inclusive no teste do webhook e no callback.
- Antes de reprocessar uma mensagem, o RS Connect consulta `/instance/connectionState/{instancia}` diretamente na Evolution e atualiza o estado salvo, evitando confiar em status `open/connected` desatualizado.
- A Fila da IA também atualiza ao vivo o estado das instâncias que possuem mensagens presas.
- Falhas de envio passam a registrar `Evolution sendText HTTP ...` e a fase exata da falha; isso separa erro de WhatsApp de erro OpenAI/Gemini.
- O envio pela Evolution normaliza números brasileiros de 10/11 dígitos com DDI 55 antes do `sendText`, reduzindo `HTTP 400` causado por telefone local sem código do país.
- Falhas históricas no formato genérico `HTTP 400: ...`, produzidas pelo antigo `EvolutionService`, deixam de alimentar falsamente o card OpenAI/IA e são direcionadas ao diagnóstico da Evolution.
- Não exige migration.

## 36.6.0 — Estabilização operacional

- Corrige `Permission denied` do backup: o script `scripts/rsconnect-backup.sh` passa a ser distribuído com permissão executável e o template n8n chama explicitamente `bash`, sem depender do chmod preservado pelo deploy.
- O workflow de backup passa a registrar o callback no RS Connect e depois marcar a execução do n8n como falha quando o backup realmente falhar, evitando falso “Succeeded”.
- Ao revisar/acompanhar/resolver um incidente de saúde da empresa, as ocorrências operacionais anteriores também passam a ser reconhecidas como revisadas; o badge “Não revisada” é atualizado após o reload.
- Falhas de IA cuja causa real é uma instância Evolution desconectada passam a ser identificadas como bloqueio de WhatsApp, com atalho para Conexões.
- O reprocessamento da IA deixa de insistir em uma instância desconectada: preserva a pendência, contabiliza como bloqueada e aguarda reconexão sem gerar um novo `ai.failed`/HTTP 400 a cada execução.
- Monitoramento da fila diferencia falha real de pendência aguardando reconexão.
- Não exige migration.

## 36.5.9 — Central de operação e diagnóstico da fila

- Move o botão hamburger para fora do cabeçalho filtrado e o fixa diretamente ao viewport, evitando que desapareça durante a rolagem.
- Reorganiza o Monitoramento para manter backups detalhados na aba Backups e deixar a visão geral mais focada em saúde, alertas e incidentes.
- Limita históricos e relações extensas às 3 entradas mais recentes, com botão “Ver mais” para expandir quando necessário.
- A Segurança passa a explicar individualmente OPENAI_API_KEY, N8N_CALLBACK_TOKEN, CALENDAR_MAINTENANCE_TOKEN, BILLING_CRON_TOKEN, AI_REPROCESS_CRON_TOKEN e credenciais da Evolution, distinguindo itens obrigatórios, opcionais e já atendidos por configuração por empresa.
- A Fila da IA passa a agrupar pendências por empresa, instância Evolution e assistente, mostrando estado da conexão, quantidade de conversas presas e última falha.
- Documenta no próprio diagnóstico que, após a primeira falha do mesmo assistente, novas tentativas daquele grupo são interrompidas na execução atual para evitar repetição em massa do mesmo erro.
- Histórico de cada verificação do Monitoramento passa a exibir as 3 evidências mais recentes.
- Não exige migration.

## 36.5.8 — Administração RS, monitoramento e navegação

- Reorganiza o menu do Super Admin em **Automação e integrações**, **Financeiro**, **Operação RS** e **Administração RS**.
- Agrupa **Fluxos n8n** e **Templates n8n** sob um único item **n8n**, com nova visão geral e navegação interna por Visão geral, Fluxos e Templates.
- Redesenha o Monitoramento da Central de operação com saúde geral, evidência da última validação, busca, filtros por status/categoria e histórico recente de cada ferramenta.
- Amplia as verificações para Evolution, n8n, OpenAI/IA, Google Agenda, pagamentos, cron de cobrança, fila da IA, relatórios, backup, banco e migrations.
- Diferencia **Operando**, **Atenção**, **Crítico** e **Sem evidência**, evitando considerar uma ferramenta saudável sem registro que comprove seu funcionamento.
- A Fila da IA passa a mostrar erros recentes com empresa, assistente, contato, etapa provável da falha e atalhos para conversa, Evolution e credenciais.
- Mantém o botão hamburger fixo durante a rolagem em todas as telas autenticadas.
- Adiciona botão global **Voltar ao topo** para cliente e Super Admin, com comportamento responsivo.
- Mantém URLs antigas de Fluxos n8n e Templates n8n funcionando.
- Não exige nova migration.

## 36.5.7 — Identificação de contatos, toque mobile e cron seguro

- Corrige o caso em que novas conversas podiam receber automaticamente o nome de um usuário interno da empresa, como `Rafa Silveira`, quando a Evolution devolvia esse valor como `pushName`.
- Nomes de usuários ativos do próprio tenant deixam de ser aceitos como nome automático de um novo contato; nesse caso a conversa fica identificada pelo telefone até existir um nome confiável ou edição manual.
- Adiciona `touch-action: manipulation` e feedback tátil/visual aos principais controles para reduzir atraso percebido de clique em dispositivos móveis.
- O endpoint do cron de cobrança passa a recusar execução quando `BILLING_CRON_TOKEN` não estiver configurado, evitando cron público por engano.
- O download do template do cron exige `APP_URL` HTTPS e `BILLING_CRON_TOKEN` antes de gerar o JSON pronto para importar no n8n.
- Atualiza cache-busting de `app.css` e `app.js` para 36.5.7.
- Não exige nova migration.

## 36.5.6 — Homologação final e correções de produção

- Faz a classificação **Cliente** prevalecer sobre o grupo conflitante **Novo interessado**, criando o contexto de **Cliente atual** e evitando reinício indevido da triagem/agenda.
- Preserva nomes de contatos já corrigidos manualmente e evita reaproveitar automaticamente o mesmo `pushName` em telefones diferentes; também normaliza `remoteJidAlt` quando a Evolution envia identificadores `@lid`.
- Revalida o modo da conversa imediatamente antes do envio da IA: ao **Assumir atendimento**, uma resposta que ainda estava sendo gerada é descartada e a IA permanece pausada.
- Mantém o botão **Reprocessar IA** visível no diagnóstico RS mesmo quando não há erro pendente.
- Corrige falso **Crítico** em fluxos n8n que já tiveram sucesso depois das falhas recentes.
- Corrige o histórico de Automações para exibir etapas tratadas pela agenda como **Concluídas**, em vez de “não executadas”.
- Separa execução manual da régua e execução real do cron no monitoramento. O template do cron passa a ser baixado com `APP_URL` e `BILLING_CRON_TOKEN` do ambiente e timezone `America/Sao_Paulo`.
- Adiciona atalho **Baixar cron n8n** na Régua de cobrança.
- Reforça responsividade em mobile: elimina overflow horizontal, usa `100dvh` nos drawers e mantém rodapé de salvar acessível.
- Atualiza cache-busting de `app.css` e `app.js` para 36.5.6.
- Não exige nova migration.

## 36.5.5 — Prontidão beta e endereço em Minha empresa

- Atualiza o versionamento interno para refletir o pacote atual.
- Faz o diagnóstico Beta reconhecer a migration `048_reporting_metrics_foundation.sql` e a fundação de relatórios.
- Ajusta a grade do formulário de endereço em Minha empresa para exibir CEP, rua, número, complemento, bairro, cidade e estado com melhor distribuição visual.
- Corrige o espaçamento da dica de CEP para evitar sobreposição e desalinhamento no card.
- Atualiza o cache-busting de `app.css`, `app.js` e `company-settings.js` para 36.5.5.
- Não exige nova migration.

## 36.5.4 — Equipe e acessos em drawer

- Remove o formulário de novo usuário que ficava permanentemente aberto ao lado da lista.
- O cadastro passa a abrir somente ao clicar em “Novo usuário”, seguindo o padrão já usado em Contatos.
- A edição deixa de abrir um formulário dentro da tabela e passa a usar a mesma gaveta lateral do cadastro.
- Reorganiza a tela em resumo da equipe, lista responsiva e formulário com seções de acesso, identificação e segurança.
- Mantém a proteção existente para alteração do próprio perfil e não exige migration.
- Atualiza o cache-busting de `app.css` e `app.js` para 36.5.4.

## 36.5.3 — Cadastro mais limpo e endereço por CEP

- Substitui os campos bloqueados de Razão social, CNPJ/CPF e Segmento por um resumo cadastral compacto, sem aparência de campo editável.
- Aplica o mesmo padrão visual aos dados oficiais exibidos em Primeiros passos.
- Adiciona preenchimento automático de rua, bairro, cidade e estado ao informar um CEP válido em Minha empresa.
- Mantém número e complemento sob edição do cliente e preserva preenchimento manual caso o serviço de CEP esteja indisponível.
- Adiciona máscara de CEP, estados visuais de consulta e mensagens amigáveis de sucesso/erro.
- Não exige migration.

## 36.5.2 — Minha empresa e dados mestres

- Enxuga o menu do cliente: Administração passa a exibir apenas Minha empresa e Central de ajuda.
- Notificações permanecem acessíveis pelo sininho do topo.
- Minha empresa ganha navegação interna por Dados da empresa, Equipe e acessos, Assinatura e Privacidade.
- Razão social, CNPJ/CPF e segmento passam a ser dados mestres somente leitura para clientes.
- Backend ignora tentativas de alteração desses dados mestres por contas cliente.
- Primeiros passos reaproveita os dados cadastrados pelo Admin RS e bloqueia os campos mestres.
- Usuários/Acessos passam a ser apresentados como Equipe e acessos dentro da central da empresa.

## 36.5.1 — Comercial em tempo real

- Renomeia o título superior de CRM para Comercial no cliente e Comercial RS no Super Admin.
- Atualiza em tempo real os cards de Negócios abertos, Valor em aberto, Ganhos e Receita ganha após mover oportunidades no Kanban.
- Atualiza também quantidade e valor total exibidos em cada etapa sem recarregar a página.
- Incrementa a versão do JavaScript principal para evitar cache do comportamento antigo.

## 36.5.0 — Experiência do Cliente

- Novo dashboard operacional do cliente.
- CRM renomeado para Comercial.
- Agenda com resultados no topo e configurações recolhíveis.

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

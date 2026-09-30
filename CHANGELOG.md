## 36.40.3 — Agenda publicada e antecedência coerente

### Corrigido
- diagnóstico confirmou que a Agenda publicada estava correta, mas a preferência “quarta pela manhã” era zerada pela antecedência mínima de 48h (`search_start_at = search_end_at = 12:00`);
- períodos totalmente bloqueados por antecedência mínima deixam de virar uma busca vazia artificial e passam a registrar o motivo operacional real;
- o WhatsApp informa quando a preferência está dentro da antecedência mínima, em vez de afirmar genericamente que não existem horários;
- horários publicados ganham uma política própria: por padrão respeitam a antecedência mínima, mas a empresa pode desligar essa trava quando a publicação explícita da vaga deve autorizar agendamento de curto prazo;
- frases de continuação como “é melhor para ela”, “prefiro assim” e confirmações curtas não preenchem demanda ou outros campos livres por acidente;
- regra é genérica e não depende de Psicologia ou de nomes de campos específicos.

### Migration
- nova `123_published_slots_min_notice_policy.sql`.


## 36.40.2 — Reset de homologação compatível com prepares nativos

### Corrigido
- `bin/reset-test-conversation.php` deixa de reutilizar o mesmo placeholder nomeado em consultas `OR`, compatibilizando o utilitário com `PDO::ATTR_EMULATE_PREPARES=false`;
- lookup de empresa por nome/slug e de contato por nome/telefone passa a usar parâmetros distintos;
- erros inesperados do utilitário CLI passam a ser exibidos diretamente no terminal, sem depender do `APP_DEBUG`, facilitando diagnóstico seguro.

### Migration
- nenhuma nova; permanece `122_calendar_client_communications.sql`.


## 36.40.1 — Isolamento de ciclo e respostas antecipadas

### Corrigido
- memória progressiva de atendimento anterior deixa de competir com a triagem estruturada do ciclo atual;
- novo ciclo remove também a memória progressiva específica da conversa;
- identidade estruturada da pessoa atendida prevalece sobre nomes presentes em histórico/memória antigos;
- mensagens rápidas podem preencher antecipadamente um único campo futuro de forma inequívoca sem alterar a ordem das perguntas;
- relato já enviado antes da etapa de modalidade não é descartado e não precisa ser solicitado novamente depois;
- incluído `bin/reset-test-conversation.php` para limpar de forma explícita e limitada o estado/histórico de contatos de homologação.

### Migration
- nenhuma nova; permanece `122_calendar_client_communications.sql`.
# 36.40.0 — Preferências de agenda normalizadas

- centraliza a interpretação de dia, data, horário, período e modalidade em um resolvedor único e genérico, usado pela Agenda sem regras específicas para Psicologia ou outro nicho;
- pedidos com “vaga/vagas” passam a abrir intenção de agenda de forma explícita, inclusive antes da triagem;
- números soltos usados como idade deixam de ser interpretados como horário; somente `HH:MM`, `14h`, `14h30` ou construções inequívocas como “às 14” viram horário;
- “quero trocar a modalidade” passa a ser reconhecido como correção de preferência e, no modo de escolha, reabre a pergunta de modalidade sem inventar uma resposta;
- pedidos de troca com origem e destino, como “trocar de presencial para online”, usam sempre a modalidade de destino; compromissos já confirmados reconhecem a troca como ajuste do próprio agendamento sem sobrescrever silenciosamente o horário atual;
- quando o contato informa a nova modalidade, consultas/opções anteriores são invalidadas antes da nova busca; uma opção da modalidade antiga nunca é escolhida só porque o texto também contém um horário;
- a política configurada pela empresa prevalece em todas as camadas: `not_applicable` ignora modalidade, `single` mantém a modalidade fixa e `choice` permite alteração;
- a triagem e o pré-agendamento passam a usar a mesma política efetiva, evitando que um texto do contato reintroduza modalidade em um negócio que não trabalha com esse conceito;
- correções de modalidade e preferência sincronizam o estado estruturado da conversa para não deixar o prompt da IA com dados antigos;
- consultas apenas por dia não inventam 09:00 e o mesmo dia da semana é considerado hoje antes de pular sete dias, preservando vagas futuras do próprio dia;
- a resposta de indisponibilidade passa a refletir o escopo realmente pesquisado, reduzindo confusão entre vagas publicadas em dias diferentes; uma vaga liberada na quarta não é reutilizada para um pedido de quinta;
- sem migration nova; permanece obrigatória `122_calendar_client_communications.sql`.

# 36.39.2 — Agenda publicada sem filtros residuais

- A consulta conversacional da Agenda publicada deixa de restringir vagas pelo responsável salvo automaticamente no pré-agendamento; a vaga escolhida passa a definir o profissional.
- Nova preferência libera hold anterior, invalida o slot escolhido e limpa o cursor técnico da disponibilidade antes de uma nova busca.
- Busca publicada possui fallback defensivo sem filtro de profissional para pré-agendamentos automáticos antigos.
- Empresas que já tinham horários publicados antes da 36.39.1 usam essas vagas quando o modo calculado não encontra opções, evitando falso “sem horários”.
- Publicar horários ativa a estratégia `published` com UPSERT, inclusive em tenants antigos sem linha prévia de configuração de disponibilidade.
- Payload diagnóstico da busca interna passa a registrar janela, modalidade e filtro de profissional efetivamente usados.
- Sem migration nova; permanece `122_calendar_client_communications.sql`.

# 36.39.1 — Consulta fiel dos horários publicados

- corrige a consulta ampla após uma tentativa exata sem vaga: “na quarta-feira tem algum horário?” limpa o horário anterior e pesquisa o dia solicitado;
- impede que pré-agendamentos antigos contendo apenas preferência de dia/horário sejam tratados como compromissos ocupados do próprio contato;
- alinha a verificação de conflito do contato com a regra já usada pela Agenda: somente compromisso real, pré-agendamento manual ou slot efetivamente selecionado bloqueia;
- no modo **Horários liberados**, a vaga publicada é a regra mais específica e não é descartada posteriormente por filtros genéricos de dia da modalidade;
- corrige a interpretação de DATETIME local ao aplicar filtros de dia, evitando conversão UTC indevida;
- em pré-agendamentos de IA ainda sem slot escolhido, a busca publicada não fica presa ao responsável automático da conversa; o profissional pode ser definido pela própria vaga escolhida;
- ao publicar horários na Agenda interna, a estratégia **somente horários liberados** passa a ser ativada automaticamente para aquela empresa; empresas que nunca publicam horários continuam preservadas no modo anterior;
- mantém todas as correções de continuidade, confirmações automáticas e comunicação da 36.39.0;
- não há migration nova; permanece obrigatória `122_calendar_client_communications.sql`.

# 36.39.0 — Continuidade do agendamento e confirmações automáticas

- adiciona uma camada determinística de **agendamento existente** antes da triagem e de qualquer novo fluxo de disponibilidade;
- perguntas como “minha consulta está confirmada?”, “que horas é?”, “onde será?”, “quero cancelar” e “preciso remarcar” passam a consultar o compromisso real do contato;
- preserva a intenção de consulta sobre compromisso também na retomada pós-expediente, impedindo que a fila reabra uma busca de novos horários;
- permite, por configuração, responder informações do próprio compromisso imediatamente fora do horário comercial;
- separa o **status do compromisso** da **confirmação do cliente**, com estados para aguardando resposta, presença confirmada, não comparecimento informado, pedido de cancelamento e pedido de remarcação;
- cria configuração por empresa para disparos ao cliente ao registrar, confirmar, cancelar/recusar e remarcar um agendamento;
- adiciona lembrete automático configurável em minutos antes do compromisso e pedido automático de confirmação de presença;
- reaproveita o cron de notificações existente para processar a fila de mensagens da Agenda, sem exigir um segundo agendador;
- ao alterar a política de lembrete/confirmação, recalcula os disparos futuros dos compromissos já confirmados;
- pedidos de cancelamento/remarcação recebidos pelo WhatsApp não liberam o horário automaticamente: a equipe é avisada e o compromisso só muda após alteração efetiva na Agenda;
- mostra o estado de confirmação do cliente nos cards da Agenda;
- adiciona em **Agenda → Configurações** o painel “Comunicação e confirmação do agendamento”, com mensagens editáveis e variáveis de data, hora, local, modalidade e profissional;
- nova migration obrigatória: `122_calendar_client_communications.sql`;
- incrementa o manifesto de migrations para a sequência 129.

# 36.38.2 — Navegação única e proporcional da Agenda

- elimina a navegação duplicada entre **Compromissos / Disponibilidade** e as quatro abas internas da Agenda;
- consolida toda a navegação em uma única barra com cinco áreas: **Compromissos**, **Visão geral**, **Disponibilidades**, **Pré-agendamentos** e **Configurações**;
- distribui as cinco opções em colunas de mesma proporção no desktop, evitando uma aba superior larga e outra inferior redundante;
- mantém a mesma barra tanto na tela de compromissos quanto nas telas de disponibilidade, deixando claro em que área o usuário está;
- em telas menores, mantém uma única navegação com rolagem horizontal em vez de empilhar dois menus;
- remove o botão redundante **Ver compromissos** do cabeçalho das telas de disponibilidade, pois o acesso agora está na própria barra principal;
- não altera workflow, consulta da Agenda interna, horários publicados, pré-reserva, integrações ou banco de dados;
- invalida apenas os assets visuais para `36.38.2`; não há nova migration e permanece obrigatória `121_internal_calendar_published_slots.sql`.

# 36.38.1 — Agenda organizada por abas e disponibilidades operacionais

- reorganiza **Agenda → Disponibilidade** em quatro abas internas: **Visão geral**, **Disponibilidades**, **Pré-agendamentos** e **Configurações**;
- remove a mistura visual entre horários, pré-agendamentos, regras estruturais e diagnósticos técnicos;
- move **Agenda por profissional**, **Regras da agenda**, manutenção e diagnóstico RS para a aba **Configurações**;
- concentra buscas, opções encontradas e validação do cliente na aba **Pré-agendamentos**;
- redesenha a aba **Disponibilidades** com resumo por status, formulário de publicação em blocos, filtros por período/profissional/modalidade/status e listagem agrupada por dia;
- mantém a estratégia e a tabela `calendar_internal_slots` da 36.38.0 sem alterar o contrato de disponibilidade do agente;
- preserva empresas em modo calculado e Google Agenda; nenhuma estratégia é trocada automaticamente;
- mantém os retornos de salvar/publicar/buscar dentro da aba correta para evitar que o usuário volte a uma tela diferente após cada ação;
- atualiza os links da Agenda para abrir resultados diretamente em **Pré-agendamentos**;
- invalida apenas os assets visuais para `36.38.1`; não há nova migration e permanece obrigatória `121_internal_calendar_published_slots.sql`.

# 36.38.0 — Horários publicados na Agenda interna

- adiciona a estratégia opt-in **Oferecer somente horários liberados** para a Agenda interna;
- preserva **Calcular pelos horários de trabalho** como padrão para empresas existentes, sem mudança silenciosa de comportamento;
- cria `calendar_internal_slots` para separar disponibilidade explícita de compromissos reais;
- permite liberar faixas que são convertidas em vagas concretas por duração e intervalo, com repetição opcional no mesmo dia da semana por até 52 semanas;
- permite disponibilidade geral da empresa ou vinculada a um profissional e modalidade;
- o agente consulta somente vagas publicadas quando a estratégia estiver ativa e nunca infere que um espaço vazio é disponibilidade;
- vagas conflitantes com compromissos reais são descartadas da consulta;
- ao escolher uma opção, a vaga é pré-reservada de forma concorrente/atômica e não pode ser consumida por duas conversas;
- a escolha de uma vaga de profissional pode atribuir automaticamente o responsável ao pré-agendamento;
- confirmação marca a vaga como utilizada; cancelamento, recusa e remarcação devolvem a vaga à disponibilidade;
- adiciona painel **Horários liberados** em Agenda → Horários e regras;
- nova migration obrigatória: `121_internal_calendar_published_slots.sql`;
- invalida cache exato e assets para o contrato `36.38.0`.

# 36.37.5 — Coleta personalizada sem contaminação por dúvidas

- impede que uma pergunta informativa do contato (ex.: “qual o valor da consulta?”) seja gravada automaticamente no próximo campo personalizado da Ordem do atendimento;
- mantém o cursor na informação pendente e permite que a IA responda a dúvida antes de retomar a coleta;
- preserva o consumo em sequência de respostas determinísticas em bursts (nome, idade, modalidade, preferência etc.);
- adiciona, para campos personalizados, a opção explícita “Aceitar uma pergunta do contato como resposta desta informação”, desligada por padrão;
- preserva as demais opções do campo ao salvar a nova configuração;
- invalida cache exato e assets para o contrato 36.37.5;
- sem nova migration.

# 36.37.4 — Fluxo contínuo e pré-agendamento legível

- reconcilia o cursor salvo da conversa com a **Ordem do atendimento atual** a cada turno; mover etapas com conversas em andamento deixa de manter a sequência antiga em memória;
- o runtime passa a derivar `active`, `required_for_completion` e `required_before_schedule` diretamente do workflow já persistido, sem depender de um novo salvamento para neutralizar flags legados do catálogo;
- campos personalizados de coleta (como uma nova **Demanda**) continuam válidos e avançam para a próxima etapa configurada, inclusive quando o cursor antigo apontava para preferência de agenda;
- ao concluir a última coleta anterior a `calendar.*`, o pedido de agenda é retomado automaticamente usando os dados já coletados, sem exigir que o contato repita “quero agendar”;
- a **Agenda interna** pode consultar disponibilidade compartilhada da empresa mesmo quando a agenda por profissional está habilitada e ainda não existe responsável definido; a exigência de profissional continua protegida na aprovação/confirmação;
- evita o silêncio após a última etapa de coleta causado por `professional_required` antes da própria consulta da Agenda interna;
- redesenha o card de **Pré-agendamento** na lista da agenda: o conteúdo passa a ocupar a largura útil, campos são exibidos em cards legíveis e valores deixam de quebrar uma letra por linha;
- o card passa a listar também as **informações personalizadas coletadas** usando os rótulos configurados pelo tenant; `Demanda`, `Convênio`, `Unidade` e outros campos deixam de depender de nomes fixos no código;
- a situação de demanda legada só aparece quando existe uma demanda legada real, evitando mostrar “pendente” para empresas que usam um campo personalizado de coleta;
- invalida CSS/JS e cache exato com contrato `36.37.4`;
- não há migration nova; permanece obrigatória `120_agent_turn_state_cursor.sql`.

# 36.37.3 — Consulta assertiva da Agenda interna

- corrige a trava residual que ainda exigia `online/presencial` em `requestAvailabilityIfNeeded()` mesmo quando a empresa configurou **Forma de atendimento = Não se aplica**, modalidade única ou posicionou a escolha depois da ação de agenda;
- a prontidão para consultar disponibilidade deixa de depender universalmente de modalidade e passa a seguir a **Ordem do atendimento**;
- modalidade única é aplicada automaticamente também a pré-agendamentos antigos que ainda estejam com `appointment_modality = indefinida`;
- consultas como **“quinta pela manhã, quais horários tem disponível?”** usam a Agenda interna do RS Connect e limitam a busca à próxima quinta no período da manhã, sem vazar sugestões de sexta/segunda como se fossem da preferência pedida;
- períodos `manhã`, `tarde` e `noite` são tratados como busca de opções, não como horário exato; somente `HH:MM` pode ser considerado preferência exata para seleção automática;
- a proteção final da IA deixa de perguntar modalidade em empresas onde ela não é exigida antes da agenda;
- invalida o cache exato com contrato `36.37.3`;
- não há migration nova; permanece obrigatória `120_agent_turn_state_cursor.sql`.

# 36.37.2 — Ordem do atendimento como fonte única da demanda

- corrige a duplicação da pergunta de **demanda/motivo do contato** causada pela convivência entre a Ordem do atendimento e o antigo bloco **Entender a demanda**;
- quando existe uma Ordem do atendimento executável, `brief_demand` passa a ser controlado exclusivamente pela etapa em que foi incluído: a camada `conversation_behavior` não antecipa, não reativa e não torna o campo obrigatório;
- o bloco **Demanda / motivo do contato** vira um status explicativo quando o workflow está ativo, removendo os dois checkboxes concorrentes. Empresas legadas sem workflow continuam com os controles antigos por compatibilidade;
- a pergunta de demanda passa a ser editada no próprio campo estruturado, e sua trava de agenda é derivada da posição da etapa em relação a `calendar.*`;
- regras por grupo de contato deixam de criar uma segunda exigência de demanda quando existe workflow; sem workflow, o comportamento legado permanece preservado;
- ao salvar a Ordem do atendimento, informações que não estejam ligadas a nenhuma etapa de **Coleta** ficam inativas no runtime, evitando perguntas “fantasmas” mesmo que o registro técnico continue disponível para reutilização;
- o prompt do agente recebe uma instrução explícita para não antecipar demanda, idade, modalidade ou qualquer outro campo fora da próxima etapa permitida;
- a agenda deixa de instruir o modelo a coletar modalidade universalmente e passa a respeitar a configuração de forma de atendimento;
- invalida o cache exato de respostas com contrato `36.37.2`, evitando reaproveitamento de respostas geradas pelo comportamento anterior;
- não há migration nova; permanece obrigatória `120_agent_turn_state_cursor.sql`.

# 36.37.1 — Exclusão segura de etapas da Ordem do atendimento

- adiciona o botão **Excluir etapa** em cada etapa do tipo **Coleta** nas telas de configuração do agente;
- pede confirmação antes da remoção e atualiza imediatamente a numeração visual das etapas restantes;
- envia uma lista explícita de etapas a excluir, evitando que simples ausência no formulário apague itens por acidente;
- impede no backend a exclusão de etapas técnicas (`Ação`, `Validação`, `Equipe` e `Conclusão`);
- quando uma etapa removida era o último vínculo de uma informação, o campo fica inativo e deixa de ser exigido pelo runtime, mas permanece cadastrado para poder ser adicionado novamente;
- ao remover o último vínculo de `brief_demand`, também desliga a compatibilidade antiga de demanda para impedir que a pergunta seja recriada silenciosamente;
- recompõe as posições do workflow após a exclusão e recalcula as travas antes da agenda;
- não há migration nova; permanece obrigatória `120_agent_turn_state_cursor.sql`.

# 36.37.0 — Motor genérico de atendimento por configuração

- transforma a **Ordem do atendimento** na fonte efetiva para a fronteira da agenda: campos vinculados a etapas de coleta antes de `calendar.*` passam a ser exigidos antes da consulta;
- permite editar, dentro de cada etapa de coleta, **quais informações pertencem à etapa**, inclusive a antiga “Triagem administrativa”;
- adiciona **Forma de atendimento** com três estratégias por empresa: `Não se aplica`, `Uma única forma` e `Mais de uma forma / cliente escolhe`;
- em modalidade única, o backend preenche automaticamente `online` ou `presencial` e não pergunta ao contato;
- quando modalidade não se aplica, a Agenda interna e o callback de disponibilidade passam a aceitar `indefinida` sem bloquear a consulta;
- o campo técnico `modality` é retirado do runtime de triagem quando a configuração já resolveu a modalidade, evitando repetição como a vista no WhatsApp;
- o template atual do n8n para eventos VAGO deixa de impor Online/Presencial universalmente e aceita negócios sem esse conceito;
- mantém compatibilidade com configurações antigas: duas modalidades ativas viram “cliente escolhe”; apenas uma ativa vira “modalidade única”;
- não há migration nova; permanece obrigatória `120_agent_turn_state_cursor.sql`.

# 36.36.28 — Consulta real de horários e confirmação sem falso positivo

- corrige o falso positivo em que mensagens como **“Sim, qual horário tem disponível?”** eram classificadas como confirmação de agendamento só por começarem com “sim” e conterem “horário”;
- a guarda de confirmação sem slot real passa a aceitar somente pedidos explícitos de confirmar/agendar, deixando respostas genéricas como “sim”, “ok” e perguntas de disponibilidade seguirem o fluxo correto;
- quando a modalidade já está definida e o lead pergunta quais horários estão disponíveis, o backend passa a consultar a agenda real mesmo sem exigir que ele invente previamente um horário específico;
- a busca ampla continua submetida às regras estruturadas do agente: por exemplo, se presencial é permitido apenas às segundas-feiras, somente slots reais de segunda-feira podem ser apresentados;
- respostas de consulta ampla deixam de dizer que “o horário solicitado não está disponível” quando nenhum horário específico foi solicitado;
- o contrato do cache exato foi atualizado para 36.36.28, evitando reaproveitar respostas antigas incompatíveis com o novo fluxo;
- não há migration nova; permanece obrigatória `120_agent_turn_state_cursor.sql`.

# 36.36.27 — Agenda factual e sem promessas duplicadas

- Faz a **Ordem do atendimento** iniciar a ação de agenda automaticamente quando a última etapa de coleta é concluída, sem deixar a IA inventar um encaminhamento para a profissional.
- Remove a mensagem de confirmação de preferência antes da consulta real de disponibilidade; o contato recebe somente a pergunta necessária ou o resultado técnico da agenda.
- Valida regras estruturadas de modalidade **antes** de consultar horários: se presencial estiver permitido somente em determinados dias, o agente informa essa regra em vez de dizer falsamente que o horário está ocupado/preenchido.
- Bloqueia respostas que prometem encaminhar para alguém verificar a agenda, avisar quando abrir encaixe ou atribuir causa não comprovada à indisponibilidade.
- Mensagens antigas/customizadas de indisponibilidade que afirmem “ocupado”, “preenchido”, “agenda cheia” ou promessa de lista de espera são neutralizadas em runtime por texto factual.
- Se até a segunda tentativa do LLM violar o contrato, o backend agora opera em **fail-closed** e envia uma resposta derivada somente do estado persistido de triagem/agenda.
- Invalida o cache exato do contrato para impedir reaproveitamento de respostas anteriores inseguras.
- Mantém a migration obrigatória `120_agent_turn_state_cursor.sql`; não há nova migration nesta versão.

# 36.36.26 — Motor de IA com contrato de resposta

- Refatora o runtime conversacional para separar estado/regras determinísticas da redação do LLM.
- Processa bursts de mensagens recebidas em ordem, uma a uma, preservando o cursor da triagem e evitando repetir nome, idade ou demanda já informados.
- Adiciona `last_processed_incoming_id` à sessão de triagem para impedir que mensagens do mesmo turno sejam reinterpretadas como respostas de etapas posteriores.
- Remove a reinserção literal da pergunta cadastrada nos modos **Natural com regras** e **Prompt Studio**; texto fixo permanece exclusivo do modo Formulário.
- Introduz um contrato obrigatório de resposta com prioridade explícita para regras/prompt cadastrados, estado da conversa e próxima etapa permitida.
- Valida a resposta gerada antes do envio e executa uma reescrita automática quando a IA ignora a próxima etapa ou inventa consulta humana/agenda.
- Trata perguntas como “consegue ajudar?” como pedidos informativos, sem simular confirmação com a profissional ou promessa de retorno inexistente.
- Invalida respostas antigas do cache exato por versão do contrato de runtime.
- Nova migration obrigatória: `120_agent_turn_state_cursor.sql`.

# 36.36.25 — Respostas naturais guiadas pelas regras

- corrige o modo **Natural com regras** para que perguntas obrigatórias da triagem deixem de ser enviadas como texto literal antes da IA; o backend continua escolhendo a etapa e bloqueando a agenda, enquanto o modelo redige a mensagem;
- o texto cadastrado em cada etapa passa a ser tratado como **objetivo de coleta** nos modos Natural e Prompt Studio, permitindo acolhimento, contexto e transições naturais sem perder a ordem configurada;
- o modo **Perguntas exatamente como cadastradas** permanece disponível de forma explícita para empresas que realmente desejam texto fixo;
- pedidos como “quero saber mais”, “me explique melhor” e “como funciona” são tratados como solicitações informativas mesmo sem ponto de interrogação e devem ser respondidos usando somente prompt, base de conhecimento e regras cadastradas;
- reforça o tom acolhedor: quando o turno trouxer relato emocionalmente relevante, a resposta deve reconhecer brevemente o contexto antes da próxima pergunta;
- remove do prompt central a regra específica “como funciona a terapia” e a substitui por uma regra genérica orientada pela configuração, evitando conteúdo de nicho no motor;
- mantém Policy Engine, ordem do atendimento, disponibilidade real, aprovação humana e migration obrigatória 119; não há migration nova.

# 36.36.24 — Tom configurável, humanização e Ordem do atendimento expansível

- adiciona **Tom do atendimento** por assistente, com opções simples como acolhedor e empático, cordial e profissional, objetivo, leve ou personalizado;
- o tom é salvo de forma estruturada no próprio agente e aplicado pelo runtime sem alterar regras, permissões ou conteúdo de negócio;
- no tom acolhedor, relatos sensíveis recebem um reconhecimento breve antes da próxima pergunta configurada, evitando respostas frias ou burocráticas;
- a **Ordem do atendimento** passa a permitir incluir uma informação já cadastrada ou criar uma nova pergunta personalizada sem editar código;
- novas perguntas personalizadas criam automaticamente o campo de triagem e a etapa executável antes da agenda, podendo depois ser reordenadas;
- frases condicionais como “se tiver vaga, prefiro online” registram apenas a modalidade e não são tratadas como preferência completa de agenda;
- enquanto existir uma etapa de coleta antes da ação de agenda, a camada final remove promessas prematuras de consulta/disponibilidade e garante a pergunta configurada da etapa pendente;
- preserva ordem configurada, Policy Engine, regras de agenda, retorno fora do horário, SLA opcional e migration obrigatória 119; não há migration nova.

# 36.36.23 — Ordem de atendimento por turno e isolamento da agenda

- corrige o cursor da triagem para que a **Ordem do atendimento** governe também turnos informativos, não apenas mensagens já classificadas como intenção de agenda;
- o Prompt da IA passa a receber o campo atual, o rótulo e a **pergunta configurada** para a próxima etapa, impedindo que o modelo escolha outra pergunta por conta própria;
- uma resposta como “Sim, estou passando por uma perda...” preenche somente a etapa em andamento e não é promovida automaticamente para uma etapa futura;
- confirmações e seleções da agenda deixam de reaproveitar pré-agendamentos de outra conversa apenas porque pertencem ao mesmo contato;
- a detecção de confirmação deixa de considerar qualquer frase iniciada por “sim” como confirmação de horário, exigindo sinal explícito de agenda quando houver texto adicional;
- preserva regras de agenda, retomada fora do horário, SLA, escopo estrito por turno e a migration obrigatória 119; não há migration nova.

# 36.36.22 — Escopo estrito por turno

- O modo **Responder só ao que foi perguntado e avançar 1 etapa** deixa de ser apenas uma instrução ao modelo e passa a ter proteção determinística antes da entrega.
- Valores e formas de pagamento configurados ficam ocultos do contexto operacional quando o cliente não perguntou por esse tema.
- Se o modelo ainda antecipar valor/pagamento, a camada de entrega remove essa informação antes do WhatsApp, preservando o restante da resposta.
- O modo estrito mantém no máximo uma pergunta de coleta por turno.
- Cache exato também passa pela mesma guarda, evitando reutilizar uma resposta antiga mais ampla do que a configuração atual permite.
- Nenhuma informação de negócio foi codificada no PHP: valor, métodos e mensagens continuam vindo da configuração da empresa/agente.
- Não há migration nova; permanece obrigatória a 119_agent_workflow_runtime_contract.sql.

# 36.36.21 — Ritmo conversacional e entrega em blocos

- A configuração de respostas agora define também o ritmo: responder apenas ao que foi perguntado e avançar uma única etapa por turno, ou permitir antecipação de informações relacionadas.
- O modo “Automático conforme o conteúdo” passa a separar respostas longas mesmo quando o provedor devolve um único parágrafo.
- Respostas determinísticas de triagem/políticas também respeitam a configuração de blocos, inclusive a apresentação inicial seguida da próxima pergunta.
- Nenhuma regra de agenda, SLA, fora do horário ou workflow foi removida; a migration obrigatória continua sendo a 119.

## 36.36.20 — 2026-09-24 — Runtime do agente orientado pela configuração

- remove o mapa hardcoded de `step_key -> campo` do runtime; cada etapa de coleta passa a usar os vínculos persistidos em `tenant_agent_workflow_steps.config_json`;
- adiciona a migration `119_agent_workflow_runtime_contract.sql`, que grava os vínculos das etapas existentes e as ações técnicas da agenda nos modelos e empresas já configurados;
- mantém a ordem cadastrada como ordem efetiva dos campos pendentes e valida conflitos como “campo obrigatório antes da agenda posicionado depois de Consultar agenda”;
- remove a inferência automática de demanda por lista de sintomas/palavras-chave no PHP; a demanda positiva só é registrada quando a etapa configurada de demanda está sendo respondida;
- adiciona um auditor de configuração no runtime e na tela de Assistentes; fluxo inconsistente falha de forma segura em vez de improvisar regras;
- mostra na tela a ordem efetiva e quais informações cada etapa realmente controla;
- preserva sem alteração os serviços de retomada fora do horário, cooldown UTC e deduplicação das versões 36.36.11/36.36.12;
- mantém Policy Engine, regras de agenda, modalidade e aprovação humana como travas determinísticas.

## 36.36.19 — 2026-09-24 — Pré-agendamento com registro consolidado

- Remove os cartões estreitos do bloco de contexto do pré-agendamento.
- Consolida origem, grupo, demanda, preferência, modalidade, responsável e mensagem do lead em um único registro legível.
- Usa os dados atuais da conversa/agenda no lugar dos valores históricos desatualizados da descrição original.
- Mantém o texto original apenas em um detalhe recolhido para auditoria.
- Ajuste exclusivamente visual/consulta: regras da IA, agenda, SLA e recuperação fora do horário não foram alteradas.

## 36.36.18 — 2026-09-24 — Pré-agendamento com contexto operacional

- reorganiza as informações do pré-agendamento na Agenda em um bloco estruturado, separando origem, grupo do contato, demanda e modalidade;
- deixa de exibir o texto técnico histórico como descrição principal, evitando mostrar “não informado” depois que preferência/modalidade já foram atualizadas;
- passa a carregar o grupo e a demanda atuais da conversa, com fallback para a triagem estruturada;
- destaca a mensagem que originou o pedido e mantém o registro original disponível em um detalhe recolhido para auditoria;
- melhora a responsividade do bloco em desktop, tablet e celular;
- não altera regras do agente, agenda, SLA, retomada pós-horário ou banco de dados; nenhuma migration nova.

## 36.36.17 — 2026-09-24 — Consistência das regras do agente e SLA Admin

- corrige a posição do toggle **Usar SLA operacional nesta empresa**, que havia sido inserido dentro do formulário de ativação/inativação da empresa; o toggle agora fica exclusivamente dentro do bloco SLA e salva pela rota `/companies/sla`;
- consolida a regra de demanda entre `conversation_behavior` e o campo técnico `brief_demand`, preservando a configuração mais restritiva existente na atualização e eliminando perda silenciosa de regra;
- após o primeiro salvamento, `brief_demand` passa a ser sincronizado a partir do bloco **Entender a demanda**, removendo dois controles concorrentes para a mesma exigência;
- preserva a ordem cadastrada no workflow depois das sobreposições operacionais; a regra de demanda não pode mais reordenar as etapas pelo `position` antigo;
- mantém os gates determinísticos já existentes de agenda (triagem, elegibilidade, modalidade, disponibilidade e aprovação humana);
- preserva integralmente a retomada pós-horário e as correções UTC/deduplicação das versões 36.36.11/36.36.12;
- nenhuma migration nova.

## 36.36.16 — 2026-09-24 — Regras do agente como fonte de verdade

- torna `conversation_behavior[demand]` a fonte canônica em tempo de execução para **Exigir a demanda antes de consultar a agenda**, mesmo quando `tenant_triage_fields` estiver desatualizado;
- revalida demanda e regras de grupo em **toda** tentativa de pré-agendamento, inclusive quando já existe um pré-agendamento em andamento;
- impede respostas de outros campos (`online`, `sim`, idade, dia/horário ou pergunta de preço) de serem gravadas acidentalmente como demanda;
- remove a desativação silenciosa da regra de demanda no contexto de cliente/paciente quando a configuração global exigir a coleta;
- em turnos mistos no modo híbrido (ex.: “como funciona?”, “qual o valor?” + pedido de horário), permite que a IA responda primeiro às perguntas usando as informações configuradas e depois faça somente a próxima pergunta obrigatória;
- não altera SLA, horário comercial, apresentação, disponibilidade real da agenda ou migrations.

## 36.36.15 — 2026-09-24 — SLA operacional opcional por empresa

- adiciona no RS Admin a opção **Usar SLA operacional nesta empresa**;
- quando desligado, a empresa continua operando normalmente, mas sem relógio de SLA, alerta preventivo ou marcação de violação nas conversas e na carga operacional;
- preserva meta, percentual de alerta e regra de expediente para uma futura reativação;
- sincronizações de horário e salvamentos do onboarding não reativam o SLA silenciosamente;
- relatórios continuam medindo tempos de primeira resposta, mas deixam de classificar novos resultados como cumprimento/violação de SLA enquanto a política estiver desativada;
- reutiliza a coluna `enabled` já existente em `tenant_sla_settings`; sem migration nova.

## 36.36.14 — 2026-09-24 — Demanda obrigatória antes da agenda

- Corrige a precedência da opção estruturada **Exigir a demanda antes de consultar a agenda**.
- A exigência passa a valer mesmo quando o contato já estiver classificado como cliente/paciente, desde que ainda não exista demanda registrada nesta conversa.
- Remove o preenchimento artificial de `brief_demand` que podia liberar a agenda sem pergunta real.
- Reabre estados antigos `not_required` criados automaticamente quando uma nova solicitação de agenda chega com a exigência ativa.
- Marcar **Exigir a demanda** passa a ativar a coleta de demanda automaticamente, evitando configuração contraditória.
- Mantém continuidade: dados já conhecidos não são repetidos; somente a demanda pendente é solicitada antes da agenda.
- Sem migration nova.

## 36.36.13 — 2026-09-24 — SLA por empresa no RS Admin

- adiciona um bloco **SLA operacional** na visão geral de cada empresa dentro do RS Admin;
- permite ao Super Admin alterar a meta da primeira resposta humana, o percentual de alerta preventivo e a contagem fora do expediente;
- exibe a prévia da meta, minuto de alerta, modo do relógio, expediente e fuso usados pela política;
- reaproveita `SlaPolicyService` e `tenant_sla_settings`, sem criar configuração paralela nem nova migration;
- registra auditoria `company.sla_updated` e mantém snapshot histórico dos ciclos já abertos;
- não altera IA, agenda, triagem, recuperação pós-horário ou regras de atendimento.

## 36.36.12 — 2026-09-24 — Retomada com apresentação única e sem duplicidade

- O aviso de ausência fora do horário deixa de contar como primeira resposta conversacional para a política de apresentação do assistente.
- Na retomada após a abertura, respostas determinísticas e respostas do provedor de IA voltam a aplicar a apresentação configurada quando ainda não houve conversa ativa anterior.
- Filtra os eventos terminais da recuperação pós-horário para que logs auxiliares de memória/integração não provoquem uma segunda tentativa da mesma demanda.
- Adiciona proteção final contra envio da mesma resposta duas vezes quando monitor pós-horário e reprocessamento concorrem sem nova mensagem do cliente.
- Nenhuma migration nova.

## 36.36.11 — 2026-09-24 — Retomada pós-horário no fuso correto

- Corrige a leitura de `conversation_messages.sent_at`: timestamps técnicos são UTC e não podem ser reinterpretados como horário local pelo cooldown da IA.
- Corrige o monitor pós-horário para interpretar `last_run_at` em UTC; isso evita a rotina ser adiada por horas após a abertura do expediente.
- Corrige expiração/fallback da fila pós-horário para usar o mesmo contrato UTC do banco.
- Mantém o tempo de silêncio configurado: mensagem recebida segundos antes da abertura ainda aguarda apenas o saldo real do cooldown.
- Nenhuma migration nova.

## 36.36.10 — 2026-09-24 — Apresentação única do assistente

- Corrige a composição da primeira resposta determinística quando o campo `name` do agente contém também a função, por exemplo `Rafa, Assistente da psicóloga Mariana Bernardes`.
- Reconhece `Aqui é a Rafa`, `Eu sou Rafa` e formas como `Rafa, assistente...` como identificação já existente; não injeta uma segunda apresentação.
- Usa apenas a parte nominal do cadastro na apresentação automática de fallback, evitando frases como `Eu sou Rafa, Assistente..., assistente virtual`.
- Mantém a proteção contra falso positivo de nomes parecidos, como `Rafa` e `Rafaela`.
- Nenhuma migration nova.

## 36.36.9 — 2026-09-23 — Hotfix de abertura, triagem e agenda

- Primeira resposta determinística identifica a atendente segundo a política de saudação.
- Demanda informada em resposta curta durante a triagem é sincronizada com a regra de grupo; pergunta genérica sobre funcionamento da terapia não é presumida como queixa.
- Preferências de dia/período/modalidade em triagem ativa podem retomar a agenda sem contaminar perguntas comuns posteriores.
- Agenda interna e seleção automática distinguem **período** de **horário exato** para não inventar uma reserva das 14h.
- Cenários novos testados em `tests/Feature/triage-agenda-two-scenarios-regression-smoke.php` e documentados em `docs/HOTFIX-v36.36.9-DOIS-CENARIOS.md`.
- Nenhuma migration nova.

## 36.36.7-r2 — Triagem de idade e identificação inicial

- corrige a coleta da idade para aceitar respostas numéricas curtas quando esse é o campo atual da triagem;
- trata faixas aproximadas de idade com uma clarificação específica, evitando loop de perguntas repetidas;
- garante que a primeira resposta automática se identifique pelo nome público configurado do assistente quando a saudação está ativa;
- aplica a mesma identificação às saudações locais e protege contra falso positivo quando o nome do contato é parecido com o do assistente;
- não adiciona migration nova.

## 36.36.7 — Identidade visual Mobile 0.5.0

- publica os assets da interface mobile em `public/mobile-app/`, separados do shell Android;
- padroniza os ícones dos atalhos e do menu inferior com SVGs leves;
- adiciona tela de carregamento com a marca **RS Connect** e o texto **Atendimento inteligente**;
- atualiza a identidade do login e prepara o app para evolução visual sem replicar a interface desktop;
- não altera API, regras de negócio nem banco de dados; permanece obrigatória a migration `118_contact_origin.sql` da 36.36.6.

## 36.36.1 — Hotfix do Production Readiness

- corrige o escopo do preflight de Evolution/WhatsApp: apenas instâncias receptoras pertencentes a tenants `LIVE` podem bloquear a release;
- instâncias em `ONBOARDING`, `READY` ou `SUSPENDED` permanecem visíveis como `INFO`, inclusive quando desconectadas ou ainda sem identidade verificada;
- corrige a carga operacional do preflight para contabilizar como produtivas somente conversas de tenants `LIVE`;
- conversas abertas de tenants não LIVE passam a ser exibidas separadamente como informação;
- preserva todos os critérios de Go-Live, SLA, webhooks, observabilidade e a migration obrigatória `116_sla_trigger_mysql_compat.sql`;
- não há migration nova; o manifesto permanece com **123 migrations**.

## 36.36.0 — Production Readiness: Homologação final

- consolida as Fases A–D em uma release candidate sem ampliar o escopo funcional;
- adiciona `bin/production-readiness.php`, um preflight operacional que valida readiness da aplicação, migration obrigatória, Go-Live/SLA, Evolution/identidade/reconciliação, webhooks recentes, carga ativa e observabilidade;
- classifica o ambiente em **PRONTO**, **PRONTO COM ATENÇÃO** ou **BLOQUEADO**, sem alterar dados;
- adiciona roteiro final ponta a ponta em `docs/HOMOLOGACAO-FINAL-v36.36.0.md`;
- preserva a operação sem filas obrigatórias: carga por responsável continua sendo a visão principal de supervisão;
- não há migration nova; permanece `116_sla_trigger_mysql_compat.sql` como requisito de banco;
- a Fase E é de validação, evidência e estabilidade: não adiciona novos módulos comerciais.

## 36.35.0 — Production Readiness: Carga operacional

- adiciona a tela **Carga operacional** para supervisão do atendimento sem exigir o módulo de filas;
- consolida conversas abertas e pendentes por responsável, incluindo **Sem responsável**;
- exibe indicadores de total ativo, atendimento humano, aguardando primeira resposta, SLA em risco e SLA violado;
- reutiliza a mesma `SlaPolicyService` da caixa de entrada e dos relatórios para evitar divergência de cálculo;
- mostra apenas risco/violação de SLA ainda pendente de primeira resposta humana, evitando tratar violações históricas já respondidas como incidente atual;
- adiciona filtros por conexão, responsável, status, modo e estado de SLA;
- permite abrir a conversa diretamente a partir da visão de supervisão;
- atualiza automaticamente a tela quando a carga muda, com verificação leve a cada 30 segundos;
- não ativa round-robin, setores ou distribuição automática e não depende do módulo **Fila e setores**;
- não há migration nova; permanece `116_sla_trigger_mysql_compat.sql` como requisito de banco.

# Changelog — RS Connect

## 36.34.4 — Hotfix de autoridade do horário na IA

### Corrigido
- o estado calculado por `AgentOperatingPolicyService` é propagado até o prompt do provedor como fonte de verdade operacional;
- quando o expediente atual está aberto, mensagens históricas de ausência continuam persistidas no banco, mas são removidas do contexto enviado ao LLM;
- o prompt informa explicitamente que mensagens antigas dizendo “fora do horário” são históricas e não representam o estado atual;
- o cache exato deixa de reaproveitar respostas de ausência gravadas durante um período fechado quando a empresa já está aberta;
- uma defesa final bloqueia qualquer resposta gerada que ainda afirme falsamente que a empresa está fora do horário enquanto a política atual estiver `inside_business_hours`;
- o bloqueio é registrado como `ai.operating_policy.blocked` para diagnóstico, sem enviar informação operacional incorreta ao contato.

### Causa confirmada em homologação
- a mensagem das 15:59 foi gerada corretamente pelo antigo caminho `ai.after_hours` antes do hotfix 36.34.3;
- depois da correção do parser, às 16:18 e 16:20 a política já estava `inside_business_hours`, porém o provedor OpenAI repetiu a frase antiga porque ela ainda fazia parte do histórico enviado;
- os logs `ai.replied` confirmaram que esses dois envios vieram do LLM, não da política de horário.

### Banco de dados
- **nenhuma migration nova**;
- permanece obrigatória `116_sla_trigger_mysql_compat.sql`;
- manifesto permanece com **123 migrations de subida**.

## 36.34.3 — Hotfix do horário de atendimento

### Corrigido
- a política operacional do agente passa a aceitar tanto o formato compacto salvo pelo onboarding (`days/start/end`) quanto o formato detalhado por dia usado na tela do agente;
- segunda a sexta em `08:00–18:00` deixam de ser interpretadas incorretamente como `day_closed`;
- a mensagem de fora do horário não é mais disparada dentro do expediente por incompatibilidade de formato;
- `nextOpeningAt()` usa a mesma normalização, preservando a retomada automática pós-horário;
- configurações antigas e novas permanecem compatíveis, sem conversão destrutiva do JSON salvo.

### Banco de dados
- **nenhuma migration nova**;
- permanece obrigatória `116_sla_trigger_mysql_compat.sql`;
- manifesto esperado: **123 migrations de subida**.

### Homologação
- com `America/Sao_Paulo`, Seg–Sex e `08:00–18:00`, uma mensagem na segunda às 15:59 deve ser considerada dentro do expediente;
- uma mensagem após 18:00 deve continuar usando a resposta fora do horário;
- depois retomar os cenários de SLA da Fase C com uma conversa nova.

## 36.34.2 — Hotfix do recebimento Evolution e trigger de SLA

### Corrigido
- corrige o trigger `trg_rs_messages_after_insert_metrics` criado na migration 115, que executava `UPDATE ... LEFT JOIN ... ORDER BY ... LIMIT` e causava `SQLSTATE[HY000] / 1221 Incorrect usage of UPDATE and ORDER BY` no MySQL;
- mensagens `MESSAGES_UPSERT` voltam a ser persistidas em `conversation_messages`;
- o snapshot de SLA continua sendo atualizado no ciclo ativo mais recente, mas agora o ciclo é selecionado primeiro e atualizado por `id`, sem `ORDER BY/LIMIT` no `UPDATE`;
- a primeira resposta humana continua sendo registrada no ciclo ativo sem alterar a semântica da Fase C;
- os retries da Evolution deixam de retornar HTTP 500 por causa do trigger de SLA.

### Banco de dados
- nova migration `116_sla_trigger_mysql_compat.sql`;
- manifesto esperado: **123 migrations de subida**;
- a migration é corretiva e apenas recria o trigger de métricas/SLA; não altera nem apaga mensagens existentes.

### Homologação
- confirmar que `MESSAGES_UPSERT` novo retorna HTTP 200;
- confirmar que a nova mensagem aparece em `conversation_messages`;
- confirmar que `conversation_service_cycles.first_incoming_at` e o snapshot de SLA continuam sendo atualizados;
- depois retomar os cenários A–E da Fase C.

## 36.34.1 — Hotfix do salvamento das regras de atendimento

### Corrigido
- o onboarding/implantação deixa de tentar gravar `handoff_action = "pause_ai"` em `ai_agents`;
- o valor aplicado automaticamente agora é `paused`, que é compatível com o ENUM existente (`paused`, `human`);
- salvar horário de atendimento, dias, fuso e política de SLA volta a funcionar sem `Warning 1265 Data truncated for column handoff_action`;
- a semântica permanece a mesma: ao ocorrer handoff configurado pelo onboarding, a IA fica pausada para continuidade humana.

### Banco de dados
- **nenhuma migration nova**;
- permanece obrigatória `115_sla_operational_policy.sql`;
- manifesto permanece com **122 migrations de subida**.

### Homologação
- repetir o salvamento das Regras de atendimento;
- validar que o formulário salva e que `ai_agents.handoff_action` permanece `paused` ou `human`;
- depois continuar os testes da Fase C normalmente.

## 36.34.0 — Production Readiness: SLA operacional

### Adicionado
- política persistente em `tenant_sla_settings` para meta de primeira resposta humana, limiar preventivo, fuso e regra de contagem fora do expediente;
- configuração na etapa de regras de atendimento do onboarding/implantação;
- snapshot da política nos ciclos de atendimento para evitar que uma alteração futura reescreva o SLA histórico;
- alerta visual **SLA em risco** ao atingir o percentual preventivo e **SLA violado** ao atingir a meta;
- contador de conversas em risco na caixa de entrada e aviso em tempo real quando uma conversa muda de faixa;
- relógio de SLA compatível com os dias/horários de atendimento da empresa.

### Consistência operacional
- somente `sender_type = user` / resposta humana atribuída encerra o SLA; resposta de IA não mascara o tempo da equipe;
- empresas fora de `LIVE` não exibem alertas produtivos de SLA;
- relatório executivo, relatório de equipe, auditoria de primeira resposta e espera atual usam o mesmo relógio de expediente;
- o filtro manual de meta nos relatórios continua disponível para simulação, sem alterar a política persistida.

### Migration
- `115_sla_operational_policy.sql`;
- manifesto esperado: **122 migrations de subida**.

### Homologação
Consulte `TESTE_DA_VERSAO.md`. A Fase C só deve ser aprovada após validar resposta normal, alerta em 80%, violação em 100%, IA sem encerrar o SLA e pausa fora do expediente.

## 36.33.0 — Production Readiness: Evolution Reliability

### Adicionado
- reconciliação explícita entre o estado salvo no RS Connect e o estado observado diretamente na Evolution API;
- ação **Reconciliar agora** em Canais WhatsApp, separada de **Reaplicar webhook** e **Recuperar conexão**;
- painel por conexão com **Estado no RS Connect**, **Estado observado na Evolution**, última reconciliação e último webhook;
- histórico auditável em `evolution_reconciliation_runs`, incluindo estado local anterior, estado remoto, correção aplicada e erro;
- reconciliação periódica pelo Monitor Operacional antes da recuperação automática;
- contagem de falhas consecutivas de reconciliação e estado `unreachable` quando a Evolution não pode ser consultada;
- trava de identidade preservada: uma conexão com número divergente nunca é marcada como saudável apenas porque a Evolution respondeu `open`.

### Idempotência
- a proteção transacional existente de `webhook_security_events` continua sendo a primeira barreira contra reprocessamento de webhooks duplicados;
- `conversation_messages.evolution_message_id` continua como segunda barreira para mensagens duplicadas;
- o `event_id` da Evolution passa a ser namespaced por evento + instância, evitando colisão entre canais diferentes;
- eventos em processamento só podem ser retomados quando falham ou ficam obsoletos, evitando resposta/IA duplicadas em concorrência normal.

### Migration
- `114_evolution_reconciliation_observability.sql`;
- manifesto esperado: **121 migrations de subida**.

### Homologação
Consulte `TESTE_DA_VERSAO.md`. A Fase B deve ser aprovada somente depois de validar estado saudável, divergência local, indisponibilidade da Evolution, recuperação e webhook duplicado.

## 36.32.2 — Hotfix do cálculo de SLA

### Corrigido
- o cálculo de SLA deixa de reutilizar o mesmo placeholder nomeado em PDO MySQL nativo (`ATTR_EMULATE_PREPARES=false`);
- os limites `dentro da meta` e `fora da meta` agora usam `:sla_met_seconds` e `:sla_breached_seconds`, eliminando o erro `HY093` que fazia o card cair silenciosamente para `0/0`;
- duração de ciclo, SLA e espera atual passam a ser consultados em blocos isolados: uma falha em um indicador não zera os demais;
- logs de relatório distinguem `service-cycle.closed`, `service-cycle.sla` e `service-cycle.waiting`, facilitando diagnóstico em produção.

### Banco de dados
- **nenhuma migration nova**;
- permanece obrigatória `113_human_first_response_report_consistency.sql`;
- manifesto permanece com **120 migrations de subida**.

## 36.32.1 — Hotfix de relatório e SLA humano

### Corrigido
- filtros do relatório executivo do cliente agora convertem `00:00–23:59` do fuso da empresa para UTC antes das consultas;
- mensagens do fim do dia local (por exemplo, 11/09 às 23h em `America/Sao_Paulo`) permanecem no dia 11 para o usuário, embora sejam persistidas em 12/09 UTC;
- séries diária, horária e heatmap de mensagens são reagrupados no fuso da empresa;
- período comparativo usa o mesmo contrato de fuso do período principal;
- **Tempo médio da 1ª resposta humana** e **SLA da 1ª resposta humana** passam a exigir a mesma evidência: `first_response_at` + `first_response_user_id`;
- ciclos com resposta humana real, mas atribuição incompleta, são reparados pela migration 113;
- adicionado trigger `AFTER UPDATE` para cobrir a corrida em que o webhook da Evolution grava o eco como `system` antes do painel atualizar a mesma mensagem para `user`.

### Estabilização
- o relatório executivo do tenant deixa de consumir temporariamente `report_daily_metrics` v2, pois esse cache materializa dias em UTC; os cards usam as tabelas operacionais até a camada agregada ganhar contrato de dia local;
- a política de Go-Live da 36.32.0 permanece inalterada.

### Migration
- `113_human_first_response_report_consistency.sql`.

## 36.32.0 — Production Readiness: Go-Live

### Adicionado
- ciclo operacional por empresa: `onboarding`, `ready`, `live` e `suspended`;
- histórico auditável de transições em `tenant_lifecycle_events`;
- ação explícita de **Colocar em produção** no Superadmin;
- datas de prontidão, primeiro Go-Live e suspensão;
- aviso visual para clientes enquanto o ambiente não está em produção;
- filtro por ciclo operacional na listagem de empresas;
- bloqueio de cobrança manual de produção antes do Go-Live;
- SLA e métricas executivas de primeira resposta/duração passam a considerar somente períodos em que a empresa estava `live`;
- MRR operacional do painel RS considera empresas em produção;
- roteiro de teste e rollback incluídos no pacote.

### Compatibilidade
- status de acesso (`tenants.status`) continua independente do ciclo operacional;
- assinatura comercial e trial não são cancelados pelo ciclo operacional;
- WhatsApp, IA, agenda e atendimento continuam utilizáveis em onboarding/ready para homologação;
- saudação inteligente da 36.31.2/36.31.3 permanece ativa.

### Migration
- `112_tenant_lifecycle_go_live.sql`.

### Atenção na atualização
A migration coloca empresas existentes em `onboarding` por padrão. Isso é intencional: o primeiro Go-Live após esta atualização deve ser confirmado conscientemente no Superadmin. O histórico anterior não é classificado retroativamente como produção oficial.

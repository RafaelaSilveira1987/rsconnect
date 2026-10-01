# RS Connect 36.41.5
> **36.41.5** restaura o horário de atendimento como autoridade global do fluxo conversacional. Fora do expediente, qualquer mensagem — inclusive consulta, confirmação, cancelamento ou remarcação de um compromisso já existente — entra na fila pós-horário, recebe somente o aviso configurado (uma vez por dia local) e é processada após a reabertura. Não há migration nova; permanece necessária `123_published_slots_min_notice_policy.sql`.

## Homologação rápida 36.41.5

Configure o dia atual para abrir depois do horário do teste (por exemplo, 09:00) e envie antes da abertura: “quero reagendar minha consulta de amanhã”, “consigo ir mais cedo” e “pode ser às 10h”. A conversa deve permanecer em **Fora do horário/Aguardando horário**, enviar no máximo um aviso de ausência e manter todas as mensagens na mesma pendência. Após a abertura e a execução da recuperação pós-horário, a demanda deve ser retomada pela camada determinística da Agenda.

# RS Connect 36.41.4
> **36.41.4** mantém as informações estruturadas do pré-agendamento visíveis depois da confirmação e elimina do compromisso a exibição do texto técnico/original. Em atendimentos **Online**, o link da consulta pode ser informado antes de confirmar — entrando na mensagem imediata — e também editado depois no detalhe do compromisso. Não há migration nova; permanece necessária `123_published_slots_min_notice_policy.sql`.

## Homologação rápida 36.41.4

Confirme um pré-agendamento Online com um link de Meet/Zoom/Teams. No calendário, abra o compromisso confirmado: o modal deve mostrar os mesmos dados estruturados do pré-agendamento (origem, modalidade, preferência, demanda e campos configurados), sem o bloco técnico “Preferência recebida.../Mensagem do lead”. O bloco **Link da consulta** deve permitir abrir ou alterar o endereço. A variável `{{link_consulta}}` também fica disponível nas mensagens automáticas.

# RS Connect 36.41.3
> **36.41.3** corrige a confirmação do pré-agendamento quando uma busca posterior sobrescreve apenas o estado visual da disponibilidade. A vaga escolhida passa a ser preservada, revalidada no momento da aprovação e só é liberada por ação explícita. A busca manual não substitui mais silenciosamente um horário já escolhido. Não há migration nova; permanece necessária `123_published_slots_min_notice_policy.sql`.

## Homologação rápida 36.41.3

Com um pré-agendamento que já mostre **Horário escolhido**, clique diretamente em **Confirmar agendamento**. O backend revalida/reaplica a vaga escolhida e confirma o compromisso. Para procurar outro horário, use **Liberar horário** primeiro; somente depois a ação **Buscar disponibilidade** volta a aparecer.

# RS Connect 36.41.2
> **36.41.2** conclui a validação operacional do pré-agendamento: depois que um horário é escolhido, a própria aba **Pré-agendamentos** exibe a ação **Confirmar agendamento**, que usa o fluxo real de aprovação, confirma a vaga, converte o pré-agendamento e dispara a comunicação configurada. Não há migration nova; permanece necessária `123_published_slots_min_notice_policy.sql`.

# RS Connect 36.41.0
> **36.41.0** mantém as correções de triagem e Agenda anteriores e organiza a comunicação automática em confirmação, lembrete e confirmação de presença. Os tempos podem ser configurados em minutos, horas ou dias, com proteção contra disparos duplicados no mesmo instante e contra lembretes incompatíveis com uma resposta posterior do cliente. Não há migration nova; permanece necessária `123_published_slots_min_notice_policy.sql`.

## Homologação recomendada

Para um contato de teste que já acumulou histórico antigo, primeiro faça a prévia com `php bin/reset-test-conversation.php --tenant=ID_OU_SLUG --contact=Tester --agent=Rafa`. Se os registros exibidos forem os corretos, aplique `--apply --purge-messages`. Depois teste um burst como: nome da pessoa, idade e um relato/objetivo enviados em balões seguidos; o sistema deve preservar os três dados, continuar perguntando somente a etapa ainda pendente e nunca recuperar o nome de um beneficiário de outro ciclo.

# RS Connect 36.40.0
> **36.40.0** normaliza o estado da Agenda: dia, período/horário e forma de atendimento são interpretados por uma única camada genérica. A configuração da empresa continua sendo a autoridade, e correções como “quero trocar a modalidade” ou “na verdade prefiro presencial às 14h” invalidam opções antigas antes de uma nova consulta. Não há migration nova; permanece `122_calendar_client_communications.sql`.

## Homologação recomendada

Teste pelo menos três perfis diferentes: um negócio sem modalidade, um com modalidade única e outro com escolha Online/Presencial. Para a Agenda publicada, valide também “tem vaga quinta pela manhã?”, troca de modalidade depois de opções já exibidas, “trocar de presencial para online” e uma resposta numérica de idade para confirmar que ela não vira horário. A busca é estrita ao dia solicitado: horários publicados na quarta não devem aparecer quando o contato pediu quinta.

# RS Connect 36.39.2
> **36.39.2** corrige um filtro residual da Agenda publicada que podia esconder horários liberados quando o pré-agendamento carregava responsável/slot de uma tentativa anterior. A descoberta de vagas da IA passa a consultar a disponibilidade publicada sem ficar presa ao owner automático, e qualquer nova preferência limpa a seleção técnica anterior antes da nova consulta.

## Atualização 36.39.2

- Horários publicados podem ser descobertos independentemente do responsável automático da conversa.
- Alterar dia/período/horário libera uma pré-reserva anterior e limpa `chosen_availability_slot_id`.
- A vaga publicada escolhida continua definindo o profissional de forma atômica.
- A ativação da estratégia publicada usa UPSERT e funciona em bases antigas.
- Slots publicados em versões anteriores são usados como compatibilidade quando a busca calculada retornaria vazia.
- Sem migration nova: permanece `122_calendar_client_communications.sql`.


## Atualização 36.39.1

Não há migration nova. Permanece obrigatória `122_calendar_client_communications.sql`.

Depois do deploy, horários publicados em **Agenda → Disponibilidades** passam a ser a fonte ativa do agente assim que forem liberados. Uma pergunta como “na quarta-feira tem algum horário?” pesquisa o dia inteiro e não reutiliza silenciosamente uma tentativa anterior, como 17h.

# RS Connect 36.39.0
> **36.39.0** fecha a continuidade operacional da Agenda: antes de iniciar uma nova triagem, o RS Connect identifica perguntas sobre compromissos já existentes e responde a partir do registro real. A Agenda também ganha confirmação automática, lembretes, pedido de presença e mensagens configuráveis por empresa, preservando status do compromisso e confirmação do cliente como estados separados.

## Atualização 36.39.0

Execute a migration obrigatória:

```bash
php bin/migrate.php verify
php bin/migrate.php up
php bin/migrate.php verify
```

Migration desta versão: `122_calendar_client_communications.sql`.

Depois do deploy, configure em **Agenda → Configurações → Comunicação e confirmação do agendamento** se a empresa deve responder consultas de compromisso fora do expediente, quais eventos disparam mensagens, quando enviar lembretes e quando solicitar confirmação de presença. O cron já utilizado para notificações passa a processar também a fila de mensagens da Agenda.

# RS Connect 36.38.2
> **36.38.2** simplifica a Agenda para uma única navegação proporcional. Compromissos, Visão geral, Disponibilidades, Pré-agendamentos e Configurações ficam na mesma barra, sem menu superior e menu interno concorrendo pelo mesmo espaço. A lógica de disponibilidade publicada da 36.38.0/36.38.1 permanece inalterada.

# RS Connect 36.37.5

## Correção 36.37.5

Campos personalizados da Ordem do atendimento não tratam mais dúvidas informativas do contato como resposta automática. Assim, uma mensagem como “qual o valor da consulta?” pode ser respondida sem preencher indevidamente um campo como “Demanda”. Para casos em que a pergunta do contato é o dado desejado, o campo pode habilitar explicitamente essa aceitação.


> **Fluxo contínuo + Agenda interna sem beco sem saída:** conversas em andamento passam a respeitar imediatamente a ordem atual das etapas, a última coleta retoma a consulta de agenda e a Agenda interna pode pesquisar horários compartilhados mesmo antes de um profissional ser definido. O card de pré-agendamento foi refeito para permanecer legível e agora mostra também campos personalizados da Ordem do atendimento sem regras específicas por nicho. Não há migration nova; permanece `120_agent_turn_state_cursor.sql`.

# RS Connect 36.37.3

> **Agenda interna guiada pela configuração:** a consulta de horários passa a respeitar integralmente a Ordem do atendimento e a política de forma de atendimento. Negócios sem modalidade não são bloqueados; modalidade única é aplicada automaticamente; períodos como “quinta pela manhã” pesquisam somente a faixa solicitada na Agenda interna e retornam opções reais sem transformar “manhã” em um horário confirmado. Não há migration nova; permanece `120_agent_turn_state_cursor.sql`.

# RS Connect 36.37.2

> **Triagem sem dupla autoridade:** quando existe uma **Ordem do atendimento**, demanda/motivo, idade, modalidade e demais informações são conduzidos somente pelo workflow. O antigo bloco “Entender a demanda” deixa de criar uma segunda pergunta e passa a mostrar o estado do roteiro. Empresas sem workflow continuam com a compatibilidade legada. Não há migration nova; permanece `120_agent_turn_state_cursor.sql`.

# RS Connect 36.37.1

> **Ordem do atendimento editável até o fim:** etapas de **Coleta** agora podem ser excluídas pela própria tela. A exclusão é confirmada antes de salvar, remove a etapa do workflow, desativa informações que ficarem sem outro vínculo e preserva esses campos para futura reutilização. Etapas técnicas de ação/validação continuam protegidas. Não há migration nova; permanece `120_agent_turn_state_cursor.sql`.

# RS Connect 36.37.0

> **Hotfix da agenda conversacional:** perguntas como “Sim, qual horário tem disponível?” não são mais tratadas como confirmação. Com a modalidade já definida, o RS Connect consulta a agenda real e apresenta somente horários que respeitam as regras cadastradas do agente. Não há migration nova; permanece `120_agent_turn_state_cursor.sql`.

# RS Connect 36.36.3

> **Hotfix do Production Readiness:** o preflight passa a tratar somente tenants `LIVE` como escopo bloqueante de Evolution/WhatsApp e de carga operacional produtiva. Ambientes `ONBOARDING`, `READY` e `SUSPENDED` continuam visíveis como informação, mas não bloqueiam a release. Não há migration nova.

## Atualização rápida

```bash
php bin/migrate.php verify
php bin/migrate.php up
php bin/migrate.php verify
php bin/production-readiness.php
```

Esperado no ambiente homologado: **1 instância receptora LIVE, 0 pendentes** e resultado **PRONTO PARA HOMOLOGAÇÃO FINAL**. Em EasyPanel, reinicie/rebuild o serviço pela interface quando o container não tiver o comando `docker`.

Depois siga `docs/HOMOLOGACAO-FINAL-v36.36.1.md`.

# RS Connect 36.36.0

> **Fase E — Homologação final / Release Candidate:** consolida Go-Live, Evolution Reliability, SLA operacional e Carga Operacional já homologados nas Fases A–D. Não há nova migration nem novo módulo de negócio.

## Atualização rápida

```bash
php bin/migrate.php verify
php bin/migrate.php up
php bin/migrate.php verify
docker compose restart app
php bin/production-readiness.php
```

O preflight final deve terminar como **PRONTO PARA HOMOLOGAÇÃO FINAL** ou **PRONTO COM ATENÇÃO**, nunca como **BLOQUEADO**. Depois execute `docs/HOMOLOGACAO-FINAL-v36.36.0.md`.

# RS Connect 36.35.0

> **Fase D — Carga operacional:** adiciona uma visão de supervisão baseada no fluxo já usado pelo cliente, sem exigir filas ou distribuição automática. A tela consolida responsável, conversas ativas e SLA em risco/violado, mantendo a mesma política de SLA da caixa de entrada e dos relatórios. Não há migration nova; permanece `116_sla_trigger_mysql_compat.sql`.

## Atualização rápida

```bash
php bin/migrate.php verify
php bin/migrate.php up
php bin/migrate.php verify
docker compose restart app
```

Depois, acesse **Relacionamento → Carga operacional**.

# RS Connect 36.34.4

## Hotfix de autoridade do horário na IA

O estado atual do expediente passa a ser uma **fonte de verdade determinística** também para o contexto enviado ao provedor de IA. Quando o RS Connect confirma que a empresa está dentro do horário, mensagens antigas de ausência permanecem no histórico/auditoria, mas deixam de ser usadas como contexto capaz de induzir a IA a repetir “Estamos fora do horário”. O cache exato também descarta respostas antigas incompatíveis e existe uma trava final antes do envio.

- nenhuma migration nova;
- permanece obrigatória `116_sla_trigger_mysql_compat.sql`;
- manifesto esperado: **123 migrations de subida**;
- atualização: substituir os arquivos e reiniciar o app.

```bash
php bin/migrate.php verify
php bin/migrate.php up
php bin/migrate.php verify
docker compose restart app
```

# RS Connect 36.34.3

## Hotfix do horário de atendimento

A política operacional do agente agora entende o mesmo formato compacto de expediente salvo pelo onboarding (`days/start/end`) e mantém compatibilidade com o formato detalhado por dia. Não há migration nova; permanece obrigatória `116_sla_trigger_mysql_compat.sql`.


> **Hotfix 36.34.2:** corrige o trigger de SLA da migration 115 que fazia o MySQL rejeitar `MESSAGES_UPSERT` com `SQLSTATE[HY000]: 1221 Incorrect usage of UPDATE and ORDER BY`. A correção está na migration `116_sla_trigger_mysql_compat.sql` e restaura o recebimento de mensagens sem remover a política de SLA.

## Atualização rápida

```bash
php bin/migrate.php verify
php bin/migrate.php up
php bin/migrate.php verify
docker compose restart app
```

Esperado: **123 migrations de subida** e execução de `116_sla_trigger_mysql_compat.sql`. Depois envie uma mensagem nova pelo WhatsApp e confirme a persistência em `conversation_messages`.

# RS Connect 36.34.1

> **Hotfix 36.34.1:** corrige o salvamento das Regras de atendimento quando o onboarding reaplica `handoff_action` ao agente. Não há migration nova; permanece `115_sla_operational_policy.sql`.


## Production Readiness — Fase C: SLA operacional

A Fase B (Evolution Reliability) foi homologada em ambiente real. Esta versão transforma o SLA da primeira resposta humana em uma política operacional persistente, visível na caixa de entrada e coerente com o expediente da empresa.

- migration obrigatória: `115_sla_operational_policy.sql`;
- manifesto esperado: **122 migrations de subida**;
- meta de primeira resposta humana configurável por empresa;
- alerta preventivo configurável (padrão **80%** da meta);
- estado **SLA em risco** antes do limite e **SLA violado** após 100%;
- relógio pode pausar fora do expediente ou contar continuamente;
- política é congelada no ciclo de atendimento para preservar a leitura histórica;
- caixa de entrada atualiza o risco automaticamente e avisa o supervisor quando o estado muda;
- relatórios executivo e de equipe passam a usar o mesmo relógio operacional do SLA;
- IA não encerra o SLA humano: somente uma resposta atribuída a uma pessoa da equipe encerra o relógio;
- consulte `TESTE_DA_VERSAO.md` antes de avançar para a Fase D.

# RS Connect 36.33.0

## Production Readiness — Fase B: Evolution Reliability

A Fase A (Go-Live e SLA) foi homologada. Esta versão fortalece o canal WhatsApp sem substituir as proteções já existentes: o webhook continua sendo o caminho de atualização em tempo real, enquanto uma nova camada de reconciliação consulta a Evolution diretamente para corrigir estado local perdido/desatualizado e registrar evidências da correção.

- migration obrigatória: `114_evolution_reconciliation_observability.sql`;
- manifesto esperado: **121 migrations**;
- **Reconciliar agora** corrige estado local a partir da Evolution;
- **Reaplicar webhook** reaplica apenas webhook/settings;
- **Recuperar conexão** continua sendo a ação de restart/recuperação;
- o Monitor Operacional reconcilia antes de tentar recuperar quedas técnicas;
- idempotência transacional de webhooks permanece ativa via `webhook_security_events`;
- consulte `TESTE_DA_VERSAO.md` antes de avançar para a Fase C.

# RS Connect 36.32.2

## Hotfix do cálculo de SLA — 36.32.2

Correção pontual da homologação da Fase A. O card **Tempo médio da 1ª resposta humana** já encontrava a resposta, mas o card de **SLA** retornava `0/0` porque a query reutilizava `:sla_seconds` duas vezes enquanto o projeto usa PDO MySQL com prepared statements nativos. A versão 36.32.2 usa placeholders exclusivos e isola as consultas operacionais para evitar que uma falha secundária apague o SLA calculado.

- nenhuma migration nova;
- permanece obrigatória `113_human_first_response_report_consistency.sql`;
- manifesto esperado: **120 migrations**;
- consulte `TESTE_DA_VERSAO.md` para repetir exatamente o cenário que apresentou `1 resposta medida` e `SLA 0/0`.

## Hotfix de relatório e SLA humano — 36.32.1

Correção da Fase A após homologação real no fim do dia: o relatório executivo do cliente agora interpreta o período no fuso configurado da empresa antes de consultar timestamps UTC. O mesmo pacote corrige a consistência entre **Tempo médio da 1ª resposta humana** e **SLA da 1ª resposta humana**, repara ciclos que possuíam horário sem usuário atribuído e adiciona proteção para a corrida entre o eco da Evolution e o envio humano pelo painel.

- migration obrigatória: `113_human_first_response_report_consistency.sql`;
- manifesto esperado após atualização: **120 migrations**;
- a migration `112_tenant_lifecycle_go_live.sql` da Fase A continua obrigatória e preservada.


## Production Readiness: Go-Live — 36.32.0

- separa **onboarding/homologação** da **operação oficial** com os estados `onboarding`, `ready`, `live` e `suspended`;
- adiciona Go-Live explícito no Superadmin, histórico de transições e auditoria;
- WhatsApp, IA, atendimento e agenda continuam disponíveis antes do Go-Live para testes reais;
- SLA, primeira resposta e duração oficial consideram somente períodos em que a empresa estava `LIVE`;
- cobrança manual de produção é bloqueada fora de `LIVE`, sem misturar ciclo operacional com assinatura/trial;
- o painel do cliente mostra uma tarja clara durante onboarding, ready ou suspensão;
- empresas existentes entram em `onboarding` após a migration para que o primeiro Go-Live seja confirmado conscientemente;
- migration obrigatória: `112_tenant_lifecycle_go_live.sql`;
- consulte `TESTE_DA_VERSAO.md` para configurar e homologar a fase antes de avançar no roadmap.

## Hotfix da saudação configurada — 36.31.3

- corrige o caso em que o contato envia apenas **"Oi/Olá/Bom dia"** em uma conversa que já possuía mensagens e a resposta configurada não era utilizada;
- no modo **Todos os contatos**, uma saudação explícita agora usa deterministicamente o texto salvo em **Resposta para saudação**, mesmo quando o mesmo registro de conversa já tem histórico;
- no modo **Somente novos contatos**, leads continuam usando a saudação configurada e cliente/paciente reconhecido segue para resposta natural da IA;
- o modo **Sem saudação automática** continua ignorando a resposta pronta de saudação;
- a limitação de "primeira resposta" permanece apenas para a apresentação espontânea da IA em mensagens que não sejam uma saudação pura;
- nenhuma migration nova; permanece obrigatória `111_agent_greeting_policy.sql`.

## Saudação inteligente por contato — 36.31.2

- cada assistente pode escolher entre **saudar todos os contatos**, **saudar somente novos contatos** ou **não usar saudação automática**;
- no modo recomendado para novos contatos, **cliente/paciente reconhecido continua a conversa com naturalidade**, sem mensagem de boas-vindas de novo lead;
- o agente pode usar o nome cadastrado quando isso soar natural;
- a apresentação automática da IA ocorre somente na primeira resposta da conversa; saudações explícitas do contato seguem a política configurada;
- respostas locais de “oi/olá” respeitam a mesma política; para cliente/paciente reconhecido, a IA assume a resposta natural quando configurado;
- o cache exato não é usado nem alimentado na abertura da conversa, evitando reaproveitar uma resposta de saudação fora de contexto;
- migration obrigatória: `111_agent_greeting_policy.sql`.

## Relatórios PDF e homologação conversacional — 36.31.1

- exportação PDF direta no painel de Relatórios, sem depender de Relatórios automáticos;
- PDF executivo completo e PDFs temáticos de Atendimento, CRM e Cobranças;
- PDF de Equipe e profissionais com SLA, primeira resposta, duração, filas e auditoria;
- CSV continua disponível para análise em planilha e agora é identificado explicitamente na interface;
- nenhuma migration nova; última obrigatória: `110_conversation_lifecycle_e2e_consistency.sql`;
- a homologação das regras conversacionais 36.31.0 continua em validação real antes do encerramento formal da primeira fase.

## Atendimento conversacional estruturado — 36.31.0

- transforma demanda, modalidades, valor/pagamento, indisponibilidade e encaminhamentos especiais em configurações operacionais do Agente;
- permite exigir **entendimento da demanda** antes de consultar a agenda para novos leads, sem requalificar cliente/paciente atual;
- adiciona modo de resposta **automático, em mensagens separadas ou em mensagem única**, com até 4 blocos e assinatura apenas no primeiro;
- estrutura atendimento **Online** e **Presencial**, incluindo local/meio e dias permitidos; a agenda filtra horários presenciais fora dos dias configurados;
- adiciona bloco de **valor e formas de pagamento**, preservando a ordem modalidade → valor/pagamento;
- permite definir o que fazer **quando não houver vaga**: somente responder, avisar a equipe ou encaminhar para humano;
- adiciona **Encaminhamentos especiais** para assuntos como palestra, aula, supervisão e parceria, com responsável e mensagem ao cliente;
- encaminhamentos especiais pausam a IA e geram notificação interna, sem transformar o assunto em pré-agendamento;
- nenhuma migration nova; permanece obrigatória `110_conversation_lifecycle_e2e_consistency.sql`.

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

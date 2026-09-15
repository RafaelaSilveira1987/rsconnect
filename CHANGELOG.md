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

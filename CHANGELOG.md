# Changelog — RS Connect

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

# Changelog — RS Connect

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

# Changelog — RS Connect

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

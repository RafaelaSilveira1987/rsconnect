# RS Connect — Etapa A — Mapeamento do módulo de Relatórios

Base analisada: ZIP v36.3.0 enviado em 23/07/2026.

## 1. Estrutura existente

O projeto já possui um módulo funcional de relatórios:

- `app/Controllers/ReportController.php`
- `app/Services/AdminExecutiveReportService.php`
- `app/Views/reports/index.php` — cliente
- `app/Views/reports/admin.php` — Super Admin RS
- `public/assets/css/reports.css`
- `public/assets/js/reports.js`
- rotas `/reports` e `/reports/export`
- permissão `reports.view`

A rota `/reports` já separa automaticamente Super Admin e cliente.

## 2. Fontes de dados reais disponíveis

### Atendimento
- `conversations`
- `conversation_messages`
- `conversation_events`
- `contacts`

### IA
- `ai_automation_logs`
- `ai_reprocess_runs`
- `ai_reprocess_settings`

### CRM
- `crm_leads`
- `crm_stages`
- `crm_tasks`
- `crm_notes`

### Agenda
- `calendar_appointments`
- `calendar_availability_requests`
- `calendar_availability_slots`
- `calendar_google_sync_logs`
- `calendar_maintenance_runs`

### Operação / integrações
- `n8n_flow_logs`
- `n8n_flow_callback_logs`
- `evolution_instances`
- `tenant_health_snapshots`
- `tenant_health_incidents`
- `system_health_checks`
- `system_incidents`
- `operations_backup_jobs`
- `system_backups`

### SaaS / financeiro RS
- `tenant_subscriptions`
- `tenant_invoices`
- `saas_plans`

> Atenção: `tenant_invoices` representa cobrança do SaaS RS Connect para o tenant. Não deve ser apresentada ao cliente como faturamento do negócio dele.

## 3. KPIs recomendados — Cliente

### Resumo
- Conversas iniciadas no período
- Novos contatos
- Mensagens recebidas
- Mensagens enviadas
- Respostas da IA
- Respostas humanas
- Participação IA x humano
- Tempo médio da primeira resposta
- Conversas abertas agora
- Não lidas agora

### Atendimento
- Mensagens por dia
- Demanda por dia da semana e hora
- Atendimentos por usuário
- Contatos mais ativos
- Mensagens com falha

### CRM
- Leads criados
- Ganhos
- Perdidos
- Win rate de oportunidades encerradas
- Pipeline por etapa
- Valor em aberto por etapa
- Tarefas pendentes / atrasadas

### Agenda
- Solicitações de disponibilidade
- Opções retornadas
- Horários escolhidos
- Agendamentos confirmados
- Concluídos
- Cancelados
- No-show
- Falhas de sincronização Google
- Funil: solicitação → escolha → confirmação → conclusão

### Insights por regra
- variação do volume contra período anterior
- horário de maior demanda
- dia de maior demanda
- percentual atendido por IA
- leads parados
- taxa de confirmação da agenda

## 4. KPIs recomendados — Super Admin RS

### SaaS
- Empresas ativas / inativas / suspensas
- Empresas novas no período
- Assinaturas ativas
- MRR estimado
- Recebido no período
- A receber
- Inadimplência

### Uso
- Mensagens
- Conversas ativas no período
- Respostas IA
- Ranking por tenant
- Clientes com baixo uso

### Saúde
- Empresas healthy / attention / critical / idle / blocked
- Instâncias WhatsApp conectadas / desconectadas
- Falhas IA
- Falhas n8n
- Falhas Google Agenda
- Backups success / error
- Incidentes abertos / críticos

### Agenda
- Solicitações
- Agendamentos
- Confirmações
- Cancelamentos
- Falhas de sincronização

## 5. Correções necessárias no módulo atual

1. `AdminExecutiveReportService`: o filtro de tenant é aplicado como `tenant_id = :tenant_id` também em consultas da tabela `tenants`, mas a tabela usa `id`. O filtro individual do Super Admin precisa de scopes separados.
2. `usageByTenant`: mensagens são filtradas pelo período, mas a contagem de conversas atualmente é all-time. Padronizar a definição para "conversas no período" ou "conversas com atividade no período".
3. `human_conversations`: atualmente mede conversas cujo modo atual é `human`, não atendimentos humanos ocorridos no período. Para relatório de período usar mensagens `sender_type='user'` ou eventos.
4. `ai_replies`: acrescentar `direction='outgoing'` para definição inequívoca.
5. CRM: `crm_won / crm_leads` mistura coortes. Recomenda-se separar "novos leads" de "win rate das oportunidades encerradas no período".
6. Financeiro do cliente: não usar `tenant_invoices` como resultado financeiro do negócio do cliente.
7. O JavaScript atual não desenha gráficos; ele apenas navega entre cards. Os "gráficos" atuais são barras CSS.
8. Só existe `reports.view`; considerar `reports.export` para controle de exportação.

## 6. Segurança / isolamento

- Cliente: sempre `tenant_id = Auth::tenantId()`.
- Super Admin: métricas agregadas podem cruzar tenants, mas sem conteúdo de mensagens.
- Empresas com restrição de privacidade continuam permitindo apenas indicadores agregados ao Super Admin.
- Não incluir `conversation_messages.content`, previews ou transcrições no relatório administrativo.

## 7. Performance

Os índices atuais já ajudam consultas por tenant/data, porém séries históricas não devem depender indefinidamente de agregações em `conversation_messages`.

Próxima fundação recomendada:

`048_reporting_metrics_foundation.sql`

Tabela principal sugerida:

`report_daily_metrics`

Chave única:

`tenant_id + metric_date`

Campos agregados sugeridos:

- contacts_new
- conversations_started
- conversations_closed
- messages_incoming
- messages_outgoing
- messages_ai
- messages_human
- messages_failed
- ai_success
- ai_errors
- n8n_success
- n8n_errors
- availability_requests
- availability_slots
- appointments_selected
- appointments_confirmed
- appointments_completed
- appointments_cancelled
- appointments_no_show
- google_sync_success
- google_sync_errors
- crm_leads_created
- crm_won
- crm_lost
- crm_value_won

Consultas de listas/rankings/últimos eventos podem continuar diretamente nas tabelas operacionais.

## 8. Arquitetura sugerida

- `TenantExecutiveReportService`
- `AdminExecutiveReportService` (refatorado)
- `ReportingAggregationService`
- `ReportingInsightService`
- `ReportExportService`

Manter `/reports` como rota principal e a separação por perfil já existente.

## 9. Ordem de implementação

1. Corrigir definições e scopes das métricas atuais.
2. Criar migration 048 e agregação diária.
3. Criar serviço de relatório do cliente separado do controller.
4. Adicionar comparação com período anterior e insights por regra.
5. Substituir barras CSS por gráficos reais na camada visual.
6. Evoluir Admin RS com saúde dos tenants, backups e integrações.
7. Adicionar exportação controlada por permissão.

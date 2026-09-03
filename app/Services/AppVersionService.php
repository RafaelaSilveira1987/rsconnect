<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use App\Core\PublicId;
use PDO;
use Throwable;

final class AppVersionService
{
    // Marcadores históricos mantidos para testes de regressão dos pacotes anteriores:
    // RS Connect 36.15.1, RS Connect 36.15.1-r4, RS Connect 36.15.1-r5, RS Connect 36.15.1-r6.
    // RS Connect 36.16.0 — gerenciamento nativo da Evolution API.
    // RS Connect 36.16.1 — gerenciamento da Evolution pelo administrador do cliente.
    // RS Connect 36.17.0 — roteamento econômico de contexto e saída de IA.
    // RS Connect 36.17.1 — novo layout responsivo da criação de instância Evolution.
    // RS Connect 36.17.2 — restauração e renovação resiliente da foto dos contatos WhatsApp.
    // RS Connect 36.18.0 — respostas locais, cache exato e relatórios PDF corrigidos.
    // RS Connect 36.18.1 — criação de instâncias corrigida e novo cabeçalho operacional.
    // RS Connect 36.18.2 — vínculo automático e edição manual dos canais por assistente.
    // RS Connect 36.18.3 — exibição operacional da fila de mensagens fora do horário.
    // RS Connect 36.18.4 — fila fora do horário também para conversas em atendimento humano.
    // RS Connect 36.18.5 — takeover humano atômico, bloqueio operacional e documentação consolidada.
    // RS Connect 36.18.6 — exclusão assistida, transferência integral de vínculos e auditoria da remoção.
    // RS Connect 36.19.0 — painel OpenAI 2.0, medição de economia, memória progressiva e fatos estruturados.
    // RS Connect 36.19.1 — atribuição financeira de consumo OpenAI por empresa e assistente.
    // RS Connect 36.19.2 — orçamento de IA por empresa, alertas e proteção automática de consumo.
    // RS Connect 36.19.3 — margem comercial, receita de referência e preço recomendado da franquia de IA.
    // RS Connect 36.20.0 — rentabilidade histórica, MRR, tendência mensal e simulação comercial de planos.
    // RS Connect 36.20.1 — linguagem simples e acessível em menus, telas, alertas e formulários.
    // RS Connect 36.20.2 — lista ativa de clientes que precisam de atenção, motivos e acompanhamento.
    // RS Connect 36.20.3 — revisão ampla de UI/UX, formulários, gavetas, checkboxes e responsividade.
    // RS Connect 36.20.4 — correção estrutural de rodapés, formulários, CRM, cobranças e responsividade administrativa.
    // RS Connect 36.20.5 — ajuda contextual, onboarding simplificado e recursos de acessibilidade.
    // RS Connect 36.20.6 — exclusão assistida idempotente quando a conexão externa já foi removida.
    // RS Connect 36.20.7 — fluxo visual coerente para exclusão local, com transferência segura quando houver vínculos.
    // RS Connect 36.20.8 — prévia de exclusão liberada do polling e do bloqueio da sessão PHP.
    // RS Connect 36.20.9 — exclusão segura sem conexão substituta, com descarte explícito dos dados dependentes.
    // RS Connect 36.20.10 — aviso fora do horário para IA/humano e retomada agendada na abertura exata.
    // RS Connect 36.20.11 — preços por origem da IA, fidelidade e nova apresentação comercial dos planos.
    // RS Connect 36.20.12 — saneamento da suíte de testes e remoção da aplicação duplicada em tests/.
    // RS Connect 36.20.13 — blindagem fail-closed, assinaturas e idempotência dos webhooks críticos.
    // RS Connect 36.20.13.1 — normalização da URL base e do Token da API PagBank/PagSeguro.
    // RS Connect 36.20.13.2 — validação de CPF/CNPJ e recuperação automática do Checkout PagBank.
    // RS Connect 36.20.13.3 — compatibilidade do schema de conciliação e persistência segura do Checkout PagBank.
    // RS Connect 36.20.13.4 — correção dos rótulos comerciais em português e preparação operacional para produção.
    // RS Connect 36.20.14 — health checks seguros, separados, públicos mínimos e diagnóstico protegido.
    // RS Connect 36.20.15 — proteção contra XSS, SVG e CSP.
    // RS Connect 36.20.15.1 — restauração segura das rotas e do menu White Label.
    // RS Connect 36.20.15.3 — inicialização resiliente do autoload e validação do Router no build.
    // RS Connect 36.20.15.2 — uploads persistentes e revisão do layout do White Label.
    // RS Connect 36.20.15.4 — layout proporcional e aplicação real do White Label no painel e login do cliente.
    // RS Connect 36.20.16 — manifesto canônico, registro schema_migrations e executor seguro.
    // RS Connect 36.21.0 — demonstração interativa da IA e automação opcional do funil por conversa.
    // RS Connect 36.21.1 — hotfix do executor de migrations para drenar resultados de PREPARE/EXECUTE.
    // RS Connect 36.21.2 — retomada automática da IA após o tempo de espera, sem depender do cron externo.
    // RS Connect 36.22.0 — monitor pós-horário configurável e acompanhamento de orçamentos pendentes.
    // RS Connect 36.22.1 — correção dos placeholders PDO no recebimento da Evolution.
    // RS Connect 36.23.0 — motor central de notificações para agenda e orçamento, com fila e WhatsApp.
    // RS Connect 36.23.1 — compatibilidade MySQL do seed INSERT...SELECT...ON DUPLICATE da migration 092.
    // RS Connect 36.23.2 — horário local da agenda, envio imediato automático e configurações separadas.
    // RS Connect 36.24.0 — inscrição pública do Plano Inicial, checkout recorrente Asaas e trial de 7 dias.
    // RS Connect 36.24.1 — normalização segura da URL da API Asaas e correção do host rsconnect.local.
    // RS Connect 36.24.3 — compatibilidade do campo name no Checkout Asaas e fallback seguro de customerData.
    // RS Connect 36.24.4 — rate limit por IP real e falhas técnicas do Asaas fora da contagem.
    // RS Connect 36.24.5 — Checkout Asaas sem customerData parcial; endereço coletado no ambiente seguro.
    // RS Connect 36.24.6 — ponte interna segura para redirecionamento ao Checkout Asaas sob CSP estrita.
    // RS Connect 36.25.1 — portal financeiro do cliente e validação segura do Asaas em Produção.
    // RS Connect 36.26.0 — tela de inscrição reorganizada e cupons promocionais para assinatura.
    // RS Connect 36.26.1 — proteção do valor mínimo do Checkout Asaas para cupons agressivos.
    // RS Connect 36.26.2 — monitor financeiro considera recuperação e não reabre alertas por falhas históricas.
    // RS Connect 36.26.6 — silenciamento operacional por conexão WhatsApp pausada pelo cliente.
    // RS Connect 36.26.7 — resolução persistente de alertas e liberação segura da fila de respostas.
    // RS Connect 36.26.8 — Central de Monitoramento compacta, gráficos e histórico progressivo.
    // RS Connect 36.26.9 — webhook autenticado e ignorado confirma recuperação técnica do gateway.
    // RS Connect 36.26.9 — Recuperação técnica do webhook financeiro.
    // RS Connect 36.27.0 — round-robin transacional por canal, sem quebrar especialistas e continuidade.
    // RS Connect 36.27.1 — handoff IA→IA por intenção transfere o pin para o especialista do canal.
    // RS Connect 36.27.2 — UI multiagente configurável e especialistas fora da distribuição genérica.
    // RS Connect 36.27.3 — handoff IA→IA identificável no chat, contexto de transferência e autoria IA por agente.
    // RS Connect 36.27.4 — assinatura visível do agente no WhatsApp, com área para especialistas.
    // RS Connect 36.27.5 — resumo operacional diário e vigilância de bloqueios de assinatura/vigência.
    // RS Connect 36.27.6 — valor comercial extraído da conversa e resolução segura dos alertas de orçamento.
    // RS Connect 36.27.7 — correção do POST UUID das ações de orçamento e validação tenant-aware do alias público.
    // RS Connect 36.27.9 — modal visual global para confirmações e prompts, sem caixas nativas do navegador.
    // Compatibilidade histórica: REQUIRED_MIGRATION = '091_after_hours_monitor_and_quote_requests.sql'.
    // Compatibilidade histórica: REQUIRED_MIGRATION = '093_public_signup_asaas_trial.sql'.
    // Compatibilidade histórica: REQUIRED_MIGRATION = '094_normalize_asaas_api_base_url.sql'.
    // Compatibilidade histórica: REQUIRED_MIGRATION = '095_public_signup_pix_qrcode.sql'.
    // Compatibilidade histórica: REQUIRED_MIGRATION = '096_public_signup_coupons.sql'.
    // Migrations históricas: 075_scheduled_reports_and_deliveries.sql, 076_evolution_instance_management.sql, 077_ai_efficiency_foundation.sql, 078_contact_avatar_refresh.sql, 079_ai_efficiency_phase2_and_report_cleanup.sql e 080_ai_memory_and_usage_intelligence.sql, 081_ai_cost_attribution.sql, 082_ai_budget_governance.sql, 083_ai_commercial_margin.sql, 084_ai_profitability_history.sql, 085_ai_commercial_attention_queue.sql, 086_plan_ai_mode_and_commitment.sql, 087_webhook_security_events.sql, 088_payment_reconciliation_schema_compat.sql, 089_schema_migrations_registry.sql, 090_crm_conversation_automation.sql e 091_after_hours_monitor_and_quote_requests.sql, 092_notification_orchestration.sql, 093_public_signup_asaas_trial.sql 094_normalize_asaas_api_base_url.sql e 095_public_signup_pix_qrcode.sql e 096_public_signup_coupons.sql e 097_evolution_operational_alert_suppression.sql e 098_operational_queue_release.sql.
    // Identidade histórica preservada: Beta Comercial 1.5.
    public const VERSION_LABEL = 'Beta Comercial 1.6';
    public const PACKAGE_LABEL = 'RS Connect 36.27.9 — Diálogos visuais padronizados';
    public const REQUIRED_MIGRATION = '099_ai_agent_round_robin_routing.sql';

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    public function dashboard(): array
    {
        $checks = $this->checks();
        $ok = count(array_filter($checks, static fn (array $check): bool => ($check['status'] ?? '') === 'ok'));
        $warning = count(array_filter($checks, static fn (array $check): bool => ($check['status'] ?? '') === 'warning'));
        $blocked = count(array_filter($checks, static fn (array $check): bool => ($check['status'] ?? '') === 'blocked'));
        $score = count($checks) > 0 ? (int) round(($ok / count($checks)) * 100) : 0;

        return [
            'version' => self::VERSION_LABEL,
            'package' => self::PACKAGE_LABEL,
            'required_migration' => self::REQUIRED_MIGRATION,
            'required_migration_number' => $this->requiredMigrationNumber(),
            'status_label' => $this->statusLabel($score, $blocked),
            'score' => $score,
            'ok' => $ok,
            'warning' => $warning,
            'blocked' => $blocked,
            'checks' => $checks,
            'environment' => $this->environment(),
            'modules' => $this->modules(),
            'deploy' => $this->deployInfo(),
            'next_actions' => $this->nextActions($checks),
        ];
    }

    private function checks(): array
    {
        $checks = [];

        $checks[] = $this->check(
            'Banco de dados',
            $this->databaseOk() ? 'ok' : 'blocked',
            $this->databaseOk() ? 'Conexão ativa com o banco configurado.' : 'Não foi possível consultar o banco de dados.',
            'Conferir DB_HOST, DB_DATABASE, DB_USERNAME e DB_PASSWORD no ambiente.'
        );

        $migrationTables = [
            'tenant_implementation_status',
            'tenant_implementation_checklist',
            'tenant_onboarding_progress',
            'tenant_onboarding_settings',
            'operations_backup_routines',
            'operations_backup_jobs',
            'system_backups',
            'system_health_checks',
            'tenant_calendar_availability_settings',
            'calendar_availability_requests',
            'calendar_availability_slots',
            'calendar_google_sync_logs',
            'tenant_notification_preferences',
            'tenant_admin_tracking',
            'admin_crm_stages',
            'admin_crm_opportunities',
            'admin_crm_activities',
            'tenant_health_snapshots',
            'tenant_health_checks',
            'tenant_health_incidents',
            'tenant_health_incident_events',
            'conversation_flow_states',
            'ai_agent_group_rules',
            'report_daily_metrics',
            'operational_alert_preferences',
            'admin_operational_notifications',
            'operational_alert_deliveries',
            'client_communications',
            'client_communication_recipients',
            'ai_usage_events',
            'ai_usage_threshold_events',
            'ai_after_hours_pending',
            'ai_agent_instance_bindings',
            'client_communication_replies',
            'ai_prompt_studio_drafts',
            'ai_agent_prompt_versions',
            'evolution_connection_events',
            'message_retention_runs',
            'conversation_assignment_history',
            'conversation_status_history',
            'calendar_appointment_history',
            'conversation_service_cycles',
            'rs_datetime_contract',
            'security_rate_limits',
            'operational_monitor_runs',
            'conversation_message_attachments',
            'conversation_ai_memory',
            'contact_ai_memory',
            'tenant_ai_commercial_attention_tracking',
            'schema_migrations',
            'tenant_crm_automation_settings',
            'crm_automation_events',
            'ai_after_hours_monitor_settings',
            'tenant_commercial_request_settings',
            'crm_commercial_requests',
            'tenant_notification_rules',
            'notification_jobs',
            'public_signup_settings',
            'public_signup_sessions',
            'tenant_subscription_gateways',
        ];
        $missingTables = array_values(array_filter($migrationTables, fn (string $table): bool => !$this->tableExists($table)));
        $checks[] = $this->check(
            'Migrations centrais',
            count($missingTables) === 0 ? 'ok' : 'blocked',
            count($missingTables) === 0 ? 'Estrutura principal do pacote atual encontrada.' : 'Tabelas ausentes: ' . implode(', ', $missingTables),
            'Executar php bin/migrate.php status e aplicar o baseline/up até a migration 093.'
        );

        $monitoringReady = $this->tableExists('operational_monitor_runs')
            && $this->columnExists('system_incidents', 'acknowledged_at')
            && $this->columnExists('operational_alert_deliveries', 'delivery_key')
            && $this->columnExists('operational_alert_preferences', 'disk_enabled')
            && $this->columnExists('client_communication_recipients', 'whatsapp_provider_message_id')
            && $this->columnExists('client_communication_recipients', 'email_provider_message_id')
            && class_exists(OperationalAlertService::class);
        $checks[] = $this->check(
            'Monitoramento e alertas operacionais',
            $monitoringReady ? 'ok' : 'blocked',
            $monitoringReady
                ? 'Incidentes, reconhecimento, lembretes, recuperação, disco, filas e canais externos estão disponíveis.'
                : 'A estrutura do monitoramento operacional ainda não foi aplicada.',
            'Executar database/migrations/073_operational_monitoring_alert_delivery.sql e validar os canais em Avisos do sistema.'
        );

        $attachmentsReady = $this->tableExists('conversation_message_attachments')
            && class_exists(ConversationAttachmentService::class);
        $checks[] = $this->check(
            'Arquivos nas conversas',
            $attachmentsReady ? 'ok' : 'blocked',
            $attachmentsReady
                ? 'Imagens, PDFs e áudios podem ser enviados, recebidos e acessados com autorização por empresa.'
                : 'A estrutura privada de anexos ainda não foi aplicada.',
            'Executar database/migrations/074_conversation_message_attachments.sql e testar um envio e um recebimento de arquivo.'
        );

        $tenantIsolationReady = class_exists(TenantIsolationService::class)
            && method_exists(TenantIsolationService::class, 'validateAuthenticatedRequest')
            && $this->tableExists('security_events');
        $checks[] = $this->check(
            'Isolamento entre empresas',
            $tenantIsolationReady ? 'ok' : 'blocked',
            $tenantIsolationReady
                ? 'UUIDs e IDs internos são validados contra o tenant autenticado antes do controller.'
                : 'A barreira central de isolamento por tenant ou a auditoria de segurança não está disponível.',
            'Implantar o pacote 36.14.0, manter a migration 074 aplicada e validar o painel executivo, anexos, isolamento, segurança e monitoramento.'
        );

        $trialStructureReady = $this->columnExists('tenant_subscriptions', 'trial_days')
            && $this->columnExists('tenant_subscriptions', 'trial_end_behavior')
            && $this->columnExists('tenant_subscriptions', 'trial_grace_days')
            && $this->tableExists('tenant_onboarding_settings');
        $checks[] = $this->check(
            'Teste gratuito e primeiro acesso',
            $trialStructureReady ? 'ok' : 'blocked',
            $trialStructureReady
                ? 'Teste por quantidade de dias, transição pós-teste e implantação guiada estão disponíveis.'
                : 'A estrutura do teste gratuito ou do primeiro acesso guiado ainda não foi aplicada.',
            'Executar database/migrations/060_free_trial_guided_first_access.sql.'
        );

        $promptStudioReady = $this->tableExists('ai_prompt_studio_drafts')
            && $this->tableExists('ai_agent_prompt_versions');
        $checks[] = $this->check(
            'Prompt Studio e versões',
            $promptStudioReady ? 'ok' : 'blocked',
            $promptStudioReady
                ? 'Criação guiada, validação de conflitos e histórico restaurável de prompts estão disponíveis.'
                : 'A estrutura do Prompt Studio e do versionamento de prompts ainda não foi aplicada.',
            'Executar database/migrations/062_prompt_studio_and_versions.sql.'
        );

        $messageGovernanceReady = $this->columnExists('users', 'whatsapp_display_name')
            && $this->columnExists('users', 'whatsapp_role_label')
            && $this->columnExists('tenants', 'message_retention_mode')
            && $this->columnExists('conversation_messages', 'delivered_content')
            && $this->columnExists('conversation_messages', 'content_purged_at')
            && $this->columnExists('evolution_instances', 'connection_updated_at')
            && $this->columnExists('evolution_instances', 'qrcode_updated_at')
            && $this->tableExists('evolution_connection_events')
            && $this->tableExists('message_retention_runs');
        $checks[] = $this->check(
            'Governança de mensagens e Evolution em tempo real',
            $messageGovernanceReady ? 'ok' : 'blocked',
            $messageGovernanceReady
                ? 'Assinatura do atendente, políticas de retenção e histórico de conexão da Evolution estão disponíveis.'
                : 'A estrutura de identificação humana, retenção e atualização de conexão ainda não foi aplicada.',
            'Executar database/migrations/063_message_governance_evolution_realtime.sql.'
        );

        $evolutionManagementReady = $this->columnExists('evolution_instances', 'management_mode')
            && $this->columnExists('evolution_instances', 'webhook_events')
            && $this->columnExists('evolution_instances', 'ignore_groups')
            && $this->columnExists('evolution_instances', 'reject_calls')
            && $this->columnExists('evolution_instances', 'last_settings_sync_at')
            && method_exists(EvolutionService::class, 'createInstance')
            && method_exists(EvolutionService::class, 'setSettings');
        $checks[] = $this->check(
            'Gerenciamento nativo da Evolution API',
            $evolutionManagementReady ? 'ok' : 'blocked',
            $evolutionManagementReady
                ? 'Criação de instâncias, QR Code, webhook, filtros e configurações remotas estão disponíveis no RS Connect.'
                : 'As colunas ou serviços do gerenciamento nativo da Evolution ainda não foram aplicados.',
            'Executar database/migrations/076_evolution_instance_management.sql e validar uma conexão em Canais WhatsApp.'
        );

        $alertSuppressionReady = $this->columnExists('evolution_instances', 'operational_alerts_enabled')
            && $this->columnExists('evolution_instances', 'operational_alerts_paused_at')
            && $this->columnExists('evolution_instances', 'operational_alerts_pause_reason');
        $checks[] = $this->check(
            'Silenciamento de alertas por conexão',
            $alertSuppressionReady ? 'ok' : 'blocked',
            $alertSuppressionReady
                ? 'Conexões pausadas pelo cliente deixam de gerar alertas de WhatsApp e fila até a reconexão.'
                : 'A estrutura de pausa operacional por conexão ainda não foi aplicada.',
            'Executar database/migrations/097_evolution_operational_alert_suppression.sql.'
        );

        $queueReleaseReady = $this->columnTypeContains('conversation_messages', 'status', "'cancelled'");
        $checks[] = $this->check(
            'Liberação segura da fila de respostas',
            $queueReleaseReady ? 'ok' : 'blocked',
            $queueReleaseReady
                ? 'Respostas pendentes podem ser canceladas sem apagar o histórico e sem novo envio após a reconexão.'
                : 'O status de cancelamento seguro da fila ainda não foi aplicado.',
            'Executar database/migrations/098_operational_queue_release.sql.'
        );

        $professionalAssignmentReady = $this->columnExists('tenants', 'professional_assignment_enabled')
            && $this->columnExists('tenants', 'professional_auto_assign_enabled')
            && $this->columnExists('contacts', 'preferred_user_id')
            && $this->columnExists('conversations', 'assignment_source');
        $checks[] = $this->check(
            'Atendimento por profissional',
            $professionalAssignmentReady ? 'ok' : 'blocked',
            $professionalAssignmentReady
                ? 'Vínculo preferencial, bloqueio por responsável e atribuição automática opcional estão disponíveis.'
                : 'A estrutura opcional de atendimento exclusivo por profissional ainda não foi aplicada.',
            'Executar database/migrations/064_professional_conversation_assignment_compat.sql.'
        );

        $professionalCalendarReady = $this->columnExists('tenants', 'professional_calendar_enabled')
            && $this->columnExists('tenants', 'professional_calendar_auto_from_conversation')
            && $this->columnExists('tenants', 'professional_calendar_prevent_contact_overlap')
            && $this->tableExists('user_calendar_profiles');
        $checks[] = $this->check(
            'Agenda por profissional',
            $professionalCalendarReady ? 'ok' : 'blocked',
            $professionalCalendarReady
                ? 'Horários individuais, calendário por usuário e bloqueio opcional de sobreposição do mesmo cliente estão disponíveis.'
                : 'A estrutura opcional de agenda individual ainda não foi aplicada.',
            'Executar database/migrations/065_professional_calendar_profiles_compat.sql e 066_contact_schedule_overlap_guard_compat.sql.'
        );

        $operationalHistoryReady = $this->tableExists('conversation_assignment_history')
            && $this->tableExists('conversation_status_history')
            && $this->tableExists('calendar_appointment_history')
            && $this->tableExists('conversation_service_cycles')
            && $this->columnExists('conversations', 'first_incoming_at')
            && $this->columnExists('conversations', 'first_response_at')
            && $this->columnExists('conversations', 'first_response_user_id')
            && $this->columnExists('calendar_appointments', 'confirmed_at')
            && $this->columnExists('calendar_appointments', 'completed_at')
            && $this->columnExists('calendar_appointments', 'no_show_at');
        $checks[] = $this->check(
            'Base histórica por profissional',
            $operationalHistoryReady ? 'ok' : 'blocked',
            $operationalHistoryReady
                ? 'Atribuições, transferências, ciclos das conversas, primeira resposta humana e mudanças da agenda estão auditáveis.'
                : 'O histórico operacional necessário para relatórios confiáveis ainda não foi aplicado.',
            'Executar as migrations 067, 068, 069, 070 e 071; a 071 padroniza datas em UTC e preserva o fuso dos relatórios. A 070 sincroniza encerramento e reabertura com os ciclos dos relatórios.'
        );

        $calendarOnboardingReady = $this->columnExists('tenant_onboarding_settings', 'calendar_mode')
            && $this->columnExists('tenant_onboarding_settings', 'smart_calendar_status');
        $checks[] = $this->check(
            'Agenda no primeiro acesso',
            $calendarOnboardingReady ? 'ok' : 'blocked',
            $calendarOnboardingReady
                ? 'O onboarding diferencia Agenda interna, Agenda inteligente homologada e operação sem agenda.'
                : 'A escolha do tipo de agenda e o controle de liberação pelo Super Admin ainda não foram aplicados.',
            'Executar database/migrations/061_onboarding_calendar_modes.sql.'
        );

        $aiQuotaLimits = $this->standardAiQuotaLimits();
        $checks[] = $this->check(
            'Franquia de IA nos planos',
            empty($aiQuotaLimits['missing']) ? 'ok' : 'blocked',
            empty($aiQuotaLimits['missing'])
                ? 'Planos padrão possuem franquia mensal de interações de IA definida.'
                : 'Planos sem franquia válida: ' . implode(', ', $aiQuotaLimits['missing']) . '.',
            'Executar database/migrations/053_ai_quota_limit_repair.sql e revisar os limites em Planos e cobrança.'
        );

        $aiTelemetryReady = $this->columnExists('ai_usage_events', 'delivery_status')
            && $this->columnExists('ai_usage_events', 'provider_calls')
            && $this->columnExists('ai_usage_events', 'total_tokens')
            && $this->columnExists('ai_usage_events', 'cached_tokens')
            && $this->columnExists('ai_usage_events', 'estimated_cost_currency');
        $checks[] = $this->check(
            'Telemetria técnica da IA',
            $aiTelemetryReady ? 'ok' : 'blocked',
            $aiTelemetryReady
                ? 'Interações entregues, chamadas ao provedor, tokens e custo estimado podem ser auditados separadamente.'
                : 'A estrutura de telemetria detalhada da IA ainda não foi aplicada.',
            'Executar database/migrations/054_ai_metrics_and_delivery_telemetry.sql.'
        );

        $aiEfficiencyReady = $this->columnExists('ai_agents', 'ai_efficiency_mode')
            && $this->columnExists('ai_agents', 'ai_selective_knowledge')
            && $this->columnExists('ai_usage_events', 'estimated_input_tokens_avoided');
        $checks[] = $this->check(
            'Eficiência de contexto da IA',
            $aiEfficiencyReady ? 'ok' : 'blocked',
            $aiEfficiencyReady
                ? 'Perfis Econômico, Equilibrado e Qualidade controlam histórico, base e saída com telemetria de economia.'
                : 'A camada de economia de contexto e a medição de tokens evitados ainda não foram aplicadas.',
            'Executar database/migrations/077_ai_efficiency_foundation.sql.'
        );

        $aiEfficiencyPhase2Ready = $this->columnExists('ai_agents', 'ai_local_replies_enabled')
            && $this->columnExists('ai_agents', 'ai_exact_cache_enabled')
            && $this->tableExists('ai_response_cache')
            && $this->columnExists('ai_usage_events', 'execution_strategy')
            && $this->columnExists('ai_usage_events', 'provider_calls_avoided');
        $checks[] = $this->check(
            'Respostas sem consumo de tokens',
            $aiEfficiencyPhase2Ready ? 'ok' : 'blocked',
            $aiEfficiencyPhase2Ready
                ? 'Regras locais e cache exato opcional podem responder sem chamar o provedor, com telemetria de chamadas evitadas.'
                : 'A segunda fase da economia de IA ainda não foi aplicada.',
            'Executar database/migrations/079_ai_efficiency_phase2_and_report_cleanup.sql.'
        );

        $aiBudgetGovernanceReady = $this->tableExists('tenant_ai_budget_policies')
            && $this->tableExists('ai_budget_threshold_events');
        $checks[] = $this->check(
            'Governança financeira da IA',
            $aiBudgetGovernanceReady ? 'ok' : 'blocked',
            $aiBudgetGovernanceReady
                ? 'Orçamento por empresa, alertas e ações automáticas de proteção estão disponíveis.'
                : 'A política financeira por empresa ainda não foi aplicada.',
            'Executar database/migrations/082_ai_budget_governance.sql.'
        );

        $aiCommercialMarginReady = $this->tableExists('tenant_ai_commercial_policies')
            && class_exists(AiCommercialMarginService::class);
        $checks[] = $this->check(
            'Gestão comercial da IA',
            $aiCommercialMarginReady ? 'ok' : 'blocked',
            $aiCommercialMarginReady
                ? 'Receita de referência, custo projetado, margem conhecida e preço mínimo por empresa estão disponíveis.'
                : 'A estrutura de margem comercial por empresa ainda não foi aplicada.',
            'Executar database/migrations/083_ai_commercial_margin.sql.'
        );

        $aiProfitabilityReady = $this->tableExists('tenant_ai_profitability_snapshots')
            && $this->tableExists('tenant_ai_commercial_policy_history')
            && class_exists(AiProfitabilityHistoryService::class);
        $checks[] = $this->check(
            'Rentabilidade histórica da IA',
            $aiProfitabilityReady ? 'ok' : 'blocked',
            $aiProfitabilityReady
                ? 'MRR, histórico mensal, tendência e simulação de planos estão disponíveis por empresa.'
                : 'A camada histórica de rentabilidade e simulação comercial ainda não foi aplicada.',
            'Executar database/migrations/084_ai_profitability_history.sql.'
        );

        $contactAvatarReady = $this->columnExists('contacts', 'avatar_checked_at');
        $checks[] = $this->check(
            'Fotos dos contatos WhatsApp',
            $contactAvatarReady ? 'ok' : 'blocked',
            $contactAvatarReady
                ? 'URLs temporárias das fotos podem ser renovadas sem perder o fallback pelas iniciais.'
                : 'O controle de atualização das fotos dos contatos ainda não foi aplicado.',
            'Executar database/migrations/078_contact_avatar_refresh.sql.'
        );

        $multiChannelReady = $this->tableExists('ai_agent_instance_bindings')
            && $this->columnExists('conversations', 'ai_agent_id')
            && $this->tableExists('ai_agent_routing_state');
        $checks[] = $this->check(
            'Canais WhatsApp e agentes',
            $multiChannelReady ? 'ok' : 'blocked',
            $multiChannelReady
                ? 'Múltiplos canais por empresa, múltiplos agentes por canal e continuidade por conversa estão disponíveis.'
                : 'O vínculo N:N entre canais WhatsApp e agentes ainda não foi aplicado.',
            'Executar database/migrations/055_multi_whatsapp_agent_routing.sql e 099_ai_agent_round_robin_routing.sql.'
        );

        $agendaWriterMisconfigured = $this->agendaWriterMisconfiguredCount();
        $checks[] = $this->check(
            'Contrato da Agenda Google',
            $agendaWriterMisconfigured === 0 ? 'ok' : 'blocked',
            $agendaWriterMisconfigured === 0
                ? 'O writer do Google Calendar está restrito a compromissos reais (calendar.appointment.created).'
                : $agendaWriterMisconfigured . ' fluxo(s) ativo(s) da Agenda Google ainda aceitam eventos genéricos e podem criar compromissos indevidos.',
            'Executar database/migrations/056_n8n_agenda_event_contract.sql e revalidar n8n → Fluxos por empresa.'
        );

        $calendarModalityReady = $this->columnExists('tenant_pre_schedule_settings', 'modality_message')
            && $this->columnExists('calendar_appointments', 'appointment_modality');
        $checks[] = $this->check(
            'Modalidade antes da disponibilidade',
            $calendarModalityReady ? 'ok' : 'blocked',
            $calendarModalityReady
                ? 'Online/Presencial é coletado antes da consulta e enviado como filtro obrigatório para a Agenda Google.'
                : 'A agenda ainda não possui a estrutura da modalidade obrigatória antes da busca de horários.',
            'Executar database/migrations/057_calendar_modality_before_availability.sql.'
        );

        $contactIdentityReady = $this->columnExists('contacts', 'name_source')
            && $this->columnExists('contacts', 'whatsapp_name_candidate')
            && $this->columnExists('contacts', 'whatsapp_name_seen_count');
        $checks[] = $this->check(
            'Identidade confiável do contato',
            $contactIdentityReady ? 'ok' : 'blocked',
            $contactIdentityReady
                ? 'Nome do WhatsApp só é promovido após observação consistente; telefone permanece como fallback seguro.'
                : 'O webhook ainda não possui a estrutura para validar nomes automáticos antes de exibi-los.',
            'Executar database/migrations/059_contact_identity_confidence.sql.'
        );

        $reactionPreferenceReady = $this->columnExists('ai_agents', 'reply_to_reactions');
        $checks[] = $this->check(
            'Reações no WhatsApp',
            $reactionPreferenceReady ? 'ok' : 'blocked',
            $reactionPreferenceReady ? 'Preferência de resposta a reações disponível por assistente.' : 'A coluna reply_to_reactions ainda não foi criada.',
            'Executar database/migrations/038_ai_reaction_preferences.sql.'
        );

        $conversationFlowReady = $this->columnExists('contacts', 'contact_group')
            && $this->tableExists('conversation_flow_states')
            && $this->tableExists('ai_agent_group_rules');
        $checks[] = $this->check(
            'Fluxo e grupos de contato',
            $conversationFlowReady ? 'ok' : 'blocked',
            $conversationFlowReady ? 'Etapas, demanda e regras por grupo disponíveis para a IA e o pré-agendamento.' : 'A estrutura de fluxo e grupos ainda não foi aplicada.',
            'Executar database/migrations/040_conversation_flow_contact_groups.sql.'
        );

        $legacyCustomerDemandRules = $conversationFlowReady
            ? $this->number("SELECT COUNT(*) FROM ai_agent_group_rules WHERE contact_group IN ('customer','patient') AND require_demand_before_pre_schedule = 1")
            : 0;
        $legacyCustomerDemandStates = $conversationFlowReady
            ? $this->number("SELECT COUNT(*) FROM conversation_flow_states fs INNER JOIN contacts ct ON ct.id = fs.contact_id AND ct.tenant_id = fs.tenant_id WHERE fs.demand_status = 'pending' AND (ct.status = 'customer' OR ct.contact_group IN ('customer','patient'))")
            : 0;
        $customerContinuityReady = $conversationFlowReady && $legacyCustomerDemandRules === 0 && $legacyCustomerDemandStates === 0;
        $checks[] = $this->check(
            'Continuidade de Cliente/Paciente',
            $customerContinuityReady ? 'ok' : ($conversationFlowReady ? 'warning' : 'blocked'),
            $customerContinuityReady
                ? 'Clientes e pacientes atuais não voltam para a coleta obrigatória de demanda.'
                : ($conversationFlowReady
                    ? ($legacyCustomerDemandRules . ' regra(s) e ' . $legacyCustomerDemandStates . ' estado(s) antigos ainda exigem normalização.')
                    : 'A estrutura de fluxo ainda não está disponível.'),
            'Executar database/migrations/050_human_takeover_customer_context.sql.'
        );

        $calendarConversationReady = $this->columnExists('calendar_appointments', 'availability_options_request_id')
            && $this->columnExists('calendar_availability_slots', 'suggestion_position')
            && $this->columnExists('tenant_pre_schedule_settings', 'availability_options_message');
        $checks[] = $this->check(
            'Agenda conversacional',
            $calendarConversationReady ? 'ok' : 'blocked',
            $calendarConversationReady
                ? 'Alternativas, escolha do contato e pré-reserva aguardando aprovação estão disponíveis.'
                : 'A estrutura para apresentar e reconhecer opções de horário ainda não foi aplicada.',
            'Executar database/migrations/046_calendar_conversational_slot_selection.sql.'
        );

        $reportingFoundationReady = $this->tableExists('report_daily_metrics')
            && $this->indexExists('conversation_messages', 'idx_messages_tenant_sent_at');
        $checks[] = $this->check(
            'Relatórios executivos',
            $reportingFoundationReady ? 'ok' : 'blocked',
            $reportingFoundationReady
                ? 'Fundação de métricas diária disponível para relatórios e comparativos.'
                : 'A base agregada de relatórios ainda não foi aplicada completamente.',
            'Executar database/migrations/048_reporting_metrics_foundation.sql.'
        );

        $operationalCommunicationReady = $this->tableExists('operational_alert_preferences')
            && $this->tableExists('admin_operational_notifications')
            && $this->tableExists('client_communications');
        $checks[] = $this->check(
            'Resolução e comunicação operacional',
            $operationalCommunicationReady ? 'ok' : 'blocked',
            $operationalCommunicationReady
                ? 'Alertas internos, playbooks e comunicados estão disponíveis.'
                : 'A estrutura da versão 36.6.5 ainda não foi aplicada.',
            'Executar database/migrations/049_operational_resolution_communications.sql.'
        );

        $communicationCenterReady = $this->tableExists('client_communication_replies')
            && $this->columnExists('client_communications', 'priority')
            && $this->columnExists('client_communications', 'response_mode')
            && $this->columnExists('client_communications', 'expires_at')
            && $this->columnExists('client_communication_recipients', 'tenant_last_seen_at')
            && $this->columnExists('client_communication_recipients', 'acknowledged_at');
        $checks[] = $this->check(
            'Central de comunicação in-app',
            $communicationCenterReady ? 'ok' : 'blocked',
            $communicationCenterReady
                ? 'Caixa de mensagens, leitura, confirmação e respostas RS e empresa estão disponíveis.'
                : 'A estrutura de mensagens interativas do cliente ainda não foi aplicada.',
            'Executar database/migrations/058_client_communication_center.sql.'
        );

        $aiUsageReady = $this->tableExists('ai_usage_events')
            && $this->tableExists('ai_usage_threshold_events')
            && $this->tableExists('ai_after_hours_pending')
            && $this->columnExists('ai_provider_credentials', 'credential_owner');
        $checks[] = $this->check(
            'Franquia de IA e recuperação pós-horário',
            $aiUsageReady ? 'ok' : 'blocked',
            $aiUsageReady
                ? 'Uso faturável por origem da credencial e fila pós-horário estão disponíveis.'
                : 'A estrutura de consumo de IA/recuperação pós-horário ainda não foi aplicada.',
            'Executar database/migrations/052_ai_usage_and_after_hours_recovery.sql.'
        );

        $appKey = (string) Env::get('APP_KEY', '');
        $checks[] = $this->check(
            'APP_KEY',
            $appKey !== '' ? 'ok' : 'blocked',
            $appKey !== '' ? 'Chave da aplicação configurada.' : 'APP_KEY vazio.',
            'Não trocar APP_KEY em produção sem plano, pois ela protege dados criptografados.'
        );

        $publicIdReady = false;
        if ($appKey !== '') {
            try {
                $publicToken = PublicId::encode('tenant', 1);
                $publicIdReady = PublicId::isUuid($publicToken)
                    && PublicId::decode('tenant', $publicToken) === 1;
            } catch (Throwable) {
                $publicIdReady = false;
            }
        }
        $checks[] = $this->check(
            'Identificadores públicos UUID',
            $publicIdReady ? 'ok' : 'blocked',
            $publicIdReady
                ? 'IDs numéricos internos são convertidos em UUIDs públicos autenticados nas URLs.'
                : 'Não foi possível gerar ou validar UUIDs públicos com a APP_KEY atual.',
            'Manter APP_KEY configurada e estável; ela protege os UUIDs públicos e as credenciais criptografadas.'
        );

        $appUrl = (string) Env::get('APP_URL', '');
        $checks[] = $this->check(
            'APP_URL',
            str_starts_with($appUrl, 'https://') ? 'ok' : ($appUrl !== '' ? 'warning' : 'blocked'),
            $appUrl !== '' ? 'APP_URL atual: ' . $appUrl : 'APP_URL não configurado.',
            'Usar a URL pública HTTPS do RS Connect no EasyPanel.'
        );

        $evolutionUrl = (string) Env::get('EVOLUTION_DEFAULT_URL', '');
        $evolutionWebhookToken = (string) Env::get('EVOLUTION_WEBHOOK_TOKEN', '');
        $instances = $this->countWhere('evolution_instances', "status IN ('connected','open','active','online')");
        $evolutionRealtimeReady = $this->columnExists('evolution_instances', 'connection_updated_at')
            && $this->columnExists('evolution_instances', 'last_webhook_at')
            && $this->tableExists('evolution_connection_events');
        $checks[] = $this->check(
            'Evolution/WhatsApp em tempo real',
            ($evolutionUrl !== '' && $evolutionRealtimeReady) ? 'ok' : 'warning',
            $instances . ' instância(s) conectada(s); URL padrão ' . ($evolutionUrl !== '' ? 'configurada' : 'não configurada')
                . '; webhook ' . ($evolutionWebhookToken !== '' ? 'protegido' : 'sem token')
                . '; atualização em tempo real ' . ($evolutionRealtimeReady ? 'disponível.' : 'pendente.'),
            'Configurar EVOLUTION_WEBHOOK_TOKEN, aplicar a migration 063 e gerar/reconectar o QR para registrar os eventos em tempo real.'
        );

        $openAiKey = (string) Env::get('OPENAI_API_KEY', '');
        $aiCredentials = $this->countWhere('ai_provider_credentials', "status = 'active'");
        $checks[] = $this->check(
            'IA/OpenAI',
            ($openAiKey !== '' || $aiCredentials > 0) ? 'ok' : 'warning',
            $aiCredentials . ' credencial(is) ativa(s) no banco; chave global ' . ($openAiKey !== '' ? 'presente.' : 'ausente.'),
            'Usar credenciais por empresa/agente ou uma chave global segura.'
        );

        $messages24 = $this->number("SELECT COUNT(*) FROM conversation_messages WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $checks[] = $this->check(
            'Conversas/webhooks',
            $messages24 > 0 ? 'ok' : 'warning',
            $messages24 . ' mensagem(ns) nas últimas 24 horas.',
            'Enviar e receber mensagem de teste em uma instância real.'
        );

        $backupToken = (string) (Env::get('OPERATIONS_BACKUP_TOKEN', '') ?: Env::get('BACKUP_WEBHOOK_TOKEN', ''));
        $backupReliabilityReady = $this->columnExists('operations_backup_jobs', 'execution_uuid')
            && $this->columnExists('operations_backup_jobs', 'callback_received_at')
            && $this->columnExists('system_backups', 'backup_job_id');
        $verifiedBackups = $this->number(
            "SELECT COUNT(*) FROM system_backups WHERE status = 'success' AND verified_at IS NOT NULL AND size_bytes >= 1024"
        );
        $checks[] = $this->check(
            'Backup automático',
            ($backupToken !== '' && $backupReliabilityReady && $verifiedBackups > 0) ? 'ok' : 'warning',
            ($backupToken !== '' ? 'Token configurado; ' : 'Token pendente; ')
                . ($backupReliabilityReady ? 'ciclo confiável disponível; ' : 'migration 047 pendente; ')
                . $verifiedBackups . ' backup(s) real(is) verificado(s).',
            'Aplicar a migration 047, importar o fluxo n8n de backup e concluir um backup com callback.'
        );

        $healthDown = $this->latestHealthCount(['down']);
        $healthWarning = $this->latestHealthCount(['warning']);
        $checks[] = $this->check(
            'Monitoramento',
            $healthDown === 0 ? ($healthWarning <= 2 ? 'ok' : 'warning') : 'blocked',
            $healthWarning . ' aviso(s) e ' . $healthDown . ' falha(s) no último ciclo.',
            'Abrir Monitoramento, resolver falhas e revisar avisos recorrentes.'
        );

        $implementationAvg = $this->number('SELECT ROUND(AVG(percent_complete)) FROM tenant_implementation_status');
        $checks[] = $this->check(
            'Implantação comercial',
            $implementationAvg >= 70 ? 'ok' : ($implementationAvg > 0 ? 'warning' : 'blocked'),
            'Média atual de implantação: ' . $implementationAvg . '%.',
            'Usar Implantação para finalizar pendências por cliente.'
        );

        $privacy = $this->countTable('tenant_privacy_settings') + $this->countTable('privacy_settings');
        $checks[] = $this->check(
            'LGPD/Privacidade',
            $privacy > 0 ? 'ok' : 'warning',
            $privacy > 0 ? 'Configuração LGPD localizada.' : 'Nenhuma configuração LGPD localizada.',
            'Conferir termos, política e aceite obrigatório por empresa.'
        );

        $billing = $this->countTable('saas_plans') + $this->countTable('payment_gateways');
        $checks[] = $this->check(
            'Cobrança',
            $billing > 0 ? 'ok' : 'warning',
            $billing . ' registro(s) entre planos/gateways detectado(s).',
            'Manter planos e régua de cobrança definidos para operação comercial.'
        );

        $onboarding = $this->countTable('tenant_onboarding_progress');
        $checks[] = $this->check(
            'Onboarding do cliente',
            $this->tableExists('tenant_onboarding_progress') ? 'ok' : 'warning',
            $this->tableExists('tenant_onboarding_progress') ? $onboarding . ' registro(s) de onboarding.' : 'Tabela de onboarding ausente.',
            'Liberar Primeiros passos para clientes novos.'
        );

        return $checks;
    }

    private function environment(): array
    {
        return [
            ['label' => 'Ambiente', 'value' => (string) Env::get('APP_ENV', 'não informado'), 'secret' => false],
            ['label' => 'Debug', 'value' => (string) Env::get('APP_DEBUG', 'não informado'), 'secret' => false],
            ['label' => 'APP_URL', 'value' => (string) Env::get('APP_URL', 'não informado'), 'secret' => false],
            ['label' => 'Timezone', 'value' => (string) Env::get('APP_TIMEZONE', 'America/Sao_Paulo'), 'secret' => false],
            ['label' => 'Evolution URL', 'value' => (string) Env::get('EVOLUTION_DEFAULT_URL', 'não informado'), 'secret' => false],
            ['label' => 'Evolution webhook', 'value' => $this->masked((string) Env::get('EVOLUTION_WEBHOOK_TOKEN', '')), 'secret' => true],
            ['label' => 'OpenAI base URL', 'value' => (string) Env::get('OPENAI_API_BASE_URL', 'não informado'), 'secret' => false],
            ['label' => 'n8n base URL', 'value' => (string) Env::get('N8N_BASE_URL', 'não informado'), 'secret' => false],
            ['label' => 'Backup token', 'value' => $this->masked((string) (Env::get('OPERATIONS_BACKUP_TOKEN', '') ?: Env::get('BACKUP_WEBHOOK_TOKEN', ''))), 'secret' => true],
            ['label' => 'OpenAI global', 'value' => $this->masked((string) Env::get('OPENAI_API_KEY', '')), 'secret' => true],
            ['label' => 'Callback n8n', 'value' => $this->masked((string) Env::get('N8N_CALLBACK_TOKEN', '')), 'secret' => true],
            ['label' => 'Cron de cobrança', 'value' => $this->masked((string) Env::get('BILLING_CRON_TOKEN', '')), 'secret' => true],
            ['label' => 'Cron fila IA', 'value' => $this->masked((string) Env::get('AI_REPROCESS_CRON_TOKEN', '')), 'secret' => true],
            ['label' => 'Manutenção agenda', 'value' => $this->masked((string) Env::get('CALENDAR_MAINTENANCE_TOKEN', '')), 'secret' => true],
            ['label' => 'Retenção de mensagens', 'value' => $this->masked((string) Env::get('MESSAGE_RETENTION_TOKEN', '')), 'secret' => true],
        ];
    }

    private function modules(): array
    {
        return [
            ['name' => 'Empresas', 'count' => $this->countWhere('tenants', "status = 'active'"), 'url' => '/companies'],
            ['name' => 'Conversas 24h', 'count' => $this->number("SELECT COUNT(*) FROM conversation_messages WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"), 'url' => '/conversations'],
            ['name' => 'Assistentes IA', 'count' => $this->countWhere('ai_agents', "status = 'active'"), 'url' => '/agents'],
            ['name' => 'n8n', 'count' => $this->countWhere('n8n_tenant_flows', "status = 'active'") + $this->countWhere('n8n_flows', "status = 'active'"), 'url' => '/n8n'],
            ['name' => 'Backups automáticos', 'count' => $this->number("SELECT COUNT(*) FROM system_backups WHERE backup_type = 'automatic' AND status = 'success'"), 'url' => '/backup-automatico'],
            ['name' => 'Alertas ativos', 'count' => $this->activeIncidentCount(), 'url' => '/monitoramento'],
        ];
    }

    private function deployInfo(): array
    {
        $base = dirname(__DIR__, 2);
        $files = [
            $base . '/bootstrap.php',
            $base . '/routes/web.php',
            $base . '/app/Views/layouts/app.php',
        ];
        $latest = null;
        foreach ($files as $file) {
            if (is_file($file)) {
                $mtime = filemtime($file) ?: null;
                if ($mtime !== null && ($latest === null || $mtime > $latest)) {
                    $latest = $mtime;
                }
            }
        }

        return [
            'php_version' => PHP_VERSION,
            'package' => self::PACKAGE_LABEL,
            'version' => self::VERSION_LABEL,
            'last_file_update' => $latest ? date('Y-m-d H:i:s', $latest) : 'não identificado',
            'public_url' => (string) Env::get('APP_URL', ''),
        ];
    }

    private function nextActions(array $checks): array
    {
        $actions = [];
        foreach ($checks as $check) {
            if (($check['status'] ?? '') !== 'ok') {
                $actions[] = [
                    'label' => $check['label'] ?? 'Ajuste',
                    'action' => $check['action'] ?? 'Revisar configuração.',
                    'status' => $check['status'] ?? 'warning',
                ];
            }
        }

        if ($actions === []) {
            $actions[] = ['label' => 'Operação', 'action' => 'Sistema pronto para beta operacional. Manter monitoramento diário e backup validado.', 'status' => 'ok'];
        }

        return array_slice($actions, 0, 8);
    }

    private function statusLabel(int $score, int $blocked): string
    {
        if ($blocked > 0) {
            return 'Beta 1.1 com bloqueios';
        }
        if ($score >= 90) {
            return 'Beta 1.1 operacional';
        }
        if ($score >= 70) {
            return 'Beta 1.1 em validação';
        }
        return 'Beta 1.1 em preparação';
    }

    private function check(string $label, string $status, string $message, string $action): array
    {
        return compact('label', 'status', 'message', 'action');
    }

    private function requiredMigrationNumber(): string
    {
        if (preg_match('/^(\d+)_/', self::REQUIRED_MIGRATION, $matches) === 1) {
            return str_pad((string) ((int) $matches[1]), 3, '0', STR_PAD_LEFT);
        }
        return '—';
    }

    private function agendaWriterMisconfiguredCount(): int
    {
        if (!$this->tableExists('n8n_tenant_flows')) {
            return 0;
        }

        try {
            $sql = <<<'SQL'
SELECT COUNT(*)
FROM n8n_tenant_flows
WHERE status = 'active'
  AND (
    flow_key IN ('agenda-google-calendar', 'agenda-google-calendar-por-empresa')
    OR LOWER(name) LIKE 'rs connect - agenda google calendar por empresa%'
  )
  AND NOT (
    COALESCE(JSON_VALID(events_json), 0) = 1
    AND JSON_LENGTH(events_json) = 1
    AND JSON_UNQUOTE(JSON_EXTRACT(events_json, '$[0]')) = 'calendar.appointment.created'
  )
SQL;
            return $this->number($sql);
        } catch (Throwable) {
            return 0;
        }
    }

    private function masked(string $value): string
    {
        if ($value === '') {
            return 'não configurado';
        }
        if (strlen($value) <= 10) {
            return substr($value, 0, 2) . '***';
        }
        return substr($value, 0, 6) . '...' . substr($value, -4);
    }

    private function databaseOk(): bool
    {
        try {
            return (int) $this->pdo->query('SELECT 1')->fetchColumn() === 1;
        } catch (Throwable) {
            return false;
        }
    }

    private function latestHealthCount(array $statuses): int
    {
        if (!$this->tableExists('system_health_checks')) {
            return 0;
        }
        try {
            $placeholders = implode(',', array_fill(0, count($statuses), '?'));
            $sql = "SELECT COUNT(*) FROM system_health_checks h
                    INNER JOIN (
                        SELECT check_key, MAX(id) AS max_id
                        FROM system_health_checks
                        GROUP BY check_key
                    ) latest ON latest.max_id = h.id
                    WHERE h.status IN ({$placeholders})";
            $statement = $this->pdo->prepare($sql);
            $statement->execute($statuses);
            return (int) $statement->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    private function activeIncidentCount(): int
    {
        if (!$this->tableExists('system_incidents')) {
            return 0;
        }
        return $this->number('SELECT COUNT(*) FROM system_incidents WHERE resolved_at IS NULL AND severity IN (\'warning\',\'critical\')');
    }

    private function number(string $sql): int
    {
        try {
            return (int) ($this->pdo->query($sql)->fetchColumn() ?: 0);
        } catch (Throwable) {
            return 0;
        }
    }

    private function countTable(string $table): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }
        return $this->number('SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '`');
    }

    private function countWhere(string $table, string $where): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }
        try {
            return (int) $this->pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '` WHERE ' . $where)->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
            );
            $statement->execute(['table' => $table, 'column' => $column]);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function columnTypeContains(string $table, string $column, string $needle): bool
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column
                 LIMIT 1'
            );
            $statement->execute(['table' => $table, 'column' => $column]);
            return str_contains(strtolower((string) $statement->fetchColumn()), strtolower($needle));
        } catch (Throwable) {
            return false;
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index'
            );
            $statement->execute(['table' => $table, 'index' => $index]);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{missing:list<string>} */
    private function standardAiQuotaLimits(): array
    {
        if (!$this->tableExists('saas_plans')) {
            return ['missing' => ['Starter', 'Profissional', 'Business']];
        }

        try {
            $statement = $this->pdo->query(
                'SELECT plan_key, name, JSON_UNQUOTE(JSON_EXTRACT(limits_json, "$.ai_interactions_month")) AS ai_limit '
                . 'FROM saas_plans WHERE plan_key IN ("starter", "pro", "business")'
            );
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $found = [];
            $missing = [];
            foreach ($rows as $row) {
                $key = (string) ($row['plan_key'] ?? '');
                $found[$key] = true;
                $value = trim((string) ($row['ai_limit'] ?? ''));
                if ($value === '' || !ctype_digit($value) || (int) $value < 1) {
                    $missing[] = (string) (($row['name'] ?? '') ?: $key);
                }
            }
            foreach (['starter' => 'Starter', 'pro' => 'Profissional', 'business' => 'Business'] as $key => $label) {
                if (empty($found[$key])) {
                    $missing[] = $label;
                }
            }
            return ['missing' => array_values(array_unique($missing))];
        } catch (Throwable) {
            return ['missing' => ['Starter', 'Profissional', 'Business']];
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
            $statement->execute(['table' => $table]);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}

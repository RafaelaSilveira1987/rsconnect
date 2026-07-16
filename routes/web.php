<?php

declare(strict_types=1);

use App\Controllers\AgentController;
use App\Controllers\AiCredentialController;
use App\Controllers\AutomationController;
use App\Controllers\BillingController;
use App\Controllers\AuthController;
use App\Controllers\BackupAutomationController;
use App\Controllers\CalendarController;
use App\Controllers\CalendarAvailabilityController;
use App\Controllers\CompanyController;
use App\Controllers\ContactController;
use App\Controllers\CrmController;
use App\Controllers\ConversationController;
use App\Controllers\EvolutionWebhookController;
use App\Controllers\DashboardController;
use App\Controllers\DocumentationController;
use App\Controllers\InstanceController;
use App\Controllers\ImplementationController;
use App\Controllers\OnboardingController;
use App\Controllers\N8nFlowController;
use App\Controllers\N8nTemplateController;
use App\Controllers\NotificationsController;
use App\Controllers\OperationsController;
use App\Controllers\PaymentGatewayController;
use App\Controllers\PrivacyController;
use App\Controllers\ReportController;
use App\Controllers\SecurityController;
use App\Controllers\BillingReminderController;
use App\Controllers\PermissionController;
use App\Controllers\TaskController;
use App\Controllers\UserController;
use App\Controllers\VersionController;
use App\Core\Router;

return static function (Router $router): void {
    $router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
    $router->post('/login', [AuthController::class, 'login'], ['guest', 'csrf']);
    $router->post('/logout', [AuthController::class, 'logout'], ['auth', 'csrf']);

    $router->get('/', [DashboardController::class, 'index'], ['auth']);
    $router->get('/ajuda', [DocumentationController::class, 'index'], ['auth']);
    $router->get('/central-ajuda', [DocumentationController::class, 'index'], ['auth']);
    $router->get('/docs', [DocumentationController::class, 'index'], ['auth']);
    $router->get('/beta-comercial', [DocumentationController::class, 'beta'], ['auth', 'super_admin']);
    $router->get('/versao-beta', [DocumentationController::class, 'beta'], ['auth', 'super_admin']);
    $router->get('/status-sistema', [VersionController::class, 'index'], ['auth', 'super_admin']);
    $router->get('/versao-sistema', [VersionController::class, 'index'], ['auth', 'super_admin']);
    $router->get('/diagnostico', [VersionController::class, 'index'], ['auth', 'super_admin']);
    $router->get('/beta-1', [VersionController::class, 'index'], ['auth', 'super_admin']);

    $router->post('/webhooks/evolution', [EvolutionWebhookController::class, 'handle']);
    $router->post('/webhooks/n8n/callback', [N8nTemplateController::class, 'callback']);

    $router->get('/conversations', [ConversationController::class, 'index'], ['auth', 'permission:conversations.view']);
    $router->get('/conversations/poll', [ConversationController::class, 'poll'], ['auth', 'permission:conversations.view']);
    $router->post('/conversations/start', [ConversationController::class, 'start'], ['auth', 'permission:conversations.manage', 'csrf']);
    $router->post('/conversations/send', [ConversationController::class, 'send'], ['auth', 'permission:conversations.manage', 'csrf']);
    $router->post('/conversations/mode', [ConversationController::class, 'setMode'], ['auth', 'permission:conversations.manage', 'csrf']);
    $router->post('/conversations/status', [ConversationController::class, 'updateStatus'], ['auth', 'permission:conversations.manage', 'csrf']);
    $router->post('/conversations/contact', [ConversationController::class, 'updateContact'], ['auth', 'permission:conversations.manage', 'csrf']);
    $router->post('/conversations/suggest', [ConversationController::class, 'suggest'], ['auth', 'permission:conversations.manage', 'csrf']);
    $router->post('/conversations/reprocess-ai', [ConversationController::class, 'reprocessAi'], ['auth', 'permission:conversations.manage', 'csrf']);
    $router->post('/conversations/mark-read', [ConversationController::class, 'markRead'], ['auth', 'permission:conversations.manage', 'csrf']);
    $router->post('/conversations/delete', [ConversationController::class, 'delete'], ['auth', 'permission:conversations.manage', 'csrf']);


    $router->get('/contacts', [ContactController::class, 'index'], ['auth', 'permission:contacts.view']);
    $router->post('/contacts', [ContactController::class, 'store'], ['auth', 'permission:contacts.manage', 'csrf']);
    $router->post('/contacts/update', [ContactController::class, 'update'], ['auth', 'permission:contacts.manage', 'csrf']);

    $router->get('/crm', [CrmController::class, 'index'], ['auth', 'permission:crm.view']);
    $router->post('/crm/leads', [CrmController::class, 'store'], ['auth', 'permission:crm.manage', 'csrf']);
    $router->post('/crm/leads/update', [CrmController::class, 'update'], ['auth', 'permission:crm.manage', 'csrf']);
    $router->post('/crm/leads/move', [CrmController::class, 'move'], ['auth', 'permission:crm.manage', 'csrf']);
    $router->post('/crm/notes', [CrmController::class, 'addNote'], ['auth', 'permission:crm.manage', 'csrf']);

    $router->get('/tasks', [TaskController::class, 'index'], ['auth', 'permission:tasks.view']);


    $router->get('/reports', [ReportController::class, 'index'], ['auth', 'permission:reports.view']);
    $router->get('/reports/export', [ReportController::class, 'export'], ['auth', 'permission:reports.view']);

    $router->get('/security', [SecurityController::class, 'index'], ['auth', 'super_admin']);
    $router->get('/seguranca', [SecurityController::class, 'index'], ['auth', 'super_admin']);
    $router->get('/implementation', [ImplementationController::class, 'index'], ['auth', 'super_admin']);
    $router->get('/implantacao', [ImplementationController::class, 'index'], ['auth', 'super_admin']);
    $router->post('/implementation/refresh', [ImplementationController::class, 'refresh'], ['auth', 'super_admin', 'csrf']);
    $router->post('/implementation/item', [ImplementationController::class, 'updateItem'], ['auth', 'super_admin', 'csrf']);
    $router->get('/operations', [OperationsController::class, 'index'], ['auth', 'super_admin']);
    $router->get('/monitoramento', [OperationsController::class, 'index'], ['auth', 'super_admin']);
    $router->post('/operations/checks/run', [OperationsController::class, 'runHealthChecks'], ['auth', 'super_admin', 'csrf']);
    $router->post('/operations/backups/register', [OperationsController::class, 'registerBackup'], ['auth', 'super_admin', 'csrf']);
    $router->post('/operations/incidents/resolve', [OperationsController::class, 'resolveIncident'], ['auth', 'super_admin', 'csrf']);
    $router->get('/operations/backups/automation', [BackupAutomationController::class, 'index'], ['auth', 'super_admin']);
    $router->get('/backup-automatico', [BackupAutomationController::class, 'index'], ['auth', 'super_admin']);
    $router->get('/backups/automatico', [BackupAutomationController::class, 'index'], ['auth', 'super_admin']);
    $router->get('/backups/automation', [BackupAutomationController::class, 'index'], ['auth', 'super_admin']);
    $router->get('/monitoramento/backups/automatico', [BackupAutomationController::class, 'index'], ['auth', 'super_admin']);
    $router->post('/operations/backups/automation/save', [BackupAutomationController::class, 'save'], ['auth', 'super_admin', 'csrf']);
    $router->post('/operations/backups/automation/trigger', [BackupAutomationController::class, 'trigger'], ['auth', 'super_admin', 'csrf']);
    $router->post('/operations/backups/automation/toggle', [BackupAutomationController::class, 'toggle'], ['auth', 'super_admin', 'csrf']);
    $router->post('/backup-automatico/save', [BackupAutomationController::class, 'save'], ['auth', 'super_admin', 'csrf']);
    $router->post('/backup-automatico/trigger', [BackupAutomationController::class, 'trigger'], ['auth', 'super_admin', 'csrf']);
    $router->post('/backup-automatico/toggle', [BackupAutomationController::class, 'toggle'], ['auth', 'super_admin', 'csrf']);
    $router->post('/webhooks/operations/backups', [OperationsController::class, 'runBackupHook']);
    $router->get('/webhooks/operations/backups', [OperationsController::class, 'runBackupHook']);

    $router->get('/privacy/accept', [PrivacyController::class, 'accept'], ['auth']);
    $router->post('/privacy/accept', [PrivacyController::class, 'acceptStore'], ['auth', 'csrf']);
    $router->get('/privacy', [PrivacyController::class, 'index'], ['auth', 'permission:privacy.view']);
    $router->post('/privacy/settings/save', [PrivacyController::class, 'saveSettings'], ['auth', 'permission:privacy.manage', 'csrf']);
    $router->post('/privacy/requests/create', [PrivacyController::class, 'createRequest'], ['auth', 'permission:privacy.manage', 'csrf']);
    $router->post('/privacy/requests/update', [PrivacyController::class, 'updateRequest'], ['auth', 'permission:privacy.manage', 'csrf']);
    $router->get('/privacy/export-contact', [PrivacyController::class, 'exportContact'], ['auth', 'permission:privacy.view']);
    $router->get('/lgpd', [PrivacyController::class, 'index'], ['auth', 'permission:privacy.view']);
    $router->get('/privacidade', [PrivacyController::class, 'index'], ['auth', 'permission:privacy.view']);

    $router->post('/security/sessions/revoke', [SecurityController::class, 'revokeSession'], ['auth', 'super_admin', 'csrf']);

    $router->get('/notifications', [NotificationsController::class, 'index'], ['auth', 'permission:notifications.view']);
    $router->get('/notifications/count', [NotificationsController::class, 'count'], ['auth', 'permission:notifications.view']);
    $router->post('/notifications/preferences', [NotificationsController::class, 'savePreferences'], ['auth', 'permission:notifications.manage', 'csrf']);
    $router->post('/notifications/read-all', [NotificationsController::class, 'markAllRead'], ['auth', 'permission:notifications.view', 'csrf']);

    $router->get('/agenda-inteligente', [CalendarController::class, 'availability'], ['auth', 'permission:calendar.view']);
    $router->get('/agenda-disponibilidade', [CalendarController::class, 'availability'], ['auth', 'permission:calendar.view']);
    $router->get('/calendar/availability', [CalendarController::class, 'availability'], ['auth', 'permission:calendar.view']);
    $router->post('/calendar/availability/settings', [CalendarAvailabilityController::class, 'saveSettings'], ['auth', 'permission:calendar.manage', 'csrf']);
    $router->post('/calendar/availability/request', [CalendarAvailabilityController::class, 'request'], ['auth', 'permission:calendar.manage', 'csrf']);
    $router->post('/calendar/availability/apply', [CalendarAvailabilityController::class, 'applySlot'], ['auth', 'permission:calendar.manage', 'csrf']);
    $router->post('/calendar/availability/release', [CalendarAvailabilityController::class, 'releaseSlot'], ['auth', 'permission:calendar.manage', 'csrf']);
    $router->post('/webhooks/calendar/availability', [CalendarAvailabilityController::class, 'callback']);
    $router->get('/webhooks/calendar/availability', [CalendarAvailabilityController::class, 'callback']);
    $router->get('/calendar', [CalendarController::class, 'index'], ['auth', 'permission:calendar.view']);
    $router->post('/calendar/appointments', [CalendarController::class, 'store'], ['auth', 'permission:calendar.manage', 'csrf']);
    $router->post('/calendar/status', [CalendarController::class, 'updateStatus'], ['auth', 'permission:calendar.manage', 'csrf']);
    $router->post('/calendar/delete', [CalendarController::class, 'delete'], ['auth', 'permission:calendar.manage', 'csrf']);
    $router->get('/calendar/ics', [CalendarController::class, 'ics'], ['auth', 'permission:calendar.view']);
    $router->post('/tasks', [TaskController::class, 'store'], ['auth', 'permission:tasks.manage', 'csrf']);
    $router->post('/tasks/status', [TaskController::class, 'updateStatus'], ['auth', 'permission:tasks.manage', 'csrf']);

    $router->get('/companies', [CompanyController::class, 'index'], ['auth', 'super_admin']);
    $router->post('/companies', [CompanyController::class, 'store'], ['auth', 'super_admin', 'csrf']);
    $router->post('/companies/status', [CompanyController::class, 'updateStatus'], ['auth', 'super_admin', 'csrf']);
    $router->get('/company-settings', [CompanyController::class, 'settings'], ['auth', 'permission:company.view']);
    $router->post('/company-settings', [CompanyController::class, 'updateSettings'], ['auth', 'permission:company.manage', 'csrf']);

    $router->get('/users', [UserController::class, 'index'], ['auth', 'permission:users.view']);
    $router->post('/users', [UserController::class, 'store'], ['auth', 'permission:users.manage', 'csrf']);
    $router->post('/users/update', [UserController::class, 'update'], ['auth', 'permission:users.manage', 'csrf']);

    $router->get('/permissions', [PermissionController::class, 'index'], ['auth', 'permission:permissions.view']);
    $router->post('/permissions', [PermissionController::class, 'update'], ['auth', 'super_admin', 'csrf']);

    $router->get('/onboarding', [OnboardingController::class, 'index'], ['auth', 'permission:onboarding.manage']);
    $router->get('/primeiros-passos', [OnboardingController::class, 'index'], ['auth', 'permission:onboarding.manage']);
    $router->post('/onboarding/company', [OnboardingController::class, 'saveCompany'], ['auth', 'permission:onboarding.manage', 'csrf']);
    $router->post('/onboarding/instance', [OnboardingController::class, 'saveInstance'], ['auth', 'permission:onboarding.manage', 'csrf']);
    $router->post('/onboarding/agent', [OnboardingController::class, 'saveAgent'], ['auth', 'permission:onboarding.manage', 'csrf']);
    $router->post('/onboarding/step', [OnboardingController::class, 'updateStep'], ['auth', 'permission:onboarding.manage', 'csrf']);
    $router->post('/onboarding/attendance', [OnboardingController::class, 'saveAttendance'], ['auth', 'permission:onboarding.manage', 'csrf']);
    $router->post('/onboarding/agenda', [OnboardingController::class, 'saveAgenda'], ['auth', 'permission:onboarding.manage', 'csrf']);
    $router->post('/onboarding/final-test', [OnboardingController::class, 'finish'], ['auth', 'permission:onboarding.manage', 'csrf']);

    $router->get('/instances', [InstanceController::class, 'index'], ['auth', 'permission:instances.view']);
    $router->post('/instances', [InstanceController::class, 'store'], ['auth', 'super_admin', 'csrf']);
    $router->post('/instances/qr', [InstanceController::class, 'qrCode'], ['auth', 'permission:instances.manage', 'csrf']);
    $router->post('/instances/test', [InstanceController::class, 'sendTest'], ['auth', 'super_admin', 'csrf']);
    $router->post('/instances/update', [InstanceController::class, 'update'], ['auth', 'super_admin', 'csrf']);
    $router->post('/instances/agent-update', [InstanceController::class, 'updateAgent'], ['auth', 'super_admin', 'csrf']);
    $router->post('/instances/delete', [InstanceController::class, 'delete'], ['auth', 'super_admin', 'csrf']);

    $router->get('/agents', [AgentController::class, 'index'], ['auth', 'permission:agents.view']);
    $router->get('/ai-credentials', [AiCredentialController::class, 'index'], ['auth', 'super_admin']);
    $router->post('/ai-credentials/save', [AiCredentialController::class, 'save'], ['auth', 'super_admin', 'csrf']);
    $router->post('/agents', [AgentController::class, 'store'], ['auth', 'permission:agents.manage', 'csrf']);
    $router->post('/agents/status', [AgentController::class, 'updateStatus'], ['auth', 'permission:agents.manage', 'csrf']);
    $router->post('/agents/prompt', [AgentController::class, 'updatePrompt'], ['auth', 'permission:agents.manage', 'csrf']);
    $router->get('/automations', [AutomationController::class, 'index'], ['auth', 'permission:automations.view']);

    $router->get('/billing', [BillingController::class, 'index'], ['auth', 'super_admin']);
    $router->get('/subscription', [BillingController::class, 'subscription'], ['auth', 'permission:billing.view']);
    $router->post('/billing/plans/save', [BillingController::class, 'savePlan'], ['auth', 'super_admin', 'csrf']);
    $router->post('/billing/subscriptions/save', [BillingController::class, 'saveSubscription'], ['auth', 'super_admin', 'csrf']);
    $router->post('/billing/invoices/create', [BillingController::class, 'createInvoice'], ['auth', 'super_admin', 'csrf']);
    $router->post('/billing/invoices/status', [BillingController::class, 'updateInvoice'], ['auth', 'super_admin', 'csrf']);

    $router->get('/payment-gateways', [PaymentGatewayController::class, 'index'], ['auth', 'super_admin']);
    $router->get('/billing-reminders', [BillingReminderController::class, 'index'], ['auth', 'super_admin']);
    $router->post('/payment-gateways/save', [PaymentGatewayController::class, 'save'], ['auth', 'super_admin', 'csrf']);
    $router->post('/billing-reminders/rules/save', [BillingReminderController::class, 'saveRule'], ['auth', 'super_admin', 'csrf']);
    $router->post('/billing-reminders/run', [BillingReminderController::class, 'run'], ['auth', 'super_admin', 'csrf']);
    $router->post('/payment-gateways/invoices/create-link', [PaymentGatewayController::class, 'createInvoiceLink'], ['auth', 'super_admin', 'csrf']);
    $router->post('/webhooks/payments/asaas', [PaymentGatewayController::class, 'webhookAsaas']);
    $router->post('/webhooks/payments/mercadopago', [PaymentGatewayController::class, 'webhookMercadoPago']);
    $router->post('/webhooks/payments/stripe', [PaymentGatewayController::class, 'webhookStripe']);
    $router->post('/webhooks/payments/pagbank', [PaymentGatewayController::class, 'webhookPagBank']);
    $router->post('/webhooks/billing/reminders/run', [BillingReminderController::class, 'cron']);
    $router->get('/webhooks/billing/reminders/run', [BillingReminderController::class, 'cron']);

    $router->get('/n8n-flows', [N8nFlowController::class, 'index'], ['auth', 'super_admin']);
    $router->get('/n8n-templates', [N8nTemplateController::class, 'index'], ['auth', 'super_admin']);
    $router->get('/n8n-templates/download', [N8nTemplateController::class, 'download'], ['auth', 'super_admin']);
    $router->post('/n8n-flows/save', [N8nFlowController::class, 'save'], ['auth', 'super_admin', 'csrf']);
    $router->post('/n8n-flows/test', [N8nFlowController::class, 'test'], ['auth', 'super_admin', 'csrf']);
};

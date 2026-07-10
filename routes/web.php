<?php

declare(strict_types=1);

use App\Controllers\AgentController;
use App\Controllers\AiCredentialController;
use App\Controllers\AutomationController;
use App\Controllers\AuthController;
use App\Controllers\CalendarController;
use App\Controllers\CompanyController;
use App\Controllers\ContactController;
use App\Controllers\CrmController;
use App\Controllers\ConversationController;
use App\Controllers\EvolutionWebhookController;
use App\Controllers\DashboardController;
use App\Controllers\InstanceController;
use App\Controllers\OnboardingController;
use App\Controllers\N8nFlowController;
use App\Controllers\N8nTemplateController;
use App\Controllers\PermissionController;
use App\Controllers\TaskController;
use App\Controllers\UserController;
use App\Core\Router;

return static function (Router $router): void {
    $router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
    $router->post('/login', [AuthController::class, 'login'], ['guest', 'csrf']);
    $router->post('/logout', [AuthController::class, 'logout'], ['auth', 'csrf']);

    $router->get('/', [DashboardController::class, 'index'], ['auth']);

    $router->post('/webhooks/evolution', [EvolutionWebhookController::class, 'handle']);
    $router->post('/webhooks/n8n/callback', [N8nTemplateController::class, 'callback']);

    $router->get('/conversations', [ConversationController::class, 'index'], ['auth', 'permission:conversations.view']);
    $router->post('/conversations/start', [ConversationController::class, 'start'], ['auth', 'permission:conversations.manage', 'csrf']);
    $router->post('/conversations/send', [ConversationController::class, 'send'], ['auth', 'permission:conversations.manage', 'csrf']);
    $router->post('/conversations/mode', [ConversationController::class, 'setMode'], ['auth', 'permission:conversations.manage', 'csrf']);
    $router->post('/conversations/status', [ConversationController::class, 'updateStatus'], ['auth', 'permission:conversations.manage', 'csrf']);
    $router->post('/conversations/contact', [ConversationController::class, 'updateContact'], ['auth', 'permission:conversations.manage', 'csrf']);
    $router->post('/conversations/suggest', [ConversationController::class, 'suggest'], ['auth', 'permission:conversations.manage', 'csrf']);
    $router->post('/conversations/reprocess-ai', [ConversationController::class, 'reprocessAi'], ['auth', 'permission:conversations.manage', 'csrf']);


    $router->get('/contacts', [ContactController::class, 'index'], ['auth', 'permission:contacts.view']);
    $router->post('/contacts', [ContactController::class, 'store'], ['auth', 'permission:contacts.manage', 'csrf']);
    $router->post('/contacts/update', [ContactController::class, 'update'], ['auth', 'permission:contacts.manage', 'csrf']);

    $router->get('/crm', [CrmController::class, 'index'], ['auth', 'permission:crm.view']);
    $router->post('/crm/leads', [CrmController::class, 'store'], ['auth', 'permission:crm.manage', 'csrf']);
    $router->post('/crm/leads/update', [CrmController::class, 'update'], ['auth', 'permission:crm.manage', 'csrf']);
    $router->post('/crm/leads/move', [CrmController::class, 'move'], ['auth', 'permission:crm.manage', 'csrf']);
    $router->post('/crm/notes', [CrmController::class, 'addNote'], ['auth', 'permission:crm.manage', 'csrf']);

    $router->get('/tasks', [TaskController::class, 'index'], ['auth', 'permission:tasks.view']);

    $router->get('/calendar', [CalendarController::class, 'index'], ['auth', 'permission:calendar.view']);
    $router->post('/calendar/appointments', [CalendarController::class, 'store'], ['auth', 'permission:calendar.manage', 'csrf']);
    $router->post('/calendar/status', [CalendarController::class, 'updateStatus'], ['auth', 'permission:calendar.manage', 'csrf']);
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
    $router->post('/onboarding/company', [OnboardingController::class, 'saveCompany'], ['auth', 'permission:onboarding.manage', 'csrf']);
    $router->post('/onboarding/instance', [OnboardingController::class, 'saveInstance'], ['auth', 'permission:onboarding.manage', 'csrf']);
    $router->post('/onboarding/agent', [OnboardingController::class, 'saveAgent'], ['auth', 'permission:onboarding.manage', 'csrf']);

    $router->get('/instances', [InstanceController::class, 'index'], ['auth', 'permission:instances.view']);
    $router->post('/instances', [InstanceController::class, 'store'], ['auth', 'permission:instances.manage', 'csrf']);
    $router->post('/instances/test', [InstanceController::class, 'sendTest'], ['auth', 'permission:instances.manage', 'csrf']);

    $router->get('/agents', [AgentController::class, 'index'], ['auth', 'permission:agents.view']);
    $router->get('/ai-credentials', [AiCredentialController::class, 'index'], ['auth', 'super_admin']);
    $router->post('/ai-credentials/save', [AiCredentialController::class, 'save'], ['auth', 'super_admin', 'csrf']);
    $router->post('/agents', [AgentController::class, 'store'], ['auth', 'permission:agents.manage', 'csrf']);
    $router->post('/agents/status', [AgentController::class, 'updateStatus'], ['auth', 'permission:agents.manage', 'csrf']);
    $router->get('/automations', [AutomationController::class, 'index'], ['auth', 'permission:automations.view']);

    $router->get('/n8n-flows', [N8nFlowController::class, 'index'], ['auth', 'super_admin']);
    $router->get('/n8n-templates', [N8nTemplateController::class, 'index'], ['auth', 'super_admin']);
    $router->get('/n8n-templates/download', [N8nTemplateController::class, 'download'], ['auth', 'super_admin']);
    $router->post('/n8n-flows/save', [N8nFlowController::class, 'save'], ['auth', 'super_admin', 'csrf']);
    $router->post('/n8n-flows/test', [N8nFlowController::class, 'test'], ['auth', 'super_admin', 'csrf']);
};

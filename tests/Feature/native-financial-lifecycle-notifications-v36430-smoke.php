<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'migration' => (string) file_get_contents($root . '/database/migrations/125_native_financial_lifecycle_notifications.sql'),
    'migration_manifest' => (string) file_get_contents($root . '/database/migrations/manifest.php'),
    'platform' => (string) file_get_contents($root . '/app/Services/PlatformNotificationService.php'),
    'billing' => (string) file_get_contents($root . '/app/Services/BillingReminderService.php'),
    'lifecycle' => (string) file_get_contents($root . '/app/Services/SubscriptionLifecycleReminderService.php'),
    'delivery' => (string) file_get_contents($root . '/app/Services/NotificationDeliveryService.php'),
    'operations' => (string) file_get_contents($root . '/app/Services/OperationsService.php'),
    'security' => (string) file_get_contents($root . '/app/Services/SecurityService.php'),
    'controller' => (string) file_get_contents($root . '/app/Controllers/BillingReminderController.php'),
    'view' => (string) file_get_contents($root . '/app/Views/billing_reminders/index.php'),
    'routes' => (string) file_get_contents($root . '/routes/web.php'),
    'start' => (string) file_get_contents($root . '/bin/rs-connect-start.sh'),
    'worker' => (string) file_get_contents($root . '/bin/process-financial-notifications.php'),
    'compose' => (string) file_get_contents($root . '/docker-compose.yml'),
    'version' => (string) file_get_contents($root . '/app/Services/AppVersionService.php'),
];
$manifest = json_decode((string) file_get_contents($root . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

$failed = 0;
$check = static function (bool $condition, string $label) use (&$failed): void {
    echo ($condition ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
    if (!$condition) $failed++;
};

$check(
    str_contains($files['migration'], "'native_in_app'")
        && str_contains($files['migration'], "'native_whatsapp'")
        && str_contains($files['migration'], "'native_both'")
        && str_contains($files['migration'], 'platform_notification_settings')
        && str_contains($files['migration'], 'subscription_notification_rules')
        && str_contains($files['migration'], 'subscription_notification_logs')
        && str_contains($files['migration_manifest'], '125_native_financial_lifecycle_notifications.sql'),
    'migration 125 cria canais nativos, remetente central e regras de vigência/trial'
);

$check(
    str_contains($files['platform'], 'sender_instance_id')
        && str_contains($files['platform'], 'configuredSenderInstanceId')
        && str_contains($files['platform'], "'max_attempts' => \$channel === 'whatsapp' ? 24 : 4")
        && str_contains($files['platform'], "'source_type'")
        && str_contains($files['delivery'], 'platformSenderInstanceId')
        && str_contains($files['delivery'], 'instanceById')
        && str_contains($files['delivery'], "'billing_reminder_log' => 'billing_reminder_logs'")
        && str_contains($files['delivery'], "'subscription_notification_log' => 'subscription_notification_logs'"),
    'WhatsApp financeiro usa remetente central explícito e sincroniza o histórico de entrega'
);

$check(
    str_contains($files['billing'], "'native_in_app' => 'RS Connect (interno)'")
        && str_contains($files['billing'], "'native_whatsapp' => 'WhatsApp nativo da plataforma'")
        && str_contains($files['billing'], 'PlatformNotificationService::NATIVE_CHANNELS')
        && str_contains($files['billing'], "'billing_reminder_log'")
        && str_contains($files['billing'], "in_array(\$channel, ['n8n', 'whatsapp', 'email'], true)"),
    'régua de cobrança separa entrega nativa de integração externa opcional'
);

$check(
    str_contains($files['lifecycle'], "'current_period_end'")
        && str_contains($files['lifecycle'], "'trial_end'")
        && str_contains($files['lifecycle'], "'trial_grace_end'")
        && str_contains($files['lifecycle'], "'{{fim_vigencia}}'")
        && str_contains($files['lifecycle'], "'{{fim_teste}}'")
        && str_contains($files['lifecycle'], "'{{fim_tolerancia}}'")
        && str_contains($files['lifecycle'], 'days_from_target'),
    'vigência, trial e tolerância são orientados por regras e placeholders configuráveis'
);

$check(
    str_contains($files['start'], 'RS_FINANCIAL_NOTIFICATION_WORKER_ENABLED')
        && str_contains($files['start'], 'RS_FINANCIAL_NOTIFICATION_WORKER_INTERVAL_SECONDS')
        && str_contains($files['start'], 'process-financial-notifications.php')
        && str_contains($files['worker'], "runDueReminders('cron')")
        && str_contains($files['worker'], 'SubscriptionLifecycleReminderService')
        && str_contains($files['compose'], 'RS_FINANCIAL_NOTIFICATION_WORKER_ENABLED')
        && str_contains($files['compose'], 'RS_FINANCIAL_NOTIFICATION_WORKER_INTERVAL_SECONDS'),
    'worker interno processa cobrança e vigência sem depender do n8n'
);

$check(
    str_contains($files['operations'], 'platform_notification_settings')
        && str_contains($files['operations'], 'worker financeiro nativo')
        && str_contains($files['security'], 'O worker nativo continua independente dele.')
        && str_contains($files['security'], 'Este token só é necessário se um sistema externo chamar o endpoint de cron.'),
    'monitor e segurança tratam cron/n8n como opcionais para as regras nativas'
);

$check(
    str_contains($files['controller'], 'savePlatformSettings')
        && str_contains($files['controller'], 'saveLifecycleRule')
        && str_contains($files['routes'], '/billing-reminders/platform-settings/save')
        && str_contains($files['routes'], '/billing-reminders/lifecycle-rules/save')
        && str_contains($files['view'], 'WhatsApp usado pelo RS Connect')
        && str_contains($files['view'], 'Avisos do ciclo da assinatura'),
    'painel permite escolher remetente central e configurar regras de vigência/trial'
);

$check(
    str_contains($files['version'], 'RS Connect 36.43.0') && str_contains($files['version'], 'RS Connect 36.43.1') && str_contains($files['version'], 'RS Connect 36.44.0') && str_contains($files['version'], 'RS Connect 36.44.1')
        && str_contains($files['version'], "REQUIRED_MIGRATION = '126_appointment_triage_snapshot.sql'")
        && ($manifest['package_version'] ?? '') === '36.44.5'
        && ($manifest['database']['required_migration'] ?? '') === '126_appointment_triage_snapshot.sql'
        && ($manifest['compatibility']['previous_release']['package_version'] ?? '') === '36.44.4',
    'release atual preserva a migration 125 e a funcionalidade financeira da 36.43.0'
);

exit($failed > 0 ? 1 : 0);

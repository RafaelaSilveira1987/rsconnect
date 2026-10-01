<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$view = (string) file_get_contents($root . '/app/Views/calendar/index.php');
$js = (string) file_get_contents($root . '/public/assets/js/app.js');
$controller = (string) file_get_contents($root . '/app/Controllers/CalendarController.php');
$routes = (string) file_get_contents($root . '/routes/web.php');
$manifest = json_decode((string) file_get_contents($root . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

$checks = [
    'modal informa se usuário pode gerenciar o link' => str_contains($view, 'data-calendar-dialog-meeting-manage="<?= $canManage ? \'1\' : \'0\' ?>"'),
    'gestor não depende da modalidade para ver o editor' => str_contains($js, 'const canManageMeeting')
        && str_contains($js, "meetingSection.hidden = !(canManageMeeting || isOnline || meetingUrl !== '')"),
    'campo de URL e salvamento continuam presentes' => str_contains($view, 'name="meeting_url"')
        && str_contains($view, 'Salvar link')
        && str_contains($routes, "'/calendar/meeting-link'")
        && str_contains($controller, 'public function updateMeetingLink(): void'),
    'release correta sem migration nova' => ($manifest['package_version'] ?? '') === '36.41.8'
        && ($manifest['database']['required_migration'] ?? '') === '123_published_slots_min_notice_policy.sql',
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}
if ($failed !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}
echo "OK - 36.41.8 mantém o link de atendimento editável mesmo com modalidade A definir.\n";

#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use App\Core\Router;
use App\Services\AccessControlService;
use App\Services\EvolutionService;

/**
 * RS Connect — Homologação assistida Evolution/WhatsApp E2E.
 *
 * Uso:
 *   php bin/evolution-e2e.php --instance=gestaodetempo --timeout=180
 *   php bin/evolution-e2e.php --instance-id=12 --timeout=180
 *
 * O teste não envia mensagem para o cliente. Ele prepara o marco temporal,
 * valida a Evolution/webhook e aguarda uma mensagem REAL enviada de outro
 * WhatsApp para a instância selecionada. Depois acompanha a persistência,
 * roteamento, IA e saída pela Evolution.
 */

const EXIT_OK = 0;
const EXIT_FAILED = 1;
const EXIT_USAGE = 2;

$options = getopt('', [
    'instance::',
    'instance-id::',
    'timeout::',
    'poll::',
    'no-wait',
    'allow-system-reply',
    'help',
]);

if (isset($options['help'])) {
    usage();
    exit(EXIT_OK);
}

$timeout = max(30, min(900, (int) ($options['timeout'] ?? 180)));
$poll = max(1, min(10, (int) ($options['poll'] ?? 2)));
$noWait = array_key_exists('no-wait', $options);
$allowSystemReply = array_key_exists('allow-system-reply', $options);
$instanceIdArg = max(0, (int) ($options['instance-id'] ?? 0));
$instanceNameArg = trim((string) ($options['instance'] ?? ''));

$startedAt = date(DATE_ATOM);
$checks = [];
$evidence = [
    'started_at' => $startedAt,
    'timeout_seconds' => $timeout,
    'checks' => &$checks,
];

try {
    $pdo = Database::connection();
    $instance = resolveInstance($pdo, $instanceIdArg, $instanceNameArg);
    $instanceId = (int) $instance['id'];
    $tenantId = (int) $instance['tenant_id'];
    $evidence['instance'] = [
        'id' => $instanceId,
        'tenant_id' => $tenantId,
        'label' => (string) ($instance['name'] ?? ''),
        'instance_name' => (string) ($instance['instance_name'] ?? ''),
    ];

    title('RS CONNECT — EVOLUTION / WHATSAPP E2E');
    out('Instância: ' . (string) ($instance['name'] ?? '') . ' [' . (string) ($instance['instance_name'] ?? '') . ']');
    out('ID: ' . $instanceId . ' | Empresa: ' . $tenantId);
    out('Início: ' . $startedAt);
    line();

    // 1) Configuração local.
    check($checks, 'instance.local_status', connectedState((string) ($instance['status'] ?? ''), (string) ($instance['connection_state'] ?? '')),
        'Status local da instância',
        'status=' . (string) ($instance['status'] ?? '') . ', state=' . (string) ($instance['connection_state'] ?? ''));
    check($checks, 'instance.receive_messages', (int) ($instance['receive_messages'] ?? 1) === 1,
        'Recebimento de mensagens habilitado');
    check($checks, 'instance.webhook_enabled', (int) ($instance['webhook_enabled'] ?? 0) === 1,
        'Webhook habilitado no cadastro');

    $events = json_decode((string) ($instance['webhook_events'] ?? '[]'), true);
    $events = is_array($events) ? array_map(static fn ($v): string => strtoupper(trim((string) $v)), $events) : [];
    check($checks, 'instance.messages_upsert', in_array('MESSAGES_UPSERT', $events, true),
        'Evento MESSAGES_UPSERT habilitado', $events === [] ? 'lista de eventos vazia' : implode(', ', $events));

    $appUrl = rtrim(trim((string) Env::get('APP_URL', '')), '/');
    $webhookToken = trim((string) Env::get('EVOLUTION_WEBHOOK_TOKEN', ''));
    check($checks, 'app.https', str_starts_with($appUrl, 'https://'), 'APP_URL pública em HTTPS', maskUrl($appUrl));
    check($checks, 'webhook.secret', strlen($webhookToken) >= 24, 'EVOLUTION_WEBHOOK_TOKEN configurado', $webhookToken !== '' ? 'presente e não exibido' : 'ausente');

    // 2) Acesso comercial da empresa.
    try {
        $access = (new AccessControlService())->statusForTenant($tenantId);
        $allowed = !empty($access['allowed']);
        check($checks, 'tenant.access', $allowed, 'Empresa liberada para automações', $allowed ? 'acesso permitido' : (string) ($access['code'] ?? 'bloqueado'));
        $evidence['tenant_access'] = [
            'allowed' => $allowed,
            'code' => $access['code'] ?? null,
        ];
    } catch (Throwable $exception) {
        check($checks, 'tenant.access', false, 'Empresa liberada para automações', safe($exception->getMessage()));
    }

    // 3) Vínculo de agente.
    $bindings = activeBindings($pdo, $tenantId, $instanceId);
    $activeAuto = array_values(array_filter($bindings, static fn (array $row): bool =>
        (string) ($row['agent_status'] ?? '') === 'active' && (int) ($row['auto_reply_enabled'] ?? 0) === 1
    ));
    check($checks, 'routing.binding', $bindings !== [], 'Instância possui agente vinculado', bindingSummary($bindings));
    check($checks, 'routing.auto_reply', $activeAuto !== [], 'Existe agente ativo com resposta automática', bindingSummary($activeAuto));
    $evidence['agent_bindings'] = array_map(static fn (array $row): array => [
        'agent_id' => (int) $row['agent_id'],
        'name' => (string) $row['name'],
        'is_primary' => (int) $row['is_primary'] === 1,
        'priority' => (int) $row['priority'],
        'auto_reply_enabled' => (int) $row['auto_reply_enabled'] === 1,
        'status' => (string) $row['agent_status'],
    ], $bindings);

    // 4) Evolution remota: conexão + webhook configurado.
    $service = evolutionService($instance);
    try {
        $live = $service->connectionState();
        $state = strtolower(trim((string) ($live['state'] ?? '')));
        $ok = in_array($state, ['open', 'connected', 'active', 'online'], true);
        check($checks, 'evolution.connection', $ok, 'Evolution confirma conexão ativa', $state !== '' ? $state : 'sem estado');
        $evidence['evolution_connection_state'] = $state;
    } catch (Throwable $exception) {
        check($checks, 'evolution.connection', false, 'Evolution confirma conexão ativa', safe($exception->getMessage()));
    }

    $expectedWebhook = Router::url('/webhooks/evolution?instance_id=' . $instanceId);
    try {
        $remoteWebhook = $service->findWebhook();
        $body = is_array($remoteWebhook['body'] ?? null) ? $remoteWebhook['body'] : [];
        if (isset($body['webhook']) && is_array($body['webhook'])) {
            $body = $body['webhook'];
        }
        $remoteEvents = is_array($body['events'] ?? null) ? array_map(static fn ($v): string => strtoupper(trim((string) $v)), $body['events']) : [];
        $remoteUrl = trim((string) ($body['url'] ?? ''));
        $remoteEnabled = filter_var($body['enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $remoteByEvents = filter_var($body['webhookByEvents'] ?? false, FILTER_VALIDATE_BOOL);
        $validReference = str_contains($remoteUrl, 'instance_id=' . $instanceId);
        $remoteOk = $remoteEnabled && !$remoteByEvents && in_array('MESSAGES_UPSERT', $remoteEvents, true)
            && str_contains($remoteUrl, '/webhooks/evolution') && $validReference;
        check($checks, 'evolution.webhook', $remoteOk, 'Webhook remoto da Evolution está correto',
            'enabled=' . ($remoteEnabled ? 'sim' : 'não') . ', MESSAGES_UPSERT=' . (in_array('MESSAGES_UPSERT', $remoteEvents, true) ? 'sim' : 'não') . ', url=' . maskUrl($remoteUrl));
        $evidence['remote_webhook'] = [
            'enabled' => $remoteEnabled,
            'messages_upsert' => in_array('MESSAGES_UPSERT', $remoteEvents, true),
            'url' => maskUrl($remoteUrl),
            'matches_expected_reference' => $validReference,
        ];
    } catch (Throwable $exception) {
        check($checks, 'evolution.webhook', false, 'Webhook remoto da Evolution está correto', safe($exception->getMessage()));
    }

    // 5) Prova segura da rota pública + autenticação. SEND_MESSAGE é ignorado pelo controller.
    if ($appUrl !== '' && strlen($webhookToken) >= 24) {
        try {
            $probe = publicWebhookProbe($appUrl, $webhookToken, (string) $instance['instance_name']);
            $probeOk = $probe['http_status'] >= 200 && $probe['http_status'] < 300
                && !empty($probe['body']['ok'])
                && (($probe['body']['ignored'] ?? '') === 'outgoing_send_message_event' || !empty($probe['body']['duplicate']));
            check($checks, 'webhook.public_probe', $probeOk, 'Rota pública aceita webhook autenticado',
                'HTTP ' . $probe['http_status'] . ', resposta=' . safe(json_encode($probe['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''));
            $evidence['public_webhook_probe'] = [
                'http_status' => $probe['http_status'],
                'ok' => $probeOk,
            ];
        } catch (Throwable $exception) {
            check($checks, 'webhook.public_probe', false, 'Rota pública aceita webhook autenticado', safe($exception->getMessage()));
        }
    }

    $preflightFailed = count(array_filter($checks, static fn (array $check): bool => ($check['status'] ?? '') === 'FAIL')) > 0;
    line();
    if ($preflightFailed) {
        out('[FALHA] O pré-teste encontrou bloqueios. Corrija os itens FAIL antes do E2E real.');
        saveReport($evidence + ['result' => 'PRECHECK_FAILED']);
        exit(EXIT_FAILED);
    }

    out('[OK] Pré-requisitos aprovados.');
    if ($noWait) {
        saveReport($evidence + ['result' => 'PRECHECK_APPROVED']);
        exit(EXIT_OK);
    }

    // 6) Marca o estado atual e aguarda uma mensagem real nova.
    $baselineId = maxIncomingId($pdo, $tenantId, $instanceId);
    $evidence['baseline_incoming_message_id'] = $baselineId;
    line();
    title('ETAPA REAL');
    out('Envie AGORA uma mensagem de OUTRO número de WhatsApp para esta instância.');
    out('Não envie pelo mesmo número conectado na Evolution.');
    out('Janela de observação: ' . $timeout . ' segundos.');
    $maskedPhone = maskPhone((string) ($instance['profile_phone'] ?? ''));
    if ($maskedPhone !== '') {
        out('Destino conectado: ' . $maskedPhone);
    }
    out('Sugestão de texto: TESTE E2E RS CONNECT ' . date('His'));
    line();

    $deadline = time() + $timeout;
    $incoming = null;
    while (time() <= $deadline) {
        $incoming = latestIncomingAfter($pdo, $tenantId, $instanceId, $baselineId);
        if ($incoming !== null) {
            break;
        }
        spinner('Aguardando MESSAGES_UPSERT e persistência da mensagem...');
        sleep($poll);
    }
    clearSpinner();

    if ($incoming === null) {
        check($checks, 'e2e.incoming', false, 'Mensagem real chegou ao RS Connect', 'nenhuma nova mensagem recebida dentro do tempo limite');
        $evidence['result'] = 'LIVE_INCOMING_TIMEOUT';
        saveReport($evidence);
        out('[REPROVADO] Nenhuma mensagem real entrou no RS Connect.');
        out('O problema está antes da IA: Evolution -> webhook público -> identificação da instância.');
        exit(EXIT_FAILED);
    }

    $incomingId = (int) $incoming['message_id'];
    $conversationId = (int) $incoming['conversation_id'];
    $evidence['incoming'] = sanitizeMessageEvidence($incoming);
    check($checks, 'e2e.incoming', true, 'Mensagem real chegou ao RS Connect',
        'message_id=' . $incomingId . ', conversation_id=' . $conversationId . ', external_id=' . (!empty($incoming['evolution_message_id']) ? 'presente' : 'ausente'));
    check($checks, 'e2e.external_id', trim((string) ($incoming['evolution_message_id'] ?? '')) !== '',
        'Mensagem recebida possui evolution_message_id');
    check($checks, 'e2e.conversation', $conversationId > 0, 'Conversa foi criada/localizada');

    $routedAgentId = (int) ($incoming['ai_agent_id'] ?? 0);
    check($checks, 'e2e.routing', $routedAgentId > 0, 'Conversa foi roteada para um agente de IA',
        $routedAgentId > 0 ? 'agent_id=' . $routedAgentId . ', ' . (string) ($incoming['agent_name'] ?? '') : 'ai_agent_id vazio');

    // 7) Aguarda resposta automática / evidência de erro.
    $outgoing = null;
    $aiLog = null;
    $responseDeadline = time() + $timeout;
    while (time() <= $responseDeadline) {
        $outgoing = latestOutgoingAfterIncoming($pdo, $tenantId, $conversationId, $incomingId, $allowSystemReply);
        $aiLog = latestAiLogForIncoming($pdo, $incomingId, $conversationId);
        if ($outgoing !== null) {
            break;
        }
        if ($aiLog !== null && (string) ($aiLog['status'] ?? '') === 'error') {
            break;
        }
        spinner('Mensagem persistida. Aguardando IA/Evolution responder...');
        sleep($poll);
    }
    clearSpinner();

    if ($aiLog !== null) {
        $evidence['ai_log'] = [
            'id' => (int) ($aiLog['id'] ?? 0),
            'event' => (string) ($aiLog['event'] ?? ''),
            'status' => (string) ($aiLog['status'] ?? ''),
            'error_message' => safe((string) ($aiLog['error_message'] ?? '')),
        ];
    }

    if ($outgoing === null) {
        $detail = $aiLog !== null
            ? 'último log: ' . (string) ($aiLog['event'] ?? '') . '/' . (string) ($aiLog['status'] ?? '') . ' ' . safe((string) ($aiLog['error_message'] ?? ''))
            : 'sem mensagem de saída e sem log conclusivo dentro do tempo limite';
        check($checks, 'e2e.reply', false, 'Resposta automática foi persistida', $detail);
        $evidence['result'] = 'LIVE_REPLY_FAILED';
        saveReport($evidence);
        out('[REPROVADO] A entrada chegou, mas a resposta não foi concluída.');
        out('Agora o problema está em roteamento/IA/Evolution de saída — não no webhook de entrada.');
        exit(EXIT_FAILED);
    }

    $evidence['outgoing'] = sanitizeMessageEvidence($outgoing);
    $senderType = (string) ($outgoing['sender_type'] ?? '');
    $status = (string) ($outgoing['status'] ?? '');
    $externalOut = trim((string) ($outgoing['evolution_message_id'] ?? ''));
    $replyOk = $allowSystemReply ? in_array($senderType, ['ai', 'system'], true) : $senderType === 'ai';
    check($checks, 'e2e.reply', $replyOk, 'Resposta automática foi persistida',
        'sender_type=' . $senderType . ', status=' . $status . ', external_id=' . ($externalOut !== '' ? 'presente' : 'ausente'));
    check($checks, 'e2e.delivery_id', $externalOut !== '', 'Evolution devolveu ID para a mensagem de saída');
    check($checks, 'e2e.delivery_status', in_array($status, ['sent', 'delivered', 'read'], true),
        'Mensagem de saída está em estado de envio válido', $status);

    sleep(3);
    $duplicateCount = countAiRepliesAfterIncoming($pdo, $conversationId, $incomingId);
    check($checks, 'e2e.no_duplicate_reply', $duplicateCount <= 1, 'Não houve resposta de IA duplicada', 'quantidade=' . $duplicateCount);
    $evidence['ai_reply_count_after_incoming'] = $duplicateCount;

    $failed = count(array_filter($checks, static fn (array $check): bool => ($check['status'] ?? '') === 'FAIL')) > 0;
    $evidence['result'] = $failed ? 'REPROVADO' : 'APROVADO';
    $report = saveReport($evidence);
    line();
    out($failed ? '[REPROVADO] Evolution/WhatsApp E2E encontrou falhas.' : '[APROVADO] Evolution/WhatsApp E2E concluído ponta a ponta.');
    out('Relatório: ' . $report);
    exit($failed ? EXIT_FAILED : EXIT_OK);
} catch (Throwable $exception) {
    clearSpinner();
    out('[ERRO] ' . safe($exception->getMessage()));
    $evidence['fatal_error'] = safe($exception->getMessage());
    $evidence['result'] = 'ERROR';
    saveReport($evidence);
    exit(EXIT_FAILED);
}

function usage(): void
{
    echo "RS Connect — Evolution/WhatsApp E2E\n\n";
    echo "Uso:\n";
    echo "  php bin/evolution-e2e.php --instance=gestaodetempo --timeout=180\n";
    echo "  php bin/evolution-e2e.php --instance-id=12 --timeout=180\n\n";
    echo "Opções:\n";
    echo "  --no-wait              Executa somente o pré-teste, sem aguardar WhatsApp real.\n";
    echo "  --allow-system-reply   Aceita aviso operacional/fora do horário como resposta.\n";
    echo "  --poll=2               Intervalo de consulta ao banco em segundos.\n";
}

function resolveInstance(PDO $pdo, int $id, string $name): array
{
    if ($id > 0) {
        $st = $pdo->prepare('SELECT * FROM evolution_instances WHERE id = :id LIMIT 1');
        $st->execute(['id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Instância ID ' . $id . ' não encontrada.');
        }
        return $row;
    }

    if ($name !== '') {
        $st = $pdo->prepare('SELECT * FROM evolution_instances WHERE instance_name = :name OR name = :name ORDER BY id DESC LIMIT 2');
        $st->execute(['name' => $name]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) {
            throw new RuntimeException('A instância "' . safe($name) . '" não foi encontrada de forma única. Use --instance-id.');
        }
        return $rows[0];
    }

    $rows = $pdo->query(
        'SELECT * FROM evolution_instances
         WHERE webhook_enabled = 1 AND receive_messages = 1
         ORDER BY (status = "connected") DESC, is_default DESC, id DESC
         LIMIT 3'
    )->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) === 1) {
        return $rows[0];
    }

    if ($rows === []) {
        throw new RuntimeException('Nenhuma instância Evolution habilitada foi encontrada.');
    }

    $labels = array_map(static fn (array $row): string => (string) $row['id'] . ':' . (string) $row['instance_name'], $rows);
    throw new RuntimeException('Há várias instâncias habilitadas. Informe --instance-id. Opções: ' . implode(', ', $labels));
}

function activeBindings(PDO $pdo, int $tenantId, int $instanceId): array
{
    try {
        $st = $pdo->prepare(
            'SELECT b.agent_id, b.is_primary, b.priority, b.status AS binding_status,
                    a.name, a.status AS agent_status, a.auto_reply_enabled
             FROM ai_agent_instance_bindings b
             INNER JOIN ai_agents a ON a.id = b.agent_id AND a.tenant_id = b.tenant_id
             WHERE b.tenant_id = :tenant_id AND b.instance_id = :instance_id AND b.status = "active"
             ORDER BY b.is_primary DESC, b.priority DESC, b.id ASC'
        );
        $st->execute(['tenant_id' => $tenantId, 'instance_id' => $instanceId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        $st = $pdo->prepare(
            'SELECT a.id AS agent_id, 1 AS is_primary, 100 AS priority, "active" AS binding_status,
                    a.name, a.status AS agent_status, a.auto_reply_enabled
             FROM ai_agents a
             WHERE a.tenant_id = :tenant_id AND a.instance_id = :instance_id
             ORDER BY a.is_default DESC, a.id ASC'
        );
        $st->execute(['tenant_id' => $tenantId, 'instance_id' => $instanceId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

function bindingSummary(array $rows): string
{
    if ($rows === []) {
        return 'nenhum vínculo ativo';
    }
    return implode('; ', array_map(static function (array $row): string {
        return '#' . (int) $row['agent_id'] . ' ' . (string) $row['name']
            . ' [auto=' . ((int) ($row['auto_reply_enabled'] ?? 0) === 1 ? 'sim' : 'não')
            . ', primary=' . ((int) ($row['is_primary'] ?? 0) === 1 ? 'sim' : 'não') . ']';
    }, $rows));
}

function evolutionService(array $instance): EvolutionService
{
    $verifySsl = filter_var(Env::get('EVOLUTION_SSL_VERIFY', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    $caBundle = trim((string) Env::get('EVOLUTION_CA_BUNDLE', ''));
    return new EvolutionService(
        (string) $instance['base_url'],
        Crypto::decrypt((string) $instance['api_key_encrypted']),
        (string) $instance['instance_name'],
        15,
        $verifySsl ?? true,
        $caBundle !== '' ? $caBundle : null
    );
}

function publicWebhookProbe(string $appUrl, string $token, string $instanceName): array
{
    $url = rtrim($appUrl, '/') . '/webhooks/evolution';
    $payload = json_encode([
        'event' => 'SEND_MESSAGE',
        'instance' => $instanceName,
        'event_id' => 'e2e-probe-' . bin2hex(random_bytes(8)),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Não foi possível iniciar o probe HTTP.');
    }
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-RS-Connect-Token: ' . $token,
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);
    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($raw === false) {
        throw new RuntimeException('Probe HTTP falhou: ' . safe($error));
    }
    $body = json_decode((string) $raw, true);
    return [
        'http_status' => $status,
        'body' => is_array($body) ? $body : ['raw' => mb_substr(strip_tags((string) $raw), 0, 300)],
    ];
}

function maxIncomingId(PDO $pdo, int $tenantId, int $instanceId): int
{
    $st = $pdo->prepare(
        'SELECT COALESCE(MAX(cm.id), 0)
         FROM conversation_messages cm
         INNER JOIN conversations c ON c.id = cm.conversation_id AND c.tenant_id = cm.tenant_id
         WHERE cm.tenant_id = :tenant_id AND c.evolution_instance_id = :instance_id AND cm.direction = "incoming"'
    );
    $st->execute(['tenant_id' => $tenantId, 'instance_id' => $instanceId]);
    return (int) $st->fetchColumn();
}

function latestIncomingAfter(PDO $pdo, int $tenantId, int $instanceId, int $baselineId): ?array
{
    $st = $pdo->prepare(
        'SELECT cm.id AS message_id, cm.conversation_id, cm.evolution_message_id, cm.message_type,
                cm.status, cm.sent_at, cm.created_at, cm.content,
                c.ai_agent_id, c.attendance_mode, c.status AS conversation_status,
                ct.phone,
                a.name AS agent_name
         FROM conversation_messages cm
         INNER JOIN conversations c ON c.id = cm.conversation_id AND c.tenant_id = cm.tenant_id
         LEFT JOIN contacts ct ON ct.id = c.contact_id
         LEFT JOIN ai_agents a ON a.id = c.ai_agent_id
         WHERE cm.tenant_id = :tenant_id
           AND c.evolution_instance_id = :instance_id
           AND cm.direction = "incoming"
           AND cm.id > :baseline_id
         ORDER BY cm.id ASC
         LIMIT 1'
    );
    $st->execute(['tenant_id' => $tenantId, 'instance_id' => $instanceId, 'baseline_id' => $baselineId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function latestOutgoingAfterIncoming(PDO $pdo, int $tenantId, int $conversationId, int $incomingId, bool $allowSystem): ?array
{
    $types = $allowSystem ? '("ai","system")' : '("ai")';
    $st = $pdo->prepare(
        'SELECT cm.id AS message_id, cm.conversation_id, cm.evolution_message_id, cm.message_type,
                cm.status, cm.sent_at, cm.created_at, cm.content, cm.sender_type, cm.error_message
         FROM conversation_messages cm
         WHERE cm.tenant_id = :tenant_id
           AND cm.conversation_id = :conversation_id
           AND cm.direction = "outgoing"
           AND cm.sender_type IN ' . $types . '
           AND cm.id > :incoming_id
         ORDER BY cm.id ASC
         LIMIT 1'
    );
    $st->execute(['tenant_id' => $tenantId, 'conversation_id' => $conversationId, 'incoming_id' => $incomingId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function latestAiLogForIncoming(PDO $pdo, int $incomingId, int $conversationId): ?array
{
    try {
        $st = $pdo->prepare(
            'SELECT id, event, status, error_message, response_preview, created_at
             FROM ai_automation_logs
             WHERE incoming_message_id = :incoming_id
             ORDER BY id DESC LIMIT 1'
        );
        $st->execute(['incoming_id' => $incomingId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable) {
        $st = $pdo->prepare(
            'SELECT id, event, status, error_message, response_preview, created_at
             FROM ai_automation_logs
             WHERE conversation_id = :conversation_id
             ORDER BY id DESC LIMIT 1'
        );
        $st->execute(['conversation_id' => $conversationId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

function countAiRepliesAfterIncoming(PDO $pdo, int $conversationId, int $incomingId): int
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM conversation_messages
         WHERE conversation_id = :conversation_id
           AND direction = "outgoing"
           AND sender_type = "ai"
           AND id > :incoming_id'
    );
    $st->execute(['conversation_id' => $conversationId, 'incoming_id' => $incomingId]);
    return (int) $st->fetchColumn();
}

function connectedState(string $status, string $state): bool
{
    $status = strtolower(trim($status));
    $state = strtolower(trim($state));
    return $status === 'connected' || in_array($state, ['open', 'connected', 'active', 'online'], true);
}

function check(array &$checks, string $key, bool $ok, string $label, string $detail = ''): void
{
    $status = $ok ? 'PASS' : 'FAIL';
    $checks[] = compact('key', 'status', 'label', 'detail');
    echo '[' . ($ok ? 'OK' : 'ERRO') . '] ' . $label;
    if ($detail !== '') {
        echo ' — ' . $detail;
    }
    echo PHP_EOL;
}

function sanitizeMessageEvidence(array $row): array
{
    return [
        'message_id' => (int) ($row['message_id'] ?? 0),
        'conversation_id' => (int) ($row['conversation_id'] ?? 0),
        'evolution_message_id_present' => trim((string) ($row['evolution_message_id'] ?? '')) !== '',
        'message_type' => (string) ($row['message_type'] ?? ''),
        'sender_type' => (string) ($row['sender_type'] ?? ''),
        'status' => (string) ($row['status'] ?? ''),
        'sent_at' => (string) ($row['sent_at'] ?? ''),
        'content_preview' => mb_substr(trim((string) ($row['content'] ?? '')), 0, 120),
        'phone' => maskPhone((string) ($row['phone'] ?? '')),
        'ai_agent_id' => (int) ($row['ai_agent_id'] ?? 0),
        'agent_name' => (string) ($row['agent_name'] ?? ''),
        'error_message' => safe((string) ($row['error_message'] ?? '')),
    ];
}

function saveReport(array $evidence): string
{
    $dir = dirname(__DIR__) . '/storage/logs/e2e';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return '[não foi possível criar storage/logs/e2e]';
    }
    $file = $dir . '/evolution-e2e-' . date('Ymd-His') . '.json';
    file_put_contents($file, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $file;
}

function maskPhone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?: '';
    if ($digits === '') {
        return '';
    }
    if (strlen($digits) <= 4) {
        return str_repeat('*', strlen($digits));
    }
    return str_repeat('*', max(4, strlen($digits) - 4)) . substr($digits, -4);
}

function maskUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return mb_substr($url, 0, 180);
    }
    $scheme = (string) ($parts['scheme'] ?? 'https');
    $host = (string) $parts['host'];
    $path = (string) ($parts['path'] ?? '');
    $query = (string) ($parts['query'] ?? '');
    return $scheme . '://' . $host . $path . ($query !== '' ? '?' . $query : '');
}

function safe(string $text): string
{
    $text = preg_replace('/(?i)(apikey|token|secret|authorization)[=: ]+[^\s,;]+/', '$1=[REDACTED]', $text) ?? $text;
    return mb_substr($text, 0, 500);
}

function title(string $text): void
{
    echo $text . PHP_EOL;
}

function line(): void
{
    echo str_repeat('=', 72) . PHP_EOL;
}

function out(string $text): void
{
    echo $text . PHP_EOL;
}

function spinner(string $text): void
{
    if (defined('STDOUT') && function_exists('stream_isatty') && stream_isatty(STDOUT)) {
        echo "\r" . $text . ' ' . date('H:i:s') . '   ';
    }
}

function clearSpinner(): void
{
    if (defined('STDOUT') && function_exists('stream_isatty') && stream_isatty(STDOUT)) {
        echo "\r" . str_repeat(' ', 110) . "\r";
    }
}

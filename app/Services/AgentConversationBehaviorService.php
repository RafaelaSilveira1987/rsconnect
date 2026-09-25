<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Regras conversacionais configuráveis pelo cliente que ficam fora do prompt livre.
 *
 * A intenção é manter comportamento operacional previsível: demanda, modalidades,
 * forma de entrega da resposta, indisponibilidade e encaminhamentos especiais.
 */
final class AgentConversationBehaviorService
{
    private static array $settingsCache = [];

    /** @return array<string,mixed> */
    public function settingsForTenant(int $tenantId, ?PDO $pdo = null): array
    {
        if ($tenantId < 1) {
            return $this->defaults();
        }
        if (isset(self::$settingsCache[$tenantId])) {
            return self::$settingsCache[$tenantId];
        }
        try {
            $profile = (new AgentBlueprintService())->profileForTenant($tenantId, true, $pdo);
            return self::$settingsCache[$tenantId] = $this->settingsFromProfile($profile);
        } catch (Throwable) {
            return self::$settingsCache[$tenantId] = $this->defaults();
        }
    }

    /** @param array<string,mixed> $profile @return array<string,mixed> */
    public function settingsFromProfile(array $profile): array
    {
        $config = is_array($profile['config'] ?? null) ? $profile['config'] : [];
        $hasExplicitBehavior = is_array($config['conversation_behavior'] ?? null);
        $raw = $hasExplicitBehavior ? $config['conversation_behavior'] : [];

        // Compatibilidade com empresas que já tinham a demanda configurada na triagem
        // antes da 36.31.0. A nova tela nasce refletindo o estado existente em vez de
        // aparentar que a coleta está desligada. Depois do primeiro salvamento, config_json
        // passa a ser a fonte explícita dessa camada amigável.
        if (!$hasExplicitBehavior) {
            foreach ((array) ($profile['triage_fields'] ?? []) as $field) {
                if (!is_array($field) || (string) ($field['field_key'] ?? '') !== 'brief_demand') {
                    continue;
                }
                $raw['demand'] = [
                    'enabled' => !empty($field['active']),
                    'required_before_schedule' => !empty($field['required_before_schedule']),
                    'prompt' => (string) ($field['prompt_text'] ?? ''),
                ];
                break;
            }
        }

        $normalized = self::normalizeConfiguration($raw);

        // 36.36.17: as duas telas históricas não podem disputar a mesma regra.
        // Se o campo estruturado brief_demand estiver marcado como obrigatório antes
        // da agenda, essa exigência é preservada mesmo que conversation_behavior tenha
        // sido salvo por uma versão anterior com o toggle desligado. A regra efetiva é
        // a mais restritiva entre as fontes persistidas, evitando perda silenciosa de
        // configuração durante upgrades.
        foreach ((array) ($profile['triage_fields'] ?? []) as $field) {
            if (!is_array($field) || (string) ($field['field_key'] ?? '') !== 'brief_demand') {
                continue;
            }
            $triageRequired = !empty($field['active']) && !empty($field['required_before_schedule']);
            if ($triageRequired) {
                $normalized['demand']['enabled'] = true;
                $normalized['demand']['required_before_schedule'] = true;
            }
            $behaviorPrompt = trim((string) (($raw['demand']['prompt'] ?? '')));
            $triagePrompt = trim((string) ($field['prompt_text'] ?? ''));
            if ($behaviorPrompt === '' && $triagePrompt !== '') {
                $normalized['demand']['prompt'] = $triagePrompt;
            }
            break;
        }

        return $normalized;
    }

    /**
     * Sanitiza o payload do formulário e garante formato estável no config_json.
     * @param array<string,mixed> $raw
     * @param array<string,mixed> $fallback
     * @return array<string,mixed>
     */
    public static function normalizeConfiguration(array $raw, array $fallback = []): array
    {
        $defaults = (new self())->defaults();
        $base = $fallback !== [] ? array_replace_recursive($defaults, $fallback) : $defaults;

        $demand = is_array($raw['demand'] ?? null) ? $raw['demand'] : [];
        $demandRequiredBeforeSchedule = !empty($demand['required_before_schedule']);
        $base['demand'] = [
            // Exigir a demanda antes da agenda implica manter a coleta de demanda ativa.
            // Evita configuração contraditória: "exigir" ligado com "perguntar" desligado.
            'enabled' => !empty($demand['enabled']) || $demandRequiredBeforeSchedule,
            'required_before_schedule' => $demandRequiredBeforeSchedule,
            'prompt' => self::cleanText($demand['prompt'] ?? ($base['demand']['prompt'] ?? ''), 1000),
        ];

        $delivery = is_array($raw['response_delivery'] ?? null) ? $raw['response_delivery'] : [];
        $mode = strtolower(trim((string) ($delivery['mode'] ?? $base['response_delivery']['mode'] ?? 'auto')));
        if (!in_array($mode, ['single', 'blocks', 'auto'], true)) {
            $mode = 'auto';
        }
        $scope = strtolower(trim((string) ($delivery['scope'] ?? $base['response_delivery']['scope'] ?? 'asked_only')));
        if (!in_array($scope, ['asked_only', 'related'], true)) {
            $scope = 'asked_only';
        }
        $base['response_delivery'] = [
            'mode' => $mode,
            'max_blocks' => max(2, min(4, (int) ($delivery['max_blocks'] ?? $base['response_delivery']['max_blocks'] ?? 3))),
            'scope' => $scope,
        ];

        $modalitiesRaw = is_array($raw['modalities'] ?? null) ? $raw['modalities'] : [];
        foreach (['online', 'presencial'] as $key) {
            $item = is_array($modalitiesRaw[$key] ?? null) ? $modalitiesRaw[$key] : [];
            $fallbackItem = is_array($base['modalities'][$key] ?? null) ? $base['modalities'][$key] : [];
            $days = is_array($item['allowed_days'] ?? null) ? $item['allowed_days'] : [];
            $days = array_values(array_unique(array_filter(array_map(
                static fn (mixed $day): string => in_array((string) $day, ['mon','tue','wed','thu','fri','sat','sun'], true) ? (string) $day : '',
                $days
            ))));
            $base['modalities'][$key] = [
                'enabled' => !empty($item['enabled']),
                'channel' => self::cleanText($item['channel'] ?? ($fallbackItem['channel'] ?? ''), 180),
                'location' => self::cleanText($item['location'] ?? ($fallbackItem['location'] ?? ''), 300),
                'allowed_days' => $days,
                'message' => self::cleanText($item['message'] ?? ($fallbackItem['message'] ?? ''), 1200),
            ];
        }

        $payment = is_array($raw['payment'] ?? null) ? $raw['payment'] : [];
        $methods = is_array($payment['methods'] ?? null) ? $payment['methods'] : [];
        $methods = array_values(array_filter(array_map(
            static fn (mixed $method): string => self::cleanText($method, 80),
            $methods
        )));
        $base['payment'] = [
            'enabled' => !empty($payment['enabled']),
            'value_text' => self::cleanText($payment['value_text'] ?? ($base['payment']['value_text'] ?? ''), 250),
            'methods' => array_slice($methods, 0, 8),
            'message' => self::cleanText($payment['message'] ?? ($base['payment']['message'] ?? ''), 1200),
        ];

        $noAvailability = is_array($raw['no_availability'] ?? null) ? $raw['no_availability'] : [];
        $action = strtolower(trim((string) ($noAvailability['action'] ?? $base['no_availability']['action'] ?? 'message_only')));
        if (!in_array($action, ['message_only', 'notify', 'handoff'], true)) {
            $action = 'message_only';
        }
        $base['no_availability'] = [
            'message' => self::cleanText($noAvailability['message'] ?? ($base['no_availability']['message'] ?? ''), 1200),
            'action' => $action,
            'target_user_id' => max(0, (int) ($noAvailability['target_user_id'] ?? 0)),
        ];

        $routes = [];
        $rawRoutes = is_array($raw['special_routes'] ?? null) ? $raw['special_routes'] : [];
        foreach ($rawRoutes as $index => $route) {
            if (!is_array($route)) {
                continue;
            }
            $label = self::cleanText($route['label'] ?? '', 160);
            $keywordsText = self::cleanText($route['keywords'] ?? '', 700);
            $keywords = self::keywordList($keywordsText);
            if ($label === '' && $keywords === []) {
                continue;
            }
            $routes[] = [
                'key' => 'route_' . (count($routes) + 1),
                'enabled' => !empty($route['enabled']),
                'label' => $label !== '' ? $label : 'Encaminhamento especial ' . (count($routes) + 1),
                'keywords' => implode(', ', $keywords),
                'customer_message' => self::cleanText($route['customer_message'] ?? '', 1200),
                'target_user_id' => max(0, (int) ($route['target_user_id'] ?? 0)),
            ];
            if (count($routes) >= 8) {
                break;
            }
        }
        $base['special_routes'] = $routes;

        return $base;
    }

    /**
     * Aplica as regras amigáveis do agente sobre o perfil operacional em memória.
     *
     * O config_json é a fonte canônica para "Exigir a demanda antes da agenda".
     * Essa sobreposição impede que um tenant_triage_fields antigo/desalinhado libere
     * a agenda mesmo quando a opção está marcada na tela do assistente.
     *
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    public function applyOperationalOverridesToProfile(array $profile): array
    {
        $settings = $this->settingsFromProfile($profile);
        $demand = is_array($settings['demand'] ?? null) ? $settings['demand'] : [];
        $demandEnabled = !empty($demand['enabled']);
        $demandRequired = !empty($demand['required_before_schedule']);
        if (!$demandEnabled && !$demandRequired) {
            return $profile;
        }

        $fields = is_array($profile['triage_fields'] ?? null) ? array_values($profile['triage_fields']) : [];
        $found = false;
        foreach ($fields as &$field) {
            if (!is_array($field) || (string) ($field['field_key'] ?? '') !== 'brief_demand') {
                continue;
            }
            $found = true;
            $field['active'] = true;
            if ($demandRequired) {
                $field['required_before_schedule'] = true;
            }
            $prompt = trim((string) ($demand['prompt'] ?? ''));
            if ($prompt !== '') {
                $field['prompt_text'] = $prompt;
            }
            break;
        }
        unset($field);

        if (!$found) {
            // Não inventa conteúdo operacional no PHP. Se a regra amigável aponta para
            // uma informação que não existe no perfil, criamos apenas o contrato mínimo
            // sem pergunta; o auditor de runtime sinaliza a configuração incompleta e o
            // atendimento automático não deve improvisar essa etapa.
            $fields[] = [
                'field_key' => 'brief_demand',
                'label' => 'Demanda',
                'field_type' => 'textarea',
                'prompt_text' => trim((string) ($demand['prompt'] ?? '')),
                'required_before_schedule' => $demandRequired,
                'required_for_completion' => true,
                'active' => true,
                'position' => 9990,
                'source' => 'conversation_behavior',
                'configuration_incomplete' => true,
            ];
        }

        // Não reordena aqui. profileForTenant já aplica a ordem configurada do
        // workflow. Reordenar novamente pelo position original fazia a etapa cadastrada
        // pelo usuário ser silenciosamente desfeita quando a regra de demanda estava ativa.
        $profile['triage_fields'] = array_values($fields);
        return $profile;
    }

    /**
     * Retorna true quando o turno contém uma pergunta informativa que deve ser
     * respondida antes de a IA retomar a próxima pergunta de triagem.
     */
    public function hasInformationalQuestion(string $content): bool
    {
        $text = $this->normalize($content);
        if ($text === '') {
            return false;
        }
        if (preg_match('/^(?:pode ser |prefiro |quero )?(online|presencial|telefone)(?: por favor)?$/u', $text)) {
            return false;
        }

        return (bool) preg_match(
            '/\b(qual|quanto|valor|preco|preço|pagamento|pagar|pix|cartao|cartão|transferencia|transferência|como funciona|como e|como é|onde|local|endereco|endereço|meet|online|presencial|quero saber mais|gostaria de saber mais|saber mais|mais informacoes|mais informações|me explique|pode explicar)\b/u',
            $text
        );
    }

    /** @param array<string,mixed> $profile */
    public function promptBlock(array $profile, string $currentTurnText = ''): string
    {
        $settings = $this->settingsFromProfile($profile);
        $lines = [];
        $interactionMode = strtolower(trim((string) ($profile['interaction_mode'] ?? 'hybrid')));
        if ($interactionMode === 'form') {
            $lines[] = '- Redação das perguntas: modo Formulário. Preserve o texto cadastrado para a etapa atual.';
        } elseif ($interactionMode === 'prompt') {
            $lines[] = '- Redação das perguntas: use o Prompt Studio para formular naturalmente a etapa atual. A pergunta cadastrada define o objetivo de coleta e não precisa ser copiada literalmente.';
        } else {
            $lines[] = '- Redação das perguntas: use linguagem natural e o tom configurado. A pergunta cadastrada define o objetivo da etapa, não uma resposta pronta. Se o turno atual trouxer contexto, dúvida ou relato sensível, responda/reconheça brevemente antes da próxima pergunta.';
        }

        if (!empty($settings['demand']['enabled'])) {
            $prompt = trim((string) ($settings['demand']['prompt'] ?? ''));
            $lines[] = '- Demanda: para novo lead/interessado, entenda a necessidade antes de avançar' . (!empty($settings['demand']['required_before_schedule']) ? ' e antes de consultar a agenda' : '') . '.';
            if ($prompt !== '') {
                $lines[] = '  Pergunta sugerida: ' . $prompt;
            }
            $lines[] = '  Cliente/paciente atual não deve ser requalificado nem obrigado a repetir uma demanda já conhecida.';
        }

        $online = $settings['modalities']['online'] ?? [];
        if (!empty($online['enabled'])) {
            $parts = ['Online'];
            if (trim((string) ($online['channel'] ?? '')) !== '') {
                $parts[] = 'meio: ' . trim((string) $online['channel']);
            }
            if (trim((string) ($online['message'] ?? '')) !== '') {
                $parts[] = trim((string) $online['message']);
            }
            $lines[] = '- Modalidade ' . implode(' · ', $parts) . '.';
        }

        $presencial = $settings['modalities']['presencial'] ?? [];
        if (!empty($presencial['enabled'])) {
            $parts = ['Presencial'];
            if (trim((string) ($presencial['location'] ?? '')) !== '') {
                $parts[] = 'local: ' . trim((string) $presencial['location']);
            }
            $days = is_array($presencial['allowed_days'] ?? null) ? $presencial['allowed_days'] : [];
            if ($days !== []) {
                $parts[] = 'dias permitidos: ' . implode(', ', array_map([$this, 'dayLabel'], $days));
            }
            if (trim((string) ($presencial['message'] ?? '')) !== '') {
                $parts[] = trim((string) $presencial['message']);
            }
            $lines[] = '- Modalidade ' . implode(' · ', $parts) . '.';
            if ($days !== []) {
                $lines[] = '  Nunca ofereça atendimento presencial em outro dia; a disponibilidade real continua sendo validada pela agenda.';
            }
        }

        $delivery = $settings['response_delivery'] ?? [];
        $scope = (string) ($delivery['scope'] ?? 'asked_only');

        $payment = $settings['payment'] ?? [];
        if (!empty($payment['enabled'])) {
            $canExposePayment = $scope !== 'asked_only'
                || trim($currentTurnText) === ''
                || $this->turnAsksPayment($currentTurnText);
            if ($canExposePayment) {
                $parts = [];
                if (trim((string) ($payment['value_text'] ?? '')) !== '') {
                    $parts[] = 'valor: ' . trim((string) $payment['value_text']);
                }
                if (!empty($payment['methods'])) {
                    $parts[] = 'pagamento: ' . implode(', ', array_map('strval', (array) $payment['methods']));
                }
                if (trim((string) ($payment['message'] ?? '')) !== '') {
                    $parts[] = trim((string) $payment['message']);
                }
                if ($parts !== []) {
                    $lines[] = '- Valores/pagamento: ' . implode(' · ', $parts) . '.';
                    $lines[] = '  Informe estes dados somente quando o turno atual pedir valor, preço, pagamento ou quando a configuração de ritmo permitir antecipação.';
                }
            } else {
                $lines[] = '- Valores/pagamento: existem dados configurados, mas eles estão OCULTOS neste turno porque o cliente não perguntou sobre valor ou pagamento. Não mencione preço nem formas de pagamento agora.';
            }
        }

        $mode = (string) ($delivery['mode'] ?? 'auto');
        if ($scope === 'asked_only') {
            $lines[] = '- Ritmo da conversa: responda somente o que o contato perguntou ou informou no TURNO ATUAL. Não antecipe valor, pagamento, modalidade, endereço, disponibilidade ou outras informações só porque estejam configuradas. Depois de responder ao pedido atual, retome somente a PRÓXIMA etapa obrigatória da Ordem do atendimento, com no máximo uma pergunta de coleta, e aguarde a resposta antes de avançar.';
        } else {
            $lines[] = '- Ritmo da conversa: você pode acrescentar informações diretamente relacionadas ao assunto perguntado, mas ainda deve fazer no máximo uma pergunta de coleta por resposta e respeitar a próxima etapa da Ordem do atendimento.';
        }
        if ($mode !== 'single') {
            $max = (int) ($delivery['max_blocks'] ?? 3);
            if ($mode === 'blocks') {
                $lines[] = '- Forma de resposta no WhatsApp: prefira mensagens separadas para assuntos diferentes, em até ' . $max . ' blocos curtos. Cada bloco deve fazer sentido sozinho; evite fragmentar frases.';
            } else {
                $lines[] = '- Forma de resposta no WhatsApp: quando houver mais de um assunto necessário na mesma resposta, separe-os em parágrafos curtos; o sistema poderá entregá-los em até ' . $max . ' balões. Evite um parágrafo único muito longo.';
            }
        }

        $noAvailability = $settings['no_availability'] ?? [];
        if (trim((string) ($noAvailability['message'] ?? '')) !== '') {
            $lines[] = '- Sem disponibilidade: use a mensagem configurada pelo sistema; não invente vaga ou promessa de horário.';
        }

        $routes = array_values(array_filter((array) ($settings['special_routes'] ?? []), static fn (array $route): bool => !empty($route['enabled'])));
        if ($routes !== []) {
            $lines[] = '- Encaminhamentos especiais estão configurados para: ' . implode(', ', array_map(static fn (array $route): string => (string) ($route['label'] ?? 'assunto especial'), $routes)) . '. O backend faz o encaminhamento antes da IA; não tente transformar esses assuntos em pré-agendamento comum.';
        }

        if ($lines === []) {
            return '';
        }

        return "COMPORTAMENTO OPERACIONAL CONFIGURADO PELA EMPRESA (prioridade sobre estilo livre):\n"
            . implode("\n", $lines)
            . "\n\n";
    }

    /**
     * Defesa determinística do ritmo "responder só ao que foi perguntado".
     *
     * O conteúdo de negócio continua vindo exclusivamente da configuração. Esta camada
     * não inventa respostas: ela apenas impede que uma resposta gerada antecipe um tema
     * estruturado que o cliente ainda não pediu e limita a coleta a uma pergunta por turno.
     */
    public function enforceTurnScope(int $tenantId, string $currentTurnText, string $reply, ?PDO $pdo = null, int $conversationId = 0): string
    {
        $reply = $this->enforceTurnScopeWithSettings(
            $this->settingsForTenant($tenantId, $pdo),
            $currentTurnText,
            $reply
        );

        if ($conversationId > 0 && trim($reply) !== '') {
            $reply = $this->enforcePendingConfiguredQuestion($tenantId, $conversationId, $reply, $pdo);
        }

        return trim($reply);
    }

    /** @param array<string,mixed> $settings */
    public function enforceTurnScopeWithSettings(array $settings, string $currentTurnText, string $reply): string
    {
        $reply = trim($reply);
        if ($reply === '') {
            return '';
        }

        $delivery = is_array($settings['response_delivery'] ?? null) ? $settings['response_delivery'] : [];
        if ((string) ($delivery['scope'] ?? 'asked_only') !== 'asked_only') {
            return $reply;
        }

        $payment = is_array($settings['payment'] ?? null) ? $settings['payment'] : [];
        if (!empty($payment['enabled']) && !$this->turnAsksPayment($currentTurnText)) {
            $reply = $this->stripUnaskedPaymentDisclosure($reply, $payment);
        }

        // O modo asked_only sempre termina, no máximo, com a primeira pergunta de coleta.
        // Assim o modelo não consegue avançar duas etapas numa única resposta mesmo que
        // o prompt livre ou a base de conhecimento tentem fazê-lo.
        $reply = $this->keepOnlyFirstQuestion($reply);

        return trim($reply);
    }

    private function enforcePendingConfiguredQuestion(int $tenantId, int $conversationId, string $reply, ?PDO $pdo = null): string
    {
        try {
            $context = (new AgentTriageService())->context($tenantId, $conversationId);
            $currentFieldKey = trim((string) ($context['current_field_key'] ?? ''));
            if ($currentFieldKey === '') {
                return $reply;
            }

            $profile = (new AgentBlueprintService())->profileForTenant($tenantId, true, $pdo);
            $prompt = '';
            foreach ((array) ($profile['triage_fields'] ?? []) as $field) {
                if (!is_array($field) || (string) ($field['field_key'] ?? '') !== $currentFieldKey) {
                    continue;
                }
                $prompt = trim((string) ($field['prompt_text'] ?? ''));
                break;
            }
            if ($prompt === '') {
                return $reply;
            }

            // Enquanto ainda existe uma etapa de coleta antes da ação de agenda, uma
            // frase gerada pela IA não pode transformar interesse em execução. O conteúdo
            // da pergunta vem do banco; esta camada apenas impede uma promessa operacional
            // prematura e garante que a pergunta configurada continue visível.
            if ($this->fieldComesBeforeCalendarAction($profile, $currentFieldKey)) {
                $reply = $this->stripPrematureCalendarActionClaims($reply);
            }

            if (!str_contains($reply, '?')) {
                $reply = trim($reply);
                $reply = $reply !== '' ? $reply . "\n\n" . $prompt : $prompt;
            }

            return trim($reply);
        } catch (Throwable) {
            return $reply;
        }
    }

    /** @param array<string,mixed> $profile */
    private function fieldComesBeforeCalendarAction(array $profile, string $fieldKey): bool
    {
        $fieldPosition = null;
        $calendarPosition = null;
        foreach ((array) ($profile['workflow'] ?? []) as $step) {
            if (!is_array($step) || empty($step['active'])) {
                continue;
            }
            $position = (int) ($step['position'] ?? 0);
            $config = is_array($step['config'] ?? null) ? $step['config'] : [];
            if ((string) ($step['step_type'] ?? '') === 'action'
                && str_starts_with(trim((string) ($config['action_key'] ?? '')), 'calendar.')) {
                $calendarPosition = $calendarPosition === null ? $position : min($calendarPosition, $position);
            }
            if ((string) ($step['step_type'] ?? '') !== 'collect') {
                continue;
            }
            $keys = [];
            if (trim((string) ($config['field_key'] ?? '')) !== '') {
                $keys[] = trim((string) $config['field_key']);
            }
            if (is_array($config['field_keys'] ?? null)) {
                foreach ($config['field_keys'] as $key) {
                    $key = trim((string) $key);
                    if ($key !== '') {
                        $keys[] = $key;
                    }
                }
            }
            if (in_array($fieldKey, $keys, true)) {
                $fieldPosition = $position;
            }
        }

        return $fieldPosition !== null && $calendarPosition !== null && $fieldPosition < $calendarPosition;
    }

    private function stripPrematureCalendarActionClaims(string $reply): string
    {
        $sentences = preg_split('/(?<=[\.!?])\s+/u', trim($reply)) ?: [trim($reply)];
        $kept = [];
        foreach ($sentences as $sentence) {
            $sentence = trim((string) $sentence);
            if ($sentence === '') {
                continue;
            }
            $normalized = $this->normalize($sentence);
            $claimsAction = preg_match(
                '/\b(vou|vamos|iremos|estou|ja|já)\b.{0,70}\b(registrar|anotar|encaminhar|verificar|consultar|buscar|checar)\b.{0,80}\b(preferencia|preferência|agenda|disponibilidade|horario|horário|vaga)\b/u',
                $normalized
            ) === 1;
            $claimsFollowup = preg_match(
                '/\b(assim que|quando)\b.{0,45}\b(confirmar|confirmado|disponibilidade|retorno)\b.{0,60}\b(aviso|avisar|comunico|retorno)\b/u',
                $normalized
            ) === 1;
            if (!$claimsAction && !$claimsFollowup) {
                $kept[] = $sentence;
            }
        }
        return trim(implode(' ', $kept));
    }

    private function turnAsksPayment(string $content): bool
    {
        $text = $this->normalize($content);
        if ($text === '') {
            return false;
        }
        return preg_match(
            '/\b(quanto\s+custa|quanto\s+fica|qual(?:\s+e|\s+é)?\s+o\s+valor|valor|preco|preço|pagamento|pagar|pix|cartao|cartão|transferencia|transferência|forma(?:s)?\s+de\s+pagamento)\b/u',
            $text
        ) === 1;
    }

    /** @param array<string,mixed> $payment */
    private function stripUnaskedPaymentDisclosure(string $reply, array $payment): string
    {
        $sentences = preg_split('/(?<=[\.!?])\s+/u', trim($reply)) ?: [trim($reply)];
        $kept = [];
        foreach ($sentences as $sentence) {
            $sentence = trim((string) $sentence);
            if ($sentence === '') {
                continue;
            }

            $cleaned = $this->stripPaymentClause($sentence, $payment);
            if ($cleaned !== '') {
                $kept[] = $cleaned;
            }
        }
        return trim(implode(' ', $kept));
    }

    /** @param array<string,mixed> $payment */
    private function stripPaymentClause(string $sentence, array $payment): string
    {
        $patterns = [
            '/\b(?:o\s+)?(?:valor|preço|preco|investimento)\b/iu',
            '/\b(?:pagamento|pagar|pix|cart[aã]o|transfer[eê]ncia)\b/iu',
            '/R\$\s*\d/iu',
            '/\b\d+(?:[\.,]\d{1,2})?\s*reais\b/iu',
        ];

        foreach ((array) ($payment['methods'] ?? []) as $method) {
            $method = trim((string) $method);
            if ($method !== '') {
                $patterns[] = '/(?<![\p{L}\p{N}])' . preg_quote($method, '/') . '(?![\p{L}\p{N}])/iu';
            }
        }

        $firstOffset = null;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $sentence, $match, PREG_OFFSET_CAPTURE) === 1) {
                $offset = (int) ($match[0][1] ?? 0);
                $firstOffset = $firstOffset === null ? $offset : min($firstOffset, $offset);
            }
        }
        if ($firstOffset === null) {
            return $sentence;
        }

        $prefix = rtrim(substr($sentence, 0, $firstOffset));
        // Retira a conjunção/pontuação que introduzia a informação de pagamento.
        $prefix = preg_replace('/(?:,\s*)?(?:e|al[eé]m\s+disso)\s*$/iu', '', $prefix) ?? $prefix;
        $prefix = rtrim($prefix, " ,;:-\t\n\r\0\x0B");
        if (mb_strlen($prefix) < 12) {
            return '';
        }
        return rtrim($prefix, '.!?') . '.';
    }

    private function keepOnlyFirstQuestion(string $reply): string
    {
        if (substr_count($reply, '?') <= 1) {
            return $reply;
        }
        $sentences = preg_split('/(?<=[\.!?])\s+/u', trim($reply)) ?: [trim($reply)];
        $kept = [];
        $questionSeen = false;
        foreach ($sentences as $sentence) {
            $sentence = trim((string) $sentence);
            if ($sentence === '') {
                continue;
            }
            if (str_contains($sentence, '?')) {
                if ($questionSeen) {
                    continue;
                }
                $questionSeen = true;
            }
            $kept[] = $sentence;
        }
        return trim(implode(' ', $kept));
    }

    /** @return array<int,string> */
    public function splitReply(int $tenantId, string $reply, ?PDO $pdo = null): array
    {
        return $this->splitReplyWithSettings($this->settingsForTenant($tenantId, $pdo), $reply);
    }

    /** @param array<string,mixed> $settings @return array<int,string> */
    public function splitReplyWithSettings(array $settings, string $reply): array
    {
        $reply = trim($reply);
        if ($reply === '') {
            return [];
        }
        $delivery = is_array($settings['response_delivery'] ?? null) ? $settings['response_delivery'] : [];
        $mode = (string) ($delivery['mode'] ?? 'auto');
        $max = max(2, min(4, (int) ($delivery['max_blocks'] ?? 3)));
        if ($mode === 'single') {
            return [$reply];
        }

        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/u', $reply) ?: [])));
        if (count($paragraphs) >= 2) {
            return $this->capBlocks($paragraphs, $max);
        }

        $sentences = array_values(array_filter(array_map('trim', preg_split('/(?<=[\.!?])\s+(?=[\p{Lu}\d])/u', $reply) ?: [])));
        if (count($sentences) < 2) {
            return [$reply];
        }

        // "Automático" não depende apenas de o modelo inserir linhas em branco.
        // Respostas longas ou com uma pergunta final são separadas pelo backend para
        // respeitar a configuração mesmo quando o provedor devolve um parágrafo único.
        if ($mode === 'auto') {
            $hasFinalQuestion = str_ends_with(trim((string) end($sentences)), '?');
            if (mb_strlen($reply) < 190 && !$hasFinalQuestion) {
                return [$reply];
            }
            $target = min($max, max(2, (int) ceil(mb_strlen($reply) / 220)));
            if ($hasFinalQuestion && count($sentences) >= 2) {
                $question = array_pop($sentences);
                $body = trim(implode(' ', $sentences));
                $bodyBlocks = $body !== '' ? $this->balancedSentenceBlocks($body, max(1, $target - 1)) : [];
                return $this->capBlocks(array_values(array_filter(array_merge($bodyBlocks, [$question]))), $max);
            }
            return $this->balancedSentenceBlocks($reply, $target);
        }

        return $this->balancedSentenceBlocks($reply, min($max, count($sentences)));
    }

    /** @return array<string,mixed>|null */
    public function matchSpecialRoute(int $tenantId, string $content, ?PDO $pdo = null): ?array
    {
        return $this->matchSpecialRouteWithSettings($this->settingsForTenant($tenantId, $pdo), $content);
    }

    /** @param array<string,mixed> $settings @return array<string,mixed>|null */
    public function matchSpecialRouteWithSettings(array $settings, string $content): ?array
    {
        $text = $this->normalize($content);
        if ($text === '') {
            return null;
        }
        foreach ((array) ($settings['special_routes'] ?? []) as $route) {
            if (!is_array($route) || empty($route['enabled'])) {
                continue;
            }
            foreach (self::keywordList((string) ($route['keywords'] ?? '')) as $keyword) {
                $needle = $this->normalize($keyword);
                if ($needle !== '' && str_contains(' ' . $text . ' ', ' ' . $needle . ' ')) {
                    return $route + ['matched_keyword' => $keyword];
                }
                if ($needle !== '' && mb_strlen($needle) >= 5 && str_contains($text, $needle)) {
                    return $route + ['matched_keyword' => $keyword];
                }
            }
        }
        return null;
    }

    /**
     * Coloca a conversa em atendimento humano e cria um aviso interno.
     * @param array<string,mixed> $route
     * @return array<string,mixed>
     */
    public function handoffSpecialRoute(PDO $pdo, int $tenantId, int $conversationId, array $route): array
    {
        $targetUserId = $this->activeTargetUser($pdo, $tenantId, (int) ($route['target_user_id'] ?? 0));
        $label = trim((string) ($route['label'] ?? 'Encaminhamento especial')) ?: 'Encaminhamento especial';

        $fields = [
            'assigned_user_id = :assigned_user_id',
            'attendance_mode = :attendance_mode',
            'operational_status = :operational_status',
            'department_id = NULL',
        ];
        $params = [
            'assigned_user_id' => $targetUserId ?: null,
            'attendance_mode' => $targetUserId > 0 ? 'human' : 'paused',
            'operational_status' => $targetUserId > 0 ? 'in_service' : 'waiting_agent',
            'id' => $conversationId,
            'tenant_id' => $tenantId,
        ];
        if ($this->hasColumn($pdo, 'conversations', 'assigned_at')) {
            $fields[] = 'assigned_at = :assigned_at';
            $params['assigned_at'] = $targetUserId > 0 ? \App\Core\Clock::nowUtc() : null;
        }
        if ($this->hasColumn($pdo, 'conversations', 'assignment_source')) {
            $fields[] = 'assignment_source = "special_route"';
        }
        $pdo->prepare('UPDATE conversations SET ' . implode(', ', $fields) . ' WHERE id = :id AND tenant_id = :tenant_id')->execute($params);

        if ($this->hasTable($pdo, 'conversation_flow_states')) {
            try {
                $pdo->prepare(
                    'UPDATE conversation_flow_states SET stage = "human_handoff", last_intent = :intent, updated_at = CURRENT_TIMESTAMP
                     WHERE tenant_id = :tenant_id AND conversation_id = :conversation_id'
                )->execute([
                    'intent' => mb_substr('special:' . $this->normalize($label), 0, 80),
                    'tenant_id' => $tenantId,
                    'conversation_id' => $conversationId,
                ]);
            } catch (Throwable) {
            }
        }

        if ($this->hasTable($pdo, 'conversation_events')) {
            try {
                $pdo->prepare(
                    'INSERT INTO conversation_events (tenant_id, conversation_id, event_type, description, metadata_json)
                     VALUES (:tenant_id, :conversation_id, "agent.special_route", :description, :metadata_json)'
                )->execute([
                    'tenant_id' => $tenantId,
                    'conversation_id' => $conversationId,
                    'description' => 'Conversa encaminhada por assunto especial: ' . mb_substr($label, 0, 220),
                    'metadata_json' => json_encode([
                        'route_label' => $label,
                        'matched_keyword' => $route['matched_keyword'] ?? null,
                        'target_user_id' => $targetUserId ?: null,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            } catch (Throwable) {
            }
        }

        $targetName = $this->userName($pdo, $targetUserId);
        try {
            (new NotificationService())->create(
                $tenantId,
                'Encaminhamento especial: ' . $label,
                ($targetName !== '' ? $targetName . ', existe ' : 'Existe ') . 'uma conversa aguardando continuidade humana.',
                'warning',
                '/conversations?conversation_id=' . $conversationId,
                'messages',
                'agent.special_route',
                'conversation',
                $conversationId,
                ['target_user_id' => $targetUserId ?: null, 'route_label' => $label]
            );
        } catch (Throwable) {
        }

        return [
            'target_user_id' => $targetUserId ?: null,
            'target_user_name' => $targetName,
            'label' => $label,
        ];
    }

    /** @param array<string,mixed> $appointment */
    public function handleNoAvailability(int $tenantId, int $conversationId, array $appointment = []): array
    {
        $settings = $this->settingsForTenant($tenantId);
        $policy = is_array($settings['no_availability'] ?? null) ? $settings['no_availability'] : [];
        $action = (string) ($policy['action'] ?? 'message_only');
        if ($action === 'message_only' || $conversationId < 1) {
            return ['action' => 'message_only'];
        }

        $pdo = Database::connection();
        $contactName = trim((string) ($appointment['contact_name'] ?? '')) ?: 'Contato';
        $targetUserId = $this->activeTargetUser($pdo, $tenantId, (int) ($policy['target_user_id'] ?? 0));
        if ($action === 'handoff') {
            $route = [
                'label' => 'Sem disponibilidade de agenda',
                'target_user_id' => $targetUserId,
                'matched_keyword' => 'calendar.no_availability',
            ];
            $result = $this->handoffSpecialRoute($pdo, $tenantId, $conversationId, $route);
            return ['action' => 'handoff'] + $result;
        }

        try {
            $targetName = $this->userName($pdo, $targetUserId);
            (new NotificationService())->create(
                $tenantId,
                'Agenda sem disponibilidade',
                $contactName . ' não encontrou vaga para a preferência informada.' . ($targetName !== '' ? ' Responsável: ' . $targetName . '.' : ''),
                'warning',
                '/conversations?conversation_id=' . $conversationId,
                'calendar',
                'calendar.no_availability',
                'conversation',
                $conversationId,
                ['target_user_id' => $targetUserId ?: null]
            );
        } catch (Throwable) {
        }
        return ['action' => 'notify', 'target_user_id' => $targetUserId ?: null];
    }

    /** @param array<string,mixed> $appointment @param array<int,array<string,mixed>> $slots @return array<int,array<string,mixed>> */
    public function filterSlotsForAppointment(int $tenantId, array $appointment, array $slots): array
    {
        $settings = $this->settingsForTenant($tenantId);
        return $this->filterSlotsWithSettings($settings, $appointment, $slots);
    }

    /** @param array<string,mixed> $settings @param array<string,mixed> $appointment @param array<int,array<string,mixed>> $slots @return array<int,array<string,mixed>> */
    public function filterSlotsWithSettings(array $settings, array $appointment, array $slots): array
    {
        $modality = strtolower(trim((string) ($appointment['appointment_modality'] ?? $appointment['location_type'] ?? '')));
        if (!in_array($modality, ['presencial', 'online'], true)) {
            return $slots;
        }
        $config = is_array($settings['modalities'][$modality] ?? null) ? $settings['modalities'][$modality] : [];
        $days = is_array($config['allowed_days'] ?? null) ? $config['allowed_days'] : [];
        if (empty($config['enabled']) || $days === []) {
            return $slots;
        }
        $timezone = trim((string) ($appointment['timezone'] ?? 'America/Sao_Paulo')) ?: 'America/Sao_Paulo';
        try {
            $tz = new DateTimeZone($timezone);
        } catch (Throwable) {
            $tz = new DateTimeZone('America/Sao_Paulo');
        }
        $dayMap = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
        return array_values(array_filter($slots, static function (array $slot) use ($days, $tz, $dayMap): bool {
            $startsAt = trim((string) ($slot['starts_at'] ?? $slot['start'] ?? ''));
            if ($startsAt === '') {
                return false;
            }
            try {
                $date = new DateTimeImmutable($startsAt, new DateTimeZone('UTC'));
                $local = $date->setTimezone($tz);
                $key = $dayMap[(int) $local->format('N')] ?? '';
                return in_array($key, $days, true);
            } catch (Throwable) {
                return false;
            }
        }));
    }

    public function clearCache(int $tenantId): void
    {
        unset(self::$settingsCache[$tenantId]);
    }

    /** @return array<string,mixed> */
    private function defaults(): array
    {
        return [
            'demand' => [
                'enabled' => false,
                'required_before_schedule' => false,
                'prompt' => 'Antes de avançarmos, pode me contar brevemente o que você está buscando neste atendimento?',
            ],
            'response_delivery' => ['mode' => 'auto', 'max_blocks' => 3, 'scope' => 'asked_only'],
            'modalities' => [
                'online' => ['enabled' => false, 'channel' => '', 'location' => '', 'allowed_days' => [], 'message' => ''],
                'presencial' => ['enabled' => false, 'channel' => '', 'location' => '', 'allowed_days' => [], 'message' => ''],
            ],
            'payment' => ['enabled' => false, 'value_text' => '', 'methods' => [], 'message' => ''],
            'no_availability' => [
                'message' => 'No momento não encontrei horários disponíveis para essa preferência. Posso registrar seu interesse e pedir outra opção de dia ou período.',
                'action' => 'message_only',
                'target_user_id' => 0,
            ],
            'special_routes' => [],
        ];
    }

    /** @return array<int,string> */
    private function balancedSentenceBlocks(string $reply, int $target): array
    {
        $sentences = array_values(array_filter(array_map('trim', preg_split('/(?<=[\.!?])\s+(?=[\p{Lu}\d])/u', trim($reply)) ?: [])));
        if ($sentences === []) {
            return [trim($reply)];
        }
        $target = max(1, min($target, count($sentences)));
        if ($target === 1) {
            return [trim(implode(' ', $sentences))];
        }
        $blocks = array_fill(0, $target, '');
        foreach ($sentences as $index => $sentence) {
            $slot = min($target - 1, (int) floor($index * $target / count($sentences)));
            $blocks[$slot] = trim($blocks[$slot] . ' ' . $sentence);
        }
        return array_values(array_filter($blocks));
    }

    /** @param array<int,string> $blocks @return array<int,string> */
    private function capBlocks(array $blocks, int $max): array
    {
        if (count($blocks) <= $max) {
            return $blocks;
        }
        $head = array_slice($blocks, 0, $max - 1);
        $head[] = implode("\n\n", array_slice($blocks, $max - 1));
        return $head;
    }

    /** @return array<int,string> */
    private static function keywordList(string $value): array
    {
        $items = preg_split('/[,;\n]+/u', $value) ?: [];
        $result = [];
        foreach ($items as $item) {
            $item = self::cleanText($item, 120);
            if ($item !== '' && !in_array($item, $result, true)) {
                $result[] = $item;
            }
        }
        return array_slice($result, 0, 30);
    }

    private static function cleanText(mixed $value, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? (string) $value);
        return mb_substr($text, 0, $max);
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($converted) && $converted !== '') {
            $value = mb_strtolower($converted);
        }
        $value = preg_replace('/[^a-z0-9\s]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function dayLabel(string $day): string
    {
        return [
            'mon' => 'segunda-feira', 'tue' => 'terça-feira', 'wed' => 'quarta-feira',
            'thu' => 'quinta-feira', 'fri' => 'sexta-feira', 'sat' => 'sábado', 'sun' => 'domingo',
        ][$day] ?? $day;
    }

    private function activeTargetUser(PDO $pdo, int $tenantId, int $userId): int
    {
        if ($userId < 1) {
            return 0;
        }
        try {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE id = :id AND tenant_id = :tenant_id AND status = "active" LIMIT 1');
            $stmt->execute(['id' => $userId, 'tenant_id' => $tenantId]);
            return (int) ($stmt->fetchColumn() ?: 0);
        } catch (Throwable) {
            return 0;
        }
    }

    private function userName(PDO $pdo, int $userId): string
    {
        if ($userId < 1) {
            return '';
        }
        try {
            $stmt = $pdo->prepare('SELECT COALESCE(NULLIF(whatsapp_display_name, ""), name) FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $userId]);
            return trim((string) ($stmt->fetchColumn() ?: ''));
        } catch (Throwable) {
            return '';
        }
    }

    private function hasTable(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
            $stmt->execute(['table' => $table]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    private function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column LIMIT 1');
            $stmt->execute(['table' => $table, 'column' => $column]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }
}

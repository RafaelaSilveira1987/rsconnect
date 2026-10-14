<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeZone;

/**
 * Avaliador puro: não tem PDO, não altera configurações nem executa a LLM.
 * Os pesos são fixos (100 pontos); críticos bloqueiam mesmo com score alto.
 */
final class AgentReadinessEvaluator
{
    /** @param array<string,mixed> $snapshot
     * @return array{status:string,readiness_score:int,can_operate:bool,checklist:list<array<string,mixed>>}
     */
    public function evaluate(array $snapshot): array
    {
        $agent = $snapshot['agent'] ?? [];
        $profile = $snapshot['profile'] ?? [];
        $config = is_array($profile['config'] ?? null) ? $profile['config'] : [];
        $express = is_array($config['express'] ?? null) ? $config['express'] : [];
        $routing = is_array($express['routing'] ?? null) ? $express['routing'] : [];
        $commercial = is_array($express['commercial'] ?? null) ? $express['commercial'] : [];
        $capabilities = is_array($snapshot['capabilities'] ?? null) ? $snapshot['capabilities'] : [];
        $channels = is_array($snapshot['channels'] ?? null) ? $snapshot['channels'] : [];
        $fields = is_array($snapshot['triage_fields'] ?? null) ? $snapshot['triage_fields'] : [];
        $workflow = is_array($snapshot['workflow'] ?? null) ? $snapshot['workflow'] : [];
        $policies = is_array($snapshot['policies'] ?? null) ? $snapshot['policies'] : [];

        $checks = [];
        $add = static function (string $category, string $item, string $status, string $severity,
            string $message, int $weight, ?string $action = null) use (&$checks): void {
            $check = [
                'category' => $category, 'item' => $item, 'status' => $status,
                'severity' => $severity, 'message' => $message,
                'auto_fix_available' => $action !== null, 'weight' => $weight,
            ];
            if ($action !== null) $check['auto_fix_action'] = $action;
            $checks[] = $check;
        };

        $configured = !empty($profile['id']) && !empty($profile['blueprint_version_id'])
            && ($profile['status'] ?? '') === 'active' && trim((string) ($agent['name'] ?? '')) !== '';
        $add('blueprint', 'Perfil e blueprint', $configured ? 'ok' : 'error', 'critical',
            $configured ? 'Perfil e versão de blueprint encontrados.' : 'É necessário aplicar um blueprint publicado ao agente.', 14);

        $connected = array_values(array_filter($channels, static fn ($ch) =>
            is_array($ch) && ($ch['binding_status'] ?? '') === 'active'
            && ($ch['status'] ?? '') === 'connected'
            && (int) ($ch['receive_messages'] ?? 0) === 1
            && (int) ($ch['webhook_enabled'] ?? 0) === 1
            && ($ch['identity_status'] ?? '') !== 'mismatch'
        ));
        $phone = trim((string) ($connected[0]['authorized_phone'] ?? $connected[0]['profile_phone'] ?? ''));
        $add('channel_integration', 'Conexão com WhatsApp', $connected ? 'ok' : 'error', 'critical',
            $connected ? 'Canal conectado e apto a receber mensagens segundo o estado salvo' . ($phone !== '' ? ' (' . $phone . ').' : '.')
                       : 'Associe um canal ativo, conectado, com webhook e recepção habilitados ao agente.', 18);

        $hasCredential = !empty($snapshot['credentials']);
        $add('channel_integration', 'Credencial de IA', $hasCredential ? 'ok' : 'error', 'critical',
            $hasCredential ? 'Credencial de provedor de IA ativa encontrada.' : 'Nenhuma credencial de IA ativa para o agente ou sua empresa.', 14);

        $hoursOk = $this->validBusinessHours($agent);
        $add('routing_and_schedule', 'Horário e fuso operacional', $hoursOk ? 'ok' : 'error', 'critical',
            $hoursOk ? 'Fuso e intervalos de funcionamento válidos.' : 'Configure o fuso e pelo menos um intervalo válido de funcionamento no agente.', 12);

        $validActions = ['capture_lead_and_promise_contact', 'handoff_to_human', 'inform_business_hours'];
        $action = $routing['out_of_hours_action'] ?? null;
        $afterHoursMessage = trim((string) ($agent['after_hours_message'] ?? ''));
        $outsidePolicy = $this->policy($policies, 'express.out_of_hours');
        $outOfHoursOk = in_array($action, $validActions, true) && $afterHoursMessage !== ''
            && $outsidePolicy !== null && ($outsidePolicy['value'] ?? null) === $action;
        $canFixOutside = !$outOfHoursOk && !empty($profile['id']) && $express !== [];
        $add('routing_and_schedule', 'Ação fora do expediente', $outOfHoursOk ? 'ok' : 'error', 'critical',
            $outOfHoursOk ? 'Ação e mensagem fora do expediente definidas.' : 'Defina uma ação estruturada e uma resposta fora do horário.',
            12, $canFixOutside ? 'set_default_out_of_hours_action' : null);

        $triageOk = count(array_filter($fields, static fn ($field) => is_array($field) && !empty($field['active']))) > 0;
        if ($triageOk) {
            foreach ($fields as $field) {
                if (empty($field['active'])) continue;
                if (trim((string) ($field['prompt_text'] ?? '')) === '' || trim((string) ($field['field_key'] ?? '')) === '') {
                    $triageOk = false;
                    break;
                }
            }
        }
        $add('triage', 'Perguntas de triagem', $triageOk ? 'ok' : 'error', 'critical',
            $triageOk ? 'Campos ativos possuem identificador e pergunta.' : 'Cadastre ao menos uma pergunta ativa e preencha todas as perguntas obrigatórias.', 6);

        $workflowAudit = (new AgentRuntimeConfigurationService())->audit([
            'triage_fields' => $fields, 'workflow' => $workflow, 'policies' => $policies,
        ]);
        $workflowOk = count(array_filter($workflow, static fn ($step) => is_array($step) && !empty($step['active']))) > 0
            && $workflowAudit['ok'];
        $add('workflow', 'Execução do fluxo e políticas', $workflowOk ? 'ok' : 'error', 'critical',
            $workflowOk ? 'Nenhum conflito crítico detectado no fluxo.' :
                ($workflow === [] ? 'Nenhum fluxo de atendimento foi configurado.' : implode(' ', array_slice($workflowAudit['errors'], 0, 3))), 8);

        // Agenda é uma capacidade opcional, mas, quando habilitada, requer
        // infraestrutura real de pré-agendamento e disponibilidade no tenant.
        $requiresAvailability = ($capabilities['calendar.read'] ?? false) === true;
        $requiresHumanApproval = ($capabilities['calendar.human_approval'] ?? false) === true;
        $preSchedule = is_array($snapshot['pre_schedule_settings'] ?? null) ? $snapshot['pre_schedule_settings'] : [];
        $availability = is_array($snapshot['calendar_availability_settings'] ?? null) ? $snapshot['calendar_availability_settings'] : [];
        $scheduleOk = (!$requiresAvailability || ((int) ($preSchedule['enabled'] ?? 0) === 1
                && (int) ($availability['enabled'] ?? 0) === 1))
            && (!$requiresHumanApproval || ((int) ($preSchedule['enabled'] ?? 0) === 1
                && (int) ($preSchedule['require_human_approval'] ?? 0) === 1
                && (int) ($preSchedule['ai_can_confirm'] ?? 1) === 0));
        $add('routing_and_schedule', 'Agenda e aprovação humana', $scheduleOk ? 'ok' : 'error', 'critical',
            $scheduleOk ? 'Agenda compatível com as capacidades selecionadas.'
                : 'A agenda/triagem não está habilitada ou a aprovação humana contradiz as capabilities.', 8);

        $pricingStrategy = $commercial['pricing_strategy'] ?? null;
        $price = trim((string) ($commercial['price_value'] ?? ''));
        $commercialConfigured = is_string($pricingStrategy) && in_array($pricingStrategy, ['fixed_price', 'on_request', 'variable_price'], true);
        $priceValid = $pricingStrategy !== 'fixed_price' || preg_match('/^R\$\s*\d[\d.]*,\d{2}$/u', $price) === 1;
        $storedPricePolicy = $this->policy($policies, 'express.fixed_price');
        $expected = $pricingStrategy === 'fixed_price' ? $price : $pricingStrategy;
        $storedPaymentPolicy = $this->policy($policies, 'express.payment_methods');
        $paymentMethods = $commercial['payment_methods'] ?? [];
        $paymentMatches = is_array($paymentMethods) && $storedPaymentPolicy !== null
            && ($storedPaymentPolicy['value'] ?? null) === implode(',', $paymentMethods);
        $guardrail = $pricingStrategy !== 'fixed_price' || str_contains(
            (string) ($storedPricePolicy['customer_message'] ?? ''), 'Não invente preços nem negocie.');
        $policyMatches = $commercialConfigured && $storedPricePolicy !== null
            && ($storedPricePolicy['value'] ?? null) === $expected && $paymentMatches && $guardrail;
        $priceOk = $commercialConfigured && $priceValid && $policyMatches;
        $priceStatus = $priceOk ? 'ok' : ($commercialConfigured ? 'error' : 'warning');
        $add('commercial_policies', 'Política comercial', $priceStatus,
            $priceStatus === 'error' ? 'critical' : 'high',
            $priceOk ? 'Estratégia e regra de preço publicadas de forma consistente.'
                : 'Revise o preço e a política comercial persistida: as duas fontes precisam coincidir.', 4);

        $linkFlag = ($capabilities['calendar.confirmation_link'] ?? false) === true;
        $tool = is_array($express['tools']['calendar.confirmed.meeting_link'] ?? null)
            ? $express['tools']['calendar.confirmed.meeting_link'] : [];
        $linkEnabled = $linkFlag || !empty($tool['enabled']);
        $link = trim((string) ($routing['default_meeting_link'] ?? ''));
        $linkOk = !$linkEnabled || $this->validHttps($link);
        $fixLink = !$linkOk && !empty($profile['id']) && $express !== [];
        $add('capabilities', 'Link de reunião', $linkOk ? 'ok' : 'warning', 'high',
            !$linkEnabled ? 'Envio automático de link desativado.'
                : ($linkOk ? 'Link HTTPS de reunião configurado.' : 'A capability de envio de link está ativa, mas o link padrão está ausente ou inválido.'),
            4, $fixLink ? 'disable_meeting_link_capability' : null);

        $weightFailed = array_sum(array_map(static fn ($c) => $c['status'] === 'ok' ? 0 : $c['weight'], $checks));
        $score = max(0, min(100, 100 - $weightFailed));
        $canOperate = !array_filter($checks, static fn ($c) => $c['status'] === 'error' && $c['severity'] === 'critical');
        // "ACTIVE" reflete operação ligada; "READY_TO_OPERATE" não efetua ativação.
        $active = ($agent['status'] ?? '') === 'active' && (int) ($agent['auto_reply_enabled'] ?? 0) === 1;
        $status = !$canOperate ? 'CONFIGURING' : ($active ? 'ACTIVE' : 'READY_TO_OPERATE');
        foreach ($checks as &$check) unset($check['weight']);
        unset($check);
        return ['status' => $status, 'readiness_score' => $score, 'can_operate' => $canOperate, 'checklist' => $checks];
    }

    /** @param list<array<string,mixed>> $policies
     *  @return array<string,mixed>|null */
    private function policy(array $policies, string $key): ?array
    {
        foreach ($policies as $policy) {
            if (is_array($policy) && ($policy['policy_key'] ?? '') === $key && !empty($policy['enabled'])) return $policy;
        }
        return null;
    }

    /** @param array<string,mixed> $agent */
    private function validBusinessHours(array $agent): bool
    {
        if ((int) ($agent['business_hours_enabled'] ?? 0) !== 1) return false;
        $timezone = trim((string) ($agent['business_timezone'] ?? ''));
        if ($timezone === '' || !in_array($timezone, DateTimeZone::listIdentifiers(), true)) return false;
        $json = $agent['business_hours_json'] ?? null;
        $ranges = is_array($json) ? $json : (is_string($json) ? json_decode($json, true) : null);
        if (!is_array($ranges)) return false;
        $present = false;
        foreach ($ranges as $day => $slots) {
            if (!in_array($day, ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'], true) || !is_array($slots)) return false;
            $previousEnd = '';
            foreach ($slots as $slot) {
                if (!is_array($slot) || count($slot) !== 2) return false;
                [$start, $end] = array_values($slot);
                if (!is_string($start) || !is_string($end) || !$this->validTime($start) || !$this->validTime($end)
                    || $start >= $end || ($previousEnd !== '' && $start < $previousEnd)) return false;
                $previousEnd = $end;
                $present = true;
            }
        }
        return $present;
    }

    private function validTime(string $time): bool
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time) === 1;
    }

    private function validHttps(string $url): bool
    {
        return $url !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }
}

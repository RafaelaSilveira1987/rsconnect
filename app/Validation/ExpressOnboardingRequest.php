<?php

declare(strict_types=1);

namespace App\Validation;

use App\DTO\ExpressOnboardingData;
use App\Enums\ExpressSegment;
use App\Enums\ExpressTone;
use DateTimeZone;

/** Equivalente ao FormRequest, sem a dependência Laravel não presente no projeto. */
final class ExpressOnboardingRequest
{
    /** @param array<string,mixed> $payload */
    public static function validate(array $payload): ExpressOnboardingData
    {
        $errors = [];
        $object = static function (mixed $v, string $path) use (&$errors): array {
            if (!is_array($v) || array_is_list($v)) {
                $errors[$path] = 'Deve ser um objeto JSON.';
                return [];
            }
            return $v;
        };
        $str = static function (array $source, string $key, string $path, int $max, bool $required = true) use (&$errors): ?string {
            $v = $source[$key] ?? null;
            if ($v === null && !$required) {
                return null;
            }
            if (!is_string($v) || ($required && trim($v) === '') || mb_strlen($v) > $max || preg_match('/[\x00-\x1F\x7F]/', $v)) {
                $errors[$path] = 'Texto obrigatório/válido (máximo ' . $max . ' caracteres).';
                return null;
            }
            return trim($v);
        };
        $bool = static function (array $source, string $key, string $path) use (&$errors): bool {
            if (!array_key_exists($key, $source) || !is_bool($source[$key])) {
                $errors[$path] = 'Deve ser booleano JSON (true/false).';
                return false;
            }
            return $source[$key];
        };
        foreach (['business_info','commercial_info','triage_config','routing_and_schedule','knowledge_base','capabilities'] as $section) {
            if (!array_key_exists($section, $payload)) {
                $errors[$section] = 'Bloco obrigatório.';
            }
        }
        $business = $object($payload['business_info'] ?? null, 'business_info');
        $commercial = $object($payload['commercial_info'] ?? null, 'commercial_info');
        $triage = $object($payload['triage_config'] ?? null, 'triage_config');
        $schedule = $object($payload['routing_and_schedule'] ?? null, 'routing_and_schedule');
        $knowledge = $object($payload['knowledge_base'] ?? null, 'knowledge_base');
        $caps = $object($payload['capabilities'] ?? null, 'capabilities');

        $name = $str($business, 'agent_name', 'business_info.agent_name', 120) ?? '';
        $segmentRaw = $str($business, 'segment', 'business_info.segment', 60) ?? '';
        $segment = ExpressSegment::tryFrom($segmentRaw);
        if ($segment === null) $errors['business_info.segment'] = 'Segmento não suportado.';
        $toneRaw = $str($business, 'tone_of_voice', 'business_info.tone_of_voice', 60) ?? '';
        $tone = ExpressTone::tryFrom($toneRaw);
        if ($tone === null) $errors['business_info.tone_of_voice'] = 'Tom de voz não suportado.';

        $pricing = $str($commercial, 'pricing_strategy', 'commercial_info.pricing_strategy', 30) ?? '';
        if (!in_array($pricing, ['fixed_price','variable_price','on_request'], true)) {
            $errors['commercial_info.pricing_strategy'] = 'Estratégia comercial não suportada.';
        }
        $price = $str($commercial, 'price_value', 'commercial_info.price_value', 40, false);
        if ($pricing === 'fixed_price' && ($price === null || preg_match('/^R\$\s*(?:\d{1,3}(?:\.\d{3})*|\d+),\d{2}$/u', $price) !== 1)) {
            $errors['commercial_info.price_value'] = 'Preço fixo deve estar no formato R$ 250,00.';
        }
        if ($pricing !== 'fixed_price' && $price !== null && $price !== '') {
            $errors['commercial_info.price_value'] = 'Informe preço somente quando fixed_price.';
        }
        $methods = $commercial['payment_methods'] ?? null;
        if (!is_array($methods) || !array_is_list($methods) || count($methods) > 5) {
            $errors['commercial_info.payment_methods'] = 'Lista de meios de pagamento inválida.';
            $methods = [];
        }
        foreach ($methods as $i => $method) {
            if (!is_string($method) || !in_array($method, ['pix','credit_card','debit_card','bank_transfer','cash'], true)) {
                $errors["commercial_info.payment_methods.$i"] = 'Meio de pagamento não autorizado.';
            }
        }
        if (count(array_filter($methods, 'is_string')) === count($methods) && count(array_unique($methods)) !== count($methods)) $errors['commercial_info.payment_methods'] = 'Meios duplicados.';

        $collectName = $bool($triage, 'collect_name', 'triage_config.collect_name');
        $collectPhone = $bool($triage, 'collect_phone', 'triage_config.collect_phone');
        $questions = $triage['custom_questions'] ?? null;
        if (!is_array($questions) || !array_is_list($questions) || count($questions) > 12) {
            $errors['triage_config.custom_questions'] = 'Até 12 perguntas em lista.';
            $questions = [];
        }
        foreach ($questions as $i => $question) {
            if (!is_string($question) || mb_strlen(trim($question)) < 5 || mb_strlen($question) > 300 || preg_match('/[\x00-\x1F]/', $question)) {
                $errors["triage_config.custom_questions.$i"] = 'Pergunta inválida.';
            }
        }
        if (count(array_unique(array_map(static fn ($q) => mb_strtolower(trim((string) $q)), $questions))) !== count($questions)) {
            $errors['triage_config.custom_questions'] = 'Perguntas duplicadas.';
        }

        $timezone = $str($schedule, 'timezone', 'routing_and_schedule.timezone', 80) ?? '';
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $errors['routing_and_schedule.timezone'] = 'Timezone IANA inválido.';
        }
        $hours = $schedule['business_hours'] ?? null;
        if (!is_array($hours) || !array_is_list($hours) || !$hours || count($hours) > 21) {
            $errors['routing_and_schedule.business_hours'] = 'Informe de 1 a 21 períodos.';
            $hours = [];
        }
        $dayValues = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
        $rangesByDay = [];
        foreach ($hours as $i => $range) {
            if (!is_array($range) || !in_array($range['day'] ?? null, $dayValues, true)
                || !is_string($range['start'] ?? null) || !is_string($range['end'] ?? null)
                || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $range['start'])
                || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $range['end'])
                || $range['start'] >= $range['end']) {
                $errors["routing_and_schedule.business_hours.$i"] = 'Dia/período inválido ou atravessando a meia-noite.';
                continue;
            }
            $rangesByDay[$range['day']][] = [$range['start'], $range['end']];
        }
        foreach ($rangesByDay as $day => $ranges) {
            usort($ranges, static fn ($a,$b) => strcmp($a[0], $b[0]));
            for ($i=1; $i<count($ranges); $i++) {
                if ($ranges[$i][0] < $ranges[$i-1][1]) {
                    $errors['routing_and_schedule.business_hours'] = "Períodos sobrepostos em $day.";
                }
            }
        }
        $afterAction = $str($schedule, 'out_of_hours_action', 'routing_and_schedule.out_of_hours_action', 60) ?? '';
        if (!in_array($afterAction, ['capture_lead_and_promise_contact','inform_business_hours','handoff_to_human'], true)) {
            $errors['routing_and_schedule.out_of_hours_action'] = 'Ação fora do expediente inválida.';
        }
        $link = $str($schedule, 'default_meeting_link', 'routing_and_schedule.default_meeting_link', 500, false);
        if ($link !== null && $link !== '' && (!filter_var($link, FILTER_VALIDATE_URL) || parse_url($link, PHP_URL_SCHEME) !== 'https')) {
            $errors['routing_and_schedule.default_meeting_link'] = 'Use um link HTTPS válido.';
        }
        $faq = $knowledge['faq'] ?? null;
        if (!is_array($faq) || !array_is_list($faq) || count($faq) > 40) {
            $errors['knowledge_base.faq'] = 'FAQ deve conter até 40 perguntas.';
            $faq = [];
        }
        foreach ($faq as $i => $item) {
            if (!is_array($item)) {
                $errors["knowledge_base.faq.$i"] = 'FAQ inválido.';
                continue;
            }
            $str($item,'question',"knowledge_base.faq.$i.question",250);
            $str($item,'answer',"knowledge_base.faq.$i.answer",1500);
        }
        if (strlen(json_encode($faq, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '') > 30000) {
            $errors['knowledge_base.faq'] = 'FAQ muito extenso (limite de 30 KiB).';
        }
        $reschedule = $bool($caps, 'allow_rescheduling', 'capabilities.allow_rescheduling');
        $escalate = $bool($caps, 'escalate_to_human_on_error', 'capabilities.escalate_to_human_on_error');
        $sendLink = $bool($caps, 'send_meeting_link_on_confirmation', 'capabilities.send_meeting_link_on_confirmation');
        if ($sendLink && ($link === null || $link === '')) {
            $errors['routing_and_schedule.default_meeting_link'] = 'Link obrigatório quando o envio está habilitado.';
        }
        // Bloco opcional para compatibilidade com o wizard da Fase 1 e clientes
        // antigos. Ausente = preservar modalidade, nunca zerar a configuração.
        $modalities = null;
        if (array_key_exists('service_modalities', $payload)) {
            $path = 'service_modalities';
            $rawModality = $object($payload['service_modalities'], $path);
            $modalityMode = $str($rawModality, 'mode', "$path.mode", 24) ?? '';
            if (!in_array($modalityMode, ['not_applicable','online','presencial','both'], true)) {
                $errors["$path.mode"] = 'Escolha: não se aplica, online, presencial ou ambos.';
            }
            $channel = $str($rawModality, 'online_channel', "$path.online_channel", 180, false) ?? '';
            $onlineMessage = $str($rawModality, 'online_message', "$path.online_message", 500, false) ?? '';
            $location = $str($rawModality, 'presencial_location', "$path.presencial_location", 300, false) ?? '';
            $presencialMessage = $str($rawModality, 'presencial_message', "$path.presencial_message", 500, false) ?? '';
            $allowed = $rawModality['presencial_days'] ?? null;
            if (!is_array($allowed) || !array_is_list($allowed) || count($allowed) > 7) {
                $errors["$path.presencial_days"] = 'Selecione dias válidos.';
                $allowed = [];
            }
            foreach ($allowed as $idx => $value) {
                if (!is_string($value) || !in_array($value, ['mon','tue','wed','thu','fri','sat','sun'], true)) {
                    $errors["$path.presencial_days.$idx"] = 'Dia inválido.';
                }
            }
            if (count(array_unique($allowed)) !== count($allowed)) {
                $errors["$path.presencial_days"] = 'Dias repetidos.';
            }
            if (in_array($modalityMode, ['online','both'], true) && $channel === '') {
                $errors["$path.online_channel"] = 'Informe como acontece o atendimento online.';
            }
            if (in_array($modalityMode, ['presencial','both'], true)) {
                if ($location === '') $errors["$path.presencial_location"] = 'Informe o local do atendimento presencial.';
                if ($allowed === []) $errors["$path.presencial_days"] = 'Marque pelo menos um dia presencial.';
            }
            $modalities = [
                'mode'=>$modalityMode, 'online_channel'=>$channel,
                'online_message'=>$onlineMessage, 'presencial_location'=>$location,
                'presencial_days'=>array_values($allowed),
                'presencial_message'=>$presencialMessage,
            ];
        }
        if ($errors !== []) throw new ExpressValidationException($errors);
        return new ExpressOnboardingData(
            $name, $segment, $tone, $pricing, $pricing === 'fixed_price' ? $price : null,
            array_values($methods), $collectName, $collectPhone, array_values(array_map('trim', $questions)),
            $timezone, array_values($hours), $afterAction, $link !== '' ? $link : null,
            array_values($faq), $reschedule, $escalate, $sendLink, $modalities
        );
    }
}

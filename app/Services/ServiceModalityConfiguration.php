<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Única projeção entre a tela Meu atendimento e conversation_behavior, que é
 * a fonte lida pelo motor de mensagens e pela política de modalidade da agenda.
 * Sem consultas, SQL ou efeito colateral; não altera horários do expediente.
 */
final class ServiceModalityConfiguration
{
    /** @return array{mode:string,online_channel:string,online_days:list<string>,online_message:string,presencial_location:string,presencial_days:list<string>,presencial_message:string} */
    public static function fromBehavior(array $behavior): array
    {
        // Leitura pura da configuração já persistida. Não normalizamos aqui:
        // o runtime normaliza ao executar; o editor não deve inventar modos.
        $service = is_array($behavior['service_mode'] ?? null) ? $behavior['service_mode'] : [];
        $modalities = is_array($behavior['modalities'] ?? null) ? $behavior['modalities'] : [];
        $online = is_array($modalities['online'] ?? null) ? $modalities['online'] : [];
        $presencial = is_array($modalities['presencial'] ?? null) ? $modalities['presencial'] : [];
        $onlineEnabled = !empty($online['enabled']);
        $presencialEnabled = !empty($presencial['enabled']);
        $mode = (string) ($service['mode'] ?? '');
        $fixed = (string) ($service['fixed_modality'] ?? 'presencial');
        // Preserva configurações legadas que não tinham service_mode explícito.
        if ($mode === '') {
            $selection = $onlineEnabled && $presencialEnabled ? 'both'
                : ($onlineEnabled ? 'online' : ($presencialEnabled ? 'presencial' : 'not_applicable'));
        } else {
            $selection = match ($mode) {
                'choice' => $onlineEnabled && $presencialEnabled ? 'both' : 'not_applicable',
                'single' => ($fixed === 'online' && $onlineEnabled) ? 'online'
                    : (($fixed === 'presencial' && $presencialEnabled) ? 'presencial' : 'not_applicable'),
                default => 'not_applicable',
            };
        }
        $normalizeDays = static fn (array $days): array => array_values(array_unique(array_filter($days,
            static fn (mixed $day): bool => is_string($day)
                && in_array($day, ['mon','tue','wed','thu','fri','sat','sun'], true))));
        $days = $normalizeDays((array) ($presencial['allowed_days'] ?? []));
        $onlineDays = $normalizeDays((array) ($online['allowed_days'] ?? []));
        return [
            'mode' => $selection,
            'online_channel' => (string) ($online['channel'] ?? ''),
            'online_days' => $onlineDays,
            'online_message' => (string) ($online['message'] ?? ''),
            'presencial_location' => (string) ($presencial['location'] ?? ''),
            'presencial_days' => $days,
            'presencial_message' => (string) ($presencial['message'] ?? ''),
        ];
    }

    /**
     * Aplica somente as propriedades operacionais de modalidade. Outras regras
     * conversacionais, pagamentos e orientações personalizadas permanecem.
     * @param array<string,mixed> $current
     * @param array<string,mixed> $selection Payload já validado pelo FormRequest
     * @return array<string,mixed>
     */
    public static function applyToBehavior(array $current, array $selection): array
    {
        $mode = $selection['mode'];
        $online = in_array($mode, ['online','both'], true);
        $presencial = in_array($mode, ['presencial','both'], true);
        $current['service_mode'] = [
            'mode' => match ($mode) {
                'both' => 'choice',
                'not_applicable' => 'not_applicable',
                default => 'single',
            },
            'fixed_modality' => $mode === 'online' ? 'online' : 'presencial',
        ];
        $current['modalities'] = is_array($current['modalities'] ?? null) ? $current['modalities'] : [];
        $current['modalities']['online'] = [
            'enabled' => $online,
            'channel' => $selection['online_channel'],
            'location' => '',
            'allowed_days' => $selection['online_days'] ?? [],
            'message' => $selection['online_message'],
        ];
        $current['modalities']['presencial'] = [
            'enabled' => $presencial,
            'channel' => '',
            'location' => $selection['presencial_location'],
            'allowed_days' => $selection['presencial_days'],
            'message' => $selection['presencial_message'],
        ];
        return AgentConversationBehaviorService::normalizeConfiguration($current);
    }
}

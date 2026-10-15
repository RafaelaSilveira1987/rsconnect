<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * Atualiza apenas projeções de leitura do perfil a partir da política operacional.
 * Nunca muda capacidade, permissão de confirmação ou janelas da agenda.
 * Não cria uma nova fonte de verdade: a autoridade do horário segue ai_agents.
 */
final class TenantConfigurationProjectionService
{
    public function syncConversationHours(PDO $pdo, int $tenantId, string $timezone, mixed $hours): void
    {
        $normalized = BusinessHoursNormalizer::normalize($hours);
        if ($normalized === null || $normalized === []) {
            throw new RuntimeException('O expediente deve conter ao menos um intervalo válido.');
        }
        $sql = 'SELECT config_json FROM tenant_agent_profiles WHERE tenant_id=:tid';
        if ($pdo->inTransaction()) $sql .= ' FOR UPDATE';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['tid'=>$tenantId]);
        $raw = $stmt->fetchColumn();
        if (!is_string($raw)) return; // Sem perfil Expressa: nenhuma projeção a atualizar.
        $config = json_decode($raw, true);
        if (!is_array($config) || !is_array($config['express'] ?? null)) return;
        if (!is_array($config['express']['routing'] ?? null)) $config['express']['routing'] = [];
        $config['express']['routing']['business_hours'] = $normalized;
        $config['express']['routing']['timezone'] = $timezone;
        $pdo->prepare('UPDATE tenant_agent_profiles SET config_json=:config WHERE tenant_id=:tid')
            ->execute(['tid'=>$tenantId, 'config'=>json_encode($config,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }
}

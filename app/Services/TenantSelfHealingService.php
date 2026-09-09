<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use PDO;
use Throwable;

/**
 * Correções operacionais seguras e repetíveis.
 *
 * A tela de saúde não deve apenas apontar problemas que o operador precisa
 * "resolver no código". Problemas conhecidos e seguros de corrigir são
 * tratados aqui e podem rodar pelo diagnóstico, pelo botão "Corrigir agora"
 * ou pelo cron de saúde.
 */
final class TenantSelfHealingService
{
    /** @var array<string,string> */
    private const REPAIRABLE_COMPONENTS = [
        'calendar.integration' => 'Agenda',
    ];

    public function supports(string $componentKey): bool
    {
        return isset(self::REPAIRABLE_COMPONENTS[$componentKey]);
    }

    /** @param array<string,mixed> $details */
    public function isRecommended(string $componentKey, array $details, string $status): bool
    {
        if (!$this->supports($componentKey) || !in_array($status, ['warning', 'critical'], true)) {
            return false;
        }
        if ($componentKey === 'calendar.integration') {
            return (int) ($details['Pré-reservas vencidas'] ?? 0) > 0
                || (int) ($details['Confirmados sem evento'] ?? 0) > 0
                || (int) ($details['Sincronizações com falha'] ?? 0) > 0;
        }
        return false;
    }

    /** @return array<string,string> */
    public function catalog(): array
    {
        return self::REPAIRABLE_COMPONENTS;
    }

    /**
     * Executa apenas reparos que não mudam decisões de negócio da empresa.
     * Não inventa URL, credencial, horário ou regra ausente.
     *
     * @return array{ok:bool,changed:bool,actions:array<int,string>,errors:array<int,string>}
     */
    public function runSafeRepairs(int $tenantId, string $origin = 'automatic'): array
    {
        $result = ['ok' => true, 'changed' => false, 'actions' => [], 'errors' => []];
        if ($tenantId < 1) {
            return ['ok' => false, 'changed' => false, 'actions' => [], 'errors' => ['Empresa inválida.']];
        }

        $calendar = $this->repairCalendar($tenantId, $origin);
        $result['changed'] = !empty($calendar['changed']);
        $result['actions'] = array_values((array) ($calendar['actions'] ?? []));
        $result['errors'] = array_values((array) ($calendar['errors'] ?? []));
        $result['ok'] = $result['errors'] === [];

        return $result;
    }

    /**
     * Repara um componente solicitado pelo painel.
     *
     * @return array{ok:bool,changed:bool,message:string,actions:array<int,string>,errors:array<int,string>}
     */
    public function repairComponent(int $tenantId, string $componentKey, string $origin = 'manual'): array
    {
        if (!$this->supports($componentKey)) {
            return [
                'ok' => false,
                'changed' => false,
                'message' => 'Este item exige ajuste de configuração e não possui correção automática segura.',
                'actions' => [],
                'errors' => [],
            ];
        }

        $result = match ($componentKey) {
            'calendar.integration' => $this->repairCalendar($tenantId, $origin),
            default => ['ok' => false, 'changed' => false, 'actions' => [], 'errors' => ['Correção não implementada.']],
        };

        $errors = array_values((array) ($result['errors'] ?? []));
        $changed = !empty($result['changed']);
        return [
            'ok' => $errors === [],
            'changed' => $changed,
            'message' => $errors !== []
                ? 'A correção automática encontrou pendências que precisam de configuração manual.'
                : ($changed ? 'A correção automática foi aplicada e o diagnóstico foi atualizado.' : 'Nenhuma correção automática era necessária.'),
            'actions' => array_values((array) ($result['actions'] ?? [])),
            'errors' => $errors,
        ];
    }

    /** @return array{changed:bool,actions:array<int,string>,errors:array<int,string>} */
    private function repairCalendar(int $tenantId, string $origin): array
    {
        $changed = false;
        $actions = [];
        $errors = [];

        try {
            $availability = new CalendarAvailabilityService();
            $source = (string) (($availability->calendarSourceSettings($tenantId)['source'] ?? 'none'));
            $settings = $availability->settings($tenantId);
            if ($source === 'none' || empty($settings['enabled'])) {
                return ['changed' => false, 'actions' => [], 'errors' => []];
            }

            // Corrige somente divergência técnica entre a origem já escolhida e os
            // flags legados. Não altera dias, horários, modo de confirmação ou URLs.
            if ($this->tableExists('tenant_calendar_availability_settings')) {
                $pdo = Database::connection();
                if ($source === 'internal' && (!empty($settings['use_n8n']) || empty($settings['use_internal_fallback']))) {
                    $stmt = $pdo->prepare(
                        'UPDATE tenant_calendar_availability_settings
                         SET enabled = 1, use_n8n = 0, use_internal_fallback = 1, updated_at = NOW()
                         WHERE tenant_id = :tenant_id'
                    );
                    $stmt->execute(['tenant_id' => $tenantId]);
                    if ($stmt->rowCount() > 0) {
                        $changed = true;
                        $actions[] = 'Origem da Agenda interna reconciliada com a configuração ativa.';
                    }
                } elseif ($source === 'google' && empty($settings['use_n8n'])) {
                    $stmt = $pdo->prepare(
                        'UPDATE tenant_calendar_availability_settings
                         SET enabled = 1, use_n8n = 1, updated_at = NOW()
                         WHERE tenant_id = :tenant_id'
                    );
                    $stmt->execute(['tenant_id' => $tenantId]);
                    if ($stmt->rowCount() > 0) {
                        $changed = true;
                        $actions[] = 'Origem Google reconciliada com a integração ativa.';
                    }
                }
            }

            // A manutenção é idempotente. Agora ela também libera holds vencidos
            // órfãos da tabela de slots, que antes ficavam presos para sempre.
            $maintenance = (new CalendarGoogleLifecycleService())->runMaintenance($tenantId, 'health_' . $origin);
            $payload = (array) ($maintenance['result'] ?? []);
            $released = (int) ($payload['expired_holds_released'] ?? 0);
            $closed = (int) ($payload['stale_requests_closed'] ?? 0);
            $retried = (int) ($payload['syncs_retried'] ?? 0);
            $created = (int) ($payload['google_events_created'] ?? 0);
            $updated = (int) ($payload['google_events_updated'] ?? 0);
            $deleted = (int) ($payload['google_events_deleted'] ?? 0);

            if ($released > 0) {
                $changed = true;
                $actions[] = $released . ' pré-reserva(s) vencida(s) liberada(s).';
            }
            if ($closed > 0) {
                $changed = true;
                $actions[] = $closed . ' consulta(s) antigas encerrada(s).';
            }
            if ($retried > 0 || $created > 0 || $updated > 0 || $deleted > 0) {
                $changed = true;
                $actions[] = 'Sincronizações pendentes da agenda foram reprocessadas.';
            }

            foreach ((array) ($payload['errors'] ?? []) as $error) {
                $errors[] = (string) $error;
            }
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }

        if ($changed || $errors !== []) {
            try {
                Audit::log('tenant.health.self_healing.calendar', [
                    'origin' => $origin,
                    'actions' => $actions,
                    'errors' => $errors,
                ], $tenantId);
            } catch (Throwable) {
            }
        }

        return ['changed' => $changed, 'actions' => $actions, 'errors' => $errors];
    }

    private function tableExists(string $table): bool
    {
        try {
            $stmt = Database::connection()->prepare(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table'
            );
            $stmt->execute(['table' => $table]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}

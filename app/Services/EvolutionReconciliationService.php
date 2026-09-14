<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use PDO;
use Throwable;

/**
 * Reconciles the local Evolution instance state with the state observed directly
 * in Evolution. Webhooks remain the fast path; reconciliation is the recovery
 * path when a webhook is delayed, lost or arrives out of order.
 */
final class EvolutionReconciliationService
{
    /** @return array<string,mixed> */
    public function reconcileById(int $instanceId, string $source = 'manual'): array
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT * FROM evolution_instances WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $instanceId]);
        $instance = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$instance) {
            throw new \RuntimeException('Conexão WhatsApp não encontrada.');
        }

        return $this->reconcile($instance, $source);
    }

    /** @param array<string,mixed> $instance @return array<string,mixed> */
    public function reconcile(array $instance, string $source = 'system'): array
    {
        $pdo = Database::connection();
        $instanceId = (int) ($instance['id'] ?? 0);
        $tenantId = (int) ($instance['tenant_id'] ?? 0);
        if ($instanceId < 1 || $tenantId < 1) {
            throw new \RuntimeException('Conexão inválida para reconciliação.');
        }

        $source = mb_substr(trim($source) !== '' ? trim($source) : 'system', 0, 40);
        $localStateBefore = $this->normalizeState((string) (($instance['connection_state'] ?? '') ?: ($instance['status'] ?? 'unknown')));
        $startedAt = Clock::nowUtc();
        $runId = $this->startRun($pdo, $tenantId, $instanceId, $source, $localStateBefore, $startedAt);

        try {
            $service = $this->serviceFor($instance);
            $live = $service->connectionState();
            $remoteState = $this->normalizeState((string) ($live['state'] ?? 'unknown'));
            if ($remoteState === 'unknown') {
                throw new \RuntimeException('A Evolution respondeu sem informar o estado real da conexão.');
            }
            $remoteStatus = $this->statusForState($remoteState);
            $remoteBody = is_array($live['body'] ?? null) ? $live['body'] : [];

            $observedPhone = EvolutionInstanceSafetyService::extractConnectedPhone($remoteBody);
            if ($observedPhone === '' && $remoteStatus === 'connected') {
                try {
                    $details = $service->instanceDetails();
                    $detailsBody = is_array($details['body'] ?? null) ? $details['body'] : [];
                    $observedPhone = EvolutionInstanceSafetyService::extractConnectedPhone($detailsBody);
                } catch (Throwable) {
                    // State reconciliation does not fail only because metadata is unavailable.
                }
            }

            $identity = EvolutionInstanceSafetyService::assess($instance);
            if ($observedPhone !== '' && EvolutionInstanceSafetyService::schemaSupported($pdo)) {
                $identity = EvolutionInstanceSafetyService::persistObservedIdentity($pdo, $instance, $observedPhone, true);
            }

            $localBucket = $this->statusForState($localStateBefore);
            $wasDivergent = $localBucket !== $remoteStatus;
            $identityMismatch = (string) ($identity['status'] ?? '') === 'mismatch';

            if ($identityMismatch) {
                $result = 'identity_mismatch';
                $action = 'blocked_identity_mismatch';
                $reason = 'Número conectado diferente do número autorizado.';
                $pdo->prepare(
                    'UPDATE evolution_instances
                     SET status = "disconnected",
                         connection_state = "identity_mismatch",
                         remote_connection_state = :remote_state,
                         reconciliation_status = "identity_mismatch",
                         reconciliation_reason = :reason,
                         last_reconciled_at = NOW(),
                         reconciliation_failures = 0,
                         last_status_check_at = NOW(),
                         connection_updated_at = NOW()
                     WHERE id = :id'
                )->execute([
                    'remote_state' => $remoteState,
                    'reason' => $reason,
                    'id' => $instanceId,
                ]);
            } else {
                $result = $wasDivergent ? 'corrected' : 'healthy';
                $action = $wasDivergent ? 'local_state_updated' : 'none';
                $reason = $wasDivergent
                    ? 'Estado local reconciliado com o estado observado na Evolution.'
                    : 'Estado local e Evolution estão consistentes.';
                $pdo->prepare(
                    'UPDATE evolution_instances
                     SET status = :status,
                         connection_state = :connection_state,
                         remote_connection_state = :remote_state,
                         reconciliation_status = :reconciliation_status,
                         reconciliation_reason = :reason,
                         last_reconciled_at = NOW(),
                         reconciliation_failures = 0,
                         last_status_check_at = NOW(),
                         connection_updated_at = CASE WHEN :changed = 1 THEN NOW() ELSE connection_updated_at END,
                         connection_reason = CASE WHEN :clear_reason = 1 THEN NULL ELSE connection_reason END
                     WHERE id = :id'
                )->execute([
                    'status' => $remoteStatus,
                    'connection_state' => $remoteState,
                    'remote_state' => $remoteState,
                    'reconciliation_status' => $result,
                    'reason' => $reason,
                    'changed' => $wasDivergent ? 1 : 0,
                    'clear_reason' => $remoteStatus === 'connected' ? 1 : 0,
                    'id' => $instanceId,
                ]);
            }

            $metadata = [
                'local_status_before' => (string) ($instance['status'] ?? ''),
                'local_bucket_before' => $localBucket,
                'remote_status' => $remoteStatus,
                'identity_status' => (string) ($identity['status'] ?? 'unknown'),
                'observed_phone' => $observedPhone,
                'http_status' => (int) ($live['status'] ?? 0),
            ];
            $this->finishRun($pdo, $runId, $remoteState, $result, $action, null, $metadata);

            return [
                'ok' => true,
                'instance_id' => $instanceId,
                'local_state_before' => $localStateBefore,
                'remote_state' => $remoteState,
                'remote_status' => $remoteStatus,
                'result' => $result,
                'action' => $action,
                'reason' => $reason,
                'identity' => $identity,
            ];
        } catch (Throwable $exception) {
            $reason = mb_substr('Falha ao consultar a Evolution: ' . $exception->getMessage(), 0, 255);
            try {
                $pdo->prepare(
                    'UPDATE evolution_instances
                     SET reconciliation_status = "unreachable",
                         reconciliation_reason = :reason,
                         last_reconciled_at = NOW(),
                         reconciliation_failures = reconciliation_failures + 1,
                         last_status_check_at = NOW()
                     WHERE id = :id'
                )->execute(['reason' => $reason, 'id' => $instanceId]);
                $this->finishRun($pdo, $runId, null, 'unreachable', 'none', $exception->getMessage(), []);
            } catch (Throwable) {
                // Preserve the original Evolution exception.
            }
            throw $exception;
        }
    }

    /** @param array<string,mixed> $instance */
    private function serviceFor(array $instance): EvolutionService
    {
        return new EvolutionService(
            (string) ($instance['base_url'] ?? ''),
            Crypto::decrypt((string) ($instance['api_key_encrypted'] ?? '')),
            (string) ($instance['instance_name'] ?? ''),
            max(5, (int) Env::get('EVOLUTION_RECONCILIATION_TIMEOUT', 15)),
            filter_var(Env::get('EVOLUTION_SSL_VERIFY', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
            trim((string) Env::get('EVOLUTION_CA_BUNDLE', '')) ?: null
        );
    }

    private function normalizeState(string $state): string
    {
        $state = mb_strtolower(trim($state));
        $state = str_replace([' ', '-'], '_', $state);
        return $state !== '' ? $state : 'unknown';
    }

    private function statusForState(string $state): string
    {
        if (in_array($state, ['open', 'connected', 'online', 'active'], true)) {
            return 'connected';
        }
        if (in_array($state, ['connecting', 'qrcode', 'qr', 'pending', 'created', 'restarting', 'recovering'], true)) {
            return 'pending';
        }
        return 'disconnected';
    }

    private function startRun(PDO $pdo, int $tenantId, int $instanceId, string $source, string $localState, string $startedAt): int
    {
        try {
            $statement = $pdo->prepare(
                'INSERT INTO evolution_reconciliation_runs
                    (tenant_id, evolution_instance_id, source, local_state_before, result_status, started_at)
                 VALUES (:tenant_id, :instance_id, :source, :local_state, "running", :started_at)'
            );
            $statement->execute([
                'tenant_id' => $tenantId,
                'instance_id' => $instanceId,
                'source' => $source,
                'local_state' => $localState,
                'started_at' => $startedAt,
            ]);
            return (int) $pdo->lastInsertId();
        } catch (Throwable) {
            return 0;
        }
    }

    /** @param array<string,mixed> $metadata */
    private function finishRun(PDO $pdo, int $runId, ?string $remoteState, string $result, string $action, ?string $error, array $metadata): void
    {
        if ($runId < 1) {
            return;
        }
        try {
            $metadataJson = $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $pdo->prepare(
                'UPDATE evolution_reconciliation_runs
                 SET remote_state = :remote_state,
                     result_status = :result_status,
                     action_taken = :action_taken,
                     error_message = :error_message,
                     metadata_json = :metadata_json,
                     finished_at = NOW()
                 WHERE id = :id'
            )->execute([
                'remote_state' => $remoteState,
                'result_status' => mb_substr($result, 0, 40),
                'action_taken' => mb_substr($action, 0, 80),
                'error_message' => $error !== null ? mb_substr($error, 0, 500) : null,
                'metadata_json' => is_string($metadataJson) ? $metadataJson : null,
                'id' => $runId,
            ]);
        } catch (Throwable) {
            // Observability must never break the main reconciliation path.
        }
    }
}

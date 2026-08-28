<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Env;
use Throwable;

final class HealthCheckService
{
    /**
     * @return array{status:string,checks:list<array{name:string,status:string}>,checked_at:string}
     */
    public function readinessDetails(): array
    {
        $checks = [
            $this->databaseCheck(),
            $this->storageCheck(),
            $this->applicationKeyCheck(),
        ];

        $ready = count(array_filter(
            $checks,
            static fn (array $check): bool => ($check['status'] ?? '') !== 'ok'
        )) === 0;

        return [
            'status' => $ready ? 'ok' : 'unavailable',
            'checks' => $checks,
            'checked_at' => Clock::nowUtcIso(),
        ];
    }

    public function isReady(): bool
    {
        return $this->readinessDetails()['status'] === 'ok';
    }

    /** @return array{name:string,status:string} */
    private function databaseCheck(): array
    {
        try {
            $statement = Database::connection()->query('SELECT 1');
            $ok = $statement !== false && (int) $statement->fetchColumn() === 1;
        } catch (Throwable) {
            $ok = false;
        }

        return [
            'name' => 'database',
            'status' => $ok ? 'ok' : 'unavailable',
        ];
    }

    /** @return array{name:string,status:string} */
    private function storageCheck(): array
    {
        $root = dirname(__DIR__, 2) . '/storage';
        $directories = [
            $root,
            $root . '/app',
            $root . '/cache',
            $root . '/logs',
        ];

        $ok = true;
        foreach ($directories as $directory) {
            if (!is_dir($directory) || !is_writable($directory)) {
                $ok = false;
                break;
            }
        }

        return [
            'name' => 'storage',
            'status' => $ok ? 'ok' : 'unavailable',
        ];
    }

    /** @return array{name:string,status:string} */
    private function applicationKeyCheck(): array
    {
        $appKey = trim((string) Env::get('APP_KEY', ''));
        $placeholder = $appKey === ''
            || str_contains(strtoupper($appKey), 'REPLACE_WITH')
            || str_contains(strtoupper($appKey), 'SUA_APP_KEY');

        return [
            'name' => 'application_key',
            'status' => $placeholder ? 'unavailable' : 'ok',
        ];
    }
}

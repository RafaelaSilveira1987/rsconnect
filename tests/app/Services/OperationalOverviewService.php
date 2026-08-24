<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Compatibilidade com referências anteriores do Painel operacional.
 * A fonte única de verdade da nova visão é OperationalHealthService.
 */
final class OperationalOverviewService
{
    public function dashboard(): array
    {
        return (new OperationalHealthService())->dashboard();
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ExpressAgentFormRequest;
use App\Services\ExpressOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/** Adaptador Laravel; sem uso no roteador PHP nativo atual. */
final class ExpressAgentLaravelController extends Controller
{
    public function __invoke(ExpressAgentFormRequest $request, ExpressOnboardingService $service): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id; // Nunca obter do body.
        return response()->json([
            'ok' => true,
            'data' => $service->apply($tenantId, $request->toDto()),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Services\ExpressOnboardingService;
use App\Validation\ExpressOnboardingRequest;
use App\Validation\ExpressValidationException;
use JsonException;
use RuntimeException;
use Throwable;

final class ExpressOnboardingController
{
    /** POST /api/agents/express; autenticado + X-CSRF-TOKEN; tenant vem da sessão. */
    public function apply(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $tenantId = (int) (Auth::tenantId() ?? 0);
        if ($tenantId <= 0) {
            $this->reply(403, ['ok'=>false,'message'=>'Sessão sem empresa vinculada.']);
            return;
        }
        $body = file_get_contents('php://input', false, null, 0, 131073);
        if (!is_string($body) || strlen($body) > 131072) {
            $this->reply(413, ['ok'=>false,'message'=>'Payload acima do limite de 128 KiB.']);
            return;
        }
        try {
            $raw = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($raw) || array_is_list($raw)) {
                throw new ExpressValidationException(['body'=>'Informe um objeto JSON.']);
            }
            $dto = ExpressOnboardingRequest::validate($raw);
            $result = (new ExpressOnboardingService())->apply($tenantId, $dto);
            Audit::log('agent.express_configuration_applied', [
                'blueprint_id'=>$result['blueprint_id'],
                'version_id'=>$result['version_id'],
                'agent_count'=>$result['connected_agents_updated'],
            ], $tenantId);
            $this->reply(200, ['ok'=>true,'data'=>$result]);
        } catch (ExpressValidationException $e) {
            $this->reply(422, ['ok'=>false,'message'=>$e->getMessage(),'errors'=>$e->errors]);
        } catch (JsonException $e) {
            $this->reply(400, ['ok'=>false,'message'=>'JSON inválido.']);
        } catch (RuntimeException $e) {
            // Configurações incompletas não devem ser salvas parcialmente.
            $this->reply(409, ['ok'=>false,'message'=>$e->getMessage()]);
        } catch (Throwable $e) {
            error_log('[RS Connect][Express] ' . $e->getMessage());
            $this->reply(500, ['ok'=>false,'message'=>'Não foi possível concluir a configuração.']);
        }
    }

    private function reply(int $http, array $data): void
    {
        http_response_code($http);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}

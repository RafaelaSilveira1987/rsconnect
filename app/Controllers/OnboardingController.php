<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\AiAutomationService;
use App\Services\AgentBlueprintService;
use App\Services\OnboardingGuideService;
use App\Services\PromptStudioService;
use PDO;
use Throwable;

final class OnboardingController
{
    public function index(): void
    {
        if (Auth::isSuperAdmin()) {
            Flash::set('warning', 'O onboarding guiado é realizado dentro da conta de cada cliente. Use Implantação para acompanhar pelo painel RS.');
            $this->redirect('/implementation');
        }

        $tenantId = (int) Auth::tenantId();
        $pdo = Database::connection();

        $companyStatement = $pdo->prepare('SELECT * FROM tenants WHERE id = :id LIMIT 1');
        $companyStatement->execute(['id' => $tenantId]);
        $company = $companyStatement->fetch(PDO::FETCH_ASSOC) ?: [];

        $instanceStatement = $pdo->prepare(
            'SELECT id, name, instance_name, base_url, status, is_default
             FROM evolution_instances
             WHERE tenant_id = :tenant_id
             ORDER BY is_default DESC, created_at DESC'
        );
        $instanceStatement->execute(['tenant_id' => $tenantId]);
        $instances = $instanceStatement->fetchAll(PDO::FETCH_ASSOC);

        $agentStatement = $pdo->prepare(
            'SELECT a.*, i.name AS instance_name
             FROM ai_agents a
             LEFT JOIN evolution_instances i ON i.id = a.instance_id
             WHERE a.tenant_id = :tenant_id
             ORDER BY a.is_default DESC, a.created_at DESC'
        );
        $agentStatement->execute(['tenant_id' => $tenantId]);
        $agents = $agentStatement->fetchAll(PDO::FETCH_ASSOC);

        $guide = (new OnboardingGuideService())->dashboard($tenantId, Auth::id());

        View::render('onboarding.index', [
            'title' => 'Ativação Assistida',
            'company' => $company,
            'instances' => $instances,
            'agents' => $agents,
            'defaultUrl' => (string) Env::get('EVOLUTION_DEFAULT_URL', ''),
            'guide' => $guide,
        ]);
    }

    public function saveCompany(): void
    {
        $tenantId = (int) Auth::tenantId();
        $pdo = Database::connection();
        $currentStatement = $pdo->prepare(
            'SELECT name, legal_name, document, email, phone, website, segment, business_niche_id, agent_blueprint_id
             FROM tenants WHERE id = :id LIMIT 1'
        );
        $currentStatement->execute(['id' => $tenantId]);
        $current = $currentStatement->fetch(PDO::FETCH_ASSOC);

        if (!$current) {
            Flash::set('error', 'Empresa não encontrada.');
            $this->redirect('/onboarding');
        }

        $name = trim((string) ($_POST['name'] ?? ($current['name'] ?? '')));
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? ($current['email'] ?? ''))));
        $phone = trim((string) ($_POST['phone'] ?? ($current['phone'] ?? '')));
        $website = trim((string) ($_POST['website'] ?? ($current['website'] ?? '')));
        $businessNicheId = max(0, (int) ($_POST['business_niche_id'] ?? 0));
        $blueprintId = max(0, (int) ($_POST['agent_blueprint_id'] ?? 0));

        if ($name === '' || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            Flash::set('error', 'Informe o nome da empresa e um e-mail válido.');
            $this->redirect('/onboarding');
        }
        if ($email === '' && $phone === '') {
            Flash::set('error', 'Informe pelo menos um contato da empresa: e-mail comercial ou telefone.');
            $this->redirect('/onboarding');
        }
        if ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL)) {
            Flash::set('error', 'Informe o site completo, incluindo https://.');
            $this->redirect('/onboarding');
        }
        if ($businessNicheId < 1) {
            Flash::set('warning', 'Escolha o segmento da empresa. O segmento é obrigatório para preparar as regras iniciais do assistente.');
            $this->redirect('/onboarding');
        }

        $blueprintService = new AgentBlueprintService();
        $niche = null;
        foreach ($blueprintService->niches() as $candidate) {
            if ((int) ($candidate['id'] ?? 0) === $businessNicheId) {
                $niche = $candidate;
                break;
            }
        }
        if (!$niche) {
            Flash::set('error', 'O segmento selecionado não está disponível. Escolha outro segmento.');
            $this->redirect('/onboarding');
        }

        $availableBlueprints = $blueprintService->blueprints($businessNicheId);
        if ($blueprintId > 0) {
            $selectedBlueprint = null;
            foreach ($availableBlueprints as $candidate) {
                if ((int) ($candidate['id'] ?? 0) === $blueprintId) {
                    $selectedBlueprint = $candidate;
                    break;
                }
            }
            if (!$selectedBlueprint) {
                Flash::set('error', 'O modelo de atendimento escolhido não pertence ao segmento selecionado.');
                $this->redirect('/onboarding');
            }
        } else {
            $blueprintId = (int) ($availableBlueprints[0]['id'] ?? 0);
        }

        if ($blueprintId < 1) {
            Flash::set('error', 'Este segmento ainda não possui um modelo de atendimento ativo. Selecione outro segmento ou fale com a equipe RS.');
            $this->redirect('/onboarding');
        }

        try {
            $pdo->beginTransaction();

            $statement = $pdo->prepare(
                'UPDATE tenants
                 SET name = :name, email = :email, phone = :phone, website = :website,
                     segment = :segment, business_niche_id = :business_niche_id,
                     onboarding_step = GREATEST(onboarding_step, 2)
                 WHERE id = :id'
            );
            $statement->execute([
                'name' => $name,
                'email' => $email !== '' ? $email : null,
                'phone' => $phone !== '' ? $phone : null,
                'website' => $website !== '' ? $website : null,
                'segment' => (string) ($niche['name'] ?? ''),
                'business_niche_id' => $businessNicheId,
                'id' => $tenantId,
            ]);

            $profile = $blueprintService->profileForTenant($tenantId, false, $pdo);
            $currentBlueprintId = (int) (($current['agent_blueprint_id'] ?? 0) ?: ($profile['blueprint_id'] ?? 0));
            if ($currentBlueprintId !== $blueprintId || empty($profile['id'])) {
                $blueprintService->applyBlueprint($tenantId, $blueprintId, $pdo, false);
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Flash::set('error', 'Não foi possível preparar a configuração inicial da empresa: ' . $exception->getMessage());
            $this->redirect('/onboarding');
        }

        Auth::refreshUser();
        Audit::log('onboarding.company_completed', [
            'business_niche_id' => $businessNicheId,
            'agent_blueprint_id' => $blueprintId,
            'segment' => (string) ($niche['name'] ?? ''),
        ], $tenantId);
        (new OnboardingGuideService())->saveStep(
            $tenantId,
            'company_profile',
            'complete',
            'Empresa identificada, segmento definido e modelo inicial de atendimento preparado.',
            Auth::id()
        );
        Flash::set('success', 'Cadastro salvo e configuração inicial do segmento preparada. Continue para a próxima etapa.');
        $this->redirect('/onboarding');
    }

    public function saveInstance(): void
    {
        $tenantId = (int) Auth::tenantId();
        $mode = (string) ($_POST['mode'] ?? 'existing');
        $pdo = Database::connection();

        try {
            $pdo->beginTransaction();

            if ($mode === 'existing') {
                $instanceId = (int) ($_POST['instance_id'] ?? 0);
                $check = $pdo->prepare(
                    'SELECT id FROM evolution_instances WHERE id = :id AND tenant_id = :tenant_id LIMIT 1'
                );
                $check->execute(['id' => $instanceId, 'tenant_id' => $tenantId]);
                if (!$check->fetchColumn()) {
                    throw new \RuntimeException('Selecione uma instância válida da sua empresa.');
                }
            } else {
                $name = trim((string) ($_POST['name'] ?? ''));
                $instanceName = trim((string) ($_POST['instance_name'] ?? ''));
                $baseUrl = rtrim(trim((string) ($_POST['base_url'] ?? '')), '/');
                $apiKey = trim((string) ($_POST['api_key'] ?? ''));

                if ($name === '' || $instanceName === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL) || $apiKey === '') {
                    throw new \RuntimeException('Informe nome, instância, URL válida e API Key.');
                }

                $insert = $pdo->prepare(
                    'INSERT INTO evolution_instances
                        (tenant_id, name, instance_name, base_url, api_key_encrypted, status, is_default)
                     VALUES
                        (:tenant_id, :name, :instance_name, :base_url, :api_key, "pending", 0)'
                );
                $insert->execute([
                    'tenant_id' => $tenantId,
                    'name' => $name,
                    'instance_name' => $instanceName,
                    'base_url' => $baseUrl,
                    'api_key' => Crypto::encrypt($apiKey),
                ]);
                $instanceId = (int) $pdo->lastInsertId();
            }

            $reset = $pdo->prepare('UPDATE evolution_instances SET is_default = 0 WHERE tenant_id = :tenant_id');
            $reset->execute(['tenant_id' => $tenantId]);
            $default = $pdo->prepare(
                'UPDATE evolution_instances SET is_default = 1 WHERE id = :id AND tenant_id = :tenant_id'
            );
            $default->execute(['id' => $instanceId, 'tenant_id' => $tenantId]);

            $progress = $pdo->prepare(
                'UPDATE tenants SET onboarding_step = GREATEST(onboarding_step, 3) WHERE id = :id'
            );
            $progress->execute(['id' => $tenantId]);

            $pdo->commit();
            Audit::log('onboarding.instance_completed', ['instance_id' => $instanceId], $tenantId);
            (new OnboardingGuideService())->saveStep($tenantId, 'whatsapp_connection', 'complete', 'Instância WhatsApp definida durante o onboarding guiado.', Auth::id());
            Flash::set('success', 'WhatsApp salvo. Continue para agente IA.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Flash::set('error', $exception->getMessage());
        }

        $this->redirect('/onboarding');
    }

    public function saveAgent(): void
    {
        $tenantId = (int) Auth::tenantId();
        $pdo = Database::connection();
        $instanceId = (int) ($_POST['instance_id'] ?? 0);
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 140);
        $tonePreset = strtolower(trim((string) ($_POST['agent_tone_preset'] ?? 'professional')));
        if (!in_array($tonePreset, ['warm', 'professional', 'objective', 'friendly'], true)) {
            $tonePreset = 'professional';
        }

        $tenantStatement = $pdo->prepare(
            'SELECT name, segment, business_niche_id, agent_blueprint_id
             FROM tenants WHERE id = :id LIMIT 1'
        );
        $tenantStatement->execute(['id' => $tenantId]);
        $tenant = $tenantStatement->fetch(PDO::FETCH_ASSOC) ?: [];

        $currentStatement = $pdo->prepare(
            'SELECT * FROM ai_agents
             WHERE tenant_id = :tenant_id
             ORDER BY is_default DESC, created_at DESC, id DESC
             LIMIT 1'
        );
        $currentStatement->execute(['tenant_id' => $tenantId]);
        $currentAgent = $currentStatement->fetch(PDO::FETCH_ASSOC) ?: [];
        $agentId = (int) ($currentAgent['id'] ?? 0);

        $profile = (new AgentBlueprintService())->profileForTenant($tenantId, false, $pdo);
        $segment = trim((string) ($tenant['segment'] ?? ''));
        if ($segment === '') {
            $segment = trim((string) ($profile['niche_name'] ?? ''));
        }
        if ($name === '') {
            $companyName = trim((string) ($tenant['name'] ?? ''));
            $name = $companyName !== '' ? 'Assistente ' . $companyName : 'Assistente virtual';
        }

        $instance = $pdo->prepare(
            'SELECT id, status FROM evolution_instances
             WHERE id = :id AND tenant_id = :tenant_id LIMIT 1'
        );
        $instance->execute(['id' => $instanceId, 'tenant_id' => $tenantId]);
        $instanceRow = $instance->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$instanceRow || ($instanceRow['status'] ?? '') !== 'connected') {
            Flash::set('error', 'Conecte o WhatsApp antes de preparar o assistente. Somente conexões ativas podem ser usadas nesta etapa.');
            $this->redirect('/onboarding?step=ai_agent#ai_agent');
        }
        if ($segment === '') {
            Flash::set('error', 'O segmento da empresa precisa estar definido antes de preparar o assistente.');
            $this->redirect('/onboarding?step=company_profile#company_profile');
        }

        $provider = trim((string) ($currentAgent['model_provider'] ?? 'openai')) ?: 'openai';
        $model = trim((string) ($currentAgent['model_name'] ?? 'gpt-4o-mini')) ?: 'gpt-4o-mini';
        $temperature = isset($currentAgent['temperature']) ? (float) $currentAgent['temperature'] : 0.2;
        $prompt = trim((string) ($currentAgent['system_prompt'] ?? ''));
        if ($prompt === '') {
            $guidance = trim((string) ($profile['prompt_guidance'] ?? ''));
            $promptParts = [
                '# Papel',
                'Você é ' . $name . ', assistente virtual da empresa ' . (trim((string) ($tenant['name'] ?? '')) ?: 'atendida pelo RS Connect') . '.',
                '',
                '# Fonte de verdade',
                'Use as configurações estruturadas do RS Connect para triagem, ordem do atendimento, agenda, permissões, limites e políticas da empresa. Essas regras prevalecem sobre qualquer suposição do modelo.',
                'Não invente preços, disponibilidade, descontos, exceções ou informações que não estejam configuradas. Quando uma decisão exigir pessoa responsável, aplique o encaminhamento configurado.',
            ];
            if ($guidance !== '') {
                $promptParts[] = '';
                $promptParts[] = '# Orientação do segmento';
                $promptParts[] = $guidance;
            }
            $prompt = trim(implode("
", $promptParts));
        }

        $builder = json_decode((string) ($currentAgent['prompt_builder_json'] ?? ''), true);
        $builder = is_array($builder) ? $builder : [];
        $builder['conversation_tone'] = [
            'preset' => $tonePreset,
            'custom' => '',
        ];
        $promptBuilderJson = json_encode($builder, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;

        try {
            $pdo->beginTransaction();

            $agentData = [
                'tenant_id' => $tenantId,
                'instance_id' => $instanceId,
                'name' => $name,
                'segment' => $segment,
                'model_provider' => $provider,
                'model_name' => $model,
                'temperature' => max(0, min(1, $temperature)),
                'system_prompt' => $prompt,
                'prompt_builder_json' => $promptBuilderJson,
            ];

            if ($agentId > 0) {
                $update = $pdo->prepare(
                    'UPDATE ai_agents
                     SET instance_id = :instance_id, name = :name, segment = :segment,
                         model_provider = :model_provider, model_name = :model_name,
                         temperature = :temperature, system_prompt = :system_prompt,
                         prompt_builder_json = :prompt_builder_json,
                         status = "active", is_default = 1
                     WHERE id = :agent_id AND tenant_id = :tenant_id'
                );
                $update->execute($agentData + ['agent_id' => $agentId]);
            } else {
                $insert = $pdo->prepare(
                    'INSERT INTO ai_agents
                        (tenant_id, instance_id, name, segment, model_provider, model_name, temperature,
                         system_prompt, prompt_builder_json, status, is_default, auto_reply_enabled)
                     VALUES
                        (:tenant_id, :instance_id, :name, :segment, :model_provider, :model_name, :temperature,
                         :system_prompt, :prompt_builder_json, "active", 1, 0)'
                );
                $insert->execute($agentData);
                $agentId = (int) $pdo->lastInsertId();
            }

            $pdo->prepare(
                'UPDATE ai_agents SET is_default = IF(id = :agent_id, 1, 0) WHERE tenant_id = :tenant_id'
            )->execute(['agent_id' => $agentId, 'tenant_id' => $tenantId]);

            // Mantém também o vínculo N:N atual para que o onboarding não dependa da
            // tela avançada de Assistentes. Em instalações antigas, a coluna instance_id
            // continua funcionando e este bloco é simplesmente ignorado.
            try {
                $pdo->prepare(
                    'UPDATE ai_agent_instance_bindings
                     SET is_primary = 0
                     WHERE tenant_id = :tenant_id AND instance_id = :instance_id AND agent_id <> :agent_id'
                )->execute(['tenant_id' => $tenantId, 'instance_id' => $instanceId, 'agent_id' => $agentId]);
                $pdo->prepare(
                    'INSERT INTO ai_agent_instance_bindings
                        (tenant_id, agent_id, instance_id, is_primary, priority, routing_keywords, status)
                     VALUES
                        (:tenant_id, :agent_id, :instance_id, 1, 200, NULL, "active")
                     ON DUPLICATE KEY UPDATE
                        is_primary = 1, priority = 200, routing_keywords = NULL, status = "active"'
                )->execute(['tenant_id' => $tenantId, 'agent_id' => $agentId, 'instance_id' => $instanceId]);
            } catch (Throwable) {
                // Compatibilidade com bancos anteriores ao vínculo N:N.
            }

            $pdo->prepare(
                'UPDATE tenants SET onboarding_step = GREATEST(onboarding_step, 6) WHERE id = :id'
            )->execute(['id' => $tenantId]);

            $pdo->commit();

            if ($agentId > 0 && trim((string) ($currentAgent['system_prompt'] ?? '')) === '') {
                (new PromptStudioService())->createVersion(
                    $tenantId,
                    $agentId,
                    $prompt,
                    'onboarding_assisted',
                    Auth::id(),
                    null,
                    null,
                    'Instruções-base criadas pela Ativação Assistida'
                );
            }

            $guideService = new OnboardingGuideService();
            $guideService->applyStoredAttendanceToAgent($tenantId, $agentId);
            Audit::log('onboarding.agent_completed', [
                'agent_id' => $agentId,
                'tone' => $tonePreset,
                'instance_id' => $instanceId,
            ], $tenantId);
            $guideService->saveStep(
                $tenantId,
                'ai_agent',
                'complete',
                'Assistente preparado pela Ativação Assistida com identidade, tom e WhatsApp definidos.',
                Auth::id()
            );
            Flash::set('success', 'Assistente preparado. As regras, perguntas e limites continuam sendo controlados pela configuração da empresa.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Flash::set('error', 'Não foi possível preparar o assistente: ' . $exception->getMessage());
        }

        $this->redirect('/onboarding');
    }

    public function updateStep(): void
    {
        $tenantId = (int) Auth::tenantId();
        $stepKey = trim((string) ($_POST['step_key'] ?? ''));
        $status = trim((string) ($_POST['status'] ?? 'auto'));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        try {
            (new OnboardingGuideService())->saveStep($tenantId, $stepKey, $status, $notes, Auth::id());
            Flash::set('success', 'Etapa atualizada.');
        } catch (Throwable $exception) {
            Flash::set('error', $exception->getMessage());
        }

        $this->redirect('/onboarding#' . $stepKey);
    }

    public function saveAttendance(): void
    {
        $tenantId = (int) Auth::tenantId();
        try {
            (new OnboardingGuideService())->saveAttendance($tenantId, $_POST, Auth::id());
            Flash::set('success', 'Regras de atendimento salvas. Elas serão aplicadas automaticamente ao agente criado nas próximas etapas.');
        } catch (Throwable $exception) {
            Flash::set('error', $exception->getMessage());
        }
        $this->redirect('/onboarding#attendance-rules');
    }

    public function saveAgenda(): void
    {
        $tenantId = (int) Auth::tenantId();
        try {
            (new OnboardingGuideService())->saveAgenda($tenantId, $_POST, Auth::id());
            Flash::set('success', 'Agenda revisada.');
        } catch (Throwable $exception) {
            Flash::set('error', $exception->getMessage());
        }
        $this->redirect('/onboarding#agenda-setup');
    }

    public function saveAgentLimits(): void
    {
        $tenantId = (int) Auth::tenantId();
        try {
            (new OnboardingGuideService())->saveAgentLimits($tenantId, $_POST, Auth::id());
            Audit::log('onboarding.agent_limits_saved', [], $tenantId);
            Flash::set('success', 'Limites e condutas salvos. As decisões críticas serão validadas antes da IA.');
        } catch (Throwable $exception) {
            Flash::set('error', $exception->getMessage());
        }
        $this->redirect('/onboarding#agent-limits');
    }

    public function finish(): void
    {
        $tenantId = (int) Auth::tenantId();
        $notes = trim((string) ($_POST['notes'] ?? ''));
        try {
            (new OnboardingGuideService())->finish($tenantId, $notes, Auth::id());
            Audit::log('onboarding.completed', ['notes' => $notes], $tenantId);
            Flash::set('success', 'Onboarding finalizado. Sua operação está pronta para teste/uso.');
        } catch (Throwable $exception) {
            Flash::set('error', $exception->getMessage());
        }
        $this->redirect('/');
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}

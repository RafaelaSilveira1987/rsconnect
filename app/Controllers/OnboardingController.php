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
use App\Services\OnboardingGuideService;
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
            'title' => 'Primeiros passos',
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
        $name = trim((string) ($_POST['name'] ?? ''));
        $legalName = trim((string) ($_POST['legal_name'] ?? ''));
        $document = trim((string) ($_POST['document'] ?? ''));
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $website = trim((string) ($_POST['website'] ?? ''));
        $segment = trim((string) ($_POST['segment'] ?? ''));

        if ($name === '' || $segment === '' || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            Flash::set('error', 'Informe nome, segmento e um e-mail válido.');
            $this->redirect('/onboarding');
        }

        if ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL)) {
            Flash::set('error', 'Informe o site completo, incluindo https://.');
            $this->redirect('/onboarding');
        }

        $statement = Database::connection()->prepare(
            'UPDATE tenants
             SET name = :name, legal_name = :legal_name, document = :document, email = :email,
                 phone = :phone, website = :website, segment = :segment,
                 onboarding_step = GREATEST(onboarding_step, 2)
             WHERE id = :id'
        );
        $statement->execute([
            'name' => $name,
            'legal_name' => $legalName !== '' ? $legalName : null,
            'document' => $document !== '' ? $document : null,
            'email' => $email !== '' ? $email : null,
            'phone' => $phone !== '' ? $phone : null,
            'website' => $website !== '' ? $website : null,
            'segment' => $segment,
            'id' => $tenantId,
        ]);

        Auth::refreshUser();
        Audit::log('onboarding.company_completed', ['segment' => $segment], $tenantId);
        (new OnboardingGuideService())->saveStep($tenantId, 'company_profile', 'complete', 'Dados da empresa revisados no onboarding guiado.', Auth::id());
        Flash::set('success', 'Dados da empresa salvos. Continue para a próxima etapa.');
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
        $instanceId = (int) ($_POST['instance_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $segment = trim((string) ($_POST['segment'] ?? ''));
        $modelProvider = (string) ($_POST['model_provider'] ?? 'google');
        $modelName = trim((string) ($_POST['model_name'] ?? 'gemini-2.0-flash'));
        $temperature = (float) ($_POST['temperature'] ?? 0.2);
        $prompt = trim((string) ($_POST['system_prompt'] ?? ''));

        if ($name === '' || $segment === '' || $prompt === '' || !in_array($modelProvider, ['google', 'openai', 'anthropic', 'custom'], true)) {
            Flash::set('error', 'Informe nome, segmento, provedor e prompt do agente.');
            $this->redirect('/onboarding');
        }

        $temperature = max(0, min(1, $temperature));
        $pdo = Database::connection();
        $instance = $pdo->prepare(
            'SELECT id FROM evolution_instances WHERE id = :id AND tenant_id = :tenant_id LIMIT 1'
        );
        $instance->execute(['id' => $instanceId, 'tenant_id' => $tenantId]);
        if (!$instance->fetchColumn()) {
            Flash::set('error', 'Selecione uma instância válida da sua empresa.');
            $this->redirect('/onboarding');
        }

        try {
            $pdo->beginTransaction();

            $currentAgent = $pdo->prepare(
                'SELECT id FROM ai_agents WHERE tenant_id = :tenant_id AND is_default = 1 LIMIT 1'
            );
            $currentAgent->execute(['tenant_id' => $tenantId]);
            $agentId = (int) ($currentAgent->fetchColumn() ?: 0);

            $agentData = [
                'tenant_id' => $tenantId,
                'instance_id' => $instanceId,
                'name' => $name,
                'segment' => $segment,
                'model_provider' => $modelProvider,
                'model_name' => $modelName,
                'temperature' => $temperature,
                'system_prompt' => $prompt,
            ];

            if ($agentId > 0) {
                $update = $pdo->prepare(
                    'UPDATE ai_agents
                     SET instance_id = :instance_id, name = :name, segment = :segment,
                         model_provider = :model_provider, model_name = :model_name,
                         temperature = :temperature, system_prompt = :system_prompt,
                         status = "active", is_default = 1
                     WHERE id = :agent_id AND tenant_id = :tenant_id'
                );
                $update->execute($agentData + ['agent_id' => $agentId]);
            } else {
                $insert = $pdo->prepare(
                    'INSERT INTO ai_agents
                        (tenant_id, instance_id, name, segment, model_provider, model_name, temperature, system_prompt, status, is_default)
                     VALUES
                        (:tenant_id, :instance_id, :name, :segment, :model_provider, :model_name, :temperature, :system_prompt, "active", 1)'
                );
                $insert->execute($agentData);
                $agentId = (int) $pdo->lastInsertId();
            }

            $reset = $pdo->prepare(
                'UPDATE ai_agents SET is_default = IF(id = :agent_id, 1, 0) WHERE tenant_id = :tenant_id'
            );
            $reset->execute(['agent_id' => $agentId, 'tenant_id' => $tenantId]);

            $complete = $pdo->prepare(
                'UPDATE tenants SET onboarding_step = GREATEST(onboarding_step, 4) WHERE id = :id'
            );
            $complete->execute(['id' => $tenantId]);

            $pdo->commit();
            Audit::log('onboarding.agent_completed', ['agent_id' => $agentId], $tenantId);
            (new OnboardingGuideService())->saveStep($tenantId, 'ai_agent', 'complete', 'Agente IA criado/revisado no onboarding guiado.', Auth::id());
            Flash::set('success', 'Agente IA salvo. Continue configurando atendimento, agenda e LGPD.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Flash::set('error', 'Não foi possível salvar o agente. Verifique os dados informados.');
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
            Flash::set('success', 'Atendimento configurado.');
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
        $this->redirect('/onboarding#final_test');
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}

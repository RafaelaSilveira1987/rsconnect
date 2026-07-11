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
use PDO;
use Throwable;

final class OnboardingController
{
    public function index(): void
    {
        if (Auth::isSuperAdmin()) {
            Flash::set('warning', 'O onboarding é realizado dentro da conta de cada cliente. Os fluxos n8n continuam exclusivos do painel RS.');
            $this->redirect('/companies');
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

        $currentAgent = $agents[0] ?? [];
        $builder = $this->decodeBuilder((string) ($currentAgent['prompt_builder_json'] ?? ''));
        if (!$builder) {
            $builder = $this->defaultBuilder($company, $currentAgent);
        }

        View::render('onboarding.index', [
            'title' => 'Configuração inicial',
            'company' => $company,
            'instances' => $instances,
            'agents' => $agents,
            'builder' => $builder,
            'generatedPrompt' => (string) ($currentAgent['system_prompt'] ?? $this->buildPrompt($builder, $company)),
            'defaultUrl' => (string) Env::get('EVOLUTION_DEFAULT_URL', ''),
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
        Flash::set('success', 'Dados da empresa salvos. Agora conecte ou selecione uma instância do WhatsApp.');
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
            Flash::set('success', 'WhatsApp vinculado. Agora personalize o assistente de atendimento.');
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
        $assistantName = trim((string) ($_POST['assistant_name'] ?? $_POST['name'] ?? ''));
        $segment = trim((string) ($_POST['segment'] ?? ''));
        $builder = $this->sanitizeBuilder($_POST);
        $prompt = trim((string) ($_POST['system_prompt'] ?? ''));

        if ($assistantName === '' || $segment === '') {
            Flash::set('error', 'Informe o nome do assistente e o segmento.');
            $this->redirect('/onboarding');
        }

        $pdo = Database::connection();
        $companyStatement = $pdo->prepare('SELECT * FROM tenants WHERE id = :id LIMIT 1');
        $companyStatement->execute(['id' => $tenantId]);
        $company = $companyStatement->fetch(PDO::FETCH_ASSOC) ?: [];

        $instance = $pdo->prepare(
            'SELECT id FROM evolution_instances WHERE id = :id AND tenant_id = :tenant_id LIMIT 1'
        );
        $instance->execute(['id' => $instanceId, 'tenant_id' => $tenantId]);
        if (!$instance->fetchColumn()) {
            Flash::set('error', 'Selecione uma instância válida da sua empresa antes de concluir o assistente.');
            $this->redirect('/onboarding');
        }

        $builder['assistant_name'] = $assistantName;
        $builder['segment'] = $segment;
        if ($prompt === '') {
            $prompt = $this->buildPrompt($builder, $company);
        }

        if (mb_strlen($prompt) < 120) {
            Flash::set('error', 'Revise o prompt. Ele precisa ter instruções suficientes para orientar o atendimento.');
            $this->redirect('/onboarding');
        }

        try {
            $pdo->beginTransaction();

            $currentAgent = $pdo->prepare(
                'SELECT id, model_provider, model_name, temperature
                 FROM ai_agents
                 WHERE tenant_id = :tenant_id AND is_default = 1
                 LIMIT 1'
            );
            $currentAgent->execute(['tenant_id' => $tenantId]);
            $agent = $currentAgent->fetch(PDO::FETCH_ASSOC) ?: [];
            $agentId = (int) ($agent['id'] ?? 0);

            $modelProvider = (string) ($agent['model_provider'] ?? 'openai');
            if (!in_array($modelProvider, ['google', 'openai', 'anthropic', 'custom'], true)) {
                $modelProvider = 'openai';
            }
            $modelName = (string) ($agent['model_name'] ?? 'gpt-4o-mini');
            $temperature = (float) ($agent['temperature'] ?? 0.2);
            $handoffKeywords = $builder['handoff_keywords'] !== '' ? $builder['handoff_keywords'] : 'humano, atendente, falar com alguém, suporte';
            $handoffMessage = $builder['human_handoff_message'] !== ''
                ? $builder['human_handoff_message']
                : 'Vou encaminhar sua conversa para uma pessoa da equipe dar continuidade.';
            $afterHoursMessage = $builder['after_hours_message'] !== ''
                ? $builder['after_hours_message']
                : null;
            $builderJson = json_encode($builder, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($agentId > 0) {
                $update = $pdo->prepare(
                    'UPDATE ai_agents
                     SET instance_id = :instance_id,
                         name = :name,
                         segment = :segment,
                         model_provider = :model_provider,
                         model_name = :model_name,
                         temperature = :temperature,
                         system_prompt = :system_prompt,
                         prompt_builder_json = :prompt_builder_json,
                         auto_reply_enabled = 1,
                         handoff_keywords = :handoff_keywords,
                         human_handoff_message = :human_handoff_message,
                         after_hours_message = :after_hours_message,
                         status = "active",
                         is_default = 1
                     WHERE id = :agent_id AND tenant_id = :tenant_id'
                );
                $update->execute([
                    'instance_id' => $instanceId,
                    'name' => $assistantName,
                    'segment' => $segment,
                    'model_provider' => $modelProvider,
                    'model_name' => $modelName,
                    'temperature' => $temperature,
                    'system_prompt' => $prompt,
                    'prompt_builder_json' => $builderJson,
                    'handoff_keywords' => $handoffKeywords,
                    'human_handoff_message' => $handoffMessage,
                    'after_hours_message' => $afterHoursMessage,
                    'agent_id' => $agentId,
                    'tenant_id' => $tenantId,
                ]);
            } else {
                $insert = $pdo->prepare(
                    'INSERT INTO ai_agents
                        (tenant_id, instance_id, name, segment, model_provider, model_name, temperature, system_prompt, prompt_builder_json, status, is_default,
                         auto_reply_enabled, handoff_keywords, human_handoff_message, after_hours_message)
                     VALUES
                        (:tenant_id, :instance_id, :name, :segment, :model_provider, :model_name, :temperature, :system_prompt, :prompt_builder_json, "active", 1,
                         1, :handoff_keywords, :human_handoff_message, :after_hours_message)'
                );
                $insert->execute([
                    'tenant_id' => $tenantId,
                    'instance_id' => $instanceId,
                    'name' => $assistantName,
                    'segment' => $segment,
                    'model_provider' => $modelProvider,
                    'model_name' => $modelName,
                    'temperature' => $temperature,
                    'system_prompt' => $prompt,
                    'prompt_builder_json' => $builderJson,
                    'handoff_keywords' => $handoffKeywords,
                    'human_handoff_message' => $handoffMessage,
                    'after_hours_message' => $afterHoursMessage,
                ]);
                $agentId = (int) $pdo->lastInsertId();
            }

            $reset = $pdo->prepare(
                'UPDATE ai_agents SET is_default = IF(id = :agent_id, 1, 0) WHERE tenant_id = :tenant_id'
            );
            $reset->execute(['agent_id' => $agentId, 'tenant_id' => $tenantId]);

            $complete = $pdo->prepare(
                'UPDATE tenants
                 SET onboarding_step = 4,
                     onboarding_completed_at = COALESCE(onboarding_completed_at, NOW()),
                     onboarding_assistant_prompt_completed_at = NOW()
                 WHERE id = :id'
            );
            $complete->execute(['id' => $tenantId]);

            $pdo->commit();
            Audit::log('onboarding.agent_prompt_completed', ['agent_id' => $agentId, 'segment' => $segment], $tenantId);
            Flash::set('success', 'Assistente configurado. A RS Connect pode concluir integrações técnicas como n8n, credenciais e fluxos externos.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Flash::set('error', 'Não foi possível salvar o assistente. Verifique se a migration 018 foi executada.');
        }

        $this->redirect('/onboarding');
    }

    private function sanitizeBuilder(array $source): array
    {
        $fields = [
            'assistant_name',
            'segment',
            'tone',
            'main_goal',
            'audience',
            'business_summary',
            'products_services',
            'service_area',
            'prices_policy',
            'common_questions',
            'collect_fields',
            'handoff_keywords',
            'human_handoff_message',
            'after_hours_message',
            'restrictions',
            'extra_context',
        ];

        $builder = [];
        foreach ($fields as $field) {
            $value = trim((string) ($source[$field] ?? ''));
            $builder[$field] = mb_substr($value, 0, 5000);
        }

        $builder['tone'] = $builder['tone'] !== '' ? $builder['tone'] : 'Profissional, claro e acolhedor';
        $builder['main_goal'] = $builder['main_goal'] !== '' ? $builder['main_goal'] : 'Atendimento inicial, qualificação do contato e encaminhamento para a equipe quando necessário';
        $builder['collect_fields'] = $builder['collect_fields'] !== '' ? $builder['collect_fields'] : 'nome, telefone, necessidade principal e melhor horário para retorno';
        $builder['restrictions'] = $builder['restrictions'] !== '' ? $builder['restrictions'] : 'Não inventar preços, prazos, políticas, disponibilidade ou informações não cadastradas.';

        return $builder;
    }

    private function decodeBuilder(string $json): array
    {
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $this->sanitizeBuilder($decoded) : [];
    }

    private function defaultBuilder(array $company, array $agent): array
    {
        $name = (string) ($company['name'] ?? 'empresa');
        $segment = (string) ($company['segment'] ?? ($agent['segment'] ?? 'atendimento'));

        return [
            'assistant_name' => (string) ($agent['name'] ?? ('Assistente ' . $name)),
            'segment' => $segment,
            'tone' => 'Profissional, claro e acolhedor',
            'main_goal' => 'Atendimento inicial, qualificação do contato e encaminhamento para a equipe quando necessário',
            'audience' => 'Pessoas interessadas nos serviços da empresa',
            'business_summary' => 'A empresa atua no segmento de ' . $segment . '.',
            'products_services' => '',
            'service_area' => '',
            'prices_policy' => '',
            'common_questions' => '',
            'collect_fields' => 'nome, telefone, necessidade principal e melhor horário para retorno',
            'handoff_keywords' => 'humano, atendente, falar com alguém, suporte',
            'human_handoff_message' => 'Vou encaminhar sua conversa para uma pessoa da equipe dar continuidade.',
            'after_hours_message' => '',
            'restrictions' => 'Não inventar preços, prazos, políticas, disponibilidade ou informações não cadastradas.',
            'extra_context' => '',
        ];
    }

    private function buildPrompt(array $builder, array $company): string
    {
        $companyName = (string) ($company['name'] ?? 'empresa');
        $assistant = $builder['assistant_name'] ?: 'Assistente virtual';
        $segment = $builder['segment'] ?: (string) ($company['segment'] ?? 'atendimento');

        $sections = [
            'Você é ' . $assistant . ', assistente virtual de atendimento da empresa ' . $companyName . '.',
            'A empresa atua no segmento de ' . $segment . '.',
            'Objetivo principal: ' . $builder['main_goal'] . '.',
            'Tom de atendimento: ' . $builder['tone'] . '.',
        ];

        $optionalMap = [
            'Público atendido' => $builder['audience'] ?? '',
            'Resumo do negócio' => $builder['business_summary'] ?? '',
            'Produtos e serviços' => $builder['products_services'] ?? '',
            'Região ou modalidade de atendimento' => $builder['service_area'] ?? '',
            'Preços, condições e políticas comerciais' => $builder['prices_policy'] ?? '',
            'Perguntas frequentes e respostas autorizadas' => $builder['common_questions'] ?? '',
            'Informações que devem ser coletadas' => $builder['collect_fields'] ?? '',
            'Palavras ou situações para transferir ao humano' => $builder['handoff_keywords'] ?? '',
            'Mensagem de transferência para humano' => $builder['human_handoff_message'] ?? '',
            'Mensagem fora do horário' => $builder['after_hours_message'] ?? '',
            'Regras e restrições' => $builder['restrictions'] ?? '',
            'Contexto adicional' => $builder['extra_context'] ?? '',
        ];

        foreach ($optionalMap as $label => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $sections[] = $label . ': ' . $value;
            }
        }

        $sections[] = 'Regras de conversa: responda em português do Brasil, de forma objetiva, educada e natural. Faça uma pergunta por vez quando precisar coletar dados.';
        $sections[] = 'Não confirme agendamentos, pagamentos, disponibilidade, descontos ou condições que não estejam claramente informados.';
        $sections[] = 'Quando não tiver segurança, quando o cliente pedir atendimento humano ou quando o assunto exigir decisão da empresa, encaminhe para uma pessoa da equipe.';
        $sections[] = 'Não diga que é uma inteligência artificial. Apresente-se como assistente virtual de atendimento.';

        return implode("\n\n", $sections);
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}

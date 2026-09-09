<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use PDO;
use RuntimeException;
use Throwable;

final class AgentBlueprintController
{
    public function index(): void
    {
        $pdo = Database::connection();

        $niches = $pdo->query(
            'SELECT n.*,
                    (SELECT COUNT(*) FROM agent_blueprints b WHERE b.niche_id = n.id) AS blueprint_count,
                    (SELECT COUNT(*) FROM tenants t WHERE t.business_niche_id = n.id) AS tenant_count
             FROM business_niches n
             ORDER BY n.position, n.name'
        )->fetchAll(PDO::FETCH_ASSOC);

        $blueprints = $pdo->query(
            'SELECT b.*,
                    n.name AS niche_name,
                    n.code AS niche_code,
                    v.id AS current_version_id,
                    v.version_no AS current_version_no,
                    v.version_label AS current_version_label,
                    v.config_json AS current_config_json,
                    v.prompt_guidance AS current_prompt_guidance,
                    (SELECT COUNT(DISTINCT p.tenant_id) FROM tenant_agent_profiles p WHERE p.blueprint_id = b.id) AS tenant_count
             FROM agent_blueprints b
             INNER JOIN business_niches n ON n.id = b.niche_id
             LEFT JOIN agent_blueprint_versions v ON v.id = (
                 SELECT av.id FROM agent_blueprint_versions av
                 WHERE av.blueprint_id = b.id
                 ORDER BY av.is_current DESC, av.version_no DESC, av.id DESC LIMIT 1
             )
             ORDER BY n.position, n.name, b.name'
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($blueprints as &$blueprint) {
            $decoded = json_decode((string) ($blueprint['current_config_json'] ?? ''), true);
            $blueprint['current_config_decoded'] = is_array($decoded) ? $decoded : [];
            $blueprint['current_config_pretty'] = is_array($decoded)
                ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string) ($blueprint['current_config_json'] ?? '');
        }
        unset($blueprint);

        View::render('agent_blueprints.index', [
            'title' => 'Modelos de atendimento por segmento',
            'niches' => $niches,
            'blueprints' => $blueprints,
        ]);
    }

    public function saveNiche(): void
    {
        $id = max(0, (int) ($_POST['id'] ?? 0));
        $name = trim((string) ($_POST['name'] ?? ''));
        $code = $this->normalizeCode((string) ($_POST['code'] ?? $name));
        $description = trim((string) ($_POST['description'] ?? ''));
        $position = max(0, min(65535, (int) ($_POST['position'] ?? 100)));
        $active = isset($_POST['active']) ? 1 : 0;

        if ($name === '' || $code === '') {
            Flash::set('error', 'Informe o nome do segmento. Se usar a configuração avançada, informe também um código válido.');
            $this->redirect('/agent-blueprints');
        }

        $pdo = Database::connection();
        try {
            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE business_niches
                     SET code = :code, name = :name, description = :description, position = :position, active = :active
                     WHERE id = :id'
                );
                $stmt->execute([
                    'code' => $code,
                    'name' => $name,
                    'description' => $description !== '' ? $description : null,
                    'position' => $position,
                    'active' => $active,
                    'id' => $id,
                ]);
                Audit::log('agent_niche.updated', ['niche_id' => $id, 'code' => $code]);
                Flash::set('success', 'Segmento atualizado. As empresas já configuradas não são alteradas automaticamente.');
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO business_niches (code, name, description, active, position)
                     VALUES (:code, :name, :description, :active, :position)'
                );
                $stmt->execute([
                    'code' => $code,
                    'name' => $name,
                    'description' => $description !== '' ? $description : null,
                    'active' => $active,
                    'position' => $position,
                ]);
                $newId = (int) $pdo->lastInsertId();
                Audit::log('agent_niche.created', ['niche_id' => $newId, 'code' => $code]);
                Flash::set('success', 'Segmento criado. Agora você pode criar um modelo de atendimento para ele.');
            }
        } catch (Throwable $exception) {
            error_log('[RS Connect][agent-blueprints] Falha ao salvar nicho: ' . $exception->getMessage());
            Flash::set('error', 'Não foi possível salvar o segmento. Verifique a configuração e tente novamente.');
        }

        $this->redirect('/agent-blueprints');
    }

    public function saveBlueprint(): void
    {
        $id = max(0, (int) ($_POST['id'] ?? 0));
        $nicheId = max(0, (int) ($_POST['niche_id'] ?? 0));
        $name = trim((string) ($_POST['name'] ?? ''));
        $code = $this->normalizeCode((string) ($_POST['code'] ?? $name));
        $description = trim((string) ($_POST['description'] ?? ''));
        $active = isset($_POST['active']) ? 1 : 0;

        if ($nicheId < 1 || $name === '' || $code === '') {
            Flash::set('error', 'Informe o segmento e o nome do modelo de atendimento.');
            $this->redirect('/agent-blueprints');
        }

        $pdo = Database::connection();
        try {
            $check = $pdo->prepare('SELECT id FROM business_niches WHERE id = :id LIMIT 1');
            $check->execute(['id' => $nicheId]);
            if (!$check->fetchColumn()) {
                throw new RuntimeException('Segmento inválido.');
            }

            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE agent_blueprints
                     SET niche_id = :niche_id, code = :code, name = :name, description = :description, active = :active
                     WHERE id = :id'
                );
                $stmt->execute([
                    'niche_id' => $nicheId,
                    'code' => $code,
                    'name' => $name,
                    'description' => $description !== '' ? $description : null,
                    'active' => $active,
                    'id' => $id,
                ]);
                Audit::log('agent_blueprint.updated', ['blueprint_id' => $id, 'niche_id' => $nicheId, 'code' => $code]);
                Flash::set('success', 'Modelo atualizado. As empresas que já usam uma cópia continuam com a configuração atual.');
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO agent_blueprints (niche_id, code, name, description, active)
                     VALUES (:niche_id, :code, :name, :description, :active)'
                );
                $stmt->execute([
                    'niche_id' => $nicheId,
                    'code' => $code,
                    'name' => $name,
                    'description' => $description !== '' ? $description : null,
                    'active' => $active,
                ]);
                $newId = (int) $pdo->lastInsertId();
                Audit::log('agent_blueprint.created', ['blueprint_id' => $newId, 'niche_id' => $nicheId, 'code' => $code]);
                Flash::set('success', 'Modelo criado. Crie a primeira versão para poder aplicá-lo às empresas.');
            }
        } catch (Throwable $exception) {
            error_log('[RS Connect][agent-blueprints] Falha ao salvar blueprint: ' . $exception->getMessage());
            Flash::set('error', $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Não foi possível salvar o modelo. Verifique a configuração e tente novamente.');
        }

        $this->redirect('/agent-blueprints');
    }

    public function publishVersion(): void
    {
        $blueprintId = max(0, (int) ($_POST['blueprint_id'] ?? 0));
        $versionLabel = trim((string) ($_POST['version_label'] ?? ''));
        $configRaw = trim((string) ($_POST['config_json'] ?? ''));
        $promptGuidance = trim((string) ($_POST['prompt_guidance'] ?? ''));

        if ($blueprintId < 1 || $configRaw === '') {
            Flash::set('error', 'Selecione o modelo e informe a configuração avançada.');
            $this->redirect('/agent-blueprints');
        }

        try {
            $config = json_decode($configRaw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($config)) {
                throw new RuntimeException('A configuração avançada do modelo está em formato inválido.');
            }
            $this->validateBlueprintConfig($config);
        } catch (Throwable $exception) {
            Flash::set('error', 'Configuração inválida: ' . $exception->getMessage());
            $this->redirect('/agent-blueprints');
        }

        $pdo = Database::connection();
        try {
            $blueprintStmt = $pdo->prepare('SELECT id, name FROM agent_blueprints WHERE id = :id LIMIT 1');
            $blueprintStmt->execute(['id' => $blueprintId]);
            $blueprint = $blueprintStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$blueprint) {
                throw new RuntimeException('Modelo de atendimento não encontrado.');
            }

            $pdo->beginTransaction();
            $versionStmt = $pdo->prepare(
                'SELECT COALESCE(MAX(version_no), 0) + 1
                 FROM agent_blueprint_versions
                 WHERE blueprint_id = :blueprint_id
                 FOR UPDATE'
            );
            $versionStmt->execute(['blueprint_id' => $blueprintId]);
            $versionNo = max(1, (int) $versionStmt->fetchColumn());

            $pdo->prepare(
                'UPDATE agent_blueprint_versions SET is_current = 0 WHERE blueprint_id = :blueprint_id'
            )->execute(['blueprint_id' => $blueprintId]);

            $insert = $pdo->prepare(
                'INSERT INTO agent_blueprint_versions
                    (blueprint_id, version_no, version_label, config_json, prompt_guidance, is_current)
                 VALUES
                    (:blueprint_id, :version_no, :version_label, :config_json, :prompt_guidance, 1)'
            );
            $insert->execute([
                'blueprint_id' => $blueprintId,
                'version_no' => $versionNo,
                'version_label' => $versionLabel !== '' ? $versionLabel : ('v' . $versionNo),
                'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'prompt_guidance' => $promptGuidance !== '' ? $promptGuidance : null,
            ]);
            $versionId = (int) $pdo->lastInsertId();
            $pdo->commit();

            Audit::log('agent_blueprint.version_published', [
                'blueprint_id' => $blueprintId,
                'version_id' => $versionId,
                'version_no' => $versionNo,
            ]);
            Flash::set(
                'success',
                'Nova versão publicada. Ela será usada quando o modelo for aplicado novamente; empresas existentes não foram alteradas.'
            );
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[RS Connect][agent-blueprints] Falha ao publicar versão: ' . $exception->getMessage());
            Flash::set('error', $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Não foi possível publicar a nova versão do modelo.');
        }

        $this->redirect('/agent-blueprints');
    }

    /** @param array<string,mixed> $config */
    private function validateBlueprintConfig(array $config): void
    {
        $mode = (string) ($config['interaction_mode'] ?? 'hybrid');
        if (!in_array($mode, ['hybrid', 'form', 'prompt'], true)) {
            throw new RuntimeException('interaction_mode deve ser hybrid, form ou prompt.');
        }

        foreach (['capabilities', 'triage_fields', 'policies', 'workflow'] as $requiredKey) {
            if (!array_key_exists($requiredKey, $config) || !is_array($config[$requiredKey])) {
                throw new RuntimeException('O campo "' . $requiredKey . '" é obrigatório.');
            }
        }

        foreach ($config['triage_fields'] as $field) {
            if (!is_array($field) || trim((string) ($field['key'] ?? '')) === '' || trim((string) ($field['label'] ?? '')) === '') {
                throw new RuntimeException('Cada informação a coletar precisa ter uma identificação interna e um nome visível.');
            }
        }

        foreach ($config['policies'] as $policy) {
            if (!is_array($policy) || trim((string) ($policy['key'] ?? '')) === '') {
                throw new RuntimeException('Cada regra precisa ter uma identificação interna.');
            }
        }
    }

    private function normalizeCode(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($transliterated) && $transliterated !== '') {
            $value = $transliterated;
        }
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}

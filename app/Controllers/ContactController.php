<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use PDO;
use Throwable;

final class ContactController
{
    public function index(): void
    {
        $pdo = Database::connection();
        $filters = [
            'search' => trim((string) ($_GET['search'] ?? '')),
            'status' => trim((string) ($_GET['status'] ?? '')),
            'tenant_id' => Auth::isSuperAdmin() ? (int) ($_GET['tenant_id'] ?? 0) : (int) Auth::tenantId(),
        ];

        $conditions = [];
        $params = [];
        if (Auth::isSuperAdmin()) {
            if ($filters['tenant_id'] > 0) {
                $conditions[] = 'ct.tenant_id = :tenant_id';
                $params['tenant_id'] = $filters['tenant_id'];
            }
        } else {
            $conditions[] = 'ct.tenant_id = :tenant_id';
            $params['tenant_id'] = Auth::tenantId();
        }

        if ($filters['search'] !== '') {
            $conditions[] = '(ct.name LIKE :search OR ct.phone LIKE :search OR ct.email LIKE :search OR ct.company LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        if (in_array($filters['status'], ['lead', 'customer', 'inactive'], true)) {
            $conditions[] = 'ct.status = :status';
            $params['status'] = $filters['status'];
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $statement = $pdo->prepare(
            'SELECT ct.*, t.name AS tenant_name, i.name AS instance_name,
                    COUNT(DISTINCT c.id) AS conversations_count,
                    COUNT(DISTINCT l.id) AS leads_count,
                    MAX(c.last_message_at) AS last_interaction_at
             FROM contacts ct
             INNER JOIN tenants t ON t.id = ct.tenant_id
             LEFT JOIN evolution_instances i ON i.id = ct.evolution_instance_id
             LEFT JOIN conversations c ON c.contact_id = ct.id
             LEFT JOIN crm_leads l ON l.contact_id = ct.id
             ' . $where . '
             GROUP BY ct.id
             ORDER BY COALESCE(MAX(c.last_message_at), ct.updated_at) DESC
             LIMIT 250'
        );
        $statement->execute($params);
        $contacts = $statement->fetchAll(PDO::FETCH_ASSOC);

        $selected = null;
        $selectedId = (int) ($_GET['contact_id'] ?? 0);
        if ($selectedId > 0) {
            $selected = $this->findContact($selectedId);
        }

        if (Auth::isSuperAdmin()) {
            $tenants = $pdo->query('SELECT id, name FROM tenants WHERE status = "active" ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
            $instances = $pdo->query(
                'SELECT i.id, i.tenant_id, i.name, t.name AS tenant_name
                 FROM evolution_instances i
                 INNER JOIN tenants t ON t.id = i.tenant_id
                 ORDER BY t.name, i.name'
            )->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $tenants = [];
            $instanceStatement = $pdo->prepare(
                'SELECT id, tenant_id, name FROM evolution_instances WHERE tenant_id = :tenant_id ORDER BY name'
            );
            $instanceStatement->execute(['tenant_id' => Auth::tenantId()]);
            $instances = $instanceStatement->fetchAll(PDO::FETCH_ASSOC);
        }

        View::render('contacts.index', [
            'title' => 'Contatos',
            'contacts' => $contacts,
            'selected' => $selected,
            'filters' => $filters,
            'tenants' => $tenants,
            'instances' => $instances,
            'canManage' => Auth::can('contacts.manage'),
        ]);
    }

    public function store(): void
    {
        $tenantId = $this->postedTenantId();
        $name = trim((string) ($_POST['name'] ?? ''));
        $phone = preg_replace('/\D+/', '', (string) ($_POST['phone'] ?? '')) ?: '';
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $company = trim((string) ($_POST['company'] ?? ''));
        $instanceId = (int) ($_POST['evolution_instance_id'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'lead');
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $tags = $this->normalizeTags((string) ($_POST['tags'] ?? ''));

        if ($tenantId < 1 || strlen($phone) < 10) {
            Flash::set('error', 'Informe a empresa e um telefone completo com DDI e DDD.');
            $this->redirect('/contacts');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', 'O e-mail informado é inválido.');
            $this->redirect('/contacts');
        }
        if (!in_array($status, ['lead', 'customer', 'inactive'], true)) {
            $status = 'lead';
        }
        if ($instanceId > 0 && !$this->instanceBelongsToTenant($instanceId, $tenantId)) {
            Flash::set('error', 'A instância selecionada não pertence à empresa.');
            $this->redirect('/contacts');
        }

        try {
            $statement = Database::connection()->prepare(
                'INSERT INTO contacts
                    (tenant_id, evolution_instance_id, phone, name, email, company, notes, tags_json, status)
                 VALUES
                    (:tenant_id, :instance_id, :phone, :name, :email, :company, :notes, :tags_json, :status)'
            );
            $statement->execute([
                'tenant_id' => $tenantId,
                'instance_id' => $instanceId > 0 ? $instanceId : null,
                'phone' => $phone,
                'name' => $name !== '' ? $name : null,
                'email' => $email !== '' ? $email : null,
                'company' => $company !== '' ? $company : null,
                'notes' => $notes !== '' ? $notes : null,
                'tags_json' => $tags ? json_encode($tags, JSON_UNESCAPED_UNICODE) : null,
                'status' => $status,
            ]);
            $contactId = (int) Database::connection()->lastInsertId();
            Audit::log('contact.created', ['contact_id' => $contactId, 'phone' => $phone], $tenantId);
            Flash::set('success', 'Contato cadastrado.');
            $this->redirect('/contacts?contact_id=' . $contactId);
        } catch (Throwable $exception) {
            $duplicate = str_contains($exception->getMessage(), 'uq_contacts_tenant_phone')
                || str_contains($exception->getMessage(), 'Duplicate entry');
            Flash::set('error', $duplicate
                ? 'Esse telefone já está cadastrado para a empresa.'
                : 'Não foi possível cadastrar o contato.');
            $this->redirect('/contacts');
        }
    }

    public function update(): void
    {
        $contactId = (int) ($_POST['contact_id'] ?? 0);
        $contact = $this->findContact($contactId);
        if (!$contact) {
            Flash::set('error', 'Contato não encontrado.');
            $this->redirect('/contacts');
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $phone = preg_replace('/\D+/', '', (string) ($_POST['phone'] ?? '')) ?: '';
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $company = trim((string) ($_POST['company'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'lead');
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $tags = $this->normalizeTags((string) ($_POST['tags'] ?? ''));

        if (strlen($phone) < 10 || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            Flash::set('error', 'Confira telefone e e-mail.');
            $this->redirect('/contacts?contact_id=' . $contactId);
        }
        if (!in_array($status, ['lead', 'customer', 'inactive'], true)) {
            $status = 'lead';
        }

        try {
            $statement = Database::connection()->prepare(
                'UPDATE contacts
                 SET name = :name, phone = :phone, email = :email, company = :company,
                     notes = :notes, tags_json = :tags_json, status = :status
                 WHERE id = :id AND tenant_id = :tenant_id'
            );
            $statement->execute([
                'name' => $name !== '' ? $name : null,
                'phone' => $phone,
                'email' => $email !== '' ? $email : null,
                'company' => $company !== '' ? $company : null,
                'notes' => $notes !== '' ? $notes : null,
                'tags_json' => $tags ? json_encode($tags, JSON_UNESCAPED_UNICODE) : null,
                'status' => $status,
                'id' => $contactId,
                'tenant_id' => $contact['tenant_id'],
            ]);
            Audit::log('contact.updated', ['contact_id' => $contactId], (int) $contact['tenant_id']);
            Flash::set('success', 'Contato atualizado.');
        } catch (Throwable $exception) {
            Flash::set('error', str_contains($exception->getMessage(), 'Duplicate entry')
                ? 'Esse telefone já pertence a outro contato da empresa.'
                : 'Não foi possível atualizar o contato.');
        }

        $this->redirect('/contacts?contact_id=' . $contactId);
    }

    private function findContact(int $contactId): ?array
    {
        if ($contactId < 1) {
            return null;
        }
        $sql = 'SELECT ct.*, t.name AS tenant_name, i.name AS instance_name
                FROM contacts ct
                INNER JOIN tenants t ON t.id = ct.tenant_id
                LEFT JOIN evolution_instances i ON i.id = ct.evolution_instance_id
                WHERE ct.id = :id';
        $params = ['id' => $contactId];
        if (!Auth::isSuperAdmin()) {
            $sql .= ' AND ct.tenant_id = :tenant_id';
            $params['tenant_id'] = Auth::tenantId();
        }
        $statement = Database::connection()->prepare($sql . ' LIMIT 1');
        $statement->execute($params);
        $contact = $statement->fetch(PDO::FETCH_ASSOC);
        return $contact ?: null;
    }

    private function postedTenantId(): int
    {
        return Auth::isSuperAdmin()
            ? (int) ($_POST['tenant_id'] ?? 0)
            : (int) Auth::tenantId();
    }

    private function instanceBelongsToTenant(int $instanceId, int $tenantId): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM evolution_instances WHERE id = :id AND tenant_id = :tenant_id'
        );
        $statement->execute(['id' => $instanceId, 'tenant_id' => $tenantId]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function normalizeTags(string $raw): array
    {
        $tags = array_map('trim', explode(',', $raw));
        $tags = array_filter($tags, static fn (string $tag): bool => $tag !== '');
        return array_values(array_unique(array_slice($tags, 0, 12)));
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}

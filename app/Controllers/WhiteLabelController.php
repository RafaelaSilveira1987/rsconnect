<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\BrandingService;
use PDO;
use RuntimeException;
use Throwable;

final class WhiteLabelController
{
    public function index(): void
    {
        $pdo = Database::connection();
        $companies = $pdo->query(
            'SELECT id, name, slug, status, white_label_enabled, brand_logo_url
             FROM tenants
             ORDER BY name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $selectedId = (int) ($_GET['tenant_id'] ?? ($companies[0]['id'] ?? 0));
        $selected = null;

        if ($selectedId > 0) {
            $statement = $pdo->prepare(
                'SELECT id, name, slug, status, white_label_enabled, brand_logo_url
                 FROM tenants
                 WHERE id = :id
                 LIMIT 1'
            );
            $statement->execute(['id' => $selectedId]);
            $selected = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        View::render('white_label.index', [
            'title' => 'Logo do cliente',
            'companies' => $companies,
            'selected' => $selected,
        ]);
    }

    public function save(): void
    {
        $tenantId = (int) ($_POST['tenant_id'] ?? 0);
        if ($tenantId < 1) {
            Flash::set('error', 'Selecione uma empresa para configurar a logo.');
            $this->redirect('/white-label');
        }

        $pdo = Database::connection();
        $statement = $pdo->prepare(
            'SELECT id, name, white_label_enabled, brand_logo_url
             FROM tenants
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $tenantId]);
        $current = $statement->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$current) {
            Flash::set('error', 'Empresa não encontrada.');
            $this->redirect('/white-label');
        }

        $currentLogo = trim((string) ($current['brand_logo_url'] ?? ''));
        $newLogo = $currentLogo;
        $uploadedLogo = null;

        try {
            if (isset($_POST['remove_logo'])) {
                $newLogo = '';
            } else {
                $uploadedLogo = $this->uploadLogo($tenantId);
                if ($uploadedLogo !== null) {
                    $newLogo = $uploadedLogo;
                }
            }

            $enabled = $newLogo !== '' ? 1 : 0;
            $update = $pdo->prepare(
                'UPDATE tenants
                 SET white_label_enabled = :enabled,
                     brand_logo_url = :brand_logo_url
                 WHERE id = :tenant_id'
            );
            $update->execute([
                'enabled' => $enabled,
                'brand_logo_url' => $newLogo !== '' ? $newLogo : null,
                'tenant_id' => $tenantId,
            ]);

            if ($currentLogo !== '' && $currentLogo !== $newLogo) {
                $this->deleteStoredLogo($currentLogo, $tenantId);
            }

            Audit::log('white_label.logo_updated', [
                'enabled' => $enabled,
                'has_logo' => $newLogo !== '',
            ], $tenantId);

            Flash::set(
                'success',
                $newLogo !== ''
                    ? 'Logo do cliente atualizada com sucesso.'
                    : 'Logo removida. A identidade padrão da RS Connect voltou a ser usada.'
            );
        } catch (Throwable $exception) {
            if ($uploadedLogo !== null && $uploadedLogo !== $currentLogo) {
                $this->deleteStoredLogo($uploadedLogo, $tenantId);
            }

            $message = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Não foi possível salvar a logo. Verifique o banco e as permissões de storage/app.';
            Flash::set('error', $message);
        }

        $this->redirect('/white-label?tenant_id=' . $tenantId);
    }

    public function asset(): void
    {
        $tenantId = (int) ($_GET['scope'] ?? 0);
        $filename = basename(trim((string) ($_GET['file'] ?? '')));

        if ($tenantId < 1 || preg_match('/^logo-\d{14}-[a-f0-9]{8}\.(?:png|jpg|webp)$/D', $filename) !== 1) {
            $this->assetNotFound();
        }

        $path = $this->brandStorageDirectory($tenantId) . '/' . $filename;
        if (!is_file($path) || !is_readable($path)) {
            $this->assetNotFound();
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower((string) $finfo->file($path));
        $allowed = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
        ];
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

        if (!isset($allowed[$mime]) || $allowed[$mime] !== $extension) {
            $this->assetNotFound();
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Cache-Control: public, max-age=86400, immutable');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; sandbox");
        readfile($path);
        exit;
    }

    public function preview(): void
    {
        $tenantId = (int) ($_GET['tenant_id'] ?? 0);
        $branding = BrandingService::forTenantId($tenantId);

        View::render('auth.login', [
            'title' => 'Pré-visualização do login',
            'branding' => $branding,
            'isPreview' => true,
        ], 'guest');
    }

    private function uploadLogo(int $tenantId): ?string
    {
        $file = $_FILES['brand_logo_file'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Não foi possível enviar a imagem. Tente novamente.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > 2 * 1024 * 1024) {
            throw new RuntimeException('A logo deve ter no máximo 2 MB.');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('O arquivo enviado não pôde ser validado.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower((string) $finfo->file($tmpName));
        $extensions = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
        ];

        if (!isset($extensions[$mime])) {
            throw new RuntimeException('Envie somente uma logo PNG, JPG/JPEG ou WEBP. SVG e ICO não são aceitos.');
        }

        $imageInfo = @getimagesize($tmpName);
        if (!is_array($imageInfo) || strtolower((string) ($imageInfo['mime'] ?? '')) !== $mime) {
            throw new RuntimeException('O conteúdo enviado não corresponde a uma imagem válida.');
        }

        $width = (int) ($imageInfo[0] ?? 0);
        $height = (int) ($imageInfo[1] ?? 0);
        if ($width < 1 || $height < 1 || $width > 4096 || $height > 4096 || ($width * $height) > 16000000) {
            throw new RuntimeException('A logo deve ter dimensões válidas de até 4096 × 4096 pixels.');
        }

        $uploadDir = $this->brandStorageDirectory($tenantId);
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Não foi possível preparar o armazenamento. Verifique a permissão de storage/app.');
        }
        if (!is_writable($uploadDir)) {
            throw new RuntimeException('O armazenamento não permite gravação. Verifique a permissão de storage/app.');
        }

        $filename = 'logo-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extensions[$mime];
        $destination = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($tmpName, $destination)) {
            throw new RuntimeException('Não foi possível salvar a logo no armazenamento persistente.');
        }
        @chmod($destination, 0644);

        return '/white-label/asset?scope=' . $tenantId . '&file=' . rawurlencode($filename);
    }

    private function deleteStoredLogo(string $url, int $tenantId): void
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path !== '/white-label/asset') {
            return;
        }

        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        if ((int) ($query['scope'] ?? 0) !== $tenantId) {
            return;
        }

        $filename = basename((string) ($query['file'] ?? ''));
        if (preg_match('/^logo-\d{14}-[a-f0-9]{8}\.(?:png|jpg|webp)$/D', $filename) !== 1) {
            return;
        }

        $file = $this->brandStorageDirectory($tenantId) . '/' . $filename;
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function brandStorageDirectory(int $tenantId): string
    {
        return dirname(__DIR__, 2) . '/storage/app/white-label/tenant-' . $tenantId;
    }

    private function assetNotFound(): never
    {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo '{"status":"not_found"}';
        exit;
    }

    private function redirect(string $path): never
    {
        header('Location: ' . Router::url($path));
        exit;
    }
}

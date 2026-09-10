<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Centraliza a identidade operacional das conexões Evolution.
 *
 * A regra é propositalmente fail-open antes da migration 108 e fail-closed
 * somente quando existe evidência objetiva de que o número conectado diverge
 * do número autorizado para a instância.
 */
final class EvolutionInstanceSafetyService
{
    private static ?bool $schemaSupported = null;

    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        return ltrim($digits, '0');
    }

    /**
     * Normaliza somente valores que podem representar um telefone real.
     * Evita tratar status HTTP (ex.: 200), ids curtos ou outros metadados como número.
     */
    public static function normalizeObservedPhone(string $phone): string
    {
        $digits = self::normalizePhone($phone);
        return self::isPlausiblePhone($digits) ? $digits : '';
    }

    public static function isPlausiblePhone(string $phone): bool
    {
        $digits = self::normalizePhone($phone);
        $length = strlen($digits);
        return $length >= 10 && $length <= 15;
    }

    public static function phonesEquivalent(string $left, string $right): bool
    {
        $a = self::normalizePhone($left);
        $b = self::normalizePhone($right);
        if ($a === '' || $b === '') {
            return false;
        }
        if (hash_equals($a, $b)) {
            return true;
        }

        // WhatsApp/Evolution pode alternar o nono dígito brasileiro em alguns JIDs.
        foreach ([[$a, $b], [$b, $a]] as [$longer, $shorter]) {
            if (strlen($longer) === 13 && strlen($shorter) === 12
                && str_starts_with($longer, '55') && str_starts_with($shorter, '55')
                && substr($longer, 0, 4) === substr($shorter, 0, 4)
                && $longer[4] === '9'
                && substr($longer, 5) === substr($shorter, 4)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $payload */
    public static function extractConnectedPhone(array $payload): string
    {
        $candidateKeys = [
            'ownerjid', 'owner_jid', 'number', 'phone', 'wuid', 'wid',
            'connectedphone', 'connected_phone', 'phonenumber', 'phone_number',
        ];

        foreach ($payload as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if (in_array($normalizedKey, $candidateKeys, true) && is_scalar($value)) {
                $digits = self::normalizeObservedPhone((string) $value);
                if ($digits !== '') {
                    return $digits;
                }
            }
        }

        // A Evolution varia o envelope entre versões e o endpoint fetchInstances
        // normalmente devolve uma lista. Percorre somente estruturas aninhadas e
        // continua aceitando telefone apenas em chaves semanticamente seguras.
        foreach ($payload as $value) {
            if (is_array($value)) {
                $found = self::extractConnectedPhone($value);
                if ($found !== '') {
                    return $found;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $instance
     * @return array{status:string,authorized_phone:string,connected_phone:string,message:string}
     */
    public static function assess(array $instance): array
    {
        $authorized = self::normalizeObservedPhone((string) ($instance['authorized_phone'] ?? ''));
        $connected = self::normalizeObservedPhone((string) ($instance['profile_phone'] ?? ''));

        if ($authorized === '') {
            return [
                'status' => 'unconfigured',
                'authorized_phone' => '',
                'connected_phone' => $connected,
                'message' => 'Número autorizado ainda não definido.',
            ];
        }
        if ($connected === '') {
            return [
                'status' => 'unknown',
                'authorized_phone' => $authorized,
                'connected_phone' => '',
                'message' => 'Aguardando confirmação do número conectado.',
            ];
        }
        if (self::phonesEquivalent($authorized, $connected)) {
            return [
                'status' => 'verified',
                'authorized_phone' => $authorized,
                'connected_phone' => $connected,
                'message' => 'Número conectado confere com o número autorizado.',
            ];
        }

        return [
            'status' => 'mismatch',
            'authorized_phone' => $authorized,
            'connected_phone' => $connected,
            'message' => 'O número conectado não corresponde ao número autorizado desta conexão.',
        ];
    }

    /** @param array<string,mixed> $instance */
    public static function assertInboundAllowed(array $instance): void
    {
        if (!self::schemaSupported()) {
            return;
        }
        $assessment = self::assess($instance);
        $storedStatus = strtolower(trim((string) ($instance['identity_status'] ?? '')));
        if ($assessment['status'] === 'mismatch' || $storedStatus === 'mismatch') {
            throw new RuntimeException('Mensagem ignorada: a conexão está usando um número diferente do número autorizado.');
        }
    }

    public static function assertOutboundAllowedByConnection(string $baseUrl, string $instanceName): void
    {
        if (!self::schemaSupported()) {
            return;
        }

        try {
            $statement = Database::connection()->prepare(
                'SELECT authorized_phone, profile_phone, identity_status
                 FROM evolution_instances
                 WHERE LOWER(TRIM(TRAILING '/' FROM TRIM(base_url))) = LOWER(:base_url)
                   AND instance_name = :instance_name
                 LIMIT 2'
            );
            $statement->execute([
                'base_url' => rtrim(trim($baseUrl), '/'),
                'instance_name' => trim($instanceName),
            ]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (count($rows) !== 1) {
                return;
            }

            $assessment = self::assess($rows[0]);
            $storedStatus = strtolower(trim((string) ($rows[0]['identity_status'] ?? '')));
            if ($assessment['status'] === 'mismatch' || $storedStatus === 'mismatch') {
                throw new RuntimeException(
                    'Envio bloqueado por segurança: o número conectado ('
                    . ($assessment['connected_phone'] !== '' ? $assessment['connected_phone'] : 'não confirmado')
                    . ') é diferente do número autorizado ('
                    . ($assessment['authorized_phone'] !== '' ? $assessment['authorized_phone'] : 'não definido')
                    . '). Revise a conexão em Canais WhatsApp.'
                );
            }
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Antes da migration 108 ou durante indisponibilidade de leitura, preserva
            // a compatibilidade do envio. O bloqueio só ocorre com evidência objetiva.
        }
    }

    /**
     * Atualiza a identidade observada e, quando permitido, adota automaticamente o
     * primeiro número confirmado como número autorizado.
     *
     * @param array<string,mixed> $instance
     * @return array{status:string,authorized_phone:string,connected_phone:string,message:string}
     */
    public static function persistObservedIdentity(PDO $pdo, array $instance, string $connectedPhone, bool $adoptIfEmpty = true): array
    {
        if (!self::schemaSupported($pdo)) {
            $copy = $instance;
            $copy['profile_phone'] = self::normalizeObservedPhone($connectedPhone);
            return self::assess($copy);
        }

        $connected = self::normalizeObservedPhone($connectedPhone);
        $authorized = self::normalizeObservedPhone((string) ($instance['authorized_phone'] ?? ''));
        if ($authorized === '' && $connected !== '' && $adoptIfEmpty) {
            $authorized = $connected;
        }

        $copy = $instance;
        $copy['authorized_phone'] = $authorized;
        $copy['profile_phone'] = $connected !== '' ? $connected : (string) ($instance['profile_phone'] ?? '');
        $assessment = self::assess($copy);
        $identityStatus = match ($assessment['status']) {
            'verified' => 'verified',
            'mismatch' => 'mismatch',
            default => 'unknown',
        };

        $statement = $pdo->prepare(
            'UPDATE evolution_instances
             SET authorized_phone = :authorized_phone,
                 profile_phone = COALESCE(NULLIF(:profile_phone, ""), profile_phone),
                 identity_status = :identity_status,
                 identity_mismatch_at = CASE WHEN :identity_status_mismatch = 1 THEN COALESCE(identity_mismatch_at, NOW()) ELSE NULL END
             WHERE id = :id'
        );
        $statement->execute([
            'authorized_phone' => $authorized !== '' ? $authorized : null,
            'profile_phone' => $connected,
            'identity_status' => $identityStatus,
            'identity_status_mismatch' => $identityStatus === 'mismatch' ? 1 : 0,
            'id' => (int) ($instance['id'] ?? 0),
        ]);

        return $assessment;
    }

    public static function schemaSupported(?PDO $pdo = null): bool
    {
        if (self::$schemaSupported !== null) {
            return self::$schemaSupported;
        }
        try {
            $pdo ??= Database::connection();
            $statement = $pdo->query(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = "evolution_instances"
                   AND COLUMN_NAME IN ("authorized_phone", "identity_status")'
            );
            return self::$schemaSupported = (int) $statement->fetchColumn() === 2;
        } catch (Throwable) {
            return self::$schemaSupported = false;
        }
    }
}

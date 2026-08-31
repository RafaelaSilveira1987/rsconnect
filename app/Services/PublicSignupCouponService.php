<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class PublicSignupCouponService
{
    public const VERSION = '36.26.0';

    /** @return list<array<string,mixed>> */
    public function adminList(): array
    {
        $statement = Database::connection()->query(
            'SELECT c.*,
                    COALESCE(u.redeemed_count, 0) AS redeemed_count,
                    COALESCE(u.reserved_count, 0) AS reserved_count,
                    COALESCE(u.total_discount, 0) AS total_discount,
                    u.last_redeemed_at
             FROM public_signup_coupons c
             LEFT JOIN (
                 SELECT coupon_id,
                        SUM(CASE WHEN status = "provisioned" THEN 1 ELSE 0 END) AS redeemed_count,
                        SUM(CASE WHEN status IN ("started","checkout_created","checkout_completed")
                                  AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) THEN 1 ELSE 0 END) AS reserved_count,
                        SUM(CASE WHEN status = "provisioned" THEN discount_amount ELSE 0 END) AS total_discount,
                        MAX(CASE WHEN status = "provisioned" THEN provisioned_at ELSE NULL END) AS last_redeemed_at
                 FROM public_signup_sessions
                 WHERE coupon_id IS NOT NULL
                 GROUP BY coupon_id
             ) u ON u.coupon_id = c.id
             ORDER BY c.active DESC, c.created_at DESC, c.id DESC'
        );
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed> */
    public function metrics(): array
    {
        $row = Database::connection()->query(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END), 0) AS active
             FROM public_signup_coupons'
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $usage = Database::connection()->query(
            'SELECT COALESCE(COUNT(*), 0) AS redeemed,
                    COALESCE(SUM(discount_amount), 0) AS total_discount
             FROM public_signup_sessions
             WHERE coupon_id IS NOT NULL AND status = "provisioned"'
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'redeemed' => (int) ($usage['redeemed'] ?? 0),
            'total_discount' => (float) ($usage['total_discount'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $input */
    public function save(array $input, ?int $userId): int
    {
        $id = max(0, (int) ($input['id'] ?? 0));
        $code = self::normalizeCode((string) ($input['code'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $discountType = strtolower(trim((string) ($input['discount_type'] ?? 'percentage')));
        $discountValue = round((float) str_replace(',', '.', (string) ($input['discount_value'] ?? '0')), 2);
        $duration = strtolower(trim((string) ($input['duration'] ?? 'first_charge')));
        $paymentMethod = strtolower(trim((string) ($input['payment_method'] ?? 'all')));
        $startsAt = $this->localInputToUtc((string) ($input['starts_at'] ?? ''));
        $endsAt = $this->localInputToUtc((string) ($input['ends_at'] ?? ''));
        $maxRedemptions = $this->nullablePositiveInt($input['max_redemptions'] ?? null);
        $maxPerEmail = max(1, min(100, (int) ($input['max_redemptions_per_email'] ?? 1)));
        $minimumAmount = max(0, round((float) str_replace(',', '.', (string) ($input['minimum_amount'] ?? '0')), 2));
        $active = !empty($input['active']) ? 1 : 0;

        if ($code === '' || strlen($code) < 3 || strlen($code) > 50) {
            throw new RuntimeException('O código do cupom deve ter entre 3 e 50 caracteres.');
        }
        if (mb_strlen($name) < 3 || mb_strlen($name) > 120) {
            throw new RuntimeException('Informe um nome interno para o cupom.');
        }
        if (!in_array($discountType, ['percentage', 'fixed'], true)) {
            throw new RuntimeException('Selecione um tipo de desconto válido.');
        }
        if ($discountType === 'percentage' && ($discountValue <= 0 || $discountValue > 90)) {
            throw new RuntimeException('O desconto percentual deve ser maior que 0 e no máximo 90%.');
        }
        if ($discountType === 'fixed' && $discountValue <= 0) {
            throw new RuntimeException('O desconto em reais deve ser maior que zero.');
        }
        if (!in_array($duration, ['first_charge', 'recurring'], true)) {
            throw new RuntimeException('Selecione se o desconto vale para a primeira cobrança ou para toda a assinatura.');
        }
        if (!in_array($paymentMethod, ['all', 'credit_card', 'pix'], true)) {
            throw new RuntimeException('Selecione uma forma de pagamento válida para o cupom.');
        }
        if ($startsAt !== null && $endsAt !== null && strtotime($endsAt) <= strtotime($startsAt)) {
            throw new RuntimeException('A data final do cupom precisa ser posterior à data inicial.');
        }

        $pdo = Database::connection();
        $duplicate = $pdo->prepare('SELECT id FROM public_signup_coupons WHERE code = :code AND id <> :id LIMIT 1');
        $duplicate->execute(['code' => $code, 'id' => $id]);
        if ($duplicate->fetchColumn()) {
            throw new RuntimeException('Já existe um cupom com este código.');
        }

        $params = [
            'code' => $code,
            'name' => mb_substr($name, 0, 120),
            'description' => $description !== '' ? mb_substr($description, 0, 255) : null,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'duration' => $duration,
            'payment_method' => $paymentMethod,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'max_redemptions' => $maxRedemptions,
            'max_redemptions_per_email' => $maxPerEmail,
            'minimum_amount' => $minimumAmount,
            'active' => $active,
            'user_id' => $userId,
        ];

        if ($id > 0) {
            $params['id'] = $id;
            $statement = $pdo->prepare(
                'UPDATE public_signup_coupons
                 SET code = :code, name = :name, description = :description,
                     discount_type = :discount_type, discount_value = :discount_value,
                     duration = :duration, payment_method = :payment_method,
                     starts_at = :starts_at, ends_at = :ends_at,
                     max_redemptions = :max_redemptions,
                     max_redemptions_per_email = :max_redemptions_per_email,
                     minimum_amount = :minimum_amount, active = :active,
                     updated_by_user_id = :user_id
                 WHERE id = :id'
            );
            $statement->execute($params);
            if ($statement->rowCount() === 0) {
                $exists = $pdo->prepare('SELECT id FROM public_signup_coupons WHERE id = :id');
                $exists->execute(['id' => $id]);
                if (!$exists->fetchColumn()) {
                    throw new RuntimeException('Cupom não encontrado.');
                }
            }
            return $id;
        }

        $statement = $pdo->prepare(
            'INSERT INTO public_signup_coupons
                (code, name, description, discount_type, discount_value, duration, payment_method,
                 starts_at, ends_at, max_redemptions, max_redemptions_per_email,
                 minimum_amount, active, created_by_user_id, updated_by_user_id)
             VALUES
                (:code, :name, :description, :discount_type, :discount_value, :duration, :payment_method,
                 :starts_at, :ends_at, :max_redemptions, :max_redemptions_per_email,
                 :minimum_amount, :active, :created_user_id, :updated_user_id)'
        );
        $insertParams = $params;
        unset($insertParams['user_id']);
        $insertParams['created_user_id'] = $userId;
        $insertParams['updated_user_id'] = $userId;
        $statement->execute($insertParams);
        return (int) $pdo->lastInsertId();
    }

    public function toggle(int $id, bool $active, ?int $userId): void
    {
        if ($id < 1) {
            throw new RuntimeException('Cupom inválido.');
        }
        $statement = Database::connection()->prepare(
            'UPDATE public_signup_coupons
             SET active = :active, updated_by_user_id = :user_id
             WHERE id = :id'
        );
        $statement->execute(['active' => $active ? 1 : 0, 'user_id' => $userId, 'id' => $id]);
        if ($statement->rowCount() === 0) {
            $exists = Database::connection()->prepare('SELECT id FROM public_signup_coupons WHERE id = :id');
            $exists->execute(['id' => $id]);
            if (!$exists->fetchColumn()) {
                throw new RuntimeException('Cupom não encontrado.');
            }
        }
    }

    /**
     * @return array{id:int,code:string,name:string,description:string,discount_type:string,discount_value:float,duration:string,payment_method:string,original_amount:float,discount_amount:float,final_amount:float,label:string}
     */
    public function apply(string $rawCode, float $baseAmount, string $email, string $paymentMethod): array
    {
        $code = self::normalizeCode($rawCode);
        if ($code === '') {
            throw new RuntimeException('Informe o código do cupom.');
        }
        $paymentMethod = $paymentMethod === 'pix' ? 'pix' : 'credit_card';
        $email = mb_strtolower(trim($email));
        $pdo = Database::connection();

        $statement = $pdo->prepare('SELECT * FROM public_signup_coupons WHERE code = :code AND active = 1 LIMIT 1');
        $statement->execute(['code' => $code]);
        $coupon = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$coupon) {
            throw new RuntimeException('Cupom inválido ou indisponível.');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if (!empty($coupon['starts_at']) && $now < new DateTimeImmutable((string) $coupon['starts_at'], new DateTimeZone('UTC'))) {
            throw new RuntimeException('Este cupom ainda não está disponível.');
        }
        if (!empty($coupon['ends_at']) && $now > new DateTimeImmutable((string) $coupon['ends_at'], new DateTimeZone('UTC'))) {
            throw new RuntimeException('Este cupom expirou.');
        }
        $allowedMethod = (string) ($coupon['payment_method'] ?? 'all');
        if ($allowedMethod !== 'all' && $allowedMethod !== $paymentMethod) {
            throw new RuntimeException($allowedMethod === 'pix'
                ? 'Este cupom é válido somente para pagamento por Pix.'
                : 'Este cupom é válido somente para cartão de crédito.');
        }
        if ($baseAmount < (float) ($coupon['minimum_amount'] ?? 0)) {
            throw new RuntimeException('O valor do plano não atende ao mínimo exigido por este cupom.');
        }

        $usage = $pdo->prepare(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN email = :email THEN 1 ELSE 0 END), 0) AS email_total
             FROM public_signup_sessions
             WHERE coupon_id = :coupon_id
               AND (status = "provisioned"
                    OR (status IN ("started","checkout_created","checkout_completed")
                        AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())))'
        );
        $usage->execute(['email' => $email, 'coupon_id' => (int) $coupon['id']]);
        $counts = $usage->fetch(PDO::FETCH_ASSOC) ?: [];
        $maxRedemptions = isset($coupon['max_redemptions']) && $coupon['max_redemptions'] !== null
            ? (int) $coupon['max_redemptions']
            : null;
        if ($maxRedemptions !== null && (int) ($counts['total'] ?? 0) >= $maxRedemptions) {
            throw new RuntimeException('Este cupom atingiu o limite de utilizações.');
        }
        if ($email !== '' && (int) ($counts['email_total'] ?? 0) >= max(1, (int) ($coupon['max_redemptions_per_email'] ?? 1))) {
            throw new RuntimeException('Este cupom já foi utilizado por este e-mail.');
        }

        $discountType = (string) $coupon['discount_type'];
        $discountValue = (float) $coupon['discount_value'];
        $discountAmount = $discountType === 'percentage'
            ? round($baseAmount * ($discountValue / 100), 2)
            : round($discountValue, 2);
        $discountAmount = min($discountAmount, max(0, $baseAmount - 1.00));
        $finalAmount = round($baseAmount - $discountAmount, 2);
        if ($discountAmount <= 0 || $finalAmount < 1.00) {
            throw new RuntimeException('Este cupom não pode ser aplicado ao valor atual do plano.');
        }

        $label = $discountType === 'percentage'
            ? rtrim(rtrim(number_format($discountValue, 2, ',', '.'), '0'), ',') . '% de desconto'
            : 'R$ ' . number_format($discountAmount, 2, ',', '.') . ' de desconto';

        return [
            'id' => (int) $coupon['id'],
            'code' => (string) $coupon['code'],
            'name' => (string) $coupon['name'],
            'description' => (string) ($coupon['description'] ?? ''),
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'duration' => (string) ($coupon['duration'] ?? 'first_charge'),
            'payment_method' => $allowedMethod,
            'original_amount' => round($baseAmount, 2),
            'discount_amount' => $discountAmount,
            'final_amount' => $finalAmount,
            'label' => $label,
        ];
    }

    public static function normalizeCode(string $code): string
    {
        $code = strtoupper(trim($code));
        return preg_replace('/[^A-Z0-9_-]+/', '', $code) ?: '';
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        return max(1, min(1000000, (int) $raw));
    }

    private function localInputToUtc(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $local = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, new DateTimeZone(Clock::appTimezone()));
        if (!$local) {
            throw new RuntimeException('Informe uma data e hora válida para o cupom.');
        }
        return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}

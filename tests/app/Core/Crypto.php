<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    public static function encrypt(string $plainText): string
    {
        if ($plainText === '') {
            return '';
        }

        $key = self::key();
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = random_bytes($ivLength);
        $tag = '';
        $encrypted = openssl_encrypt($plainText, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($encrypted === false) {
            throw new RuntimeException('Falha ao criptografar dado sensível.');
        }

        return base64_encode($iv . $tag . $encrypted);
    }

    public static function decrypt(string $payload): string
    {
        if ($payload === '') {
            return '';
        }

        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            throw new RuntimeException('Dado criptografado inválido.');
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $tagLength = 16;
        $iv = substr($decoded, 0, $ivLength);
        $tag = substr($decoded, $ivLength, $tagLength);
        $encrypted = substr($decoded, $ivLength + $tagLength);

        $plainText = openssl_decrypt(
            $encrypted,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plainText === false) {
            throw new RuntimeException('Não foi possível descriptografar a API Key. Confira APP_KEY.');
        }

        return $plainText;
    }

    private static function key(): string
    {
        $appKey = (string) Env::get('APP_KEY', '');
        if ($appKey === '') {
            throw new RuntimeException('APP_KEY não configurada. Execute php bin/generate-key.php.');
        }

        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            if ($decoded !== false) {
                return hash('sha256', $decoded, true);
            }
        }

        return hash('sha256', $appKey, true);
    }
}

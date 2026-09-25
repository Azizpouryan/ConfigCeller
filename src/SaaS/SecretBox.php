<?php

declare(strict_types=1);

namespace MirzaBot\SaaS;

use InvalidArgumentException;
use RuntimeException;

/**
 * Versioned libsodium secretbox encryption for credentials and bot secrets.
 * The key is supplied by the process environment, never by the database.
 */
final class SecretBox
{
    private const VERSION = 'v1';
    private const KEY_BYTES = 32;

    private function __construct(private readonly string $key)
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new RuntimeException('The sodium extension is required for secret storage.');
        }
        if (strlen($key) !== self::KEY_BYTES) {
            throw new InvalidArgumentException('The secret key must be exactly 32 bytes.');
        }
    }

    public static function fromEnvironment(string $variable = 'MIRZABOT_SECRET_KEY'): self
    {
        $value = getenv($variable);
        if ($value === false || trim((string) $value) === '') {
            throw new RuntimeException("Missing {$variable}; refusing to store a secret without an external key.");
        }

        $value = trim((string) $value);
        if (str_starts_with($value, 'base64:')) {
            $value = substr($value, 7);
        }

        $decoded = base64_decode($value, true);
        if ($decoded !== false && strlen($decoded) === self::KEY_BYTES) {
            return new self($decoded);
        }

        if (strlen($value) === self::KEY_BYTES) {
            return new self($value);
        }

        throw new InvalidArgumentException('MIRZABOT_SECRET_KEY must be a 32-byte value or base64-encoded 32-byte value.');
    }

    public static function isConfigured(string $variable = 'MIRZABOT_SECRET_KEY'): bool
    {
        try {
            self::fromEnvironment($variable);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return self::VERSION . '.' . base64_encode($nonce . $ciphertext);
    }

    public function decrypt(string $encoded): string
    {
        [$version, $payload] = array_pad(explode('.', $encoded, 2), 2, null);
        if ($version !== self::VERSION || !is_string($payload) || $payload === '') {
            throw new InvalidArgumentException('Unsupported or malformed encrypted secret.');
        }

        $decoded = base64_decode($payload, true);
        if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new InvalidArgumentException('Malformed encrypted secret.');
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        if ($plaintext === false) {
            throw new RuntimeException('Unable to decrypt secret.');
        }

        return $plaintext;
    }
}

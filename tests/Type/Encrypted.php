<?php

declare(strict_types=1);

namespace SpotTest\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

class Encrypted extends Type
{
    public static $key;

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
    {
        if (is_string($value)) {
            $value = self::aes256_decrypt(self::$key, base64_decode($value));
        } else {
            $value = null;
        }

        return $value;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): mixed
    {
        return base64_encode(self::aes256_encrypt(self::$key, $value));
    }

    public function getName(): string
    {
        return 'encrypted';
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'TEXT';
    }

    private function aes256_encrypt(string $key, string $data): string
    {
        if (32 !== strlen($key)) {
            $key = hash('SHA256', $key, true);
        }
        // Pad data to 16-byte boundary for ZERO_PADDING mode
        $padLen = 16 - (strlen($data) % 16);
        $data .= str_repeat(chr($padLen), $padLen);
        $iv = str_repeat("\0", 16);
        $result = openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);

        return $result !== false ? $result : '';
    }

    private function aes256_decrypt(string $key, string $data): string
    {
        if (32 !== strlen($key)) {
            $key = hash('SHA256', $key, true);
        }
        $iv = str_repeat("\0", 16);
        $decrypted = openssl_decrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
        $padding = ord($decrypted[strlen($decrypted) - 1]);

        return substr($decrypted, 0, -$padding);
    }
}

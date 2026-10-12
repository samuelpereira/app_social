<?php

namespace App\Services\Slush;

use App\Exceptions\InvalidSlushSignatureException;

class ZkLoginSignature
{
    private const FLAG = 5;

    /**
     * @return array{0: string, 1: string}
     */
    public static function addresses(string $serializedSignature): array
    {
        $bytes = base64_decode($serializedSignature, true);

        if ($bytes === false || $bytes === '' || ord($bytes[0]) !== self::FLAG) {
            throw new InvalidSlushSignatureException('Slush sign-in could not be verified.');
        }

        $reader = new BcsReader(substr($bytes, 1));
        $reader->skipProofPoints();
        $issuer = self::issuer($reader->string(), $reader->u8());
        $reader->string();
        $addressSeed = $reader->string();

        if ($addressSeed === '' || ! ctype_digit($addressSeed)) {
            throw new InvalidSlushSignatureException('Slush sign-in could not be verified.');
        }

        return [
            self::address($issuer, $addressSeed, padded: true),
            self::address($issuer, $addressSeed, padded: false),
        ];
    }

    private static function issuer(string $value, int $indexMod4): string
    {
        $decoded = self::decodeBase64Url($value, $indexMod4);
        $last = substr($decoded, -1);

        if ($last !== '}' && $last !== ',') {
            throw new InvalidSlushSignatureException('Slush sign-in could not be verified.');
        }

        $claim = json_decode('{'.substr($decoded, 0, -1).'}', true);

        if (! is_array($claim) || count($claim) !== 1 || ! isset($claim['iss']) || ! is_string($claim['iss'])) {
            throw new InvalidSlushSignatureException('Slush sign-in could not be verified.');
        }

        if ($claim['iss'] === 'accounts.google.com') {
            return 'https://accounts.google.com';
        }

        return $claim['iss'];
    }

    private static function decodeBase64Url(string $value, int $indexMod4): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        $bits = '';

        for ($index = 0; $index < strlen($value); $index++) {
            $position = strpos($alphabet, $value[$index]);

            if ($position === false) {
                throw new InvalidSlushSignatureException('Slush sign-in could not be verified.');
            }

            $bits .= str_pad(decbin($position), 6, '0', STR_PAD_LEFT);
        }

        $firstOffset = $indexMod4 % 4;

        if ($firstOffset === 1) {
            $bits = substr($bits, 2);
        } elseif ($firstOffset === 2) {
            $bits = substr($bits, 4);
        } elseif ($firstOffset !== 0) {
            throw new InvalidSlushSignatureException('Slush sign-in could not be verified.');
        }

        $lastOffset = ($indexMod4 + strlen($value) - 1) % 4;

        if ($lastOffset === 2) {
            $bits = substr($bits, 0, -2);
        } elseif ($lastOffset === 1) {
            $bits = substr($bits, 0, -4);
        } elseif ($lastOffset !== 3) {
            throw new InvalidSlushSignatureException('Slush sign-in could not be verified.');
        }

        if (strlen($bits) % 8 !== 0) {
            throw new InvalidSlushSignatureException('Slush sign-in could not be verified.');
        }

        $decoded = '';

        for ($index = 0; $index < strlen($bits); $index += 8) {
            $decoded .= chr((int) bindec(substr($bits, $index, 8)));
        }

        return $decoded;
    }

    private static function address(string $issuer, string $addressSeed, bool $padded): string
    {
        $seed = self::seedBytes($addressSeed, $padded);
        $issuerBytes = $issuer;
        $publicKey = chr(strlen($issuerBytes)).$issuerBytes.$seed;
        $digest = sodium_crypto_generichash(chr(self::FLAG).$publicKey, '', 32);

        return '0x'.bin2hex($digest);
    }

    private static function seedBytes(string $addressSeed, bool $padded): string
    {
        $hex = gmp_strval(gmp_init($addressSeed, 10), 16);

        if (strlen($hex) % 2 === 1) {
            $hex = '0'.$hex;
        }

        $hex = substr(str_pad($hex, 64, '0', STR_PAD_LEFT), -64);
        $bytes = hex2bin($hex);

        if ($bytes === false) {
            throw new InvalidSlushSignatureException('Slush sign-in could not be verified.');
        }

        if ($padded) {
            return $bytes;
        }

        $trimmed = ltrim($bytes, "\0");

        return $trimmed === '' ? "\0" : $trimmed;
    }
}

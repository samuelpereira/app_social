<?php

namespace App\Services\Slush;

use App\Exceptions\InvalidSlushSignatureException;

class SlushPersonalMessage
{
    private const ED25519_FLAG = 0;

    private const ZKLOGIN_FLAG = 5;

    private const SERIALIZED_SIGNATURE_LENGTH = 97;

    private const SIGNATURE_LENGTH = 64;

    private const PUBLIC_KEY_LENGTH = 32;

    /**
     * Recover the Sui address that signed a personal message.
     *
     * An Ed25519 account is checked locally. A Google account inside Slush
     * is a zkLogin signature, which the Sui network checks.
     */
    public static function address(string $message, string $serializedSignature): string
    {
        $bytes = base64_decode(trim($serializedSignature), true);

        if ($bytes === false || $bytes === '') {
            throw new InvalidSlushSignatureException('Slush sign-in could not be verified.');
        }

        $flag = ord($bytes[0]);

        if ($flag === self::ZKLOGIN_FLAG) {
            return (new SuiZkLoginVerifier)->address($message, trim($serializedSignature));
        }

        if ($flag !== self::ED25519_FLAG || strlen($bytes) !== self::SERIALIZED_SIGNATURE_LENGTH) {
            throw new InvalidSlushSignatureException('Slush sign-in could not be verified.');
        }

        $signature = substr($bytes, 1, self::SIGNATURE_LENGTH);
        $publicKey = substr($bytes, 1 + self::SIGNATURE_LENGTH, self::PUBLIC_KEY_LENGTH);

        if ($signature === '' || $publicKey === '') {
            throw new InvalidSlushSignatureException('Slush sign-in could not be verified.');
        }

        $verified = sodium_crypto_sign_verify_detached($signature, self::digest($message), $publicKey);

        if ($verified !== true) {
            throw new InvalidSlushSignatureException('Slush sign-in could not be verified.');
        }

        $address = '0x'.bin2hex(sodium_crypto_generichash(chr(self::ED25519_FLAG).$publicKey, '', 32));

        if (preg_match('/^0x[a-f0-9]{64}$/', $address) !== 1) {
            throw new InvalidSlushSignatureException('Slush sign-in could not be verified.');
        }

        return $address;
    }

    /**
     * Hash the message the way a Sui personal-message signature does.
     */
    private static function digest(string $message): string
    {
        $payload = self::uleb128(strlen($message)).$message;
        $withIntent = chr(3).chr(0).chr(0).$payload;

        return sodium_crypto_generichash($withIntent, '', 32);
    }

    /**
     * Encode an integer the way BCS encodes a byte-vector length.
     */
    private static function uleb128(int $value): string
    {
        $encoded = '';

        do {
            $byte = $value & 0x7F;
            $value >>= 7;

            if ($value !== 0) {
                $byte |= 0x80;
            }

            $encoded .= chr($byte);
        } while ($value !== 0);

        return $encoded;
    }
}

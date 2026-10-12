<?php

namespace App\Services\Enoki;

use App\Exceptions\InvalidZkLoginTokenException;

final class GoogleIdToken
{
    private function __construct(
        public string $subject,
        public string $email,
        public bool $emailVerified,
        public string $name,
    ) {}

    /**
     * Read the Google identity claims from a zkLogin JWT.
     *
     * The caller must already have confirmed the token with Enoki. This method
     * checks the claims, not the signature.
     */
    public static function fromJwt(string $jwt, string $clientId): self
    {
        $payload = self::payload($jwt);

        $issuer = $payload['iss'] ?? null;

        if (! in_array($issuer, ['https://accounts.google.com', 'accounts.google.com'], true)) {
            throw new InvalidZkLoginTokenException('The token issuer is not Google.');
        }

        if (! self::audienceMatches($payload['aud'] ?? null, $clientId)) {
            throw new InvalidZkLoginTokenException('The token audience does not match this app.');
        }

        $expiresAt = $payload['exp'] ?? null;

        if (! is_int($expiresAt) || $expiresAt <= now()->timestamp) {
            throw new InvalidZkLoginTokenException('The token is expired.');
        }

        $subject = $payload['sub'] ?? null;

        if (! is_string($subject) || $subject === '' || strlen($subject) > 255) {
            throw new InvalidZkLoginTokenException('The token subject is missing.');
        }

        $email = $payload['email'] ?? null;

        if (! is_string($email) || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidZkLoginTokenException('The token email is missing.');
        }

        $verified = $payload['email_verified'] ?? false;
        $emailVerified = $verified === true || $verified === 'true';
        $name = $payload['name'] ?? null;
        $name = is_string($name) ? trim($name) : '';

        if ($name === '') {
            $name = strstr($email, '@', true) ?: 'Google user';
        }

        return new self($subject, $email, $emailVerified, mb_substr($name, 0, 255));
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(string $jwt): array
    {
        $segments = explode('.', $jwt);

        if (count($segments) !== 3) {
            throw new InvalidZkLoginTokenException('The token is malformed.');
        }

        $json = self::base64UrlDecode($segments[1]);
        $payload = json_decode($json, true);

        if (! is_array($payload)) {
            throw new InvalidZkLoginTokenException('The token payload is malformed.');
        }

        /** @var array<string, mixed> $payload */

        return $payload;
    }

    private static function audienceMatches(mixed $audience, string $clientId): bool
    {
        if ($clientId === '') {
            return false;
        }

        if (is_string($audience)) {
            return self::sameString($clientId, $audience);
        }

        if (! is_array($audience)) {
            return false;
        }

        foreach ($audience as $value) {
            if (is_string($value) && self::sameString($clientId, $value)) {
                return true;
            }
        }

        return false;
    }

    private static function sameString(string $known, string $value): bool
    {
        if (strlen($known) !== strlen($value)) {
            return false;
        }

        return hash_equals($known, $value);
    }

    private static function base64UrlDecode(string $segment): string
    {
        $remainder = strlen($segment) % 4;

        if ($remainder !== 0) {
            $segment .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($segment, '-_', '+/'), true);

        if ($decoded === false) {
            throw new InvalidZkLoginTokenException('The token payload is malformed.');
        }

        return $decoded;
    }
}

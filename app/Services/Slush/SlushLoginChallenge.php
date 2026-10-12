<?php

namespace App\Services\Slush;

use Illuminate\Contracts\Session\Session;

class SlushLoginChallenge
{
    private const SESSION_KEY = 'slush_login';

    /**
     * Store a one-time message and return the exact text the wallet must sign.
     */
    public function issue(Session $session): string
    {
        $nonce = bin2hex(random_bytes(32));
        $appName = config('app.name');
        $message = 'Sign in to '.(is_string($appName) && $appName !== '' ? $appName : 'Laravel')."\nNonce: ".$nonce;

        $session->put(self::SESSION_KEY, [
            'message' => $message,
            'expires_at' => now()->addMinutes(5)->getTimestamp(),
        ]);

        return $message;
    }

    /**
     * Take the stored message, refusing a missing or expired challenge.
     */
    public function pull(Session $session): ?string
    {
        $stored = $session->pull(self::SESSION_KEY);

        if (! is_array($stored)) {
            return null;
        }

        $message = $stored['message'] ?? null;
        $expiresAt = $stored['expires_at'] ?? null;

        if (! is_string($message) || $message === '' || ! is_int($expiresAt)) {
            return null;
        }

        if ($expiresAt < now()->getTimestamp()) {
            return null;
        }

        return $message;
    }
}

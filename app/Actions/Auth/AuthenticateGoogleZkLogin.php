<?php

namespace App\Actions\Auth;

use App\Enums\OauthProvider;
use App\Exceptions\EnokiUnavailableException;
use App\Exceptions\InvalidZkLoginTokenException;
use App\Models\OauthIdentity;
use App\Models\User;
use App\Services\Enoki\EnokiClient;
use App\Services\Enoki\GoogleIdToken;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthenticateGoogleZkLogin
{
    public function __construct(private EnokiClient $enoki) {}

    public function handle(string $jwt): User
    {
        $clientId = config('services.google.client_id');

        if (! is_string($clientId) || $clientId === '') {
            throw $this->unavailable();
        }

        try {
            $token = GoogleIdToken::fromJwt($jwt, $clientId);
            $address = $this->enoki->addressForJwt($jwt);
        } catch (InvalidZkLoginTokenException) {
            throw $this->rejected();
        } catch (EnokiUnavailableException) {
            throw $this->unavailable();
        }

        try {
            return DB::transaction(fn (): User => $this->findOrCreateUser($token, $address));
        } catch (UniqueConstraintViolationException) {
            $identity = OauthIdentity::query()
                ->where('provider', OauthProvider::Google)
                ->where('provider_user_id', $token->subject)
                ->first();

            if ($identity === null) {
                throw $this->rejected();
            }

            $identity->update(['sui_address' => $address]);

            $user = $identity->user;

            if (! $user instanceof User) {
                throw $this->rejected();
            }

            return $user;
        }
    }

    private function findOrCreateUser(GoogleIdToken $token, string $address): User
    {
        $identity = OauthIdentity::query()
            ->where('provider', OauthProvider::Google)
            ->where('provider_user_id', $token->subject)
            ->first();

        if ($identity !== null) {
            $identity->update(['sui_address' => $address]);

            return $identity->user;
        }

        $user = $this->resolveUser($token);

        $user->oauthIdentities()->create([
            'provider' => OauthProvider::Google,
            'provider_user_id' => $token->subject,
            'sui_address' => $address,
        ]);

        return $user;
    }

    private function resolveUser(GoogleIdToken $token): User
    {
        $existing = User::query()->where('email', $token->email)->first();

        if ($existing !== null) {
            if ($token->emailVerified && $existing->hasVerifiedEmail()) {
                return $existing;
            }

            throw ValidationException::withMessages([
                'jwt' => 'This email is already registered.',
            ]);
        }

        $user = User::query()->create([
            'name' => $token->name,
            'email' => $token->email,
            'password' => null,
        ]);

        if ($token->emailVerified) {
            $user->markEmailAsVerified();
        }

        return $user;
    }

    private function rejected(): ValidationException
    {
        return ValidationException::withMessages([
            'jwt' => 'Google sign-in could not be verified.',
        ]);
    }

    private function unavailable(): ValidationException
    {
        return ValidationException::withMessages([
            'jwt' => 'Google sign-in is unavailable. Try again.',
        ]);
    }
}

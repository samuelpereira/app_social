<?php

namespace App\Actions\Auth;

use App\Exceptions\InvalidSlushSignatureException;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Slush\SlushLoginChallenge;
use App\Services\Slush\SlushPersonalMessage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthenticateSlushLogin
{
    public function __construct(private SlushLoginChallenge $challenge) {}

    /**
     * Find or create the user who proved control of a Slush address.
     */
    public function handle(Request $request, string $signature): User
    {
        $message = $this->challenge->pull($request->session());

        if ($message === null) {
            throw ValidationException::withMessages([
                'signature' => 'Slush sign-in expired. Try again.',
            ]);
        }

        try {
            $address = SlushPersonalMessage::address($message, $signature);
        } catch (InvalidSlushSignatureException $exception) {
            throw ValidationException::withMessages([
                'signature' => $exception->getMessage(),
            ]);
        }

        try {
            return DB::transaction(fn (): User => $this->findOrCreateUser($address));
        } catch (UniqueConstraintViolationException) {
            $wallet = Wallet::query()->where('address', $address)->first();
            $user = $wallet?->user;

            if (! $user instanceof User) {
                throw $this->rejected();
            }

            return $user;
        }
    }

    private function findOrCreateUser(string $address): User
    {
        $wallet = Wallet::query()->where('address', $address)->first();

        if ($wallet !== null) {
            $user = $wallet->user;

            if (! $user instanceof User) {
                throw $this->rejected();
            }

            return $user;
        }

        $user = User::query()->create([
            'name' => $this->displayName($address),
            'email' => null,
            'password' => null,
        ]);

        $user->wallet()->create([
            'address' => $address,
        ]);

        return $user;
    }

    private function displayName(string $address): string
    {
        return mb_substr($address, 0, 6).'…'.mb_substr($address, -4);
    }

    private function rejected(): ValidationException
    {
        return ValidationException::withMessages([
            'signature' => 'Slush sign-in could not be verified.',
        ]);
    }
}

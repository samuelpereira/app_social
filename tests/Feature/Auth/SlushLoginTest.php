<?php

use App\Models\User;
use App\Services\Slush\SlushPersonalMessage;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

test('a signature produced by the sui sdk recovers the address', function () {
    $address = SlushPersonalMessage::address(
        "Sign in to Laravel\nNonce: abcdef",
        'AMLWqP7JaUFDlF20oJzpReP5syKjzbXeroO0Lo18Xcq7ZdtpUpEfHqg3edEDLzHo2+PYa2Dj6EkY5HYqPhxeNwN5tVYuj+ZU+UB4sRLoqYunkB+FOuaVvtfg45ELrQSWZA==',
    );

    expect($address)->toBe('0x7573c697fa68450f04fa0dee2d39dcdc8a5ccf5db547f3e47638a6f8eeeec110');
});

test('a google account inside slush signs in when sui accepts the proof', function () {
    config(['services.enoki.network' => 'testnet']);

    Http::preventStrayRequests();
    Http::fake([
        'https://graphql.testnet.sui.io/*' => Http::response([
            'data' => ['verifyZkLoginSignature' => ['success' => true]],
        ]),
    ]);

    $message = slushChallengeMessage($this);
    $address = '0x8c7b637e2e13e728dd412b186e170eb3d5d4031f4cc11883fb14e5487a4eaa1c';

    $this->post(route('login.slush.store'), [
        'signature' => slushZkLoginSignature(),
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
    $this->assertDatabaseHas('wallets', [
        'address' => $address,
    ]);

    Http::assertSent(function ($request) use ($message, $address): bool {
        $variables = $request->data()['variables'] ?? [];

        return $variables['bytes'] === base64_encode($message)
            && $variables['signature'] === slushZkLoginSignature()
            && $variables['intentScope'] === 'PERSONAL_MESSAGE'
            && $variables['author'] === $address;
    });
});

test('a rejected slush google proof does not sign in', function () {
    config(['services.enoki.network' => 'testnet']);

    Http::preventStrayRequests();
    Http::fake([
        'https://graphql.testnet.sui.io/*' => Http::response([
            'data' => ['verifyZkLoginSignature' => ['success' => false]],
        ]),
        'https://graphql.mainnet.sui.io/*' => Http::response([
            'data' => ['verifyZkLoginSignature' => ['success' => false]],
        ]),
    ]);

    $message = slushChallengeMessage($this);

    $this->post(route('login.slush.store'), [
        'signature' => slushZkLoginSignature(),
    ])->assertSessionHasErrors([
        'signature' => 'Slush sign-in could not be verified.',
    ]);

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
    expect($message)->not->toBeEmpty();
});

test('slush sign-in creates a user and starts a session', function () {
    $keypair = slushKeypair();
    $message = slushChallengeMessage($this);

    $response = $this->post(route('login.slush.store'), [
        'signature' => slushSignature($message, $keypair),
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticated();

    $address = slushAddress($keypair['public']);
    $user = User::query()->whereHas('wallet', function ($query) use ($address): void {
        $query->where('address', $address);
    })->first();

    expect($user)->not->toBeNull()
        ->and($user->email)->toBeNull()
        ->and($user->password)->toBeNull()
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->name)->toBe(mb_substr($address, 0, 6).'…'.mb_substr($address, -4));

    $this->assertDatabaseHas('wallets', [
        'user_id' => $user->id,
        'address' => $address,
    ]);

    $this->get(route('dashboard'))->assertOk();
});

test('the same slush address returns the same user', function () {
    $keypair = slushKeypair();

    $this->post(route('login.slush.store'), [
        'signature' => slushSignature(slushChallengeMessage($this), $keypair),
    ])->assertRedirect(route('dashboard', absolute: false));

    $userId = auth()->id();

    $this->post(route('logout'));

    $this->post(route('login.slush.store'), [
        'signature' => slushSignature(slushChallengeMessage($this), $keypair),
    ])->assertRedirect(route('dashboard', absolute: false));

    expect(User::query()->count())->toBe(1)
        ->and(auth()->id())->toBe($userId);
});

test('an invalid slush signature does not sign in', function () {
    $keypair = slushKeypair();
    $message = slushChallengeMessage($this);
    $signature = base64_decode(slushSignature($message, $keypair), true);
    $signature[20] = $signature[20] === "\0" ? "\1" : "\0";

    $this->post(route('login.slush.store'), [
        'signature' => base64_encode($signature),
    ])->assertSessionHasErrors([
        'signature' => 'Slush sign-in could not be verified.',
    ]);

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

test('a malformed zklogin signature does not sign in', function () {
    $keypair = slushKeypair();
    $message = slushChallengeMessage($this);
    $signature = base64_decode(slushSignature($message, $keypair), true);
    $signature[0] = chr(5);

    $this->post(route('login.slush.store'), [
        'signature' => base64_encode($signature),
    ])->assertSessionHasErrors([
        'signature' => 'Slush sign-in could not be verified.',
    ]);

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

test('slush sign-in without a challenge is rejected', function () {
    $keypair = slushKeypair();

    $this->post(route('login.slush.store'), [
        'signature' => slushSignature('Sign in to Laravel', $keypair),
    ])->assertSessionHasErrors([
        'signature' => 'Slush sign-in expired. Try again.',
    ]);

    $this->assertGuest();
});

test('an expired slush challenge is rejected', function () {
    $keypair = slushKeypair();
    $message = "Sign in to Laravel\nNonce: ".str_repeat('ab', 32);

    $this->withSession([
        'slush_login' => [
            'message' => $message,
            'expires_at' => now()->subMinute()->getTimestamp(),
        ],
    ])->post(route('login.slush.store'), [
        'signature' => slushSignature($message, $keypair),
    ])->assertSessionHasErrors([
        'signature' => 'Slush sign-in expired. Try again.',
    ]);

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

/**
 * @return array{secret: string, public: string}
 */
function slushKeypair(): array
{
    $pair = sodium_crypto_sign_keypair();

    return [
        'secret' => sodium_crypto_sign_secretkey($pair),
        'public' => sodium_crypto_sign_publickey($pair),
    ];
}

function slushAddress(string $publicKey): string
{
    return '0x'.bin2hex(sodium_crypto_generichash(chr(0).$publicKey, '', 32));
}

/**
 * @param  array{secret: string, public: string}  $keypair
 */
function slushSignature(string $message, array $keypair): string
{
    $payload = chr(strlen($message)).$message;
    $digest = sodium_crypto_generichash(chr(3).chr(0).chr(0).$payload, '', 32);
    $signature = sodium_crypto_sign_detached($digest, $keypair['secret']);

    return base64_encode(chr(0).$signature.$keypair['public']);
}

function slushZkLoginSignature(): string
{
    return 'BQIBMQEyAgIBMwE0AgE1ATYCATcBODBJbWx6Y3lJNkltaDBkSEJ6T2k4dllXTmpiM1Z1ZEhNdVoyOXZaMnhsTG1OdmJTSXMAJGV5SmhiR2NpT2lKU1V6STFOaUlzSW5SNWNDSTZJa3BYVkNKOR4xMjM0NTY3ODkwMTIzNDU2Nzg5MDEyMzQ1Njc4OTBkAAAAAAAAAGEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEB';
}

function slushChallengeMessage(TestCase $test): string
{
    $challenge = $test->getJson(route('login.slush.challenge'));
    $challenge->assertOk();
    $message = $challenge->json('message');

    expect($message)->toBeString()->not->toBeEmpty();

    return $message;
}

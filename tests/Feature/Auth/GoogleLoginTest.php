<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.enoki.key' => 'test-enoki-key',
        'services.google.client_id' => 'test-google-client',
    ]);
});

test('google sign-in creates a user and starts a session', function () {
    $jwt = googleZkLoginJwt();
    $address = zkLoginAddress('a');

    fakeEnokiZkLogin($address);

    $response = $this->post(route('login.google.store'), [
        'jwt' => $jwt,
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticated();

    $user = User::query()->where('email', 'ada@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Ada Lovelace')
        ->and($user->password)->toBeNull()
        ->and($user->hasVerifiedEmail())->toBeTrue();

    $this->assertDatabaseHas('oauth_identities', [
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'google-subject',
        'sui_address' => $address,
    ]);

    Http::assertSent(function ($request) use ($jwt): bool {
        return $request->url() === 'https://api.enoki.mystenlabs.com/v1/zklogin'
            && $request->hasHeader('zklogin-jwt', $jwt)
            && $request->hasHeader('Authorization', 'Bearer test-enoki-key');
    });
});

test('google sign-in does not start a session when Enoki rejects the token', function () {
    Http::preventStrayRequests();

    Http::fake([
        'https://api.enoki.mystenlabs.com/v1/zklogin' => Http::response([
            'errors' => [['message' => 'invalid']],
        ], 401),
    ]);

    $response = $this->post(route('login.google.store'), [
        'jwt' => googleZkLoginJwt(),
    ]);

    $response->assertSessionHasErrors([
        'jwt' => 'Google sign-in could not be verified.',
    ]);
    $this->assertGuest();
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('oauth_identities', 0);
});

test('google sign-in returns the same user for the same subject', function () {
    $jwt = googleZkLoginJwt();
    $nextAddress = zkLoginAddress('b');

    fakeEnokiZkLoginSequence([zkLoginAddress('a'), $nextAddress]);

    $this->post(route('login.google.store'), [
        'jwt' => $jwt,
    ]);

    $this->post(route('logout'));

    $this->assertGuest();

    $response = $this->post(route('login.google.store'), [
        'jwt' => googleZkLoginJwt([
            'email' => 'other@example.com',
            'name' => 'Other Person',
        ]),
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));

    expect(User::query()->count())->toBe(1);

    $user = User::query()->where('email', 'ada@example.com')->first();

    expect($user)->not->toBeNull();
    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseCount('oauth_identities', 1);
    $this->assertDatabaseHas('oauth_identities', [
        'user_id' => $user->id,
        'provider_user_id' => 'google-subject',
        'sui_address' => $nextAddress,
    ]);
});

test('google sign-in links a verified Google email to the verified account', function () {
    $user = User::factory()->create([
        'email' => 'ada@example.com',
    ]);

    fakeEnokiZkLogin(zkLoginAddress('c'));

    $response = $this->post(route('login.google.store'), [
        'jwt' => googleZkLoginJwt(),
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
    expect(User::query()->count())->toBe(1);
    $this->assertDatabaseHas('oauth_identities', [
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'google-subject',
    ]);
});

test('google sign-in does not link an unverified account with the same email', function () {
    User::factory()->unverified()->create([
        'email' => 'ada@example.com',
    ]);

    fakeEnokiZkLogin(zkLoginAddress('d'));

    $response = $this->post(route('login.google.store'), [
        'jwt' => googleZkLoginJwt(),
    ]);

    $response->assertSessionHasErrors([
        'jwt' => 'This email is already registered.',
    ]);
    $this->assertGuest();
    expect(User::query()->count())->toBe(1);
    $this->assertDatabaseCount('oauth_identities', 0);
});

test('google sign-in does not call Enoki when the token audience does not match', function () {
    Http::preventStrayRequests();

    $response = $this->post(route('login.google.store'), [
        'jwt' => googleZkLoginJwt([
            'aud' => 'another-client',
        ]),
    ]);

    $response->assertSessionHasErrors([
        'jwt' => 'Google sign-in could not be verified.',
    ]);
    $this->assertGuest();
    $this->assertDatabaseCount('users', 0);
    Http::assertNothingSent();
});

/**
 * @param  array<string, mixed>  $claims
 */
function googleZkLoginJwt(array $claims = []): string
{
    $payload = array_merge([
        'iss' => 'https://accounts.google.com',
        'aud' => 'test-google-client',
        'sub' => 'google-subject',
        'email' => 'ada@example.com',
        'email_verified' => true,
        'name' => 'Ada Lovelace',
        'exp' => now()->addHour()->getTimestamp(),
    ], $claims);

    $encode = function (array $value): string {
        return rtrim(strtr(base64_encode((string) json_encode($value)), '+/', '-_'), '=');
    };

    return $encode(['alg' => 'none', 'typ' => 'JWT']).'.'.$encode($payload).'.signature';
}

function zkLoginAddress(string $nibble): string
{
    return '0x'.str_repeat($nibble, 64);
}

function fakeEnokiZkLogin(string $address): void
{
    fakeEnokiZkLoginSequence([$address]);
}

/**
 * @param  list<string>  $addresses
 */
function fakeEnokiZkLoginSequence(array $addresses): void
{
    Http::preventStrayRequests();

    Http::fake([
        'https://api.enoki.mystenlabs.com/v1/zklogin' => function () use (&$addresses) {
            $address = array_shift($addresses);

            return Http::response([
                'data' => [
                    'salt' => '1',
                    'address' => $address,
                    'publicKey' => 'ignored',
                ],
            ]);
        },
    ]);
}

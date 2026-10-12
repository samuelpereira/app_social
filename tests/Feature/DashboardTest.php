<?php

use App\Models\OauthIdentity;
use App\Models\User;
use App\Models\Wallet;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('dashboard shows the user Sui address', function () {
    $address = '0x'.str_repeat('a', 64);
    $user = User::factory()->create();
    OauthIdentity::factory()->for($user)->create([
        'sui_address' => $address,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('suiAddress', $address));
});

test('dashboard shows the Slush address', function () {
    $address = '0x'.str_repeat('b', 64);
    $user = User::factory()->create(['email' => null]);
    Wallet::factory()->for($user)->create([
        'address' => $address,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('suiAddress', $address));
});

test('dashboard has no Sui address for a password account', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('suiAddress', null));
});
